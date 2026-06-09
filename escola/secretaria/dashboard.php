<?php
// escola/secretaria/index.php - Dashboard da Secretaria
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y') . '/' . (date('Y') + 1);
$ano_anterior = (date('Y') - 1) . '/' . date('Y');

// Estatísticas
$stats = [];

// Total de alunos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Alunos matriculados no ano letivo atual
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT e.id) as total 
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    WHERE e.escola_id = :escola_id AND m.ano_letivo = :ano_letivo AND m.status = 'ativa'
");
$stmt->execute([':escola_id' => $escola_id, ':ano_letivo' => $ano_letivo]);
$stats['matriculados'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Inscrições pendentes
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM inscricoes WHERE escola_id = :escola_id AND status = 'pendente'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['inscricoes_pendentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Alunos aptos para rematrícula
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT e.id) as total 
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    WHERE e.escola_id = :escola_id AND m.ano_letivo = :ano_anterior AND m.status = 'concluida'
");
$stmt->execute([':escola_id' => $escola_id, ':ano_anterior' => $ano_anterior]);
$stats['aptos_rematricula'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$stmt = $conn->prepare("
    SELECT COUNT(*) as total FROM professores p 
    JOIN usuarios u ON u.id = p.usuario_id 
    WHERE p.escola_id = :escola_id AND u.status = 'ativo'
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-submenu .nav-link { padding: 8px 25px; font-size: 0.9em; }
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
        .btn-primary:hover { background: #004d2d; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; margin-top: 5px; }
        .stat-icon { font-size: 2.5em; color: #006B3E; margin-bottom: 10px; }
        .menu-modulos { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .modulo-card { background: white; border-radius: 15px; padding: 25px; text-align: center; text-decoration: none; color: #333; transition: all 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .modulo-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: #006B3E; }
        .modulo-icon { font-size: 3em; margin-bottom: 15px; }
        .modulo-title { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
        .modulo-desc { font-size: 0.85em; color: #666; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .welcome-banner { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; }
        .ano-letivo { background: rgba(255,255,255,0.2); display: inline-block; padding: 5px 15px; border-radius: 20px; font-size: 0.9em; margin-top: 10px; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-building"></i> Secretaria Escolar</h2>
            <div>
                <span class="badge bg-success me-2">Ano Letivo: <?php echo $ano_letivo; ?></span>
                <a href="matricula.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Nova Matrícula</a>
            </div>
        </div>
        
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h3><i class="fas fa-greeting"></i> Bem-vindo(a), <?php echo $_SESSION['usuario_nome'] ?? 'Secretária(o)'; ?>!</h3>
            <p>Gerencie todas as operações administrativas da escola: matrículas, inscrições, rematrículas e consulta de alunos.</p>
            <div class="ano-letivo"><i class="fas fa-calendar"></i> Ano Letivo <?php echo $ano_letivo; ?></div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $stats['total_alunos']; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-value"><?php echo $stats['matriculados']; ?></div>
                <div class="stat-label">Matriculados <?php echo date('Y'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-value"><?php echo $stats['inscricoes_pendentes']; ?></div>
                <div class="stat-label">Inscrições Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-sync-alt"></i></div>
                <div class="stat-value"><?php echo $stats['aptos_rematricula']; ?></div>
                <div class="stat-label">Aptos à Rematrícula</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
                <div class="stat-value"><?php echo $stats['total_turmas']; ?></div>
                <div class="stat-label">Turmas Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                <div class="stat-value"><?php echo $stats['total_professores']; ?></div>
                <div class="stat-label">Professores</div>
            </div>
        </div>
        
        <!-- Módulos da Secretaria -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-th-large"></i> Módulos da Secretaria
            </div>
            <div class="card-body">
                <div class="menu-modulos">
                    <a href="lista_alunos.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-list text-primary"></i></div>
                        <div class="modulo-title">Lista de Alunos</div>
                        <div class="modulo-desc">Consultar todos os alunos cadastrados</div>
                    </a>
                    
                    <a href="alunos_matriculados.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-check-circle text-success"></i></div>
                        <div class="modulo-title">Alunos Matriculados</div>
                        <div class="modulo-desc">Alunos com matrícula ativa no ano letivo</div>
                    </a>
                    
                    <a href="inscricoes.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-file-signature text-warning"></i></div>
                        <div class="modulo-title">Inscrições</div>
                        <div class="modulo-desc">Gerir pré-inscrições e candidaturas</div>
                    </a>
                    
                    <a href="rematricula.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-sync-alt text-info"></i></div>
                        <div class="modulo-title">Rematrícula</div>
                        <div class="modulo-desc">Renovação de matrícula anual</div>
                    </a>
                    
                    <a href="matricula.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-user-plus text-danger"></i></div>
                        <div class="modulo-title">Matrícula</div>
                        <div class="modulo-desc">Nova matrícula de alunos</div>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Últimas Inscrições -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clock"></i> Últimas Inscrições Pendentes
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Nome</th>
                                <th>Classe Pretendida</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $conn->prepare("
                                SELECT * FROM inscricoes 
                                WHERE escola_id = :escola_id AND status = 'pendente' 
                                ORDER BY data_inscricao DESC LIMIT 5
                            ");
                            $stmt->execute([':escola_id' => $escola_id]);
                            $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php foreach ($inscricoes as $inscricao): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($inscricao['data_inscricao'])); ?></td>
                                <td><?php echo htmlspecialchars($inscricao['aluno_nome']); ?></td>
                                <td><?php echo $inscricao['classe_pretendida']; ?></td>
                                <td><span class="badge bg-warning">Pendente</span></td>
                                <td>
                                    <a href="inscricoes.php?acao=aprovar&id=<?php echo $inscricao['id']; ?>" class="btn btn-sm btn-success">Aprovar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($inscricoes)): ?>
                            <tr>
                                <td colspan="5" class="text-center">Nenhuma inscrição pendente</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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