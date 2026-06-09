<?php
// super-admin/relatorios/estatisticas.php - Estatísticas Gerais do Sistema
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$ano = $_GET['ano'] ?? date('Y');

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================

// Crescimento de escolas por ano
$stmt = $conn->query("
    SELECT 
        YEAR(created_at) as ano,
        COUNT(*) as total
    FROM escolas
    GROUP BY YEAR(created_at)
    ORDER BY ano ASC
");
$crescimento_escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento de usuários por ano
$stmt = $conn->query("
    SELECT 
        YEAR(created_at) as ano,
        COUNT(*) as total
    FROM usuarios
    WHERE tipo != 'super_admin'
    GROUP BY YEAR(created_at)
    ORDER BY ano ASC
");
$crescimento_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distribuição de usuários por tipo
$stmt = $conn->query("
    SELECT 
        tipo,
        COUNT(*) as total
    FROM usuarios
    WHERE tipo != 'super_admin'
    GROUP BY tipo
    ORDER BY total DESC
");
$distribuicao_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Taxa de retenção de escolas (ativas vs total)
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ativa' THEN 1 ELSE 0 END) as ativas,
        SUM(CASE WHEN status = 'suspensa' THEN 1 ELSE 0 END) as suspensas,
        SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial
    FROM escolas
");
$retencao = $stmt->fetch(PDO::FETCH_ASSOC);

// Ticket médio por plano
$stmt = $conn->prepare("
    SELECT 
        p.nome as plano_nome,
        AVG(a.valor) as ticket_medio
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    WHERE a.status = 'ativa'
    GROUP BY p.id
");
$stmt->execute();
$ticket_medio = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento mensal do ano (escolas)
$stmt = $conn->prepare("
    SELECT 
        MONTH(created_at) as mes,
        COUNT(*) as total
    FROM escolas
    WHERE YEAR(created_at) = :ano
    GROUP BY MONTH(created_at)
    ORDER BY mes ASC
");
$stmt->execute([':ano' => $ano]);
$crescimento_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses_nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$dados_crescimento = array_fill(0, 12, 0);
foreach ($crescimento_mensal as $c) {
    $dados_crescimento[$c['mes'] - 1] = $c['total'];
}

// Top 5 planos mais contratados
$stmt = $conn->query("
    SELECT 
        p.nome as plano_nome,
        COUNT(a.id) as total_contratacoes
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    GROUP BY p.id
    ORDER BY total_contratacoes DESC
    LIMIT 5
");
$planos_contratados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Satisfação (baseado em tickets resolvidos)
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) as resolvidos
    FROM tickets_suporte
");
$satisfacao = $stmt->fetch(PDO::FETCH_ASSOC);
$taxa_resolucao = ($satisfacao['total_tickets'] > 0) ? ($satisfacao['resolvidos'] / $satisfacao['total_tickets'] * 100) : 0;

// Tempo médio de resposta (tickets)
$stmt = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as tempo_medio
    FROM tickets_suporte
    WHERE status = 'fechado'
");
$tempo_resposta = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas Gerais | SIGE Angola</title>
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
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
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
        
        .progress {
            height: 25px;
            border-radius: 12px;
        }
        
        .print-only { display: none; }
        
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .print-only { display: block; }
            .card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
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
            <h2><i class="fas fa-chart-bar"></i> Estatísticas Gerais</h2>
            <div class="no-print">
                <form method="GET" class="d-inline-flex gap-2">
                    <select name="ano" class="form-control form-control-sm w-auto">
                        <?php for ($i = 2024; $i <= date('Y'); $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
                <button onclick="window.print()" class="btn btn-secondary btn-sm ms-2"><i class="fas fa-print"></i> Imprimir</button>
            </div>
        </div>
        
        <div class="print-only text-center mb-4">
            <h2>SIGE Angola</h2>
            <h4>Estatísticas Gerais do Sistema</h4>
            <p>Gerado em: <?php echo date('d/m/Y H:i:s'); ?></p>
            <hr>
        </div>
        
        <!-- KPIs Principais -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $retencao['total']; ?></div><div class="stat-label">Total de Escolas</div><small>Cadastradas no sistema</small></div>
            <div class="stat-card"><div class="stat-value"><?php echo round(($retencao['ativas'] / max($retencao['total'], 1)) * 100, 1); ?>%</div><div class="stat-label">Taxa de Retenção</div><small>Escolas ativas</small></div>
            <div class="stat-card"><div class="stat-value"><?php echo round($taxa_resolucao, 1); ?>%</div><div class="stat-label">Tickets Resolvidos</div><small>Satisfação do cliente</small></div>
            <div class="stat-card"><div class="stat-value"><?php echo round($tempo_resposta['tempo_medio'] ?? 0); ?>h</div><div class="stat-label">Tempo Médio de Resposta</div><small>Tickets de suporte</small></div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento de Escolas</div>
                    <div class="card-body"><canvas id="crescimentoEscolasChart" height="250"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento de Usuários</div>
                    <div class="card-body"><canvas id="crescimentoUsuariosChart" height="250"></canvas></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição de Usuários</div>
                    <div class="card-body">
                        <canvas id="usuariosChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php 
                            $tipos_nomes = ['admin_escola' => 'Administradores', 'diretor' => 'Diretores', 'professor' => 'Professores', 'secretaria' => 'Secretários', 'aluno' => 'Alunos', 'pai' => 'Pais/Encarregados'];
                            foreach ($distribuicao_usuarios as $tipo): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2"><span><?php echo $tipos_nomes[$tipo['tipo']] ?? ucfirst($tipo['tipo']); ?></span><span class="badge bg-secondary"><?php echo $tipo['total']; ?> usuários</span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Crescimento Mensal - <?php echo $ano; ?></div>
                    <div class="card-body"><canvas id="crescimentoMensalChart" height="250"></canvas></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Planos Mais Contratados</div>
                    <div class="card-body"><canvas id="planosChart" height="250"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-ticket-alt"></i> Ticket Médio por Plano</div>
                    <div class="card-body">
                        <?php foreach ($ticket_medio as $tm): ?>
                        <div class="mb-3"><div class="d-flex justify-content-between mb-1"><span><?php echo $tm['plano_nome']; ?></span><span class="fw-bold">KZ <?php echo number_format($tm['ticket_medio'], 2, ',', '.'); ?></span></div><div class="progress"><div class="progress-bar bg-success" style="width: <?php echo min(100, ($tm['ticket_medio'] / 1000) * 100); ?>%"></div></div></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-simple"></i> Resumo de Indicadores</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center"><h3><?php echo $retencao['ativas']; ?></h3><p class="text-muted">Escolas Ativas</p><div class="progress"><div class="progress-bar bg-success" style="width: <?php echo ($retencao['ativas'] / max($retencao['total'], 1)) * 100; ?>%"></div></div></div>
                    <div class="col-md-3 text-center"><h3><?php echo $retencao['suspensas']; ?></h3><p class="text-muted">Escolas Suspensas</p><div class="progress"><div class="progress-bar bg-warning" style="width: <?php echo ($retencao['suspensas'] / max($retencao['total'], 1)) * 100; ?>%"></div></div></div>
                    <div class="col-md-3 text-center"><h3><?php echo $retencao['trial']; ?></h3><p class="text-muted">Escolas em Trial</p><div class="progress"><div class="progress-bar bg-info" style="width: <?php echo ($retencao['trial'] / max($retencao['total'], 1)) * 100; ?>%"></div></div></div>
                    <div class="col-md-3 text-center"><h3><?php echo $satisfacao['resolvidos']; ?>/<?php echo $satisfacao['total_tickets']; ?></h3><p class="text-muted">Tickets Resolvidos</p><div class="progress"><div class="progress-bar bg-success" style="width: <?php echo $taxa_resolucao; ?>%"></div></div></div>
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
        
        // Gráfico de crescimento de escolas
        new Chart(document.getElementById('crescimentoEscolasChart'), {
            type: 'line', data: { labels: <?php echo json_encode(array_column($crescimento_escolas, 'ano')); ?>, datasets: [{ label: 'Novas Escolas', data: <?php echo json_encode(array_column($crescimento_escolas, 'total')); ?>, borderColor: '#006B3E', backgroundColor: 'rgba(0, 107, 62, 0.1)', tension: 0.4, fill: true }] }, options: { responsive: true }
        });
        
        // Gráfico de crescimento de usuários
        new Chart(document.getElementById('crescimentoUsuariosChart'), {
            type: 'line', data: { labels: <?php echo json_encode(array_column($crescimento_usuarios, 'ano')); ?>, datasets: [{ label: 'Novos Usuários', data: <?php echo json_encode(array_column($crescimento_usuarios, 'total')); ?>, borderColor: '#1A2A6C', backgroundColor: 'rgba(26, 42, 108, 0.1)', tension: 0.4, fill: true }] }, options: { responsive: true }
        });
        
        // Gráfico de distribuição de usuários
        new Chart(document.getElementById('usuariosChart'), {
            type: 'doughnut', data: { labels: <?php 
                $tipos = [];
                foreach ($distribuicao_usuarios as $t) {
                    $tipos_nomes = ['admin_escola'=>'Administradores','diretor'=>'Diretores','professor'=>'Professores','secretaria'=>'Secretários','aluno'=>'Alunos','pai'=>'Pais'];
                    $tipos[] = $tipos_nomes[$t['tipo']] ?? ucfirst($t['tipo']);
                }
                echo json_encode($tipos);
            ?>, datasets: [{ data: <?php echo json_encode(array_column($distribuicao_usuarios, 'total')); ?>, backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107', '#dc3545'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Gráfico de crescimento mensal
        new Chart(document.getElementById('crescimentoMensalChart'), {
            type: 'bar', data: { labels: <?php echo json_encode($meses_nomes); ?>, datasets: [{ label: 'Novas Escolas', data: <?php echo json_encode($dados_crescimento); ?>, backgroundColor: '#006B3E' }] }, options: { responsive: true }
        });
        
        // Gráfico de planos contratados
        new Chart(document.getElementById('planosChart'), {
            type: 'pie', data: { labels: <?php echo json_encode(array_column($planos_contratados, 'plano_nome')); ?>, datasets: [{ data: <?php echo json_encode(array_column($planos_contratados, 'total_contratacoes')); ?>, backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>