<?php
// escola/professor/buscar_dados_aluno.php
require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if ($aluno_id == 0) {
    echo json_encode(['success' => false, 'message' => 'ID do aluno não informado']);
    exit;
}

// Buscar dados do aluno
$sql = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.data_nascimento, 
        e.email,
        e.telefone,
        e.foto,
        e.bi,
        e.genero,
        e.endereco,
        e.pai_nome,
        e.mae_nome,
        e.telefone,
        e.encarregado_nome
    FROM estudantes e
    WHERE e.id = :aluno_id
";
$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id]);
$aluno = $stmt->fetch(PDO::FETCH_ASSOC);

if ($aluno) {
    // Calcular idade
    $idade = '';
    if (!empty($aluno['data_nascimento'])) {
        $data_nasc = new DateTime($aluno['data_nascimento']);
        $hoje = new DateTime();
        $idade = $data_nasc->diff($hoje)->y;
    }
    
    // URL da foto
    $foto_url = !empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto']) 
                ? '../../uploads/alunos/fotos/' . $aluno['foto'] 
                : '../../assets/images/avatar-padrao.png';
    
    echo json_encode([
        'success' => true,
        'aluno' => [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'data_nascimento' => date('d/m/Y', strtotime($aluno['data_nascimento'])),
            'idade' => $idade,
            'email' => $aluno['email'],
            'telefone' => $aluno['telefone'],
            'foto_url' => $foto_url,
            'bi' => $aluno['bi'],
            'genero' => $aluno['genero'],
            'endereco' => $aluno['endereco'],
            'pai_nome' => $aluno['pai_nome'],
            'mae_nome' => $aluno['mae_nome'],
            'telefone' => $aluno['telefone'],
            'encarregado_nome' => $aluno['encarregado_nome']
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Aluno não encontrado']);
}
?>