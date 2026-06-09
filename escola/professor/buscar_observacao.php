<?php
// escola/professor/buscar_observacao.php
require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$ano_letivo = date('Y');

$sql = "
    SELECT observacao_academica 
    FROM notas 
    WHERE estudante_id = :aluno_id 
    AND turma_id = :turma_id 
    AND bimestre = :bimestre 
    AND ano_letivo = :ano_letivo
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':aluno_id' => $aluno_id,
    ':turma_id' => $turma_id,
    ':bimestre' => $bimestre,
    ':ano_letivo' => $ano_letivo
]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'observacao' => $result ? $result['observacao_academica'] : ''
]);
?>