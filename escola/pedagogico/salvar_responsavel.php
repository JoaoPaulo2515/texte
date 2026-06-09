<?php
// escola/pedagogico/salvar_responsavel.php
require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

$escola_id = $_SESSION['escola_id'] ?? 0;

// Verificar se é edição ou novo
if (isset($_POST['editar_responsavel']) && isset($_POST['responsavel_id'])) {
    // Modo edição
    $id = (int)$_POST['responsavel_id'];
    $nome = $_POST['nome'] ?? '';
    $parentesco = $_POST['parentesco'] ?? '';
    $bi = $_POST['bi'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $telefone2 = $_POST['telefone2'] ?? '';
    $email = $_POST['email'] ?? '';
    $profissao = $_POST['profissao'] ?? '';
    $estado_civil = $_POST['estado_civil'] ?? '';
    $provincia = $_POST['provincia'] ?? '';
    $municipio = $_POST['municipio'] ?? '';
    $bairro = $_POST['bairro'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $observacoes = $_POST['observacoes'] ?? '';
    
    if (empty($nome) || empty($telefone)) {
        echo json_encode(['success' => false, 'message' => 'Nome e telefone são obrigatórios']);
        exit;
    }
    
    try {
        $sql = "UPDATE responsaveis SET 
                    nome = :nome, 
                    parentesco = :parentesco, 
                    bi = :bi, 
                    telefone = :telefone, 
                    telefone2 = :telefone2, 
                    email = :email, 
                    profissao = :profissao, 
                    estado_civil = :estado_civil, 
                    provincia = :provincia, 
                    municipio = :municipio, 
                    bairro = :bairro, 
                    endereco = :endereco, 
                    observacoes = :observacoes,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':parentesco' => $parentesco,
            ':bi' => $bi,
            ':telefone' => $telefone,
            ':telefone2' => $telefone2,
            ':email' => $email,
            ':profissao' => $profissao,
            ':estado_civil' => $estado_civil,
            ':provincia' => $provincia,
            ':municipio' => $municipio,
            ':bairro' => $bairro,
            ':endereco' => $endereco,
            ':observacoes' => $observacoes
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Responsável atualizado com sucesso!', 'id' => $id]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
    exit;
}

// Modo criação (código existente)
$nome = $_POST['nome'] ?? '';
$parentesco = $_POST['parentesco'] ?? '';
$bi = $_POST['bi'] ?? '';
$telefone = $_POST['telefone'] ?? '';
$telefone2 = $_POST['telefone2'] ?? '';
$email = $_POST['email'] ?? '';
$profissao = $_POST['profissao'] ?? '';
$estado_civil = $_POST['estado_civil'] ?? '';
$provincia = $_POST['provincia'] ?? '';
$municipio = $_POST['municipio'] ?? '';
$bairro = $_POST['bairro'] ?? '';
$endereco = $_POST['endereco'] ?? '';
$observacoes = $_POST['observacoes'] ?? '';

if (empty($nome) || empty($telefone)) {
    echo json_encode(['success' => false, 'message' => 'Nome e telefone são obrigatórios']);
    exit;
}

try {
    $sql = "INSERT INTO responsaveis (escola_id, nome, parentesco, bi, telefone, telefone2, email, profissao, estado_civil, provincia, municipio, bairro, endereco, observacoes, created_at) 
            VALUES (:escola_id, :nome, :parentesco, :bi, :telefone, :telefone2, :email, :profissao, :estado_civil, :provincia, :municipio, :bairro, :endereco, :observacoes, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':parentesco' => $parentesco,
        ':bi' => $bi,
        ':telefone' => $telefone,
        ':telefone2' => $telefone2,
        ':email' => $email,
        ':profissao' => $profissao,
        ':estado_civil' => $estado_civil,
        ':provincia' => $provincia,
        ':municipio' => $municipio,
        ':bairro' => $bairro,
        ':endereco' => $endereco,
        ':observacoes' => $observacoes
    ]);
    
    $id = $conn->lastInsertId();
    echo json_encode(['success' => true, 'message' => 'Responsável cadastrado com sucesso!', 'id' => $id, 'nome' => $nome, 'parentesco' => $parentesco, 'bi' => $bi, 'telefone' => $telefone, 'email' => $email, 'endereco' => $endereco]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao cadastrar: ' . $e->getMessage()]);
}
?>