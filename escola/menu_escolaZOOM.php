<?php
// menu_escola.php - Menu lateral para o Dashboard da Escola
// Este arquivo deve ser incluído em todas as páginas da área da escola

// ============================================
// CONFIGURAÇÃO DE ZOOM PADRONIZADO
// ============================================

// Definir nível de zoom padrão (em porcentagem)
$zoom_padrao = 90; // 90% - Ajuste conforme necessidade (50% a 150%)

// Permitir usuário alterar zoom via GET
if (isset($_GET['zoom'])) {
    $zoom = (int)$_GET['zoom'];
    if ($zoom >= 50 && $zoom <= 150) {
        $_SESSION['zoom_nivel'] = $zoom;
    }
}

// Pegar zoom da sessão ou usar padrão
$zoom_atual = $_SESSION['zoom_nivel'] ?? $zoom_padrao;

// ============================================
// FUNÇÕES PARA CABEÇALHO E RODAPÉ
// ============================================

// Buscar informações da escola para o rodapé
function getEscolaInfo($conn, $escola_id) {
    try {
        $sql = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $escola_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
    }
}

// Buscar versão do sistema
function getVersaoSistema() {
    return '2.5.0';
}

// Buscar data de validade da licença
function getDataValidadeLicenca() {
    // Esta data pode vir do banco de dados ou de um arquivo de configuração
    // Exemplo: 31 de Dezembro de 2026
    return '2026-12-31';
}

// Buscar notificações não lidas
function getNotificacoesNaoLidas($conn, $usuario_id) {
    try {
        $sql = "SELECT COUNT(*) as total FROM notificacoes 
                WHERE usuario_id = :usuario_id AND lida = 0";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

// Buscar última atividade do usuário
function getUltimoAcesso($conn, $usuario_id) {
    try {
        $sql = "SELECT ultimo_acesso FROM usuarios WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['ultimo_acesso'] ?? date('Y-m-d H:i:s');
    } catch (PDOException $e) {
        return date('Y-m-d H:i:s');
    }
}

// Atualizar último acesso
function atualizarUltimoAcesso($conn, $usuario_id) {
    try {
        $sql = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
    } catch (PDOException $e) {
        // Silent fail
    }
}

// Verificar se a licença está expirada
function isLicencaExpirada() {
    $data_validade = getDataValidadeLicenca();
    $hoje = new DateTime();
    $validade = new DateTime($data_validade);
    return $hoje > $validade;
}

// Obter dias restantes da licença
function getDiasRestantesLicenca() {
    $data_validade = getDataValidadeLicenca();
    $hoje = new DateTime();
    $validade = new DateTime($data_validade);
    $diferenca = $hoje->diff($validade);
    return $diferenca->days;
}

// Atualizar último acesso ao carregar a página
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    atualizarUltimoAcesso($conn, $_SESSION['usuario_id']);
}

// Buscar informações da escola para o rodapé
$escola_info = [];
if (isset($_SESSION['escola_id']) && isset($conn)) {
    $escola_info = getEscolaInfo($conn, $_SESSION['escola_id']);
}

// Buscar notificações não lidas
$total_notificacoes = 0;
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    $total_notificacoes = getNotificacoesNaoLidas($conn, $_SESSION['usuario_id']);
}

// Obter último acesso
$ultimo_acesso = '';
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    $ultimo_acesso = getUltimoAcesso($conn, $_SESSION['usuario_id']);
}

// Versão do sistema e licença
$versao_sistema = getVersaoSistema();
$licenca_expirada = isLicencaExpirada();
$dias_restantes = getDiasRestantesLicenca();
$ano_atual = date('Y');
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=<?php echo $zoom_atual / 100; ?>, user-scalable=yes">
    <style>
        /* ============================================
           CONFIGURAÇÃO DE ZOOM PADRONIZADO
           ============================================ */
        
        /* Zoom base para toda a aplicação */
        html {
            /* Para Chrome, Edge, Opera */
            zoom: <?php echo $zoom_atual; ?>%;
            
            /* Para Firefox */
            -moz-transform: scale(<?php echo $zoom_atual / 100; ?>);
            -moz-transform-origin: top left;
            
            /* Para navegadores modernos */
            transform: scale(<?php echo $zoom_atual / 100; ?>);
            transform-origin: top left;
        }
        
        /* Ajuste específico para Firefox */
        @-moz-document url-prefix() {
            body {
                width: <?php echo 100 / ($zoom_atual / 100); ?>%;
            }
        }
        
        /* Zoom responsivo baseado na resolução da tela */
        @media screen and (min-width: 1920px) {
            html { zoom: 100%; -moz-transform: scale(1); transform: scale(1); }
        }
        @media screen and (min-width: 1600px) and (max-width: 1919px) {
            html { zoom: 95%; -moz-transform: scale(0.95); transform: scale(0.95); }
        }
        @media screen and (min-width: 1366px) and (max-width: 1599px) {
            html { zoom: 90%; -moz-transform: scale(0.9); transform: scale(0.9); }
        }
        @media screen and (min-width: 1024px) and (max-width: 1365px) {
            html { zoom: 85%; -moz-transform: scale(0.85); transform: scale(0.85); }
        }
        
        /* Menu do Escola - Sidebar */
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
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
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
        
        .nav-submenu .nav-link i {
            font-size: 0.9em;
            width: 20px;
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
        
        /* HEADER SUPERIOR */
        .top-header {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        /* Chat Button */
        .chat-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chat-btn:hover {
            background: #f0f2f5;
            color: #006B3E;
        }
        
        /* Notificações */
        .notifications-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
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
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Dropdown de Notificações */
        .notifications-dropdown {
            position: absolute;
            top: 55px;
            right: 100px;
            width: 350px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
        }
        
        .notifications-dropdown.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }
        
        .notifications-header {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
        }
        
        .notifications-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #e8f5e9;
        }
        
        .notification-title {
            font-weight: 500;
            font-size: 0.9em;
        }
        
        .notification-time {
            font-size: 0.7em;
            color: #999;
            margin-top: 3px;
        }
        
        .notifications-footer {
            padding: 10px 15px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        /* User Dropdown */
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
            transition: background 0.3s;
        }
        
        .user-info-header:hover {
            background: #f0f2f5;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
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
        
        .user-role {
            font-size: 0.75em;
            color: #666;
        }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 50px;
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
            transition: background 0.2s;
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
        
        .last-access {
            font-size: 0.7em;
            color: #999;
            padding: 10px 15px;
            border-top: 1px solid #eee;
        }
        
        /* RODAPÉ */
        .footer {
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
        
        .footer-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .footer-right {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        /* Controles de Zoom no Rodapé */
        .zoom-control {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f0f2f5;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .zoom-control i {
            cursor: pointer;
            transition: all 0.2s;
            font-size: 12px;
        }
        
        .zoom-control i:hover {
            color: #006B3E;
            transform: scale(1.1);
        }
        
        .zoom-percent {
            min-width: 45px;
            text-align: center;
            font-weight: 500;
            font-size: 11px;
        }
        
        .license-status {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7em;
        }
        
        .license-valid {
            background: #d4edda;
            color: #155724;
        }
        
        .license-expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .license-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            margin-bottom: 45px;
            padding: 20px;
            background: #f5f7fb;
            min-height: calc(100vh - 105px);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .top-header {
                left: 0;
            }
            .footer {
                left: 0;
            }
            .main-content {
                margin-left: 0;
                margin-top: 60px;
                margin-bottom: 45px;
            }
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .menu-toggle {
                display: block;
                position: fixed;
                top: 12px;
                left: 20px;
                z-index: 1001;
                background: #006B3E;
                color: white;
                border: none;
                width: 36px;
                height: 36px;
                border-radius: 8px;
                cursor: pointer;
            }
            .page-title {
                margin-left: 50px;
            }
            .user-name {
                display: none;
            }
            .notifications-dropdown {
                right: 70px;
                width: 320px;
            }
            .footer-left {
                font-size: 0.65em;
                gap: 8px;
            }
            .footer-right {
                font-size: 0.65em;
                gap: 8px;
            }
        }
        
        .menu-toggle {
            display: none;
        }
        
        /* Scrollbar personalizada */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 5px;
        }
        
        /* Realtime badge */
        .realtime-badge {
            font-size: 0.7em;
            background: #28a745;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
    </style>
</head>
<body>

<!-- Botão Menu Toggle para Mobile -->
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header">
    <div class="header-left">
        <div class="page-title" id="pageTitle">Dashboard</div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"></span>
            <span class="realtime-badge">AO</span>
        </div>
    </div>
    <div class="header-right">
        <!-- Chat Button -->
        <button class="chat-btn" id="chatBtn" onclick="abrirChat()">
            <i class="fas fa-comment-dots"></i>
        </button>
        
        <!-- Notificações -->
        <div class="notifications-btn" id="notificationsBtn">
            <i class="fas fa-bell"></i>
            <?php if ($total_notificacoes > 0): ?>
            <span class="notification-badge"><?php echo $total_notificacoes; ?></span>
            <?php endif; ?>
        </div>
        
        <div class="notifications-dropdown" id="notificationsDropdown">
            <div class="notifications-header">
                <i class="fas fa-bell"></i> Notificações
            </div>
            <div class="notifications-list" id="notificationsList">
                <div class="notification-item">
                    <div class="notification-title">Bem-vindo ao SIGE Angola!</div>
                    <div class="notification-time">Agora</div>
                </div>
                <div class="notification-item unread">
                    <div class="notification-title">Nova versão disponível (<?php echo $versao_sistema; ?>)</div>
                    <div class="notification-time">Hoje</div>
                </div>
            </div>
            <div class="notifications-footer">
                <a href="#" style="color: #006B3E; text-decoration: none;">Ver todas</a>
            </div>
        </div>
        
        <!-- User Dropdown -->
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></div>
                    <div class="user-role"><?php echo ucfirst($_SESSION['user_role'] ?? 'Administrador'); ?></div>
                </div>
                <i class="fas fa-chevron-down" style="color: #999; font-size: 0.8em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="perfil.php" class="dropdown-item-custom">
                    <i class="fas fa-user-circle"></i> Meu Perfil
                </a>
                <a href="configuracoes.php" class="dropdown-item-custom">
                    <i class="fas fa-cog"></i> Configurações
                </a>
                <div class="dropdown-divider"></div>
                <div class="last-access">
                    <i class="fas fa-history"></i> Último acesso: <span id="ultimoAcesso"><?php echo date('d/m/Y H:i:s', strtotime($ultimo_acesso)); ?></span>
                </div>
                <div class="dropdown-divider"></div>
                <a href="../logout.php" class="dropdown-item-custom">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar da Escola -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
        <h3>SIGE Angola</h3>
        <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        <div class="user-info-sidebar mt-2">
            <small><i class="fas fa-user"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></small>
            <br>
            <small><i class="fas fa-building"></i> <?php echo ucfirst($_SESSION['user_role'] ?? 'Administrador'); ?></small>
            <br>
            <small><i class="fas fa-chart-line"></i> Dashboard Geral</small>
        </div>
    </div>
    
    <ul class="nav-menu">
        <!-- DASHBOARD -->
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        
        <!-- ACADÉMICO -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-graduation-cap"></i>
                <span>Académico</span>
            </a>
            <ul class="nav-submenu" id="submenuAcademico">
                <li class="nav-item"><a href="alunos/index.php" class="nav-link"><i class="fas fa-users"></i> Alunos</a></li>
                <li class="nav-item"><a href="professores/index.php" class="nav-link"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
                <li class="nav-item"><a href="turmas/index.php" class="nav-link"><i class="fas fa-users-group"></i> Turmas</a></li>
                <li class="nav-item"><a href="disciplinas/index.php" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a></li>
                <li class="nav-item"><a href="notas/index.php" class="nav-link"><i class="fas fa-edit"></i> Notas</a></li>
                <li class="nav-item"><a href="chamada/index.php" class="nav-link"><i class="fas fa-calendar-check"></i> Chamada</a></li>
            </ul>
        </li>
        
        <!-- SISTEMA DE AVALIAÇÃO -->
        <li class="nav-item has-submenu" id="menuSistemaAvaliacao">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-chart-line"></i>
                <span>Sistema de Avaliação</span>
            </a>
            <ul class="nav-submenu" id="submenuSistemaAvaliacao">
                <li class="nav-item"><a href="avaliacao/provas/index.php" class="nav-link"><i class="fas fa-file-alt"></i> Provas</a></li>
                <li class="nav-item"><a href="avaliacao/pautas/index.php" class="nav-link"><i class="fas fa-list-alt"></i> Pautas</a></li>
                <li class="nav-item"><a href="avaliacao/tipos/index.php" class="nav-link"><i class="fas fa-tags"></i> Tipos de Prova</a></li>
                <li class="nav-item"><a href="avaliacao/por_curso/index.php" class="nav-link"><i class="fas fa-graduation-cap"></i> Avaliação por Curso</a></li>
                <li class="nav-item"><a href="avaliacao/sistema/index.php" class="nav-link"><i class="fas fa-cog"></i> Sistema de Avaliações</a></li>
                <li class="nav-item"><a href="avaliacao/aproveitamento/index.php" class="nav-link"><i class="fas fa-chart-simple"></i> Aproveitamento do Aluno</a></li>
            </ul>
        </li>
        
        <!-- RELATÓRIOS PEDAGÓGICOS -->
        <li class="nav-item has-submenu" id="menuRelatoriosPedagogicos">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-file-alt"></i>
                <span>Relatórios Pedagógicos</span>
            </a>
            <ul class="nav-submenu" id="submenuRelatoriosPedagogicos">
                <li class="nav-item"><a href="relatorios/lista_nominal.php" class="nav-link"><i class="fas fa-list"></i> Lista Nominal de Alunos</a></li>
                <li class="nav-item"><a href="relatorios/estatistico_alunos.php" class="nav-link"><i class="fas fa-chart-bar"></i> Relatório Estatístico de Alunos</a></li>
                <li class="nav-item"><a href="relatorios/professor.php" class="nav-link"><i class="fas fa-chalkboard-user"></i> Relatório Professor</a></li>
                <li class="nav-item"><a href="relatorios/inscricoes.php" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições de Estudantes</a></li>
                <li class="nav-item"><a href="relatorios/estatistica_professor.php" class="nav-link"><i class="fas fa-chart-line"></i> Estatística Professor</a></li>
                <li class="nav-item"><a href="relatorios/manipautas.php" class="nav-link"><i class="fas fa-table"></i> Manipautas</a></li>
                <li class="nav-item"><a href="relatorios/boletim_nota.php" class="nav-link"><i class="fas fa-file-pdf"></i> Boletim de Nota</a></li>
                <li class="nav-item"><a href="relatorios/historico_notas.php" class="nav-link"><i class="fas fa-history"></i> Histórico de Notas</a></li>
                <li class="nav-item"><a href="relatorios/historico_faltas.php" class="nav-link"><i class="fas fa-calendar-times"></i> Histórico de Faltas</a></li>
                <li class="nav-item"><a href="relatorios/cadernetas.php" class="nav-link"><i class="fas fa-book"></i> Cadernetas (Avaliação Contínua)</a></li>
            </ul>
        </li>
        
        <!-- TESOURARIA -->
        <li class="nav-item has-submenu" id="menuTesouraria">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-money-bill-wave"></i>
                <span>Tesouraria</span>
            </a>
            <ul class="nav-submenu" id="submenuTesouraria">
                <li class="nav-item"><a href="tesouraria/pagamentos.php" class="nav-link"><i class="fas fa-credit-card"></i> Pagamento de Serviços</a></li>
                <li class="nav-item"><a href="tesouraria/mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                <li class="nav-item"><a href="tesouraria/ver_pagamentos.php" class="nav-link"><i class="fas fa-eye"></i> Ver Pagamentos</a></li>
                <li class="nav-item"><a href="tesouraria/relatorios_financeiros.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios Financeiros</a></li>
                <li class="nav-item"><a href="tesouraria/relatorios_diarios.php" class="nav-link"><i class="fas fa-calendar-day"></i> Relatórios Diários</a></li>
                <li class="nav-item"><a href="tesouraria/dividas.php" class="nav-link"><i class="fas fa-exclamation-triangle"></i> Dívidas</a></li>
            </ul>
        </li>
        
        <!-- ÁREA FISCAL -->
        <li class="nav-item has-submenu" id="menuAreaFiscal">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Área Fiscal</span>
            </a>
            <ul class="nav-submenu" id="submenuAreaFiscal">
                <li class="nav-item"><a href="fiscal/clientes/index.php" class="nav-link"><i class="fas fa-users"></i> Clientes</a></li>
                <li class="nav-item"><a href="fiscal/fornecedores/index.php" class="nav-link"><i class="fas fa-truck"></i> Fornecedores</a></li>
                <li class="nav-item"><a href="fiscal/notas_fiscais.php" class="nav-link"><i class="fas fa-file-invoice"></i> Notas Fiscais</a></li>
                <li class="nav-item"><a href="fiscal/impostos.php" class="nav-link"><i class="fas fa-percent"></i> Impostos</a></li>
            </ul>
        </li>
        
        <!-- PRODUTOS -->
        <li class="nav-item has-submenu" id="menuProdutos">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-box"></i>
                <span>Produtos</span>
            </a>
            <ul class="nav-submenu" id="submenuProdutos">
                <li class="nav-item"><a href="produtos/novo.php" class="nav-link"><i class="fas fa-plus-circle"></i> Novo Produto</a></li>
                <li class="nav-item"><a href="produtos/artigos/index.php" class="nav-link"><i class="fas fa-tags"></i> Artigos</a></li>
                <li class="nav-item"><a href="produtos/estoque/index.php" class="nav-link"><i class="fas fa-warehouse"></i> Estoque</a></li>
                <li class="nav-item"><a href="produtos/categorias.php" class="nav-link"><i class="fas fa-folder"></i> Categorias</a></li>
            </ul>
        </li>
        
        <!-- RECURSOS HUMANOS (RH) -->
        <li class="nav-item has-submenu" id="menuRH">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-users"></i>
                <span>Recursos Humanos</span>
            </a>
            <ul class="nav-submenu" id="submenuRH">
                <li class="nav-item"><a href="rh/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Dashboard RH</a></li>
                <li class="nav-item has-submenu" id="menuRHFuncionarios">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                        <i class="fas fa-user-tie"></i> Funcionários
                    </a>
                    <ul class="nav-submenu" id="submenuRHFuncionarios">
                        <li class="nav-item"><a href="rh/funcionarios/listar.php" class="nav-link"><i class="fas fa-list"></i> Listar</a></li>
                        <li class="nav-item"><a href="rh/funcionarios/cadastrar.php" class="nav-link"><i class="fas fa-user-plus"></i> Cadastrar</a></li>
                        <li class="nav-item"><a href="rh/funcionarios/visualizar.php" class="nav-link"><i class="fas fa-eye"></i> Visualizar</a></li>
                    </ul>
                </li>
                <li class="nav-item has-submenu" id="menuRHRecrutamento">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                        <i class="fas fa-bullhorn"></i> Recrutamento
                    </a>
                    <ul class="nav-submenu" id="submenuRHRecrutamento">
                        <li class="nav-item"><a href="rh/recrutamento/vagas.php" class="nav-link"><i class="fas fa-file-signature"></i> Vagas</a></li>
                        <li class="nav-item"><a href="rh/recrutamento/candidatos.php" class="nav-link"><i class="fas fa-users-viewfinder"></i> Candidatos</a></li>
                    </ul>
                </li>
                <li class="nav-item has-submenu" id="menuRHAvaliacao">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                        <i class="fas fa-star"></i> Avaliação
                    </a>
                    <ul class="nav-submenu" id="submenuRHAvaliacao">
                        <li class="nav-item"><a href="rh/avaliacao/periodos.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Períodos</a></li>
                        <li class="nav-item"><a href="rh/avaliacao/resultados.php" class="nav-link"><i class="fas fa-chart-bar"></i> Resultados</a></li>
                    </ul>
                </li>
                <li class="nav-item"><a href="rh/formacao/planos.php" class="nav-link"><i class="fas fa-graduation-cap"></i> Formação</a></li>
                <li class="nav-item"><a href="rh/documentacao/index.php" class="nav-link"><i class="fas fa-folder-open"></i> Documentação</a></li>
                <li class="nav-item"><a href="rh/configurar.php" class="nav-link"><i class="fas fa-cog"></i> Configurações RH</a></li>
                <li class="nav-item"><a href="rh/relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios RH</a></li>
            </ul>
        </li>
        
        <!-- SECRETARIA -->
        <li class="nav-item has-submenu" id="menuSecretaria">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-building"></i>
                <span>Secretaria</span>
            </a>
            <ul class="nav-submenu" id="submenuSecretaria">
                <li class="nav-item"><a href="secretaria/lista_alunos.php" class="nav-link"><i class="fas fa-list"></i> Lista de Alunos</a></li>
                <li class="nav-item"><a href="secretaria/alunos_matriculados.php" class="nav-link"><i class="fas fa-check-circle"></i> Alunos Matriculados</a></li>
                <li class="nav-item"><a href="secretaria/inscricoes.php" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições</a></li>
                <li class="nav-item"><a href="secretaria/rematricula.php" class="nav-link"><i class="fas fa-sync-alt"></i> Rematrícula</a></li>
                <li class="nav-item"><a href="secretaria/matricula.php" class="nav-link"><i class="fas fa-user-plus"></i> Matrícula</a></li>
                <li class="nav-item"><a href="secretaria/documentos.php" class="nav-link"><i class="fas fa-file-pdf"></i> Documentos</a></li>
                <li class="nav-item"><a href="secretaria/certificados.php" class="nav-link"><i class="fas fa-certificate"></i> Certificados</a></li>
            </ul>
        </li>
        
        <!-- FINANCEIRO -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-coins"></i>
                <span>Financeiro</span>
            </a>
            <ul class="nav-submenu" id="submenuFinanceiro">
                <li class="nav-item"><a href="financeiro/contas_receber/index.php" class="nav-link"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
                <li class="nav-item"><a href="financeiro/contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
                <li class="nav-item"><a href="financeiro/fluxo_caixa/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                <li class="nav-item"><a href="financeiro/balancete/index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                <li class="nav-item"><a href="financeiro/orcamento/index.php" class="nav-link"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                <li class="nav-item"><a href="financeiro/taxas/index.php" class="nav-link"><i class="fas fa-percent"></i> Taxas e Multas</a></li>
                <li class="nav-item"><a href="financeiro/parcelamentos/index.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
                <li class="nav-item"><a href="financeiro/folha_pagamento/index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                <li class="nav-item"><a href="financeiro/mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                <li class="nav-item"><a href="financeiro/extratos.php" class="nav-link"><i class="fas fa-file-invoice"></i> Extratos</a></li>
                <li class="nav-item"><a href="financeiro/recibos.php" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
            </ul>
        </li>
        
        <!-- SERVIÇOS PEDAGÓGICOS -->
        <li class="nav-item has-submenu" id="menuServicosPedagogicos">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-chalkboard"></i>
                <span>Serviços Pedagógicos</span>
            </a>
            <ul class="nav-submenu" id="submenuServicosPedagogicos">
                <li class="nav-item"><a href="servicos_pedagogicos/gerais/index.php" class="nav-link"><i class="fas fa-cog"></i> Gerais</a></li>
                <li class="nav-item"><a href="servicos_pedagogicos/disciplina_turma/index.php" class="nav-link"><i class="fas fa-link"></i> Disciplina e Turma</a></li>
                <li class="nav-item"><a href="servicos_pedagogicos/disciplina_classe/index.php" class="nav-link"><i class="fas fa-layer-group"></i> Disciplina e Classe</a></li>
                <li class="nav-item"><a href="servicos_pedagogicos/coordenacao/index.php" class="nav-link"><i class="fas fa-users"></i> Coordenação</a></li>
            </ul>
        </li>
        
        <!-- BIBLIOTECA -->
        <li class="nav-item has-submenu" id="menuBiblioteca">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-book-open"></i>
                <span>Biblioteca</span>
            </a>
            <ul class="nav-submenu" id="submenuBiblioteca">
                <li class="nav-item"><a href="biblioteca/index.php" class="nav-link"><i class="fas fa-search"></i> Visualizar Acervo</a></li>
                <li class="nav-item"><a href="biblioteca/cadastrar.php" class="nav-link"><i class="fas fa-plus-circle"></i> Cadastrar Livro</a></li>
                <li class="nav-item"><a href="biblioteca/emprestimos.php" class="nav-link"><i class="fas fa-hand-holding"></i> Empréstimos</a></li>
                <li class="nav-item"><a href="biblioteca/reservas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Reservas</a></li>
            </ul>
        </li>
        
        <!-- CONFIGURAÇÕES -->
        <li class="nav-item has-submenu" id="menuConfiguracoes">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-cogs"></i>
                <span>Configurações</span>
            </a>
            <ul class="nav-submenu" id="submenuConfiguracoes">
                <li class="nav-item"><a href="config/geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                <li class="nav-item"><a href="config/banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                <li class="nav-item"><a href="config/pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Pagamento</a></li>
                <li class="nav-item"><a href="config/sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Sistema</a></li>
                <li class="nav-item"><a href="config/email/index.php" class="nav-link"><i class="fas fa-envelope"></i> Email</a></li>
                <li class="nav-item"><a href="config/backup/index.php" class="nav-link"><i class="fas fa-database"></i> Backup</a></li>
                <li class="nav-item"><a href="config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões</a></li>
            </ul>
        </li>
        
        <!-- SUPORTE -->
        <li class="nav-item has-submenu" id="menuSuporte">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-headset"></i>
                <span>Suporte</span>
            </a>
            <ul class="nav-submenu" id="submenuSuporte">
                <li class="nav-item"><a href="suporte/chamados.php" class="nav-link"><i class="fas fa-ticket-alt"></i> Chamados</a></li>
                <li class="nav-item"><a href="suporte/faq.php" class="nav-link"><i class="fas fa-question-circle"></i> FAQ</a></li>
                <li class="nav-item"><a href="suporte/manuais.php" class="nav-link"><i class="fas fa-book"></i> Manuais</a></li>
                <li class="nav-item"><a href="suporte/tutoriais.php" class="nav-link"><i class="fas fa-video"></i> Tutoriais</a></li>
            </ul>
        </li>
        
        <!-- DIVISÓRIA -->
        <li class="nav-item">
            <hr style="margin: 10px 25px; border-color: rgba(255,255,255,0.1);">
        </li>
        
        <!-- PERFIL -->
        <li class="nav-item">
            <a href="perfil.php" class="nav-link">
                <i class="fas fa-user-circle"></i>
                <span>Meu Perfil</span>
            </a>
        </li>
        
        <!-- SAIR -->
        <li class="nav-item">
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Sair</span>
            </a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer">
    <div class="footer-left">
        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'SIGE Angola'); ?></span>
        <?php if (!empty($escola_info['endereco'])): ?>
        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(substr($escola_info['endereco'], 0, 30)); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['telefone'])): ?>
        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($escola_info['telefone']); ?></span>
        <?php endif; ?>
        <span><i class="fas fa-code-branch"></i> v<?php echo $versao_sistema; ?></span>
    </div>
    <div class="footer-right">
        <!-- Controles de Zoom -->
        <div class="zoom-control">
            <i class="fas fa-search-minus" id="zoomMenos" title="Diminuir Zoom (Ctrl + -)"></i>
            <span class="zoom-percent" id="zoomPercentual"><?php echo $zoom_atual; ?>%</span>
            <i class="fas fa-search-plus" id="zoomMais" title="Aumentar Zoom (Ctrl + +)"></i>
            <i class="fas fa-undo-alt" id="zoomReset" title="Resetar Zoom (Ctrl + 0)" style="font-size: 11px;"></i>
        </div>
        
        <span class="license-status <?php echo $licenca_expirada ? 'license-expired' : ($dias_restantes <= 30 ? 'license-warning' : 'license-valid'); ?>">
            <i class="fas <?php echo $licenca_expirada ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i>
            <?php echo $licenca_expirada ? 'Licença Expirada' : 'Licença Válida'; ?>
            <?php if (!$licenca_expirada): ?>
            (<?php echo $dias_restantes; ?> dias)
            <?php endif; ?>
        </span>
        <span><i class="fas fa-copyright"></i> <?php echo $ano_atual; ?> SIGE</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>

<script>
    // ============================================
    // CONTROLE DE ZOOM
    // ============================================
    
    var zoomNivel = <?php echo $zoom_atual; ?>;
    
    function aplicarZoomCompleto() {
        // Aplicar zoom no html
        document.documentElement.style.zoom = zoomNivel + '%';
        document.documentElement.style.MozTransform = 'scale(' + (zoomNivel / 100) + ')';
        document.documentElement.style.MozTransformOrigin = 'top left';
        document.documentElement.style.transform = 'scale(' + (zoomNivel / 100) + ')';
        document.documentElement.style.transformOrigin = 'top left';
        
        // Ajuste para Firefox
        if (navigator.userAgent.toLowerCase().indexOf('firefox') > -1) {
            document.body.style.width = (100 / (zoomNivel / 100)) + '%';
        }
        
        // Atualizar texto
        document.getElementById('zoomPercentual').innerText = zoomNivel + '%';
        
        // Salvar na sessão via AJAX
        fetch(window.location.pathname + '?zoom=' + zoomNivel, { method: 'GET', keepalive: true });
        
        // Salvar no localStorage
        localStorage.setItem('zoom_nivel', zoomNivel);
    }
    
    function aumentarZoom() {
        if (zoomNivel < 150) {
            zoomNivel += 10;
            aplicarZoomCompleto();
        }
    }
    
    function diminuirZoom() {
        if (zoomNivel > 50) {
            zoomNivel -= 10;
            aplicarZoomCompleto();
        }
    }
    
    function resetarZoom() {
        zoomNivel = 90;
        aplicarZoomCompleto();
        localStorage.removeItem('zoom_nivel');
    }
    
    // Carregar zoom salvo do localStorage
    var zoomSalvo = localStorage.getItem('zoom_nivel');
    if (zoomSalvo && zoomSalvo != zoomNivel) {
        zoomNivel = parseInt(zoomSalvo);
        aplicarZoomCompleto();
    }
    
    // Eventos dos botões de zoom
    document.getElementById('zoomMenos')?.addEventListener('click', diminuirZoom);
    document.getElementById('zoomMais')?.addEventListener('click', aumentarZoom);
    document.getElementById('zoomReset')?.addEventListener('click', resetarZoom);
    
    // Atalhos de teclado para zoom
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === '-') {
            e.preventDefault();
            diminuirZoom();
        } else if (e.ctrlKey && e.key === '+') {
            e.preventDefault();
            aumentarZoom();
        } else if (e.ctrlKey && e.key === '0') {
            e.preventDefault();
            resetarZoom();
        }
    });
    
    // ============================================
    // DATA E HORA EM TEMPO REAL
    // ============================================
    
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
    
    // ============================================
    // MENU E DROPDOWNS
    // ============================================
    
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    function toggleSubmenu(event) {
        event.preventDefault();
        const parentLi = event.currentTarget.closest('.has-submenu');
        const submenu = parentLi.querySelector('.nav-submenu');
        
        document.querySelectorAll('.has-submenu').forEach(item => {
            if (item !== parentLi) {
                item.classList.remove('open');
                item.querySelector('.nav-submenu')?.classList.remove('show');
            }
        });
        
        parentLi.classList.toggle('open');
        submenu.classList.toggle('show');
    }
    
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
    
    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function() {
        if (userDropdownMenu) userDropdownMenu.classList.remove('show');
        if (notificationsDropdown) notificationsDropdown.classList.remove('show');
    });
    
    // Função do Chat
    function abrirChat() {
        alert('💬 Chat em desenvolvimento.\n\nEm breve você poderá conversar com o suporte em tempo real!');
    }
    
    // Atualizar título da página baseado na URL
    function atualizarPageTitle() {
        const path = window.location.pathname;
        const pageName = path.split('/').pop();
        const pageTitle = document.getElementById('pageTitle');
        
        if (pageTitle) {
            const titles = {
                'index.php': 'Dashboard',
                'dashboard.php': 'Dashboard',
                'lista_nominal.php': 'Lista Nominal',
                'estatistico_alunos.php': 'Estatísticas de Alunos',
                'professor.php': 'Relatório de Professores',
                'pagamentos.php': 'Gestão de Pagamentos',
                'boletim_nota.php': 'Boletim de Notas',
                'historico_notas.php': 'Histórico de Notas',
                'historico_faltas.php': 'Histórico de Faltas',
                'cadernetas.php': 'Cadernetas',
                'manipautas.php': 'Manipautas'
            };
            pageTitle.textContent = titles[pageName] || 'SIGE Angola';
        }
    }
    
    atualizarPageTitle();
    
    // ============================================
    // ABRIR MENU BASEADO NA URL ATUAL
    // ============================================
    
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        
        const matches = (pattern) => currentPath.includes(pattern);
        
        // Académico
        if (matches('/alunos/') || matches('/professores/') || matches('/turmas/') || 
            matches('/disciplinas/') || matches('/notas/') || matches('/chamada/')) {
            const menu = document.getElementById('menuAcademico');
            const submenu = document.getElementById('submenuAcademico');
            if (menu) { menu.classList.add('open'); menu.classList.add('open'); }
            if (submenu) submenu.classList.add('show');
        }
        
        // Sistema de Avaliação
        if (matches('/avaliacao/')) {
            const menu = document.getElementById('menuSistemaAvaliacao');
            const submenu = document.getElementById('submenuSistemaAvaliacao');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // Relatórios Pedagógicos
        if (matches('/relatorios/') && !matches('/relatorios/notas') && !matches('/relatorios/frequencia')) {
            const menu = document.getElementById('menuRelatoriosPedagogicos');
            const submenu = document.getElementById('submenuRelatoriosPedagogicos');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // Tesouraria
        if (matches('/tesouraria/')) {
            const menu = document.getElementById('menuTesouraria');
            const submenu = document.getElementById('submenuTesouraria');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // RH
        if (matches('/rh/')) {
            const menu = document.getElementById('menuRH');
            const submenu = document.getElementById('submenuRH');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
            
            if (matches('/funcionarios/')) {
                const sub = document.getElementById('menuRHFuncionarios');
                const subSub = document.getElementById('submenuRHFuncionarios');
                if (sub) sub.classList.add('open');
                if (subSub) subSub.classList.add('show');
            }
            if (matches('/recrutamento/')) {
                const sub = document.getElementById('menuRHRecrutamento');
                const subSub = document.getElementById('submenuRHRecrutamento');
                if (sub) sub.classList.add('open');
                if (subSub) subSub.classList.add('show');
            }
            if (matches('/avaliacao/')) {
                const sub = document.getElementById('menuRHAvaliacao');
                const subSub = document.getElementById('submenuRHAvaliacao');
                if (sub) sub.classList.add('open');
                if (subSub) subSub.classList.add('show');
            }
        }
        
        // Secretaria
        if (matches('/secretaria/')) {
            const menu = document.getElementById('menuSecretaria');
            const submenu = document.getElementById('submenuSecretaria');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // Financeiro
        if (matches('/financeiro/')) {
            const menu = document.getElementById('menuFinanceiro');
            const submenu = document.getElementById('submenuFinanceiro');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // Configurações
        if (matches('/config/') || matches('/permissoes.php')) {
            const menu = document.getElementById('menuConfiguracoes');
            const submenu = document.getElementById('submenuConfiguracoes');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
        
        // Suporte
        if (matches('/suporte/')) {
            const menu = document.getElementById('menuSuporte');
            const submenu = document.getElementById('submenuSuporte');
            if (menu) menu.classList.add('open');
            if (submenu) submenu.classList.add('show');
        }
    });
</script>
</body>
</html>