<?php
// escola/pedagogico/conselho_sessao.php - Detalhes da Sessão do Conselho de Nota

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_verifica = "
    SELECT f.*, 
           u.id as usuario_id,
           u.usuario,
           u.email,
           u.tipo as usuario_tipo
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
    include __DIR__ . '/access_denied_negado.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$sessao_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($sessao_id <= 0) {
    header('Location: conselho_nota.php');
    exit;
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DA SESSÃO
// ============================================
$sql_sessao = "
    SELECT 
        cns.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.sala,
        t.turno,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        (SELECT COUNT(*) FROM conselho_nota_participantes WHERE sessao_id = cns.id) as total_participantes,
        (SELECT COUNT(*) FROM conselho_nota_solicitacoes WHERE sessao_id = cns.id) as total_solicitacoes,
        (SELECT COUNT(*) FROM conselho_nota_solicitacoes WHERE sessao_id = cns.id AND status = 'pendente') as solicitacoes_pendentes,
        (SELECT COUNT(*) FROM conselho_nota_solicitacoes WHERE sessao_id = cns.id AND status = 'em_votacao') as solicitacoes_votacao,
        (SELECT COUNT(*) FROM conselho_nota_solicitacoes WHERE sessao_id = cns.id AND status = 'finalizado') as solicitacoes_finalizadas
    FROM conselho_nota_sessoes cns
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    WHERE cns.id = :sessao_id AND cns.escola_id = :escola_id
";
$stmt_sessao = $conn->prepare($sql_sessao);
$stmt_sessao->execute([':sessao_id' => $sessao_id, ':escola_id' => $escola_id]);
$sessao = $stmt_sessao->fetch(PDO::FETCH_ASSOC);

if (!$sessao) {
    header('Location: conselho_nota.php');
    exit;
}

// ============================================
// BUSCAR PARTICIPANTES DA SESSÃO
// ============================================
$sql_participantes = "
    SELECT 
        cnp.*,
        f.nome as funcionario_nome,
        f.cargo,
        f.email,
        f.foto
    FROM conselho_nota_participantes cnp
    INNER JOIN funcionarios f ON f.id = cnp.funcionario_id
    WHERE cnp.sessao_id = :sessao_id
    ORDER BY f.cargo DESC, f.nome ASC
";
$stmt_participantes = $conn->prepare($sql_participantes);
$stmt_participantes->execute([':sessao_id' => $sessao_id]);
$participantes = $stmt_participantes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES DA SESSÃO
// ============================================
$sql_solicitacoes = "
    SELECT 
        cnsol.*,
        e.nome as aluno_nome,
        e.matricula,
        e.foto as aluno_foto,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id) as total_votos,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'favoravel') as votos_favoraveis,
        (SELECT COUNT(*) FROM conselho_nota_votos WHERE solicitacao_id = cnsol.id AND voto = 'contra') as votos_contra
    FROM conselho_nota_solicitacoes cnsol
    INNER JOIN estudantes e ON e.id = cnsol.estudante_id
    WHERE cnsol.sessao_id = :sessao_id
    ORDER BY cnsol.status ASC, cnsol.created_at ASC
";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([':sessao_id' => $sessao_id]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DETERMINAR REGRAS DE AVALIAÇÃO
// ============================================
$classe_ano = $sessao['turma_ano'];
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
$escala_max = $is_ensino_fundamental ? 10 : 20;

// Verificar se é disciplina de língua
$disciplina_nome = $sessao['disciplina_nome'];
$is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || 
                          stripos($disciplina_nome, 'inglês') !== false);

// ============================================
// PROCESSAR VOTO (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'registrar_voto') {
    header('Content-Type: application/json');
    
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
                $sql_update_nota = "UPDATE notas SET media_final = :nota WHERE matricula_id = :matricula_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre";
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
// PROCESSAR FINALIZAÇÃO DE SOLICITAÇÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_solicitacao'])) {
    $solicitacao_id = (int)$_POST['solicitacao_id'];
    $decisao = $_POST['decisao'];
    $observacoes = $_POST['observacoes'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($decisao == 'aprovado') {
            $sql_solic = "SELECT nota_sugerida, matricula_id, disciplina_id, bimestre FROM conselho_nota_solicitacoes WHERE id = :id";
            $stmt_solic = $conn->prepare($sql_solic);
            $stmt_solic->execute([':id' => $solicitacao_id]);
            $solic = $stmt_solic->fetch(PDO::FETCH_ASSOC);
            
            if ($solic) {
                $sql_update_nota = "UPDATE notas SET media_final = :nota WHERE matricula_id = :matricula_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre";
                $stmt_update_nota = $conn->prepare($sql_update_nota);
                $stmt_update_nota->execute([
                    ':nota' => $solic['nota_sugerida'],
                    ':matricula_id' => $solic['matricula_id'],
                    ':disciplina_id' => $solic['disciplina_id'],
                    ':bimestre' => $solic['bimestre']
                ]);
            }
            $resultado = 'aprovado';
        } else {
            $resultado = 'reprovado';
        }
        
        $sql_update = "UPDATE conselho_nota_solicitacoes SET status = 'finalizado', resultado_final = :resultado, observacoes_finais = :observacoes WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':resultado' => $resultado,
            ':observacoes' => $observacoes,
            ':id' => $solicitacao_id
        ]);
        
        $conn->commit();
        header("Location: conselho_sessao.php?id=" . $sessao_id . "&success=1");
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
    <title>Sessão do Conselho | <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> | SIGE Angola</title>
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

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 15px;
            border-left: 4px solid var(--primary-green);
        }

        .info-card i {
            color: var(--primary-green);
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .info-card .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 1px;
        }

        .info-card .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .participante-item {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 30px;
            margin: 5px;
        }

        .solicitacao-table {
            width: 100%;
            border-collapse: collapse;
        }

        .solicitacao-table th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
        }

        .solicitacao-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .solicitacao-table tr:hover {
            background: #f8f9fa;
        }

        .badge-pendente { background: #ffc107; color: #856404; padding: 5px 12px; border-radius: 20px; font-size: 11px; }
        .badge-em_votacao { background: #17a2b8; color: white; padding: 5px 12px; border-radius: 20px; font-size: 11px; }
        .badge-finalizado { background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-size: 11px; }
        .badge-aprovado { background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-size: 11px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 5px 12px; border-radius: 20px; font-size: 11px; }

        .btn-votar {
            background: linear-gradient(135deg, var(--purple) 0%, #5a32a3 100%);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 15px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-votar:hover {
            transform: translateY(-2px);
            color: white;
        }

        .btn-votado {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .votos-progresso {
            height: 6px;
            border-radius: 10px;
            margin: 8px 0;
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
                    <h2><i class="fas fa-gavel me-2"></i> Sessão do Conselho de Nota</h2>
                    <p><?php echo htmlspecialchars($sessao['disciplina_nome']); ?> - <?php echo $sessao['turma_ano']; ?>ª <?php echo htmlspecialchars($sessao['turma_nome']); ?></p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div>
                    <a href="conselho_nota.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
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

        <?php if (isset($error) && $error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Informações da Sessão -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-info-circle me-2"></i> Informações da Sessão</h5>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-card">
                        <i class="fas fa-building"></i>
                        <div class="info-label">Turma</div>
                        <div class="info-value"><?php echo $sessao['turma_ano']; ?>ª - <?php echo htmlspecialchars($sessao['turma_nome']); ?></div>
                        <small class="text-muted">Sala: <?php echo $sessao['sala'] ?: 'Não definida'; ?> | Turno: <?php echo ucfirst($sessao['turno']); ?></small>
                    </div>
                    <div class="info-card">
                        <i class="fas fa-book"></i>
                        <div class="info-label">Disciplina</div>
                        <div class="info-value"><?php echo htmlspecialchars($sessao['disciplina_nome']); ?></div>
                        <small class="text-muted">Código: <?php echo $sessao['disciplina_codigo'] ?: '---'; ?></small>
                    </div>
                    <div class="info-card">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="info-label">Data e Hora</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($sessao['data_sessao'])); ?></div>
                        <small class="text-muted"><?php echo date('H:i', strtotime($sessao['hora_inicio'])); ?> - <?php echo date('H:i', strtotime($sessao['hora_fim'])); ?></small>
                    </div>
                    <div class="info-card">
                        <i class="fas fa-layer-group"></i>
                        <div class="info-label">Bimestre</div>
                        <div class="info-value"><?php echo $sessao['bimestre']; ?>º Bimestre</div>
                        <small class="text-muted">Escala: 0 a <?php echo $escala_max; ?> | Aprovação: ≥ <?php echo $limite_aprovacao; ?></small>
                    </div>
                </div>
                <?php if (!empty($sessao['descricao'])): ?>
                    <div class="alert alert-secondary mt-2">
                        <i class="fas fa-align-left me-2"></i> <?php echo htmlspecialchars($sessao['descricao']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Participantes -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-users me-2"></i> Participantes da Sessão (<?php echo count($participantes); ?>)</h5>
            </div>
            <div class="card-body">
                <?php foreach ($participantes as $participante): ?>
                <div class="participante-item">
                    <i class="fas fa-user-circle"></i>
                    <strong><?php echo htmlspecialchars($participante['funcionario_nome']); ?></strong>
                    <span class="badge bg-secondary"><?php echo ucfirst($participante['cargo']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Resumo das Solicitações -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-chart-bar me-2"></i> Resumo das Solicitações</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <h4 class="text-warning mb-0"><?php echo $sessao['solicitacoes_pendentes']; ?></h4>
                            <small>Pendentes</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <h4 class="text-info mb-0"><?php echo $sessao['solicitacoes_votacao']; ?></h4>
                            <small>Em Votação</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <h4 class="text-success mb-0"><?php echo $sessao['solicitacoes_finalizadas']; ?></h4>
                            <small>Finalizadas</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <h4 class="text-primary mb-0"><?php echo $sessao['total_solicitacoes']; ?></h4>
                            <small>Total</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista de Solicitações -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h5><i class="fas fa-list me-2"></i> Solicitações de Revisão de Nota</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="solicitacao-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Nota Atual</th>
                                <th>Nota Sugerida</th>
                                <th>Votos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($solicitacoes)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-3x mb-2"></i>
                                    <p>Nenhuma solicitação encontrada para esta sessão</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($solicitacoes as $index => $solic): 
                                    $total_votos = $solic['total_votos'] ?? 0;
                                    $votos_favoraveis = $solic['votos_favoraveis'] ?? 0;
                                    $votos_contra = $solic['votos_contra'] ?? 0;
                                    $percentual_favor = $total_votos > 0 ? round(($votos_favoraveis / $total_votos) * 100, 0) : 0;
                                    
                                    $status_class = '';
                                    $status_texto = '';
                                    if ($solic['status'] == 'pendente') {
                                        $status_class = 'badge-pendente';
                                        $status_texto = 'Pendente';
                                    } elseif ($solic['status'] == 'em_votacao') {
                                        $status_class = 'badge-em_votacao';
                                        $status_texto = 'Em Votação';
                                    } else {
                                        $status_class = 'badge-finalizado';
                                        $status_texto = $solic['resultado_final'] == 'aprovado' ? 'Aprovado' : 'Reprovado';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($solic['aluno_nome']); ?></strong><br>
                                        <small class="text-muted"><?php echo $solic['bimestre']; ?>º Bimestre</small>
                                    </td>
                                    <td><?php echo htmlspecialchars($solic['matricula']); ?></td>
                                    <td class="text-danger"><?php echo $solic['nota_atual']; ?></td>
                                    <td class="text-success fw-bold"><?php echo $solic['nota_sugerida']; ?></td>
                                    <td>
                                        <div class="text-center">
                                            <small><?php echo $total_votos; ?> / 3 votos</small>
                                            <div class="progress votos-progresso">
                                                <div class="progress-bar bg-success" style="width: <?php echo $percentual_favor; ?>%"></div>
                                            </div>
                                            <small>
                                                <span class="text-success">👍 <?php echo $votos_favoraveis; ?></span> | 
                                                <span class="text-danger">👎 <?php echo $votos_contra; ?></span>
                                            </small>
                                        </div>
                                    </td>
                                    <td><span class="badge <?php echo $status_class; ?>"><?php echo $status_texto; ?></span></td>
                                    <td>
                                        <?php if ($solic['status'] != 'finalizado'): ?>
                                            <button class="btn-detalhes btn-sm" onclick="verDetalhesSolicitacao(<?php echo $solic['id']; ?>)">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">Finalizado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
                    <button type="button" class="btn btn-primary" id="btnVotarModal" style="display: none;" onclick="votarModal()">Registrar Voto</button>
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
        let solicitacaoAtual = null;
        
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
                        solicitacaoAtual = response.dados;
                        let jaVotou = response.dados.ja_votou || false;
                        let status = response.dados.status;
                        
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
                                    ${jaVotou ? '<div class="alert alert-success mt-2"><i class="fas fa-check-circle"></i> Você já votou nesta solicitação.</div>' : ''}
                                    ${status === 'finalizado' ? '<div class="alert alert-secondary mt-2"><i class="fas fa-check-double"></i> Esta solicitação já foi finalizada. Resultado: ' + (response.dados.resultado_final === 'aprovado' ? 'APROVADA' : 'REPROVADA') + '</div>' : ''}
                                </div>
                            </div>
                        `;
                        $('#modalDetalhesConteudo').html(html);
                        
                        if (!jaVotou && status !== 'finalizado') {
                            $('#btnVotarModal').show();
                        } else {
                            $('#btnVotarModal').hide();
                        }
                    } else {
                        $('#modalDetalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                        $('#btnVotarModal').hide();
                    }
                },
                error: function() {
                    $('#modalDetalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                    $('#btnVotarModal').hide();
                }
            });
        }
        
        function votarModal() {
            if (solicitacaoAtual) {
                $('#modalDetalhesSolicitacao').modal('hide');
                abrirVotacao(solicitacaoAtual.id, solicitacaoAtual.aluno_nome, solicitacaoAtual.nota_atual, solicitacaoAtual.nota_sugerida, solicitacaoAtual.motivo);
            }
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
                url: 'conselho_sessao.php?id=<?php echo $sessao_id; ?>',
                method: 'POST',
                data: { acao: 'registrar_voto', solicitacao_id: solicitacaoId, voto: voto, justificativa: justificativa },
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
                    Swal.fire('Erro!', 'Erro ao registrar voto.', 'error');
                }
            });
        }
    </script>
</body>
</html>