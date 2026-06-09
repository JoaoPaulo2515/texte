<?php
// escola/includes/auth.php - Autenticação da Escola com redirecionamento inteligente

session_start();

function getConnection() {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=sige_angola;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Erro na conexão: " . $e->getMessage());
    }
}

/**
 * Redireciona para a página de login preservando a URL de destino
 * @param string $destino - URL para onde redirecionar após o login
 */
function redirectToLogin($destino = null) {
    if (!$destino) {
        $destino = $_SERVER['REQUEST_URI'] ?? '';
    }
    
    // Codificar a URL de destino
    $destino_codificado = urlencode($destino);
    header("Location: login.php?redirect=$destino_codificado");
    exit;
}

/**
 * Verifica autenticação da ESCOLA e redireciona para onde o usuário estava tentando acessar
 * @return array Dados da escola autenticada
 */
function checkEscolaAuth() {
    // Verificar se está logado
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] != 'escola') {
        // Salvar a URL atual para redirecionar após o login
        $redirect_url = $_SERVER['REQUEST_URI'];
        redirectToLogin($redirect_url);
        exit;
    }
    
    // Verificar se a sessão expirou (opcional: validar com banco de dados)
    $conn = getConnection();
    $sql = "SELECT id, nome, email, endereco, telefone, status FROM escolas WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $_SESSION['escola_id']]);
    $escola = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$escola || $escola['status'] != 'ativa') {
        session_destroy();
        redirectToLogin();
        exit;
    }
    
    // Retornar dados da escola
    return [
        'escola_id' => $escola['id'],
        'nome' => $escola['nome'],
        'email' => $escola['email'],
        'endereco' => $escola['endereco'],
        'telefone' => $escola['telefone'],
        'user_type' => 'escola'
    ];
}
?>