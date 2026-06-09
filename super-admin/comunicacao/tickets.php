<?php
// super-admin/comunicacao/tickets.php - Gestão de Tickets de Suporte
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Processar resposta
if ($action == 'responder' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_GET['id'];
    $mensagem = $_POST['mensagem'] ?? '';
    
    if (empty($mensagem)) {
        $error = "Digite uma mensagem de resposta.";
    } else {
        try {
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("
                INSERT INTO ticket_respostas (ticket_id, usuario_id, mensagem, is_admin, created_at)
                VALUES (:ticket_id, :usuario_id, :mensagem, 1, NOW())
            ");
            $stmt->execute([
                ':ticket_id' => $id,
                ':usuario_id' => $_SESSION['usuario_id'],
                ':mensagem' => $mensagem
            ]);
            
            $stmt = $conn->prepare("
                UPDATE tickets_suporte SET status = 'respondido', updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            
            $conn->commit();
            $message = "Resposta enviada com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'responder_ticket', 'tickets_suporte', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fechar ticket
if ($action == 'fechar' && isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        $stmt = $conn->prepare("UPDATE tickets_suporte SET status = 'fechado', updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = "Ticket fechado com sucesso!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Listar tickets
$status_filter = $_GET['status'] ?? '';
$prioridade_filter = $_GET['prioridade'] ?? '';

$query = "
    SELECT t.*, e.nome as escola_nome, e.subdominio,
           (SELECT COUNT(*) FROM ticket_respostas WHERE ticket_id = t.id) as total_respostas
    FROM tickets_suporte t
    JOIN escolas e ON e.id = t.escola_id
    WHERE 1=1
";

$params = [];
if ($status_filter) {
    $query .= " AND t.status = :status";
    $params[':status'] = $status_filter;
}
if ($prioridade_filter) {
    $query .= " AND t.prioridade = :prioridade";
    $params[':prioridade'] = $prioridade_filter;
}

$query .= " ORDER BY FIELD(t.prioridade, 'urgente', 'alta', 'media', 'baixa'), t.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ver ticket específico
$ticket_view = null;
$respostas = [];
if ($action == 'view' && isset($_GET['id'])) {
    $stmt = $conn->prepare("
        SELECT t.*, e.nome as escola_nome, e.subdominio, e.email as escola_email
        FROM tickets_suporte t
        JOIN escolas e ON e.id = t.escola_id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $_GET['id']]);
    $ticket_view = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ticket_view) {
        $stmt = $conn->prepare("
            SELECT r.*, u.nome as usuario_nome
            FROM ticket_respostas r
            LEFT JOIN usuarios u ON u.id = r.usuario_id
            WHERE r.ticket_id = :ticket_id
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([':ticket_id' => $_GET['id']]);
        $respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Estatísticas
$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as total FROM tickets_suporte WHERE status NOT IN ('fechado')");
$stats['abertos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM tickets_suporte WHERE prioridade = 'urgente' AND status != 'fechado'");
$stats['urgentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as media FROM tickets_suporte WHERE status = 'fechado'");
$stats['tempo_medio'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media'] ?? 0);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets de Suporte | SIGE Angola</title>
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
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-aberto { background: #ffebee; color: #c62828; }
        .status-em_andamento { background: #fff3e0; color: #ef6c00; }
        .status-respondido { background: #e3f2fd; color: #1565c0; }
        .status-fechado { background: #e8f5e9; color: #2e7d32; }
        
        .ticket-item {
            border-left: 4px solid;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .ticket-item:hover {
            background: #f8f9fa;
        }
        
        .prioridade-urgente { border-left-color: #dc3545; }
        .prioridade-alta { border-left-color: #fd7e14; }
        .prioridade-media { border-left-color: #ffc107; }
        .prioridade-baixa { border-left-color: #28a745; }
        
        .message-bubble {
            background: #f0f2f5;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .message-bubble.admin {
            background: #e3f2fd;
            border-left: 4px solid #1565c0;
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
            <h2><i class="fas fa-ticket-alt"></i> Tickets de Suporte</h2>
            <div class="btn-group">
                <a href="?status=aberto" class="btn btn-sm btn-danger">Abertos</a>
                <a href="?status=respondido" class="btn btn-sm btn-info">Respondidos</a>
                <a href="?status=fechado" class="btn btn-sm btn-success">Fechados</a>
                <a href="tickets.php" class="btn btn-sm btn-secondary">Todos</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['abertos']; ?></div><div>Tickets Abertos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['urgentes']; ?></div><div>Tickets Urgentes</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['tempo_medio']; ?></div><div>Tempo Médio (horas)</div></div>
        </div>
        
        <?php if ($action == 'view' && $ticket_view): ?>
            <!-- Visualizar Ticket -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3>Ticket #<?php echo $ticket_view['id']; ?> - <?php echo htmlspecialchars($ticket_view['assunto']); ?></h3>
                        <small><?php echo htmlspecialchars($ticket_view['escola_nome']); ?> (<?php echo $ticket_view['subdominio']; ?>.sige.ao)</small>
                    </div>
                    <a href="tickets.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <strong>Prioridade:</strong>
                            <span class="badge bg-<?php 
                                echo $ticket_view['prioridade'] == 'urgente' ? 'danger' : 
                                    ($ticket_view['prioridade'] == 'alta' ? 'warning' : 
                                    ($ticket_view['prioridade'] == 'media' ? 'info' : 'success')); 
                            ?>"><?php echo ucfirst($ticket_view['prioridade']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Status:</strong>
                            <span class="status-badge status-<?php echo $ticket_view['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket_view['status'])); ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Categoria:</strong> <?php echo ucfirst($ticket_view['categoria'] ?? 'Geral'); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Aberto em:</strong> <?php echo date('d/m/Y H:i', strtotime($ticket_view['created_at'])); ?>
                        </div>
                    </div>
                    
                    <div class="message-bubble">
                        <strong><i class="fas fa-user"></i> <?php echo htmlspecialchars($ticket_view['escola_nome']); ?></strong>
                        <div class="mt-2"><?php echo nl2br(htmlspecialchars($ticket_view['mensagem'])); ?></div>
                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($ticket_view['created_at'])); ?></small>
                    </div>
                    
                    <?php foreach ($respostas as $resposta): ?>
                    <div class="message-bubble <?php echo $resposta['is_admin'] ? 'admin' : ''; ?>">
                        <strong><i class="fas <?php echo $resposta['is_admin'] ? 'fa-user-shield' : 'fa-user'; ?>"></i> 
                            <?php echo $resposta['is_admin'] ? 'Suporte SIGE Angola' : htmlspecialchars($resposta['usuario_nome']); ?>
                        </strong>
                        <div class="mt-2"><?php echo nl2br(htmlspecialchars($resposta['mensagem'])); ?></div>
                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($resposta['created_at'])); ?></small>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if ($ticket_view['status'] != 'fechado'): ?>
                    <hr>
                    <form method="POST">
                        <div class="mb-3">
                            <label>Responder ao ticket</label>
                            <textarea name="mensagem" class="form-control" rows="4" required placeholder="Digite sua resposta aqui..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-reply"></i> Enviar Resposta</button>
                        <a href="?action=fechar&id=<?php echo $ticket_view['id']; ?>" class="btn btn-secondary" onclick="return confirm('Fechar ticket?')"><i class="fas fa-check"></i> Fechar Ticket</a>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Lista de Tickets -->
            <div class="card">
                <div class="card-header"><i class="fas fa-list"></i> Lista de Tickets</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>ID</th><th>Escola</th><th>Assunto</th><th>Prioridade</th><th>Status</th><th>Respostas</th><th>Data</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                <tr class="ticket-item prioridade-<?php echo $ticket['prioridade']; ?>" onclick="location.href='?action=view&id=<?php echo $ticket['id']; ?>'">
                                    <td><strong>#<?php echo $ticket['id']; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($ticket['escola_nome']); ?></strong><br><small><?php echo $ticket['subdominio']; ?>.sige.ao</small></td>
                                    <td><?php echo htmlspecialchars($ticket['assunto']); ?></td>
                                    <td><span class="badge bg-<?php echo $ticket['prioridade'] == 'urgente' ? 'danger' : ($ticket['prioridade'] == 'alta' ? 'warning' : 'info'); ?>"><?php echo ucfirst($ticket['prioridade']); ?></span></td>
                                    <td><span class="status-badge status-<?php echo $ticket['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span></td>
                                    <td><?php echo $ticket['total_respostas']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($ticket['created_at'])); ?></td>
                                    <td><a href="?action=view&id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> Ver</a></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($tickets)): ?>
                                <tr><td colspan="8" class="text-center">Nenhum ticket encontrado</td>
                                <?php endif; ?>
                            </tbody>
                         </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
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