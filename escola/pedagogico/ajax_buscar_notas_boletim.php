<?php
// escola/pedagogico/ajax_buscar_notas_boletim.php

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$escola_id = $funcionario['escola_id'];

$aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
$turma_id = isset($_POST['turma_id']) ? (int)$_POST['turma_id'] : 0;
$ano_letivo_id = isset($_POST['ano_letivo_id']) ? (int)$_POST['ano_letivo_id'] : 0;

if (!$aluno_id || !$turma_id || !$ano_letivo_id) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
    exit;
}

// Buscar classe da turma para determinar escala
$sql_classe = "SELECT ano FROM turmas WHERE id = :turma_id";
$stmt_classe = $conn->prepare($sql_classe);
$stmt_classe->execute([':turma_id' => $turma_id]);
$turma = $stmt_classe->fetch(PDO::FETCH_ASSOC);
$classe_ano = $turma['ano'] ?? 0;
$limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
$escala_max = ($classe_ano <= 6) ? 10 : 20;
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);

// Buscar ano letivo
$sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute([':id' => $ano_letivo_id]);
$ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_ano = $ano_let['ano'] ?? '';

// ============================================
// VERIFICAR PAGAMENTOS DO BOLETIM NA TABELA pagamentos
// ============================================
$sql_conta_pagamentos = "
    SELECT COUNT(*) as total_pagamentos
    FROM pagamentos 
    WHERE (tipo_pagamento LIKE '%boletim%' OR referente LIKE '%boletim%' OR referente LIKE '%BOLETIM%')
    AND status = 'pago'
    AND data_pagamento IS NOT NULL
";
$stmt_conta = $conn->prepare($sql_conta_pagamentos);
$stmt_conta->execute();
$total_pagamentos = $stmt_conta->fetch(PDO::FETCH_ASSOC)['total_pagamentos'] ?? 0;

// Definir bimestres liberados com base no número de pagamentos
$bimestres_liberados = [];
if ($total_pagamentos >= 2) {
    $bimestres_liberados = [1, 2, 3, 4];
} elseif ($total_pagamentos >= 1) {
    $bimestres_liberados = [1];
}

// Buscar disciplinas e todas as notas (MAC, NPT, Exames)
$sql_notas = "
    SELECT 
        d.id,
        d.nome as disciplina_nome,
        d.codigo,
        CASE WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' OR d.nome LIKE '%portugues%' OR d.nome LIKE '%ingles%' THEN 1 ELSE 0 END as is_lingua,
        -- Bimestre 1
        n1.mac as mac_1,
        n1.npt as npt_1,
        n1.exame_normal as exame_normal_1,
        n1.exame_recurso as exame_recurso_1,
        n1.exame_especial as exame_especial_1,
        n1.exame_oral as exame_oral_1,
        n1.exame_escrito as exame_escrito_1,
        n1.media_final as media_1,
        n1.status as status_1,
        -- Bimestre 2
        n2.mac as mac_2,
        n2.npt as npt_2,
        n2.exame_normal as exame_normal_2,
        n2.exame_recurso as exame_recurso_2,
        n2.exame_especial as exame_especial_2,
        n2.exame_oral as exame_oral_2,
        n2.exame_escrito as exame_escrito_2,
        n2.media_final as media_2,
        n2.status as status_2,
        -- Bimestre 3
        n3.mac as mac_3,
        n3.npt as npt_3,
        n3.exame_normal as exame_normal_3,
        n3.exame_recurso as exame_recurso_3,
        n3.exame_especial as exame_especial_3,
        n3.exame_oral as exame_oral_3,
        n3.exame_escrito as exame_escrito_3,
        n3.media_final as media_3,
        n3.status as status_3,
        -- Bimestre 4
        n4.mac as mac_4,
        n4.npt as npt_4,
        n4.exame_normal as exame_normal_4,
        n4.exame_recurso as exame_recurso_4,
        n4.exame_especial as exame_especial_4,
        n4.exame_oral as exame_oral_4,
        n4.exame_escrito as exame_escrito_4,
        n4.media_final as media_4,
        n4.status as status_4,
        -- Média final anual
        ROUND((COALESCE(n1.media_final, 0) + COALESCE(n2.media_final, 0) + COALESCE(n3.media_final, 0) + COALESCE(n4.media_final, 0)) / 4, 1) as media_anual
    FROM disciplina_turma dt
    INNER JOIN disciplinas d ON d.id = dt.disciplina_id
    LEFT JOIN notas n1 ON n1.disciplina_id = d.id AND n1.estudante_id = :aluno_id AND n1.bimestre = 1 AND n1.ano_letivo_id = :ano_letivo_id
    LEFT JOIN notas n2 ON n2.disciplina_id = d.id AND n2.estudante_id = :aluno_id1 AND n2.bimestre = 2 AND n2.ano_letivo_id = :ano_letivo_id1
    LEFT JOIN notas n3 ON n3.disciplina_id = d.id AND n3.estudante_id = :aluno_id2 AND n3.bimestre = 3 AND n3.ano_letivo_id = :ano_letivo_id2
    LEFT JOIN notas n4 ON n4.disciplina_id = d.id AND n4.estudante_id = :aluno_id3 AND n4.bimestre = 4 AND n4.ano_letivo_id = :ano_letivo_id3
    WHERE dt.turma_id = :turma_id
    ORDER BY d.nome ASC
";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([
    ':aluno_id' => $aluno_id,
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':aluno_id3' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':ano_letivo_id1' => $ano_letivo_id,
    ':ano_letivo_id2' => $ano_letivo_id,
    ':ano_letivo_id3' => $ano_letivo_id,
    ':turma_id' => $turma_id
]);
$disciplinas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'disciplinas' => $disciplinas,
    'bimestres_liberados' => $bimestres_liberados,
    'total_pagamentos' => $total_pagamentos,
    'limite_aprovacao' => $limite_aprovacao,
    'escala_max' => $escala_max,
    'ano_letivo' => $ano_letivo_ano,
    'is_classe_exame' => $is_classe_exame
]);
exit;