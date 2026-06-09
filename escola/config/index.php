<?php
// escola/config/index.php - Menu Principal de Configurações
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar estatísticas
$stats = [];

// Total de anos letivos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM anos_letivos WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['anos_letivos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de países
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM paises");
$stmt->execute();
$stats['paises'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de províncias
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM angola_provincias");
$stmt->execute();
$stats['provincias'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Status do lançamento de notas
$stmt = $conn->prepare("
    SELECT status, data_abertura, data_fechamento 
    FROM configuracoes_sistema 
    WHERE escola_id = :escola_id AND chave = 'lancamento_notas'
");
$stmt->execute([':escola_id' => $escola_id]);
$lancamento_notas = $stmt->fetch(PDO::FETCH_ASSOC);
$status_notas = $lancamento_notas['status'] ?? 'fechado';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações | SIGE Angola</title>
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
        
        .modulo-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: block;
            height: 100%;
        }
        .modulo-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: #006B3E; }
        .modulo-icon { font-size: 3em; margin-bottom: 15px; }
        .modulo-title { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
        .modulo-desc { font-size: 0.85em; color: #666; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: bold;
        }
        .status-aberto { background: #d4edda; color: #155724; }
        .status-fechado { background: #f8d7da; color: #721c24; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
            <div class="user-info-sidebar mt-2">
                <small><i class="fas fa-user"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu" id="menuConfig">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i> <span>Configurações</span>
                </a>
                <ul class="nav-submenu show" id="submenuConfig">
                    <li class="nav-item"><a href="geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-cogs"></i> Configurações do Sistema</h2>
            <div>
                <span class="status-badge <?php echo $status_notas == 'aberto' ? 'status-aberto' : 'status-fechado'; ?>">
                    <i class="fas <?php echo $status_notas == 'aberto' ? 'fa-lock-open' : 'fa-lock'; ?>"></i>
                    Lançamento de Notas: <?php echo $status_notas == 'aberto' ? 'ABERTO' : 'FECHADO'; ?>
                </span>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['anos_letivos']; ?></div>
                <div>Anos Letivos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['paises']; ?></div>
                <div>Países</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['provincias']; ?></div>
                <div>Províncias</div>
            </div>
        </div>
        
        <!-- Módulos -->
        <div class="row">
            <div class="col-md-3 mb-4">
                <a href="geral/index.php" class="modulo-card">
                    <div class="modulo-icon"><i class="fas fa-globe text-primary"></i></div>
                    <div class="modulo-title">Geral</div>
                    <div class="modulo-desc">Gerir anos letivos, meses, países, províncias, comunas e bairros</div>
                </a>
            </div>
            <div class="col-md-3 mb-4">
                <a href="banco/index.php" class="modulo-card">
                    <div class="modulo-icon"><i class="fas fa-university text-success"></i></div>
                    <div class="modulo-title">Banco</div>
                    <div class="modulo-desc">Configurações bancárias, contas e transferências</div>
                </a>
            </div>
            <div class="col-md-3 mb-4">
                <a href="pagamento/index.php" class="modulo-card">
                    <div class="modulo-icon"><i class="fas fa-credit-card text-warning"></i></div>
                    <div class="modulo-title">Forma de Pagamento</div>
                    <div class="modulo-desc">Gerir formas de pagamento, taxas e recibos</div>
                </a>
            </div>
            <div class="col-md-3 mb-4">
                <a href="sistema/index.php" class="modulo-card">
                    <div class="modulo-icon"><i class="fas fa-chalkboard text-danger"></i></div>
                    <div class="modulo-title">Abrir Sistema</div>
                    <div class="modulo-desc">Calendário de provas e lançamento de notas</div>
                </a>
            </div>
        </div>
        
        <!-- Informações Rápidas -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> Informações do Sistema
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-calendar text-primary"></i> <strong>Ano Letivo Atual:</strong> 
                                <?php
                                $stmt = $conn->prepare("SELECT ano FROM anos_letivos WHERE escola_id = :escola_id AND status = 'ativo'");
                                $stmt->execute([':escola_id' => $escola_id]);
                                $ano_atual = $stmt->fetch(PDO::FETCH_ASSOC);
                                echo $ano_atual['ano'] ?? 'Nenhum ano letivo ativo';
                                ?>
                            </li>
                            <li><i class="fas fa-percent text-success"></i> <strong>Taxa de Multa Padrão:</strong> 10%</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-credit-card text-warning"></i> <strong>Formas de Pagamento Ativas:</strong> 
                                <?php
                                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo'");
                                $stmt->execute([':escola_id' => $escola_id]);
                                echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                            </li>
                            <li><i class="fas fa-file-alt text-info"></i> <strong>Última Atualização:</strong> <?php echo date('d/m/Y H:i'); ?></li>
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