<?php
// escola/financeiro/folha_pagamento/holerite.php - Visualizar Holerite Individual
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$funcionario_id = $_GET['id'] ?? 0;
$processamento_id = $_GET['processamento_id'] ?? 0;

// Buscar holerite
$stmt = $conn->prepare("
    SELECT h.*, f.nome, f.numero_processo, f.cargo, f.bi
    FROM folha_holerites h
    JOIN funcionarios f ON h.funcionario_id = f.id
    WHERE h.funcionario_id = ? AND h.processamento_id = ? AND h.escola_id = ?
");
$stmt->execute([$funcionario_id, $processamento_id, $escola_id]);
$holerite = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$holerite) {
    die("Holerite não encontrado.");
}

// Redirecionar para o PDF
header("Location: ../../../" . $holerite['caminho_pdf']);
exit;
?>