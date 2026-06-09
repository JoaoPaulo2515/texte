<?php
// escola/professor/provas/monitorar_aluno.php - Monitorar Aluno em Prova

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$tentativa_id = isset($_GET['tentativa']) ? (int)$_GET['tentativa'] : 0;

// Buscar dados da tentativa
$db = Database::getInstance();
$conn = $db->getConnection();

$sql = "SELECT t.*, e.nome as aluno_nome, e.matricula, p.titulo, p.duracao_minutos
        FROM online_provas_tentativas t
        JOIN estudantes e ON e.id = t.aluno_id
        JOIN online_provas p ON p.id = t.prova_id
        WHERE t.id = :tentativa_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':tentativa_id' => $tentativa_id]);
$tentativa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tentativa) {
    die('Tentativa não encontrada');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Monitorando: <?php echo htmlspecialchars($tentativa['aluno_nome']); ?></title>
    <meta http-equiv="refresh" content="10">
</head>
<body>
    <h1>Monitorando: <?php echo htmlspecialchars($tentativa['aluno_nome']); ?></h1>
    <p>Prova: <?php echo htmlspecialchars($tentativa['titulo']); ?></p>
    <p>Tentativa: <?php echo $tentativa['tentativa_numero']; ?>ª</p>
    <p>Início: <?php echo date('d/m/Y H:i:s', strtotime($tentativa['data_inicio'])); ?></p>
    <p>Tempo decorrido: <?php echo floor((time() - strtotime($tentativa['data_inicio'])) / 60); ?> minutos</p>
</body>
</html>