<?php
// super-admin/comunicacao/index.php - Central de Comunicação
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Estatísticas de tickets
$stmt = $conn->query("SELECT COUNT(*) as total FROM tickets_suporte WHERE status NOT IN ('fechado')");
$tickets_abertos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM tickets_suporte WHERE prioridade = 'urgente' AND status != 'fechado'");
$tickets_urgentes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Últimos tickets
$stmt = $conn->query("
    SELECT t.*, e.nome as escola_nome, e.subdominio
    FROM tickets_suporte t
    JOIN escolas e ON e.id = t.escola_id
    ORDER BY t.created_at DESC
    LIMIT 5
");
$ultimos_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Notificações não lidas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM notificacoes WHERE escola_id IS NULL AND lida = 0");
$stmt->execute();
$notificacoes_nao_lidas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .ticket-item { border-left: 4px solid; margin-bottom: 10px; transition: all 0.3s; }
        .ticket-item:hover { background: #f8f9fa; }
        .prioridade-urgente { border-left-color: #dc3545; }
        .prioridade-alta { border-left-color: #fd7e14; }
        .prioridade-media { border-left-color: #ffc107; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-headset"></i> Central de Comunicação</h2>
            <div>
                <a href="enviar.php" class="btn btn-primary btn-sm"><i class="fas fa-envelope"></i> Nova Notificação</a>
                <a href="tickets.php" class="btn btn-info btn-sm"><i class="fas fa-ticket-alt"></i> Tickets</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $tickets_abertos; ?></div><div>Tickets Abertos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $tickets_urgentes; ?></div><div>Tickets Urgentes</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $notificacoes_nao_lidas; ?></div><div>Notificações Não Lidas</div></div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-ticket-alt"></i> Últimos Tickets</div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($ultimos_tickets as $ticket): ?>
                            <div class="list-group-item ticket-item prioridade-<?php echo $ticket['prioridade']; ?>">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong>#<?php echo $ticket['id']; ?></strong> - <?php echo htmlspecialchars($ticket['assunto']); ?>
                                        <br>
                                        <small><?php echo htmlspecialchars($ticket['escola_nome']); ?> (<?php echo $ticket['subdominio']; ?>.sige.ao)</small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-<?php echo $ticket['prioridade'] == 'urgente' ? 'danger' : ($ticket['prioridade'] == 'alta' ? 'warning' : 'info'); ?>">
                                            <?php echo ucfirst($ticket['prioridade']); ?>
                                        </span>
                                        <br>
                                        <small><?php echo date('d/m/Y', strtotime($ticket['created_at'])); ?></small>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <a href="tickets.php?action=view&id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-primary">Responder</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Estatísticas de Suporte</div>
                    <div class="card-body">
                        <canvas id="ticketsChart" height="200"></canvas>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-envelope"></i> Ações Rápidas</div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="enviar.php?tipo=geral" class="btn btn-outline-primary"><i class="fas fa-bullhorn"></i> Comunicado Geral</a>
                            <a href="enviar.php?tipo=especifica" class="btn btn-outline-info"><i class="fas fa-school"></i> Comunicado por Escola</a>
                            <a href="notificacoes.php" class="btn btn-outline-success"><i class="fas fa-bell"></i> Gerenciar Notificações</a>
                            <a href="tickets.php" class="btn btn-outline-warning"><i class="fas fa-ticket-alt"></i> Ver Todos os Tickets</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Gráfico de tickets
        new Chart(document.getElementById('ticketsChart'), {
            type: 'doughnut',
            data: {
                labels: ['Abertos', 'Em Andamento', 'Respondidos', 'Fechados'],
                datasets: [{
                    data: [<?php echo $tickets_abertos; ?>, 5, 8, 12],
                    backgroundColor: ['#dc3545', '#fd7e14', '#ffc107', '#28a745']
                }]
            }
        });
    </script>
</body>
</html>