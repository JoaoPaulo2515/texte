<?php
// escola/turmas/excluir.php - Excluir Turma
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$confirm = $_GET['confirm'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados da turma
$stmt = $conn->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM matriculas WHERE turma_id = t.id) as total_alunos,
           (SELECT COUNT(*) FROM alocacoes WHERE turma_id = t.id) as total_disciplinas
    FROM turmas t
    WHERE t.id = :id AND t.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$turma = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: index.php?error=Turma não encontrada');
    exit;
}

// Processar exclusão
if ($confirm === 'yes') {
    try {
        $conn->beginTransaction();
        
        // Log antes de excluir
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, dados_antes, ip, created_at)
            VALUES (:usuario_id, 'excluir_turma', 'turmas', :registro_id, :dados_antes, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':dados_antes' => json_encode($turma),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Excluir turma (as relações em cascata vão excluir matrículas e alocações)
        $stmt = $conn->prepare("DELETE FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Turma '{$turma['nome']}' excluída com sucesso!";
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao excluir turma: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir Turma | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .card-header { background: #dc3545; color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .warning-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .btn-danger { background: #dc3545; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 150px; font-weight: 600; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-trash-alt"></i> Excluir Turma</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Atenção! Esta ação é irreversível</h3>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="warning-box">
                    <h5><i class="fas fa-skull-crosswalk"></i> Você está prestes a excluir a turma:</h5>
                    <div class="info-row">
                        <div class="info-label">Nome:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($turma['nome']); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Ano/Classe:</div>
                        <div class="info-value"><?php echo $turma['ano']; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Turno:</div>
                        <div class="info-value"><?php echo ucfirst($turma['turno']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Ano Letivo:</div>
                        <div class="info-value"><?php echo $turma['ano_letivo']; ?></div>
                    </div>
                    <?php if ($turma['sala']): ?>
                    <div class="info-row">
                        <div class="info-label">Sala:</div>
                        <div class="info-value"><?php echo htmlspecialchars($turma['sala']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Data de Cadastro:</div>
                        <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($turma['created_at'])); ?></div>
                    </div>
                </div>
                
                <div class="alert alert-danger">
                    <i class="fas fa-skull-crosswalk"></i>
                    <strong>AVISO IMPORTANTE:</strong> Ao excluir esta turma, os seguintes dados serão permanentemente removidos:
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <i class="fas fa-users fa-3x text-danger mb-2"></i>
                                <h3><?php echo $turma['total_alunos']; ?></h3>
                                <p>Matrículas de Alunos</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <i class="fas fa-book fa-3x text-danger mb-2"></i>
                                <h3><?php echo $turma['total_disciplinas']; ?></h3>
                                <p>Disciplinas Atribuídas</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Dados que serão perdidos permanentemente:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Todas as matrículas dos alunos nesta turma</li>
                        <li>Todas as notas lançadas para alunos desta turma</li>
                        <li>Todos os registros de presença/chamada</li>
                        <li>Todas as alocações de professores e disciplinas</li>
                        <li>Histórico acadêmico dos alunos referente a esta turma</li>
                    </ul>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Sugestão:</strong> Se deseja apenas desativar a turma, considere apenas <strong>encerrar</strong> a turma em vez de excluir.
                </div>
                
                <div class="text-center mt-4">
                    <a href="?id=<?php echo $id; ?>&confirm=yes" class="btn btn-danger btn-lg px-5" onclick="return confirm('Esta ação é irreversível! Tem certeza absoluta que deseja excluir permanentemente esta turma e todos os seus dados?')">
                        <i class="fas fa-trash-alt"></i> Sim, excluir permanentemente
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                        <i class="fas fa-ban"></i> Cancelar
                    </a>
                </div>
                
                <div class="text-center mt-3">
                    <small class="text-muted">Para confirmar a exclusão, clique no botão vermelho acima.</small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>