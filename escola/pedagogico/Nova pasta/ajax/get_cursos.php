<?php
// escola/ajax/get_cursos.php

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
$nivel_id = isset($_GET['nivel_id']) ? (int)$_GET['nivel_id'] : 0;

if (!$nivel_id) {
    echo json_encode(['success' => false, 'cursos' => []]);
    exit;
}

try {
    $sql = "SELECT id, nome, codigo, duracao_anos 
            FROM cursos 
            WHERE nivel_id = :nivel_id 
            AND status = 'ativo' 
            AND escola_id = :escola_id 
            ORDER BY nome ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':nivel_id' => $nivel_id,
        ':escola_id' => $escola_id
    ]);
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'cursos' => $cursos]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>