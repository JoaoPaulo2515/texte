<?php
// escola/professor/ajax/get_historico_aluno.php

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$estudante_id = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if ($estudante_id <= 0 || $disciplina_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

// Buscar dados do aluno
$sql_aluno = "SELECT id, nome, matricula, bi, data_nascimento, genero, telefone, email, foto FROM estudantes WHERE id = :estudante_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':estudante_id' => $estudante_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    echo json_encode(['success' => false, 'message' => 'Aluno não encontrado']);
    exit;
}

// Buscar informações da turma para regras de avaliação
$sql_turma = "SELECT ano FROM turmas WHERE id = :turma_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
$classe_ano = $turma['ano'] ?? 0;
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;

// Buscar notas do aluno por bimestre
$sql_notas = "
    SELECT 
        bimestre,
        mac,
        npt,
        exame_normal,
        exame_recurso,
        exame_especial,
        exame_oral,
        exame_escrito,
        media_final,
        status
    FROM notas
    WHERE estudante_id = :estudante_id 
    AND disciplina_id = :disciplina_id 
    AND ano_letivo_id = :ano_letivo_id
    ORDER BY bimestre
";
$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([
    ':estudante_id' => $estudante_id,
    ':disciplina_id' => $disciplina_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$notas_db = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas por bimestre
$notas_por_bimestre = [];
$soma_medias = 0;
$count_notas = 0;

foreach ($notas_db as $nota) {
    $bimestre = $nota['bimestre'];
    $media = $nota['media_final'] ?? 0;
    $status = $nota['status'] ?? 'pendente';
    
    // Converter status para português
    $status_texto = '';
    switch ($status) {
        case 'aprovado': $status_texto = 'Aprovado'; break;
        case 'recuperacao': $status_texto = 'Recuperação'; break;
        case 'reprovado': $status_texto = 'Reprovado'; break;
        default: $status_texto = 'Pendente';
    }
    
    $notas_por_bimestre[$bimestre] = [
        'mac' => $nota['mac'] ? number_format($nota['mac'], 1) : '-',
        'npt' => $nota['npt'] ? number_format($nota['npt'], 1) : '-',
        'exame_normal' => $nota['exame_normal'] ? number_format($nota['exame_normal'], 1) : '-',
        'exame_recurso' => $nota['exame_recurso'] ? number_format($nota['exame_recurso'], 1) : '-',
        'media_final' => $media ? number_format($media, 1) : '-',
        'situacao' => $status_texto
    ];
    
    if ($media > 0) {
        $soma_medias += $media;
        $count_notas++;
    }
}

// Calcular média anual
$media_anual = $count_notas > 0 ? round($soma_medias / $count_notas, 1) : 0;
$situacao_anual = 'Pendente';
if ($media_anual > 0) {
    if ($media_anual > $limite_aprovacao) {
        $situacao_anual = 'Aprovado';
    } elseif ($media_anual == $limite_aprovacao) {
        $situacao_anual = 'Recuperação';
    } else {
        $situacao_anual = 'Reprovado';
    }
}

// Formatar data de nascimento
$aluno['data_nascimento'] = $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : null;

echo json_encode([
    'success' => true,
    'aluno' => $aluno,
    'notas' => $notas_por_bimestre,
    'media_anual' => $media_anual,
    'situacao_anual' => $situacao_anual
]);
exit;
?>