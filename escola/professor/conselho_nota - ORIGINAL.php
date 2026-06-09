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
        'disciplina_selecionada' => 0
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ============================================
// FUNÇÃO PARA ATUALIZAR NOTA (AJAX)
// ============================================
// ============================================
// FUNÇÃO PARA ATUALIZAR NOTA (AJAX) - CORRIGIDA com professor_id
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_nota'])) {
    $estudante_id = (int)$_POST['estudante_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $campo = $_POST['campo'];
    $valor = $_POST['valor'];
    $turma_id = (int)$_POST['turma_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $professor_id = $funcionario_id; // Usar o ID do funcionário logado
    
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
            // Atualizar registro existente
            $sql = "UPDATE notas SET $campo = :valor WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt = $conn->prepare($sql);
        } else {
            // Inserir novo registro com professor_id
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
        
        // Atualizar média parcial (MAC + NPT) / 2
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
// PROCESSAR VOTAÇÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['votar'])) {
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
                           votos_contra = (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = :id AND voto = 'contra')
                       WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $solicitacao_id]);
        
        $conn->commit();
        $success = "✅ Voto registrado com sucesso!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
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
        .modal-ficha { max-width: 900px; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #006B3E; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
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
        .disciplina-row {
            transition: all 0.2s;
        }
        .disciplina-row:hover {
            background-color: #f8f9fa;
        }
        .total-row {
            background-color: #e8f5e9;
            font-weight: bold;
        }
        .table-notas th, .table-notas td {
            vertical-align: middle;
            text-align: center;
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
                    <a href="dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
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
                                <button class="btn btn-primary w-100" onclick="carregarAlunos(<?php echo $sessao['id']; ?>, <?php echo $sessao['turma_id']; ?>, <?php echo $sessao['disciplina_id']; ?>, <?php echo $sessao['escola_id']; ?>, <?php echo $sessao['ano_letivo_id']; ?>)">
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
                <div class="modal-header" style="background: #17a2b8; color: white;">
                    <h5 class="modal-title"><i class="fas fa-vote-yea"></i> Votação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="solicitacao_id" id="voto_solicitacao_id">
                        <p><strong>Aluno:</strong> <span id="voto_aluno_nome"></span></p>
                        <p><strong>Nota Atual:</strong> <span id="voto_nota_atual"></span> → <strong>Sugerida:</strong> <span id="voto_nota_sugerida"></span></p>
                        <div class="mb-3">
                            <label>Seu Voto *</label>
                            <select name="voto" class="form-select" required>
                                <option value="favoravel">✅ Favorável</option>
                                <option value="contra">❌ Contra</option>
                                <option value="abstencao">⏸️ Abstenção</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Justificativa</label>
                            <textarea name="justificativa" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="votar" class="btn btn-primary">Votar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let alunosData = {};
        let fichaData = {};
        
        function toggleSessao(id) {
            $('#sessao-' + id).toggleClass('active');
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
                        $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar ficha.</div>');
                    }
                },
                error: function() {
                    $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar dados do aluno.</div>');
                }
            });
        }
        
        function renderizarFicha(data,bimestreAtivo) {
            let aluno = data.aluno;
            let disciplinas = data.disciplinas;
            let notas = data.notas;
            let escalaMax = data.escala_max;
            let escalaAprovacao = data.escala_aprovacao;
            let serie = data.ano;
            let turmaId = data.turma_id;
            let anoLetivoId = <?php echo $ano_letivo_id; ?>;
            
            if (!aluno) {
                $('#ficha-conteudo').html('<div class="alert alert-danger">Aluno não encontrado.</div>');
                return;
            }
            
            // Determinar quais campos exibir por bimestre
            let ehClasseExame = (serie == 6 || serie == 9 || serie == 12);
            
            // Navegação por bimestre (apenas 1, 2, 3)
            let bimestres = [1, 2, 3];
            
            let navHtml = '<div class="nav-bimestre">';
            for (let bim of bimestres) {
                let activeClass = (bim === bimestreAtivo) ? 'active' : '';
                navHtml += `<button class="btn-bimestre ${activeClass}" onclick="mudarBimestre(${bim})">${bim}º Bimestre</button>`;
            }
            navHtml += '</div>';
            
            // Informações do aluno
            let fotoHtml = aluno.foto ? 
                `<img src="../uploads/alunos/${aluno.foto}" class="ficha-foto" style="width:120px;height:120px;border-radius:50%;object-fit:cover;">` :
                `<div class="bg-secondary d-flex align-items-center justify-content-center mx-auto rounded-circle" style="width:120px;height:120px;">
                    <i class="fas fa-user fa-4x text-white"></i>
                </div>`;
            
            let html = `
                <div class="row mb-3">
                    <div class="col-md-12">
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
                        <p><i class="fas fa-calendar"></i> <strong>Nascimento:</strong> ${aluno.data_nascimento ? new Date(aluno.data_nascimento).toLocaleDateString('pt-BR') : '-'}</p>
                        <p><i class="fas fa-school"></i> <strong>Turma:</strong> ${aluno.turma_nome} - ${aluno.turma_ano || aluno.turma_ano || ''}</p>
                        <p><i class="fas fa-chart-line"></i> <strong>Escala:</strong> 0 a ${escalaMax}</p>
                    </div>
                    <div class="col-md-9">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm table-notas">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="25%">Disciplina</th>
            `;
            
            // Cabeçalho dos campos
            if (bimestreAtivo == 1 || bimestreAtivo == 2) {
                html += '<th width="15%">MAC (0-20)</th><th width="15%">NPT (0-20)</th><th width="15%">Média Parcial</th>';
            } else if (bimestreAtivo == 3) {
                if (ehClasseExame) {
                    html += '<th width="12%">MAC</th><th width="12%">NPT</th><th width="12%">Normal</th>';
                    // Verificar se é Português ou Inglês para exame oral/escrito
                    let disciplinaSelecionada = data.disciplina_selecionada || 0;
                    html += '<th width="12%">Exame Final</th>';
                } else {
                    html += '<th width="15%">MAC</th><th width="15%">NPT</th><th width="20%">Média Final</th>';
                }
            }
            html += '<th width="15%">Status</th></tr></thead><tbody>';
            
            // Para cada disciplina
            for (let disc of disciplinas) {
                let nota = (notas[disc.id] && notas[disc.id][bimestreAtivo]) ? notas[disc.id][bimestreAtivo] : null;
                let notaValor = nota ? parseFloat(nota.media_parcial || nota.media_final || 0).toFixed(1) : '-';
                let corClasse = '';
                
                if (notaValor !== '-') {
                    let valor = parseFloat(notaValor);
                    let percentual = (valor / escalaMax) * 100;
                    if (percentual >= 70) corClasse = 'nota-alta';
                    else if (percentual >= 50) corClasse = 'nota-media';
                    else corClasse = 'nota-baixa';
                }
                
                let statusTexto = '-';
                let statusCor = '';
                if (nota) {
                    if (nota.media_final) {
                        let mediaFinal = parseFloat(nota.media_final);
                        if (mediaFinal >= escalaAprovacao) {
                            statusTexto = 'Aprovado';
                            statusCor = 'badge-aprovado';
                        } else {
                            statusTexto = 'Reprovado';
                            statusCor = 'badge-reprovado';
                        }
                    } else if (nota.media_parcial) {
                        let mediaParcial = parseFloat(nota.media_parcial);
                        if (mediaParcial >= escalaAprovacao) {
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
                    // MAC
                    let macValor = nota && nota.mac ? nota.mac : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" 
                        value="${macValor}" 
                        step="0.5" min="0" max="${escalaMax}"
                        onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})"></td>`;
                    // NPT
                    let nptValor = nota && nota.npt ? nota.npt : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" 
                        value="${nptValor}" 
                        step="0.5" min="0" max="${escalaMax}"
                        onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})"></td>`;
                    // Média Parcial
                    let mediaParcial = nota && nota.media_parcial ? parseFloat(nota.media_parcial).toFixed(1) : '-';
                    html += `<td class="${corClasse}"><strong>${mediaParcial !== '-' ? mediaParcial : '-'}</strong></td>`;
                } else if (bimestreAtivo == 3) {
                    // MAC
                    let macValor = nota && nota.mac ? nota.mac : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" 
                        value="${macValor}" 
                        step="0.5" min="0" max="${escalaMax}"
                        onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'mac', this.value, ${turmaId}, ${anoLetivoId})"></td>`;
                    // NPT
                    let nptValor = nota && nota.npt ? nota.npt : '';
                    html += `<td><input type="number" class="nota-input ${corClasse}" 
                        value="${nptValor}" 
                        step="0.5" min="0" max="${escalaMax}"
                        onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'npt', this.value, ${turmaId}, ${anoLetivoId})"></td>`;
                    
                    if (ehClasseExame) {
                        // Exame Normal
                        let exameNormal = nota && nota.exame_normal ? nota.exame_normal : '';
                        html += `<td><input type="number" class="nota-input ${corClasse}" 
                            value="${exameNormal}" 
                            step="0.5" min="0" max="${escalaMax}"
                            onchange="atualizarNotaCampo(${aluno.id}, ${disc.id}, ${bimestreAtivo}, 'exame_normal', this.value, ${turmaId}, ${anoLetivoId})"></td>`;
                        // Exame Final
                        let mediaFinal = nota && nota.media_final ? parseFloat(nota.media_final).toFixed(1) : '-';
                        html += `<td class="${corClasse}"><strong>${mediaFinal !== '-' ? mediaFinal : '-'}</strong></td>`;
                    } else {
                        // Média Final
                        let mediaFinal = nota && nota.media_final ? parseFloat(nota.media_final).toFixed(1) : '-';
                        html += `<td class="${corClasse}"><strong>${mediaFinal !== '-' ? mediaFinal : '-'}</strong></td>`;
                    }
                }
                
                html += `<td><span class="badge ${statusCor}">${statusTexto}</span></td>
                </tr>`;
            }
            
            html += '</tbody></table></div>';
            html += '<div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> As notas são salvas automaticamente ao sair do campo.</div>';
            html += '</div></div>';
            
            $('#ficha-conteudo').html(html);
        }
        
        function mudarBimestre(bimestre) {
            renderizarFicha(fichaData, bimestre);
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
                        // Recarregar a ficha para atualizar as médias
                        abrirFichaAluno(fichaData.aluno.matricula_id);
                        showToast(response.message, 'success');
                    } else {
                        showToast(response.error, 'error');
                    }
                },
                error: function() {
                    showToast('Erro ao salvar nota', 'error');
                }
            });
        }
        
        function showToast(message, type) {
            let toastHtml = `<div class="toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0 position-fixed bottom-0 end-0 m-3" style="z-index: 9999;">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`;
            $('body').append(toastHtml);
            let toast = new bootstrap.Toast($('.toast').last()[0]);
            toast.show();
            setTimeout(() => $('.toast').last().remove(), 3000);
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