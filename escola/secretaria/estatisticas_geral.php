<?php
// escola/secretaria/estatisticas_geral.php - Estatísticas Gerais da Escola

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

// Estatísticas Gerais
$stats = [];

// Total de alunos
$sql = "SELECT COUNT(*) as total FROM estudantes e INNER JOIN matriculas m ON m.estudante_id = e.id WHERE m.escola_id = :escola_id AND m.status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$sql = "SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = :escola_id AND cargo LIKE '%professor%'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$sql = "SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql = "SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Média geral de todas as notas
$sql = "SELECT AVG(media_final) as media FROM notas n JOIN matriculas m ON m.estudante_id = n.estudante_id WHERE m.escola_id = :escola_id AND n.media_final IS NOT NULL";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media'] ?? 0, 2);

// Alunos por gênero
$sql = "SELECT e.genero, COUNT(*) as total 
        FROM estudantes e 
        INNER JOIN matriculas m ON m.estudante_id = e.id 
        WHERE m.escola_id = :escola_id AND m.status = 'ativa' 
        GROUP BY e.genero";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['genero'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alunos por turma
$sql = "SELECT t.nome, t.ano, COUNT(m.id) as total 
        FROM turmas t 
        LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa' 
        WHERE t.escola_id = :escola_id AND t.status = 'ativa' 
        GROUP BY t.id ORDER BY t.ano, t.nome";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['turmas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas Gerais | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .stat-card { text-align: center; padding: 20px; background: #f8f9fa; border-radius: 10px; height: 100%; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 2rem; font-weight: bold; color: #006B3E; }
        .stat-icon { font-size: 2rem; color: #1A2A6C; margin-bottom: 10px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Estatísticas Gerais da Escola</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                            <div class="stat-number"><?php echo $stats['total_alunos']; ?></div>
                            <div>Total de Alunos</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                            <div class="stat-number"><?php echo $stats['total_professores']; ?></div>
                            <div>Professores</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-school"></i></div>
                            <div class="stat-number"><?php echo $stats['total_turmas']; ?></div>
                            <div>Turmas Ativas</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-book"></i></div>
                            <div class="stat-number"><?php echo $stats['total_disciplinas']; ?></div>
                            <div>Disciplinas</div>
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
                                foreach ($stats['genero'] as $g) {
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
                                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Alunos por Turma</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="graficoTurmas" height="250"></canvas>
                                <script>
                                    new Chart(document.getElementById('graficoTurmas'), {
                                        type: 'bar',
                                        data: {
                                            labels: <?php echo json_encode(array_map(function($t) { return $t['ano'] . 'ª ' . $t['nome']; }, $stats['turmas'])); ?>,
                                            datasets: [{ label: 'Número de Alunos', data: <?php echo json_encode(array_column($stats['turmas'], 'total')); ?>, backgroundColor: '#006B3E' }]
                                        },
                                        options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
                                    });
                                </script>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-table"></i> Detalhamento por Turma</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-light">
                                            <tr><th>Turma</th><th>Ano</th><th>Total de Alunos</th><th>% do Total</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($stats['turmas'] as $t): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($t['nome']); ?></td>
                                                <td><?php echo $t['ano']; ?>º Ano</td>
                                                <td><?php echo $t['total']; ?></td>
                                                <td><?php echo $stats['total_alunos'] > 0 ? round(($t['total'] / $stats['total_alunos']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr><th colspan="2">Total Geral</th><th><?php echo $stats['total_alunos']; ?></th><th>100%</th></tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>