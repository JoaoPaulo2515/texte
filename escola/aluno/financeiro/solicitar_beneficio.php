<?php
// escola/aluno/financeiro/solicitar_beneficio.php

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

$beneficio_id = $_POST['beneficio_id'] ?? 0;
$motivo = $_POST['motivo'] ?? '';

if (!$beneficio_id) {
    $_SESSION['erro'] = 'Benefício não identificado';
    header('Location: descontos.php');
    exit;
}

$sql = "INSERT INTO solicitacoes_beneficios (beneficio_id, aluno_id, escola_id, motivo, status, data_solicitacao) 
        VALUES (:beneficio_id, :aluno_id, :escola_id, :motivo, 'pendente', NOW())";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':beneficio_id' => $beneficio_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':motivo' => $motivo
]);

$_SESSION['sucesso'] = 'Solicitação enviada com sucesso! Aguarde a análise da secretaria.';
header('Location: descontos.php');
exit;
?>