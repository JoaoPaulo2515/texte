<?php
// logout.php - Encerrar sessão do usuário
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Registrar log de logout
if (isset($_SESSION['usuario_id'])) {
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, ip, created_at)
            VALUES (:usuario_id, 'logout', :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
    } catch (Exception $e) {
        // Ignorar erro de log
    }
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir cookie de sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir a sessão
session_destroy();

// Redirecionar para o login
header('Location: login.php');
exit;