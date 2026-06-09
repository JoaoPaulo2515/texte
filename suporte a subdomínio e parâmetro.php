<?php
// index.php - Roteador principal (suporte a subdomínio e parâmetro)
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

// Detectar escola pelo subdomínio ou parâmetro GET
function detectarEscola($conn) {
    $host = $_SERVER['HTTP_HOST'];
    $escola_param = $_GET['escola'] ?? '';
    
    // Via 1: Subdomínio
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        $subdominio = explode('.', $host)[0];
        $dominiosAdmin = ['admin', 'sige', 'www', 'localhost'];
        
        if (!in_array($subdominio, $dominiosAdmin)) {
            $stmt = $conn->prepare("SELECT * FROM escolas WHERE subdominio = :subdominio AND status = 'ativa'");
            $stmt->execute([':subdominio' => $subdominio]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // Via 2: Parâmetro GET (para localhost)
    if ($escola_param) {
        $stmt = $conn->prepare("SELECT * FROM escolas WHERE subdominio = :subdominio OR id = :id");
        $stmt->execute([':subdominio' => $escola_param, ':id' => $escola_param]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return null;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola = detectarEscola($conn);

// Redirecionar para login da escola se for uma escola válida
if ($escola && !isset($_SESSION['usuario_id'])) {
    header('Location: escola/login.php?escola=' . $escola['subdominio']);
    exit;
}

// ... resto do código do index



em super-admin/escolas/index.php, implementa botao para alteracao dos dados de acesso do admin da escola, em janela modal, com perguntta de confirmacao em caso se deseja continuar ou nao a troca dos dados de acesso, com o usuario e senha. mas estrutura do codigo deve ser a mesmo, so acrescenta esse pedido