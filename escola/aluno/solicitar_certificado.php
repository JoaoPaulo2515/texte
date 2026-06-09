<?php
// escola/aluno/documentos/solicitar_certificado.php

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

$tipo = $_POST['tipo'] ?? '';
$titulo = $_POST['titulo'] ?? '';
$descricao = $_POST['descricao'] ?? '';
$ano_letivo = $_POST['ano_letivo'] ?? date('Y');

if (empty($tipo)) {
    $_SESSION['erro'] = 'Selecione o tipo de certificado';
    header('Location: certificados.php');
    exit;
}

// Gerar código único de solicitação
$codigo = 'SOL-' . strtoupper(uniqid());

$sql = "INSERT INTO solicitacoes_certificados (aluno_id, escola_id, tipo, titulo, descricao, ano_letivo, codigo_solicitacao, status, data_solicitacao) 
        VALUES (:aluno_id, :escola_id, :tipo, :titulo, :descricao, :ano_letivo, :codigo, 'pendente', NOW())";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':tipo' => $tipo,
    ':titulo' => $titulo,
    ':descricao' => $descricao,
    ':ano_letivo' => $ano_letivo,
    ':codigo' => $codigo
]);

$_SESSION['sucesso'] = 'Solicitação enviada com sucesso! Você receberá uma notificação quando o certificado estiver disponível.';
header('Location: certificados.php');
exit;
?>