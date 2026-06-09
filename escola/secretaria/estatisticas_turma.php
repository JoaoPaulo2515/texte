<?php
// escola/secretaria/estatisticas_turma.php - Estatísticas por Turma

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$is_secretaria = ($_SESSION['usuario_tipo'] == 'secretaria' || ($_SESSION['papel'] ?? '') == 'secretaria');
$is_admin = ($_SESSION['usuario_tipo'] == 'super_admin' || $_SESSION['usuario_tipo'] == 'admin_escola' || $_SESSION['usuario_tipo'] == 'diretor' || ($_SESSION['papel'] ?? '') == 'admin');

if (!$is_secretaria && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// Buscar turmas
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

$estatisticas = [];
$turma_selecionada = null;

if (isset($_GET['turma_id']) && $_GET['turma_id'] > 0) {
    $turma_id = (int)$_GET['turma_id'];
    
    // Buscar dados da turma
    $sql = "SELECT * FROM turmas WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $turma_id]);
    $turma_selecionada = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total de alunos
    $sql = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':turma_id' => $turma_id]);
    $estatisticas['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Alunos por gênero
    $sql = "SELECT e.genero, COUNT(*) as total 
            FROM matriculas m 
            JOIN estudantes e ON e.id = m.estudante_id 
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' 
            GROUP BY e.genero";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':turma_id' => $turma_id]);
    $estatisticas['genero'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Média geral da turma
    $sql = "SELECT AVG(n.media_final) as media_geral 
            FROM notas n 
            JOIN matriculas m ON m.estudante_id = n.estudante_id 
            WHERE m.turma_id = :turma_id AND n.media_final IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':turma_id' => $turma_id]);
    $estatisticas['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media_geral'] ?? 0, 2);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas por Turma | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .stat-card { text-align: center; padding: 20px; background: #f8f9fa; border-radius: 10px; height: 100%; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Estatísticas por Turma</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Selecione a Turma</label>
                        <select name="turma_id" class="form-select" required>
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas as $turma): ?>
                            <option value="<?php echo $turma['id']; ?>" <?php echo (isset($_GET['turma_id']) && $_GET['turma_id'] == $turma['id']) ? 'selected' : ''; ?>>
                                <?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Gerar Estatísticas</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($turma_selecionada): ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div><i class="fas fa-users"></i> Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $estatisticas['media_geral']; ?></div>
                    <div><i class="fas fa-chart-line"></i> Média Geral</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($estatisticas['genero']); ?></div>
                    <div><i class="fas fa-venus-mars"></i> Distribuição por Gênero</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição por Gênero</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoGenero" height="250"></canvas>
                        <?php
                        $labels = [];
                        $dados = [];
                        foreach ($estatisticas['genero'] as $g) {
                            $labels[] = $g['genero'] == 'M' ? 'Masculino' : 'Feminino';
                            $dados[] = $g['total'];
                        }
                        ?>
                        <script>
                            new Chart(document.getElementById('graficoGenero'), {
                                type: 'pie',
                                data: { labels: <?php echo json_encode($labels); ?>, datasets: [{ data: <?php echo json_encode($dados); ?>, backgroundColor: ['#006B3E', '#1A2A6C'] }] }
                            });
                        </script>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações da Turma</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <tr><th>Turma</th><td><?php echo $turma_selecionada['ano'] . 'ª - ' . htmlspecialchars($turma_selecionada['nome']); ?></td></tr>
                            <tr><th>Turno</th><td><?php echo ucfirst($turma_selecionada['turno']); ?></td></tr>
                            <tr><th>Total de Alunos</th><td><?php echo $estatisticas['total_alunos']; ?></td></tr>
                            <tr><th>Média Geral</th><td><?php echo $estatisticas['media_geral']; ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>