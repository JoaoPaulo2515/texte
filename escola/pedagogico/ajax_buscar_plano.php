<?php
// escola/pedagogico/ajax_buscar_plano.php

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID não informado']);
    exit;
}

$sql = "SELECT * FROM planos_ensino WHERE id = :id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id]);
$plano = $stmt->fetch(PDO::FETCH_ASSOC);

if ($plano) {
    echo json_encode(['success' => true, 'plano' => $plano]);
} else {
    echo json_encode(['success' => false, 'message' => 'Plano não encontrado']);
}