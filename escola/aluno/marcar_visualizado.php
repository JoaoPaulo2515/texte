<?php
// escola/aluno/documentos/marcar_visualizado.php

require_once __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$certificado_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$certificado_id) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

// Verificar se o certificado pertence ao aluno
$sql = "SELECT id FROM certificados WHERE id = :id AND aluno_id = :aluno_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $certificado_id, ':aluno_id' => $aluno_id]);

if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Certificado não encontrado']);
    exit;
}

// Registrar visualização
$sql = "INSERT INTO certificados_visualizacoes (certificado_id, aluno_id, data_visualizacao) 
        VALUES (:certificado_id, :aluno_id, NOW())
        ON DUPLICATE KEY UPDATE data_visualizacao = NOW()";
$stmt = $conn->prepare($sql);
$stmt->execute([':certificado_id' => $certificado_id, ':aluno_id' => $aluno_id]);

echo json_encode(['success' => true]);
?>