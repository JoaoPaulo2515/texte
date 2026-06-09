<?php
// escola/aluno/biblioteca/renovar_emprestimo.php - AJAX para renovar empréstimo

require_once __DIR__ . '/../../../config/database.php';
session_start();

header('Content-Type: application/json');

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$emprestimo_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$emprestimo_id) {
    echo json_encode(['success' => false, 'message' => 'ID do empréstimo não informado']);
    exit;
}

// Buscar dados do empréstimo
$sql = "SELECT e.*, a.titulo 
        FROM emprestimos e
        JOIN acervo a ON a.id = e.acervo_id
        WHERE e.id = :id AND e.aluno_id = :aluno_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $emprestimo_id, ':aluno_id' => $aluno_id]);
$emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emprestimo) {
    echo json_encode(['success' => false, 'message' => 'Empréstimo não encontrado']);
    exit;
}

// Verificar se pode renovar
if ($emprestimo['status'] != 'ativo') {
    echo json_encode(['success' => false, 'message' => 'Este empréstimo não está ativo']);
    exit;
}

if ($emprestimo['renovacoes'] >= 3) {
    echo json_encode(['success' => false, 'message' => 'Número máximo de renovações atingido (3)']);
    exit;
}

if (strtotime($emprestimo['data_devolucao_prevista']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Empréstimo atrasado. Não é possível renovar. Devolva o material primeiro.']);
    exit;
}

// Renovar (adicionar 7 dias)
$nova_data = date('Y-m-d', strtotime($emprestimo['data_devolucao_prevista'] . ' + 7 days'));
$renovacoes = $emprestimo['renovacoes'] + 1;

$sql_update = "UPDATE emprestimos 
               SET data_devolucao_prevista = :nova_data, 
                   renovacoes = :renovacoes,
                   updated_at = NOW()
               WHERE id = :id";
$stmt_update = $conn->prepare($sql_update);
$stmt_update->execute([
    ':nova_data' => $nova_data,
    ':renovacoes' => $renovacoes,
    ':id' => $emprestimo_id
]);

echo json_encode([
    'success' => true,
    'nova_data' => date('d/m/Y', strtotime($nova_data)),
    'message' => 'Empréstimo renovado com sucesso'
]);
?>