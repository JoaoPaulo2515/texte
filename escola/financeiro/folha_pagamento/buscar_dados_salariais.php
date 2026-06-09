<?php
// escola/financeiro/folha_pagamento/buscar_dados_salariais.php
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
$funcionario_id = $_GET['funcionario_id'] ?? 0;

if ($funcionario_id) {
    $stmt = $conn->prepare("
        SELECT 
            salario_base,
            subsidio_transporte,
            subsidio_alimentacao,
            outros_vencimentos,
            desconto_inss,
            desconto_irrf,
            outros_descontos
        FROM folha_funcionarios
        WHERE funcionario_id = ? AND escola_id = ?
    ");
    $stmt->execute([$funcionario_id, $escola_id]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dados) {
        echo json_encode(['success' => true, 'dados' => $dados]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nenhum dado encontrado']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
}
?>