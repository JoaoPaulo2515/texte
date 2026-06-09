<?php
// escola/professor/includes/menu_professor.php - Menu Lateral do Professor com Redirecionamento Inteligente

// ============================================
// SISTEMA DE REDIRECIONAMENTO INTELIGENTE
// ============================================

// Configuração de rotas - Mapeamento de todos os submenus para seus caminhos reais
$rotas_professor = [
    // Dashboard
    'dashboard' => 'dashboard.php',
    
    // Menu Acadêmico
    'minhas_turmas' => 'minhas_turmas.php',
    'lancar_notas' => 'lancar_notas.php',
    'registrar_chamada' => 'registrar_chamada.php',
    'atividades' => 'atividades.php',
    'meus_alunos' => 'meus_alunos.php',
    
    // Horário
    'meu_horario' => 'meu_horario.php',
    'meus_horarios' => 'meus_horarios.php',
    
    // Relatórios
    'relatorios_index' => 'index.php',
    'relatorios_mini_pautas' => 'mini_pautas.php',
    'relatorios_pautas_gerais' => 'pautas_gerais.php',
    'relatorios_estatistica_turma' => 'estatistica_turma.php',
    'relatorios_estatistica_disciplina' => 'estatistica_disciplina.php',
    
    // Agenda
    'calendario_provas' => 'calendario_provas.php',
    
    // Financeiro
    'meu_perfil' => 'meu_perfil.php',
    'meu_salario' => 'meu_salario.php',
    'dividas_pagar' => 'dividas_pagar.php',
    'dividas_receber' => 'dividas_receber.php',
    'solicitar_vale' => 'solicitar_vale.php',
    'solicitar_ferias' => 'solicitar_ferias.php',
    
    // ============================================
    // PROVA ONLINE - MÓDULO DO PROFESSOR
    // ============================================
    'provas_criar' => 'criar_prova.php',
    'provas_disponiveis' => 'provas_disponiveis.php',
    'provas_andamento' => 'provas_andamento.php',
    'provas_historico' => 'historico_provas.php',
    'provas_resultados' => 'resultados_provas.php',
    'provas_calendario' => 'calendario_provas.php',
    'provas_corrigir' => 'corrigir_provas.php',
    'provas_questoes' => 'gerenciar_questoes.php',
    
    // Outros
    'conselho_nota' => 'conselho_nota.php',
    'biblioteca' => 'biblioteca.php',
    'proposta_prova' => 'proposta_prova.php',
    
    // Suporte (acesso global)
    'suporte_chamados' => 'chamados.php',
    'suporte_faq' => 'faq.php',
    'suporte_manuais' => 'manuais.php',
    'suporte_tutoriais' => 'tutoriais.php',
];

// Função para redirecionamento inteligente
function getLinkProfessor($destino, $rotas) {
    if (isset($rotas[$destino])) {
        return $rotas[$destino];
    }
    if (strpos($destino, 'http') === 0 || strpos($destino, '//') === 0) {
        return $destino;
    }
    return $destino;
}

// ============================================
// FUNÇÕES PARA CABEÇALHO E RODAPÉ
// ============================================

// Buscar informações do professor
function getProfessorInfo($conn, $usuario_id, $escola_id) {
    try {
        $sql = "SELECT f.*, u.nome as usuario_nome, u.email 
                FROM funcionarios f 
                JOIN usuarios u ON u.id = f.usuario_id 
                WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Buscar informações da escola
function getEscolaInfoProfessor($conn, $escola_id) {
    try {
        $sql = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $escola_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
    }
}

// Buscar notificações não lidas do professor
function getNotificacoesProfessor($conn, $usuario_id) {
    try {
        $sql = "SELECT COUNT(*) as total FROM notificacoes 
                WHERE usuario_id = :usuario_id AND destino = 'professor' AND lida = 0";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

// Buscar turmas do professor
function getTurmasProfessor($conn, $funcionario_id) {
    try {
        $sql = "SELECT DISTINCT t.id, t.nome, t.ano, t.turno 
                FROM turmas t
                JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
                WHERE pdt.professor_id = :funcionario_id AND t.status = 'ativa'
                ORDER BY t.ano, t.nome";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':funcionario_id' => $funcionario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Buscar total de alunos do professor
function getTotalAlunosProfessor($conn, $funcionario_id) {
    try {
        $sql = "SELECT COUNT(DISTINCT m.estudante_id) as total 
                FROM matriculas m
                JOIN professor_disciplina_turma pdt ON pdt.turma_id = m.turma_id
                WHERE pdt.professor_id = :funcionario_id AND m.status = 'ativa'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':funcionario_id' => $funcionario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (PDOException $e) {
        return 0;
    }
}

// Buscar última atividade do usuário
function getUltimoAcessoProfessor($conn, $usuario_id) {
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
function atualizarUltimoAcessoProfessor($conn, $usuario_id) {
    try {
        $sql = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
    } catch (PDOException $e) {}
}

// Buscar versão do sistema
function getVersaoSistemaProfessor() {
    return '2.5.0';
}

// Buscar data atual formatada
function getDataAtualFormatada() {
    setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');
    return strftime('%A, %d de %B de %Y');
}

// ============================================
// CARREGAMENTO DE DADOS
// ============================================

// Atualizar último acesso
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    atualizarUltimoAcessoProfessor($conn, $_SESSION['usuario_id']);
}

$professor_info = null;
$escola_info = null;
$total_notificacoes = 0;
$turmas_professor = [];
$total_alunos = 0;
$ultimo_acesso = '';

if (isset($conn) && isset($_SESSION['usuario_id']) && isset($_SESSION['escola_id'])) {
    $professor_info = getProfessorInfo($conn, $_SESSION['usuario_id'], $_SESSION['escola_id']);
    $escola_info = getEscolaInfoProfessor($conn, $_SESSION['escola_id']);
    $total_notificacoes = getNotificacoesProfessor($conn, $_SESSION['usuario_id']);
    $ultimo_acesso = getUltimoAcessoProfessor($conn, $_SESSION['usuario_id']);
    
    if ($professor_info && isset($professor_info['id'])) {
        $turmas_professor = getTurmasProfessor($conn, $professor_info['id']);
        $total_alunos = getTotalAlunosProfessor($conn, $professor_info['id']);
    }
}

$versao_sistema = getVersaoSistemaProfessor();
$data_atual = getDataAtualFormatada();
$ano_atual = date('Y');

// Detectar página atual
$current_url = $_SERVER['REQUEST_URI'];
$current_file = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>

<style>
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
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
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
        font-size: 1.3em;
        margin-bottom: 5px;
    }
    
    .sidebar-header p {
        font-size: 0.8em;
        opacity: 0.8;
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
        font-size: 0.95em;
        cursor: pointer;
    }
    
    .nav-link:hover, .nav-link.active {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    
    .nav-link.active {
        border-left: 4px solid #FFD700;
    }
    
    .nav-link i {
        width: 20px;
        text-align: center;
    }
    
    .has-submenu > .nav-link {
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
        max-height: 800px;
        overflow-y: auto;
    }
    
    .nav-submenu .nav-link {
        padding: 10px 25px;
        font-size: 0.85em;
    }
    
    .nav-submenu .nav-link i {
        font-size: 0.8em;
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
    
    /* Top Header */
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
    
    .chat-btn, .notifications-btn {
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
    }
    
    .chat-btn:hover, .notifications-btn:hover {
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
    
    /* Main Content */
    .main-content {
        margin-left: 280px;
        margin-top: 60px;
        margin-bottom: 45px;
        padding: 20px;
        background: #f5f7fb;
        min-height: calc(100vh - 105px);
    }
    
    /* Stats Cards */
    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }
    
    .stat-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        transition: transform 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
    }
    
    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #006B3E;
    }
    
    .stat-label {
        color: #666;
        font-size: 0.85em;
        margin-top: 5px;
    }
    
    .stat-icon {
        font-size: 1.8em;
        color: #1A2A6C;
        margin-bottom: 10px;
    }
    
    /* Footer */
    .footer-professor {
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
    
    .footer-professor i {
        margin-right: 3px;
    }
    
    /* Menu Toggle */
    .menu-toggle {
        display: none;
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
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @media (max-width: 768px) {
        .top-header { left: 0; }
        .footer-professor { left: 0; }
        .main-content { margin-left: 0; margin-top: 60px; margin-bottom: 45px; }
        .sidebar { left: -280px; }
        .sidebar.open { left: 0; }
        .menu-toggle { display: block; }
        .page-title { margin-left: 50px; }
        .user-name { display: none; }
        .notifications-dropdown { right: 70px; width: 320px; }
        .stats-cards { grid-template-columns: repeat(2, 1fr); }
    }
    
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
    
    .realtime-badge {
        font-size: 0.7em;
        background: #28a745;
        color: white;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 5px;
    }
</style>

<!-- Botão Menu Toggle para Mobile -->
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- TOP HEADER -->
<div class="top-header">
    <div class="header-left">
        <div class="page-title" id="pageTitle">Professor - Dashboard</div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"></span>
            <span class="realtime-badge">AO</span>
        </div>
    </div>
    <div class="header-right">
        <button class="chat-btn" id="chatBtn" onclick="abrirChat()">
            <i class="fas fa-comment-dots"></i>
        </button>
        
        <div class="notifications-dropdown" id="notificationsDropdown">
            <div class="notifications-header"><i class="fas fa-bell"></i> Notificações</div>
            <div class="notifications-list">
                <div class="notification-item"><div class="notification-title">Bem-vindo ao SIGE Angola!</div><div class="notification-time">Agora</div></div>
                <div class="notification-item unread"><div class="notification-title">Nova versão disponível (<?php echo $versao_sistema; ?>)</div><div class="notification-time">Hoje</div></div>
            </div>
            <div class="notifications-footer"><a href="#" style="color:#006B3E;">Ver todas</a></div>
        </div>
        
        <div class="notifications-btn" id="notificationsBtn">
            <i class="fas fa-bell"></i>
            <?php if ($total_notificacoes > 0): ?>
            <span class="notification-badge"><?php echo $total_notificacoes; ?></span>
            <?php endif; ?>
        </div>
        
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar"><i class="fas fa-user-chalkboard"></i></div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo htmlspecialchars($professor_info['usuario_nome'] ?? 'Professor'); ?></div>
                    <div class="user-role">Professor</div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.8em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="<?php echo getLinkProfessor('meu_perfil', $rotas_professor); ?>" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <div class="dropdown-divider"></div>
                <div class="last-access"><i class="fas fa-history"></i> Último acesso: <?php echo date('d/m/Y H:i:s', strtotime($ultimo_acesso)); ?></div>
                <div class="dropdown-divider"></div>
                <a href="../../logout.php" class="dropdown-item-custom"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
        <h3>SIGE Angola</h3>
        <p>Área do Professor</p>
        <?php if ($professor_info): ?>
        <div class="user-info-sidebar mt-2">
            <small><i class="fas fa-user"></i> <?php echo htmlspecialchars($professor_info['usuario_nome'] ?? 'Professor'); ?></small><br>
            <small><i class="fas fa-building"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'Escola'); ?></small>
        </div>
        <?php endif; ?>
    </div>
    
    <ul class="nav-menu">
        <!-- Dashboard -->
        <li class="nav-item">
            <a href="<?php echo getLinkProfessor('dashboard', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Menu Acadêmico -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-graduation-cap"></i> Menu Acadêmico</a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('minhas_turmas', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'minhas_turmas.php' ? 'active' : ''; ?>"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('lancar_notas', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'lancar_notas.php' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('registrar_chamada', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'registrar_chamada.php' ? 'active' : ''; ?>"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('atividades', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'atividades.php' ? 'active' : ''; ?>"><i class="fas fa-tasks"></i> Atividades</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('meus_alunos', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'meus_alunos.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Meus Alunos</a></li>
            </ul>
        </li>
        
        <!-- Meu Horário -->
        <li class="nav-item">
            <a href="<?php echo getLinkProfessor('meu_horario', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'meu_horario.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-week"></i> Meu Horário
            </a>
        </li>
        
        <!-- ============================================ -->
        <!-- PROVA ONLINE - MÓDULO DO PROFESSOR             -->
        <!-- ============================================ -->
        <li class="nav-item has-submenu" id="menuProvaOnline">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-file-alt"></i> Prova Online <span class="badge-prova">Novo</span></a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_criar', $rotas_professor); ?>" class="nav-link"><i class="fas fa-plus-circle"></i> Criar Prova</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_disponiveis', $rotas_professor); ?>" class="nav-link"><i class="fas fa-play-circle"></i> Provas Disponíveis</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_andamento', $rotas_professor); ?>" class="nav-link"><i class="fas fa-hourglass-half"></i> Provas em Andamento</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_historico', $rotas_professor); ?>" class="nav-link"><i class="fas fa-history"></i> Histórico de Provas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_resultados', $rotas_professor); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Resultados das Provas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_calendario', $rotas_professor); ?>" class="nav-link"><i class="fas fa-calendar-alt"></i> Calendário de Provas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_corrigir', $rotas_professor); ?>" class="nav-link"><i class="fas fa-check-double"></i> Corrigir Provas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('provas_questoes', $rotas_professor); ?>" class="nav-link"><i class="fas fa-database"></i> Banco de Questões</a></li>
            </ul>
        </li>
        
        <!-- Relatórios -->
        <li class="nav-item has-submenu" id="menuRelatorios">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('relatorios_mini_pautas', $rotas_professor); ?>" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('relatorios_pautas_gerais', $rotas_professor); ?>" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('relatorios_estatistica_turma', $rotas_professor); ?>" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('relatorios_estatistica_disciplina', $rotas_professor); ?>" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
            </ul>
        </li>
        
        <!-- Agenda -->
        <li class="nav-item has-submenu" id="menuAgenda">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-calendar-alt"></i> Agenda</a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('meus_horarios', $rotas_professor); ?>" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('calendario_provas', $rotas_professor); ?>" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
            </ul>
        </li>
        
        <!-- Financeiro -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-coins"></i> Financeiro</a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('meu_perfil', $rotas_professor); ?>" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('meu_salario', $rotas_professor); ?>" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('dividas_pagar', $rotas_professor); ?>" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('dividas_receber', $rotas_professor); ?>" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('solicitar_vale', $rotas_professor); ?>" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('solicitar_ferias', $rotas_professor); ?>" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
            </ul>
        </li>
        
        <!-- Conselho de Nota -->
        <li class="nav-item">
            <a href="<?php echo getLinkProfessor('conselho_nota', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'conselho_nota.php' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-user"></i> Conselho de Nota
            </a>
        </li>
        
        <!-- Biblioteca -->
        <li class="nav-item">
            <a href="<?php echo getLinkProfessor('biblioteca', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'biblioteca.php' ? 'active' : ''; ?>">
                <i class="fas fa-book"></i> Biblioteca
            </a>
        </li>
        
        <!-- Proposta de Prova -->
        <li class="nav-item">
            <a href="<?php echo getLinkProfessor('proposta_prova', $rotas_professor); ?>" class="nav-link <?php echo $current_file == 'proposta_prova.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Proposta de Prova
            </a>
        </li>
        
        <!-- Suporte -->
        <li class="nav-item has-submenu" id="menuSuporte">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-headset"></i> Suporte</a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkProfessor('suporte_chamados', $rotas_professor); ?>" class="nav-link"><i class="fas fa-ticket-alt"></i> Chamados</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('suporte_faq', $rotas_professor); ?>" class="nav-link"><i class="fas fa-question-circle"></i> FAQ</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('suporte_manuais', $rotas_professor); ?>" class="nav-link"><i class="fas fa-book"></i> Manuais</a></li>
                <li class="nav-item"><a href="<?php echo getLinkProfessor('suporte_tutoriais', $rotas_professor); ?>" class="nav-link"><i class="fas fa-video"></i> Tutoriais</a></li>
            </ul>
        </li>
        
        <!-- Divisória -->
        <li class="nav-item"><hr style="margin: 10px 25px; border-color: rgba(255,255,255,0.1);"></li>
        
        <!-- Sair -->
        <li class="nav-item">
            <a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<!-- Cards de Estatísticas (apenas no dashboard) -->
<?php if ($current_file == 'dashboard.php'): ?>
<div class="stats-cards">
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-chalkboard"></i></div><div class="stat-number"><?php echo count($turmas_professor); ?></div><div class="stat-label">Minhas Turmas</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-number"><?php echo $total_alunos; ?></div><div class="stat-label">Total de Alunos</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-number"><?php echo $total_notificacoes; ?></div><div class="stat-label">Notificações</div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-week"></i></div><div class="stat-number"><?php echo date('H:i'); ?></div><div class="stat-label">Horário Atual</div></div>
</div>
<?php endif; ?>

<!-- RODAPÉ -->
<div class="footer-professor">
    <div class="footer-left">
        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'SIGE Angola'); ?></span>
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
        <span><i class="fas fa-user-check"></i> Professor</span>
        <span><i class="fas fa-copyright"></i> <?php echo $ano_atual; ?> SIGE Angola</span>
    </div>
</div>

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
    
    // Toggle submenu
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    window.toggleSubmenu = function(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const parent = event.currentTarget.closest('.has-submenu');
        if (parent) {
            parent.classList.toggle('open');
        }
    };
    
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
    
    document.addEventListener('click', function() {
        if (userDropdownMenu) userDropdownMenu.classList.remove('show');
        if (notificationsDropdown) notificationsDropdown.classList.remove('show');
    });
    
    function abrirChat() {
        alert('Chat em desenvolvimento. Em breve você poderá conversar com o suporte!');
    }
    
    // Manter submenus abertos baseado na URL atual
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.pathname;
        const currentFile = currentUrl.split('/').pop();
        
        const menuMapping = {
            'minhas_turmas': 'menuAcademico', 'lancar_notas': 'menuAcademico',
            'registrar_chamada': 'menuAcademico', 'atividades': 'menuAcademico',
            'meus_alunos': 'menuAcademico', 'mini_pautas': 'menuRelatorios',
            'pautas_gerais': 'menuRelatorios', 'estatistica_turma': 'menuRelatorios',
            'estatistica_disciplina': 'menuRelatorios', 'meus_horarios': 'menuAgenda',
            'calendario_provas': 'menuAgenda', 'meu_perfil': 'menuFinanceiro',
            'meu_salario': 'menuFinanceiro', 'dividas_pagar': 'menuFinanceiro',
            'dividas_receber': 'menuFinanceiro', 'solicitar_vale': 'menuFinanceiro',
            'solicitar_ferias': 'menuFinanceiro', 'chamados': 'menuSuporte',
            'faq': 'menuSuporte', 'manuais': 'menuSuporte', 'tutoriais': 'menuSuporte',
            'criar_prova': 'menuProvaOnline', 'provas_disponiveis': 'menuProvaOnline',
            'provas_andamento': 'menuProvaOnline', 'historico_provas': 'menuProvaOnline',
            'resultados_provas': 'menuProvaOnline', 'calendario_provas': 'menuProvaOnline',
            'corrigir_provas': 'menuProvaOnline', 'gerenciar_questoes': 'menuProvaOnline'
        };
        
        for (const [page, menuId] of Object.entries(menuMapping)) {
            if (currentFile.includes(page)) {
                const menuElement = document.getElementById(menuId);
                if (menuElement) menuElement.classList.add('open');
                break;
            }
        }
    });
</script>