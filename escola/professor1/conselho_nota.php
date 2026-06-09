<?php
// escola/professor/conselho_nota.php - Conselho de Nota

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
// ============================================
// INICIALIZAR VARIÁVEIS (CORREÇÃO)
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
        t.ano,
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
    INNER JOIN turmas t ON t.id = d.id
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
            t.ano,
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
            AND m.ano_letivo = $ano_atual
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
// FUNÇÃO PARA BUSCAR FICHA DO ALUNO (ATUALIZADA)
// ============================================
if (isset($_GET['ficha_aluno']) && isset($_GET['matricula_id'])) {
    $matricula_id = (int)$_GET['matricula_id'];
    
    // Buscar dados do aluno e sua turma
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
    
    // Buscar todas as disciplinas
    $sql_disciplinas = "SELECT id, nome FROM disciplinas ORDER BY nome";
    $disciplinas = $conn->query($sql_disciplinas)->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar notas do aluno por disciplina e bimestre
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
    
    // Organizar notas por disciplina e bimestre
    $notas_organizadas = [];
    foreach ($notas as $nota) {
        $notas_organizadas[$nota['disciplina_id']][$nota['bimestre']] = $nota;
    }
    
    // Buscar matrícula do aluno para saber a série
    $sql_matricula = "SELECT ano FROM turmas WHERE id = (SELECT turma_id FROM matriculas WHERE id = :matricula_id)";
    $stmt_matricula = $conn->prepare($sql_matricula);
    $stmt_matricula->execute([':matricula_id' => $matricula_id]);
    $turma_info = $stmt_matricula->fetch(PDO::FETCH_ASSOC);
    $serie_aluno = $turma_info ? $turma_info['ano'] : 0;
    
    // Determinar escala de notas (0-10 para até 6º ano, 0-20 para superior)
    $escala_max = ($serie_aluno <= 6) ? 10 : 20;
    $escala_aprovacao = ($serie_aluno <= 6) ? 5 : 10;
    
    // Verificar se o aluno tem votos suficientes para editar notas
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
        'votacao' => $votacao
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
        
        // Verificar se já votou
        $sql_check = "SELECT id FROM conselho_nota_votos WHERE solicitacao_id = :solicitacao_id AND funcionario_id = :funcionario_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':solicitacao_id' => $solicitacao_id,
            ':funcionario_id' => $funcionario_id
        ]);
        
        if ($stmt_check->rowCount() > 0) {
            throw new Exception("Você já votou nesta solicitação.");
        }
        
        // Registrar voto
        $sql_voto = "INSERT INTO conselho_nota_votos (solicitacao_id, funcionario_id, voto, justificativa) 
                     VALUES (:solicitacao_id, :funcionario_id, :voto, :justificativa)";
        $stmt_voto = $conn->prepare($sql_voto);
        $stmt_voto->execute([
            ':solicitacao_id' => $solicitacao_id,
            ':funcionario_id' => $funcionario_id,
            ':voto' => $voto,
            ':justificativa' => $justificativa
        ]);
        
        // Atualizar contagem
        $sql_update = "UPDATE conselho_nota_solicitacoes 
                       SET votos_favoraveis = (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = :id AND voto = 'favoravel'),
                           votos_contra = (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = :id AND voto = 'contra'),
                           status = 'em_votacao'
                       WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $solicitacao_id]);
        
        // Verificar maioria (3 votos ou mais)
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
            
            // Se aprovado, atualizar a nota do aluno
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
    
    // Validar campos permitidos
    $campos_permitidos = ['mac', 'npt', 'exame_normal', 'exame_recurso', 'exame_especial', 'exame_oral', 'exame_escrito'];
    if (!in_array($campo, $campos_permitidos)) {
        echo json_encode(['success' => false, 'error' => 'Campo não permitido']);
        exit;
    }
    
    try {
        // Verificar se já existe registro
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
        
        // Atualizar média parcial
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
// CRIAR SOLICITAÇÃO DE REVISÃO (CORRIGIDO)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'criar_solicitacao') {
    
    // Definir cabeçalho JSON primeiro
    header('Content-Type: application/json');
    
    // Verificar se os parâmetros obrigatórios existem
    if (!isset($_POST['estudante_id']) || !isset($_POST['disciplina_id']) || !isset($_POST['bimestre'])) {
        echo json_encode(['success' => false, 'error' => 'Parâmetros incompletos.']);
        exit;
    }
    
    $estudante_id = (int)$_POST['estudante_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $nota_sugerida = (float)$_POST['nota_sugerida'];
    $motivo = $_POST['motivo'];
    $justificativa = "Notas";
    //$justificativa = $_POST['justificativa'];
    $turma_id = (int)$_POST['turma_id'];
    
    // Validar dados
    if ($estudante_id <= 0 || $disciplina_id <= 0 || $bimestre <= 0 || $turma_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
        exit;
    }
    
    if (empty($motivo) || empty($justificativa)) {
        echo json_encode(['success' => false, 'error' => 'Motivo e justificativa são obrigatórios.']);
        exit;
    }
    
    try {
        // Buscar nota atual do aluno
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
        
        // Buscar sessão ativa para esta turma/disciplina/bimestre
        $sql_sessao = "SELECT id FROM conselho_nota_sessoes WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND status IN ('agendado', 'em_andamento') LIMIT 1";
        $stmt_sessao = $conn->prepare($sql_sessao);
        $stmt_sessao->execute([
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre
        ]);
        $sessao = $stmt_sessao->fetch(PDO::FETCH_ASSOC);
        
        if (!$sessao) {
            echo json_encode(['success' => false, 'error' => 'Não há sessão do conselho ativa para esta turma/disciplina neste bimestre. Solicite ao coordenador para criar uma sessão.']);
            exit;
        }
        
        $sessao_id = $sessao['id'];
        
        // Verificar se já existe solicitação para este aluno
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
        
        // Buscar matricula_id
        $sql_matricula = "SELECT id FROM matriculas WHERE estudante_id = :estudante_id AND turma_id = :turma_id LIMIT 1";
        $stmt_matricula = $conn->prepare($sql_matricula);
        $stmt_matricula->execute([':estudante_id' => $estudante_id, ':turma_id' => $turma_id]);
        $matricula = $stmt_matricula->fetch(PDO::FETCH_ASSOC);
        $matricula_id = $matricula['id'] ?? 0;
        
        if ($matricula_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Matrícula do aluno não encontrada.']);
            exit;
        }
        
        // Criar solicitação
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conselho de Nota | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
   <style>
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .sessao-card { background: white; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .sessao-header { background: #006B3E; color: white; padding: 15px 20px; cursor: pointer; }
        .sessao-body { padding: 20px; display: none; }
        .sessao-body.active { display: block; }
        .filtro-card { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .aluno-card { background: white; border-radius: 10px; margin-bottom: 15px; padding: 15px; border-left: 4px solid #006B3E; transition: transform 0.2s; cursor: pointer; }
        .aluno-card:hover { transform: translateX(5px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .aluno-foto { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; }
        .badge-aprovado { background: #28a745; color: white; }
        .badge-reprovado { background: #dc3545; color: white; }
        .badge-recuperacao { background: #ffc107; color: black; }
        .badge-votacao { background: #6f42c1; color: white; }
        .modal-ficha { max-width: 900px; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #006B3E; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .solicitacao-item {
            background: #fff8e7;
            border-left: 4px solid #ffc107;
            transition: all 0.3s;
        }
        .solicitacao-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .btn-votar {
            background: #6f42c1;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 13px;
        }
        .btn-votar:hover {
            background: #5a32a3;
        }
        
        /* Botão Mostrar/Ocultar Solicitações */
        .btn-toggle-solicitacoes {
            background: #006B3E;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-toggle-solicitacoes:hover {
            background: #004d2d;
            transform: scale(1.02);
        }
        
        /* Botão Ajuda */
        .btn-ajuda-conselho {
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 8px 25px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-ajuda-conselho:hover {
            background: #138496;
            transform: scale(1.02);
        }
        
        /* Estilos do Modal de Ajuda */
        .modal-help-icon {
            color: #006B3E;
            margin-right: 10px;
        }
        .help-section {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        .help-section:last-child {
            border-bottom: none;
        }
        .help-title {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        .help-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .help-number {
            width: 30px;
            height: 30px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .help-content {
            flex: 1;
        }
        .help-content h6 {
            margin-bottom: 5px;
            color: #006B3E;
        }
        .help-content p {
            margin-bottom: 0;
            font-size: 13px;
            color: #666;
        }
        .badge-voto {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-favoravel { background: #d4edda; color: #155724; }
        .badge-contra { background: #f8d7da; color: #721c24; }
        .badge-abstencao { background: #e2e3e5; color: #383d41; }
        
        /* Estilos para a ficha do aluno */
        .nota-input {
            width: 70px;
            text-align: center;
            padding: 5px;
            border-radius: 5px;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }
        .nota-input:focus {
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 5px rgba(0,107,62,0.3);
        }
        .nota-input:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        .nota-aprovada { background-color: #d4edda; color: #155724; }
        .nota-reprovada { background-color: #f8d7da; color: #721c24; }
        .nota-recuperacao { background-color: #fff3cd; color: #856404; }
        .nota-baixa { background-color: #f8d7da; color: #721c24; }
        .nota-media { background-color: #fff3cd; color: #856404; }
        .nota-alta { background-color: #d4edda; color: #155724; }
        
        .nav-bimestre {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
        }
        .btn-bimestre {
            padding: 8px 20px;
            border-radius: 25px;
            background: #f8f9fa;
            color: #333;
            border: 1px solid #dee2e6;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-bimestre.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
        }
        .btn-bimestre:hover {
            transform: translateY(-2px);
        }
        
        .info-editavel {
            background: #e8f5e9;
            border-left: 4px solid #28a745;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .info-bloqueado {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        
        .solicitacoes-container.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chalkboard-teacher"></i> Conselho de Nota</h2>
                    <p>Participe das sessões do conselho e analise as notas dos alunos</p>
                    <small><i class="fas fa-user-check"></i> Você tem permissão para participar do conselho</small>
                </div>
                <div>
                    <button type="button" class="btn-ajuda-conselho me-2" data-bs-toggle="modal" data-bs-target="#modalAjudaConselho">
                        <i class="fas fa-question-circle"></i> Ajuda
                    </button>
                    <a href="dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
            </div>
        </div>
        
        <!-- Seção de Solicitações Pendentes de Votação (OCULTA POR PADRÃO) -->
        <?php if (!empty($solicitacoes_pendentes)): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-gavel"></i> Solicitações Pendentes de Votação
                    <span class="badge bg-dark ms-2"><?php echo count($solicitacoes_pendentes); ?> pendentes</span>
                </div>
                <button class="btn-toggle-solicitacoes" onclick="toggleSolicitacoes()">
                    <i class="fas fa-eye" id="toggleIcon"></i> <span id="toggleText">Mostrar</span>
                </button>
            </div>
            <div class="card-body solicitacoes-container hidden" id="solicitacoesContainer">
                <?php foreach ($solicitacoes_pendentes as $solic): ?>
                <div class="solicitacao-item mb-3 p-3 border rounded">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <strong><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($solic['aluno_nome']); ?></strong><br>
                            <small class="text-muted">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($solic['disciplina_nome']); ?> - 
                                <i class="fas fa-layer-group"></i> <?php echo $solic['bimestre']; ?>º Bimestre<br>
                                <i class="fas fa-chart-line"></i> Nota atual: <strong><?php echo $solic['nota_atual']; ?></strong> → 
                                Nota sugerida: <strong><?php echo $solic['nota_sugerida']; ?></strong><br>
                                <i class="fas fa-comment"></i> Motivo: <?php echo htmlspecialchars($solic['motivo']); ?>
                            </small>
                        </div>
                        <div class="col-md-3 text-center">
                            <span class="badge bg-secondary">
                                <i class="fas fa-chart-bar"></i> Votos: 
                                <span class="text-success"><?php echo $solic['votos_favoraveis']; ?> ✅</span> / 
                                <span class="text-danger"><?php echo $solic['votos_contra']; ?> ❌</span>
                            </span>
                        </div>
                        <div class="col-md-2 text-end">
                            <button class="btn btn-votar" onclick="abrirVotacao(<?php echo $solic['id']; ?>, '<?php echo addslashes($solic['aluno_nome']); ?>', <?php echo $solic['nota_atual']; ?>, <?php echo $solic['nota_sugerida']; ?>, '<?php echo addslashes($solic['motivo']); ?>')">
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
        <div class="modal fade" id="modalAjudaConselho" tabindex="-1" aria-labelledby="modalAjudaConselhoLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header" style="background: #006B3E; color: white;">
                        <h5 class="modal-title" id="modalAjudaConselhoLabel">
                            <i class="fas fa-question-circle"></i> Guia do Conselho de Nota
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- O que é o Conselho de Nota -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-gavel modal-help-icon"></i> O que é o Conselho de Nota?
                            </div>
                            <p>O Conselho de Nota é uma comissão pedagógica responsável por analisar e decidir sobre solicitações de revisão de notas dos alunos. O conselho é composto por professores e coordenadores que votam para aprovar ou reprovar as revisões.</p>
                        </div>
                        
                        <!-- Como Funciona o Processo de Votação -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-vote-yea modal-help-icon"></i> Como Funciona o Processo de Votação?
                            </div>
                            <div class="help-step">
                                <div class="help-number">1</div>
                                <div class="help-content">
                                    <h6>Solicitação de Revisão</h6>
                                    <p>Um professor solicita a revisão da nota de um aluno, informando a nota atual, nota sugerida, motivo e justificativa.</p>
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-number">2</div>
                                <div class="help-content">
                                    <h6>Análise pelos Professores</h6>
                                    <p>Os professores participantes do conselho analisam a solicitação e votam.</p>
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-number">3</div>
                                <div class="help-content">
                                    <h6>Tipos de Voto</h6>
                                    <p>
                                        <span class="badge-voto badge-favoravel">✅ Favorável</span> - Aprovar a revisão da nota<br>
                                        <span class="badge-voto badge-contra">❌ Contra</span> - Manter a nota atual<br>
                                        <span class="badge-voto badge-abstencao">⏸️ Abstenção</span> - Não opinar sobre a solicitação
                                    </p>
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-number">4</div>
                                <div class="help-content">
                                    <h6>Contagem de Votos</h6>
                                    <p>O sistema conta automaticamente os votos. A solicitação é aprovada quando:</p>
                                    <ul class="mt-1">
                                        <li><strong>Mínimo de 3 votos</strong> são registrados</li>
                                        <li><strong>Votos Favoráveis > Votos Contra</strong> (maioria simples)</li>
                                        <li>Abstenções não contam para a maioria</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="help-step">
                                <div class="help-number">5</div>
                                <div class="help-content">
                                    <h6>Resultado</h6>
                                    <p>Se aprovada, a nota do aluno é automaticamente atualizada. Se reprovada, a nota permanece inalterada.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Regras de Votação -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-chart-bar modal-help-icon"></i> Regras de Votação
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Situação</th>
                                            <th>Votos Favoráveis</th>
                                            <th>Votos Contra</th>
                                            <th>Resultado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td>Aprovado</td><td>2</td><td>1</td><td class="text-success">✅ APROVADO - Nota alterada</td></tr>
                                        <tr><td>Aprovado</td><td>3</td><td>0</td><td class="text-success">✅ APROVADO - Nota alterada</td></tr>
                                        <tr><td>Reprovado</td><td>1</td><td>2</td><td class="text-danger">❌ REPROVADO - Nota mantida</td></tr>
                                        <tr><td>Reprovado</td><td>1</td><td>1</td><td class="text-danger">❌ REPROVADO - Empate</td></tr>
                                        <tr><td>Pendente</td><td>1</td><td>1</td><td>⏳ Aguardando (menos de 3 votos)</td></tr>
                                        <tr><td>Pendente</td><td>2</td><td>0</td><td>⏳ Aguardando (menos de 3 votos)</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Como Votar no Sistema -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-hand-pointer modal-help-icon"></i> Como Votar no Sistema?
                            </div>
                            <ol>
                                <li>Na seção <strong>"Solicitações Pendentes de Votação"</strong>, clique no botão <strong class="text-primary">"Votar"</strong> ao lado da solicitação desejada.</li>
                                <li>Selecione seu voto: <span class="badge-voto badge-favoravel">Favorável</span>, <span class="badge-voto badge-contra">Contra</span> ou <span class="badge-voto badge-abstencao">Abstenção</span>.</li>
                                <li>Adicione uma justificativa (opcional, mas recomendada).</li>
                                <li>Clique em <strong>"Registrar Voto"</strong> para confirmar.</li>
                                <li>Acompanhe a contagem de votos na listagem de solicitações.</li>
                            </ol>
                        </div>
                        
                        <!-- Como Solicitar Revisão de Nota -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-edit modal-help-icon"></i> Como Solicitar Revisão de Nota?
                            </div>
                            <ol>
                                <li>Acesse a <strong>"Ficha do Aluno"</strong> clicando no botão <strong class="text-primary">"Analisar"</strong> na lista de alunos.</li>
                                <li>Na ficha do aluno, clique no botão <strong class="text-warning">"Solicitar Revisão de Nota ao Conselho"</strong>.</li>
                                <li>Preencha o formulário com:
                                    <ul>
                                        <li>Disciplina e bimestre</li>
                                        <li>Nota sugerida</li>
                                        <li>Motivo da solicitação</li>
                                        <li>Justificativa detalhada</li>
                                    </ul>
                                </li>
                                <li>Envie a solicitação. Ela aparecerá na seção de votação para os professores do conselho.</li>
                            </ol>
                        </div>
                        
                        <!-- Dicas Importantes -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-lightbulb modal-help-icon"></i> Dicas Importantes
                            </div>
                            <ul>
                                <li>✅ <strong>Justifique seu voto</strong> - A justificativa ajuda na transparência do processo.</li>
                                <li>✅ <strong>Seja objetivo</strong> - Analise a solicitação com base no desempenho do aluno.</li>
                                <li>✅ <strong>Respeite os prazos</strong> - As votações têm prazo para conclusão.</li>
                                <li>✅ <strong>Consulte o histórico</strong> - Verifique o histórico do aluno antes de votar.</li>
                                <li>✅ <strong>Notas editáveis</strong> - Após aprovação do conselho, as notas podem ser editadas pelo professor.</li>
                            </ul>
                        </div>
                        
                        <!-- Perguntas Frequentes -->
                        <div class="help-section">
                            <div class="help-title">
                                <i class="fas fa-question-circle modal-help-icon"></i> Perguntas Frequentes
                            </div>
                            <div class="accordion" id="faqAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq1">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                            Quantos votos são necessários para aprovar uma revisão?
                                        </button>
                                    </h2>
                                    <div id="collapse1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            São necessários no mínimo <strong>3 votos</strong> e a maioria simples de votos favoráveis (Favoráveis > Contra).
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq2">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                            O que acontece se houver empate?
                                        </button>
                                    </h2>
                                    <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Em caso de empate, a solicitação é <strong>reprovada</strong> e a nota do aluno permanece inalterada.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq3">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                            Posso alterar meu voto depois de registrar?
                                        </button>
                                    </h2>
                                    <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Não, após registrar o voto, ele não pode ser alterado. Certifique-se da sua decisão antes de votar.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq4">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                                            Quem pode solicitar revisão de nota?
                                        </button>
                                    </h2>
                                    <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            Apenas professores com permissão no conselho podem solicitar revisão de nota.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-headset"></i> <strong>Precisa de mais ajuda?</strong>
                            <p class="mb-0 mt-1">Entre em contato com o suporte técnico da sua escola ou envie um email para suporte@sigeangola.com</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                        <button type="button" class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir Ajuda
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (empty($sessoes)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle fa-2x mb-2"></i>
                <h5>Nenhuma sessão do conselho disponível</h5>
                <p>No momento não há sessões do conselho agendadas para você.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sessoes as $sessao): ?>
            <div class="sessao-card" data-sessao-id="<?php echo $sessao['id']; ?>">
                <div class="sessao-header" onclick="toggleSessao(<?php echo $sessao['id']; ?>)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-calendar-alt"></i> 
                            <strong><?php echo htmlspecialchars($sessao['titulo'] ?: $sessao['disciplina_nome']); ?></strong>
                            <br>
                            <small><?php echo htmlspecialchars($sessao['turma_nome']); ?> | 
                            <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> | 
                            <?php echo $sessao['bimestre']; ?>º Bimestre</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?php echo $sessao['status'] == 'agendado' ? 'warning' : 'info'; ?>">
                                <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                            </span>
                            <br>
                            <small>
                                <i class="fas fa-users"></i> <?php echo $sessao['total_participantes']; ?> participantes |
                                <i class="fas fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($sessao['data_sessao'] . ' ' . $sessao['hora_inicio'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
                <div class="sessao-body" id="sessao-<?php echo $sessao['id']; ?>">
                    <div class="filtro-card">
                        <div class="row">
                            <div class="col-md-4">
                                <label>Filtrar por Aluno</label>
                                <input type="text" id="filtro_nome_<?php echo $sessao['id']; ?>" class="form-control" placeholder="Digite o nome...">
                            </div>
                            <div class="col-md-4">
                                <label>Filtrar por Status</label>
                                <select id="filtro_status_<?php echo $sessao['id']; ?>" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="Aprovado">Aprovados</option>
                                    <option value="Reprovado">Reprovados</option>
                                    <option value="Recuperação">Recuperação</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>&nbsp;</label>
                                <button class="btn btn-primary w-100" onclick="carregarAlunos(<?php echo $sessao['id']; ?>, <?php echo $sessao['turma_id']; ?>, <?php echo $sessao['disciplina_id']; ?>, <?php echo $sessao['bimestre']; ?>)">
                                    <i class="fas fa-sync-alt"></i> Carregar Alunos
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="alunos-container-<?php echo $sessao['id']; ?>">
                        <div class="text-center p-5">
                            <div class="spinner"></div>
                            <p>Clique em "Carregar Alunos" para visualizar a lista...</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal Ficha do Aluno -->
    <div class="modal fade" id="modalFichaAluno" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-user-graduate"></i> Ficha do Aluno</h5>
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
                <div class="modal-header" style="background: #6f42c1; color: white;">
                    <h5 class="modal-title"><i class="fas fa-vote-yea"></i> Votação do Conselho</h5>
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
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="voto" id="voto_favoravel" value="favoravel" checked>
                                <label class="form-check-label text-success" for="voto_favoravel">
                                    <i class="fas fa-check-circle"></i> Favorável - Aprovar revisão
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="voto" id="voto_contra" value="contra">
                                <label class="form-check-label text-danger" for="voto_contra">
                                    <i class="fas fa-times-circle"></i> Contra - Manter nota
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
                        <label class="form-label">Justificativa</label>
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
                data: { 
                    ajax_alunos: 1, 
                    turma_id: turmaId, 
                    disciplina_id: disciplinaId, 
                    bimestre: bimestre 
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alunosData[sessaoId] = response.data;
                        renderizarAlunos(sessaoId);
                    } else {
                        container.html('<div class="alert alert-danger">Erro: ' + response.error + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    container.html('<div class="alert alert-danger">Erro ao carregar alunos: ' + error + '</div>');
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
                                <div class="aluno-foto bg-secondary d-flex align-items-center justify-content-center">
                                    <i class="fas fa-user fa-2x text-white"></i>
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
                                <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); abrirFichaAluno(${aluno.matricula_id})">
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
            alert(matriculaId);
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
                        $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar ficha: ' + (response.error || 'Erro desconhecido') + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro detalhado:', xhr.responseText);
                    $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar dados do aluno: ' + error + '</div>');
                }
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
    let votacao = data.votacao;
    
    if (!aluno) {
        $('#ficha-conteudo').html('<div class="alert alert-danger">Aluno não encontrado.</div>');
        return;
    }
    
    // ORDENAR DISCIPLINAS ALFABETICAMENTE
    disciplinas.sort(function(a, b) {
        return a.nome.localeCompare(b.nome);
    });
    
    let ehClasseExame = (serie == 6 || serie == 9 || serie == 12);
    let bimestres = [1, 2, 3];
    
    // Informação sobre edição
    let infoHtml = '';
    if (podeEditar) {
        infoHtml = `<div class="info-editavel mb-3">
            <i class="fas fa-check-circle text-success"></i> 
            <strong>Notas editáveis!</strong> Este aluno tem votos suficientes para revisão de nota.
            <br><small>Votos favoráveis: ${votacao.votos_favoraveis} | Total de votos: ${votacao.total_votos}</small>
        </div>`;
    } else {
        infoHtml = `<div class="info-bloqueado mb-3">
            <i class="fas fa-lock text-warning"></i> 
            <strong>Notas bloqueadas para edição.</strong> 
            Este aluno ainda não tem votos suficientes para revisão de nota.
            <br><small>Votos favoráveis: ${votacao.votos_favoraveis} | Total de votos: ${votacao.total_votos}</small>
        </div>`;
    }
    
    let navHtml = '<div class="nav-bimestre">';
    for (let bim of bimestres) {
        let activeClass = (bim === bimestreAtivo) ? 'active' : '';
        navHtml += `<button class="btn-bimestre ${activeClass}" onclick="mudarBimestre(${bim})">${bim}º Bimestre</button>`;
    }
    navHtml += '</div>';
    
    let fotoHtml = aluno.foto ? 
        `<img src="../uploads/alunos/${aluno.foto}" style="width:120px;height:120px;border-radius:50%;object-fit:cover;">` :
        `<div class="bg-secondary d-flex align-items-center justify-content-center mx-auto rounded-circle" style="width:120px;height:120px;">
            <i class="fas fa-user fa-4x text-white"></i>
        </div>`;
    
    let html = `
        <div class="row mb-3">
            <div class="col-md-12">
                ${infoHtml}
                ${navHtml}
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 text-center">
                ${fotoHtml}
                <h5 class="mt-2">${aluno.nome}</h5>
                <p class="text-muted">Processo: ${aluno.numero_processo || '-'}</p>
                <hr>
                <p><i class="fas fa-id-card"></i> <strong>BI:</strong> ${aluno.bi || '-'}</p>
                <p><i class="fas fa-school"></i> <strong>Turma:</strong> ${aluno.turma_nome} - ${aluno.turma_ano || ''}</p>
                <p><i class="fas fa-chart-line"></i> <strong>Escala:</strong> 0 a ${escalaMax}</p>
            </div>
            <div class="col-md-9">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-notas">
                        <thead class="table-dark">
                            <tr>
                                <th width="25%">Disciplina</th>`;
    
    if (bimestreAtivo == 1 || bimestreAtivo == 2) {
        html += '<th width="15%">MAC</th><th width="15%">NPT</th><th width="15%">Média Parcial</th>';
    } else if (bimestreAtivo == 3) {
        if (ehClasseExame) {
            html += '<th width="12%">MAC</th><th width="12%">NPT</th><th width="12%">Exame Normal</th><th width="12%">Média Final</th>';
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
            if (bimestreAtivo == 1 || bimestreAtivo == 2) {
                mediaValor = nota.media_parcial || 0;
            } else {
                mediaValor = nota.media_final || 0;
            }
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
        
        html += `<tr class="disciplina-row">
            <td class="text-start"><strong>${disc.nome}</strong></td>`;
        
        if (bimestreAtivo == 1 || bimestreAtivo == 2) {
            let macValor = (nota && nota.mac) ? nota.mac : '';
            html += `<td><input type="number" class="nota-input ${corClasse}" 
                value="${macValor}" step="0.5" min="0" max="${escalaMax}"
                onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})"
                ${disabledAttr}></td>`;
            
            let nptValor = (nota && nota.npt) ? nota.npt : '';
            html += `<td><input type="number" class="nota-input ${corClasse}" 
                value="${nptValor}" step="0.5" min="0" max="${escalaMax}"
                onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})"
                ${disabledAttr}></td>`;
            
            let mediaParcial = (nota && nota.media_parcial) ? parseFloat(nota.media_parcial).toFixed(1) : '-';
            html += `<td class="${corClasse}"><strong>${mediaParcial}</strong></td>`;
        } 
        else if (bimestreAtivo == 3) {
            let macValor = (nota && nota.mac) ? nota.mac : '';
            html += `<td><input type="number" class="nota-input ${corClasse}" 
                value="${macValor}" step="0.5" min="0" max="${escalaMax}"
                onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})"
                ${disabledAttr}></td>`;
            
            let nptValor = (nota && nota.npt) ? nota.npt : '';
            html += `<td><input type="number" class="nota-input ${corClasse}" 
                value="${nptValor}" step="0.5" min="0" max="${escalaMax}"
                onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})"
                ${disabledAttr}><td>`;
            
            if (ehClasseExame) {
                let exameNormal = (nota && nota.exame_normal) ? nota.exame_normal : '';
                html += `<td><input type="number" class="nota-input ${corClasse}" 
                    value="${exameNormal}" step="0.5" min="0" max="${escalaMax}"
                    onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'exame_normal', this.value, ${turmaId}, ${anoLetivoId})"
                    ${disabledAttr}></td>`;
                
                let mediaFinal = (nota && nota.media_final) ? parseFloat(nota.media_final).toFixed(1) : '-';
                html += `<td class="${corClasse}"><strong>${mediaFinal}</strong></td>`;
            } else {
                let mediaFinal = (nota && nota.media_final) ? parseFloat(nota.media_final).toFixed(1) : '-';
                html += `<td class="${corClasse}"><strong>${mediaFinal}</strong></td>`;
            }
        }
        
        html += `<td><span class="badge ${statusCor}">${statusTexto}</span></td>
        </tr>`;
    }
    
    html += '</tbody></table></div>';
    
    if (!podeEditar) {
        html += '<div class="alert alert-warning mt-3"><i class="fas fa-info-circle"></i> As notas deste aluno só podem ser editadas após o conselho aprovar a revisão por votação.</div>';
    } else {
        html += '<div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> As notas são salvas automaticamente ao sair do campo.</div>';
    }
    
    // Adicionar botão para solicitar revisão
    html += `<div class="row mt-3">
        <div class="col-12">
            <button class="btn btn-warning w-100" onclick="solicitarRevisaoNota(${aluno.id}, ${turmaId})">
                <i class="fas fa-gavel"></i> Solicitar Revisão de Nota ao Conselho
            </button>
        </div>
    </div>`;
    
    html += '</div></div>';
    
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
            $('#justificativa_voto').prop('disabled', false);
            $('#justificativa_voto').css('background-color', 'white');
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
            
            Swal.fire({
                title: 'Processando...',
                text: 'Registrando seu voto',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: {
                    acao: 'registrar_voto',
                    solicitacao_id: solicitacaoId,
                    voto: voto,
                    justificativa: justificativa
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#modalVotacao').modal('hide');
                        Swal.fire('Sucesso!', response.message, 'success');
                        if (response.resultado) {
                            Swal.fire({
                                title: 'Resultado da Votação!',
                                text: response.resultado === 'aprovado' ? '✅ SOLICITAÇÃO APROVADA! A nota foi atualizada.' : '❌ SOLICITAÇÃO REPROVADA! A nota permanece a mesma.',
                                icon: response.resultado === 'aprovado' ? 'success' : 'error'
                            });
                        }
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        Swal.fire('Erro!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Erro!', 'Erro ao registrar voto. Tente novamente.', 'error');
                }
            });
        }
        
        function atualizarNotaCampo(estudanteId, disciplinaId, bimestre, campo, valor, turmaId, anoLetivoId) {
            if (valor === '' || valor === null) valor = 0;
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: {
                    atualizar_nota: 1,
                    estudante_id: estudanteId,
                    disciplina_id: disciplinaId,
                    bimestre: bimestre,
                    campo: campo,
                    valor: valor,
                    turma_id: turmaId,
                    ano_letivo_id: anoLetivoId
                },
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
                Swal.fire('Erro!', 'Dados do aluno não carregados. Tente novamente.', 'error');
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
                html: `
                    <div class="text-start">
                        <div class="mb-3"><label class="form-label">Aluno: <strong>${fichaData.aluno.nome}</strong></label></div>
                        <div class="mb-3"><label class="form-label">Disciplina *</label><select id="disciplina_revisao" class="form-select">${disciplinasHtml}</select></div>
                        <div class="mb-3"><label class="form-label">Bimestre *</label><select id="bimestre_revisao" class="form-select"><option value="">Selecione...</option><option value="1">1º Bimestre</option><option value="2">2º Bimestre</option><option value="3">3º Bimestre</option></select></div>
                        <div class="mb-3"><label class="form-label">Nota Atual</label><input type="number" id="nota_atual_revisao" class="form-control" step="0.5" readonly></div>
                        <div class="mb-3"><label class="form-label">Nota Sugerida *</label><input type="number" id="nota_sugerida_revisao" class="form-control" step="0.5" min="0" max="${fichaData.escala_max}" required></div>
                        <div class="mb-3"><label class="form-label">Motivo da Solicitação *</label><select id="motivo_revisao" class="form-select" required><option value="">Selecione...</option><option value="Erro de Lançamento">📝 Erro de Lançamento</option><option value="Prova de Recuperação">📚 Prova de Recuperação</option><option value="Trabalho Complementar">📄 Trabalho Complementar</option><option value="Atividade Extraclasse">⭐ Atividade Extraclasse</option><option value="Participação em Olimpíadas">🏆 Participação em Olimpíadas</option><option value="Projeto Especial">🎯 Projeto Especial</option><option value="Outros">📌 Outros</option></select></div>
                        <div class="mb-3"><label class="form-label">Justificativa Detalhada *</label><textarea id="justificativa_revisao" class="form-control" rows="3" placeholder="Descreva detalhadamente os motivos da revisão..." required></textarea></div>
                    </div>
                `,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'Enviar Solicitação',
                cancelButtonText: 'Cancelar',
                preConfirm: () => {
                    let disciplinaId = document.getElementById('disciplina_revisao').value;
                    let bimestre = document.getElementById('bimestre_revisao').value;
                    let notaSugerida = document.getElementById('nota_sugerida_revisao').value;
                    let motivo = document.getElementById('motivo_revisao').value;
                    let justificativa = document.getElementById('justificativa_revisao').value;
                    
                    if (!disciplinaId) { Swal.showValidationMessage('⚠️ Selecione a disciplina'); return false; }
                    if (!bimestre) { Swal.showValidationMessage('⚠️ Selecione o bimestre'); return false; }
                    if (!notaSugerida || notaSugerida <= 0) { Swal.showValidationMessage('⚠️ Informe uma nota sugerida válida'); return false; }
                    if (parseFloat(notaSugerida) > fichaData.escala_max) { Swal.showValidationMessage(`⚠️ A nota sugerida não pode ultrapassar ${fichaData.escala_max}`); return false; }
                    if (!motivo) { Swal.showValidationMessage('⚠️ Selecione o motivo'); return false; }
                    
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
            Swal.fire({ title: 'Enviando solicitação...', text: 'Aguarde um momento', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'POST',
                data: { acao: 'criar_solicitacao', estudante_id: estudanteId, disciplina_id: disciplinaId, bimestre: bimestre, nota_sugerida: notaSugerida, motivo: motivo, justificativa: justificativa, turma_id: turmaId },
                dataType: 'json',
                timeout: 30000,
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire('✅ Solicitação Enviada!', response.message, 'success').then(() => { location.reload(); });
                    } else {
                        Swal.fire('❌ Erro!', response.error || 'Erro ao enviar solicitação.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire('❌ Erro!', 'Erro de conexão com o servidor. Tente novamente.', 'error');
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
    </script>
</body>
</html>