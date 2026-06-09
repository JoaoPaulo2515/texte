<?php
// index.php - Roteador principal do sistema
session_start();

// ============================================
// CONFIGURAÇÕES INICIAIS
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Africa/Luanda');

// Verificar se está em modo de manutenção
$maintenanceFile = __DIR__ . '/config/maintenance.lock';
if (file_exists($maintenanceFile) && !isset($_SESSION['usuario_id'])) {
    die('<h1>Sistema em Manutenção</h1><p>Voltamos em breve.</p>');
}

// Verificar instalação
$lockFile = __DIR__ . '/config/installed.lock';
if (!file_exists($lockFile)) {
    header('Location: install.php');
    exit;
}

// Carregar classes e configurações
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

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
    $dominiosAdmin = ['admin', 'sige', 'administracao', 'super', 'www'];
    
    if (in_array($subdominio, $dominiosAdmin)) {
        $isSuperAdmin = true;
    } else {
        try {
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
            $isSuperAdmin = true;
        }
    }
}

// ============================================
// VERIFICAR AUTENTICAÇÃO
// ============================================

$isLoggedIn = isset($_SESSION['usuario_id']);
$pagina = $_GET['page'] ?? '';

// Rotas públicas (não precisam de login)
$rotasPublicas = ['login', 'logout', 'recuperar_senha', 'reset_senha'];

// Se não está logado e não é rota pública, redirecionar para login
if (!$isLoggedIn && !in_array($pagina, $rotasPublicas) && $pagina !== '') {
    header('Location: login.php');
    exit;
}

// Se está logado e tentou acessar login, redirecionar para dashboard correto
if ($isLoggedIn && $pagina === 'login') {
    if ($_SESSION['usuario_tipo'] === 'super_admin') {
        header('Location: super-admin/dashboard.php');
    } else {
        header('Location: escola/index.php');
    }
    exit;
}

// Se está logado e acessou a raiz, redirecionar para dashboard correto
if ($isLoggedIn && $pagina === '') {
    if ($_SESSION['usuario_tipo'] === 'super_admin') {
        header('Location: super-admin/dashboard.php');
        exit;
    } else {
        header('Location: escola/index.php');
        exit;
    }
}

// ============================================
// ROTEAMENTO DAS PÁGINAS
// ============================================

// Mapeamento de páginas para arquivos
$rotas = [
    // Páginas públicas
    'login' => 'login.php',
    'logout' => 'logout.php',
    'recuperar_senha' => 'recuperar_senha.php',
    'reset_senha' => 'reset_senha.php',
    
    // Páginas do Super Admin
    'dashboard' => 'super-admin/dashboard.php',
    'escolas' => 'super-admin/escolas/index.php',
    'planos' => 'super-admin/planos/index.php',
    'assinaturas' => 'super-admin/assinaturas/index.php',
    'pagamentos' => 'super-admin/pagamentos/index.php',
    'suporte' => 'super-admin/comunicacao/index.php',
    'relatorios' => 'super-admin/relatorios/index.php',
    'config_sistema' => 'super-admin/config/sistema.php',
    'permissoes' => 'super-admin/config/permissoes.php',
    
    // Páginas da Escola
    'escola_dashboard' => 'escola/index.php',
    'alunos' => 'escola/alunos/index.php',
    'professores' => 'escola/professores/index.php',
    'turmas' => 'escola/turmas/index.php',
    'disciplinas' => 'escola/disciplinas/index.php',
    'notas' => 'escola/notas/index.php',
    'chamada' => 'escola/chamada/index.php',
    'biblioteca' => 'escola/biblioteca/index.php',
    'relatorios_escola' => 'escola/relatorios/index.php'
];

// Se a página existe no mapeamento, incluir o arquivo
if (isset($rotas[$pagina])) {
    $arquivo = $rotas[$pagina];
    if (file_exists($arquivo)) {
        include $arquivo;
    } else {
        // Arquivo não encontrado, mostrar erro
        include '404.php';
    }
} elseif ($pagina !== '') {
    // Rota não encontrada
    include '404.php';
} else {
    // Se chegou aqui e está logado, redirecionar (já tratado acima)
    if ($isLoggedIn) {
        if ($_SESSION['usuario_tipo'] === 'super_admin') {
            header('Location: super-admin/dashboard.php');
        } else {
            header('Location: escola/index.php');
        }
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}
?>