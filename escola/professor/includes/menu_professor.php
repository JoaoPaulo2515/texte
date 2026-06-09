<?php
// escola/professor/includes/menu_professor.php - Menu Lateral do Professor (Design Moderno - Cor Verde)

// Garantir que o config/database.php seja incluído
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 3)); // Volta 3 níveis até a raiz
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Se a sessão não foi iniciada, iniciar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Buscar informações do professor
$professor_info = [];
$total_turmas = 0;
$total_notificacoes_nao_lidas = 0;
$notificacoes = [];

if (isset($_SESSION['professor_id'])) {
    try {
        $sql_professor = "SELECT f.*, u.nome as usuario_nome, u.email 
                          FROM funcionarios f 
                          JOIN usuarios u ON u.id = f.usuario_id 
                          WHERE f.id = :professor_id AND f.escola_id = :escola_id";
        $stmt_professor = $conn->prepare($sql_professor);
        $stmt_professor->execute([
            ':professor_id' => $_SESSION['professor_id'],
            ':escola_id' => $_SESSION['escola_id']
        ]);
        $professor_info = $stmt_professor->fetch(PDO::FETCH_ASSOC);
        
        // Buscar total de turmas do professor
        $sql_turmas = "SELECT COUNT(*) as total FROM professor_turmas WHERE professor_id = :professor_id";
        $stmt_turmas = $conn->prepare($sql_turmas);
        $stmt_turmas->execute([':professor_id' => $_SESSION['professor_id']]);
        $total_turmas = $stmt_turmas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Buscar notificações
        $sql_notif = "SELECT * FROM notificacoes_professor 
                      WHERE professor_id = :professor_id 
                      ORDER BY created_at DESC 
                      LIMIT 50";
        $stmt_notif = $conn->prepare($sql_notif);
        $stmt_notif->execute([':professor_id' => $_SESSION['professor_id']]);
        $notificacoes = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
        
        $sql_count = "SELECT COUNT(*) as total FROM notificacoes_professor 
                      WHERE professor_id = :professor_id AND lida = 0";
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->execute([':professor_id' => $_SESSION['professor_id']]);
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
    <title><?php echo $titulo_pagina ?? 'Área do Professor'; ?> | SIGE Angola</title>
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
           SIDEBAR MODERNO - COR VERDE (igual menu pedagógico)
           ============================================== */
        .sidebar-professor {
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
        
        .sidebar-professor::-webkit-scrollbar { width: 4px; }
        .sidebar-professor::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .sidebar-professor::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        .sidebar-professor::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        
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
            color: rgba(255,255,255,0.85);
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
        
        .submenu-title {
            padding: 8px 18px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.5);
            background: rgba(0,0,0,0.15);
            margin-top: 8px;
            border-radius: 8px;
            pointer-events: none;
        }
        
        .submenu-title:first-child { margin-top: 0; }
        
        /* ==============================================
           TOP HEADER GLASSMORPHISM
           ============================================== */
        .top-header-professor {
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
        
        /* Botão Chat */
        .chat-btn {
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
        
        .chat-btn:hover { background: #006B3E; color: white; }
        
        .chat-badge {
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: #28a745;
            color: white;
            font-size: 0.6em;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Notificações */
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
           CHAT MODAL
           ============================================== */
        .chat-modal {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 380px;
            height: 500px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            z-index: 1002;
            overflow: hidden;
            animation: fadeInUp 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .chat-modal.show { display: flex; }
        
        .chat-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header h6 { margin: 0; font-size: 1rem; font-weight: 600; }
        .chat-close { background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; opacity: 0.8; }
        .chat-close:hover { opacity: 1; }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .chat-message { display: flex; flex-direction: column; max-width: 85%; }
        .message-bot { align-items: flex-start; }
        .message-user { align-items: flex-end; margin-left: auto; }
        
        .message-text {
            padding: 10px 15px;
            border-radius: 18px;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        .message-bot .message-text {
            background: white;
            color: #333;
            border-bottom-left-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .message-user .message-text {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-bottom-right-radius: 5px;
        }
        
        .chat-time { font-size: 0.65rem; color: #999; margin-top: 3px; padding: 0 5px; }
        
        .chat-input-area {
            padding: 15px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            background: white;
        }
        
        .chat-input-area input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 25px;
            outline: none;
            font-size: 0.85rem;
        }
        
        .chat-input-area input:focus { border-color: #006B3E; }
        
        .chat-input-area button {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border: none;
            color: white;
            padding: 10px 18px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .chat-input-area button:hover { transform: scale(1.02); }
        
        /* ==============================================
           MAIN CONTENT
           ============================================== */
        .main-content-professor {
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
        
        .footer-professor {
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
        
        .menu-toggle-professor {
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
        
        .badge-prova {
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
            .sidebar-professor { left: -280px; }
            .sidebar-professor.open { left: 0; border-radius: 0; }
            .top-header-professor { left: 0; border-radius: 0; }
            .footer-professor { left: 0; }
            .main-content-professor { margin-left: 0; margin-top: 70px; padding: 20px; }
            .menu-toggle-professor { display: block; }
            .page-title { margin-left: 50px; }
            .user-name { display: none; }
            .notifications-dropdown, .chat-modal { width: 320px; right: 20px; }
            .chat-modal { bottom: 20px; right: 20px; }
        }
        
        @media (max-width: 480px) {
            .date-time .realtime-badge { display: none; }
            .date-time { font-size: 0.7em; }
            .main-content-professor { padding: 15px; }
            .chat-modal { width: calc(100% - 40px); height: 450px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle-professor" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header-professor">
    <div class="header-left">
        <div class="page-title" id="pageTitle"><?php echo $titulo_pagina ?? 'Dashboard Professor'; ?></div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"><?php echo $data_atual; ?></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"><?php echo $hora_atual; ?></span>
            <span class="realtime-badge">🇦🇴 AO</span>
        </div>
    </div>
    <div class="header-right">
        <!-- Botão CHAT -->
        <button class="chat-btn" id="chatBtn">
            <i class="fas fa-comment-dots"></i>
            <span class="chat-badge">1</span>
        </button>
        
        <!-- Notificações -->
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
                            <div class="notification-icon <?php echo $notif['cor'] ?? 'primary'; ?>">
                                <i class="fas <?php echo $notif['icone'] ?? 'fa-bell'; ?>"></i>
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
                    <div class="user-name"><?php echo $_SESSION['professor_nome'] ?? $professor_info['usuario_nome'] ?? 'Professor'; ?></div>
                    <div class="user-role" style="font-size: 0.65em; color: #999;">Professor</div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.75em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="perfil.php" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <a href="alterar_senha.php" class="dropdown-item-custom"><i class="fas fa-key"></i> Alterar Senha</a>
                <div class="dropdown-divider"></div>
                <a href="../../logout.php" class="dropdown-item-custom text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
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

<!-- Sidebar -->
<div class="sidebar-professor" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
        <h3>SIGE Angola</h3>
        <p>Área do Professor</p>
        
        <div class="user-info-sidebar">
            <div><i class="fas fa-user"></i> <?php echo $_SESSION['professor_nome'] ?? $professor_info['usuario_nome'] ?? 'Professor'; ?></div>
            <div><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome']); ?></div>
            <div><i class="fas fa-building"></i> <?php echo $total_turmas; ?> Turmas</div>
            <div><i class="fas fa-calendar-alt"></i> Ano Letivo <?php echo date('Y'); ?></div>
        </div>
    </div>
    
    <ul class="nav-menu">
        <!-- Dashboard -->
        <li class="nav-item">
            <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Acadêmico -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link"><i class="fas fa-graduation-cap"></i> Acadêmico</a>
            <ul class="nav-submenu">
                <li><a href="minhas_turmas.php" class="nav-link"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                <li><a href="lancar_notas.php" class="nav-link"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                <li><a href="registrar_chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                <li><a href="atividades.php" class="nav-link"><i class="fas fa-tasks"></i> Atividades</a></li>
                <li><a href="meus_alunos.php" class="nav-link"><i class="fas fa-users"></i> Meus Alunos</a></li>
            </ul>
        </li>
        
        <!-- Prova Online -->
        <li class="nav-item has-submenu" id="menuProvaOnline">
            <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Prova Online <span class="badge-prova">Novo</span></a>
            <ul class="nav-submenu">
                <li class="submenu-title">📋 GESTÃO DE PROVAS</li>
                <li><a href="criar_prova.php" class="nav-link"><i class="fas fa-plus-circle"></i> Criar Prova</a></li>
                <li><a href="listar_provas.php" class="nav-link"><i class="fas fa-list"></i> Listar Provas</a></li>
                <li><a href="editar_prova.php" class="nav-link"><i class="fas fa-edit"></i> Editar Prova</a></li>
                
                <li class="submenu-title">👁️ VISUALIZAÇÃO</li>
                <li><a href="provas_disponiveis.php" class="nav-link"><i class="fas fa-play-circle"></i> Provas Disponíveis</a></li>
                <li><a href="provas_andamento.php" class="nav-link"><i class="fas fa-hourglass-half"></i> Provas em Andamento</a></li>
                <li><a href="historico_provas.php" class="nav-link"><i class="fas fa-history"></i> Histórico de Provas</a></li>
                <li><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendário de Provas</a></li>
                
                <li class="submenu-title">📊 RESULTADOS</li>
                <li><a href="resultados_prova.php" class="nav-link"><i class="fas fa-chart-line"></i> Resultados das Provas</a></li>
                <li><a href="corrigir_provas.php" class="nav-link"><i class="fas fa-check-double"></i> Corrigir Provas</a></li>
                
                <li class="submenu-title">📚 BANCO DE QUESTÕES</li>
                <li><a href="gerenciar_questoes.php" class="nav-link"><i class="fas fa-database"></i> Banco de Questões</a></li>
                <li><a href="importar_questoes.php" class="nav-link"><i class="fas fa-upload"></i> Importar Questões</a></li>
                <li><a href="categorias_questoes.php" class="nav-link"><i class="fas fa-tags"></i> Categorias</a></li>
            </ul>
        </li>
        
        <!-- Relatórios -->
        <li class="nav-item has-submenu" id="menuRelatorios">
            <a href="#" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a>
            <ul class="nav-submenu">
                <li><a href="mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                <li><a href="pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                <li><a href="estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                <li><a href="estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
            </ul>
        </li>
        
        <!-- Agenda -->
        <li class="nav-item has-submenu" id="menuAgenda">
            <a href="#" class="nav-link"><i class="fas fa-calendar-alt"></i> Agenda</a>
            <ul class="nav-submenu">
                <li><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                <li><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
            </ul>
        </li>
        
        <!-- Financeiro -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link"><i class="fas fa-coins"></i> Financeiro</a>
            <ul class="nav-submenu">
                <li><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                <li><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                <li><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                <li><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                <li><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                <li><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
            </ul>
        </li>
        
        <li class="nav-item"><hr style="margin: 15px 25px; border-color: rgba(255,255,255,0.08);"></li>
        
        <!-- Conselho de Nota -->
        <li class="nav-item">
            <a href="conselho_nota.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'conselho_nota.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i> Conselho de Nota
            </a>
        </li>
        
        <!-- Biblioteca -->
        <li class="nav-item">
            <a href="biblioteca.php" class="nav-link"><i class="fas fa-book"></i> Biblioteca</a>
        </li>
        
        <!-- Proposta de Prova -->
        <li class="nav-item">
            <a href="proposta_prova.php" class="nav-link"><i class="fas fa-file-alt"></i> Proposta de Prova</a>
        </li>
        
        <!-- Suporte -->
        <li class="nav-item has-submenu" id="menuSuporte">
            <a href="#" class="nav-link"><i class="fas fa-headset"></i> Suporte</a>
            <ul class="nav-submenu">
                <li><a href="chamados.php" class="nav-link"><i class="fas fa-ticket-alt"></i> Chamados</a></li>
                <li><a href="faq.php" class="nav-link"><i class="fas fa-question-circle"></i> FAQ</a></li>
                <li><a href="manuais.php" class="nav-link"><i class="fas fa-book"></i> Manuais</a></li>
                <li><a href="tutoriais.php" class="nav-link"><i class="fas fa-video"></i> Tutoriais</a></li>
            </ul>
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
            <a href="../../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer-professor">
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
        <span><i class="fas fa-chalkboard-user"></i> Área do Professor</span>
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
    
    // Toggle Notificações
    const notificationsBtn = document.getElementById('notificationsBtn');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    
    if (notificationsBtn) {
        notificationsBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('show');
        });
    }
    
    // Chat
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
                resposta = '📊 Para acessar as notas, vá ao menu "Académico" > "Lançar Notas".';
            } else if (msgLower.includes('turma') || msgLower.includes('aluno')) {
                resposta = '👨‍🏫 Gerencie suas turmas e alunos no menu "Académico" > "Minhas Turmas".';
            } else if (msgLower.includes('prova') || msgLower.includes('exame') || msgLower.includes('teste')) {
                resposta = '📝 Gerencie suas provas online no menu "Prova Online". Lá você pode criar, visualizar e corrigir provas.';
            } else if (msgLower.includes('horário') || msgLower.includes('agenda')) {
                resposta = '⏰ Consulte seu horário no menu "Agenda" > "Meus Horários".';
            } else if (msgLower.includes('salário') || msgLower.includes('pagamento')) {
                resposta = '💰 Informações financeiras no menu "Financeiro" > "Meu Salário".';
            } else if (msgLower.includes('ajuda') || msgLower.includes('suporte')) {
                resposta = '🆘 Precisa de ajuda? Acesse o menu "Suporte" para abrir um chamado ou consultar o FAQ.';
            } else if (msgLower.includes('obrigado') || msgLower.includes('gratidão')) {
                resposta = '😊 Por nada! Estou aqui para ajudar. Conte sempre comigo!';
            } else if (msgLower.includes('oi') || msgLower.includes('olá') || msgLower.includes('ola')) {
                resposta = 'Olá, professor(a)! 👋 Como posso ajudá-lo hoje? Você pode perguntar sobre notas, turmas, provas online, horários ou salário.';
            } else {
                resposta = '💡 Dica: Você pode perguntar sobre:\n- Notas e avaliações\n- Turmas e alunos\n- Provas online\n- Horários\n- Salário e finanças\n- Suporte técnico\nComo posso ajudar?';
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