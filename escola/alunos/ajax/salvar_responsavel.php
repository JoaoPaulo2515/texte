<?php
// escola/ajax/salvar_responsavel.php

require_once __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$nome = $_POST['nome'] ?? '';
$parentesco = $_POST['parentesco'] ?? '';
$bi = $_POST['bi'] ?? '';
$nif = $_POST['nif'] ?? '';
$telefone = $_POST['telefone'] ?? '';
$telefone2 = $_POST['telefone2'] ?? '';
$email = $_POST['email'] ?? '';
$endereco = $_POST['endereco'] ?? '';
$provincia = $_POST['provincia'] ?? '';
$municipio = $_POST['municipio'] ?? '';
$bairro = $_POST['bairro'] ?? '';
$profissao = $_POST['profissao'] ?? '';
$estado_civil = $_POST['estado_civil'] ?? '';
$observacoes = $_POST['observacoes'] ?? '';

if (empty($nome) || empty($telefone)) {
    echo json_encode(['success' => false, 'message' => 'Nome e telefone são obrigatórios']);
    exit;
}

try {
    $sql = "INSERT INTO responsaveis (escola_id, nome, parentesco, bi, nif, telefone, telefone2, email, 
                                       endereco, provincia, municipio, bairro, profissao, estado_civil, observacoes, status) 
            VALUES (:escola_id, :nome, :parentesco, :bi, :nif, :telefone, :telefone2, :email, 
                    :endereco, :provincia, :municipio, :bairro, :profissao, :estado_civil, :observacoes, 'ativo')";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':parentesco' => $parentesco,
        ':bi' => $bi,
        ':nif' => $nif,
        ':telefone' => $telefone,
        ':telefone2' => $telefone2,
        ':email' => $email,
        ':endereco' => $endereco,
        ':provincia' => $provincia,
        ':municipio' => $municipio,
        ':bairro' => $bairro,
        ':profissao' => $profissao,
        ':estado_civil' => $estado_civil,
        ':observacoes' => $observacoes
    ]);
    
    $id = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'id' => $id,
        'nome' => $nome,
        'parentesco' => $parentesco,
        'bi' => $bi,
        'telefone' => $telefone,
        'email' => $email,
        'endereco' => $endereco
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>