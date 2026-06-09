<?php
// menu_tesouraria.php - Menu lateral para a Tesouraria com Redirecionamento Inteligente
// Este arquivo deve ser incluído em todas as páginas da área da tesouraria

// ============================================
// SISTEMA DE REDIRECIONAMENTO INTELIGENTE
// ============================================

// Configuração de rotas - Mapeamento de todos os submenus para seus caminhos reais
$rotas_tesouraria = [
    // Dashboard
    'dashboard' => 'index.php',
    
    // Gestão de Pagamentos
    'pagamentos' => 'pagamentos.php',
    'novo_pagamento' => 'novo_pagamento.php',
    'ver_pagamentos' => 'ver_pagamentos.php',
    
    // Mensalidades
    'mensalidades' => 'mensalidades.php',
    'lancar_mensalidades' => 'lancar_mensalidades.php',
    'consultar_mensalidades' => 'consultar_mensalidades.php',
    
    // Dívidas
    'dividas' => 'dividas.php',
    'dividas_alunos' => 'dividas_alunos.php',
    'parcelamentos' => 'parcelamentos.php',
    
    // Caixa
    'caixa' => 'caixa.php',
    'caixa_diario' => 'caixa_diario.php',
    'fechar_caixa' => 'fechar_caixa.php',
    
    // Receitas e Despesas
    'receitas' => 'receitas.php',
    'despesas' => 'despesas.php',
    'categorias' => 'categorias.php',
    
    // Fluxo de Caixa
    'fluxo_caixa' => 'fluxo_caixa.php',
    'balancete' => 'balancete.php',
    'extrato' => 'extrato.php',
    
    // Recibos
    'recibos' => 'recibos.php',
    'emitir_recibo' => 'emitir_recibo.php',
    'consultar_recibos' => 'consultar_recibos.php',
    
    // Faturação (NOVO)
    'fatura_proforma' => 'faturacao/fatura_proforma.php',
    'facturas' => 'faturacao/facturas.php',
    'factura_recibo' => 'faturacao/factura_recibo.php',
    'recibos_faturacao' => 'faturacao/recibos.php',
    
    // Relatórios
    'relatorios_financeiros' => 'relatorios_financeiros.php',
    'relatorios_diarios' => 'relatorios_diarios.php',
    'relatorios_mensais' => 'relatorios_mensais.php',
    'relatorios_anuais' => 'relatorios_anuais.php',
    
    // Configurações
    'configuracoes' => 'configuracoes.php',
    'taxas_multas' => 'taxas_multas.php',
    'contas_bancarias' => 'contas_bancarias.php',
];

// Função para redirecionamento inteligente
function getLinkTesouraria($destino, $rotas) {
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

// Buscar informações da escola
function getEscolaInfoTesouraria($conn, $escola_id) {
    try {
        $sql = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $escola_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
    }
}

// Buscar informações do usuário financeiro
function getUserInfoTesouraria($conn, $usuario_id) {
    try {
        $sql = "SELECT nome, email, tipo, papel FROM usuarios WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['nome' => 'Usuário', 'email' => '', 'tipo' => 'financeiro', 'papel' => 'financeiro'];
    }
}

// Buscar resumo financeiro do dia
function getResumoFinanceiro($conn, $escola_id) {
    $hoje = date('Y-m-d');
    $resumo = [];
    
    // Total de receitas do dia
    $sql = "SELECT SUM(valor) as total FROM pagamentos WHERE escola_id = :escola_id AND status = 'confirmado' AND DATE(data_pagamento) = :data";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':data' => $hoje]);
    $resumo['receitas_hoje'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total de despesas do dia
    $sql = "SELECT SUM(valor) as total FROM caixa WHERE escola_id = :escola_id AND tipo = 'saida' AND status = 'ativo' AND DATE(data_movimento) = :data";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':data' => $hoje]);
    $resumo['despesas_hoje'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Saldo do dia
    $resumo['saldo_hoje'] = $resumo['receitas_hoje'] - $resumo['despesas_hoje'];
    
    // Total de pendentes
    $sql = "SELECT SUM(valor_total - valor_pago) as total FROM mensalidades WHERE escola_id = :escola_id AND status IN ('pendente', 'parcial')";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id]);
    $resumo['total_pendente'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    return $resumo;
}

// Buscar versão do sistema
function getVersaoSistemaTesouraria() {
    return '2.5.0';
}

// Buscar data atual formatada
function getDataAtualFormatadaTesouraria() {
    setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');
    return strftime('%A, %d de %B de %Y');
}

// ============================================
// CARREGAMENTO DE DADOS
// ============================================
$escola_info = [];
$user_info = [];
$resumo_financeiro = [];
$total_notificacoes = 0;
$ultimo_acesso = '';

if (isset($_SESSION['escola_id']) && isset($conn)) {
    $escola_info = getEscolaInfoTesouraria($conn, $_SESSION['escola_id']);
}

if (isset($_SESSION['usuario_id']) && isset($conn)) {
    $user_info = getUserInfoTesouraria($conn, $_SESSION['usuario_id']);
    $resumo_financeiro = getResumoFinanceiro($conn, $_SESSION['escola_id']);
    $ultimo_acesso = $_SESSION['ultimo_acesso'] ?? date('Y-m-d H:i:s');
}

$versao_sistema = getVersaoSistemaTesouraria();
$data_atual = getDataAtualFormatadaTesouraria();
$ano_atual = date('Y');

// Página atual
$current_url = $_SERVER['REQUEST_URI'];
$current_file = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>

<style>
    /* Sidebar */
    .sidebar-tesouraria {
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
    
    .nav-link:hover,
    .nav-link.active {
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
    
    .nav-submenu .nav-link i {
        font-size: 0.8em;
    }
    
    /* Top Header */
    .top-header-tesouraria {
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
    
    .resumo-card-header {
        background: linear-gradient(135deg, #FFD700, #006B3E);
        padding: 8px 15px;
        border-radius: 20px;
        color: #1A2A6C;
        font-weight: bold;
        font-size: 0.85em;
    }
    
    .resumo-card-header i {
        margin-right: 5px;
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
    .main-content-tesouraria {
        margin-left: 280px;
        margin-top: 60px;
        margin-bottom: 45px;
        padding: 20px;
        background: #f5f7fb;
        min-height: calc(100vh - 105px);
    }
    
    /* Footer */
    .footer-tesouraria {
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
    
    /* Menu Toggle */
    .menu-toggle-tesouraria {
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
        .top-header-tesouraria { left: 0; }
        .footer-tesouraria { left: 0; }
        .main-content-tesouraria { margin-left: 0; margin-top: 60px; margin-bottom: 45px; }
        .sidebar-tesouraria { left: -280px; }
        .sidebar-tesouraria.open { left: 0; }
        .menu-toggle-tesouraria { display: block; }
        .page-title { margin-left: 50px; }
        .user-name { display: none; }
    }
    
    .sidebar-tesouraria::-webkit-scrollbar {
        width: 5px;
    }
    
    .sidebar-tesouraria::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.1);
    }
    
    .sidebar-tesouraria::-webkit-scrollbar-thumb {
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
    
    .badge-tesouraria {
        background: #FFD700;
        color: #1A2A6C;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        margin-left: 8px;
    }
</style>

<!-- Botão Menu Toggle -->
<button class="menu-toggle-tesouraria" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header-tesouraria">
    <div class="header-left">
        <div class="page-title" id="pageTitle">Tesouraria - Dashboard</div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"></span>
            <span class="realtime-badge">AO</span>
        </div>
    </div>
    <div class="header-right">
        <div class="resumo-card-header">
            <i class="fas fa-money-bill-wave"></i> 
            Hoje: <span id="resumoHoje"><?php echo number_format($resumo_financeiro['saldo_hoje'], 2, ',', '.'); ?> Kz</span>
        </div>
        
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar"><i class="fas fa-coins"></i></div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo htmlspecialchars($user_info['nome'] ?? 'Financeiro'); ?></div>
                    <div class="user-role">Tesouraria</div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.8em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="perfil.php" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <a href="configuracoes.php" class="dropdown-item-custom"><i class="fas fa-cog"></i> Configurações</a>
                <div class="dropdown-divider"></div>
                <div class="last-access"><i class="fas fa-history"></i> Último acesso: <?php echo date('d/m/Y H:i:s', strtotime($ultimo_acesso)); ?></div>
                <div class="dropdown-divider"></div>
                <a href="../../logout.php" class="dropdown-item-custom"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar da Tesouraria -->
<div class="sidebar-tesouraria" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-coins"></i></div>
        <h3>SIGE Angola</h3>
        <p>Tesouraria</p>
        <div class="user-info-sidebar mt-2">
            <small><i class="fas fa-user"></i> <?php echo htmlspecialchars($user_info['nome'] ?? 'Financeiro'); ?></small><br>
            <small><i class="fas fa-building"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'Escola'); ?></small><br>
            <small><i class="fas fa-chart-line"></i> Financeiro</small>
        </div>
    </div>
    
    <ul class="nav-menu">
        <!-- DASHBOARD -->
        <li class="nav-item">
            <a href="<?php echo getLinkTesouraria('dashboard', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
                <span class="badge-tesouraria">Principal</span>
            </a>
        </li>
        
        <!-- GESTÃO DE PAGAMENTOS -->
        <li class="nav-item has-submenu" id="menuPagamentos">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-credit-card"></i> Gestão de Pagamentos
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('pagamentos', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'pagamentos.php' ? 'active' : ''; ?>"><i class="fas fa-plus-circle"></i> Novo Pagamento</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('ver_pagamentos', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'ver_pagamentos.php' ? 'active' : ''; ?>"><i class="fas fa-eye"></i> Ver Pagamentos</a></li>
            </ul>
        </li>
        
        <!-- MENSALIDADES -->
        <li class="nav-item has-submenu" id="menuMensalidades">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-calendar-dollar"></i> Mensalidades
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('mensalidades', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'mensalidades.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> Gerenciar Mensalidades</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('lancar_mensalidades', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-plus"></i> Lançar Mensalidades</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('consultar_mensalidades', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-search"></i> Consultar Mensalidades</a></li>
            </ul>
        </li>
        
        <!-- DÍVIDAS -->
        <li class="nav-item has-submenu" id="menuDividas">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-exclamation-triangle"></i> Dívidas
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('dividas', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'dividas.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> Lista de Dívidas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('dividas_alunos', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-user-graduate"></i> Dívidas por Aluno</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('parcelamentos', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
            </ul>
        </li>
        
        <!-- CAIXA -->
        <li class="nav-item has-submenu" id="menuCaixa">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-cash-register"></i> Caixa
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('caixa', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'caixa.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Caixa Diário</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('caixa_diario', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-eye"></i> Consultar Caixa</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('fechar_caixa', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-lock"></i> Fechar Caixa</a></li>
            </ul>
        </li>
        
        <!-- RECEITAS E DESPESAS -->
        <li class="nav-item has-submenu" id="menuReceitasDespesas">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-chart-pie"></i> Receitas e Despesas
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('receitas', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'receitas.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-up text-success"></i> Receitas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('despesas', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'despesas.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-down text-danger"></i> Despesas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('categorias', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-tags"></i> Categorias</a></li>
            </ul>
        </li>
        
        <!-- FLUXO DE CAIXA -->
        <li class="nav-item has-submenu" id="menuFluxoCaixa">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-chart-line"></i> Fluxo de Caixa
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('fluxo_caixa', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'fluxo_caixa.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('balancete', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'balancete.php' ? 'active' : ''; ?>"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('extrato', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'extrato.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i> Extrato</a></li>
            </ul>
        </li>
        
        <!-- RECIBOS -->
        <li class="nav-item has-submenu" id="menuRecibos">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-receipt"></i> Recibos
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('recibos', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'recibos.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> Listar Recibos</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('emitir_recibo', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-print"></i> Emitir Recibo</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('consultar_recibos', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-search"></i> Consultar Recibos</a></li>
            </ul>
        </li>
        
        <!-- FATURAÇÃO (NOVO) -->
        <li class="nav-item has-submenu" id="menuFaturacao">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-file-invoice-dollar"></i> Faturação
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('fatura_proforma', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'fatura_proforma.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice"></i> Fatura Pró-Forma</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('facturas', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'facturas.php' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> Facturas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('factura_recibo', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'factura_recibo.php' ? 'active' : ''; ?>"><i class="fas fa-file-pdf"></i> Factura/Recibo</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('recibos_faturacao', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'recibos_faturacao.php' ? 'active' : ''; ?>"><i class="fas fa-receipt"></i> Recibos</a></li>
            </ul>
        </li>
        
        <!-- RELATÓRIOS -->
        <li class="nav-item has-submenu" id="menuRelatorios">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-file-alt"></i> Relatórios
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('relatorios_financeiros', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'relatorios_financeiros.php' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Relatórios Financeiros</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('relatorios_diarios', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-calendar-day"></i> Relatórios Diários</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('relatorios_mensais', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-calendar-alt"></i> Relatórios Mensais</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('relatorios_anuais', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-calendar-year"></i> Relatórios Anuais</a></li>
            </ul>
        </li>
        
        <!-- CONFIGURAÇÕES -->
        <li class="nav-item has-submenu" id="menuConfiguracoes">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-cogs"></i> Configurações
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('configuracoes', $rotas_tesouraria); ?>" class="nav-link <?php echo $current_file == 'configuracoes.php' ? 'active' : ''; ?>"><i class="fas fa-sliders-h"></i> Geral</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('taxas_multas', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-percent"></i> Taxas e Multas</a></li>
                <li class="nav-item"><a href="<?php echo getLinkTesouraria('contas_bancarias', $rotas_tesouraria); ?>" class="nav-link"><i class="fas fa-university"></i> Contas Bancárias</a></li>
            </ul>
        </li>
        
        <!-- DIVISÓRIA -->
        <li class="nav-item">
            <hr style="margin: 10px 25px; border-color: rgba(255,255,255,0.1);">
        </li>
        
        <!-- VOLTAR -->
        <li class="nav-item">
            <a href="../dashboard.php" class="nav-link">
                <i class="fas fa-arrow-left"></i> Voltar ao Sistema
            </a>
        </li>
        
        <!-- SAIR -->
        <li class="nav-item">
            <a href="../../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Sair
            </a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer-tesouraria">
    <div class="footer-left">
        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'SIGE Angola'); ?></span>
        <?php if (!empty($escola_info['endereco'])): ?>
        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($escola_info['endereco']); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['telefone'])): ?>
        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($escola_info['telefone']); ?></span>
        <?php endif; ?>
    </div>
    <div class="footer-right">
        <span><i class="fas fa-code-branch"></i> Versão <?php echo $versao_sistema; ?></span>
        <span><i class="fas fa-coins"></i> Tesouraria</span>
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
        const parentLi = event.currentTarget.closest('.has-submenu');
        if (parentLi) {
            const submenu = parentLi.querySelector('.nav-submenu');
            parentLi.classList.toggle('open');
            if (submenu) submenu.classList.toggle('show');
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
    
    document.addEventListener('click', function() {
        if (userDropdownMenu) userDropdownMenu.classList.remove('show');
    });
    
    // Manter submenus abertos baseado na URL atual
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.pathname;
        const currentFile = currentUrl.split('/').pop();
        
        const menuMapping = {
            'pagamentos': 'menuPagamentos',
            'ver_pagamentos': 'menuPagamentos',
            'mensalidades': 'menuMensalidades',
            'dividas': 'menuDividas',
            'caixa': 'menuCaixa',
            'receitas': 'menuReceitasDespesas',
            'despesas': 'menuReceitasDespesas',
            'fluxo_caixa': 'menuFluxoCaixa',
            'balancete': 'menuFluxoCaixa',
            'extrato': 'menuFluxoCaixa',
            'recibos': 'menuRecibos',
            'fatura_proforma': 'menuFaturacao',
            'facturas': 'menuFaturacao',
            'factura_recibo': 'menuFaturacao',
            'recibos_faturacao': 'menuFaturacao',
            'relatorios': 'menuRelatorios',
            'configuracoes': 'menuConfiguracoes'
        };
        
        for (const [page, menuId] of Object.entries(menuMapping)) {
            if (currentFile.includes(page)) {
                const menuElement = document.getElementById(menuId);
                if (menuElement) {
                    menuElement.classList.add('open');
                    const submenu = menuElement.querySelector('.nav-submenu');
                    if (submenu) submenu.classList.add('show');
                }
                break;
            }
        }
    });
</script>