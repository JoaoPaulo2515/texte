<?php
// escola/professor/ajax_registrar_chamada.php - Versão Simplificada

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Receber dados
$aluno_id = (int)$_POST['estudante_id'];
$status = $_POST['status'];
$turma_id = (int)$_POST['turma_id'];
$disciplina_id = (int)$_POST['disciplina_id'];
$data_aula = $_POST['data_aula'];
$escola_id = (int)$_POST['escola_id'];
$ano_letivo_id = (int)$_POST['ano_letivo_id'];
$professor_id = (int)$_POST['professor_id'];

// Validar
if ($aluno_id <= 0 || $turma_id <= 0 || $disciplina_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    // Tentar atualizar primeiro
    $sql_update = "UPDATE chamada SET 
                        status = :status,
                        updated_at = NOW()
                    WHERE turma_id = :turma_id 
                    AND disciplina_id = :disciplina_id 
                    AND estudante_id = :estudante_id 
                    AND data_aula = :data_aula
                    AND professor_id = :professor_id";
    
    $stmt = $conn->prepare($sql_update);
    $stmt->execute([
        ':status' => $status,
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':estudante_id' => $aluno_id,
        ':data_aula' => $data_aula,
        ':professor_id' => $professor_id
    ]);
    
    // Se não atualizou nenhum registro, inserir
    if ($stmt->rowCount() == 0) {
        $sql_insert = "INSERT INTO chamada (
                            professor_id, turma_id, disciplina_id, estudante_id,
                            data_aula, status, escola_id, ano_letivo_id, created_at
                        ) VALUES (
                            :professor_id, :turma_id, :disciplina_id, :estudante_id,
                            :data_aula, :status, :escola_id, :ano_letivo_id, NOW()
                        )";
        
        $stmt = $conn->prepare($sql_insert);
        $stmt->execute([
            ':professor_id' => $professor_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':estudante_id' => $aluno_id,
            ':data_aula' => $data_aula,
            ':status' => $status,
            ':escola_id' => $escola_id,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Status registrado com sucesso']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>