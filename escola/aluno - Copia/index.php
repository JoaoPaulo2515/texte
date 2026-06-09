<?php
// aluno/index.php - Dashboard do Aluno

define('ROOT_PATH', dirname(__DIR__, 2)); // Vai para a pasta escola, depois para a raiz

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';

// Buscar dados do aluno
$sql_aluno = "SELECT * FROM estudantes WHERE id = :id AND escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma atual
$sql_turma = "SELECT t.* FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar notas do aluno
$sql_notas = "SELECT n.*, d.nome as disciplina 
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id 
              ORDER BY n.created_at DESC LIMIT 5";
$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id]);
$ultimas_notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Buscar mensalidades pendentes
$sql_mensalidades = "SELECT COUNT(*) as total, SUM(valor_total - valor_pago) as total_devedor
                     FROM mensalidades 
                     WHERE aluno_id = :aluno_id AND status IN ('pendente', 'parcial')";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':aluno_id' => $aluno_id]);
$mensalidades = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);

// Buscar próximos eventos
$sql_eventos = "SELECT * FROM eventos_escolares 
                WHERE escola_id = :escola_id 
                AND data_evento >= CURDATE() 
                ORDER BY data_evento ASC LIMIT 5";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([':escola_id' => $escola_id]);
$proximos_eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Buscar avisos
$sql_avisos = "SELECT * FROM avisos 
               WHERE escola_id = :escola_id 
               AND (turma_id IS NULL OR turma_id = :turma_id)
               AND data_inicio <= CURDATE() AND data_fim >= CURDATE()
               ORDER BY created_at DESC LIMIT 5";
$stmt_avisos = $conn->prepare($sql_avisos);
$stmt_avisos->execute([':escola_id' => $escola_id, ':turma_id' => $turma['id'] ?? 0]);
$avisos = $stmt_avisos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Área do Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        
        .welcome-card { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 25px; margin-bottom: 20px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3><i class="fas fa-graduation-cap"></i> Bem-vindo, <?php echo htmlspecialchars($aluno['nome']); ?>!</h3>
                    <p class="mb-0">Turma: <?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></p>
                    <small>Matrícula: <?php echo $aluno['matricula']; ?></small>
                </div>
                <div class="text-end">
                    <div class="display-4"><?php echo date('d/m/Y'); ?></div>
                    <small><?php echo date('l'); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo number_format($ultimas_notas ? array_sum(array_column($ultimas_notas, 'media_final')) / count($ultimas_notas) : 0, 1); ?> / 20</div>
                    <div class="text-muted">Média Geral</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $mensalidades['total'] ?? 0; ?></div>
                    <div class="text-muted">Mensalidades Pendentes</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo number_format($mensalidades['total_devedor'] ?? 0, 2, ',', '.'); ?> Kz</div>
                    <div class="text-muted">Valor em Débito</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Últimas Notas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Últimas Avaliações</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ultimas_notas)): ?>
                            <div class="alert alert-info text-center">Nenhuma nota registrada ainda.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead><tr><th>Disciplina</th><th class="text-end">Nota</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($ultimas_notas as $nota): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($nota['disciplina']); ?></td>
                                            <td class="text-end fw-bold"><?php echo number_format($nota['media_final'], 1, ',', '.'); ?></td>
                                            <td><?php echo $nota['media_final'] >= 10 ? '<span class="badge bg-success">Aprovado</span>' : '<span class="badge bg-danger">Reprovado</span>'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <div class="text-center mt-2">
                            <a href="academico/boletim.php" class="btn btn-sm btn-outline-primary">Ver Boletim Completo</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Avisos -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bullhorn"></i> Avisos Recentes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($avisos)): ?>
                            <div class="alert alert-info text-center">Nenhum aviso no momento.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($avisos as $aviso): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><?php echo htmlspecialchars($aviso['titulo']); ?></strong>
                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($aviso['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-0 small"><?php echo htmlspecialchars(substr($aviso['conteudo'], 0, 100)); ?>...</p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <!-- Próximos Eventos -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Próximos Eventos</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($proximos_eventos)): ?>
                            <div class="alert alert-info text-center">Nenhum evento programado.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($proximos_eventos as $evento): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <strong><?php echo htmlspecialchars($evento['nome']); ?></strong>
                                        <span class="badge bg-primary"><?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?></span>
                                    </div>
                                    <p class="mb-0 small"><?php echo htmlspecialchars($evento['descricao'] ?? ''); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Ações Rápidas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Ações Rápidas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 mb-2">
                                <a href="financeiro/mensalidades.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-calendar-dollar"></i> Ver Mensalidades
                                </a>
                            </div>
                            <div class="col-6 mb-2">
                                <a href="academico/boletim.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-chart-line"></i> Ver Boletim
                                </a>
                            </div>
                            <div class="col-6 mb-2">
                                <a href="documentos/declaracoes.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-file-alt"></i> Solicitar Declaração
                                </a>
                            </div>
                            <div class="col-6 mb-2">
                                <a href="perfil.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-user-edit"></i> Atualizar Dados
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
    </script>
</body>
</html>