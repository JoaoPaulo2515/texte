<?php
// escola/relatorios/ajax_professor_detalhes.php

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$professor_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($professor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    // Buscar dados do professor
    $sql = "SELECT p.*, 
            GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas,
            GROUP_CONCAT(DISTINCT CONCAT(t.ano, 'ª ', t.nome) SEPARATOR ', ') as turmas
            FROM funcionarios p
            LEFT JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
            LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
            LEFT JOIN turmas t ON t.id = pdt.turma_id
            WHERE p.id = :id AND p.escola_id = :escola_id
            GROUP BY p.id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $professor_id, ':escola_id' => $escola_id]);
    $professor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($professor) {
        echo json_encode(['success' => true, 'professor' => $professor]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Professor não encontrado']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>