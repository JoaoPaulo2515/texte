<?php
// escola/avaliacao/tipos/ajax_tipo_detalhes.php - Buscar detalhes do tipo via AJAX

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$sql = "SELECT * FROM tipos_avaliacao WHERE id = :id AND escola_id = :escola_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$tipo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tipo) {
    echo json_encode(['success' => false, 'message' => 'Tipo não encontrado']);
    exit;
}

$niveis_texto = [
    '1ciclo' => '1º Ciclo (1ª - 4ª Classe)',
    '2ciclo' => '2º Ciclo (5ª - 6ª Classe)',
    '3ciclo' => '3º Ciclo (7ª - 9ª Classe)',
    'medio' => 'Ensino Médio (10ª - 12ª/13ª Classe)'
];

$categorias_texto = [
    'prova' => 'Provas',
    'trabalho' => 'Trabalhos',
    'teste' => 'Testes',
    'exame' => 'Exames',
    'atividade' => 'Atividades'
];

$response = [
    'success' => true,
    'nome' => htmlspecialchars($tipo['nome']),
    'codigo' => htmlspecialchars($tipo['codigo']),
    'categoria' => $categorias_texto[$tipo['categoria']] ?? $tipo['categoria'],
    'nivel_ensino' => $niveis_texto[$tipo['nivel_ensino']] ?? $tipo['nivel_ensino'],
    'peso_padrao' => number_format($tipo['peso_padrao'], 1, ',', '.'),
    'escala_maxima' => $tipo['escala_maxima'],
    'ordem' => $tipo['ordem'],
    'status' => $tipo['status'],
    'descricao' => nl2br(htmlspecialchars($tipo['descricao'] ?? ''))
];

echo json_encode($response);
exit;
?>