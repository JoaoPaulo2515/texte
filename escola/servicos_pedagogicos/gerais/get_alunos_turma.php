<?php
// escola/servicos_pedagogicos/gerais/get_alunos_turma.php

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if ($turma_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID da turma inválido']);
    exit;
}

$stmt = $conn->prepare("
    SELECT e.id, e.nome, e.matricula, e.data_nascimento, e.genero, e.foto,
           m.numero_matricula, m.data_matricula
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
");
$stmt->execute([':turma_id' => $turma_id]);
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'alunos' => $alunos]);
?>