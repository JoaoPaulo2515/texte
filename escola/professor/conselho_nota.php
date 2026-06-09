<?php
// escola/professor/conselho_nota.php - Conselho de Nota

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// INICIALIZAR VARIÁVEIS
// ============================================
$success = '';
$error = '';

// ============================================
// BUSCAR ID DO FUNCIONARIO (professor) NA TABELA FUNCIONARIOS
// ============================================
$sql_func = "SELECT f.id 
             FROM funcionarios f 
             INNER JOIN funcionarios p ON p.usuario_id = f.usuario_id 
             WHERE p.id = :professor_id AND f.escola_id = :escola_id 
             LIMIT 1";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
]);
$funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario ? $funcionario['id'] : 0;

if ($funcionario_id == 0) {
    $sql_func2 = "SELECT id FROM funcionarios WHERE escola_id = :escola_id LIMIT 1";
    $stmt_func2 = $conn->prepare($sql_func2);
    $stmt_func2->execute([':escola_id' => $escola_id]);
    $funcionario = $stmt_func2->fetch(PDO::FETCH_ASSOC);
    $funcionario_id = $funcionario ? $funcionario['id'] : 0;
}

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_permicao = "
    SELECT cnp.* 
    FROM conselho_nota_permissoes cnp
    WHERE cnp.funcionario_id = :funcionario_id 
    AND cnp.ativo = 1
    AND cnp.ano_letivo_id = (SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1)
";
$stmt_perm = $conn->prepare($sql_permicao);
$stmt_perm->execute([':funcionario_id' => $funcionario_id]);
$permicao = $stmt_perm->fetch(PDO::FETCH_ASSOC);

if (!$permicao && $funcionario_id == 0) {
    die("
    <div style='text-align: center; padding: 50px;'>
        <i class='fas fa-lock' style='font-size: 60px; color: #dc3545;'></i>
        <h2>Acesso Negado</h2>
        <p>Você não tem permissão para acessar o Conselho de Nota.</p>
        <p>Entre em contato com o coordenador pedagógico.</p>
        <a href='dashboard.php' class='btn btn-primary'>Voltar ao Dashboard</a>
    </div>
    ");
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->query($sql_ano);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// FUNÇÃO PARA CALCULAR SITUAÇÃO DO ALUNO BASEADO NOS CRITÉRIOS
// ============================================
function calcularSituacaoAluno($conn, $aluno_id, $serie, $notas_por_disciplina, $escala_max, $escala_aprovacao) {
    
    $sql_criterio = "
        SELECT * FROM criterio_avaliacao 
        WHERE (ano_inicio <= :serie AND ano_fim >= :serie)
        ORDER BY 
            CASE 
                WHEN tipo_classe = 'exame' AND :serie IN (6,9,12) THEN 1
                WHEN tipo_classe = 'secundario' AND :serie >= 7 THEN 2
                WHEN tipo_classe = 'fundamental' AND :serie <= 6 THEN 3
                ELSE 4
            END
        LIMIT 1
    ";
    $stmt_criterio = $conn->prepare($sql_criterio);
    $stmt_criterio->execute([':serie' => $serie]);
    $criterio = $stmt_criterio->fetch(PDO::FETCH_ASSOC);
    
    $ehClasseExame = ($serie == 6 || $serie == 9 || $serie == 12);
    $media_aprovacao = $criterio ? $criterio['media_aprovacao'] : $escala_aprovacao;
    $permite_negativas = $criterio ? $criterio['permite_negativas'] : 1;
    $limite_negativas = $criterio ? $criterio['limite_negativas'] : 2;
    $proibido_matematica_portugues = $criterio ? $criterio['proibido_matematica_portugues'] : 1;
    
    $total_negativas = 0;
    $soma_medias = 0;
    $count_disciplinas = 0;
    $tem_negativa_matematica = false;
    $tem_negativa_portugues = false;
    
    foreach ($notas_por_disciplina as $disciplina_id => $disciplina_notas) {
        $media_final = 0;
        if (isset($disciplina_notas[3]['media_final']) && $disciplina_notas[3]['media_final'] > 0) {
            $media_final = floatval($disciplina_notas[3]['media_final']);
        } elseif (isset($disciplina_notas[3]['media_parcial']) && $disciplina_notas[3]['media_parcial'] > 0) {
            $media_final = floatval($disciplina_notas[3]['media_parcial']);
        } else {
            $melhor_media = 0;
            for ($b = 1; $b <= 3; $b++) {
                if (isset($disciplina_notas[$b]['media_final']) && $disciplina_notas[$b]['media_final'] > $melhor_media) {
                    $melhor_media = floatval($disciplina_notas[$b]['media_final']);
                }
                if (isset($disciplina_notas[$b]['media_parcial']) && $disciplina_notas[$b]['media_parcial'] > $melhor_media) {
                    $melhor_media = floatval($disciplina_notas[$b]['media_parcial']);
                }
            }
            $media_final = $melhor_media;
        }
        
        if ($media_final > 0) {
            $soma_medias += $media_final;
            $count_disciplinas++;
            
            if ($media_final < $media_aprovacao) {
                $total_negativas++;
                
                $sql_disc = "SELECT nome FROM disciplinas WHERE id = :id";
                $stmt_disc = $conn->prepare($sql_disc);
                $stmt_disc->execute([':id' => $disciplina_id]);
                $disc_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC);
                
                if ($disc_nome) {
                    $nome_lower = strtolower($disc_nome['nome']);
                    if (strpos($nome_lower, 'matem') !== false) $tem_negativa_matematica = true;
                    if (strpos($nome_lower, 'portug') !== false || strpos($nome_lower, 'língua') !== false) $tem_negativa_portugues = true;
                }
            }
        }
    }
    
    $media_geral = $count_disciplinas > 0 ? round($soma_medias / $count_disciplinas, 1) : 0;
    $situacao = '';
    $mensagem_situacao = '';
    $pode_recurso = false;
    
    if ($ehClasseExame) {
        if ($total_negativas == 0) {
            $situacao = 'aprovado';
            $mensagem_situacao = "✅ APROVADO - Sem disciplinas negativas";
        } else {
            $situacao = 'reprovado';
            $mensagem_situacao = "❌ REPROVADO - Classe de exame não pode ter disciplinas negativas. Total: $total_negativas negativa(s)";
            $pode_recurso = ($criterio && $criterio['permite_recurso']);
        }
    } else {
        if ($total_negativas == 0) {
            $situacao = 'aprovado';
            $mensagem_situacao = "✅ APROVADO - Todas as disciplinas com notas positivas";
        } elseif ($permite_negativas && $total_negativas <= $limite_negativas) {
            if ($proibido_matematica_portugues && $tem_negativa_matematica && $tem_negativa_portugues) {
                $situacao = 'reprovado';
                $mensagem_situacao = "❌ REPROVADO - Matemática e Português negativas simultaneamente";
                $pode_recurso = true;
            } else {
                $situacao = 'aprovado_com_recurso';
                $mensagem_situacao = "🔄 APROVADO COM RECURSO - $total_negativas disciplina(s) negativa(s)";
                $pode_recurso = true;
            }
        } else {
            $situacao = 'reprovado';
            $mensagem_situacao = "❌ REPROVADO - Excedeu o limite de $limite_negativas disciplina(s) negativa(s)";
            $pode_recurso = ($criterio && $criterio['permite_recurso']);
        }
    }
    
    return [
        'situacao' => $situacao,
        'mensagem' => $mensagem_situacao,
        'total_negativas' => $total_negativas,
        'media_geral' => $media_geral,
        'pode_recurso' => $pode_recurso
    ];
}

// ============================================
// BUSCAR SESSÕES ATIVAS
// ============================================
$sql_sessoes = "
    SELECT DISTINCT 
        cns.id,
        cns.titulo,
        cns.descricao,
        cns.data_sessao,
        cns.hora_inicio,
        cns.hora_fim,
        cns.status,
        cns.escola_id,
        cns.ano_letivo_id,
        cns.turma_id,
        cns.disciplina_id,
        cns.bimestre,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        (SELECT COUNT(*) FROM conselho_nota_participantes WHERE sessao_id = cns.id) as total_participantes,
        (SELECT COUNT(*) FROM conselho_nota_solicitacoes WHERE sessao_id = cns.id AND status = 'pendente') as pendentes
    FROM conselho_nota_sessoes cns
    INNER JOIN conselho_nota_participantes cnp ON cnp.sessao_id = cns.id
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    WHERE cnp.funcionario_id = :funcionario_id 
    AND cns.ano_letivo_id = :ano_letivo_id
    AND cns.status IN ('agendado', 'em_andamento')
    ORDER BY cns.data_sessao ASC, cns.hora_inicio ASC
";
$stmt_sessoes = $conn->prepare($sql_sessoes);
$stmt_sessoes->execute([
    ':funcionario_id' => $funcionario_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$sessoes = $stmt_sessoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES PENDENTES PARA VOTAÇÃO
// ============================================
$sql_solicitacoes_pendentes = "
    SELECT s.*, 
           e.nome as aluno_nome,
           d.nome as disciplina_nome,
           t.nome as turma_nome
    FROM conselho_nota_solicitacoes s
    INNER JOIN estudantes e ON e.id = s.estudante_id
    INNER JOIN disciplinas d ON d.id = s.disciplina_id
    INNER JOIN turmas t ON t.id = s.turma_id
    WHERE s.sessao_id IN (SELECT id FROM conselho_nota_sessoes WHERE id IN (SELECT sessao_id FROM conselho_nota_participantes WHERE funcionario_id = :funcionario_id))
    AND s.status IN ('pendente', 'em_votacao')
    ORDER BY s.created_at ASC
";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes_pendentes);
$stmt_solicitacoes->execute([':funcionario_id' => $funcionario_id]);
$solicitacoes_pendentes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÃO PARA BUSCAR ALUNOS
// ============================================
if (isset($_GET['ajax_alunos'])) {
    $turma_id = (int)$_GET['turma_id'];
    $disciplina_id = (int)$_GET['disciplina_id'];
    $bimestre = (int)$_GET['bimestre'];
  
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.foto,
            e.bi,
            m.id as matricula_id,
            m.numero_processo,
            t.nome as turma,
            t.ano as turma_ano,
            esc.nome as escola,
            d.nome as disciplina,
            n.bimestre,
            n.status,
            COALESCE(n.media_parcial, 0) as nota_parcial,
            COALESCE(n.media_final, 0) as nota_final,
            CASE 
                WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 10 THEN 'Aprovado'
                WHEN COALESCE(n.media_final, n.media_parcial, 0) >= 7 THEN 'Recuperação'
                ELSE 'Reprovado'
            END as situacao
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        INNER JOIN turmas t ON t.id = m.turma_id
        INNER JOIN escolas esc ON esc.id = t.escola_id
        INNER JOIN disciplinas d ON d.id = $disciplina_id
        LEFT JOIN notas n ON n.estudante_id = e.id 
            AND n.disciplina_id = $disciplina_id
            AND n.bimestre = $bimestre
            AND n.ano_letivo_id = $ano_letivo_id
            AND n.turma_id = m.turma_id
        WHERE m.turma_id = $turma_id
            AND t.escola_id = $escola_id
            AND m.status = 'ativa'
            AND m.ano_letivo = $ano_letivo_id
        ORDER BY e.nome
    ";
    
    try {
        $stmt_alunos = $conn->query($sql_alunos);
        $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $alunos]);
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// FUNÇÃO PARA BUSCAR FICHA DO ALUNO
// ============================================
if (isset($_GET['ficha_aluno']) && isset($_GET['matricula_id'])) {
    $matricula_id = (int)$_GET['matricula_id'];
    
    $sql_aluno = "
        SELECT 
            e.*,
            m.id as matricula_id,
            m.numero_processo,
            m.data_matricula,
            m.status as matricula_status,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano,
            t.turno
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        INNER JOIN turmas t ON t.id = m.turma_id
        WHERE m.id = :matricula_id
    ";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([':matricula_id' => $matricula_id]);
    $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    $sql_disciplinas = "SELECT id, nome FROM disciplinas ORDER BY nome";
    $disciplinas = $conn->query($sql_disciplinas)->fetchAll(PDO::FETCH_ASSOC);
    
    $sql_notas = "
        SELECT 
            n.bimestre,
            n.disciplina_id,
            n.media_parcial,
            n.media_final,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.exame_oral,
            n.exame_escrito,
            n.status as nota_status
        FROM notas n
        WHERE n.estudante_id = :estudante_id 
        AND n.ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':estudante_id' => $aluno['id'],
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    $notas_organizadas = [];
    foreach ($notas as $nota) {
        $notas_organizadas[$nota['disciplina_id']][$nota['bimestre']] = $nota;
    }
    
    $sql_matricula = "SELECT ano FROM turmas WHERE id = (SELECT turma_id FROM matriculas WHERE id = :matricula_id)";
    $stmt_matricula = $conn->prepare($sql_matricula);
    $stmt_matricula->execute([':matricula_id' => $matricula_id]);
    $turma_info = $stmt_matricula->fetch(PDO::FETCH_ASSOC);
    $serie_aluno = $turma_info ? $turma_info['ano'] : 0;
    
    $escala_max = ($serie_aluno <= 6) ? 10 : 20;
    $escala_aprovacao = ($serie_aluno <= 6) ? 5 : 10;
    
    $sql_votacao = "
        SELECT COUNT(*) as total_votos, 
               SUM(CASE WHEN voto = 'favoravel' THEN 1 ELSE 0 END) as votos_favoraveis
        FROM conselho_nota_votos 
        WHERE solicitacao_id IN (SELECT id FROM conselho_nota_solicitacoes WHERE estudante_id = :estudante_id)
    ";
    $stmt_votacao = $conn->prepare($sql_votacao);
    $stmt_votacao->execute([':estudante_id' => $aluno['id']]);
    $votacao = $stmt_votacao->fetch(PDO::FETCH_ASSOC);
    $pode_editar = ($votacao['total_votos'] >= 3 && $votacao['votos_favoraveis'] > $votacao['total_votos'] / 2);
        // Calcular situação do aluno baseado nos critérios
    $situacao_aluno = calcularSituacaoAluno($conn, $aluno['id'], $serie_aluno, $notas_organizadas, $escala_max, $escala_aprovacao);

    $response = [
        'success' => true,
        'aluno' => $aluno,
        'disciplinas' => $disciplinas,
        'notas' => $notas_organizadas,
        'bimestres' => [1, 2, 3],
        'escala_max' => $escala_max,
        'escala_aprovacao' => $escala_aprovacao,
        'ano' => $serie_aluno,
        'turma_id' => $aluno['turma_id'],
        'disciplina_selecionada' => 0,
        'pode_editar' => $pode_editar,
        'votacao' => $votacao,
        'situacao_aluno' => $situacao_aluno
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ============================================
// PROCESSAR VOTO (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'registrar_voto') {
    $solicitacao_id = (int)$_POST['solicitacao_id'];
    $voto = $_POST['voto'];
    $justificativa = $_POST['justificativa'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        $sql_check = "SELECT id FROM conselho_nota_votos WHERE solicitacao_id = :solicitacao_id AND funcionario_id = :funcionario_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':solicitacao_id' => $solicitacao_id,
            ':funcionario_id' => $funcionario_id
        ]);
        
        if ($stmt_check->rowCount() > 0) {
            throw new Exception("Você já votou nesta solicitação.");
        }
        
        $sql_voto = "INSERT INTO conselho_nota_votos (solicitacao_id, funcionario_id, voto, justificativa) 
                     VALUES (:solicitacao_id, :funcionario_id, :voto, :justificativa)";
        $stmt_voto = $conn->prepare($sql_voto);
        $stmt_voto->execute([
            ':solicitacao_id' => $solicitacao_id,
            ':funcionario_id' => $funcionario_id,
            ':voto' => $voto,
            ':justificativa' => $justificativa
        ]);
        
        $sql_update = "UPDATE conselho_nota_solicitacoes 
                       SET votos_favoraveis = (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = :id AND voto = 'favoravel'),
                           votos_contra = (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = :id AND voto = 'contra'),
                           status = 'em_votacao'
                       WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $solicitacao_id]);
        
        $sql_status = "SELECT votos_favoraveis, votos_contra, nota_sugerida, matricula_id, disciplina_id, bimestre FROM conselho_nota_solicitacoes WHERE id = :id";
        $stmt_status = $conn->prepare($sql_status);
        $stmt_status->execute([':id' => $solicitacao_id]);
        $status = $stmt_status->fetch(PDO::FETCH_ASSOC);
        
        $total_votos = $status['votos_favoraveis'] + $status['votos_contra'];
        
        if ($total_votos >= 3) {
            $resultado = ($status['votos_favoraveis'] > $status['votos_contra']) ? 'aprovado' : 'reprovado';
            
            $sql_result = "UPDATE conselho_nota_solicitacoes SET status = 'finalizado', resultado_final = :resultado WHERE id = :id";
            $stmt_result = $conn->prepare($sql_result);
            $stmt_result->execute([':resultado' => $resultado, ':id' => $solicitacao_id]);
            
            if ($resultado == 'aprovado') {
                $sql_update_nota = "UPDATE notas SET media_final = :nota WHERE estudante_id = :matricula_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre";
                $stmt_update_nota = $conn->prepare($sql_update_nota);
                $stmt_update_nota->execute([
                    ':nota' => $status['nota_sugerida'],
                    ':matricula_id' => $status['matricula_id'],
                    ':disciplina_id' => $status['disciplina_id'],
                    ':bimestre' => $status['bimestre']
                ]);
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Voto registrado com sucesso!', 'resultado' => $resultado ?? null]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// FUNÇÃO PARA ATUALIZAR NOTA (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_nota'])) {
    $estudante_id = (int)$_POST['estudante_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $campo = $_POST['campo'];
    $valor = $_POST['valor'];
    $turma_id = (int)$_POST['turma_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $professor_id = $funcionario_id;
    
    $campos_permitidos = ['mac', 'npt', 'exame_normal', 'exame_recurso', 'exame_especial', 'exame_oral', 'exame_escrito'];
    if (!in_array($campo, $campos_permitidos)) {
        echo json_encode(['success' => false, 'error' => 'Campo não permitido']);
        exit;
    }
    
    try {
        $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        if ($stmt_check->rowCount() > 0) {
            $sql = "UPDATE notas SET $campo = :valor WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt = $conn->prepare($sql);
        } else {
            $sql = "INSERT INTO notas (estudante_id, disciplina_id, bimestre, ano_letivo_id, turma_id, professor_id, $campo) 
                    VALUES (:estudante_id, :disciplina_id, :bimestre, :ano_letivo_id, :turma_id, :professor_id, :valor)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':professor_id', $professor_id);
        }
        
        $stmt->bindParam(':estudante_id', $estudante_id);
        $stmt->bindParam(':disciplina_id', $disciplina_id);
        $stmt->bindParam(':bimestre', $bimestre);
        $stmt->bindParam(':ano_letivo_id', $ano_letivo_id);
        $stmt->bindParam(':turma_id', $turma_id);
        $stmt->bindParam(':valor', $valor !== '' ? $valor : null);
        $stmt->execute();
        
        $sql_update_media = "UPDATE notas SET media_parcial = (COALESCE(mac,0) + COALESCE(npt,0)) / 2 
                            WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id 
                            AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
        $stmt_media = $conn->prepare($sql_update_media);
        $stmt_media->execute([
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Nota atualizada com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// CRIAR SOLICITAÇÃO DE REVISÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'criar_solicitacao') {
    
    header('Content-Type: application/json');
    
    if (!isset($_POST['estudante_id']) || !isset($_POST['disciplina_id']) || !isset($_POST['bimestre'])) {
        echo json_encode(['success' => false, 'error' => 'Parâmetros incompletos.']);
        exit;
    }
    
    $estudante_id = (int)$_POST['estudante_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $nota_sugerida = (float)$_POST['nota_sugerida'];
    $motivo = $_POST['motivo'];
    $justificativa = $_POST['justificativa'] ?? '';
    $turma_id = (int)$_POST['turma_id'];
    
    if ($estudante_id <= 0 || $disciplina_id <= 0 || $bimestre <= 0 || $turma_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
        exit;
    }
    
    if (empty($motivo)) {
        echo json_encode(['success' => false, 'error' => 'Motivo é obrigatório.']);
        exit;
    }
    
    try {
        $sql_nota = "SELECT media_final, media_parcial FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        $nota_atual = $nota['media_final'] ?? $nota['media_parcial'] ?? 0;
        
        $sql_sessao = "SELECT id FROM conselho_nota_sessoes WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND status IN ('agendado', 'em_andamento') LIMIT 1";
        $stmt_sessao = $conn->prepare($sql_sessao);
        $stmt_sessao->execute([
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre
        ]);
        $sessao = $stmt_sessao->fetch(PDO::FETCH_ASSOC);
        
        if (!$sessao) {
            echo json_encode(['success' => false, 'error' => 'Não há sessão do conselho ativa para esta turma/disciplina neste bimestre.']);
            exit;
        }
        
        $sessao_id = $sessao['id'];
        
        $sql_check = "SELECT id FROM conselho_nota_solicitacoes WHERE sessao_id = :sessao_id AND estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':sessao_id' => $sessao_id,
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre
        ]);
        
        if ($stmt_check->rowCount() > 0) {
            echo json_encode(['success' => false, 'error' => 'Já existe uma solicitação de revisão para este aluno nesta disciplina/bimestre.']);
            exit;
        }
        
        $sql_matricula = "SELECT id FROM matriculas WHERE estudante_id = :estudante_id AND turma_id = :turma_id LIMIT 1";
        $stmt_matricula = $conn->prepare($sql_matricula);
        $stmt_matricula->execute([':estudante_id' => $estudante_id, ':turma_id' => $turma_id]);
        $matricula = $stmt_matricula->fetch(PDO::FETCH_ASSOC);
        $matricula_id = $matricula['id'] ?? 0;
        
        if ($matricula_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Matrícula do aluno não encontrada.']);
            exit;
        }
        
        $sql = "INSERT INTO conselho_nota_solicitacoes (sessao_id, funcionario_solicitante_id, matricula_id, estudante_id, disciplina_id, bimestre, nota_atual, nota_sugerida, motivo, justificativa, status) 
                VALUES (:sessao_id, :professor_id, :matricula_id, :estudante_id, :disciplina_id, :bimestre, :nota_atual, :nota_sugerida, :motivo, :justificativa, 'pendente')";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([
            ':sessao_id' => $sessao_id,
            ':professor_id' => $funcionario_id,
            ':matricula_id' => $matricula_id,
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':nota_atual' => $nota_atual,
            ':nota_sugerida' => $nota_sugerida,
            ':motivo' => $motivo,
            ':justificativa' => $justificativa
        ]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Solicitação de revisão enviada ao conselho com sucesso!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar solicitação no banco de dados.']);
        }
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Erro no banco de dados: ' . $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Conselho de Nota | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --purple: #6f42c1;
            --orange: #fd7e14;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '🎓';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar, .btn-ajuda, .btn-print {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-ajuda {
            background: linear-gradient(135deg, var(--orange) 0%, #e66a00 100%);
            color: white;
        }

        .btn-ajuda:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3);
            color: white;
        }

        .btn-print {
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        /* ============================================
           SESSÃO CARD
        ============================================ */
        .sessao-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: var(--transition);
        }

        .sessao-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .sessao-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, #004d2d 100%);
            color: white;
            padding: 20px 25px;
            cursor: pointer;
            transition: var(--transition);
        }

        .sessao-header:hover {
            background: linear-gradient(135deg, #004d2d 0%, var(--primary-green) 100%);
        }

        .sessao-body {
            padding: 25px;
            display: none;
            background: #f8f9fa;
        }

        .sessao-body.active {
            display: block;
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           FILTRO CARD
        ============================================ */
        .filtro-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filtro-card .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .filtro-card .form-control,
        .filtro-card .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .filtro-card .form-control:focus,
        .filtro-card .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        /* ============================================
           ALUNO CARD
        ============================================ */
        .aluno-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 18px;
            border-left: 4px solid var(--primary-green);
            transition: var(--transition);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .aluno-card:hover {
            transform: translateX(8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary-dark);
        }

        .aluno-foto {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ============================================
           SOLICITAÇÕES PENDENTES
        ============================================ */
        .solicitacoes-header {
            background: linear-gradient(135deg, var(--purple) 0%, #5a32a3 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 15px 20px;
        }

        .solicitacoes-container {
            background: white;
            border-radius: 0 0 20px 20px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
        }

        .solicitacoes-container.hidden {
            display: none;
        }

        .solicitacao-item {
            background: linear-gradient(135deg, #fff8e7 0%, #fff3cd 100%);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 15px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
        }

        .solicitacao-item:hover {
            transform: translateX(8px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-votar {
            background: linear-gradient(135deg, var(--purple) 0%, #5a32a3 100%);
            color: white;
            border-radius: 30px;
            padding: 6px 20px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
        }

        .btn-votar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(111, 66, 193, 0.3);
            color: white;
        }

        .btn-toggle-solicitacoes {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .btn-toggle-solicitacoes:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.02);
            color: white;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-aprovado { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-reprovado { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-recuperacao { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-pendente { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-agendado { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-em_andamento { background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%); color: white; }

        .badge-voto-favoravel { background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; }
        .badge-voto-contra { background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; }
        .badge-voto-abstencao { background: #e2e3e5; color: #383d41; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
        }

        .modal-header-custom .btn-close-white {
            filter: brightness(0) invert(1);
        }

        .modal-ficha {
            max-width: 1000px;
        }

        /* ============================================
           TABELA DE NOTAS
        ============================================ */
        .table-notas {
            border-radius: 16px;
            overflow: hidden;
            width: 100%;
        }

        .table-notas th {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            padding: 12px;
            text-align: center;
        }

        .table-notas td {
            padding: 10px;
            vertical-align: middle;
            text-align: center;
        }

        .nota-input {
            width: 80px;
            text-align: center;
            padding: 6px;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .nota-input:focus {
            border-color: var(--primary-green);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        .nota-input:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        /* Cores das notas baseadas na escala */
        .nota-alta { background-color: #d4edda; color: #155724; font-weight: bold; }
        .nota-media { background-color: #fff3cd; color: #856404; font-weight: bold; }
        .nota-baixa { background-color: #f8d7da; color: #721c24; font-weight: bold; }

        /* ============================================
           NAV BIMESTRE
        ============================================ */
        .nav-bimestre {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .btn-bimestre {
            padding: 10px 25px;
            border-radius: 50px;
            background: #f8f9fa;
            color: #495057;
            border: 2px solid #e9ecef;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
        }

        .btn-bimestre.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .btn-bimestre:hover {
            transform: translateY(-2px);
            border-color: var(--primary-green);
        }

        /* ============================================
           INFO CARDS
        ============================================ */
        .info-editavel {
            background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%);
            border-left: 4px solid var(--success);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .info-bloqueado {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid var(--warning);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        /* ============================================
           HELP SECTION
        ============================================ */
        .help-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .help-section:last-child {
            border-bottom: none;
        }

        .help-title {
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .help-step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: var(--transition);
        }

        .help-step:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .help-number {
            width: 35px;
            height: 35px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        .spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ============================================
           SCROLLBAR
        ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .nav-bimestre {
                justify-content: center;
            }
            
            .btn-bimestre {
                padding: 6px 15px;
                font-size: 0.8rem;
            }
            
            .table-notas {
                font-size: 0.75rem;
            }
            
            .nota-input {
                width: 60px;
                font-size: 0.7rem;
            }
            
            .solicitacao-item .row {
                flex-direction: column;
                gap: 10px;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .btn-print, .filtro-card, .sessao-header {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .sessao-body {
                display: block !important;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
 <style>
    /* Estilos para os votos nas solicitações pendentes */
.votos-container {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 60px !important;
    padding: 10px 20px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.votos-favoraveis {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
    font-size: 1.2rem !important;
    padding: 6px 15px !important;
    border-radius: 40px !important;
    box-shadow: 0 2px 5px rgba(40, 167, 69, 0.3);
}

.votos-contra {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
    font-size: 1.2rem !important;
    padding: 6px 15px !important;
    border-radius: 40px !important;
    box-shadow: 0 2px 5px rgba(220, 53, 69, 0.3);
}

.votos-favoraveis:hover, .votos-contra:hover {
    transform: scale(1.02);
    transition: transform 0.2s ease;
}
 </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-chalkboard-user me-2"></i> Conselho de Nota</h2>
                    <p>Participe das sessões do conselho e analise as notas dos alunos</p>
                    <small><i class="fas fa-user-check me-1"></i> Você tem permissão para participar do conselho</small>
                </div>
                <div class="no-print">
                    <button type="button" class="btn-ajuda" data-bs-toggle="modal" data-bs-target="#modalAjudaConselho">
                        <i class="fas fa-question-circle"></i> Ajuda
                    </button>
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Seção de Solicitações Pendentes de Votação -->
        <!-- Seção de Solicitações Pendentes de Votação - APENAS ESTA PARTE MODIFICADA -->
<?php if (!empty($solicitacoes_pendentes)): ?>
<div class="fade-in-up">
    <div class="solicitacoes-header d-flex justify-content-between align-items-center">
        <div>
            <i class="fas fa-gavel me-2"></i>
            <strong>Solicitações Pendentes de Votação</strong>
            <span class="badge bg-white text-dark ms-2"><?php echo count($solicitacoes_pendentes); ?> pendente(s)</span>
        </div>
        <button class="btn-toggle-solicitacoes" onclick="toggleSolicitacoes()">
            <i class="fas fa-eye" id="toggleIcon"></i> <span id="toggleText">Mostrar</span>
        </button>
    </div>
    <div class="solicitacoes-container hidden" id="solicitacoesContainer">
        <?php foreach ($solicitacoes_pendentes as $solic): ?>
        <div class="solicitacao-item">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <strong><i class="fas fa-user-graduate me-2"></i> <?php echo htmlspecialchars($solic['aluno_nome']); ?></strong><br>
                    <small class="text-muted">
                        <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($solic['disciplina_nome']); ?> - 
                        <i class="fas fa-layer-group me-1"></i> <?php echo $solic['bimestre']; ?>º Bimestre<br>
                        <i class="fas fa-chart-line me-1"></i> Nota atual: <strong><?php echo $solic['nota_atual']; ?></strong> → 
                        Nota sugerida: <strong><?php echo $solic['nota_sugerida']; ?></strong><br>
                        <i class="fas fa-comment me-1"></i> Motivo: <?php echo htmlspecialchars($solic['motivo']); ?>
                    </small>
                </div>
                <div class="col-md-3 text-center">
                    <!-- VOTOS COM TAMANHO E COR AUMENTADOS -->
                    <div class="votos-container" style="background: #f8f9fa; border-radius: 50px; padding: 8px 15px; display: inline-block;">
                        <i class="fas fa-chart-bar me-2" style="font-size: 1.1rem;"></i>
                        <strong style="font-size: 1.1rem;">Votos:</strong>
                        <span class="votos-favoraveis" style="background: #28a745; color: white; padding: 5px 12px; border-radius: 30px; margin: 0 5px; font-weight: bold; font-size: 1.1rem; display: inline-block;">
                            <i class="fas fa-thumbs-up me-1"></i> <?php echo $solic['votos_favoraveis']; ?> ✅
                        </span>
                        <span style="font-size: 1.1rem; font-weight: bold;">/</span>
                        <span class="votos-contra" style="background: #dc3545; color: white; padding: 5px 12px; border-radius: 30px; margin: 0 5px; font-weight: bold; font-size: 1.1rem; display: inline-block;">
                            <i class="fas fa-thumbs-down me-1"></i> <?php echo $solic['votos_contra']; ?> ❌
                        </span>
                    </div>
                </div>
                <div class="col-md-2 text-end">
                    <button class="btn-votar" onclick="abrirVotacao(<?php echo $solic['id']; ?>, '<?php echo addslashes($solic['aluno_nome']); ?>', <?php echo $solic['nota_atual']; ?>, <?php echo $solic['nota_sugerida']; ?>, '<?php echo addslashes($solic['motivo']); ?>')">
                        <i class="fas fa-vote-yea"></i> Votar
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
        
        <!-- Modal de Ajuda do Conselho de Nota -->
        <div class="modal fade" id="modalAjudaConselho" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header modal-header-custom">
                        <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Guia do Conselho de Nota</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="help-section">
                            <div class="help-title"><i class="fas fa-gavel me-2"></i> O que é o Conselho de Nota?</div>
                            <p>O Conselho de Nota é uma comissão pedagógica responsável por analisar e decidir sobre solicitações de revisão de notas dos alunos.</p>
                        </div>
                        
                        <div class="help-section">
                            <div class="help-title"><i class="fas fa-vote-yea me-2"></i> Como Funciona o Processo de Votação?</div>
                            <div class="help-step"><div class="help-number">1</div><div class="help-content"><h6>Solicitação de Revisão</h6><p>Um professor solicita a revisão da nota de um aluno.</p></div></div>
                            <div class="help-step"><div class="help-number">2</div><div class="help-content"><h6>Análise pelos Professores</h6><p>Os professores participantes do conselho analisam e votam.</p></div></div>
                            <div class="help-step"><div class="help-number">3</div><div class="help-content"><h6>Tipos de Voto</h6><p><span class="badge-voto-favoravel">✅ Favorável</span> <span class="badge-voto-contra">❌ Contra</span> <span class="badge-voto-abstencao">⏸️ Abstenção</span></p></div></div>
                            <div class="help-step"><div class="help-number">4</div><div class="help-content"><h6>Contagem de Votos</h6><p>Mínimo de 3 votos e maioria simples de votos favoráveis.</p></div></div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>Dica Importante:</strong> Sempre justifique seu voto para maior transparência no processo!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir Ajuda</button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (empty($sessoes)): ?>
            <div class="alert alert-info text-center fade-in-up">
                <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                <h5>Nenhuma sessão do conselho disponível</h5>
                <p>No momento não há sessões do conselho agendadas para você.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sessoes as $sessao): ?>
            <div class="sessao-card fade-in-up" data-sessao-id="<?php echo $sessao['id']; ?>">
                <div class="sessao-header" onclick="toggleSessao(<?php echo $sessao['id']; ?>)">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <i class="fas fa-calendar-alt me-2"></i> 
                            <strong><?php echo htmlspecialchars($sessao['titulo'] ?: $sessao['disciplina_nome']); ?></strong>
                            <br>
                            <small><?php echo htmlspecialchars($sessao['turma_nome']); ?> | 
                            <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> | 
                            <?php echo $sessao['bimestre']; ?>º Bimestre</small>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo $sessao['status'] == 'agendado' ? 'badge-agendado' : 'badge-em_andamento'; ?>">
                                <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                            </span>
                            <br>
                            <small>
                                <i class="fas fa-users me-1"></i> <?php echo $sessao['total_participantes']; ?> participantes |
                                <i class="fas fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($sessao['data_sessao'] . ' ' . $sessao['hora_inicio'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
                <div class="sessao-body" id="sessao-<?php echo $sessao['id']; ?>">
                    <div class="filtro-card">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label"><i class="fas fa-search me-1"></i> Filtrar por Aluno</label>
                                <input type="text" id="filtro_nome_<?php echo $sessao['id']; ?>" class="form-control" placeholder="Digite o nome...">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><i class="fas fa-filter me-1"></i> Filtrar por Status</label>
                                <select id="filtro_status_<?php echo $sessao['id']; ?>" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="Aprovado">Aprovados</option>
                                    <option value="Reprovado">Reprovados</option>
                                    <option value="Recuperação">Recuperação</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary-custom w-100" onclick="carregarAlunos(<?php echo $sessao['id']; ?>, <?php echo $sessao['turma_id']; ?>, <?php echo $sessao['disciplina_id']; ?>, <?php echo $sessao['bimestre']; ?>)">
                                    <i class="fas fa-sync-alt me-2"></i> Carregar Alunos
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="alunos-container-<?php echo $sessao['id']; ?>">
                        <div class="text-center p-5">
                            <div class="spinner"></div>
                            <p class="mt-3 text-muted">Clique em "Carregar Alunos" para visualizar a lista...</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal Ficha do Aluno -->
    <div class="modal fade" id="modalFichaAluno" tabindex="-1">
        <div class="modal-dialog modal-xl modal-ficha">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-user-graduate me-2"></i> Ficha do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="ficha-conteudo">
                    <div class="text-center p-5"><div class="spinner"></div><p>Carregando...</p></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Votação -->
    <div class="modal fade" id="modalVotacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-vote-yea me-2"></i> Votação do Conselho</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="voto_solicitacao_id">
                    <div class="alert alert-info">
                        <p><strong><i class="fas fa-user-graduate"></i> Aluno:</strong> <span id="voto_aluno_nome"></span></p>
                        <p><strong><i class="fas fa-chart-line"></i> Nota Atual:</strong> <span id="voto_nota_atual"></span> → <strong>Nota Sugerida:</strong> <span id="voto_nota_sugerida"></span></p>
                        <p><strong><i class="fas fa-comment"></i> Motivo:</strong> <span id="voto_motivo"></span></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Seu Voto</label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="voto" id="voto_favoravel" value="favoravel" checked>
                                <label class="form-check-label text-success" for="voto_favoravel">
                                    <i class="fas fa-check-circle"></i> Favorável
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="voto" id="voto_contra" value="contra">
                                <label class="form-check-label text-danger" for="voto_contra">
                                    <i class="fas fa-times-circle"></i> Contra
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="voto" id="voto_abstencao" value="abstencao">
                                <label class="form-check-label text-secondary" for="voto_abstencao">
                                    <i class="fas fa-minus-circle"></i> Abstenção
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Justificativa</label>
                        <textarea id="justificativa_voto" class="form-control" rows="3" placeholder="Justifique seu voto..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="registrarVoto()">
                        <i class="fas fa-vote-yea"></i> Registrar Voto
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let alunosData = {};
        let fichaData = {};
        let solicitacoesVisiveis = false;
        
        function toggleSessao(id) {
            $('#sessao-' + id).toggleClass('active');
        }
        
        function toggleSolicitacoes() {
            solicitacoesVisiveis = !solicitacoesVisiveis;
            if (solicitacoesVisiveis) {
                $('#solicitacoesContainer').removeClass('hidden');
                $('#toggleIcon').removeClass('fa-eye').addClass('fa-eye-slash');
                $('#toggleText').text('Ocultar');
            } else {
                $('#solicitacoesContainer').addClass('hidden');
                $('#toggleIcon').removeClass('fa-eye-slash').addClass('fa-eye');
                $('#toggleText').text('Mostrar');
            }
        }
        
        function carregarAlunos(sessaoId, turmaId, disciplinaId, bimestre) {
            let container = $('#alunos-container-' + sessaoId);
            container.html('<div class="text-center p-5"><div class="spinner"></div><p>Carregando alunos...</p></div>');
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'GET',
                data: { ajax_alunos: 1, turma_id: turmaId, disciplina_id: disciplinaId, bimestre: bimestre },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alunosData[sessaoId] = response.data;
                        renderizarAlunos(sessaoId);
                    } else {
                        container.html('<div class="alert alert-danger">Erro: ' + response.error + '</div>');
                    }
                },
                error: function() {
                    container.html('<div class="alert alert-danger">Erro ao carregar alunos.</div>');
                }
            });
        }
        
        function renderizarAlunos(sessaoId) {
            let container = $('#alunos-container-' + sessaoId);
            let alunos = alunosData[sessaoId] || [];
            let filtroNome = $('#filtro_nome_' + sessaoId).val().toLowerCase();
            let filtroStatus = $('#filtro_status_' + sessaoId).val();
            
            let filtered = alunos.filter(function(aluno) {
                let matchNome = filtroNome === '' || aluno.nome.toLowerCase().includes(filtroNome);
                let matchStatus = filtroStatus === '' || aluno.situacao === filtroStatus;
                return matchNome && matchStatus;
            });
            
            if (filtered.length === 0) {
                container.html('<div class="alert alert-warning">Nenhum aluno encontrado.</div>');
                return;
            }
            
            let html = '';
            for (let aluno of filtered) {
                let notaAtual = parseFloat(aluno.nota_final).toFixed(1);
                let statusClass = aluno.situacao === 'Aprovado' ? 'badge-aprovado' : (aluno.situacao === 'Reprovado' ? 'badge-reprovado' : 'badge-recuperacao');
                
                html += `
                    <div class="aluno-card" onclick="abrirFichaAluno(${aluno.matricula_id})">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="aluno-foto">
                                    <i class="fas fa-user fa-2x text-secondary"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h6 class="mb-0">${aluno.nome}</h6>
                                <small class="text-muted">Processo: ${aluno.numero_processo || '-'}</small>
                                <br>
                                <small>Nota: <strong>${notaAtual}</strong></small>
                                <br>
                                <span class="badge ${statusClass}">${aluno.situacao}</span>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-primary-custom btn-sm" onclick="event.stopPropagation(); abrirFichaAluno(${aluno.matricula_id})">
                                    <i class="fas fa-edit"></i> Analisar
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
            container.html(html);
        }
        
        function abrirFichaAluno(matriculaId) {
            $('#ficha-conteudo').html('<div class="text-center p-5"><div class="spinner"></div><p>Carregando dados do aluno...</p></div>');
            $('#modalFichaAluno').modal('show');
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'GET',
                data: { ficha_aluno: 1, matricula_id: matriculaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        fichaData = response;
                        renderizarFicha(response, 1);
                    } else {
                        $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar ficha.</div>');
                    }
                },
                error: function() {
                    $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar dados do aluno.</div>');
                }
            });
        }

        function ampliarImagem(foto, nomeAluno) {
    let imagemUrl = foto ? `../uploads/alunos/${foto}` : '../assets/images/avatar-padrao.png';
    Swal.fire({
        title: `<i class="fas fa-user-graduate me-2"></i> ${nomeAluno}`,
        html: `<img src="${imagemUrl}" style="width:100%; max-width:400px; border-radius:15px; box-shadow:0 5px 20px rgba(0,0,0,0.2);">`,
        showConfirmButton: true,
        confirmButtonText: '<i class="fas fa-times me-2"></i>Fechar',
        confirmButtonColor: '#006B3E',
        background: 'white',
        padding: '20px',
        width: '500px'
    });
}
           function renderizarFicha(data, bimestreAtivo) {
            let aluno = data.aluno;
            let disciplinas = data.disciplinas;
            let notas = data.notas;
            let escalaMax = data.escala_max;
            let escalaAprovacao = data.escala_aprovacao;
            let serie = data.ano;
            let turmaId = data.turma_id;
            let anoLetivoId = <?php echo $ano_letivo_id; ?>;
            let podeEditar = data.pode_editar;
            let situacaoAluno = data.situacao_aluno || {};
            
            if (!aluno) {
                $('#ficha-conteudo').html('<div class="alert alert-danger">Aluno não encontrado.</div>');
                return;
            }
            
            disciplinas.sort(function(a, b) { return a.nome.localeCompare(b.nome); });
            
            let ehClasseExame = (serie == 6 || serie == 9 || serie == 12);
            let bimestres = [1, 2, 3];
            
            // Calcular média final do aluno
            let somaMedias = 0;
            let countDisciplinasComNota = 0;
            
            for (let disc of disciplinas) {
                let notaFinal = 0;
                if (notas[disc.id] && notas[disc.id][3]) {
                    notaFinal = notas[disc.id][3].media_final || notas[disc.id][3].media_parcial || 0;
                } else {
                    for (let bim of [1, 2, 3]) {
                        if (notas[disc.id] && notas[disc.id][bim]) {
                            let nota = notas[disc.id][bim].media_final || notas[disc.id][bim].media_parcial || 0;
                            if (nota > notaFinal) notaFinal = nota;
                        }
                    }
                }
                if (notaFinal > 0) {
                    somaMedias += notaFinal;
                    countDisciplinasComNota++;
                }
            }
            
            let mediaFinalAluno = countDisciplinasComNota > 0 ? (somaMedias / countDisciplinasComNota).toFixed(1) : '0.0';
            
            let statusClass = '';
            let statusIcon = '';
            if (situacaoAluno.situacao === 'aprovado') {
                statusClass = 'badge-aprovado';
                statusIcon = '✅';
            } else if (situacaoAluno.situacao === 'aprovado_com_recurso') {
                statusClass = 'badge-recuperacao';
                statusIcon = '🔄';
            } else if (situacaoAluno.situacao === 'reprovado') {
                statusClass = 'badge-reprovado';
                statusIcon = '❌';
            } else {
                statusClass = 'badge-pendente';
                statusIcon = '⏳';
            }
            
            let fotoHtml = aluno.foto ? 
                `<img src="../uploads/alunos/${aluno.foto}" style="width:120px;height:120px;border-radius:50%;object-fit:cover;cursor:pointer;border:3px solid #006B3E;" onclick="ampliarImagem('${aluno.foto}', '${aluno.nome.replace(/'/g, "\\'")}')" title="Clique para ampliar">` :
                `<div class="aluno-foto mx-auto" style="width:120px;height:120px;background:linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:3px solid #006B3E;" onclick="ampliarImagem(null, '${aluno.nome.replace(/'/g, "\\'")}')" title="Clique para ampliar"><i class="fas fa-user fa-4x text-secondary"></i></div>`;
            
            let infoHtml = podeEditar ? 
                `<div class="info-editavel"><i class="fas fa-check-circle text-success me-2"></i><strong>Notas editáveis!</strong> Este aluno tem votos suficientes para revisão de nota.</div>` :
                `<div class="info-bloqueado"><i class="fas fa-lock text-warning me-2"></i><strong>Notas bloqueadas para edição.</strong> Este aluno ainda não tem votos suficientes para revisão de nota.</div>`;
            
            let navHtml = '<div class="nav-bimestre">';
            for (let bim of bimestres) {
                navHtml += `<button class="btn-bimestre ${bim === bimestreAtivo ? 'active' : ''}" onclick="mudarBimestre(${bim})">${bim}º Bimestre</button>`;
            }
            navHtml += '</div>';
            
            let html = `
                <div class="row mb-4"><div class="col-md-12">${infoHtml}${navHtml}</div></div>
                <div class="row">
                    <div class="col-md-3 text-center">
                        ${fotoHtml}
                        <h5 class="mt-3">${aluno.nome}</h5>
                        <p class="text-muted">Processo: ${aluno.numero_processo || '-'}</p>
                        <hr>
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-chart-line"></i> Resumo Acadêmico</h6>
                                <hr>
                                <p><strong><i class="fas fa-calculator"></i> Média Final:</strong> <span class="badge ${statusClass}" style="font-size:1rem;">${mediaFinalAluno}</span></p>
                                <p><strong><i class="fas fa-exclamation-triangle"></i> Disciplinas Negativas:</strong> <span class="badge ${situacaoAluno.total_negativas > 0 ? 'badge-reprovado' : 'badge-aprovado'}">${situacaoAluno.total_negativas || 0}</span></p>
                                <p><strong><i class="fas ${situacaoAluno.situacao === 'aprovado' ? 'fa-check-circle text-success' : (situacaoAluno.situacao === 'aprovado_com_recurso' ? 'fa-sync-alt text-warning' : 'fa-times-circle text-danger')}"></i> Situação:</strong> 
                                    <span class="badge ${statusClass}" style="font-size:0.9rem;">${statusIcon} ${situacaoAluno.situacao === 'aprovado' ? 'APROVADO' : (situacaoAluno.situacao === 'aprovado_com_recurso' ? 'APROVADO COM RECURSO' : 'REPROVADO')}</span>
                                </p>
                                ${situacaoAluno.mensagem ? `<small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> ${situacaoAluno.mensagem}</small>` : ''}
                                ${situacaoAluno.pode_recurso ? `<small class="text-warning d-block mt-2"><i class="fas fa-gavel"></i> Este aluno pode solicitar recurso ao conselho!</small>` : ''}
                            </div>
                        </div>
                        <hr>
                        <p><i class="fas fa-id-card"></i> <strong>BI:</strong> ${aluno.bi || '-'}</p>
                        <p><i class="fas fa-school"></i> <strong>Turma:</strong> ${aluno.turma_nome}</p>
                        <p><i class="fas fa-chart-line"></i> <strong>Escala:</strong> 0 a ${escalaMax}</p>
                        <p><i class="fas fa-check-circle"></i> <strong>Aprovação:</strong> ${escalaAprovacao} pontos</p>
                        ${ehClasseExame ? '<p><i class="fas fa-star text-warning"></i> <strong>Classe de Exame</strong></p>' : ''}
                    </div>
                    <div class="col-md-9">
                        <div class="table-responsive">
                            <table class="table table-notas">
                                <thead><tr><th width="30%">Disciplina</th>`;
            
            if (bimestreAtivo == 1 || bimestreAtivo == 2) {
                html += '<th width="20%">MAC</th><th width="20%">NPT</th><th width="20%">Média Parcial</th>';
            } else if (bimestreAtivo == 3) {
                if (ehClasseExame) {
                    html += '<th width="12%">MAC</th><th width="12%">NPT</th><th width="12%">Exame Normal</th><th width="12%">Exame Recurso</th><th width="15%">Média Final</th>';
                } else {
                    html += '<th width="15%">MAC</th><th width="15%">NPT</th><th width="20%">Média Final</th>';
                }
            }
            html += '<th width="15%">Status</th></tr></thead><tbody>';
            
            for (let disc of disciplinas) {
                let nota = (notas[disc.id] && notas[disc.id][bimestreAtivo]) ? notas[disc.id][bimestreAtivo] : null;
                let corClasse = '';
                let disabledAttr = podeEditar ? '' : 'disabled';
                let mediaValor = 0;
                
                if (nota) {
                    mediaValor = (bimestreAtivo == 1 || bimestreAtivo == 2) ? (nota.media_parcial || 0) : (nota.media_final || 0);
                }
                
                let percentual = (mediaValor / escalaMax) * 100;
                if (percentual >= 70) corClasse = 'nota-alta';
                else if (percentual >= 50) corClasse = 'nota-media';
                else corClasse = 'nota-baixa';
                
                let statusTexto = '-';
                let statusCor = '';
                if (nota) {
                    if (bimestreAtivo == 3 && nota.media_final) {
                        if (parseFloat(nota.media_final) >= escalaAprovacao) {
                            statusTexto = 'Aprovado';
                            statusCor = 'badge-aprovado';
                        } else {
                            statusTexto = 'Reprovado';
                            statusCor = 'badge-reprovado';
                        }
                    } else if (nota.media_parcial) {
                        if (parseFloat(nota.media_parcial) >= escalaAprovacao) {
                            statusTexto = 'Aprovado';
                            statusCor = 'badge-aprovado';
                        } else {
                            statusTexto = 'Recuperação';
                            statusCor = 'badge-recuperacao';
                        }
                    }
                }
                
                html += `<tr><td class="text-start"><strong>${disc.nome}</strong></td>`;
                
                if (bimestreAtivo == 1 || bimestreAtivo == 2) {
                    let macValor = (nota && nota.mac) ? nota.mac : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" value="${macValor}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                    
                    let nptValor = (nota && nota.npt) ? nota.npt : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" value="${nptValor}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                    
                    let mediaParcial = (nota && nota.media_parcial) ? parseFloat(nota.media_parcial).toFixed(1) : '-';
                    html += `<td class="${corClasse}"><strong>${mediaParcial}</strong></td>`;
                } 
                else if (bimestreAtivo == 3) {
                    let macValor = (nota && nota.mac) ? nota.mac : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" value="${macValor}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                    
                    let nptValor = (nota && nota.npt) ? nota.npt : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" value="${nptValor}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                    
                    if (ehClasseExame) {
                        let exameNormal = (nota && nota.exame_normal) ? nota.exame_normal : '';
                        html += `<td><input type="number" class="nota-input ${corClasse}" value="${exameNormal}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'exame_normal', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                        
                        let exameRecurso = (nota && nota.exame_recurso) ? nota.exame_recurso : '';
                        html += `<td><input type="number" class="nota-input ${corClasse}" value="${exameRecurso}" step="0.5" min="0" max="${escalaMax}" onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'exame_recurso', this.value, ${turmaId}, ${anoLetivoId})" ${disabledAttr}></td>`;
                        
                        let mediaFinal = (nota && nota.media_final) ? parseFloat(nota.media_final).toFixed(1) : '-';
                        html += `<td class="${corClasse}"><strong>${mediaFinal}</strong></td>`;
                    } else {
                        let mediaFinal = (nota && nota.media_final) ? parseFloat(nota.media_final).toFixed(1) : '-';
                        html += `<td class="${corClasse}"><strong>${mediaFinal}</strong></td>`;
                    }
                }
                
                html += `<td><span class="badge ${statusCor}">${statusTexto}</span></td></tr>`;
            }
            
            html += '</tbody></table></div>';
            
            if (!podeEditar) {
                html += '<div class="alert alert-warning mt-3"><i class="fas fa-info-circle me-2"></i> As notas deste aluno só podem ser editadas após o conselho aprovar a revisão por votação.</div>';
            } else {
                html += '<div class="alert alert-info mt-3"><i class="fas fa-info-circle me-2"></i> As notas são salvas automaticamente ao sair do campo.</div>';
            }
            
            html += `<div class="row mt-4"><div class="col-12"><button class="btn btn-warning w-100" onclick="solicitarRevisaoNota(${aluno.id}, ${turmaId})"><i class="fas fa-gavel me-2"></i> Solicitar Revisão de Nota ao Conselho</button></div></div>`;
            
            $('#ficha-conteudo').html(html);
        }
        
        function mudarBimestre(bimestre) {
            renderizarFicha(fichaData, bimestre);
        }
        
        function abrirVotacao(id, alunoNome, notaAtual, notaSugerida, motivo) {
            $('#voto_solicitacao_id').val(id);
            $('#voto_aluno_nome').text(alunoNome);
            $('#voto_nota_atual').text(notaAtual);
            $('#voto_nota_sugerida').text(notaSugerida);
            $('#voto_motivo').text(motivo);
            $('#justificativa_voto').val('');
            $('#modalVotacao').modal('show');
        }
        
        function registrarVoto() {
            var solicitacaoId = $('#voto_solicitacao_id').val();
            var voto = $('input[name="voto"]:checked').val();
            var justificativa = $('#justificativa_voto').val();
            
            if (!voto) {
                Swal.fire('Atenção!', 'Selecione um voto!', 'warning');
                return;
            }
            
            Swal.fire({ title: 'Processando...', text: 'Registrando seu voto', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: { acao: 'registrar_voto', solicitacao_id: solicitacaoId, voto: voto, justificativa: justificativa },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#modalVotacao').modal('hide');
                        Swal.fire('Sucesso!', response.message, 'success');
                        if (response.resultado) {
                            Swal.fire({ title: 'Resultado da Votação!', text: response.resultado === 'aprovado' ? '✅ SOLICITAÇÃO APROVADA! A nota foi atualizada.' : '❌ SOLICITAÇÃO REPROVADA! A nota permanece a mesma.', icon: response.resultado === 'aprovado' ? 'success' : 'error' });
                        }
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        Swal.fire('Erro!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Erro!', 'Erro ao registrar voto.', 'error');
                }
            });
        }
        
        function atualizarNotaCampo(estudanteId, disciplinaId, bimestre, campo, valor, turmaId, anoLetivoId) {
            if (valor === '' || valor === null) valor = 0;
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: { atualizar_nota: 1, estudante_id: estudanteId, disciplina_id: disciplinaId, bimestre: bimestre, campo: campo, valor: valor, turma_id: turmaId, ano_letivo_id: anoLetivoId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        abrirFichaAluno(fichaData.aluno.matricula_id);
                        Swal.fire('Sucesso!', response.message, 'success');
                    } else {
                        Swal.fire('Erro!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Erro!', 'Erro ao salvar nota', 'error');
                }
            });
        }
        
        function solicitarRevisaoNota(estudanteId, turmaId) {
            if (!fichaData || !fichaData.disciplinas) {
                Swal.fire('Erro!', 'Dados do aluno não carregados.', 'error');
                return;
            }
            
            let disciplinasArray = [...fichaData.disciplinas];
            disciplinasArray.sort(function(a, b) { return a.nome.localeCompare(b.nome); });
            
            let disciplinasHtml = '<option value="">Selecione...</option>';
            for (let disc of disciplinasArray) {
                disciplinasHtml += `<option value="${disc.id}">${disc.nome}</option>`;
            }
            
            Swal.fire({
                title: 'Solicitar Revisão de Nota',
                html: `<div class="text-start">
                    <div class="mb-3"><label class="form-label">Aluno: <strong>${fichaData.aluno.nome}</strong></label></div>
                    <div class="mb-3"><label class="form-label">Disciplina *</label><select id="disciplina_revisao" class="form-select">${disciplinasHtml}</select></div>
                    <div class="mb-3"><label class="form-label">Bimestre *</label><select id="bimestre_revisao" class="form-select"><option value="">Selecione...</option><option value="1">1º Bimestre</option><option value="2">2º Bimestre</option><option value="3">3º Bimestre</option></select></div>
                    <div class="mb-3"><label class="form-label">Nota Atual</label><input type="number" id="nota_atual_revisao" class="form-control" step="0.5" readonly></div>
                    <div class="mb-3"><label class="form-label">Nota Sugerida *</label><input type="number" id="nota_sugerida_revisao" class="form-control" step="0.5" min="0" max="${fichaData.escala_max}" required></div>
                    <div class="mb-3"><label class="form-label">Motivo da Solicitação *</label><select id="motivo_revisao" class="form-select"><option value="">Selecione...</option><option value="Erro de Lançamento">📝 Erro de Lançamento</option><option value="Prova de Recuperação">📚 Prova de Recuperação</option><option value="Trabalho Complementar">📄 Trabalho Complementar</option><option value="Atividade Extraclasse">⭐ Atividade Extraclasse</option><option value="Outros">📌 Outros</option></select></div>
                    <div class="mb-3"><label class="form-label">Justificativa (opcional)</label><textarea id="justificativa_revisao" class="form-control" rows="3" placeholder="Descreva os motivos da revisão (opcional)..."></textarea></div>
                </div>`,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'Enviar Solicitação',
                cancelButtonText: 'Cancelar',
                preConfirm: () => {
                    let disciplinaId = document.getElementById('disciplina_revisao').value;
                    let bimestre = document.getElementById('bimestre_revisao').value;
                    let notaSugerida = document.getElementById('nota_sugerida_revisao').value;
                    let motivo = document.getElementById('motivo_revisao').value;
                    
                    if (!disciplinaId) { Swal.showValidationMessage('Selecione a disciplina'); return false; }
                    if (!bimestre) { Swal.showValidationMessage('Selecione o bimestre'); return false; }
                    if (!notaSugerida || notaSugerida <= 0) { Swal.showValidationMessage('Informe uma nota sugerida válida'); return false; }
                    if (parseFloat(notaSugerida) > fichaData.escala_max) { Swal.showValidationMessage(`Nota sugerida não pode ultrapassar ${fichaData.escala_max}`); return false; }
                    if (!motivo) { Swal.showValidationMessage('Selecione o motivo'); return false; }
                    
                    let justificativa = document.getElementById('justificativa_revisao').value;
                    return { disciplinaId, bimestre, notaSugerida, motivo, justificativa };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    enviarSolicitacaoRevisao(estudanteId, result.value.disciplinaId, result.value.bimestre, result.value.notaSugerida, result.value.motivo, result.value.justificativa, turmaId);
                }
            });
            
            setTimeout(() => {
                function carregarNotaAtual() {
                    let disciplinaId = $('#disciplina_revisao').val();
                    let bimestre = $('#bimestre_revisao').val();
                    if (disciplinaId && bimestre) {
                        let notaAtual = fichaData.notas[disciplinaId]?.[bimestre]?.media_final || fichaData.notas[disciplinaId]?.[bimestre]?.media_parcial || 0;
                        $('#nota_atual_revisao').val(parseFloat(notaAtual).toFixed(1));
                    }
                }
                $('#disciplina_revisao').off('change').on('change', carregarNotaAtual);
                $('#bimestre_revisao').off('change').on('change', carregarNotaAtual);
                setTimeout(carregarNotaAtual, 100);
            }, 100);
        }
        
        function enviarSolicitacaoRevisao(estudanteId, disciplinaId, bimestre, notaSugerida, motivo, justificativa, turmaId) {
            Swal.fire({ title: 'Enviando...', text: 'Aguarde', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: { acao: 'criar_solicitacao', estudante_id: estudanteId, disciplina_id: disciplinaId, bimestre: bimestre, nota_sugerida: notaSugerida, motivo: motivo, justificativa: justificativa, turma_id: turmaId },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire('✅ Sucesso!', response.message, 'success').then(() => { location.reload(); });
                    } else {
                        Swal.fire('❌ Erro!', response.error || 'Erro ao enviar', 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('❌ Erro!', 'Erro de conexão', 'error');
                }
            });
        }
        
        $(document).on('keyup', '[id^=filtro_nome_]', function() {
            let id = $(this).attr('id').split('_').pop();
            if (alunosData[id]) renderizarAlunos(parseInt(id));
        });
        
        $(document).on('change', '[id^=filtro_status_]', function() {
            let id = $(this).attr('id').split('_').pop();
            if (alunosData[id]) renderizarAlunos(parseInt(id));
        });
        
        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.sessao-card, .solicitacoes-container, .alert-info').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>