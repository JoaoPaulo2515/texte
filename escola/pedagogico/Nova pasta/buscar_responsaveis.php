<?php
// escola/ajax/buscar_responsaveis.php

require_once __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';

if (empty($termo)) {
    echo json_encode(['success' => false, 'message' => 'Termo de busca vazio']);
    exit;
}

try {
    $sql = "SELECT id, nome, parentesco, bi, telefone, email, endereco, profissao 
            FROM responsaveis 
            WHERE escola_id = :escola_id 
            AND status = 'ativo'
            AND (nome LIKE :termo 
                 OR bi LIKE :termobi 
                 OR telefone LIKE :termotel 
                 OR email LIKE :termoem)
            ORDER BY nome ASC
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':termo' => "%$termo%",
        ':termobi' => "%$termo%",
        ':termotel' => "%$termo%",
        ':termoem' => "%$termo%"
    ]);
    $responsaveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $responsaveis]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>