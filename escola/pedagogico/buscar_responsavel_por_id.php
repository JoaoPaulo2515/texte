<?php
// escola/pedagogico/buscar_responsavel_por_id.php
require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$sql = "SELECT * FROM responsaveis WHERE id = :id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id]);
$responsavel = $stmt->fetch(PDO::FETCH_ASSOC);

if ($responsavel) {
    echo json_encode(['success' => true, 'responsavel' => $responsavel]);
} else {
    echo json_encode(['success' => false, 'message' => 'Responsável não encontrado']);
}
?>