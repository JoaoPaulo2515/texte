<?php
// escola/servicos_pedagogicos/gerais/index.php - Dashboard Geral
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Estatísticas
$stats = [];

// Total de classes
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM classes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de períodos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM periodos WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_periodos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de salas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM salas WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_salas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de cursos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM cursos WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_cursos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de associações classe-curso
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM classe_curso WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_associacoes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão Geral | SIGE Angola</title>
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
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        .stat-icon { font-size: 2em; margin-bottom: 10px; }
        
        .modulo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .modulo-item { background: white; border-radius: 15px; padding: 20px; text-align: center; text-decoration: none; color: #333; transition: all 0.3s; display: block; }
        .modulo-item:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: #006B3E; }
        .modulo-icon { font-size: 2.5em; margin-bottom: 15px; }
        .modulo-title { font-weight: bold; margin-bottom: 5px; }
        .modulo-desc { font-size: 0.75em; color: #666; }
    </style>
</head>
<body>
     <?php require_once __DIR__ . '/../../menu_escola.php';?>

    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-cog"></i> Gestão Geral</h2>
            <div>
                <a href="exportar.php" class="btn btn-success btn-sm"><i class="fas fa-download"></i> Exportar Dados</a>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-layer-group"></i></div><div class="stat-value"><?php echo $stats['total_classes']; ?></div><div class="stat-label">Classes</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-alt"></i></div><div class="stat-value"><?php echo $stats['total_periodos']; ?></div><div class="stat-label">Períodos</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-door-open"></i></div><div class="stat-value"><?php echo $stats['total_salas']; ?></div><div class="stat-label">Salas</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-users-group"></i></div><div class="stat-value"><?php echo $stats['total_turmas']; ?></div><div class="stat-label">Turmas</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-graduation-cap"></i></div><div class="stat-value"><?php echo $stats['total_cursos']; ?></div><div class="stat-label">Cursos</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-book"></i></div><div class="stat-value"><?php echo $stats['total_disciplinas']; ?></div><div class="stat-label">Disciplinas</div></div>
        </div>
        
        <!-- Módulos -->
        <div class="card">
            <div class="card-header"><i class="fas fa-th-large"></i> Módulos de Gestão</div>
            <div class="card-body">
                <div class="modulo-grid">
                    <a href="classes.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-layer-group text-primary"></i></div>
                        <div class="modulo-title">Classes</div>
                        <div class="modulo-desc">Gerir classes/anos escolares</div>
                    </a>
                    <a href="periodos.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-calendar-alt text-success"></i></div>
                        <div class="modulo-title">Períodos</div>
                        <div class="modulo-desc">Gerir períodos letivos</div>
                    </a>
                    <a href="salas.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-door-open text-warning"></i></div>
                        <div class="modulo-title">Salas</div>
                        <div class="modulo-desc">Gerir salas de aula</div>
                    </a>
                    <a href="cursos.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-graduation-cap text-info"></i></div>
                        <div class="modulo-title">Cursos</div>
                        <div class="modulo-desc">Gerir cursos oferecidos</div>
                    </a>
                    <a href="disciplinas.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-book text-danger"></i></div>
                        <div class="modulo-title">Disciplinas</div>
                        <div class="modulo-desc">Gerir disciplinas</div>
                    </a>
                    <a href="associar_classe_curso.php" class="modulo-item">
                        <div class="modulo-icon"><i class="fas fa-link text-secondary"></i></div>
                        <div class="modulo-title">Associar Classe-Curso</div>
                        <div class="modulo-desc">Relacionar classes com cursos</div>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Informações Rápidas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle"></i> Informações do Sistema</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check-circle text-success"></i> <strong>Última atualização:</strong> <?php echo date('d/m/Y H:i:s'); ?></li>
                            <li><i class="fas fa-database text-primary"></i> <strong>Total de registros:</strong> <?php echo array_sum($stats); ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-chart-line text-warning"></i> <strong>Taxa de ocupação de salas:</strong> Calculando...</li>
                            <li><i class="fas fa-users text-info"></i> <strong>Média de turmas por curso:</strong> <?php echo $stats['total_cursos'] > 0 ? round($stats['total_turmas'] / $stats['total_cursos'], 1) : 0; ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
    </script>
</body>
</html>