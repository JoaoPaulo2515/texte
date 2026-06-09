<?php
// escola/includes/menu_pedagogico.php - Menu Lateral, Cabeçalho e Rodapé do Pedagógico (Design Moderno)

// Data e hora atual
$data_atual = date('d/m/Y');
$hora_atual = date('H:i:s');
$dia_semana = date('l');
$dia_semana_pt = [
    'Monday' => 'Segunda-feira', 'Tuesday' => 'Terça-feira', 'Wednesday' => 'Quarta-feira',
    'Thursday' => 'Quinta-feira', 'Friday' => 'Sexta-feira', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
];
$dia_nome = $dia_semana_pt[$dia_semana] ?? $dia_semana;

// Buscar informações da escola
$escola_info = [];
if (isset($_SESSION['escola_id'])) {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $_SESSION['escola_id']]);
    $escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
}
if (empty($escola_info)) {
    $escola_info = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// Buscar turmas do pedagogo para contagem
$total_turmas = 0;
if (isset($_SESSION['pedagogo_id'])) {
    try {
        $sql_turmas = "SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id";
        $stmt_turmas = $conn->prepare($sql_turmas);
        $stmt_turmas->execute([':escola_id' => $_SESSION['escola_id']]);
        $total_turmas = $stmt_turmas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } catch (Exception $e) {}
}

// ==============================================
// BUSCAR TOTAL DE SOLICITAÇÕES PENDENTES DO CONSELHO
// ==============================================
$total_solicitacoes_pendentes = 0;
if (isset($_SESSION['pedagogo_id']) && isset($_SESSION['escola_id'])) {
    try {
        // Buscar ano letivo ativo
        $sql_ano = "SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1";
        $stmt_ano = $conn->prepare($sql_ano);
        $stmt_ano->execute();
        $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
        $ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
        
        // Contar solicitações pendentes
        $sql_solicitacoes = "
            SELECT COUNT(*) as total 
            FROM conselho_nota_solicitacoes cns
            INNER JOIN conselho_nota_sessoes cns2 ON cns2.id = cns.sessao_id
            WHERE cns.status IN ('pendente', 'em_votacao')
            AND cns2.ano_letivo_id = :ano_letivo_id
            AND cns2.escola_id = :escola_id
        ";
        $stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
        $stmt_solicitacoes->execute([
            ':ano_letivo_id' => $ano_letivo_id,
            ':escola_id' => $_SESSION['escola_id']
        ]);
        $total_solicitacoes_pendentes = $stmt_solicitacoes->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } catch (Exception $e) {
        $total_solicitacoes_pendentes = 0;
    }
}

// ==============================================
// BUSCAR NOTIFICAÇÕES DO PEDAGOGO
// ==============================================
$notificacoes = [];
$total_notificacoes_nao_lidas = 0;

if (isset($_SESSION['pedagogo_id'])) {
    try {
        $sql_notif = "SELECT n.*, 
                             CASE 
                                 WHEN n.tipo = 'solicitacao' THEN 'fa-gavel'
                                 WHEN n.tipo = 'aviso' THEN 'fa-bullhorn'
                                 WHEN n.tipo = 'reuniao' THEN 'fa-users'
                                 WHEN n.tipo = 'relatorio' THEN 'fa-chart-line'
                                 WHEN n.tipo = 'evento' THEN 'fa-calendar-alt'
                                 WHEN n.tipo = 'alerta' THEN 'fa-exclamation-triangle'
                                 ELSE 'fa-bell'
                             END as icone,
                             CASE 
                                 WHEN n.tipo = 'solicitacao' THEN 'primary'
                                 WHEN n.tipo = 'aviso' THEN 'warning'
                                 WHEN n.tipo = 'reuniao' THEN 'info'
                                 WHEN n.tipo = 'relatorio' THEN 'success'
                                 WHEN n.tipo = 'evento' THEN 'danger'
                                 ELSE 'secondary'
                             END as cor
                      FROM notificacoes_pedagogico n
                      WHERE n.funcionario_id = :funcionario_id
                      ORDER BY n.created_at DESC
                      LIMIT 50";
        $stmt_notif = $conn->prepare($sql_notif);
        $stmt_notif->execute([':funcionario_id' => $_SESSION['pedagogo_id']]);
        $notificacoes = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
        
        $sql_count = "SELECT COUNT(*) as total FROM notificacoes_pedagogico 
                      WHERE funcionario_id = :funcionario_id AND lida = 0";
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->execute([':funcionario_id' => $_SESSION['pedagogo_id']]);
        $total_notificacoes_nao_lidas = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
    } catch (Exception $e) {
        $notificacoes = [];
        $total_notificacoes_nao_lidas = 0;
    }
}

// Versão do sistema
$versao_sistema = '2.5.0';
$ano_atual = date('Y');
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $titulo_pagina ?? 'Área Pedagógica'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ==============================================
           DESIGN MODERNO - BORDAS ARREDONDADAS
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e8ecf1 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* ==============================================
           SIDEBAR MODERNO
           ============================================== */
        .sidebar-pedagogico {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #0a2b2c 0%, #0d3b2e 50%, #0a2b2c 100%);
            color: white;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
            border-radius: 0 20px 20px 0;
        }
        
        .sidebar-pedagogico::-webkit-scrollbar { width: 4px; }
        .sidebar-pedagogico::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar-pedagogico::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar-pedagogico::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        
        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            position: relative;
            z-index: 2;
            background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0));
        }
        
        .sidebar-header .logo { font-size: 2.8em; margin-bottom: 12px; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .sidebar-header h3 { font-size: 1.3em; margin-bottom: 5px; font-weight: 700; letter-spacing: 0.5px; }
        .sidebar-header p { font-size: 0.75em; opacity: 0.7; letter-spacing: 1px; }
        
        .user-info-sidebar {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 0.8em;
            line-height: 1.6;
            background: rgba(255,255,255,0.03);
            border-radius: 15px;
            padding: 15px;
        }
        
        .user-info-sidebar i { width: 24px; margin-right: 8px; opacity: 0.7; }
        
        .nav-menu {
            list-style: none;
            padding: 20px 12px;
            margin: 0;
            position: relative;
            z-index: 2;
        }
        
        .nav-item { margin-bottom: 8px; }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            gap: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 16px;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link i { width: 24px; text-align: center; font-size: 1.2em; }
        
        .has-submenu { position: relative; }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s ease;
            font-size: 0.75rem;
            opacity: 0.7;
        }
        
        .has-submenu.open > .nav-link::after { transform: rotate(180deg); }
        .has-submenu.open > .nav-link { background: rgba(255,255,255,0.1); border-radius: 16px 16px 12px 12px; }
        
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out;
        }
        
        .has-submenu.open .nav-submenu { max-height: 800px; overflow-y: auto; }
        
        .nav-submenu .nav-link {
            padding: 10px 18px;
            font-size: 0.85em;
            border-radius: 12px;
            margin: 3px 0;
        }
        
        .nav-submenu .nav-link:hover { background: rgba(255,255,255,0.08); transform: translateX(3px); }
        .nav-submenu .nav-link i { font-size: 0.9em; width: 20px; }
        
        /* ==============================================
           TOP HEADER GLASSMORPHISM
           ============================================== */
        .top-header-pedagogico {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            position: fixed;
            top: 0;
            right: 0;
            left: 280px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            z-index: 999;
            transition: all 0.3s;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            border-radius: 0 0 0 20px;
        }
        
        .header-left { display: flex; align-items: center; gap: 20px; }
        
        .page-title {
            font-size: 1.3em;
            font-weight: 700;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }
        
        .date-time {
            background: linear-gradient(135deg, #f0f2f5, #e8ecf1);
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 0.85em;
            color: #2c3e50;
            font-weight: 500;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .date-time i { margin-right: 5px; color: #006B3E; }
        .realtime-badge { font-size: 0.65em; background: #28a745; color: white; padding: 2px 8px; border-radius: 20px; margin-left: 8px; }
        
        .header-right { display: flex; align-items: center; gap: 15px; }
        
        .notifications-btn {
            background: #f0f2f5;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s;
        }
        
        .notifications-btn:hover { background: #006B3E; color: white; }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            font-size: 0.65em;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            height: 18px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .notifications-dropdown {
            position: absolute;
            top: 60px;
            right: 80px;
            width: 380px;
            max-height: 500px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            display: none;
            z-index: 1001;
            overflow: hidden;
            animation: fadeIn 0.2s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .notifications-dropdown.show { display: block; }
        
        .notifications-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .marcar-todas {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 0.7rem;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .marcar-todas:hover { background: rgba(255,255,255,0.3); }
        
        .notifications-list { max-height: 400px; overflow-y: auto; }
        
        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
            cursor: pointer;
            display: flex;
            gap: 12px;
        }
        
        .notification-item:hover { background: #f8f9fa; }
        .notification-item.nao-lida { background: linear-gradient(135deg, #e8f5e9, #f0f7ff); }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .notification-icon.primary { background: #006B3E20; color: #006B3E; }
        .notification-icon.warning { background: #ffc10720; color: #ffc107; }
        .notification-icon.success { background: #28a74520; color: #28a745; }
        .notification-icon.info { background: #17a2b820; color: #17a2b8; }
        .notification-icon.danger { background: #dc354520; color: #dc3545; }
        
        .notification-title { font-weight: 600; color: #333; margin-bottom: 4px; font-size: 0.9rem; }
        .notification-message { font-size: 0.8rem; color: #666; margin-bottom: 4px; }
        .notification-time { font-size: 0.7rem; color: #999; }
        
        .notifications-footer { padding: 12px 20px; text-align: center; border-top: 1px solid #eee; background: #f8f9fa; }
        
        .user-dropdown { position: relative; cursor: pointer; }
        
        .user-info-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px 6px 8px;
            border-radius: 40px;
            background: #f0f2f5;
            transition: all 0.3s;
        }
        
        .user-info-header:hover { background: #e8ecf1; }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .user-name { font-weight: 600; color: #333; }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 55px;
            right: 0;
            width: 260px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .dropdown-menu-custom.show { display: block; animation: fadeIn 0.2s ease; }
        
        .dropdown-item-custom {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .dropdown-item-custom:hover { background: #f8f9fa; }
        .dropdown-item-custom i { width: 22px; color: #006B3E; }
        .dropdown-divider { height: 1px; background: #eee; margin: 5px 0; }
        
        /* ==============================================
           MAIN CONTENT
           ============================================== */
        .main-content-pedagogico {
            margin-left: 280px;
            margin-top: 70px;
            margin-bottom: 45px;
            padding: 25px 30px;
            background: #f0f2f5;
            min-height: calc(100vh - 115px);
        }
        
        .card {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover { transform: translateY(-4px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        
        .footer-pedagogico {
            position: fixed;
            bottom: 0;
            right: 0;
            left: 280px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 10px 30px;
            font-size: 0.7em;
            color: #666;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 998;
            transition: all 0.3s;
        }
        
        .menu-toggle-pedagogico {
            display: none;
            position: fixed;
            top: 18px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 1.2em;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .badge-solicitacao {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
            color: white;
            font-size: 0.65rem;
            padding: 3px 8px;
            border-radius: 20px;
            margin-left: 8px;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.02); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .sidebar-pedagogico { left: -280px; }
            .sidebar-pedagogico.open { left: 0; border-radius: 0; }
            .top-header-pedagogico { left: 0; border-radius: 0; }
            .footer-pedagogico { left: 0; }
            .main-content-pedagogico { margin-left: 0; margin-top: 70px; padding: 20px; }
            .menu-toggle-pedagogico { display: block; }
            .page-title { margin-left: 50px; }
            .user-name { display: none; }
            .notifications-dropdown { width: 320px; right: 20px; }
        }
        
        @media (max-width: 480px) {
            .date-time .realtime-badge { display: none; }
            .date-time { font-size: 0.7em; }
            .main-content-pedagogico { padding: 15px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle-pedagogico" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header-pedagogico">
    <div class="header-left">
        <div class="page-title" id="pageTitle"><?php echo $titulo_pagina ?? 'Dashboard Pedagógico'; ?></div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"><?php echo $data_atual; ?></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"><?php echo $hora_atual; ?></span>
            <span class="realtime-badge">🇦🇴 AO</span>
        </div>
    </div>
    <div class="header-right">
        <div class="notifications-container" style="position: relative;">
            <button class="notifications-btn" id="notificationsBtn">
                <i class="fas fa-bell"></i>
                <?php if ($total_notificacoes_nao_lidas > 0): ?>
                <span class="notification-badge"><?php echo $total_notificacoes_nao_lidas > 99 ? '99+' : $total_notificacoes_nao_lidas; ?></span>
                <?php endif; ?>
            </button>
            
            <div class="notifications-dropdown" id="notificationsDropdown">
                <div class="notifications-header">
                    <h6><i class="fas fa-bell"></i> Notificações</h6>
                    <?php if ($total_notificacoes_nao_lidas > 0): ?>
                    <button class="marcar-todas" id="marcarTodasBtn">Marcar todas como lidas</button>
                    <?php endif; ?>
                </div>
                <div class="notifications-list" id="notificationsList">
                    <?php if (empty($notificacoes)): ?>
                        <div class="sem-notificacoes py-5 text-center">
                            <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                            <p class="text-muted">Nenhuma notificação</p>
                            <small class="text-muted">Você não tem notificações no momento.</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notificacoes as $notif): ?>
                        <div class="notification-item <?php echo $notif['lida'] == 0 ? 'nao-lida' : ''; ?>" 
                             data-id="<?php echo $notif['id']; ?>"
                             onclick="marcarNotificacaoComoLida(<?php echo $notif['id']; ?>, '<?php echo $notif['link'] ?? '#'; ?>')">
                            <div class="notification-icon <?php echo $notif['cor']; ?>">
                                <i class="fas <?php echo $notif['icone']; ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title"><?php echo htmlspecialchars($notif['titulo']); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars(substr($notif['mensagem'], 0, 80)) . (strlen($notif['mensagem']) > 80 ? '...' : ''); ?></div>
                                <div class="notification-time"><i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($notificacoes)): ?>
                <div class="notifications-footer">
                    <a href="notificacoes.php" class="text-decoration-none">Ver todas as notificações</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar">
                    <i class="fas fa-chalkboard-user"></i>
                </div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo $_SESSION['pedagogo_nome'] ?? 'Pedagogo'; ?></div>
                    <div class="user-role" style="font-size: 0.65em; color: #999;"><?php echo $_SESSION['pedagogo_cargo'] ?? 'Pedagogo'; ?></div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.75em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="perfil.php" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <a href="alterar_senha.php" class="dropdown-item-custom"><i class="fas fa-key"></i> Alterar Senha</a>
                <div class="dropdown-divider"></div>
                <a href="../logout.php" class="dropdown-item-custom text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar -->
<div class="sidebar-pedagogico" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
        <h3>SIGE Angola</h3>
        <p>Área Pedagógica</p>
        
        <div class="user-info-sidebar">
            <div><i class="fas fa-user-tie"></i> <?php echo $_SESSION['pedagogo_nome'] ?? 'Pedagogo'; ?></div>
            <div><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome']); ?></div>
            <div><i class="fas fa-building"></i> <?php echo $total_turmas; ?> Turmas</div>
            <div><i class="fas fa-calendar-alt"></i> Ano Letivo <?php echo date('Y'); ?></div>
        </div>
    </div>
    
    <ul class="nav-menu">
        <!-- Dashboard -->
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Alunos -->
        <li class="nav-item has-submenu" id="menuAlunos">
            <a href="#" class="nav-link"><i class="fas fa-user-graduate"></i> Alunos</a>
            <ul class="nav-submenu">
                <li><a href="listar_alunos.php" class="nav-link"><i class="fas fa-list"></i> Listar Alunos</a></li>
                <li><a href="cadastrar_aluno.php" class="nav-link"><i class="fas fa-plus"></i> Cadastrar Aluno</a></li>
                <li><a href="editar_aluno.php" class="nav-link"><i class="fas fa-edit"></i> Editar Aluno</a></li>
                <li><a href="visualizar_aluno.php" class="nav-link"><i class="fas fa-eye"></i> Visualizar Aluno</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="historico_escolar.php" class="nav-link"><i class="fas fa-history"></i> Histórico Escolar</a></li>
                <li><a href="transferir_aluno.php" class="nav-link"><i class="fas fa-exchange-alt"></i> Transferir Aluno</a></li>
            </ul>
        </li>
        
        <!-- Turmas -->
        <li class="nav-item has-submenu" id="menuTurmas">
            <a href="#" class="nav-link"><i class="fas fa-building"></i> Turmas</a>
            <ul class="nav-submenu">
                <li><a href="listar_turmas.php" class="nav-link"><i class="fas fa-list"></i> Listar Turmas</a></li>
                <li><a href="criar_turma.php" class="nav-link"><i class="fas fa-plus"></i> Criar Turma</a></li>
                <li><a href="editar_turma.php" class="nav-link"><i class="fas fa-edit"></i> Editar Turma</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="horario_turma.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Horário da Turma</a></li>
                <li><a href="alunos_turma.php" class="nav-link"><i class="fas fa-users"></i> Alunos da Turma</a></li>
                <li><a href="atribuir_professor.php" class="nav-link"><i class="fas fa-chalkboard-user"></i> Atribuir Professor</a></li>
            </ul>
        </li>
        
        <!-- Disciplinas -->
        <li class="nav-item has-submenu" id="menuDisciplinas">
            <a href="#" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a>
            <ul class="nav-submenu">
                <li><a href="listar_disciplinas.php" class="nav-link"><i class="fas fa-list"></i> Listar Disciplinas</a></li>
                <li><a href="cadastrar_disciplina.php" class="nav-link"><i class="fas fa-plus"></i> Cadastrar Disciplina</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="disciplinas_turma.php" class="nav-link"><i class="fas fa-chalkboard"></i> Disciplinas por Turma</a></li>
                <li><a href="atribuir_professor_disciplina.php" class="nav-link"><i class="fas fa-user-check"></i> Atribuir Professor</a></li>
            </ul>
        </li>
        
        <!-- Notas -->
        <li class="nav-item has-submenu" id="menuNotas">
            <a href="#" class="nav-link"><i class="fas fa-chart-line"></i> Notas</a>
            <ul class="nav-submenu">
                <li><a href="lancar_notas.php" class="nav-link"><i class="fas fa-edit"></i> Lançar Notas</a></li>
                <li><a href="calcular_medias.php" class="nav-link"><i class="fas fa-calculator"></i> Calcular Médias</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="recuperacao.php" class="nav-link"><i class="fas fa-sync-alt"></i> Recuperação</a></li>
                <li><a href="exame_final.php" class="nav-link"><i class="fas fa-star"></i> Exame Final</a></li>
            </ul>
        </li>
        
        <!-- Provas Online -->
        <li class="nav-item has-submenu" id="menuProvas">
            <a href="#" class="nav-link"><i class="fas fa-pen-alt"></i> Provas Online <span class="badge-solicitacao">Novo</span></a>
            <ul class="nav-submenu">
                <li><a href="listar_provas.php" class="nav-link"><i class="fas fa-list"></i> Listar Provas</a></li>
                <li><a href="criar_prova.php" class="nav-link"><i class="fas fa-plus"></i> Criar Prova</a></li>
                <li><a href="questoes.php" class="nav-link"><i class="fas fa-question-circle"></i> Questões</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="provas_disponiveis.php" class="nav-link"><i class="fas fa-play-circle"></i> Provas Disponíveis</a></li>
                <li><a href="provas_andamento.php" class="nav-link"><i class="fas fa-hourglass-half"></i> Provas em Andamento</a></li>
                <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
                <li><a href="resultados_prova.php" class="nav-link"><i class="fas fa-chart-bar"></i> Resultados</a></li>
                <li><a href="corrigir_prova.php" class="nav-link"><i class="fas fa-check-double"></i> Corrigir Prova</a></li>
            </ul>
        </li>
        
        <!-- Frequência -->
        <li class="nav-item has-submenu" id="menuFrequencia">
            <a href="#" class="nav-link"><i class="fas fa-calendar-check"></i> Frequência</a>
            <ul class="nav-submenu">
                <li><a href="marcar_presenca.php" class="nav-link"><i class="fas fa-check-square"></i> Marcar Presença</a></li>
                <li><a href="relatorio_faltas.php" class="nav-link"><i class="fas fa-file-alt"></i> Relatório de Faltas</a></li>
                <li><a href="justificativas.php" class="nav-link"><i class="fas fa-file-signature"></i> Justificativas</a></li>
            </ul>
        </li>
        
        <!-- Boletins -->
        <li class="nav-item has-submenu" id="menuBoletins">
            <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Boletins</a>
            <ul class="nav-submenu">
                <li><a href="gerar_boletim.php" class="nav-link"><i class="fas fa-download"></i> Gerar Boletim</a></li>
                <li><a href="boletim_turma.php" class="nav-link"><i class="fas fa-users"></i> Boletim da Turma</a></li>
                <li><a href="boletim_individual.php" class="nav-link"><i class="fas fa-user"></i> Boletim Individual</a></li>
            </ul>
        </li>
        
        <!-- Relatórios -->
        
        <!-- Relatórios -->
<li class="nav-item has-submenu" id="menuRelatorios">
    <a href="#" class="nav-link"><i class="fas fa-chart-bar"></i> Relatórios</a>
    <ul class="nav-submenu">
        <li><a href="desempenho_turma.php" class="nav-link"><i class="fas fa-chart-line"></i> Desempenho por Turma</a></li>
        <li><a href="desempenho_disciplina.php" class="nav-link"><i class="fas fa-chart-simple"></i> Desempenho por Disciplina</a></li>
        <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
        <li><a href="aprovacao_reprovacao.php" class="nav-link"><i class="fas fa-check-circle"></i> Aprovação/Reprovação</a></li>
        <li><a href="ranking_alunos.php" class="nav-link"><i class="fas fa-trophy"></i> Ranking de Alunos</a></li>
        <li><hr class="dropdown-divider" style="margin: 5px 0; border-color: rgba(255,255,255,0.1);"></li>
        <li><a href="pauta_final.php" class="nav-link"><i class="fas fa-file-alt"></i> Pauta Final</a></li>
        <li><a href="exportar_relatorios.php" class="nav-link"><i class="fas fa-download"></i> Exportar Relatórios</a></li>
    </ul>
</li>
        
        <!-- Planejamento -->
        <li class="nav-item has-submenu" id="menuPlanejamento">
            <a href="#" class="nav-link"><i class="fas fa-tasks"></i> Planejamento</a>
            <ul class="nav-submenu">
                <li><a href="plano_ensino.php" class="nav-link"><i class="fas fa-book-open"></i> Plano de Ensino</a></li>
                <li><a href="plano_aula.php" class="nav-link"><i class="fas fa-chalkboard"></i> Plano de Aula</a></li>
                <li><a href="projetos.php" class="nav-link"><i class="fas fa-project-diagram"></i> Projetos</a></li>
            </ul>
        </li>
        
        <!-- Configurações - COM NOVOS SUBMENUS -->
        <li class="nav-item has-submenu" id="menuConfig">
            <a href="#" class="nav-link"><i class="fas fa-cog"></i> Configurações</a>
            <ul class="nav-submenu">
                <!-- Anos Letivos -->
                <li><a href="anos_letivos.php" class="nav-link"><i class="fas fa-calendar"></i> Anos Letivos</a></li>
                
                <!-- Parâmetros de Avaliação -->
                <li><a href="parametros_avaliacao.php" class="nav-link"><i class="fas fa-sliders-h"></i> Parâmetros de Avaliação</a></li>
                
                <!-- Cursos -->
                <li><a href="cursos.php" class="nav-link"><i class="fas fa-graduation-cap"></i> Cursos</a></li>
                
                <!-- Salas -->
                <li><a href="salas.php" class="nav-link"><i class="fas fa-door-open"></i> Salas</a></li>
                
                <!-- Níveis de Ensino -->
                <li><a href="niveis_ensino.php" class="nav-link"><i class="fas fa-layer-group"></i> Níveis de Ensino</a></li>
                
                <!-- Critérios de Avaliação -->
                <li><a href="criterios_avaliacao.php" class="nav-link"><i class="fas fa-check-double"></i> Critérios de Avaliação</a></li>
                
                <!-- Classes -->
                <li><a href="classes.php" class="nav-link"><i class="fas fa-building"></i> Classes</a></li>
                
                <!-- Turnos -->
                <li><a href="turnos.php" class="nav-link"><i class="fas fa-clock"></i> Turnos</a></li>
                
                <!-- Ciclos de Ensino -->
                <li><a href="ciclos_ensino.php" class="nav-link"><i class="fas fa-chart-line"></i> Ciclos de Ensino</a></li>
            </ul>
        </li>
        
        <li class="nav-item"><hr style="margin: 15px 25px; border-color: rgba(255,255,255,0.08);"></li>
        
        <!-- Conselho de Nota -->
        <li class="nav-item">
            <a href="conselho_nota.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'conselho_nota.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i> Conselho de Nota
                <?php if ($total_solicitacoes_pendentes > 0): ?>
                <span class="badge-solicitacao" style="margin-left: auto;"><?php echo $total_solicitacoes_pendentes; ?></span>
                <?php endif; ?>
            </a>
        </li>
        
        <li class="nav-item"><hr style="margin: 15px 25px; border-color: rgba(255,255,255,0.08);"></li>
        
        <!-- Perfil e Sair -->
        <li class="nav-item">
            <a href="perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a>
        </li>
        <li class="nav-item">
            <a href="alterar_senha.php" class="nav-link"><i class="fas fa-key"></i> Alterar Senha</a>
        </li>
        <li class="nav-item">
            <a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer-pedagogico">
    <div class="footer-left">
        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome']); ?></span>
        <?php if (!empty($escola_info['endereco'])): ?>
        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($escola_info['endereco']); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['telefone'])): ?>
        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($escola_info['telefone']); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['email'])): ?>
        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($escola_info['email']); ?></span>
        <?php endif; ?>
    </div>
    <div class="footer-right">
        <span><i class="fas fa-code-branch"></i> Versão <?php echo $versao_sistema; ?></span>
        <span><i class="fas fa-chalkboard-user"></i> Área Pedagógica</span>
        <span><i class="fas fa-copyright"></i> <?php echo $ano_atual; ?> SIGE Angola</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Atualizar data e hora em tempo real
    function atualizarDataHora() {
        const agora = new Date();
        const dia = String(agora.getDate()).padStart(2, '0');
        const mes = String(agora.getMonth() + 1).padStart(2, '0');
        const ano = agora.getFullYear();
        const dataFormatada = `${dia}/${mes}/${ano}`;
        const horas = String(agora.getHours()).padStart(2, '0');
        const minutos = String(agora.getMinutes()).padStart(2, '0');
        const segundos = String(agora.getSeconds()).padStart(2, '0');
        const horaFormatada = `${horas}:${minutos}:${segundos}`;
        
        const dateElement = document.getElementById('currentDate');
        const timeElement = document.getElementById('currentTime');
        if (dateElement) dateElement.textContent = dataFormatada;
        if (timeElement) timeElement.textContent = horaFormatada;
    }
    
    setInterval(atualizarDataHora, 1000);
    atualizarDataHora();
    
    // Menu Toggle
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    // Toggle submenu
    function toggleSubmenu(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const parentLi = event.currentTarget.closest('.has-submenu');
        if (parentLi) {
            parentLi.classList.toggle('open');
        }
    }
    
    document.querySelectorAll('.has-submenu > a').forEach(link => {
        link.addEventListener('click', toggleSubmenu);
    });
    
    // Toggle User Dropdown
    const userDropdown = document.getElementById('userDropdown');
    const userDropdownMenu = document.getElementById('userDropdownMenu');
    if (userDropdown) {
        userDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdownMenu.classList.toggle('show');
        });
    }
    
    // ==============================================
    // NOTIFICAÇÕES
    // ==============================================
    const notificationsBtn = document.getElementById('notificationsBtn');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    
    if (notificationsBtn) {
        notificationsBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('show');
        });
    }
    
    function marcarNotificacaoComoLida(id, link) {
        $.ajax({
            url: 'marcar_notificacao_lida.php',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.notification-item[data-id="' + id + '"]').removeClass('nao-lida');
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        let count = parseInt(badge.textContent) - 1;
                        if (count <= 0) {
                            badge.style.display = 'none';
                        } else {
                            badge.textContent = count;
                        }
                    }
                    if (link && link !== '#') {
                        window.location.href = link;
                    }
                }
            }
        });
    }
    
    $('#marcarTodasBtn').on('click', function() {
        $.ajax({
            url: 'marcar_notificacao_lida.php',
            method: 'POST',
            data: { marcar_todas: true },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.notification-item').removeClass('nao-lida');
                    $('.notification-badge').hide();
                    location.reload();
                }
            }
        });
    });
    
    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function(event) {
        if (userDropdownMenu && !userDropdown.contains(event.target)) {
            userDropdownMenu.classList.remove('show');
        }
        if (notificationsDropdown && notificationsBtn && !notificationsBtn.contains(event.target) && !notificationsDropdown.contains(event.target)) {
            notificationsDropdown.classList.remove('show');
        }
    });
    
    // Manter submenus abertos baseado na URL atual
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.has-submenu').forEach(menu => {
            const links = menu.querySelectorAll('.nav-submenu a');
            if (Array.from(links).some(link => currentUrl.includes(link.getAttribute('href')))) {
                menu.classList.add('open');
            }
        });
    });
</script>
</body>
</html>