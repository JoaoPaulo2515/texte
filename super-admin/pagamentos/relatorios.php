<?php
// super-admin/pagamentos/relatorios.php - Relatórios financeiros
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

// Resumo por mês do ano
$stmt = $conn->prepare("
    SELECT 
        MONTH(data_pagamento) as mes,
        COUNT(*) as total_pagamentos,
        SUM(valor) as total_valor
    FROM pagamentos
    WHERE status = 'pago' AND YEAR(data_pagamento) = :ano
    GROUP BY MONTH(data_pagamento)
    ORDER BY mes ASC
");
$stmt->execute([':ano' => $ano]);
$resumo_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preencher meses faltantes
$meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$dados_grafico = array_fill(0, 12, 0);
foreach ($resumo_mensal as $r) {
    $dados_grafico[$r['mes'] - 1] = $r['total_valor'];
}

// Pagamentos por método
$stmt = $conn->prepare("
    SELECT metodo_pagamento, COUNT(*) as total, SUM(valor) as total_valor
    FROM pagamentos
    WHERE status = 'pago' AND YEAR(data_pagamento) = :ano
    GROUP BY metodo_pagamento
");
$stmt->execute([':ano' => $ano]);
$por_metodo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top escolas que mais pagaram
$stmt = $conn->prepare("
    SELECT e.nome, e.subdominio, COUNT(p.id) as total_pagamentos, SUM(p.valor) as total_valor
    FROM pagamentos p
    JOIN escolas e ON e.id = p.escola_id
    WHERE p.status = 'pago' AND YEAR(p.data_pagamento) = :ano
    GROUP BY e.id
    ORDER BY total_valor DESC
    LIMIT 10
");
$stmt->execute([':ano' => $ano]);
$top_escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumo geral
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as total_pago,
        SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) as total_pendente,
        COUNT(CASE WHEN status = 'pago' THEN 1 END) as qtd_pago,
        COUNT(CASE WHEN status = 'pendente' THEN 1 END) as qtd_pendente
    FROM pagamentos
    WHERE YEAR(created_at) = :ano
");
$stmt->execute([':ano' => $ano]);
$resumo_geral = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Financeiros | SIGE Angola</title>
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
            <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="relatorios.php" class="nav-link active"><i class="fas fa-chart-line"></i> Relatórios</a></li>
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
            <h2><i class="fas fa-chart-line"></i> Relatórios Financeiros</h2>
            <form method="GET" class="d-flex gap-2">
                <select name="ano" class="form-control w-auto">
                    <?php for ($i = 2024; $i <= date('Y'); $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </form>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">KZ <?php echo number_format($resumo_geral['total_pago'] ?? 0, 2, ',', '.'); ?></div>
                <div>Total Recebido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">KZ <?php echo number_format($resumo_geral['total_pendente'] ?? 0, 2, ',', '.'); ?></div>
                <div>Valor Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $resumo_geral['qtd_pago'] ?? 0; ?></div>
                <div>Pagamentos Realizados</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $resumo_geral['qtd_pendente'] ?? 0; ?></div>
                <div>Pagamentos Pendentes</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Faturamento Mensal - <?php echo $ano; ?></div>
                    <div class="card-body">
                        <canvas id="faturamentoChart" height="300"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Pagamentos por Método</div>
                    <div class="card-body">
                        <canvas id="metodosChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($por_metodo as $m): 
                                $metodos = ['dinheiro'=>'Dinheiro','transferencia'=>'Transferência','deposito'=>'Depósito','cartao'=>'Cartão','multicaixa'=>'Multicaixa'];
                            ?>
                            <div class="d-flex justify-content-between">
                                <span><?php echo $metodos[$m['metodo_pagamento']] ?? ucfirst($m['metodo_pagamento']); ?></span>
                                <span>KZ <?php echo number_format($m['total_valor'], 2, ',', '.'); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-trophy"></i> Top 10 Escolas que Mais Pagaram - <?php echo $ano; ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>#</th><th>Escola</th><th>Subdomínio</th><th>Nº Pagamentos</th><th>Total Pago</th><th>Média</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_escolas as $i => $e): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($e['nome']); ?></strong></td>
                                <td><?php echo $e['subdominio']; ?>.sige.ao</small></td>
                                <td><?php echo $e['total_pagamentos']; ?></td>
                                <td>KZ <?php echo number_format($e['total_valor'], 2, ',', '.'); ?></td>
                                <td>KZ <?php echo number_format($e['total_valor'] / $e['total_pagamentos'], 2, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
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
        
        // Gráfico de faturamento mensal
        const ctx1 = document.getElementById('faturamentoChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($meses); ?>,
                datasets: [{
                    label: 'Faturamento (KZ)',
                    data: <?php echo json_encode($dados_grafico); ?>,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'KZ ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de métodos de pagamento
        const ctx2 = document.getElementById('metodosChart').getContext('2d');
        const metodosLabels = <?php 
            $labels = [];
            $values = [];
            foreach ($por_metodo as $m) {
                $metodos = ['dinheiro'=>'Dinheiro','transferencia'=>'Transferência','deposito'=>'Depósito','cartao'=>'Cartão','multicaixa'=>'Multicaixa'];
                $labels[] = $metodos[$m['metodo_pagamento']] ?? ucfirst($m['metodo_pagamento']);
                $values[] = $m['total_valor'];
            }
            echo json_encode($labels);
        ?>;
        const metodosValues = <?php echo json_encode($values); ?>;
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: metodosLabels,
                datasets: [{
                    data: metodosValues,
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107']
                }]
            }
        });
        
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