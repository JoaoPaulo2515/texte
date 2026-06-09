<?php
// escola/avaliacao/tipos/ajax_gerar_codigo.php - Gerar código automático

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$categoria = $_POST['categoria'] ?? '';

$prefixos = [
    'prova' => 'PROV',
    'trabalho' => 'TRAB',
    'teste' => 'TEST',
    'exame' => 'EXAM',
    'atividade' => 'ATIV'
];

$prefixo = $prefixos[$categoria] ?? 'AVAL';

// Buscar último código
$sql = "SELECT codigo FROM tipos_avaliacao 
        WHERE escola_id = :escola_id 
        AND codigo LIKE :prefixo 
        ORDER BY id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':escola_id' => $escola_id,
    ':prefixo' => $prefixo . '%'
]);
$ultimo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($ultimo && preg_match('/(\d+)$/', $ultimo['codigo'], $matches)) {
    $numero = (int)$matches[1] + 1;
} else {
    $numero = 1;
}

$codigo = $prefixo . str_pad($numero, 3, '0', STR_PAD_LEFT);

echo json_encode(['success' => true, 'codigo' => $codigo]);
exit;
?>