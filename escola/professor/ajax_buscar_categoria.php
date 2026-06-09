<?php
// escola/professor/ajax_buscar_categoria.php - Buscar Categoria para Edição

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$escola_id = $professor['escola_id'];
$categoria_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$categoria_id) {
    echo json_encode(['success' => false, 'message' => 'ID não informado']);
    exit;
}

$sql = "SELECT * FROM online_provas_categorias WHERE id = :id AND escola_id = :escola_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $categoria_id, ':escola_id' => $escola_id]);
$categoria = $stmt->fetch(PDO::FETCH_ASSOC);

if ($categoria) {
    echo json_encode([
        'success' => true,
        'id' => $categoria['id'],
        'nome' => $categoria['nome'],
        'descricao' => $categoria['descricao'],
        'cor' => $categoria['cor'],
        'icone' => $categoria['icone']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Categoria não encontrada']);
}
?>