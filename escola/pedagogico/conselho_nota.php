<?php
// escola/pedagogico/conselho_nota.php - Conselho de Nota (Área Pedagógica)

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO (MESMA LÓGICA DO INDEX.PHP)
// ============================================
$sql_verifica = "
    SELECT f.*, 
           u.id as usuario_id,
           u.usuario,
           u.email,
           u.tipo as usuario_tipo,
           (SELECT COUNT(*) FROM conselho_nota_permissoes WHERE funcionario_id = f.id AND ativo = 1) as tem_permissao_conselho
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
    AND u.status = 'ativo'
    AND f.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];
$error = "";

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);

if (!$ano_letivo) {
    $sql_ano = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute();
    $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
}

$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SESSÕES DO CONSELHO
// ============================================
$sql_sessoes = "
    SELECT 
        cns.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        COUNT(DISTINCT cnp.funcionario_id) as total_participantes,
        COUNT(DISTINCT cnsol.id) as total_solicitacoes,
        SUM(CASE WHEN cnsol.status = 'pendente' THEN 1 ELSE 0 END) as solicitacoes_pendentes
    FROM conselho_nota_sessoes cns
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    LEFT JOIN conselho_nota_participantes cnp ON cnp.sessao_id = cns.id
    LEFT JOIN conselho_nota_solicitacoes cnsol ON cnsol.sessao_id = cns.id
    WHERE cns.ano_letivo_id = :ano_letivo_id 
    AND cns.escola_id = :escola_id
    GROUP BY cns.id
    ORDER BY cns.data_sessao DESC, cns.created_at DESC
";
$stmt_sessoes = $conn->prepare($sql_sessoes);
$stmt_sessoes->execute([
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$sessoes = $stmt_sessoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES PENDENTES
// ============================================
$sql_solicitacoes_pendentes = "
    SELECT 
        cnsol.*,
        e.nome as aluno_nome,
        e.matricula,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id) as total_votos,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'favoravel') as votos_favoraveis,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'contra') as votos_contra
    FROM conselho_nota_solicitacoes cnsol
    INNER JOIN estudantes e ON e.id = cnsol.estudante_id
    INNER JOIN turmas t ON t.id = cnsol.turma_id
    INNER JOIN disciplinas d ON d.id = cnsol.disciplina_id
    WHERE cnsol.ano_letivo_id = :ano_letivo_id
    AND cnsol.status IN ('pendente', 'em_votacao')
    ORDER BY cnsol.created_at ASC
";
$stmt_solicitacoes_pendentes = $conn->prepare($sql_solicitacoes_pendentes);
$stmt_solicitacoes_pendentes->execute([':ano_letivo_id' => $ano_letivo_id]);
$solicitacoes_pendentes = $stmt_solicitacoes_pendentes->fetchAll(PDO::FETCH_ASSOC);
$total_pendentes = count($solicitacoes_pendentes);

// ============================================
// PROCESSAR APROVAÇÃO/REPROVAÇÃO DE SOLICITAÇÃO (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_solicitacao'])) {
    $solicitacao_id = (int)$_POST['solicitacao_id'];
    $decisao = $_POST['decisao'];
    $observacoes = $_POST['observacoes'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($decisao == 'aprovado') {
            $status = 'finalizado';
            $resultado = 'aprovado';
            
            // Buscar dados da solicitação
            $sql_solic = "SELECT nota_sugerida, matricula_id, disciplina_id, bimestre FROM conselho_nota_solicitacoes WHERE id = :id";
            $stmt_solic = $conn->prepare($sql_solic);
            $stmt_solic->execute([':id' => $solicitacao_id]);
            $solic = $stmt_solic->fetch(PDO::FETCH_ASSOC);
            
            if ($solic) {
                // Atualizar nota
                $sql_update_nota = "UPDATE notas SET media_final = :nota WHERE matricula_id = :matricula_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre";
                $stmt_update_nota = $conn->prepare($sql_update_nota);
                $stmt_update_nota->execute([
                    ':nota' => $solic['nota_sugerida'],
                    ':matricula_id' => $solic['matricula_id'],
                    ':disciplina_id' => $solic['disciplina_id'],
                    ':bimestre' => $solic['bimestre']
                ]);
            }
        } else {
            $status = 'finalizado';
            $resultado = 'reprovado';
        }
        
        // Atualizar solicitação
        $sql_update = "UPDATE conselho_nota_solicitacoes SET status = :status, resultado_final = :resultado, observacoes_finais = :observacoes WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':status' => $status,
            ':resultado' => $resultado,
            ':observacoes' => $observacoes,
            ':id' => $solicitacao_id
        ]);
        
        $conn->commit();
        $success = "Solicitação finalizada com sucesso!";
        
        // Redirecionar para evitar reenvio
        header("Location: conselho_nota.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao finalizar solicitação: " . $e->getMessage();
    }
}

$success_message = isset($_GET['success']) ? "Operação realizada com sucesso!" : "";
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Conselho de Nota | Pedagógico | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --purple: #6f42c1;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

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
            content: '⚖️';
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
            position: relative;
            z-index: 1;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .card-custom {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .card-header-custom {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 20px;
            border: none;
        }

        .card-header-custom h5 {
            margin: 0;
            font-weight: 600;
        }

        .sessao-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .sessao-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .sessao-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, #004d2d 100%);
            color: white;
            padding: 15px 20px;
            cursor: pointer;
        }

        .sessao-body {
            padding: 20px;
            display: none;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }

        .sessao-body.active {
            display: block;
        }

        .solicitacao-item {
            background: linear-gradient(135deg, #fff8e7 0%, #fff3cd 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
        }

        .solicitacao-item:hover {
            transform: translateX(5px);
        }

        .badge-pendente { background: #ffc107; color: #856404; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-em_votacao { background: #17a2b8; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-finalizado { background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; }

        .btn-aprovar {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-reprovar {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-detalhes {
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-detalhes:hover, .btn-aprovar:hover, .btn-reprovar:hover {
            transform: translateY(-2px);
            color: white;
        }

        .votos-progresso {
            height: 8px;
            border-radius: 10px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-gavel me-2"></i> Conselho de Nota</h2>
                    <p>Gerencie as solicitações de revisão de notas do conselho pedagógico</p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div>
                    <a href="index.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Solicitações Pendentes -->
        <?php if (!empty($solicitacoes_pendentes)): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-clock me-2"></i> Solicitações Pendentes de Análise</h5>
                <small>Total: <?php echo $total_pendentes; ?> solicitação(ões) aguardando decisão</small>
            </div>
            <div class="card-body p-0">
                <div class="p-3">
                    <?php foreach ($solicitacoes_pendentes as $solic): 
                        $votos_favoraveis = $solic['votos_favoraveis'] ?? 0;
                        $votos_contra = $solic['votos_contra'] ?? 0;
                        $total_votos = $votos_favoraveis + $votos_contra;
                        $percentual_favor = $total_votos > 0 ? round(($votos_favoraveis / $total_votos) * 100, 0) : 0;
                    ?>
                    <div class="solicitacao-item">
                        <div class="row">
                            <div class="col-md-7">
                                <strong><i class="fas fa-user-graduate me-2"></i> <?php echo htmlspecialchars($solic['aluno_nome']); ?></strong>
                                <br>
                                <small class="text-muted">
                                    <i class="fas fa-building me-1"></i> <?php echo $solic['turma_ano']; ?>ª - <?php echo htmlspecialchars($solic['turma_nome']); ?><br>
                                    <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($solic['disciplina_nome']); ?> - <?php echo $solic['bimestre']; ?>º Bimestre<br>
                                    <i class="fas fa-chart-line me-1"></i> Nota atual: <strong><?php echo $solic['nota_atual']; ?></strong> → 
                                    Nota sugerida: <strong><?php echo $solic['nota_sugerida']; ?></strong><br>
                                    <i class="fas fa-comment me-1"></i> Motivo: <?php echo htmlspecialchars($solic['motivo']); ?>
                                </small>
                            </div>
                            <div class="col-md-3 text-center">
                                <div class="mb-2">
                                    <span class="badge <?php echo $solic['status'] == 'pendente' ? 'badge-pendente' : 'badge-em_votacao'; ?>">
                                        <?php echo $solic['status'] == 'pendente' ? 'Aguardando Votação' : 'Em Votação'; ?>
                                    </span>
                                </div>
                                <div class="mb-2">
                                    <small>Votos: <?php echo $total_votos; ?> / 3</small>
                                    <div class="progress votos-progresso">
                                        <div class="progress-bar bg-success" style="width: <?php echo $percentual_favor; ?>%"></div>
                                    </div>
                                    <small>
                                        <span class="text-success">👍 <?php echo $votos_favoraveis; ?> favoráveis</span> | 
                                        <span class="text-danger">👎 <?php echo $votos_contra; ?> contra</span>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-2 text-end">
                                <button class="btn-detalhes btn-sm w-100 mb-2" onclick="verDetalhesSolicitacao(<?php echo $solic['id']; ?>)">
                                    <i class="fas fa-eye"></i> Detalhes
                                </button>
                                <?php if ($solic['status'] == 'em_votacao' || $total_votos >= 3): ?>
                                <button class="btn-aprovar btn-sm w-100 mb-2" onclick="finalizarSolicitacao(<?php echo $solic['id']; ?>, 'aprovado')">
                                    <i class="fas fa-check"></i> Aprovar
                                </button>
                                <button class="btn-reprovar btn-sm w-100" onclick="finalizarSolicitacao(<?php echo $solic['id']; ?>, 'reprovado')">
                                    <i class="fas fa-times"></i> Reprovar
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sessões do Conselho -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-calendar-alt me-2"></i> Sessões do Conselho de Nota</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($sessoes)): ?>
                    <div class="text-center p-5 text-muted">
                        <i class="fas fa-calendar-times fa-3x mb-2"></i>
                        <p>Nenhuma sessão do conselho encontrada</p>
                        <small>As sessões serão exibidas aqui quando forem criadas</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($sessoes as $sessao): ?>
                    <div class="sessao-card">
                        <div class="sessao-header" onclick="toggleSessao(<?php echo $sessao['id']; ?>)">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <strong><?php echo htmlspecialchars($sessao['titulo'] ?: $sessao['disciplina_nome']); ?></strong>
                                    <br>
                                    <small>
                                        <i class="fas fa-building me-1"></i> <?php echo $sessao['turma_ano']; ?>ª - <?php echo htmlspecialchars($sessao['turma_nome']); ?> | 
                                        <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> | 
                                        <i class="fas fa-layer-group me-1"></i> <?php echo $sessao['bimestre']; ?>º Bimestre
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="badge <?php echo $sessao['status'] == 'agendado' ? 'badge-pendente' : 'badge-em_votacao'; ?>">
                                        <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                                    </span>
                                    <br>
                                    <small>
                                        <i class="fas fa-users me-1"></i> <?php echo $sessao['total_participantes']; ?> participantes |
                                        <i class="fas fa-gavel me-1"></i> <?php echo $sessao['solicitacoes_pendentes']; ?> pendentes
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="sessao-body" id="sessao-<?php echo $sessao['id']; ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong><i class="fas fa-info-circle me-1"></i> Detalhes da Sessão</strong></p>
                                    <p><small>Data: <?php echo date('d/m/Y', strtotime($sessao['data_sessao'])); ?> às <?php echo date('H:i', strtotime($sessao['hora_inicio'])); ?></small></p>
                                    <?php if (!empty($sessao['descricao'])): ?>
                                        <p><small><?php echo htmlspecialchars($sessao['descricao']); ?></small></p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 text-end">
                                    <a href="conselho_sessao.php?id=<?php echo $sessao['id']; ?>" class="btn btn-primary-custom btn-sm">
                                        <i class="fas fa-arrow-right"></i> Ver Detalhes da Sessão
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal de Detalhes da Solicitação -->
    <div class="modal fade" id="modalDetalhesSolicitacao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-gavel me-2"></i> Detalhes da Solicitação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalDetalhesConteudo">
                    <div class="text-center p-5"><div class="spinner-border text-success"></div><p>Carregando...</p></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Finalização -->
    <div class="modal fade" id="modalFinalizarSolicitacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-gavel me-2"></i> Finalizar Solicitação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="solicitacao_id" id="finalizar_solicitacao_id">
                        <input type="hidden" name="decisao" id="finalizar_decisao">
                        <p id="finalizar_mensagem"></p>
                        <div class="mb-3">
                            <label class="form-label">Observações Finais</label>
                            <textarea name="observacoes" class="form-control" rows="3" placeholder="Digite observações sobre esta decisão..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="finalizar_solicitacao" class="btn btn-primary">Confirmar Decisão</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSessao(id) {
            $('#sessao-' + id).toggleClass('active');
        }

        function verDetalhesSolicitacao(id) {
            $('#modalDetalhesConteudo').html('<div class="text-center p-5"><div class="spinner-border text-success"></div><p>Carregando detalhes...</p></div>');
            $('#modalDetalhesSolicitacao').modal('show');
            
            $.ajax({
                url: 'ajax_buscar_solicitacao.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let html = `
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong><i class="fas fa-user-graduate"></i> Aluno:</strong> ${response.dados.aluno_nome}</p>
                                    <p><strong><i class="fas fa-building"></i> Turma:</strong> ${response.dados.turma_ano}ª - ${response.dados.turma_nome}</p>
                                    <p><strong><i class="fas fa-book"></i> Disciplina:</strong> ${response.dados.disciplina_nome}</p>
                                    <p><strong><i class="fas fa-layer-group"></i> Bimestre:</strong> ${response.dados.bimestre}º Bimestre</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong><i class="fas fa-chart-line"></i> Nota Atual:</strong> ${response.dados.nota_atual}</p>
                                    <p><strong><i class="fas fa-arrow-up"></i> Nota Sugerida:</strong> ${response.dados.nota_sugerida}</p>
                                    <p><strong><i class="fas fa-comment"></i> Motivo:</strong> ${response.dados.motivo}</p>
                                    <p><strong><i class="fas fa-file-signature"></i> Justificativa:</strong> ${response.dados.justificativa || 'Não informada'}</p>
                                </div>
                                <div class="col-12">
                                    <hr>
                                    <p><strong><i class="fas fa-chart-bar"></i> Votação:</strong></p>
                                    <div class="progress mb-2">
                                        <div class="progress-bar bg-success" style="width: ${response.dados.percentual_favor}%"></div>
                                    </div>
                                    <p><span class="text-success">👍 ${response.dados.votos_favoraveis} favoráveis</span> | <span class="text-danger">👎 ${response.dados.votos_contra} contra</span></p>
                                </div>
                            </div>
                        `;
                        $('#modalDetalhesConteudo').html(html);
                    } else {
                        $('#modalDetalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                    }
                },
                error: function() {
                    $('#modalDetalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                }
            });
        }

        function finalizarSolicitacao(id, decisao) {
            $('#finalizar_solicitacao_id').val(id);
            $('#finalizar_decisao').val(decisao);
            let mensagem = decisao === 'aprovado' 
                ? 'Deseja realmente <strong class="text-success">APROVAR</strong> esta solicitação? A nota do aluno será alterada.'
                : 'Deseja realmente <strong class="text-danger">REPROVAR</strong> esta solicitação? A nota do aluno permanecerá a mesma.';
            $('#finalizar_mensagem').html(mensagem);
            $('#modalFinalizarSolicitacao').modal('show');
        }
    </script>
</body>
</html>