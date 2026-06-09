<?php
// escola/relatorios/index.php - Central de Relatórios
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];

// Buscar dados da escola para o layout
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Estatísticas gerais para o dashboard
$stats = [];

// Total de alunos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM professores WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Média geral de notas
$stmt = $conn->prepare("
    SELECT AVG(media_final) as media_geral 
    FROM notas n
    JOIN matriculas m ON m.id = n.estudante_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media_geral'] ?? 0, 1);

// Taxa de aprovação
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(*) as total
    FROM notas n
    JOIN matriculas m ON m.id = n.estudante_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$aprovacao = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['taxa_aprovacao'] = $aprovacao['total'] > 0 ? round(($aprovacao['aprovados'] / $aprovacao['total']) * 100, 1) : 0;

// Total de livros na biblioteca
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM livros WHERE escola_id = :escola_id AND status = 'disponivel'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_livros'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
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
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .card:hover { transform: translateY(-5px); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-value { font-size: 2.5em; font-weight: bold; color: #006B3E; }
        .btn-relatorio { background: white; border: 2px solid #006B3E; color: #006B3E; padding: 15px; border-radius: 12px; transition: all 0.3s; width: 100%; }
        .btn-relatorio:hover { background: #006B3E; color: white; transform: translateY(-3px); }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .relatorio-icon { font-size: 2.5em; margin-bottom: 15px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Central de Relatórios</h2>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_alunos']; ?></div>
                <div>Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_professores']; ?></div>
                <div>Professores</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_turmas']; ?></div>
                <div>Turmas Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['media_geral']; ?></div>
                <div>Média Geral</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="btn-relatorio text-center" onclick="location.href='notas.php'">
                    <div class="relatorio-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h4>Relatório de Notas</h4>
                    <p class="text-muted small">Visualize o desempenho académico por turma, disciplina e bimestre</p>
                    <span class="badge bg-success mt-2">Acessar <i class="fas fa-arrow-right"></i></span>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="btn-relatorio text-center" onclick="location.href='frequencia.php'">
                    <div class="relatorio-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h4>Relatório de Frequência</h4>
                    <p class="text-muted small">Acompanhe a presença dos alunos por turma e período</p>
                    <span class="badge bg-success mt-2">Acessar <i class="fas fa-arrow-right"></i></span>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="btn-relatorio text-center" onclick="location.href='desempenho.php'">
                    <div class="relatorio-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Análise de Desempenho</h4>
                    <p class="text-muted small">Estatísticas detalhadas de desempenho escolar</p>
                    <span class="badge bg-success mt-2">Acessar <i class="fas fa-arrow-right"></i></span>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-info-circle"></i> Informações</h3>
            </div>
            <div class="card-body">
                <p>Os relatórios podem ser filtrados por turma, disciplina, bimestre e período. Utilize os filtros disponíveis em cada página para personalizar sua análise.</p>
                <hr>
                <div class="row">
                    <div class="col-md-4">
                        <i class="fas fa-print text-primary"></i> <strong>Impressão</strong><br>
                        <small>Clique no botão imprimir para gerar uma versão para impressão</small>
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-file-excel text-success"></i> <strong>Exportação</strong><br>
                        <small>Exporte os dados para Excel/CSV</small>
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-chart-pie text-info"></i> <strong>Gráficos</strong><br>
                        <small>Visualização gráfica dos dados estatísticos</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>