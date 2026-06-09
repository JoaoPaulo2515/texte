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
        cns.turma_id,
        cns.disciplina_id,
        cns.bimestre,
        t.nome as turma_nome,
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
// FUNÇÃO PARA BUSCAR ALUNOS (USANDO CAMPOS CORRETOS)
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
    t.ano,
    esc.nome as escola,
    d.nome as disciplina,
    n.bimestre,
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
INNER JOIN disciplinas d ON d.id = :disciplina_id
LEFT JOIN notas n ON n.estudante_id = e.id 
    AND n.disciplina_id = d.id
    AND n.bimestre = :bimestre
    AND n.ano_letivo_id = m.ano_letivo
WHERE m.turma_id = :turma_id
    AND t.escola_id = :escola_id
    AND m.status = 'ativa'
    AND m.ano_letivo = :ano_letivo_id
ORDER BY e.nome";
    
    try {
        $stmt_alunos = $conn->prepare($sql_alunos);
        $stmt_alunos->execute([
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
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
            t.nome as turma_nome,
            t.ano
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        INNER JOIN turmas t ON t.id = m.turma_id
        WHERE m.id = :matricula_id
    ";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([':matricula_id' => $matricula_id]);
    $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    // Buscar notas do aluno (todas disciplinas e bimestres)
    $sql_notas = "
        SELECT 
            n.bimestre,
            n.media_parcial,
            n.media_final,
            n.npt,
            n.mac,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.status as nota_status,
            d.nome as disciplina_nome,
            d.id as disciplina_id
        FROM notas n
        INNER JOIN disciplinas d ON d.id = n.disciplina_id
        WHERE n.estudante_id = :estudante_id 
        AND n.ano_letivo_id = :ano_letivo_id
        ORDER BY n.bimestre, d.nome
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':estudante_id' => $aluno['id'],
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas
    $sql_disciplinas = "SELECT id, nome FROM disciplinas ORDER BY nome";
    $disciplinas = $conn->query($sql_disciplinas)->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'aluno' => $aluno,
        'notas' => $notas,
        'disciplinas' => $disciplinas,
        'bimestres' => [1, 2, 3, 4]
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
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
        .modal-ficha { max-width: 800px; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #006B3E; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
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
                let matchNome = filtroNome === '' || aluno.estudante_nome.toLowerCase().includes(filtroNome);
                let matchStatus = filtroStatus === '' || aluno.status_aluno === filtroStatus;
                return matchNome && matchStatus;
            });
            
            if (filtered.length === 0) {
                container.html('<div class="alert alert-warning">Nenhum aluno encontrado.</div>');
                return;
            }
            
            let html = '';
            for (let aluno of filtered) {
                let notaAtual = parseFloat(aluno.nota_atual).toFixed(1);
                let statusClass = aluno.status_aluno === 'Aprovado' ? 'badge-aprovado' : (aluno.status_aluno === 'Reprovado' ? 'badge-reprovado' : 'badge-recuperacao');
                
                html += `
                    <div class="aluno-card" onclick="abrirFichaAluno(${aluno.matricula_id})">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="aluno-foto bg-secondary d-flex align-items-center justify-content-center">
                                    <i class="fas fa-user fa-2x text-white"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h6 class="mb-0">${aluno.estudante_nome}</h6>
                                <small class="text-muted">Processo: ${aluno.numero_matricula || '-'}</small>
                                <br>
                                <small>Nota: <strong>${notaAtual}</strong></small>
                                <br>
                                <span class="badge ${statusClass}">${aluno.status_aluno}</span>
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
            $('#ficha-conteudo').html('<div class="text-center p-5"><div class="spinner"></div><p>Carregando...</p></div>');
            $('#modalFichaAluno').modal('show');
            
            $.ajax({
                url: 'conselho_nota.php',
                method: 'GET',
                data: { ficha_aluno: 1, matricula_id: matriculaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderizarFicha(response);
                    } else {
                        $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar ficha.</div>');
                    }
                },
                error: function() {
                    $('#ficha-conteudo').html('<div class="alert alert-danger">Erro ao carregar dados do aluno.</div>');
                }
            });
        }
        
        function renderizarFicha(data) {
            let aluno = data.aluno;
            let notas = data.notas;
            let bimestres = data.bimestres;
            
            if (!aluno) {
                $('#ficha-conteudo').html('<div class="alert alert-danger">Aluno não encontrado.</div>');
                return;
            }
            
            // Organizar notas por bimestre
            let notasPorBimestre = {};
            for (let bim of bimestres) {
                notasPorBimestre[bim] = [];
            }
            
            for (let nota of notas) {
                if (notasPorBimestre[nota.bimestre]) {
                    notasPorBimestre[nota.bimestre].push(nota);
                }
            }
            
            let html = `
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="ficha-foto bg-secondary d-flex align-items-center justify-content-center mx-auto" style="width:120px;height:120px;border-radius:50%">
                            <i class="fas fa-user fa-4x text-white"></i>
                        </div>
                        <h5 class="mt-2">${aluno.nome}</h5>
                        <p class="text-muted">Processo: ${aluno.numero_processo || '-'}</p>
                        <hr>
                        <p><i class="fas fa-id-card"></i> <strong>BI:</strong> ${aluno.bi || '-'}</p>
                        <p><i class="fas fa-calendar"></i> <strong>Nascimento:</strong> ${aluno.data_nascimento ? new Date(aluno.data_nascimento).toLocaleDateString('pt-BR') : '-'}</p>
                        <p><i class="fas fa-school"></i> <strong>Turma:</strong> ${aluno.turma_nome} - ${aluno.ano || ''}</p>
                    </div>
                    <div class="col-md-8">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Disciplina</th>
                                        ${bimestres.map(b => `<th class="text-center">${b}º Bim</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            for (let disc of data.disciplinas) {
                html += `<tr><td><strong>${disc.nome}</strong></td>`;
                for (let bim of bimestres) {
                    let notaEncontrada = notasPorBimestre[bim].find(n => n.disciplina_id === disc.id);
                    let notaValor = notaEncontrada ? parseFloat(notaEncontrada.media_parcial || notaEncontrada.media_final || 0).toFixed(1) : '-';
                    let bgColor = notaValor !== '-' && parseFloat(notaValor) >= 10 ? '#d4edda' : (notaValor !== '-' && parseFloat(notaValor) >= 7 ? '#fff3cd' : '#f8d7da');
                    html += `<td class="text-center" style="background:${bgColor}">${notaValor}</td>`;
                }
                html += `</tr>`;
            }
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            $('#ficha-conteudo').html(html);
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