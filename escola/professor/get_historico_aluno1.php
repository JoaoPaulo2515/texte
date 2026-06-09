<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

$professor = checkProfessorAuth();
$conn = getConnection();

$estudante_id = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if (!$estudante_id || !$disciplina_id) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

// Buscar dados do aluno
$sql_aluno = "SELECT id, nome, matricula, bi, data_nascimento, telefone, foto, genero, email FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $estudante_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar nome da disciplina
$sql_disc = "SELECT nome FROM disciplinas WHERE id = :disciplina_id";
$stmt_disc = $conn->prepare($sql_disc);
$stmt_disc->execute([':disciplina_id' => $disciplina_id]);
$disciplina = $stmt_disc->fetch(PDO::FETCH_ASSOC);

// Buscar notas do aluno nos 3 bimestres
$notas = [];
for ($bimestre = 1; $bimestre <= 3; $bimestre++) {
    $sql_notas = "SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final, status 
                  FROM notas 
                  WHERE estudante_id = :estudante_id 
                  AND disciplina_id = :disciplina_id 
                  AND bimestre = :bimestre 
                  AND ano_letivo_id = :ano_letivo_id";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':estudante_id' => $estudante_id,
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $nota = $stmt_notas->fetch(PDO::FETCH_ASSOC);
    
    $notas[$bimestre] = [
        'mac' => $nota['mac'] ?? null,
        'npt' => $nota['npt'] ?? null,
        'exame_normal' => $nota['exame_normal'] ?? null,
        'exame_recurso' => $nota['exame_recurso'] ?? null,
        'exame_especial' => $nota['exame_especial'] ?? null,
        'exame_oral' => $nota['exame_oral'] ?? null,
        'exame_escrito' => $nota['exame_escrito'] ?? null,
        'media_final' => $nota['media_final'] ?? null,
        'situacao' => $nota['status'] ?? 'Pendente'
    ];
}

echo json_encode([
    'success' => true,
    'aluno' => $aluno,
    'disciplina_nome' => $disciplina['nome'] ?? 'Disciplina',
    'notas' => $notas
]);