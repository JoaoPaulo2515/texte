<?php
// escola/professor/ajax_prova_detalhes.php - Buscar detalhes da prova via AJAX

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$sql = "
    SELECT 
        p.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala as turma_sala,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        f.nome as docente_nome,
        ta.nome as tipo_avaliacao_nome,
        ta.cor as tipo_cor,
        ta.icone as tipo_icone
    FROM provas p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    LEFT JOIN funcionarios f ON f.id = p.docente_responsavel
    LEFT JOIN tipos_avaliacao ta ON ta.id = p.tipo_prova
    WHERE p.id = :id
    AND p.escola_id = :escola_id
";

$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$prova = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    echo json_encode(['success' => false, 'message' => 'Prova não encontrada']);
    exit;
}

$response = [
    'success' => true,
    'id' => $prova['id'],
    'titulo' => htmlspecialchars($prova['titulo']),
    'disciplina_nome' => htmlspecialchars($prova['disciplina_nome']),
    'disciplina_codigo' => htmlspecialchars($prova['disciplina_codigo'] ?? ''),
    'turma_nome' => htmlspecialchars($prova['turma_nome']),
    'turma_ano' => $prova['turma_ano'],
    'data_prova' => date('d/m/Y', strtotime($prova['data_prova'])),
    'hora_inicio' => date('H:i', strtotime($prova['hora_inicio'])),
    'hora_fim' => date('H:i', strtotime($prova['hora_fim'])),
    'tipo_prova' => $prova['tipo_prova'],
    'tipo_avaliacao_nome' => htmlspecialchars($prova['tipo_avaliacao_nome'] ?? ''),
    'periodo' => htmlspecialchars($prova['periodo']),
    'valor_total' => number_format($prova['valor_total'], 2, ',', '.'),
    'sala' => htmlspecialchars($prova['sala'] ?: 'Não definida'),
    'descricao' => nl2br(htmlspecialchars($prova['descricao'] ?? '')),
    'instrucoes' => nl2br(htmlspecialchars($prova['instrucoes'] ?? '')),
    'material_permitido' => htmlspecialchars($prova['material_permitido'] ?: 'Nenhum'),
    'status' => $prova['status'],
    'docente_nome' => htmlspecialchars($prova['docente_nome'] ?? 'Não atribuído')
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
exit;
?>