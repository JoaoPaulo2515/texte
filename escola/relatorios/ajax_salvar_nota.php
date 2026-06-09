<?php
// escola/relatorios/ajax_salvar_nota.php

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
$nota = isset($_POST['nota']) ? (float)$_POST['nota'] : 0;
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : 1;
$ano_letivo_id = isset($_POST['ano_letivo_id']) ? (int)$_POST['ano_letivo_id'] : 1;

if ($aluno_id <= 0 || $disciplina_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    // Verificar se já existe nota
    $sql_check = "SELECT id FROM notas WHERE aluno_id = :aluno_id AND disciplina_id = :disciplina_id AND trimestre = :trimestre AND ano_letivo_id = :ano_letivo_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([
        ':aluno_id' => $aluno_id,
        ':disciplina_id' => $disciplina_id,
        ':trimestre' => $trimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($existe) {
        // Atualizar
        $sql = "UPDATE notas SET nota = :nota, data_lancamento = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':nota' => $nota, ':id' => $existe['id']]);
    } else {
        // Inserir
        $sql = "INSERT INTO notas (aluno_id, disciplina_id, nota, trimestre, ano_letivo_id, data_lancamento) 
                VALUES (:aluno_id, :disciplina_id, :nota, :trimestre, :ano_letivo_id, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':aluno_id' => $aluno_id,
            ':disciplina_id' => $disciplina_id,
            ':nota' => $nota,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Nota salva com sucesso']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>