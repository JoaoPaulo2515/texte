<?php
// escola/pedagogico/ajax_buscar_solicitacao.php - Buscar detalhes da solicitação via AJAX

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.* 
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.status = 'ativo'
    AND f.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$funcionario_id = $funcionario['id'];

// Buscar solicitação
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

$sql = "
    SELECT 
        cnsol.*,
        e.nome as aluno_nome,
        e.matricula,
        e.foto as aluno_foto,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id) as total_votos,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'favoravel') as votos_favoraveis,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'contra') as votos_contra,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND funcionario_id = :funcionario_id) as ja_votou
    FROM conselho_nota_solicitacoes cnsol
    INNER JOIN estudantes e ON e.id = cnsol.estudante_id
    INNER JOIN turmas t ON t.id = cnsol.turma_id
    INNER JOIN disciplinas d ON d.id = cnsol.disciplina_id
    WHERE cnsol.id = :id
";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':id' => $id,
    ':funcionario_id' => $funcionario_id
]);
$solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

if ($solicitacao) {
    $total_votos = $solicitacao['total_votos'] ?? 0;
    $votos_favoraveis = $solicitacao['votos_favoraveis'] ?? 0;
    $votos_contra = $solicitacao['votos_contra'] ?? 0;
    $percentual_favor = $total_votos > 0 ? round(($votos_favoraveis / $total_votos) * 100, 0) : 0;
    
    // Determinar classe da turma para escala
    $classe_ano = $solicitacao['turma_ano'];
    $is_ensino_fundamental = ($classe_ano <= 6);
    $escala_max = $is_ensino_fundamental ? 10 : 20;
    $limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
    
    echo json_encode([
        'success' => true,
        'dados' => [
            'id' => $solicitacao['id'],
            'aluno_nome' => $solicitacao['aluno_nome'],
            'aluno_matricula' => $solicitacao['matricula'],
            'aluno_foto' => $solicitacao['aluno_foto'],
            'turma_nome' => $solicitacao['turma_nome'],
            'turma_ano' => $solicitacao['turma_ano'],
            'disciplina_nome' => $solicitacao['disciplina_nome'],
            'bimestre' => $solicitacao['bimestre'],
            'nota_atual' => $solicitacao['nota_atual'],
            'nota_sugerida' => $solicitacao['nota_sugerida'],
            'motivo' => $solicitacao['motivo'],
            'justificativa' => $solicitacao['justificativa'],
            'status' => $solicitacao['status'],
            'resultado_final' => $solicitacao['resultado_final'],
            'total_votos' => $total_votos,
            'votos_favoraveis' => $votos_favoraveis,
            'votos_contra' => $votos_contra,
            'percentual_favor' => $percentual_favor,
            'ja_votou' => $solicitacao['ja_votou'] > 0,
            'observacoes_finais' => $solicitacao['observacoes_finais'],
            'created_at' => date('d/m/Y H:i', strtotime($solicitacao['created_at'])),
            'escala_max' => $escala_max,
            'limite_aprovacao' => $limite_aprovacao
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitação não encontrada']);
}
?>