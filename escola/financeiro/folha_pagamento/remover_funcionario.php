<?php
// escola/financeiro/folha_pagamento/remover_funcionario.php
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$funcionario_id = $_POST['funcionario_id'] ?? 0;

if ($funcionario_id) {
    try {
        $stmt = $conn->prepare("DELETE FROM folha_funcionarios WHERE funcionario_id = ? AND escola_id = ?");
        $stmt->execute([$funcionario_id, $escola_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
}
?>