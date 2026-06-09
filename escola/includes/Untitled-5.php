<?php
// escola/pedagogico/index.php - Dashboard Pedagógico

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar se o usuário tem permissão para acessar a área pedagógica
$sql_verifica = "
    SELECT f.*, 
           (SELECT COUNT(*) FROM conselho_nota_permissoes WHERE funcionario_id = f.id AND ativo = 1) as tem_permissao_conselho
    FROM funcionarios f
    WHERE f.usuario_id = :usuario_id AND f.cargo IN ('pedagogo', 'coordenador', 'diretor', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die("
    <div style='text-align: center; padding: 50px;'>
        <i class='fas fa-lock' style='font-size: 60px; color: #dc3545;'></i>
        <h2>Acesso Negado</h2>
        <p>Você não tem permissão para acessar a área pedagógica.</p>
        <p>Esta área é restrita para pedagogos, coordenadores e diretores.</p>
        <a href='../dashboard.php' class='btn btn-primary'>Voltar ao Dashboard</a>
    </div>
    ");
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);

if (!$ano_letivo) {
    $sql_ano = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute();
    $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
}

$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================

// Total de alunos matriculados
$sql_total_alunos = "
    SELECT COUNT(DISTINCT e.id) as total
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.status = 'ativa' AND m.ano_letivo_id = :ano_letivo_id
";
$stmt_total_alunos = $conn->prepare($sql_total_alunos);
$stmt_total_alunos->execute([':ano_letivo_id' => $ano_letivo_id]);
$total_alunos = $stmt_total_alunos->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$sql_total_professores = "
    SELECT COUNT(DISTINCT f.id) as total
    FROM funcionarios f
    WHERE f.escola_id = :escola_id AND f.cargo IN ('professor', 'docente')
";
$stmt_total_professores = $conn->prepare($sql_total_professores);
$stmt_total_professores->execute([':escola_id' => $escola_id]);
$total_professores = $stmt_total_professores->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$sql_total_turmas = "
    SELECT COUNT(*) as total
    FROM turmas
    WHERE escola_id = :escola_id
";
$stmt_total_turmas = $conn->prepare($sql_total_turmas);
$stmt_total_turmas->execute([':escola_id' => $escola_id]);
$total_turmas = $stmt_total_turmas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql_total_disciplinas = "
    SELECT COUNT(*) as total
    FROM disciplinas
";
$stmt_total_disciplinas = $conn->prepare($sql_total_disciplinas);
$stmt_total_disciplinas->execute();
$total_disciplinas = $stmt_total_disciplinas->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================
// ESTATÍSTICAS DE APROVEITAMENTO
// ============================================

// Taxa de aprovação geral (3º bimestre)
$sql_aprovacao = "
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
        COUNT(*) as total
    FROM notas n
    WHERE n.bimestre = 3 AND n.ano_letivo_id = :ano_letivo_id
";
$stmt_aprovacao = $conn->prepare($sql_aprovacao);
$stmt_aprovacao->execute([':ano_letivo_id' => $ano_letivo_id]);
$aprovacao = $stmt_aprovacao->fetch(PDO::FETCH_ASSOC);

$total_notas = $aprovacao['total'] ?: 1;
$percentual_aprovacao = round(($aprovacao['aprovados'] / $total_notas) * 100, 1);
$percentual_recuperacao = round(($aprovacao['recuperacao'] / $total_notas) * 100, 1);
$percentual_reprovacao = round(($aprovacao['reprovados'] / $total_notas) * 100, 1);

// ============================================
// SESSÕES DO CONSELHO ATIVAS
// ============================================
$sql_sessoes_conselho = "
    SELECT 
        cns.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        COUNT(DISTINCT cnp.funcionario_id) as participantes,
        COUNT(DISTINCT cnsol.id) as solicitacoes
    FROM conselho_nota_sessoes cns
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    LEFT JOIN conselho_nota_participantes cnp ON cnp.sessao_id = cns.id
    LEFT JOIN conselho_nota_solicitacoes cnsol ON cnsol.sessao_id = cns.id AND cnsol.status = 'pendente'
    WHERE cns.ano_letivo_id = :ano_letivo_id AND cns.status IN ('agendado', 'em_andamento')
    GROUP BY cns.id
    ORDER BY cns.data_sessao ASC, cns.hora_inicio ASC
    LIMIT 5
";
$stmt_sessoes_conselho = $conn->prepare($sql_sessoes_conselho);
$stmt_sessoes_conselho->execute([':ano_letivo_id' => $ano_letivo_id]);
$sessoes_conselho = $stmt_sessoes_conselho->fetchAll(PDO::FETCH_ASSOC);
$total_sessoes = count($sessoes_conselho);

// ============================================
// SOLICITAÇÕES PENDENTES DO CONSELHO
// ============================================
$sql_solicitacoes_pendentes = "
    SELECT 
        cnsol.*,
        e.nome as aluno_nome,
        d.nome as disciplina_nome,
        t.nome as turma_nome,
        t.ano as turma_ano,
        COUNT(cnv.id) as total_votos,
        SUM(CASE WHEN cnv.voto = 'favoravel' THEN 1 ELSE 0 END) as votos_favoraveis
    FROM conselho_nota_solicitacoes cnsol
    INNER JOIN estudantes e ON e.id = cnsol.estudante_id
    INNER JOIN disciplinas d ON d.id = cnsol.disciplina_id
    INNER JOIN turmas t ON t.id = cnsol.turma_id
    LEFT JOIN conselho_nota_votos cnv ON cnv.solicitacao_id = cnsol.id
    WHERE cnsol.status IN ('pendente', 'em_votacao')
    AND cnsol.ano_letivo_id = :ano_letivo_id
    GROUP BY cnsol.id
    ORDER BY cnsol.created_at ASC
    LIMIT 10
";
$stmt_solicitacoes_pendentes = $conn->prepare($sql_solicitacoes_pendentes);
$stmt_solicitacoes_pendentes->execute([':ano_letivo_id' => $ano_letivo_id]);
$solicitacoes_pendentes = $stmt_solicitacoes_pendentes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ALUNOS COM MAIOR NÚMERO DE NEGATIVAS
// ============================================
$sql_alunos_negativas = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        t.nome as turma_nome,
        t.ano as turma_ano,
        COUNT(CASE WHEN n.media_final < (CASE WHEN t.ano <= 6 THEN 5 ELSE 10 END) THEN 1 END) as total_negativas,
        AVG(CASE WHEN n.media_final > 0 THEN n.media_final ELSE NULL END) as media_geral
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    INNER JOIN turmas t ON t.id = m.turma_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.bimestre = 3 AND n.ano_letivo_id = :ano_letivo_id
    WHERE m.status = 'ativa' AND m.ano_letivo_id = :ano_letivo_id
    GROUP BY e.id
    HAVING total_negativas > 0
    ORDER BY total_negativas DESC, media_geral ASC
    LIMIT 10
";
$stmt_alunos_negativas = $conn->prepare($sql_alunos_negativas);
$stmt_alunos_negativas->execute([':ano_letivo_id' => $ano_letivo_id]);
$alunos_negativas = $stmt_alunos_negativas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DESEMPENHO POR TURMA
// ============================================
$sql_desempenho_turmas = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        COUNT(DISTINCT e.id) as total_alunos,
        AVG(CASE WHEN n.media_final > 0 THEN n.media_final ELSE NULL END) as media_turma,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa' AND m.ano_letivo_id = :ano_letivo_id
    LEFT JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.bimestre = 3 AND n.ano_letivo_id = :ano_letivo_id
    WHERE t.escola_id = :escola_id
    GROUP BY t.id
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_desempenho_turmas = $conn->prepare($sql_desempenho_turmas);
$stmt_desempenho_turmas->execute([
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$desempenho_turmas = $stmt_desempenho_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALENDÁRIO DE EVENTOS PEDAGÓGICOS
// ============================================
$sql_eventos = "
    SELECT * FROM calendario_escolar
    WHERE escola_id = :escola_id AND data_evento >= CURDATE()
    ORDER BY data_evento ASC
    LIMIT 5
";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([':escola_id' => $escola_id]);
$eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dashboard Pedagógico | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --orange: #fd7e14;
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
            content: '📚';
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

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border-left: 4px solid var(--primary-green);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--primary-green);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1A2A6C;
        }

        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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

        .badge-aprovado { background: #d4edda; color: #155724; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-recuperacao { background: #fff3cd; color: #856404; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-reprovado { background: #f8d7da; color: #721c24; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-agendado { background: #cfe2ff; color: #084298; padding: 5px 12px; border-radius: 20px; font-size: 12px; }

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

        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }

        .table-custom th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #495057;
        }

        .table-custom td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .table-custom tr:hover {
            background: #f8f9fa;
        }

        .progress {
            height: 8px;
            border-radius: 10px;
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .stat-value { font-size: 22px; }
            .stat-icon { width: 45px; height: 45px; font-size: 20px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/menu_pedagogico.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-chalkboard-user me-2"></i> Dashboard Pedagógico</h2>
                    <p>Visão geral do desempenho académico e gestão pedagógica</p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div>
                    <a href="../dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row fade-in-up">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Alunos</div>
                            <div class="stat-value"><?php echo number_format($total_alunos, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Professores</div>
                            <div class="stat-value"><?php echo number_format($total_professores, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Turmas</div>
                            <div class="stat-value"><?php echo number_format($total_turmas, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Disciplinas</div>
                            <div class="stat-value"><?php echo number_format($total_disciplinas, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-book"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico de Aproveitamento e Sessões do Conselho -->
        <div class="row fade-in-up">
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-chart-pie me-2"></i> Aproveitamento Geral (3º Bimestre)</h5>
                    </div>
                    <div class="card-body p-4">
                        <canvas id="graficoAprovacao" style="max-height: 250px;"></canvas>
                        <div class="row text-center mt-3">
                            <div class="col-4">
                                <span class="badge-aprovado"><i class="fas fa-check-circle"></i> Aprovados: <?php echo $aprovacao['aprovados']; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge-recuperacao"><i class="fas fa-sync-alt"></i> Recuperação: <?php echo $aprovacao['recuperacao']; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge-reprovado"><i class="fas fa-times-circle"></i> Reprovados: <?php echo $aprovacao['reprovados']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-gavel me-2"></i> Sessões do Conselho de Nota</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($sessoes_conselho)): ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fas fa-calendar-alt fa-3x mb-2"></i>
                                <p>Nenhuma sessão do conselho agendada</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($sessoes_conselho as $sessao): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-building me-1"></i> <?php echo $sessao['turma_ano']; ?>ª - <?php echo htmlspecialchars($sessao['turma_nome']); ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> |
                                            <i class="fas fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($sessao['data_sessao'] . ' ' . $sessao['hora_inicio'])); ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="badge <?php echo $sessao['status'] == 'agendado' ? 'badge-agendado' : 'badge-recuperacao'; ?>">
                                            <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Solicitações Pendentes do Conselho -->
        <?php if (!empty($solicitacoes_pendentes)): ?>
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-clock me-2"></i> Solicitações Pendentes de Votação</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Turma</th>
                                <th>Disciplina</th>
                                <th>Nota Atual</th>
                                <th>Nota Sugerida</th>
                                <th>Votos</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitacoes_pendentes as $solic): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($solic['aluno_nome']); ?></strong></td>
                                <td><?php echo $solic['turma_ano']; ?>ª - <?php echo htmlspecialchars($solic['turma_nome']); ?></td>
                                <td><?php echo htmlspecialchars($solic['disciplina_nome']); ?></td>
                                <td><?php echo $solic['nota_atual']; ?></td>
                                <td class="text-warning fw-bold"><?php echo $solic['nota_sugerida']; ?></td>
                                <td>
                                    <span class="text-success">✅ <?php echo $solic['votos_favoraveis']; ?></span> / 
                                    <span class="text-danger">❌ <?php echo $solic['total_votos'] - $solic['votos_favoraveis']; ?></span>
                                </td>
                                <td>
                                    <a href="conselho_nota.php" class="btn btn-primary-custom btn-sm">
                                        <i class="fas fa-gavel"></i> Votar
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alunos com Maior Número de Negativas -->
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-exclamation-triangle me-2"></i> Alunos em Risco (Maior Número de Negativas)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Turma</th>
                                <th>Disciplinas Negativas</th>
                                <th>Média Geral</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($alunos_negativas)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                    <p>Nenhum aluno com disciplinas negativas encontrado.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($alunos_negativas as $index => $aluno): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                    <td><span class="badge-reprovado"><?php echo $aluno['total_negativas']; ?> negativa(s)</span></td>
                                    <td><?php echo number_format($aluno['media_geral'], 1); ?></td>
                                    <td>
                                        <a href="../professor/ver_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-primary-custom btn-sm">
                                            <i class="fas fa-eye"></i> Ver Ficha
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Desempenho por Turma -->
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-chart-line me-2"></i> Desempenho por Turma</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Turma</th>
                                <th>Total Alunos</th>
                                <th>Média Turma</th>
                                <th>Aprovados</th>
                                <th>Recuperação</th>
                                <th>Reprovados</th>
                                <th>Taxa Aprovação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($desempenho_turmas as $turma): 
                                $total = $turma['total_alunos'] ?: 1;
                                $taxa = $turma['total_alunos'] > 0 ? round(($turma['aprovados'] / $turma['total_alunos']) * 100, 1) : 0;
                                $barra_cor = $taxa >= 75 ? 'success' : ($taxa >= 50 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><strong><?php echo $turma['ano']; ?>ª - <?php echo htmlspecialchars($turma['nome']); ?></strong></td>
                                <td><?php echo $turma['total_alunos']; ?></td>
                                <td><?php echo number_format($turma['media_turma'], 1); ?></td>
                                <td class="text-success"><?php echo $turma['aprovados']; ?></td>
                                <td class="text-warning"><?php echo $turma['recuperacao']; ?></td>
                                <td class="text-danger"><?php echo $turma['reprovados']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?php echo $taxa; ?>%</span>
                                        <div class="progress flex-grow-1">
                                            <div class="progress-bar bg-<?php echo $barra_cor; ?>" style="width: <?php echo $taxa; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Eventos Pedagógicos -->
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-calendar-alt me-2"></i> Próximos Eventos Pedagógicos</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($eventos)): ?>
                    <div class="text-center p-5 text-muted">
                        <i class="fas fa-calendar-day fa-3x mb-2"></i>
                        <p>Nenhum evento agendado</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($eventos as $evento): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><i class="fas fa-calendar-day me-2 text-primary"></i> <?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?></strong>
                                <div class="mt-1"><?php echo htmlspecialchars($evento['titulo']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($evento['descricao']); ?></small>
                            </div>
                            <span class="badge bg-secondary"><?php echo $evento['tipo']; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de Aprovação
        const ctx = document.getElementById('graficoAprovacao').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $aprovacao['aprovados']; ?>, <?php echo $aprovacao['recuperacao']; ?>, <?php echo $aprovacao['reprovados']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Animações ao scroll
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in-up');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.stat-card, .card-custom').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease-out';
            observer.observe(el);
        });
    </script>
</body>
</html>