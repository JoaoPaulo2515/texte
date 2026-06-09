<?php
// aluno/includes/menu_aluno.php - Menu Lateral, Cabeçalho e Rodapé do Aluno

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

// Buscar turma do aluno
$turma_info = '';
if (isset($_SESSION['aluno_id'])) {
    try {
        $sql_turma = "SELECT t.ano, t.nome FROM turmas t
                      JOIN matriculas m ON m.turma_id = t.id
                      WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
                      LIMIT 1";
        $stmt_turma = $conn->prepare($sql_turma);
        $stmt_turma->execute([':aluno_id' => $_SESSION['aluno_id']]);
        $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
        if ($turma) {
            $turma_info = $turma['ano'] . 'ª - ' . $turma['nome'];
        }
    } catch (Exception $e) {}
}

// ==============================================
// BUSCAR NOTIFICAÇÕES DO ALUNO
// ==============================================
$notificacoes = [];
$total_notificacoes_nao_lidas = 0;

if (isset($_SESSION['aluno_id'])) {
    try {
        // Buscar notificações da tabela
        $sql_notif = "SELECT n.*, 
                             CASE 
                                 WHEN n.tipo = 'tarefa' THEN 'fa-tasks'
                                 WHEN n.tipo = 'aviso' THEN 'fa-bullhorn'
                                 WHEN n.tipo = 'pagamento' THEN 'fa-credit-card'
                                 WHEN n.tipo = 'nota' THEN 'fa-chart-line'
                                 WHEN n.tipo = 'evento' THEN 'fa-calendar-alt'
                                 WHEN n.tipo = 'prova' THEN 'fa-file-alt'
                                 ELSE 'fa-bell'
                             END as icone,
                             CASE 
                                 WHEN n.tipo = 'tarefa' THEN 'primary'
                                 WHEN n.tipo = 'aviso' THEN 'warning'
                                 WHEN n.tipo = 'pagamento' THEN 'success'
                                 WHEN n.tipo = 'nota' THEN 'info'
                                 WHEN n.tipo = 'evento' THEN 'danger'
                                 WHEN n.tipo = 'prova' THEN 'primary'
                                 ELSE 'secondary'
                             END as cor
                      FROM notificacoes n
                      WHERE n.aluno_id = :aluno_id
                      ORDER BY n.created_at DESC
                      LIMIT 50";
        $stmt_notif = $conn->prepare($sql_notif);
        $stmt_notif->execute([':aluno_id' => $_SESSION['aluno_id']]);
        $notificacoes = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar não lidas
        $sql_count = "SELECT COUNT(*) as total FROM notificacoes 
                      WHERE aluno_id = :aluno_id AND lida = 0";
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->execute([':aluno_id' => $_SESSION['aluno_id']]);
        $total_notificacoes_nao_lidas = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
    } catch (Exception $e) {
        // Tabela pode não existir ainda
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
    <title><?php echo $titulo_pagina ?? 'Área do Aluno'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Fundo geral da página */
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Sidebar - mesma cor da tesouraria */
        .sidebar-aluno {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-aluno::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.3;
            pointer-events: none;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: relative;
            z-index: 2;
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.3em;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.8;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 0.8em;
            line-height: 1.6;
        }
        
        .user-info-sidebar i {
            width: 20px;
            margin-right: 5px;
            opacity: 0.7;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
            position: relative;
            z-index: 2;
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
            gap: 12px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            border-left: 3px solid #FFD700;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .has-submenu {
            position: relative;
        }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s;
        }
        
        .has-submenu.open > .nav-link::after {
            transform: rotate(180deg);
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 55px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .has-submenu.open .nav-submenu {
            max-height: 500px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 25px;
            font-size: 0.85em;
        }
        
        .nav-submenu .nav-link:hover {
            background: rgba(255,255,255,0.1);
            border-left: 3px solid #FFD700;
        }
        
        /* Top Header */
        .top-header-aluno {
            background: white;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            position: fixed;
            top: 0;
            right: 0;
            left: 280px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            z-index: 999;
            transition: all 0.3s;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #333;
        }
        
        .date-time {
            background: #f0f2f5;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            color: #555;
        }
        
        .date-time i {
            margin-right: 5px;
            color: #006B3E;
        }
        
        .realtime-badge {
            font-size: 0.7em;
            background: #28a745;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .chat-btn {
            background: none;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s;
        }
        
        .chat-btn:hover {
            background: #f0f2f5;
            color: #006B3E;
            transform: scale(1.1);
        }
        
        .chat-badge {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #28a745;
            color: white;
            font-size: 0.6em;
            padding: 2px 4px;
            border-radius: 10px;
            min-width: 12px;
            height: 12px;
        }
        
        /* Notificações */
        .notifications-btn {
            background: none;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s;
        }
        
        .notifications-btn:hover {
            background: #f0f2f5;
            color: #006B3E;
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #dc3545;
            color: white;
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            height: 18px;
        }
        
        /* Dropdown de Notificações */
        .notifications-dropdown {
            position: absolute;
            top: 55px;
            right: 80px;
            width: 380px;
            max-height: 500px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.15);
            display: none;
            z-index: 1001;
            overflow: hidden;
            animation: fadeIn 0.2s ease;
        }
        
        .notifications-dropdown.show {
            display: block;
        }
        
        .notifications-header {
            padding: 15px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notifications-header h6 {
            margin: 0;
            font-size: 1rem;
        }
        
        .marcar-todas {
            background: none;
            border: none;
            color: rgba(255,255,255,0.8);
            font-size: 0.75rem;
            cursor: pointer;
        }
        
        .marcar-todas:hover {
            color: white;
            text-decoration: underline;
        }
        
        .notifications-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
            cursor: pointer;
            display: flex;
            gap: 12px;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.nao-lida {
            background: #f0f7ff;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
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
        .notification-icon.secondary { background: #6c757d20; color: #6c757d; }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }
        
        .notification-message {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 4px;
        }
        
        .notification-time {
            font-size: 0.7rem;
            color: #999;
        }
        
        .notifications-footer {
            padding: 10px 15px;
            text-align: center;
            border-top: 1px solid #eee;
            background: #f8f9fa;
        }
        
        .notifications-footer a {
            color: #006B3E;
            text-decoration: none;
            font-size: 0.8rem;
        }
        
        .notifications-footer a:hover {
            text-decoration: underline;
        }
        
        .sem-notificacoes {
            padding: 30px;
            text-align: center;
            color: #999;
        }
        
        .sem-notificacoes i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .user-dropdown {
            position: relative;
            cursor: pointer;
        }
        
        .user-info-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 10px;
            border-radius: 30px;
        }
        
        .user-info-header:hover {
            background: #f0f2f5;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .user-name {
            font-weight: 500;
            color: #333;
        }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 55px;
            right: 0;
            width: 250px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
        }
        
        .dropdown-menu-custom.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }
        
        .dropdown-item-custom {
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            text-decoration: none;
        }
        
        .dropdown-item-custom:hover {
            background: #f8f9fa;
        }
        
        .dropdown-item-custom i {
            width: 20px;
            color: #006B3E;
        }
        
        .dropdown-divider {
            height: 1px;
            background: #eee;
            margin: 5px 0;
        }
        
        /* Main Content */
        .main-content-aluno {
            margin-left: 280px;
            margin-top: 60px;
            margin-bottom: 45px;
            padding: 20px;
            background: #f5f7fb;
            min-height: calc(100vh - 105px);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .footer-aluno {
            position: fixed;
            bottom: 0;
            right: 0;
            left: 280px;
            background: white;
            padding: 8px 25px;
            font-size: 0.7em;
            color: #666;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 998;
            transition: all 0.3s;
        }
        
        .footer-left, .footer-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .menu-toggle-aluno {
            display: none;
            position: fixed;
            top: 12px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .top-header-aluno { left: 0; }
            .footer-aluno { left: 0; }
            .main-content-aluno { margin-left: 0; margin-top: 60px; margin-bottom: 45px; }
            .sidebar-aluno { left: -280px; }
            .sidebar-aluno.open { left: 0; }
            .menu-toggle-aluno { display: block; }
            .page-title { margin-left: 50px; }
            .user-name { display: none; }
            .notifications-dropdown { width: 320px; right: 20px; }
        }
        
        .sidebar-aluno::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar-aluno::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar-aluno::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 5px;
        }
        
        .badge-tarefa {
            background: #FFD700;
            color: #1A2A6C;
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }
        
        .badge-prova {
            background: #dc3545;
            color: white;
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        
        /* Chat Modal */
        .chat-modal {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 350px;
            height: 450px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            z-index: 1002;
            overflow: hidden;
            animation: fadeInUp 0.3s ease;
        }
        
        .chat-modal.show {
            display: flex;
        }
        
        .chat-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header h6 {
            margin: 0;
            font-size: 1rem;
        }
        
        .chat-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .chat-messages {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: #f8f9fa;
        }
        
        .chat-message {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
        }
        
        .message-bot {
            align-items: flex-start;
        }
        
        .message-user {
            align-items: flex-end;
        }
        
        .message-text {
            max-width: 80%;
            padding: 10px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
        }
        
        .message-bot .message-text {
            background: #e9ecef;
            color: #333;
            border-bottom-left-radius: 5px;
        }
        
        .message-user .message-text {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-bottom-right-radius: 5px;
        }
        
        .chat-time {
            font-size: 0.7rem;
            color: #999;
            margin-top: 3px;
        }
        
        .chat-input-area {
            padding: 10px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            background: white;
        }
        
        .chat-input-area input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
        }
        
        .chat-input-area button {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>

<!-- Botão Menu Toggle -->
<button class="menu-toggle-aluno" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header-aluno">
    <div class="header-left">
        <div class="page-title" id="pageTitle"><?php echo $titulo_pagina ?? 'Dashboard'; ?></div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"><?php echo $data_atual; ?></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"><?php echo $hora_atual; ?></span>
            <span class="realtime-badge">AO</span>
        </div>
    </div>
    <div class="header-right">
        <!-- Botão CHAT -->
        <button class="chat-btn" id="chatBtn">
            <i class="fas fa-comment-dots"></i>
            <span class="chat-badge">1</span>
        </button>
        
        <!-- Botão e Dropdown de Notificações -->
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
                        <div class="sem-notificacoes">
                            <i class="fas fa-bell-slash"></i>
                            <p>Nenhuma notificação</p>
                            <small>Você não tem notificações no momento.</small>
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
                                <div class="notification-time">
                                    <i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($notificacoes)): ?>
                <div class="notifications-footer">
                    <a href="comunicacao/notificacoes.php">Ver todas as notificações</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar"><i class="fas fa-user-graduate"></i></div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo $_SESSION['aluno_nome'] ?? 'Aluno'; ?></div>
                    <div class="user-role" style="font-size: 0.7em; color: #666;">Aluno</div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.8em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="perfil.php" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <a href="alterar_senha.php" class="dropdown-item-custom"><i class="fas fa-key"></i> Alterar Senha</a>
                <div class="dropdown-divider"></div>
                <a href="../logout.php" class="dropdown-item-custom"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal do Chat -->
<div class="chat-modal" id="chatModal">
    <div class="chat-header">
        <h6><i class="fas fa-headset"></i> Suporte SIGE Angola</h6>
        <button class="chat-close" id="chatClose">&times;</button>
    </div>
    <div class="chat-messages" id="chatMessages">
        <div class="chat-message message-bot">
            <div class="message-text">
                Olá! 👋<br>
                Sou o assistente virtual do SIGE Angola.<br>
                Como posso ajudar você hoje?
            </div>
            <div class="chat-time"><?php echo date('H:i'); ?></div>
        </div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="chatInput" placeholder="Digite sua mensagem..." onkeypress="if(event.key==='Enter') enviarMensagem()">
        <button id="chatSend" onclick="enviarMensagem()"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>
</br></br>
<!-- Sidebar -->
<div class="sidebar-aluno" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-graduation-cap"></i></div>
        <h3>SIGE Angola</h3>
        <p>Área do Aluno</p>
        
        <div class="user-info-sidebar">
            <div><i class="fas fa-user"></i> <?php echo $_SESSION['aluno_nome'] ?? 'Aluno'; ?></div>
            <div><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome']); ?></div>
            <div><i class="fas fa-users"></i> <?php echo !empty($turma_info) ? $turma_info : 'Turma não atribuída'; ?></div>
            <div><i class="fas fa-id-card"></i> <?php echo $_SESSION['aluno_matricula'] ?? '---'; ?></div>
        </div>
    </div>
    
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Académico -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link"><i class="fas fa-graduation-cap"></i> Académico</a>
            <ul class="nav-submenu">
                <li><a href="academico/minhas_notas.php" class="nav-link"><i class="fas fa-chart-line"></i> Minhas Notas</a></li>
                <li><a href="academico/boletim.php" class="nav-link"><i class="fas fa-chart-bar"></i> Boletim</a></li>
                <li><a href="academico/historico.php" class="nav-link"><i class="fas fa-history"></i> Histórico Escolar</a></li>
                <li><a href="academico/frequencia.php" class="nav-link"><i class="fas fa-calendar-check"></i> Frequência</a></li>
                <li><a href="academico/horario.php" class="nav-link"><i class="fas fa-clock"></i> Horário de Aulas</a></li>
                <li><a href="academico/calendario.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendário Académico</a></li>
            </ul>
        </li>
        
      <!-- Financeiro - com novos submenus -->
<li class="nav-item has-submenu" id="menuFinanceiro">
    <a href="#" class="nav-link"><i class="fas fa-coins"></i> Financeiro</a>
    <ul class="nav-submenu">
        <li><a href="financeiro/mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
        <li><a href="financeiro/pagamentos.php" class="nav-link"><i class="fas fa-credit-card"></i> Histórico de Pagamentos</a></li>
        <li><a href="financeiro/extrato.php" class="nav-link"><i class="fas fa-file-invoice"></i> Extrato Financeiro</a></li>
        <li><a href="financeiro/recibos.php" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
        <li><a href="financeiro/boletos.php" class="nav-link"><i class="fas fa-barcode"></i> Boletos</a></li>
        <li><a href="financeiro/fatura_pro_forma.php" class="nav-link"><i class="fas fa-file-invoice"></i> Fatura Pró-Forma</a></li>
        <li><a href="financeiro/negociacao.php" class="nav-link"><i class="fas fa-handshake"></i> Negociação de Débitos</a></li>
        <li><a href="financeiro/resumo_tipos.php" class="nav-link"><i class="fas fa-chart-pie"></i> Resumo por Tipo</a></li>
        <li><a href="financeiro/projecoes.php" class="nav-link"><i class="fas fa-chart-line"></i> Projeções Financeiras</a></li>
        <li><a href="financeiro/descontos.php" class="nav-link"><i class="fas fa-tags"></i> Descontos e Benefícios</a></li>
        <li><a href="financeiro/servicos.php" class="nav-link"><i class="fas fa-tools"></i> Serviços Adicionais</a></li>
        <li><a href="financeiro/notas_fiscais.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Notas Fiscais</a></li>
    </ul>
</li>
        <!-- Tarefas -->
        <li class="nav-item has-submenu" id="menuTarefas">
            <a href="#" class="nav-link"><i class="fas fa-tasks"></i> Tarefas <span class="badge-tarefa">Novo</span></a>
            <ul class="nav-submenu">
                <li><a href="tarefas/minhas_tarefas.php" class="nav-link"><i class="fas fa-list-check"></i> Minhas Tarefas</a></li>
                <li><a href="tarefas/solicitar_tarefa.php" class="nav-link"><i class="fas fa-plus-circle"></i> Solicitar Tarefa</a></li>
                <li><a href="tarefas/entregas_pendentes.php" class="nav-link"><i class="fas fa-clock"></i> Entregas Pendentes</a></li>
                <li><a href="tarefas/historico_tarefas.php" class="nav-link"><i class="fas fa-history"></i> Histórico de Tarefas</a></li>
                <li><a href="tarefas/calendario_tarefas.php" class="nav-link"><i class="fas fa-calendar-week"></i> Calendário de Tarefas</a></li>
                <li><a href="tarefas/notas_tarefas.php" class="nav-link"><i class="fas fa-star"></i> Notas das Tarefas</a></li>
            </ul>
        </li>
        
        <!-- ============================================= -->
        <!-- PROVA ONLINE - NOVO MÓDULO                     -->
        <!-- ============================================= -->
        <li class="nav-item has-submenu" id="menuProvaOnline">
            <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Prova Online <span class="badge-prova">Novo</span></a>
            <ul class="nav-submenu">
                <li><a href="provas/disponiveis.php" class="nav-link"><i class="fas fa-play-circle"></i> Provas Disponíveis</a></li>
                <li><a href="provas/em_andamento.php" class="nav-link"><i class="fas fa-hourglass-half"></i> Provas em Andamento</a></li>
                <li><a href="provas/historico_provas.php" class="nav-link"><i class="fas fa-history"></i> Histórico de Provas</a></li>
                <li><a href="provas/resultados.php" class="nav-link"><i class="fas fa-chart-line"></i> Meus Resultados</a></li>
                <li><a href="provas/calendario_provas.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendário de Provas</a></li>
            </ul>
        </li>
        
        <!-- Documentos -->
        <li class="nav-item has-submenu" id="menuDocumentos">
            <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Documentos</a>
            <ul class="nav-submenu">
                <li><a href="documentos/certificados.php" class="nav-link"><i class="fas fa-certificate"></i> Certificados</a></li>
                <li><a href="documentos/declaracoes.php" class="nav-link"><i class="fas fa-file-signature"></i> Declarações</a></li>
                <li><a href="documentos/comprovativos.php" class="nav-link"><i class="fas fa-paperclip"></i> Comprovativos</a></li>
            </ul>
        </li>
        
        <!-- Biblioteca -->
        <li class="nav-item has-submenu" id="menuBiblioteca">
            <a href="#" class="nav-link"><i class="fas fa-book-open"></i> Biblioteca</a>
            <ul class="nav-submenu">
                <li><a href="biblioteca/acervo.php" class="nav-link"><i class="fas fa-search"></i> Consultar Acervo</a></li>
                <li><a href="biblioteca/emprestimos.php" class="nav-link"><i class="fas fa-hand-holding"></i> Meus Empréstimos</a></li>
                <li><a href="biblioteca/reservas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Reservas</a></li>
            </ul>
        </li>
        
        <!-- Comunicação -->
        <li class="nav-item has-submenu" id="menuComunicacao">
            <a href="#" class="nav-link"><i class="fas fa-envelope"></i> Comunicação</a>
            <ul class="nav-submenu">
                <li><a href="comunicacao/avisos.php" class="nav-link"><i class="fas fa-bullhorn"></i> Avisos</a></li>
                <li><a href="comunicacao/notificacoes.php" class="nav-link"><i class="fas fa-bell"></i> Notificações</a></li>
                <li><a href="comunicacao/contato.php" class="nav-link"><i class="fas fa-phone-alt"></i> Contactar Escola</a></li>
            </ul>
        </li>
        
        <li class="nav-item"><hr style="margin: 10px 25px; border-color: rgba(255,255,255,0.1);"></li>
        
        <li class="nav-item">
            <a href="perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a>
        </li>
        <li class="nav-item">
            <a href="alterar_senha.php" class="nav-link"><i class="fas fa-key"></i> Alterar Senha</a>
        </li>
        <li class="nav-item">
            <a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer-aluno">
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
        <span><i class="fas fa-graduation-cap"></i> Área do Aluno</span>
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
    
    // Função para marcar notificação como lida
    function marcarNotificacaoComoLida(id, link) {
        $.ajax({
            url: 'marcar_notificacao_lida.php',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Remover classe nao-lida
                    $('.notification-item[data-id="' + id + '"]').removeClass('nao-lida');
                    
                    // Atualizar badge
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        let count = parseInt(badge.textContent) - 1;
                        if (count <= 0) {
                            badge.style.display = 'none';
                        } else {
                            badge.textContent = count;
                        }
                    }
                    
                    // Redirecionar se tiver link
                    if (link && link !== '#') {
                        window.location.href = link;
                    }
                }
            }
        });
    }
    
    // Marcar todas como lidas
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
                    
                    // Recarregar a lista
                    location.reload();
                }
            }
        });
    });
    
    // Funções do Chat
    const chatModal = document.getElementById('chatModal');
    const chatBtn = document.getElementById('chatBtn');
    const chatClose = document.getElementById('chatClose');
    const chatInput = document.getElementById('chatInput');
    const chatMessages = document.getElementById('chatMessages');
    
    if (chatBtn) {
        chatBtn.addEventListener('click', function() {
            chatModal.classList.toggle('show');
            if (chatModal.classList.contains('show')) {
                chatInput.focus();
            }
        });
    }
    
    if (chatClose) {
        chatClose.addEventListener('click', function() {
            chatModal.classList.remove('show');
        });
    }
    
    function enviarMensagem() {
        const mensagem = chatInput.value.trim();
        if (mensagem === '') return;
        
        const time = new Date();
        const horaAtual = time.getHours().toString().padStart(2, '0') + ':' + time.getMinutes().toString().padStart(2, '0');
        
        const userMessageDiv = document.createElement('div');
        userMessageDiv.className = 'chat-message message-user';
        userMessageDiv.innerHTML = `
            <div class="message-text">${mensagem.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
            <div class="chat-time">${horaAtual}</div>
        `;
        chatMessages.appendChild(userMessageDiv);
        
        chatInput.value = '';
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        setTimeout(() => {
            let resposta = '';
            const msgLower = mensagem.toLowerCase();
            
            if (msgLower.includes('nota') || msgLower.includes('boletim')) {
                resposta = '📊 Para consultar suas notas, acesse o menu "Académico" > "Minhas Notas" ou "Boletim". Lá você encontra todas as suas avaliações!';
            } else if (msgLower.includes('mensalidade') || msgLower.includes('pagamento')) {
                resposta = '💰 As suas mensalidades podem ser consultadas no menu "Financeiro" > "Mensalidades". Lá você pode ver o status dos seus pagamentos.';
            } else if (msgLower.includes('senha') || msgLower.includes('login')) {
                resposta = '🔐 Para alterar sua senha, acesse "Meu Perfil" > "Alterar Senha". Se esqueceu sua senha, contacte a secretaria da escola.';
            } else if (msgLower.includes('tarefa') || msgLower.includes('trabalho')) {
                resposta = '📝 Suas tarefas estão disponíveis no menu "Tarefas". Lá você pode ver as tarefas pendentes e o histórico.';
            } else if (msgLower.includes('prova') || msgLower.includes('exame') || msgLower.includes('teste')) {
                resposta = '📝 Suas provas online estão disponíveis no menu "Prova Online". Lá você pode ver as provas disponíveis, em andamento e seus resultados.';
            } else if (msgLower.includes('biblioteca') || msgLower.includes('livro')) {
                resposta = '📚 A biblioteca virtual está disponível no menu "Biblioteca". Você pode consultar o acervo, ver seus empréstimos e fazer reservas.';
            } else if (msgLower.includes('contato') || msgLower.includes('ajuda')) {
                resposta = '📞 Para contactar a escola, utilize o menu "Comunicação" > "Contactar Escola" ou ligue para o número da secretaria.';
            } else if (msgLower.includes('obrigado') || msgLower.includes('gratidão')) {
                resposta = '😊 Por nada! Estou aqui para ajudar. Se precisar de mais alguma coisa, é só chamar!';
            } else if (msgLower.includes('oi') || msgLower.includes('olá') || msgLower.includes('ola')) {
                resposta = 'Olá! 👋 Como posso ajudá-lo hoje? Você pode perguntar sobre notas, mensalidades, tarefas, provas online, biblioteca ou como contactar a escola.';
            } else {
                resposta = '💡 Dica: Você pode perguntar sobre:\n- Notas e boletim\n- Mensalidades e pagamentos\n- Tarefas e trabalhos\n- Provas online\n- Biblioteca\n- Contactar a escola\nComo posso ajudar?';
            }
            
            const botMessageDiv = document.createElement('div');
            botMessageDiv.className = 'chat-message message-bot';
            botMessageDiv.innerHTML = `
                <div class="message-text">${resposta.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                <div class="chat-time">${horaAtual}</div>
            `;
            chatMessages.appendChild(botMessageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }, 500);
    }
    
    window.enviarMensagem = enviarMensagem;
    
    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function(event) {
        if (userDropdownMenu && !userDropdown.contains(event.target)) {
            userDropdownMenu.classList.remove('show');
        }
        if (notificationsDropdown && notificationsBtn && !notificationsBtn.contains(event.target) && !notificationsDropdown.contains(event.target)) {
            notificationsDropdown.classList.remove('show');
        }
        if (chatModal && chatBtn && !chatBtn.contains(event.target) && !chatModal.contains(event.target)) {
            chatModal.classList.remove('show');
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