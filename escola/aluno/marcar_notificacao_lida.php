<?php
// aluno/marcar_notificacao_lida.php - AJAX para marcar notificações como lidas

require_once __DIR__ . '/../config/database.php';
session_start();

header('Content-Type: application/json');

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];

// Verificar se é para marcar todas
if (isset($_POST['marcar_todas']) && $_POST['marcar_todas'] === true) {
    $sql = "UPDATE notificacoes SET lida = 1, data_leitura = NOW() WHERE aluno_id = :aluno_id AND lida = 0";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':aluno_id' => $aluno_id]);
    echo json_encode(['success' => true]);
    exit;
}

// Marcar uma notificação específica
if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    
    $sql = "UPDATE notificacoes SET lida = 1, data_leitura = NOW() WHERE id = :id AND aluno_id = :aluno_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':aluno_id' => $aluno_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
?>