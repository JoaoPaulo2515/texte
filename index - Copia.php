<?php
// index.php - Roteador principal do sistema

// ============================================
// INICIALIZAÇÃO DO SISTEMA
// ============================================

session_start();

// Carregar constantes
require_once __DIR__ . '/config/constants.php';

// Verificar instalação
$lockFile = __DIR__ . '/config/installed.lock';
if (!file_exists($lockFile) && basename($_SERVER['PHP_SELF']) !== 'install.php') {
    header('Location: install.php');
    exit;
}

// Configurar ambiente
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

ini_set('log_errors', 1);
ini_set('error_log', LOGS_PATH . '/error.log');

// ============================================
// DETECTAR ESCOLA POR DOMÍNIO
// ============================================

$host = $_SERVER['HTTP_HOST'];
$isSuperAdmin = false;
$escola = null;

// Verificar se é acesso local
if ($host === 'localhost' || $host === '127.0.0.1' || filter_var($host, FILTER_VALIDATE_IP)) {
    $isSuperAdmin = true;
} else {
    // Verificar subdomínio
    $subdominio = explode('.', $host)[0];
    $dominiosAdmin = ['admin', 'sige', 'administracao', 'super'];
    
    if (in_array($subdominio, $dominiosAdmin)) {
        $isSuperAdmin = true;
    } else {
        try {
            require_once __DIR__ . '/config/database.php';
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // Buscar escola pelo subdomínio
            $stmt = $conn->prepare("SELECT * FROM escolas WHERE subdominio = :subdominio AND status != 'inativa'");
            $stmt->execute([':subdominio' => $subdominio]);
            $escola = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($escola) {
                $_SESSION['escola_id'] = $escola['id'];
                $_SESSION['escola_nome'] = $escola['nome'];
                $_SESSION['escola_subdominio'] = $escola['subdominio'];
            } else {
                $isSuperAdmin = true;
            }
        } catch (Exception $e) {
            writeLog("Erro ao detectar escola: " . $e->getMessage(), 'error');
        }
    }
}

// ============================================
// ROTEAMENTO
// ============================================

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$action = isset($_GET['action']) ? $_GET['action'] : null;

// Verificar autenticação
$isLoggedIn = isset($_SESSION['usuario_id']) || isset($_SESSION['super_admin_id']);

if (!$isLoggedIn && $page !== 'login') {
    $page = 'login';
}

// Definir caminho base para includes
if ($isSuperAdmin && $isLoggedIn) {
    $basePath = __DIR__ . '/super-admin';
} elseif ($escola && $isLoggedIn) {
    $basePath = __DIR__ . '/escola';
} else {
    $basePath = __DIR__ . '/auth';
}

// ============================================
// CARREGAR PÁGINA
// ============================================

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo APP_DESCRIPTION; ?>">
    <meta name="author" content="<?php echo APP_AUTHOR; ?>">
    <title><?php echo APP_NAME; ?> - <?php echo ucfirst($page); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>/images/favicon.ico">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <!-- CSS Customizado -->
    <link href="<?php echo CSS_URL; ?>/style.css" rel="stylesheet">
    <link href="<?php echo CSS_URL; ?>/responsive.css" rel="stylesheet">
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</head>
<body>

<?php if ($isLoggedIn && $page !== 'login'): ?>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="user-avatar">
                <?php echo isset($_SESSION['usuario_nome']) ? strtoupper(substr($_SESSION['usuario_nome'], 0, 2)) : 'AD'; ?>
            </div>
            <div class="user-info">
                <h4><?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></h4>
                <p><?php echo $_SESSION['usuario_email'] ?? ''; ?></p>
                <?php if (isset($_SESSION['escola_nome'])): ?>
                <small><i class="fas fa-school"></i> <?php echo $_SESSION['escola_nome']; ?></small>
                <?php endif; ?>
            </div>
        </div>
        
        <nav class="nav-menu">
            <ul>
                <li><a href="?page=dashboard" class="<?php echo $page == 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <?php if ($isSuperAdmin): ?>
                <li><a href="?page=escolas" class="<?php echo $page == 'escolas' ? 'active' : ''; ?>"><i class="fas fa-school"></i> Escolas</a></li>
                <li><a href="?page=planos" class="<?php echo $page == 'planos' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Planos</a></li>
                <li><a href="?page=assinaturas" class="<?php echo $page == 'assinaturas' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
                <li><a href="?page=pagamentos" class="<?php echo $page == 'pagamentos' ? 'active' : ''; ?>"><i class="fas fa-money-bill"></i> Pagamentos</a></li>
                <li><a href="?page=suporte" class="<?php echo $page == 'suporte' ? 'active' : ''; ?>"><i class="fas fa-headset"></i> Suporte</a></li>
                <li><a href="?page=relatorios" class="<?php echo $page == 'relatorios' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                <?php else: ?>
                <li><a href="?page=alunos" class="<?php echo $page == 'alunos' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Alunos</a></li>
                <li><a href="?page=professores" class="<?php echo $page == 'professores' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
                <li><a href="?page=turmas" class="<?php echo $page == 'turmas' ? 'active' : ''; ?>"><i class="fas fa-users-group"></i> Turmas</a></li>
                <li><a href="?page=notas" class="<?php echo $page == 'notas' ? 'active' : ''; ?>"><i class="fas fa-graduation-cap"></i> Notas</a></li>
                <li><a href="?page=chamada" class="<?php echo $page == 'chamada' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Chamada</a></li>
                <?php endif; ?>
                <li><a href="?page=configuracoes" class="<?php echo $page == 'configuracoes' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Configurações</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
            </ul>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title">
                <h2><?php echo ucfirst($page); ?></h2>
            </div>
            <div class="user-menu">
                <i class="fas fa-bell"></i>
                <i class="fas fa-cog"></i>
            </div>
        </div>
        
        <div class="content">
<?php endif; ?>

            <!-- Conteúdo da Página -->
            <?php
            $pageFile = $basePath . '/' . $page . '.php';
            if (file_exists($pageFile)) {
                include $pageFile;
            } else {
                echo "<div class='alert alert-warning'>Página não encontrada.</div>";
            }
            ?>

<?php if ($isLoggedIn && $page !== 'login'): ?>
        </div>
    </div>
<?php endif; ?>

<!-- Scripts -->
<script>
    const APP_URL = '<?php echo APP_URL; ?>';
    const API_URL = '<?php echo APP_URL_API; ?>';
    const DEBUG = <?php echo DEBUG_MODE ? 'true' : 'false'; ?>;
    const ESCOLA_ID = '<?php echo $_SESSION['escola_id'] ?? ''; ?>';
    const IS_SUPER_ADMIN = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
</script>
<script src="<?php echo JS_URL; ?>/main.js"></script>

</body>
</html>