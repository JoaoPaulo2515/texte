<?php
// escola/professor/ajax_solicitacao_vale.php - Buscar status de solicitações

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];

$sql_func = "SELECT f.id FROM funcionarios f INNER JOIN professores p ON p.usuario_id = f.usuario_id WHERE p.id = :pid";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([':pid' => $professor_id]);
$func = $stmt_func->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $func['id'] ?? 0;

$sql = "SELECT COUNT(*) as pendentes FROM solicitacoes_vale WHERE funcionario_id = :fid AND status = 'pendente'";
$stmt = $conn->prepare($sql);
$stmt->execute([':fid' => $funcionario_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['pendentes' => $result['pendentes'] ?? 0]);
?>