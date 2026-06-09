<?php
// super-admin/comunicacao/notificacoes.php - Gerenciar notificações
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Marcar como lida
if (isset($_GET['marcar_lida']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE id = :id");
    $stmt->execute([':id' => $id]);
    header("Location: notificacoes.php");
    exit;
}

// Marcar todas como lidas
if (isset($_GET['marcar_todas'])) {
    $stmt = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE escola_id IS NULL");
    $stmt->execute();
    header("Location: notificacoes.php");
    exit;
}

// Excluir notificação
if (isset($_GET['excluir']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM notificacoes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    header("Location: notificacoes.php");
    exit;
}

// Listar notificações
$status_filter = $_GET['status'] ?? '';
$limit = $_GET['limit'] ?? 50;

$query = "
    SELECT n.*, e.nome as escola_nome, e.subdominio
    FROM notificacoes n
    LEFT JOIN escolas e ON e.id = n.escola_id
    WHERE n.escola_id IS NULL
";

if ($status_filter == 'nao_lidas') {
    $query .= " AND n.lida = 0";
} elseif ($status_filter == 'lidas') {
    $query .= " AND n.lida = 1";
}

$query .= " ORDER BY n.created_at DESC LIMIT :limit";

$stmt = $conn->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stmt = $conn->query("SELECT COUNT(*) as total FROM notificacoes WHERE escola_id IS NULL");
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM notificacoes WHERE escola_id IS NULL AND lida = 0");
$nao_lidas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
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
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Navegação */
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #006B3E;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        
        .notification-item {
            border-left: 3px solid;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-nao_lida {
            border-left-color: #006B3E;
            background: #f0fdf4;
        }
        
        .notification-lida {
            border-left-color: #ccc;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
            <div class="user-info-sidebar">
                <small><i class="fas fa-user-shield"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <!-- Configurações com Submenu -->
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-cog"></i> Configurações</a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../config/sistema.php" class="nav-link"><i class="fas fa-globe"></i> Configurações do Sistema</a></li>
                    <li class="nav-item"><a href="../config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões e Papéis</a></li>
                </ul>
            </li>
            
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-bell"></i> Notificações</h2>
            <div>
                <a href="?marcar_todas=1" class="btn btn-sm btn-warning" onclick="return confirm('Marcar todas como lidas?')"><i class="fas fa-check-double"></i> Marcar todas</a>
                <a href="enviar.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Nova Notificação</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $total; ?></div><div>Total de Notificações</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $nao_lidas; ?></div><div>Não Lidas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $total - $nao_lidas; ?></div><div>Lidas</div></div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Histórico de Notificações
                <div class="btn-group ms-3">
                    <a href="notificacoes.php" class="btn btn-sm btn-secondary">Todas</a>
                    <a href="?status=nao_lidas" class="btn btn-sm btn-warning">Não Lidas</a>
                    <a href="?status=lidas" class="btn btn-sm btn-success">Lidas</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($notificacoes as $notif): ?>
                    <div class="list-group-item notification-item notification-<?php echo $notif['lida'] ? 'lida' : 'nao_lida'; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <strong><?php echo htmlspecialchars($notif['titulo']); ?></strong>
                                    <?php if (!$notif['lida']): ?>
                                        <span class="badge bg-success">Nova</span>
                                    <?php endif; ?>
                                    <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?></small>
                                </div>
                                <p class="mb-1"><?php echo nl2br(htmlspecialchars($notif['mensagem'])); ?></p>
                                <small class="text-muted">
                                    <i class="fas fa-tag"></i> Tipo: <?php echo ucfirst($notif['tipo']); ?> |
                                    <i class="fas fa-flag"></i> Prioridade: <?php echo ucfirst($notif['prioridade'] ?? 'normal'); ?>
                                </small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <?php if (!$notif['lida']): ?>
                                <a href="?marcar_lida=1&id=<?php echo $notif['id']; ?>" class="btn btn-success" title="Marcar como lida">
                                    <i class="fas fa-check"></i>
                                </a>
                                <?php endif; ?>
                                <a href="?excluir=1&id=<?php echo $notif['id']; ?>" class="btn btn-danger" title="Excluir" onclick="return confirm('Excluir esta notificação?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($notificacoes)): ?>
                    <div class="list-group-item text-center">Nenhuma notificação encontrada</div>
                    <?php endif; ?>
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
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatorios').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>