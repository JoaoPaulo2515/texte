<?php
// escola/turmas/visualizar.php - Visualizar detalhes da turma
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados da turma
$stmt = $conn->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM matriculas WHERE turma_id = t.id AND status = 'ativa') as total_alunos,
           (SELECT COUNT(DISTINCT professor_id) FROM alocacoes WHERE turma_id = t.id) as total_professores,
           (SELECT COUNT(DISTINCT disciplina_id) FROM alocacoes WHERE turma_id = t.id) as total_disciplinas
    FROM turmas t
    WHERE t.id = :id AND t.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$turma = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: index.php?error=Turma não encontrada');
    exit;
}

// Buscar alunos da turma
$stmt = $conn->prepare("
    SELECT e.id, u.nome, e.matricula, e.bi, m.data_matricula
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    JOIN matriculas m ON m.estudante_id = e.id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY u.nome ASC
");
$stmt->execute([':turma_id' => $id]);
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores e disciplinas
$stmt = $conn->prepare("
    SELECT p.id, u.nome as professor_nome, p.especialidade,
           d.id as disciplina_id, d.nome as disciplina_nome, a.id as alocacao_id
    FROM alocacoes a
    JOIN professores p ON p.id = a.professor_id
    JOIN usuarios u ON u.id = p.usuario_id
    JOIN disciplinas d ON d.id = a.disciplina_id
    WHERE a.turma_id = :turma_id
    ORDER BY d.nome ASC
");
$stmt->execute([':turma_id' => $id]);
$alocacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Turnos para exibição
$turnos = [
    'manha' => 'Manhã',
    'tarde' => 'Tarde',
    'noite' => 'Noite'
];

// Estatísticas de notas da turma
$stmt = $conn->prepare("
    SELECT 
        AVG(n.media) as media_geral,
        COUNT(CASE WHEN n.media >= 10 THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.media >= 7 AND n.media < 10 THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.media < 7 THEN 1 END) as reprovados
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    WHERE m.turma_id = :turma_id AND n.media IS NOT NULL
");
$stmt->execute([':turma_id' => $id]);
$estatisticas_notas = $stmt->fetch(PDO::FETCH_ASSOC);

// Estatísticas de frequência
$stmt = $conn->prepare("
    SELECT 
        AVG(CASE WHEN p.presente = 1 THEN 100 ELSE 0 END) as taxa_presenca
    FROM presencas p
    JOIN matriculas m ON m.id = p.matricula_id
    WHERE m.turma_id = :turma_id
");
$stmt->execute([':turma_id' => $id]);
$frequencia = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($turma['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 150px; font-weight: 600; color: #555; }
        .info-value { flex: 1; color: #333; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .stats-card { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px; margin-bottom: 15px; }
        .stats-number { font-size: 2em; font-weight: bold; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users-group"></i> Turma: <?php echo htmlspecialchars($turma['nome']); ?></h2>
            <div>
                <a href="editar.php?id=<?php echo $turma['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="excluir.php?id=<?php echo $turma['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i> Excluir</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-info-circle"></i> Informações da Turma</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">Ano/Classe:</div><div class="info-value"><?php echo $turma['ano']; ?></div></div>
                        <div class="info-row"><div class="info-label">Turno:</div><div class="info-value"><?php echo $turnos[$turma['turno']] ?? ucfirst($turma['turno']); ?></div></div>
                        <div class="info-row"><div class="info-label">Ano Letivo:</div><div class="info-value"><?php echo $turma['ano_letivo']; ?></div></div>
                        <?php if ($turma['sala']): ?>
                        <div class="info-row"><div class="info-label">Sala:</div><div class="info-value"><?php echo htmlspecialchars($turma['sala']); ?></div></div>
                        <?php endif; ?>
                        <div class="info-row"><div class="info-label">Capacidade:</div><div class="info-value"><?php echo $turma['capacidade']; ?> alunos</div></div>
                        <div class="info-row"><div class="info-label">Status:</div><div class="info-value">
                            <span class="badge bg-<?php echo $turma['status'] == 'ativa' ? 'success' : 'danger'; ?>">
                                <?php echo ucfirst($turma['status']); ?>
                            </span>
                        </div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Estatísticas</div>
                    <div class="card-body">
                        <div class="stats-card">
                            <div class="stats-number text-primary"><?php echo $turma['total_alunos']; ?>/<?php echo $turma['capacidade']; ?></div>
                            <div>Alunos Matriculados</div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $turma['total_professores']; ?></div>
                            <div>Professores</div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-number text-info"><?php echo $turma['total_disciplinas']; ?></div>
                            <div>Disciplinas</div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-number text-warning"><?php echo round($estatisticas_notas['media_geral'] ?? 0, 1); ?></div>
                            <div>Média Geral</div>
                        </div>
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo round($frequencia['taxa_presenca'] ?? 0, 1); ?>%</div>
                            <div>Taxa de Presença</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Desempenho Académico</div>
                    <div class="card-body">
                        <canvas id="desempenhoChart" height="200"></canvas>
                        <div class="row mt-3 text-center">
                            <div class="col-4">
                                <h5 class="text-success"><?php echo $estatisticas_notas['aprovados'] ?? 0; ?></h5>
                                <small>Aprovados</small>
                            </div>
                            <div class="col-4">
                                <h5 class="text-warning"><?php echo $estatisticas_notas['recuperacao'] ?? 0; ?></h5>
                                <small>Recuperação</small>
                            </div>
                            <div class="col-4">
                                <h5 class="text-danger"><?php echo $estatisticas_notas['reprovados'] ?? 0; ?></h5>
                                <small>Reprovados</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-users"></i> Alunos Matriculados</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>#</th><th>Matrícula</th><th>Nome</th><th>BI</th><th>Data Matrícula</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alunos as $i => $aluno): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo $aluno['matricula']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                        <td><?php echo $aluno['bi'] ?? '-'; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($aluno['data_matricula'])); ?></td>
                                        <td><a href="../alunos/visualizar.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-info">Ver</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($alunos)): ?>
                                    <tr><td colspan="6" class="text-center">Nenhum aluno matriculado</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chalkboard-user"></i> Professores e Disciplinas</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Disciplina</th><th>Professor</th><th>Especialidade</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alocacoes as $aloc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($aloc['disciplina_nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($aloc['professor_nome']); ?></td>
                                        <td><?php echo htmlspecialchars($aloc['especialidade'] ?? '-'); ?></td>
                                        <td><a href="../professores/visualizar.php?id=<?php echo $aloc['id']; ?>" class="btn btn-sm btn-info">Ver Professor</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($alocacoes)): ?>
                                    <tr><td colspan="4" class="text-center">Nenhuma disciplina atribuída</td></td>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Gráfico de desempenho
        const ctx = document.getElementById('desempenhoChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [
                        <?php echo $estatisticas_notas['aprovados'] ?? 0; ?>,
                        <?php echo $estatisticas_notas['recuperacao'] ?? 0; ?>,
                        <?php echo $estatisticas_notas['reprovados'] ?? 0; ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>