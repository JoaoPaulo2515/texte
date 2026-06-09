<?php
// escola/pedagogico/ajax_buscar_notas_aluno.php
require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$escola_id = isset($_GET['escola_id']) ? (int)$_GET['escola_id'] : 0;

if (!$aluno_id || !$escola_id) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

// Buscar todas as notas do aluno
$sql = "
    SELECT 
        n.*,
        d.nome as disciplina_nome,
        t.nome as turma_nome,
        al.ano as ano_letivo
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN turmas t ON t.id = n.turma_id
    INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
    WHERE n.estudante_id = :aluno_id AND n.escola_id = :escola_id
    ORDER BY al.ano DESC, n.bimestre ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas únicas
$sql_disciplinas = "
    SELECT DISTINCT d.id, d.nome
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    WHERE n.estudante_id = :aluno_id AND n.escola_id = :escola_id
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos únicos
$sql_anos = "
    SELECT DISTINCT al.id, al.ano
    FROM notas n
    INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
    WHERE n.estudante_id = :aluno_id AND n.escola_id = :escola_id
    ORDER BY al.ano DESC
";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'notas' => $notas,
    'disciplinas' => $disciplinas,
    'anos_letivos' => $anos_letivos
]);