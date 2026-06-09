<?php
// super-admin/dashboard.php - Dashboard do Super Administrador
require_once __DIR__ . '/../config/database.php';
session_start();

// Verificar se é super admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Estatísticas do Dashboard
$stats = [];

// Total de escolas
$stmt = $conn->query("SELECT COUNT(*) as total FROM escolas");
$stats['total_escolas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Escolas ativas
$stmt = $conn->query("SELECT COUNT(*) as total FROM escolas WHERE status = 'ativa'");
$stats['escolas_ativas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Escolas em trial
$stmt = $conn->query("SELECT COUNT(*) as total FROM escolas WHERE status = 'trial' AND trial_ate >= CURDATE()");
$stats['escolas_trial'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Escolas suspensas
$stmt = $conn->query("SELECT COUNT(*) as total FROM escolas WHERE status = 'suspensa'");
$stats['escolas_suspensas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de usuários no sistema
$stmt = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE tipo != 'super_admin'");
$stats['total_usuarios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Assinaturas ativas
$stmt = $conn->query("SELECT COUNT(*) as total FROM assinaturas WHERE status = 'ativa' AND data_fim >= CURDATE()");
$stats['assinaturas_ativas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Faturamento mensal
$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago' AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())");
$stats['faturamento_mensal'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Faturamento anual
$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago' AND YEAR(data_pagamento) = YEAR(CURDATE())");
$stats['faturamento_anual'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Tickets abertos
$stmt = $conn->query("SELECT COUNT(*) as total FROM tickets_suporte WHERE status NOT IN ('fechado')");
$stats['tickets_abertos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Crescimento mensal de escolas
$stmt = $conn->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as mes,
        COUNT(*) as total
    FROM escolas
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY mes ASC
");
$crescimento = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distribuição de planos
$stmt = $conn->query("
    SELECT p.nome, COUNT(e.id) as total
    FROM planos p
    LEFT JOIN escolas e ON e.plano_id = p.id
    WHERE p.status = 'ativo'
    GROUP BY p.id
");
$distribuicao_planos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Escolas prestes a expirar (próximos 30 dias)
$stmt = $conn->prepare("
    SELECT e.*, a.data_fim, a.valor, p.nome as plano_nome 
    FROM escolas e
    JOIN assinaturas a ON a.escola_id = e.id
    JOIN planos p ON p.id = a.plano_id
    WHERE a.status = 'ativa' 
    AND a.data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY a.data_fim ASC
    LIMIT 10
");
$stmt->execute();
$escolas_expiracao = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimos pagamentos
$stmt = $conn->query("
    SELECT p.*, e.nome as escola_nome, e.subdominio
    FROM pagamentos p
    JOIN escolas e ON e.id = p.escola_id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$ultimos_pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Atividades recentes
$stmt = $conn->query("
    SELECT l.*, u.nome as usuario_nome
    FROM logs_sistema l
    LEFT JOIN usuarios u ON u.id = l.usuario_id
    ORDER BY l.created_at DESC
    LIMIT 10
");
$atividades_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Super Admin | SIGE SaaS</title>
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
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
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
        
        .page-title h2 {
            margin: 0;
            font-size: 1.5em;
        }
        
        .user-menu {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .user-menu i {
            font-size: 1.2em;
            cursor: pointer;
            color: #666;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
        }
        
        .stat-icon.primary { background: #e3f2fd; color: #1976d2; }
        .stat-icon.success { background: #e8f5e9; color: #388e3c; }
        .stat-icon.warning { background: #fff3e0; color: #f57c00; }
        .stat-icon.danger { background: #ffebee; color: #d32f2f; }
        .stat-icon.info { background: #e0f7fa; color: #0097a7; }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.1em;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .table {
            margin-bottom: 0;
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
        .status-pago { background: #e8f5e9; color: #388e3c; }
        .status-pendente { background: #fff3e0; color: #f57c00; }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1a1a2e;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE SaaS</h3>
            <p>Sistema de Gestão Escolar</p>
            <div class="user-info-sidebar">
                <small>
                    <i class="fas fa-user-shield"></i> 
                    <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?>
                </small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="?page=dashboard" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=escolas" class="nav-link">
                    <i class="fas fa-school"></i>
                    <span>Escolas</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=planos" class="nav-link">
                    <i class="fas fa-box"></i>
                    <span>Planos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=assinaturas" class="nav-link">
                    <i class="fas fa-credit-card"></i>
                    <span>Assinaturas</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=pagamentos" class="nav-link">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Pagamentos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=suporte" class="nav-link">
                    <i class="fas fa-headset"></i>
                    <span>Suporte</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=relatorios" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span>Relatórios</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=configuracoes" class="nav-link">
                    <i class="fas fa-cog"></i>
                    <span>Configurações</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
            </div>
            <div class="user-menu">
                <i class="fas fa-bell"></i>
                <i class="fas fa-cog"></i>
                <span><?php echo $_SESSION['usuario_nome'] ?? 'Admin'; ?></span>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon primary">
                        <i class="fas fa-school"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_escolas']); ?></div>
                <div class="stat-label">Total de Escolas</div>
                <small>Ativas: <?php echo $stats['escolas_ativas']; ?> | Trial: <?php echo $stats['escolas_trial']; ?></small>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon success">
                        <i class="fas fa-credit-card"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['assinaturas_ativas']; ?></div>
                <div class="stat-label">Assinaturas Ativas</div>
                <small>Planos contratados</small>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-value">R$ <?php echo number_format($stats['faturamento_mensal'], 2, ',', '.'); ?></div>
                <div class="stat-label">Faturamento Mensal</div>
                <small>Anual: R$ <?php echo number_format($stats['faturamento_anual'], 2, ',', '.'); ?></small>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon info">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_usuarios']); ?></div>
                <div class="stat-label">Usuários Ativos</div>
                <small>Em todo o sistema</small>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon danger">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['tickets_abertos']; ?></div>
                <div class="stat-label">Tickets Abertos</div>
                <small>Suporte pendente</small>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['escolas_suspensas']; ?></div>
                <div class="stat-label">Escolas Suspensas</div>
                <small>Pagamento pendente</small>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Crescimento de Escolas</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="crescimentoChart" height="300"></canvas>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Escolas Próximas ao Vencimento</h3>
                        <a href="?page=assinaturas" class="btn btn-sm btn-primary">Ver todas</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Escola</th>
                                        <th>Plano</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($escolas_expiracao as $escola): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($escola['nome']); ?></strong><br>
                                            <small><?php echo $escola['subdominio']; ?>.sige.com</small>
                                         </td>
                                         <td><?php echo $escola['plano_nome']; ?></td>
                                         <td>R$ <?php echo number_format($escola['valor'], 2, ',', '.'); ?></td>
                                         <td><?php echo date('d/m/Y', strtotime($escola['data_fim'])); ?></td>
                                         <td>
                                            <span class="status-badge status-<?php echo $escola['status']; ?>">
                                                <?php echo ucfirst($escola['status']); ?>
                                            </span>
                                         </td>
                                         <td>
                                            <a href="?page=escolas&action=view&id=<?php echo $escola['id']; ?>" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?page=assinaturas&action=renovar&id=<?php echo $escola['id']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-sync"></i>
                                            </a>
                                         </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($escolas_expiracao)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">Nenhuma escola próxima ao vencimento</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Distribuição de Planos</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="planosChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($distribuicao_planos as $plano): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><?php echo $plano['nome']; ?></span>
                                <span class="badge bg-secondary"><?php echo $plano['total']; ?> escolas</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Últimos Pagamentos</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($ultimos_pagamentos as $pagamento): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($pagamento['escola_nome']); ?></strong>
                                        <br>
                                        <small>R$ <?php echo number_format($pagamento['valor'], 2, ',', '.'); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="status-badge status-<?php echo $pagamento['status']; ?>">
                                            <?php echo ucfirst($pagamento['status']); ?>
                                        </span>
                                        <br>
                                        <small><?php echo date('d/m/Y', strtotime($pagamento['created_at'])); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Atividades Recentes</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Usuário</th><th>Ação</th><th>Tabela</th><th>Data/Hora</th><th>IP</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($atividades_recentes as $atividade): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($atividade['usuario_nome'] ?? 'Sistema'); ?></td>
                                <td><?php echo htmlspecialchars($atividade['acao']); ?></td>
                                <td><?php echo htmlspecialchars($atividade['tabela']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($atividade['created_at'])); ?></td>
                                <td><?php echo $atividade['ip']; ?></td>
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
    <script>
        // Menu toggle para mobile
        $('#menuToggle').click(function() {
            $('#sidebar').toggleClass('open');
        });
        
        // Gráfico de crescimento
        const ctx1 = document.getElementById('crescimentoChart').getContext('2d');
        const meses = <?php echo json_encode(array_column($crescimento, 'mes')); ?>;
        const totais = <?php echo json_encode(array_column($crescimento, 'total')); ?>;
        
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Novas Escolas',
                    data: totais,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de planos
        const ctx2 = document.getElementById('planosChart').getContext('2d');
        const planosLabels = <?php echo json_encode(array_column($distribuicao_planos, 'nome')); ?>;
        const planosData = <?php echo json_encode(array_column($distribuicao_planos, 'total')); ?>;
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: planosLabels,
                datasets: [{
                    data: planosData,
                    backgroundColor: ['#4caf50', '#2196f3', '#ff9800', '#9c27b0', '#f44336'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>