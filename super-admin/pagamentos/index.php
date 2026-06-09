<?php
// super-admin/pagamentos/index.php - Gestão de Pagamentos
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$status_filter = $_GET['status'] ?? '';
$mes_filter = $_GET['mes'] ?? date('Y-m');

$query = "
    SELECT p.*, e.nome as escola_nome, e.subdominio, e.logo,
           a.tipo_cobranca, pl.nome as plano_nome
    FROM pagamentos p
    JOIN escolas e ON e.id = p.escola_id
    JOIN assinaturas a ON a.id = p.assinatura_id
    JOIN planos pl ON pl.id = a.plano_id
    WHERE 1=1
";

$params = [];
if ($status_filter) {
    $query .= " AND p.status = :status";
    $params[':status'] = $status_filter;
}
if ($mes_filter) {
    $query .= " AND DATE_FORMAT(p.created_at, '%Y-%m') = :mes";
    $params[':mes'] = $mes_filter;
}

$query .= " ORDER BY p.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumo financeiro
$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago'");
$total_recebido = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago' AND MONTH(data_pagamento) = MONTH(CURDATE())");
$total_mes = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->query("SELECT COUNT(*) as total FROM pagamentos WHERE status = 'pendente'");
$pendentes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos | SIGE Angola</title>
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
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-pago { background: #e8f5e9; color: #388e3c; }
        .status-pendente { background: #fff3e0; color: #f57c00; }
        .status-cancelado { background: #ffebee; color: #d32f2f; }
        
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
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
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
            <h2><i class="fas fa-money-bill-wave"></i> Pagamentos</h2>
            <a href="registrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Registrar Pagamento</a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">KZ <?php echo number_format($total_recebido, 2, ',', '.'); ?></div>
                <div>Total Recebido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">KZ <?php echo number_format($total_mes, 2, ',', '.'); ?></div>
                <div>Recebido no Mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $pendentes; ?></div>
                <div>Pagamentos Pendentes</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filtros
                <form method="GET" class="d-inline-flex gap-2 ms-3">
                    <select name="status" class="form-control form-control-sm w-auto">
                        <option value="">Todos os status</option>
                        <option value="pago" <?php echo $status_filter == 'pago' ? 'selected' : ''; ?>>Pagos</option>
                        <option value="pendente" <?php echo $status_filter == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="cancelado" <?php echo $status_filter == 'cancelado' ? 'selected' : ''; ?>>Cancelados</option>
                    </select>
                    <input type="month" name="mes" class="form-control form-control-sm w-auto" value="<?php echo $mes_filter; ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Escola</th>
                                <th>Plano</th>
                                <th>Valor</th>
                                <th>Referente</th>
                                <th>Vencimento</th>
                                <th>Data Pagamento</th>
                                <th>Método</th>
                                <th>Status</th>
                                <th>Comprovante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagamentos as $p): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($p['escola_nome']); ?></strong><br>
                                    <small><?php echo $p['subdominio']; ?>.sige.ao</small>
                                 </div>
                                </td>
                                <td><?php echo $p['plano_nome']; ?></td>
                                <td>KZ <?php echo number_format($p['valor'], 2, ',', '.'); ?></td>
                                <td><?php echo $p['referente']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($p['data_vencimento'])); ?></td>
                                <td><?php echo $p['data_pagamento'] ? date('d/m/Y', strtotime($p['data_pagamento'])) : '-'; ?></td>
                                <td>
                                    <?php
                                    $metodos = ['dinheiro'=>'Dinheiro','transferencia'=>'Transferência','deposito'=>'Depósito','cartao'=>'Cartão','multicaixa'=>'Multicaixa'];
                                    echo $metodos[$p['metodo_pagamento']] ?? ucfirst($p['metodo_pagamento']);
                                    ?>
                                 </div>
                                </td>
                                <td><span class="status-badge status-<?php echo $p['status']; ?>"><?php echo ucfirst($p['status']); ?></span></td>
                                <td>
                                    <?php if ($p['comprovante']): ?>
                                        <a href="../../uploads/comprovantes/<?php echo $p['comprovante']; ?>" target="_blank" class="btn btn-sm btn-info" title="Ver Comprovante">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($p['status'] == 'pendente'): ?>
                                        <a href="registrar.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-success" title="Registrar Pagamento">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php endif; ?>
                                 </div>
                                 </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pagamentos)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Nenhum pagamento encontrado</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
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