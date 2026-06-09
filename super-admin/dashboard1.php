<?php
// super-admin/dashboard.php - Dashboard do Super Admin
require_once __DIR__ . '/../config/database.php';
session_start();

// Verificar se é super admin
if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['tipo'] !== 'super_admin') {
    header('Location: ../index.php?page=login');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Estatísticas gerais
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

// Escolas prestes a expirar (próximos 30 dias)
$stmt = $conn->query("
    SELECT e.*, a.data_fim, p.nome as plano_nome 
    FROM escolas e
    JOIN assinaturas a ON a.escola_id = e.id
    JOIN planos p ON p.id = a.plano_id
    WHERE a.status = 'ativa' 
    AND a.data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY a.data_fim ASC
    LIMIT 10
");
$escolas_expiracao = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - SIGE SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4caf50;
            --danger: #f44336;
            --warning: #ff9800;
            --info: #2196f3;
        }
        
        body {
            background: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2em;
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
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
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
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.2em;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
        }
        
        .btn-primary:hover {
            background: var(--secondary);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        
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
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE SaaS</h3>
            <p>Sistema de Gestão Escolar</p>
            <hr style="border-color: rgba(255,255,255,0.1); margin: 15px 0;">
            <div>
                <small>
                    <i class="fas fa-user-shield"></i> 
                    <?php echo $_SESSION['usuario']['nome']; ?>
                </small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="escolas/" class="nav-link">
                    <i class="fas fa-school"></i>
                    <span>Escolas</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="planos/" class="nav-link">
                    <i class="fas fa-box"></i>
                    <span>Planos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="assinaturas/" class="nav-link">
                    <i class="fas fa-credit-card"></i>
                    <span>Assinaturas</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="pagamentos/" class="nav-link">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Pagamentos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="comunicacao/" class="nav-link">
                    <i class="fas fa-headset"></i>
                    <span>Suporte</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="relatorios/" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span>Relatórios</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="config/" class="nav-link">
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
                    <div class="stat-icon danger">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['tickets_abertos']; ?></div>
                <div class="stat-label">Tickets Abertos</div>
                <small>Suporte pendente</small>
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
                        <a href="assinaturas/" class="btn btn-sm btn-primary">Ver todas</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Escola</th>
                                        <th>Plano</th>
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
                                        <td><?php echo date('d/m/Y', strtotime($escola['data_fim'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $escola['status']; ?>">
                                                <?php echo ucfirst($escola['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="escolas/visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="assinaturas/renovar.php?id=<?php echo $escola['id']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-sync"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($escolas_expiracao)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">Nenhuma escola próxima ao vencimento</td>
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
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Últimas Atividades</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php
                            $stmt = $conn->query("
                                SELECT * FROM logs_sistema 
                                ORDER BY created_at DESC 
                                LIMIT 5
                            ");
                            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($logs as $log):
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-circle" style="font-size: 8px; color: #4caf50;"></i>
                                        <span><?php echo htmlspecialchars($log['acao']); ?></span>
                                        <br>
                                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de crescimento
        const ctx1 = document.getElementById('crescimentoChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                datasets: [{
                    label: 'Novas Escolas',
                    data: [12, 19, 15, 17, 22, 25, 30, 35, 40, 45, 50, 55],
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
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Básico', 'Profissional', 'Empresarial'],
                datasets: [{
                    data: [45, 35, 20],
                    backgroundColor: ['#4caf50', '#2196f3', '#ff9800'],
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