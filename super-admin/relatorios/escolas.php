<?php
// super-admin/relatorios/escolas.php - Relatório de Escolas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$status_filter = $_GET['status'] ?? '';
$provincia_filter = $_GET['provincia'] ?? '';
$plano_filter = $_GET['plano'] ?? '';
$export = $_GET['export'] ?? '';

// Buscar dados das escolas
$query = "
    SELECT 
        e.*, 
        p.nome as plano_nome,
        (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
        (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id AND status = 'ativa') as tem_assinatura,
        (SELECT COUNT(*) FROM pagamentos WHERE escola_id = e.id AND status = 'pago') as total_pagamentos,
        (SELECT SUM(valor) FROM pagamentos WHERE escola_id = e.id AND status = 'pago') as total_recebido
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE 1=1
";

$params = [];
if ($status_filter) {
    $query .= " AND e.status = :status";
    $params[':status'] = $status_filter;
}
if ($provincia_filter) {
    $query .= " AND e.provincia = :provincia";
    $params[':provincia'] = $provincia_filter;
}
if ($plano_filter) {
    $query .= " AND e.plano_id = :plano";
    $params[':plano'] = $plano_filter;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista de Províncias de Angola
$provincias = [
    'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 
    'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 
    'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 
    'Namibe', 'Uíge', 'Zaire'
];

// Buscar planos para filtro
$planos = $conn->query("SELECT id, nome FROM planos WHERE status = 'ativo' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS PARA GRÁFICOS
// ============================================

// Resumo por status
$resumo_status = [];
$stmt = $conn->query("SELECT status, COUNT(*) as total FROM escolas GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $resumo_status[$row['status']] = $row['total'];
}

// Resumo por província
$stmt = $conn->query("
    SELECT provincia, COUNT(*) as total 
    FROM escolas 
    WHERE provincia IS NOT NULL 
    GROUP BY provincia 
    ORDER BY total DESC 
    LIMIT 10
");
$resumo_provincia = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumo por plano
$stmt = $conn->query("
    SELECT p.nome as plano_nome, COUNT(e.id) as total 
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    GROUP BY p.id
    ORDER BY total DESC
");
$resumo_plano = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento anual de escolas
$stmt = $conn->query("
    SELECT 
        YEAR(created_at) as ano,
        COUNT(*) as total
    FROM escolas
    GROUP BY YEAR(created_at)
    ORDER BY ano ASC
");
$crescimento_anual = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento mensal do ano atual
$ano_atual = date('Y');
$stmt = $conn->prepare("
    SELECT 
        MONTH(created_at) as mes,
        COUNT(*) as total
    FROM escolas
    WHERE YEAR(created_at) = :ano
    GROUP BY MONTH(created_at)
    ORDER BY mes ASC
");
$stmt->execute([':ano' => $ano_atual]);
$crescimento_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses_nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$dados_crescimento = array_fill(0, 12, 0);
foreach ($crescimento_mensal as $c) {
    $dados_crescimento[$c['mes'] - 1] = $c['total'];
}

// Top escolas com mais usuários
$stmt = $conn->query("
    SELECT e.nome, e.subdominio, COUNT(u.id) as total_usuarios
    FROM escolas e
    JOIN usuarios u ON u.escola_id = e.id
    GROUP BY e.id
    ORDER BY total_usuarios DESC
    LIMIT 10
");
$top_escolas_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// EXPORTAR CSV
// ============================================
if ($export == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_escolas_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['RELATÓRIO DE ESCOLAS - SIGE ANGOLA']);
    fputcsv($output, ['Data de geração:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['FILTROS APLICADOS:']);
    fputcsv($output, ['Status:', $status_filter ?: 'Todos']);
    fputcsv($output, ['Província:', $provincia_filter ?: 'Todas']);
    fputcsv($output, ['Plano:', $plano_filter ?: 'Todos']);
    fputcsv($output, []);
    fputcsv($output, ['LISTA DE ESCOLAS']);
    fputcsv($output, [
        'ID', 'Escola', 'Subdomínio', 'E-mail', 'Telefone', 'Província', 
        'Município', 'Plano', 'Status', 'Usuários', 'Pagamentos', 
        'Total Recebido', 'Data Cadastro'
    ]);
    
    foreach ($escolas as $e) {
        fputcsv($output, [
            $e['id'],
            $e['nome'],
            $e['subdominio'] . '.sige.ao',
            $e['email'],
            $e['telefone'] ?? '-',
            $e['provincia'] ?? '-',
            $e['municipio'] ?? '-',
            $e['plano_nome'] ?? '-',
            $e['status'],
            $e['total_usuarios'],
            $e['total_pagamentos'] ?? 0,
            'KZ ' . number_format($e['total_recebido'] ?? 0, 2, ',', '.'),
            date('d/m/Y', strtotime($e['created_at']))
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['RESUMO ESTATÍSTICO']);
    fputcsv($output, ['Total de Escolas:', count($escolas)]);
    fputcsv($output, ['Escolas Ativas:', $resumo_status['ativa'] ?? 0]);
    fputcsv($output, ['Escolas em Trial:', $resumo_status['trial'] ?? 0]);
    fputcsv($output, ['Escolas Suspensas:', $resumo_status['suspensa'] ?? 0]);
    fputcsv($output, ['Total de Usuários:', array_sum(array_column($escolas, 'total_usuarios'))]);
    fputcsv($output, ['Total Recebido:', 'KZ ' . number_format(array_sum(array_column($escolas, 'total_recebido')), 2, ',', '.')]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Escolas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        
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
        
        .print-only { display: none; }
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .print-only { display: block; }
            .card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
        }
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
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
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
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
            <h2><i class="fas fa-chart-line"></i> Relatório de Escolas</h2>
            <div class="no-print">
                <a href="?export=csv<?php echo $status_filter ? '&status='.$status_filter : ''; ?><?php echo $provincia_filter ? '&provincia='.$provincia_filter : ''; ?><?php echo $plano_filter ? '&plano='.$plano_filter : ''; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Exportar CSV
                </a>
                <button onclick="window.print()" class="btn btn-secondary btn-sm ms-2">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <div class="print-only text-center mb-4">
            <h2>SIGE Angola</h2>
            <h4>Relatório de Escolas</h4>
            <p>Gerado em: <?php echo date('d/m/Y H:i:s'); ?></p>
            <hr>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo count($escolas); ?></div><div class="stat-label">Total de Escolas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $resumo_status['ativa'] ?? 0; ?></div><div class="stat-label">Escolas Ativas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $resumo_status['trial'] ?? 0; ?></div><div class="stat-label">Em Período Trial</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $resumo_status['suspensa'] ?? 0; ?></div><div class="stat-label">Escolas Suspensas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo array_sum(array_column($escolas, 'total_usuarios')); ?></div><div class="stat-label">Total de Usuários</div></div>
            <div class="stat-card"><div class="stat-value">KZ <?php echo number_format(array_sum(array_column($escolas, 'total_recebido')), 2, ',', '.'); ?></div><div class="stat-label">Total Recebido</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">Todos</option>
                        <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                        <option value="trial" <?php echo $status_filter == 'trial' ? 'selected' : ''; ?>>Trial</option>
                        <option value="suspensa" <?php echo $status_filter == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                        <option value="inativa" <?php echo $status_filter == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Província</label>
                    <select name="provincia" class="form-control">
                        <option value="">Todas</option>
                        <?php foreach ($provincias as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $provincia_filter == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Plano</label>
                    <select name="plano" class="form-control">
                        <option value="">Todos</option>
                        <?php foreach ($planos as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $plano_filter == $p['id'] ? 'selected' : ''; ?>><?php echo $p['nome']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Escolas por Status</div>
                    <div class="card-body">
                        <canvas id="statusChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Top 10 Províncias</div>
                    <div class="card-body">
                        <canvas id="provinciaChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição por Plano</div>
                    <div class="card-body">
                        <canvas id="planoChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento Anual</div>
                    <div class="card-body">
                        <canvas id="crescimentoChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento Mensal - <?php echo $ano_atual; ?></div>
                    <div class="card-body">
                        <canvas id="crescimentoMensalChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-trophy"></i> Top 10 Escolas com Mais Usuários</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th>#</th><th>Escola</th><th>Subdomínio</th><th>Usuários</th></tr></thead>
                                <tbody>
                                    <?php foreach ($top_escolas_usuarios as $i => $escola): ?>
                                    <tr><td><?php echo $i + 1; ?></td><td><strong><?php echo htmlspecialchars($escola['nome']); ?></strong></td><td><?php echo $escola['subdominio']; ?>.sige.ao</td><td><span class="badge bg-primary"><?php echo $escola['total_usuarios']; ?></span></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-simple"></i> Resumo por Status</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6"><div class="text-center mb-3"><h3><?php echo $resumo_status['ativa'] ?? 0; ?></h3><p class="text-success">Ativas</p><div class="progress"><div class="progress-bar bg-success" style="width: <?php echo ($resumo_status['ativa'] ?? 0) / max(count($escolas), 1) * 100; ?>%"></div></div></div></div>
                            <div class="col-md-6"><div class="text-center mb-3"><h3><?php echo $resumo_status['trial'] ?? 0; ?></h3><p class="text-info">Em Trial</p><div class="progress"><div class="progress-bar bg-info" style="width: <?php echo ($resumo_status['trial'] ?? 0) / max(count($escolas), 1) * 100; ?>%"></div></div></div></div>
                            <div class="col-md-6"><div class="text-center"><h3><?php echo $resumo_status['suspensa'] ?? 0; ?></h3><p class="text-warning">Suspensas</p><div class="progress"><div class="progress-bar bg-warning" style="width: <?php echo ($resumo_status['suspensa'] ?? 0) / max(count($escolas), 1) * 100; ?>%"></div></div></div></div>
                            <div class="col-md-6"><div class="text-center"><h3><?php echo $resumo_status['inativa'] ?? 0; ?></h3><p class="text-danger">Inativas</p><div class="progress"><div class="progress-bar bg-danger" style="width: <?php echo ($resumo_status['inativa'] ?? 0) / max(count($escolas), 1) * 100; ?>%"></div></div></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Escolas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Escolas <span class="badge bg-secondary ms-2"><?php echo count($escolas); ?> registros</span></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>#</th><th>Escola</th><th>Subdomínio</th><th>Província</th><th>Plano</th><th>Status</th><th>Usuários</th><th>Pagamentos</th><th>Total Recebido</th><th>Cadastro</th></tr></thead>
                        <tbody>
                            <?php foreach ($escolas as $i => $e): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($e['nome']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($e['email']); ?></small></td>
                                <td><?php echo $e['subdominio']; ?>.sige.ao</td>
                                <td><?php echo $e['provincia'] ?? '-'; ?></td>
                                <td><?php echo $e['plano_nome'] ?? '-'; ?></td>
                                <td><span class="status-badge status-<?php echo $e['status']; ?>"><?php echo ucfirst($e['status']); ?></span></td>
                                <td><?php echo $e['total_usuarios']; ?></td>
                                <td><?php echo $e['total_pagamentos'] ?? 0; ?></td>
                                <td class="text-success">KZ <?php echo number_format($e['total_recebido'] ?? 0, 2, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($e['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($escolas)): ?>
                            <tr><td colspan="10" class="text-center py-4"><i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>Nenhuma escola encontrada com os filtros selecionados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        // Gráfico de status
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: { labels: ['Ativas', 'Trial', 'Suspensas', 'Inativas'], datasets: [{ data: [<?php echo $resumo_status['ativa'] ?? 0; ?>, <?php echo $resumo_status['trial'] ?? 0; ?>, <?php echo $resumo_status['suspensa'] ?? 0; ?>, <?php echo $resumo_status['inativa'] ?? 0; ?>], backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Gráfico de províncias
        new Chart(document.getElementById('provinciaChart'), {
            type: 'bar',
            data: { labels: <?php echo json_encode(array_column($resumo_provincia, 'provincia')); ?>, datasets: [{ label: 'Número de Escolas', data: <?php echo json_encode(array_column($resumo_provincia, 'total')); ?>, backgroundColor: '#006B3E' }] },
            options: { responsive: true, indexAxis: 'y' }
        });
        
        // Gráfico de planos
        new Chart(document.getElementById('planoChart'), {
            type: 'pie',
            data: { labels: <?php echo json_encode(array_column($resumo_plano, 'plano_nome')); ?>, datasets: [{ data: <?php echo json_encode(array_column($resumo_plano, 'total')); ?>, backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Gráfico de crescimento anual
        new Chart(document.getElementById('crescimentoChart'), {
            type: 'line',
            data: { labels: <?php echo json_encode(array_column($crescimento_anual, 'ano')); ?>, datasets: [{ label: 'Novas Escolas', data: <?php echo json_encode(array_column($crescimento_anual, 'total')); ?>, borderColor: '#006B3E', backgroundColor: 'rgba(0, 107, 62, 0.1)', tension: 0.4, fill: true }] },
            options: { responsive: true }
        });
        
        // Gráfico de crescimento mensal
        new Chart(document.getElementById('crescimentoMensalChart'), {
            type: 'bar',
            data: { labels: <?php echo json_encode($meses_nomes); ?>, datasets: [{ label: 'Novas Escolas', data: <?php echo json_encode($dados_crescimento); ?>, backgroundColor: '#006B3E' }] },
            options: { responsive: true }
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>