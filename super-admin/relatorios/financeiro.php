<?php
// super-admin/relatorios/financeiro.php - Relatório Financeiro
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$ano = $_GET['ano'] ?? date('Y');
$mes = $_GET['mes'] ?? date('m');
$export = $_GET['export'] ?? '';

// ============================================
// DADOS PARA RELATÓRIOS
// ============================================

// Resumo financeiro geral
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as total_recebido,
        SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) as total_pendente,
        SUM(CASE WHEN status = 'cancelado' THEN valor ELSE 0 END) as total_cancelado,
        COUNT(CASE WHEN status = 'pago' THEN 1 END) as qtd_pagos,
        COUNT(CASE WHEN status = 'pendente' THEN 1 END) as qtd_pendentes
    FROM pagamentos
    WHERE YEAR(created_at) = :ano
");
$stmt->execute([':ano' => $ano]);
$resumo_geral = $stmt->fetch(PDO::FETCH_ASSOC);

// Faturamento mensal do ano
$stmt = $conn->prepare("
    SELECT 
        MONTH(data_pagamento) as mes,
        SUM(valor) as total
    FROM pagamentos
    WHERE status = 'pago' AND YEAR(data_pagamento) = :ano
    GROUP BY MONTH(data_pagamento)
    ORDER BY mes ASC
");
$stmt->execute([':ano' => $ano]);
$faturamento_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preencher meses faltantes
$meses_nomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$dados_faturamento = array_fill(0, 12, 0);
foreach ($faturamento_mensal as $f) {
    $dados_faturamento[$f['mes'] - 1] = $f['total'];
}

// Pagamentos por método (ano atual)
$stmt = $conn->prepare("
    SELECT 
        metodo_pagamento,
        COUNT(*) as quantidade,
        SUM(valor) as total
    FROM pagamentos
    WHERE status = 'pago' AND YEAR(data_pagamento) = :ano
    GROUP BY metodo_pagamento
    ORDER BY total DESC
");
$stmt->execute([':ano' => $ano]);
$pagamentos_metodo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Receita por plano
$stmt = $conn->prepare("
    SELECT 
        p.nome as plano_nome,
        COUNT(pg.id) as quantidade,
        SUM(pg.valor) as total
    FROM pagamentos pg
    JOIN assinaturas a ON a.id = pg.assinatura_id
    JOIN planos p ON p.id = a.plano_id
    WHERE pg.status = 'pago' AND YEAR(pg.data_pagamento) = :ano
    GROUP BY p.id
    ORDER BY total DESC
");
$stmt->execute([':ano' => $ano]);
$receita_planos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Inadimplência (pagamentos atrasados)
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(valor) as valor_total,
        AVG(DATEDIFF(CURDATE(), data_vencimento)) as dias_atraso_medio
    FROM pagamentos
    WHERE status = 'pendente' AND data_vencimento < CURDATE()
");
$stmt->execute();
$inadimplencia = $stmt->fetch(PDO::FETCH_ASSOC);

// Previsão de receita para próximos meses
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(data_vencimento, '%Y-%m') as mes,
        SUM(valor) as total_previsto
    FROM pagamentos
    WHERE status = 'pendente' AND data_vencimento >= CURDATE()
    GROUP BY DATE_FORMAT(data_vencimento, '%Y-%m')
    ORDER BY mes ASC
    LIMIT 6
");
$stmt->execute();
$previsao_receita = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top escolas que mais pagaram
$stmt = $conn->prepare("
    SELECT 
        e.nome,
        e.subdominio,
        COUNT(pg.id) as total_pagamentos,
        SUM(pg.valor) as total_pago
    FROM pagamentos pg
    JOIN escolas e ON e.id = pg.escola_id
    WHERE pg.status = 'pago' AND YEAR(pg.data_pagamento) = :ano
    GROUP BY e.id
    ORDER BY total_pago DESC
    LIMIT 10
");
$stmt->execute([':ano' => $ano]);
$top_escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Exportar CSV
if ($export == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_financeiro_' . $ano . '_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['RELATÓRIO FINANCEIRO SIGE ANGOLA']);
    fputcsv($output, ['Período:', $ano]);
    fputcsv($output, ['Data de geração:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['RESUMO GERAL']);
    fputcsv($output, ['Total Recebido', 'KZ ' . number_format($resumo_geral['total_recebido'], 2, ',', '.')]);
    fputcsv($output, ['Total Pendente', 'KZ ' . number_format($resumo_geral['total_pendente'], 2, ',', '.')]);
    fputcsv($output, ['Pagamentos Realizados', $resumo_geral['qtd_pagos']]);
    fputcsv($output, ['Pagamentos Pendentes', $resumo_geral['qtd_pendentes']]);
    fputcsv($output, []);
    fputcsv($output, ['FATURAMENTO MENSAL']);
    foreach ($meses_nomes as $i => $mes_nome) {
        fputcsv($output, [$mes_nome, 'KZ ' . number_format($dados_faturamento[$i], 2, ',', '.')]);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Financeiro | SIGE Angola</title>
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
        
        .print-only { display: none; }
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .print-only { display: block; }
            .card { box-shadow: none; border: 1px solid #ddd; }
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
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="escolas.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            
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
            <h2><i class="fas fa-chart-pie"></i> Relatório Financeiro</h2>
            <div class="no-print">
                <form method="GET" class="d-inline-flex gap-2 me-2">
                    <select name="ano" class="form-control form-control-sm w-auto">
                        <?php for ($i = 2024; $i <= date('Y'); $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
                <a href="?export=csv&ano=<?php echo $ano; ?>" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Exportar CSV</a>
                <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Imprimir</button>
            </div>
        </div>
        
        <div class="print-only text-center mb-4">
            <h2>SIGE Angola</h2>
            <h4>Relatório Financeiro - <?php echo $ano; ?></h4>
            <p>Gerado em: <?php echo date('d/m/Y H:i:s'); ?></p>
            <hr>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value">KZ <?php echo number_format($resumo_geral['total_recebido'] ?? 0, 2, ',', '.'); ?></div><div class="stat-label">Total Recebido</div><small><?php echo $resumo_geral['qtd_pagos'] ?? 0; ?> pagamentos</small></div>
            <div class="stat-card"><div class="stat-value">KZ <?php echo number_format($resumo_geral['total_pendente'] ?? 0, 2, ',', '.'); ?></div><div class="stat-label">Total Pendente</div><small><?php echo $resumo_geral['qtd_pendentes'] ?? 0; ?> pendentes</small></div>
            <div class="stat-card"><div class="stat-value">KZ <?php echo number_format($inadimplencia['valor_total'] ?? 0, 2, ',', '.'); ?></div><div class="stat-label">Inadimplência</div><small>Média: <?php echo round($inadimplencia['dias_atraso_medio'] ?? 0); ?> dias</small></div>
            <div class="stat-card"><div class="stat-value"><?php echo number_format(($resumo_geral['total_recebido'] > 0 ? ($resumo_geral['total_pendente'] / $resumo_geral['total_recebido'] * 100) : 0), 1); ?>%</div><div class="stat-label">Taxa de Inadimplência</div><small>Meta: < 5%</small></div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Faturamento Mensal - <?php echo $ano; ?></div>
                    <div class="card-body"><canvas id="faturamentoChart" height="300"></canvas></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Receita por Plano</div>
                    <div class="card-body">
                        <canvas id="planosChart" height="250"></canvas>
                        <div class="mt-3"><?php foreach ($receita_planos as $plano): ?><div class="d-flex justify-content-between align-items-center mb-2"><span><?php echo $plano['plano_nome']; ?></span><span class="badge bg-secondary">KZ <?php echo number_format($plano['total'], 2, ',', '.'); ?></span></div><?php endforeach; ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Pagamentos por Método</div>
                    <div class="card-body">
                        <canvas id="metodosChart" height="250"></canvas>
                        <div class="mt-3"><?php $metodos_nomes = ['dinheiro'=>'Dinheiro','transferencia'=>'Transferência','deposito'=>'Depósito','cartao'=>'Cartão','multicaixa'=>'Multicaixa']; foreach ($pagamentos_metodo as $metodo): ?><div class="d-flex justify-content-between align-items-center mb-2"><span><?php echo $metodos_nomes[$metodo['metodo_pagamento']] ?? ucfirst($metodo['metodo_pagamento']); ?></span><span><?php echo $metodo['quantidade']; ?> transações</span><span class="badge bg-info">KZ <?php echo number_format($metodo['total'], 2, ',', '.'); ?></span></div><?php endforeach; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar-alt"></i> Previsão de Receita</div>
                    <div class="card-body p-0">
                        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Mês</th><th>Valor Previsto</th><th>Status</th></tr></thead><tbody><?php foreach ($previsao_receita as $prev): ?><tr><td><?php echo date('F/Y', strtotime($prev['mes'] . '-01')); ?></td><td>KZ <?php echo number_format($prev['total_previsto'], 2, ',', '.'); ?></td><td><span class="badge bg-warning">Pendente</span></td></tr><?php endforeach; ?><?php if (empty($previsao_receita)): ?><tr><td colspan="3" class="text-center">Nenhuma previsão para os próximos meses</td></tr><?php endif; ?></tbody></table></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-trophy"></i> Top 10 Escolas que Mais Pagaram - <?php echo $ano; ?></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover"><thead><tr><th>#</th><th>Escola</th><th>Subdomínio</th><th>Nº Pagamentos</th><th>Total Pago</th></tr></thead><tbody><?php foreach ($top_escolas as $i => $escola): ?><tr><td><?php echo $i + 1; ?></td><td><strong><?php echo htmlspecialchars($escola['nome']); ?></strong></td><td><?php echo $escola['subdominio']; ?>.sige.ao</td><td><?php echo $escola['total_pagamentos']; ?></td><td class="text-success">KZ <?php echo number_format($escola['total_pago'], 2, ',', '.'); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
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
        
        // Gráfico de faturamento mensal
        new Chart(document.getElementById('faturamentoChart'), {
            type: 'line', data: { labels: <?php echo json_encode($meses_nomes); ?>, datasets: [{ label: 'Faturamento (KZ)', data: <?php echo json_encode($dados_faturamento); ?>, borderColor: '#006B3E', backgroundColor: 'rgba(0, 107, 62, 0.1)', tension: 0.4, fill: true }] }, options: { responsive: true, plugins: { tooltip: { callbacks: { label: function(context) { return 'KZ ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}); } } } } }
        });
        
        // Gráfico de receita por plano
        new Chart(document.getElementById('planosChart'), {
            type: 'doughnut', data: { labels: <?php echo json_encode(array_column($receita_planos, 'plano_nome')); ?>, datasets: [{ data: <?php echo json_encode(array_column($receita_planos, 'total')); ?>, backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107', '#dc3545'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return context.label + ': KZ ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}); } } } } }
        });
        
        // Gráfico de métodos de pagamento
        new Chart(document.getElementById('metodosChart'), {
            type: 'bar', data: { labels: <?php 
                $labels = []; foreach ($pagamentos_metodo as $m) { $metodos_nomes = ['dinheiro'=>'Dinheiro','transferencia'=>'Transferência','deposito'=>'Depósito','cartao'=>'Cartão','multicaixa'=>'Multicaixa']; $labels[] = $metodos_nomes[$m['metodo_pagamento']] ?? ucfirst($m['metodo_pagamento']); } echo json_encode($labels); 
            ?>, datasets: [{ label: 'Valor (KZ)', data: <?php echo json_encode(array_column($pagamentos_metodo, 'total')); ?>, backgroundColor: '#006B3E' }] }, options: { responsive: true, plugins: { tooltip: { callbacks: { label: function(context) { return 'KZ ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}); } } } } }
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>