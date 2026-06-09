<?php
// escola/aluno/biblioteca/cancelar_reserva.php - AJAX para cancelar reserva

require_once __DIR__ . '/../../config/database.php';
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
$reserva_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$reserva_id) {
    echo json_encode(['success' => false, 'message' => 'ID da reserva não informado']);
    exit;
}

// Buscar dados da reserva
$sql = "SELECT r.*, a.titulo 
        FROM reservas r
        JOIN acervo_livros a ON a.id = r.acervo_id
        WHERE r.id = :id AND r.aluno_id = :aluno_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $reserva_id, ':aluno_id' => $aluno_id]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    echo json_encode(['success' => false, 'message' => 'Reserva não encontrada']);
    exit;
}

// Verificar se pode cancelar
if ($reserva['status'] != 'ativa') {
    echo json_encode(['success' => false, 'message' => 'Esta reserva não pode ser cancelada']);
    exit;
}

// Cancelar reserva
$sql_update = "UPDATE reservas SET status = 'cancelada', updated_at = NOW() WHERE id = :id";
$stmt_update = $conn->prepare($sql_update);
$stmt_update->execute([':id' => $reserva_id]);

echo json_encode(['success' => true, 'message' => 'Reserva cancelada com sucesso']);
?>