<?php
// escola/professor/ajax_divida_detalhes.php - Buscar detalhes da dívida via AJAX

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$sql = "SELECT * FROM dividas WHERE id = :id AND funcionario_id = :professor_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
$divida = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$divida) {
    echo json_encode(['success' => false, 'message' => 'Dívida não encontrada']);
    exit;
}

echo json_encode([
    'success' => true,
    'descricao' => $divida['descricao'],
    'referencia' => $divida['referencia'],
    'tipo' => $divida['tipo'],
    'valor_original' => $divida['valor_original'],
    'valor_pago' => $divida['valor_pago'] ?? 0,
    'valor_restante' => ($divida['valor_original'] - ($divida['valor_pago'] ?? 0)),
    'juros' => $divida['juros'] ?? 0,
    'multas' => $divida['multas'] ?? 0,
    'desconto' => $divida['desconto'] ?? 0,
    'data_vencimento' => $divida['data_vencimento'],
    'status' => $divida['status'],
    'created_at' => $divida['created_at'],
    'desconto_folha' => $divida['desconto_folha'] ?? 0
]);
exit;
?>