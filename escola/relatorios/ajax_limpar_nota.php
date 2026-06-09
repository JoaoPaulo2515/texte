<?php
// escola/relatorios/ajax_limpar_nota.php

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
$disciplina_id = isset($_POST['disciplina_id']) ? (int)$_POST['disciplina_id'] : 0;
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : 1;
$ano_letivo_id = isset($_POST['ano_letivo_id']) ? (int)$_POST['ano_letivo_id'] : 1;
$nota_id = isset($_POST['nota_id']) ? (int)$_POST['nota_id'] : 0;

try {
    if ($nota_id > 0) {
        $sql = "DELETE FROM notas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $nota_id]);
    } else {
        $sql = "DELETE FROM notas WHERE aluno_id = :aluno_id AND disciplina_id = :disciplina_id AND trimestre = :trimestre AND ano_letivo_id = :ano_letivo_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':aluno_id' => $aluno_id,
            ':disciplina_id' => $disciplina_id,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Nota removida com sucesso']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>