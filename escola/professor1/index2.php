<?php
// escola/professorlancamento/index.php
require_once 'includes/config.php';

$pdo = getConnection();
$professor = checkProfessorAuth();
$ano_letivo = getAnoLetivoAtivo($pdo);

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$ano_letivo_id = $ano_letivo['id'];

// 1. Buscar turmas do professor na escola
$sql_turmas = "
    SELECT DISTINCT 
        t.id, 
        t.nome, 
        t.serie,
        t.turno,
        d.id AS disciplina_id,
        d.nome AS disciplina_nome,
        pdt.bimestre
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    INNER JOIN professores p ON p.id = pdt.professor_id
    WHERE pdt.professor_id = :professor_id 
    AND pdt.ano_letivo_id = :ano_letivo_id
    AND p.escola_id = :escola_id
    ORDER BY t.serie, t.nome, d.nome
";

$stmt_turmas = $pdo->prepare($sql_turmas);
$stmt_turmas->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// 2. Buscar alunos das turmas do professor
$sql_alunos = "
    SELECT 
        a.id, 
        a.nome, 
        a.matricula,
        a.email,
        a.telefone,
        t.id AS turma_id,
        t.nome AS turma_nome,
        t.serie
    FROM alunos a
    INNER JOIN turmas t ON t.id = a.turma_id
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :professor_id 
    AND pdt.ano_letivo_id = :ano_letivo_id
    AND t.escola_id = :escola_id
    ORDER BY t.serie, t.nome, a.nome
";

$stmt_alunos = $pdo->prepare($sql_alunos);
$stmt_alunos->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// 3. Buscar atividades do professor
$sql_atividades = "
    SELECT 
        a.id,
        a.titulo,
        a.descricao,
        a.data_entrega,
        a.valor_maximo,
        a.tipo,
        a.status,
        d.nome AS disciplina_nome,
        t.nome AS turma_nome,
        COUNT(l.id) AS total_lancamentos
    FROM atividades a
    INNER JOIN disciplinas d ON d.id = a.disciplina_id
    INNER JOIN turmas t ON t.id = a.turma_id
    LEFT JOIN lancamentos_notas l ON l.atividade_id = a.id
    WHERE a.professor_id = :professor_id 
    AND a.escola_id = :escola_id
    GROUP BY a.id
    ORDER BY a.data_entrega DESC
    LIMIT 30
";

$stmt_atividades = $pdo->prepare($sql_atividades);
$stmt_atividades->execute([
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
]);
$atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);

// 4. Buscar notas lançadas (usando a tabela notas existente)
$sql_notas = "
    SELECT 
        n.id,
        n.mac,
        n.npt,
        n.exame_normal,
        n.media_final,
        n.status AS situacao,
        n.bimestre,
        n.created_at,
        a.nome AS aluno_nome,
        a.matricula,
        d.nome AS disciplina_nome,
        t.nome AS turma_nome
    FROM notas n
    INNER JOIN alunos a ON a.id = n.aluno_id
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN turmas t ON t.id = a.turma_id
    WHERE n.professor_id = :professor_id 
    AND n.ano_letivo_id = :ano_letivo_id
    ORDER BY n.created_at DESC
    LIMIT 50
";

$stmt_notas = $pdo->prepare($sql_notas);
$stmt_notas->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// 5. Estatísticas do professor
$sql_stats = "
    SELECT 
        (SELECT COUNT(DISTINCT pdt.turma_id) 
         FROM professor_disciplina_turma pdt
         WHERE pdt.professor_id = :professor_id 
         AND pdt.ano_letivo_id = :ano_letivo_id) AS total_turmas,
        
        (SELECT COUNT(DISTINCT a.id) 
         FROM alunos a
         INNER JOIN turmas t ON t.id = a.turma_id
         INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
         WHERE pdt.professor_id = :professor_id 
         AND pdt.ano_letivo_id = :ano_letivo_id) AS total_alunos,
        
        (SELECT COUNT(DISTINCT pdt.disciplina_id) 
         FROM professor_disciplina_turma pdt
         WHERE pdt.professor_id = :professor_id 
         AND pdt.ano_letivo_id = :ano_letivo_id) AS total_disciplinas,
        
        (SELECT COUNT(*) 
         FROM atividades a
         WHERE a.professor_id = :professor_id 
         AND a.escola_id = :escola_id) AS total_atividades
";

$stmt_stats = $pdo->prepare($sql_stats);
$stmt_stats->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// 6. Médias por turma
$sql_medias_turma = "
    SELECT 
        t.nome AS turma_nome,
        t.serie,
        ROUND(AVG(
            CASE 
                WHEN n.media_final IS NOT NULL THEN n.media_final
                ELSE (COALESCE(n.mac, 0) + COALESCE(n.npt, 0)) / 2
            END
        ), 2) AS media_geral,
        COUNT(DISTINCT a.id) AS total_alunos,
        COUNT(DISTINCT CASE WHEN n.media_final >= 10 THEN a.id END) AS aprovados,
        COUNT(DISTINCT CASE WHEN n.media_final BETWEEN 7 AND 9.9 THEN a.id END) AS recuperacao,
        COUNT(DISTINCT CASE WHEN n.media_final < 7 THEN a.id END) AS reprovados
    FROM turmas t
    LEFT JOIN alunos a ON a.turma_id = t.id
    LEFT JOIN notas n ON n.aluno_id = a.id AND n.professor_id = :professor_id
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :professor_id 
    AND pdt.ano_letivo_id = :ano_letivo_id
    AND t.escola_id = :escola_id
    GROUP BY t.id
    ORDER BY t.serie, t.nome
";

$stmt_medias = $pdo->prepare($sql_medias_turma);
$stmt_medias->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':escola_id' => $escola_id
]);
$medias_turma = $stmt_medias->fetchAll(PDO::FETCH_ASSOC);

// 7. Informações da escola
$sql_escola = "SELECT nome_fantasia, razao_social, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $pdo->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard do Professor - <?php echo htmlspecialchars($escola['nome_fantasia'] ?? 'Sistema'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4caf50;
            --warning-color: #ff9800;
            --danger-color: #f44336;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 24px;
        }
        
        .card-stats {
            border: none;
            border-radius: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        
        .card-stats:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .table-responsive {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .btn-lancar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 25px;
            transition: all 0.3s;
        }
        
        .btn-lancar:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(67,97,238,0.3);
        }
        
        .page-header {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .badge-aprovado {
            background-color: #4caf50;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .badge-reprovado {
            background-color: #f44336;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .badge-recuperacao {
            background-color: #ff9800;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 1000;
            }
            .sidebar .nav {
                flex-direction: row !important;
                justify-content: space-around;
            }
            .content-wrapper {
                margin-bottom: 80px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="text-center py-4">
                    <?php if (!empty($escola['logo'])): ?>
                        <img src="../../uploads/logo/<?php echo $escola['logo']; ?>" alt="Logo" class="rounded-circle" width="70" height="70">
                    <?php else: ?>
                        <i class="bi bi-person-circle" style="font-size: 60px; color: white;"></i>
                    <?php endif; ?>
                    <h6 class="text-white mt-2 mb-0"><?php echo htmlspecialchars($professor['professor_nome']); ?></h6>
                    <small class="text-white-50">Professor</small>
                    <hr class="bg-white my-2">
                    <small class="text-white-50"><?php echo htmlspecialchars($escola['nome_fantasia'] ?? ''); ?></small>
                </div>
                <nav class="nav flex-column px-3">
                    <a class="nav-link active" href="#" data-page="dashboard">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="#" data-page="turmas">
                        <i class="bi bi-building"></i> Minhas Turmas
                    </a>
                    <a class="nav-link" href="#" data-page="alunos">
                        <i class="bi bi-people"></i> Meus Alunos
                    </a>
                    <a class="nav-link" href="#" data-page="atividades">
                        <i class="bi bi-calendar-check"></i> Atividades
                    </a>
                    <a class="nav-link" href="#" data-page="notas">
                        <i class="bi bi-journal-bookmark-fill"></i> Lançar Notas
                    </a>
                    <a class="nav-link" href="#" data-page="relatorios">
                        <i class="bi bi-graph-up"></i> Relatórios
                    </a>
                    <a class="nav-link" href="#" data-page="horarios">
                        <i class="bi bi-clock-history"></i> Meus Horários
                    </a>
                    <hr class="bg-white">
                    <a class="nav-link" href="../../logout.php">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 content-wrapper p-4">
                <!-- Dashboard Content -->
                <div id="dashboard-content">
                    <div class="page-header d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-0"><i class="bi bi-speedometer2 me-2"></i> Dashboard Acadêmico</h2>
                            <small class="text-muted">Ano Letivo: <?php echo $ano_letivo['ano']; ?> | <?php echo date('d/m/Y H:i'); ?></small>
                        </div>
                        <div>
                            <span class="badge bg-success p-2">
                                <i class="bi bi-calendar-check"></i> Período de Lançamento: Bimestre Ativo
                            </span>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card card-stats bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-title mb-1">Minhas Turmas</h6>
                                            <h2 class="mb-0"><?php echo $stats['total_turmas'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-building" style="font-size: 45px; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card card-stats bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-title mb-1">Meus Alunos</h6>
                                            <h2 class="mb-0"><?php echo $stats['total_alunos'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-people" style="font-size: 45px; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card card-stats bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-title mb-1">Disciplinas</h6>
                                            <h2 class="mb-0"><?php echo $stats['total_disciplinas'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-book" style="font-size: 45px; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card card-stats bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="card-title mb-1">Atividades</h6>
                                            <h2 class="mb-0"><?php echo $stats['total_atividades'] ?? 0; ?></h2>
                                        </div>
                                        <i class="bi bi-calendar" style="font-size: 45px; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <h5><i class="bi bi-bar-chart-steps"></i> Médias das Turmas por Disciplina</h5>
                                <canvas id="mediasChart" height="300"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="chart-container">
                                <h5><i class="bi bi-pie-chart"></i> Distribuição de Alunos</h5>
                                <canvas id="alunosChart" height="250"></canvas>
                            </div>
                            <div class="chart-container mt-3">
                                <h5><i class="bi bi-graph-up"></i> Situação Acadêmica</h5>
                                <canvas id="situacaoChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Data -->
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <h5><i class="bi bi-calendar-check"></i> Últimas Atividades</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr><th>Título</th><th>Turma</th><th>Disciplina</th><th>Data Entrega</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach(array_slice($atividades, 0, 5) as $atividade): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($atividade['titulo']); ?></td>
                                                <td><?php echo htmlspecialchars($atividade['turma_nome']); ?></td>
                                                <td><?php echo htmlspecialchars($atividade['disciplina_nome']); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(count($atividades) == 0): ?>
                                            <tr><td colspan="4" class="text-center text-muted">Nenhuma atividade cadastrada</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <h5><i class="bi bi-journal-bookmark-fill"></i> Últimos Lançamentos</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr><th>Aluno</th><th>Disciplina</th><th>Nota</th><th>Bimestre</th><th>Situação</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach(array_slice($notas, 0, 5) as $nota): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($nota['aluno_nome']); ?></td>
                                                <td><?php echo htmlspecialchars($nota['disciplina_nome']); ?></td>
                                                <td><strong><?php echo number_format($nota['media_final'] ?? ($nota['mac'] + $nota['npt'])/2, 1); ?></strong></td>
                                                <td><?php echo $nota['bimestre'] . 'º Bim'; ?></td>
                                                <td>
                                                    <span class="badge-<?php 
                                                        echo $nota['situacao'] == 'aprovado' ? 'aprovado' : 
                                                            ($nota['situacao'] == 'recuperacao' ? 'recuperacao' : 'reprovado'); 
                                                    ?>">
                                                        <?php echo ucfirst($nota['situacao'] ?? 'Pendente'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(count($notas) == 0): ?>
                                            <tr><td colspan="5" class="text-center text-muted">Nenhuma nota lançada</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Turmas Content -->
                <div id="turmas-content" style="display: none;">
                    <div class="page-header">
                        <h2><i class="bi bi-building"></i> Minhas Turmas</h2>
                        <p class="text-muted mb-0">Turmas que você leciona no ano letivo <?php echo $ano_letivo['ano']; ?></p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>#</th><th>Turma</th><th>Série</th><th>Turno</th><th>Disciplina</th><th>Bimestre</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($turmas as $index => $turma): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($turma['nome']); ?></strong></td>
                                    <td><?php echo $turma['serie'] . 'ª Classe'; ?></td>
                                    <td><?php echo $turma['turno']; ?></td>
                                    <td><?php echo htmlspecialchars($turma['disciplina_nome']); ?></td>
                                    <td><?php echo $turma['bimestre'] . 'º Bimestre'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="verTurma(<?php echo $turma['id']; ?>, '<?php echo htmlspecialchars($turma['disciplina_nome']); ?>')">
                                            <i class="bi bi-eye"></i> Ver Detalhes
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="lancarNotasTurma(<?php echo $turma['id']; ?>, <?php echo $turma['disciplina_id']; ?>)">
                                            <i class="bi bi-pencil"></i> Lançar Notas
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Alunos Content -->
                <div id="alunos-content" style="display: none;">
                    <div class="page-header">
                        <h2><i class="bi bi-people"></i> Meus Alunos</h2>
                        <p class="text-muted mb-0">Total: <?php echo count($alunos); ?> alunos</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="tabelaAlunos">
                            <thead class="table-light">
                                <tr><th>Matrícula</th><th>Nome</th><th>Email</th><th>Telefone</th><th>Turma</th><th>Série</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($alunos as $aluno): ?>
                                <tr>
                                    <td><?php echo $aluno['matricula']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                    <td><?php echo $aluno['email'] ?? '-'; ?></td>
                                    <td><?php echo $aluno['telefone'] ?? '-'; ?></td>
                                    <td><?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                    <td><?php echo $aluno['serie'] . 'ª'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="verHistoricoAluno(<?php echo $aluno['id']; ?>)">
                                            <i class="bi bi-journal-bookmark"></i> Histórico
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Atividades Content -->
                <div id="atividades-content" style="display: none;">
                    <div class="page-header d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="bi bi-calendar-check"></i> Minhas Atividades</h2>
                            <p class="text-muted mb-0">Atividades, trabalhos e avaliações</p>
                        </div>
                        <button class="btn btn-lancar" data-bs-toggle="modal" data-bs-target="#novaAtividadeModal">
                            <i class="bi bi-plus-circle"></i> Nova Atividade
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Título</th><th>Descrição</th><th>Disciplina</th><th>Turma</th><th>Data Entrega</th><th>Valor</th><th>Lançamentos</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($atividades as $atividade): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($atividade['titulo']); ?></strong></td>
                                    <td><?php echo substr(htmlspecialchars($atividade['descricao']), 0, 50); ?>...</td>
                                    <td><?php echo htmlspecialchars($atividade['disciplina_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($atividade['turma_nome']); ?></td>
                                    <td>
                                        <?php if($atividade['data_entrega']): ?>
                                            <span class="badge <?php echo strtotime($atividade['data_entrega']) < time() ? 'bg-danger' : 'bg-warning'; ?>">
                                                <?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $atividade['valor_maximo']; ?></td>
                                    <td><?php echo $atividade['total_lancamentos']; ?>/<?php echo $stats['total_alunos']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="lancarNotasAtividade(<?php echo $atividade['id']; ?>)">
                                            <i class="bi bi-pencil"></i> Lançar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Notas Content -->
                <div id="notas-content" style="display: none;">
                    <div class="page-header">
                        <h2><i class="bi bi-journal-bookmark-fill"></i> Lançar Notas</h2>
                        <p class="text-muted mb-0">Selecione a turma e disciplina para lançar as notas</p>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><i class="bi bi-building"></i> Turma</label>
                            <select id="filtroTurma" class="form-select" onchange="carregarDisciplinas()">
                                <option value="">Selecione...</option>
                                <?php 
                                $turmas_unicas = [];
                                foreach($turmas as $turma):
                                    if(!isset($turmas_unicas[$turma['id']])):
                                        $turmas_unicas[$turma['id']] = $turma;
                                ?>
                                <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']) . ' - ' . $turma['serie'] . 'ª'; ?></option>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><i class="bi bi-book"></i> Disciplina</label>
                            <select id="filtroDisciplina" class="form-select" onchange="carregarBimestres()" disabled>
                                <option value="">Selecione primeiro a turma</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label"><i class="bi bi-calendar"></i> Bimestre</label>
                            <select id="filtroBimestre" class="form-select" onchange="carregarAlunosNotas()" disabled>
                                <option value="">Selecione...</option>
                                <option value="1">1º Bimestre</option>
                                <option value="2">2º Bimestre</option>
                                <option value="3">3º Bimestre</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <button class="btn btn-primary" onclick="carregarAlunosNotas()" id="btnCarregar" disabled>
                                <i class="bi bi-search"></i> Carregar Alunos
                            </button>
                        </div>
                    </div>
                    <div id="lancamentoNotasArea" class="mt-3"></div>
                </div>

                <!-- Relatórios Content -->
                <div id="relatorios-content" style="display: none;">
                    <div class="page-header">
                        <h2><i class="bi bi-graph-up"></i> Relatórios por Turma</h2>
                        <p class="text-muted mb-0">Análise de desempenho das suas turmas</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Turma</th><th>Série</th><th>Média Geral</th><th>Total Alunos</th><th>Aprovados</th><th>Recuperação</th><th>Reprovados</th><th>Relatório</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($medias_turma as $media): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($media['turma_nome']); ?></strong></td>
                                    <td><?php echo $media['serie'] . 'ª'; ?></td>
                                    <td>
                                        <span class="badge <?php echo ($media['media_geral'] ?? 0) >= 10 ? 'bg-success' : (($media['media_geral'] ?? 0) >= 7 ? 'bg-warning' : 'bg-danger'); ?>">
                                            <?php echo number_format($media['media_geral'] ?? 0, 1); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $media['total_alunos']; ?></td>
                                    <td><span class="text-success"><?php echo $media['aprovados'] ?? 0; ?></span></td>
                                    <td><span class="text-warning"><?php echo $media['recuperacao'] ?? 0; ?></span></td>
                                    <td><span class="text-danger"><?php echo $media['reprovados'] ?? 0; ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="gerarRelatorioPDF('<?php echo htmlspecialchars($media['turma_nome']); ?>')">
                                            <i class="bi bi-file-pdf"></i> Gerar PDF
                                        </button>
                                        <button class="btn btn-sm btn-secondary" onclick="gerarRelatorioExcel('<?php echo htmlspecialchars($media['turma_nome']); ?>')">
                                            <i class="bi bi-file-excel"></i> Excel
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Horários Content -->
                <div id="horarios-content" style="display: none;">
                    <div class="page-header">
                        <h2><i class="bi bi-clock-history"></i> Meus Horários</h2>
                        <p class="text-muted mb-0">Grade de horários do ano letivo <?php echo $ano_letivo['ano']; ?></p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Horário</th>
                                    <th>Segunda</th>
                                    <th>Terça</th>
                                    <th>Quarta</th>
                                    <th>Quinta</th>
                                    <th>Sexta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $horarios = [
                                    '07:30', '08:20', '09:10', '10:00', '10:50', '11:40', '13:30', '14:20', '15:10', '16:00'
                                ];
                                $dias = ['segunda', 'terca', 'quarta', 'quinta', 'sexta'];
                                
                                // Buscar horários do professor
                                $sql_horarios = "
                                    SELECT h.dia_semana, h.horario_inicio, h.horario_fim, 
                                           d.nome AS disciplina_nome, t.nome AS turma_nome
                                    FROM horarios h
                                    INNER JOIN disciplinas d ON d.id = h.disciplina_id
                                    INNER JOIN turmas t ON t.id = h.turma_id
                                    WHERE h.professor_id = :professor_id 
                                    AND h.ano_letivo_id = :ano_letivo_id
                                ";
                                $stmt_horarios = $pdo->prepare($sql_horarios);
                                $stmt_horarios->execute([
                                    ':professor_id' => $professor_id,
                                    ':ano_letivo_id' => $ano_letivo_id
                                ]);
                                $horarios_prof = [];
                                while($row = $stmt_horarios->fetch(PDO::FETCH_ASSOC)) {
                                    $horarios_prof[$row['dia_semana']][$row['horario_inicio']] = $row;
                                }
                                
                                foreach($horarios as $hora):
                                ?>
                                <tr>
                                    <td class="bg-light fw-bold"><?php echo $hora; ?></td>
                                    <?php foreach($dias as $dia): ?>
                                    <td>
                                        <?php if(isset($horarios_prof[$dia][$hora])): ?>
                                            <strong><?php echo htmlspecialchars($horarios_prof[$dia][$hora]['disciplina_nome']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($horarios_prof[$dia][$hora]['turma_nome']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Nova Atividade Modal -->
    <div class="modal fade" id="novaAtividadeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nova Atividade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNovaAtividade">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Título *</label>
                                <input type="text" name="titulo" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="trabalho">Trabalho</option>
                                    <option value="prova">Prova</option>
                                    <option value="exercicio">Exercício</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva a atividade..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Disciplina *</label>
                                <select name="disciplina_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php 
                                    $disciplinas_unicas = [];
                                    foreach($turmas as $turma):
                                        if(!isset($disciplinas_unicas[$turma['disciplina_id']])):
                                            $disciplinas_unicas[$turma['disciplina_id']] = $turma['disciplina_nome'];
                                    ?>
                                    <option value="<?php echo $turma['disciplina_id']; ?>"><?php echo htmlspecialchars($turma['disciplina_nome']); ?></option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Turma *</label>
                                <select name="turma_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']) . ' - ' . $turma['serie'] . 'ª'; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Entrega</label>
                                <input type="date" name="data_entrega" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor Máximo</label>
                                <input type="number" name="valor_maximo" class="form-control" step="0.5" value="10" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Atividade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Dados para gráficos
        const turmasNomes = <?php echo json_encode(array_column($medias_turma, 'turma_nome')); ?>;
        const turmasMedias = <?php echo json_encode(array_column($medias_turma, 'media_geral')); ?>;
        const alunosPorTurma = <?php 
            $counts = [];
            foreach($turmas as $t) {
                $counts[] = count(array_filter($alunos, function($a) use ($t) {
                    return $a['turma_id'] == $t['id'];
                }));
            }
            echo json_encode($counts);
        ?>;
        
        // Gráfico de médias
        const ctxMedias = document.getElementById('mediasChart').getContext('2d');
        new Chart(ctxMedias, {
            type: 'bar',
            data: {
                labels: turmasNomes,
                datasets: [{
                    label: 'Média Geral (0-20)',
                    data: turmasMedias,
                    backgroundColor: 'rgba(67, 97, 238, 0.6)',
                    borderColor: 'rgba(67, 97, 238, 1)',
                    borderWidth: 1,
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 20,
                        title: { display: true, text: 'Nota' }
                    }
                },
                plugins: {
                    tooltip: { callbacks: { label: (ctx) => `${ctx.raw.toFixed(1)} valores` } }
                }
            }
        });
        
        // Gráfico de alunos
        const ctxAlunos = document.getElementById('alunosChart').getContext('2d');
        new Chart(ctxAlunos, {
            type: 'pie',
            data: {
                labels: turmasNomes,
                datasets: [{
                    data: alunosPorTurma,
                    backgroundColor: ['#4361ee', '#3f37c9', '#4caf50', '#ff9800', '#f44336', '#9c27b0'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        // Gráfico de situação
        const ctxSituacao = document.getElementById('situacaoChart').getContext('2d');
        const aprovados = <?php echo array_sum(array_column($medias_turma, 'aprovados')) ?: 0; ?>;
        const recuperacao = <?php echo array_sum(array_column($medias_turma, 'recuperacao')) ?: 0; ?>;
        const reprovados = <?php echo array_sum(array_column($medias_turma, 'reprovados')) ?: 0; ?>;
        
        new Chart(ctxSituacao, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [aprovados, recuperacao, reprovados],
                    backgroundColor: ['#4caf50', '#ff9800', '#f44336'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Navegação entre páginas
        document.querySelectorAll('.nav-link[data-page]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.dataset.page;
                
                document.querySelectorAll('[id$="-content"]').forEach(content => {
                    content.style.display = 'none';
                });
                
                document.getElementById(`${page}-content`).style.display = 'block';
                
                document.querySelectorAll('.nav-link').forEach(nav => nav.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        // Funções auxiliares
        function verTurma(id, disciplina) {
            Swal.fire({
                title: 'Detalhes da Turma',
                html: `Carregando informações da turma ${id}...`,
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
            carregarAlunosNotas();
        }
        
        function verHistoricoAluno(id) {
            window.open(`historico_aluno.php?id=${id}`, '_blank', 'width=1000,height=600');
        }
        
        function lancarNotasAtividade(atividadeId) {
            window.location.href = `lancar_notas_atividade.php?id=${atividadeId}`;
        }
        
        function lancarNotasTurma(turmaId, disciplinaId) {
            document.getElementById('filtroTurma').value = turmaId;
            carregarDisciplinas();
            setTimeout(() => {
                document.getElementById('filtroDisciplina').value = disciplinaId;
                carregarBimestres();
                setTimeout(() => {
                    document.getElementById('filtroBimestre').value = '1';
                    carregarAlunosNotas();
                }, 300);
            }, 300);
            
            document.querySelector('.nav-link[data-page="notas"]').click();
        }
        
        function carregarDisciplinas() {
            const turmaId = document.getElementById('filtroTurma').value;
            const disciplinaSelect = document.getElementById('filtroDisciplina');
            
            if(!turmaId) {
                disciplinaSelect.innerHTML = '<option value="">Selecione primeiro a turma</option>';
                disciplinaSelect.disabled = true;
                return;
            }
            
            fetch(`ajax/get_disciplinas_turma.php?turma_id=${turmaId}`)
                .then(response => response.json())
                .then(data => {
                    disciplinaSelect.innerHTML = '<option value="">Selecione...</option>';
                    data.forEach(disp => {
                        disciplinaSelect.innerHTML += `<option value="${disp.id}">${disp.nome}</option>`;
                    });
                    disciplinaSelect.disabled = false;
                });
        }
        
        function carregarBimestres() {
            const bimestreSelect = document.getElementById('filtroBimestre');
            bimestreSelect.disabled = false;
            document.getElementById('btnCarregar').disabled = false;
        }
        
        function carregarAlunosNotas() {
            const turmaId = document.getElementById('filtroTurma').value;
            const disciplinaId = document.getElementById('filtroDisciplina').value;
            const bimestre = document.getElementById('filtroBimestre').value;
            
            if(!turmaId || !disciplinaId || !bimestre) {
                Swal.fire('Atenção', 'Selecione turma, disciplina e bimestre', 'warning');
                return;
            }
            
            document.getElementById('lancamentoNotasArea').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><br>Carregando alunos...</div>';
            
            fetch(`ajax/carregar_alunos_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        let html = `
                            <div class="table-responsive">
                                <form id="formLancamentoNotas">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>#</th>
                                                <th>Matrícula</th>
                                                <th>Aluno</th>
                                                <th>MAC (0-10)</th>
                                                <th>NPT (0-10)</th>
                                                <th>Exame Normal (0-20)</th>
                                                <th>Exame Recurso (0-20)</th>
                                                <th>Exame Especial (0-20)</th>
                                                <th>Média Final</th>
                                                <th>Situação</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        data.alunos.forEach((aluno, index) => {
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${aluno.matricula}</td>
                                    <td><strong>${aluno.nome}</strong></td>
                                    <td><input type="number" step="0.5" min="0" max="10" name="mac[${aluno.id}]" value="${aluno.mac || ''}" class="form-control form-control-sm" style="width: 80px" onchange="calcularMedia(${aluno.id})"></td>
                                    <td><input type="number" step="0.5" min="0" max="10" name="npt[${aluno.id}]" value="${aluno.npt || ''}" class="form-control form-control-sm" style="width: 80px" onchange="calcularMedia(${aluno.id})"></td>
                                    <td><input type="number" step="0.5" min="0" max="20" name="exame_normal[${aluno.id}]" value="${aluno.exame_normal || ''}" class="form-control form-control-sm" style="width: 80px" onchange="calcularMedia(${aluno.id})"></td>
                                    <td><input type="number" step="0.5" min="0" max="20" name="exame_recurso[${aluno.id}]" value="${aluno.exame_recurso || ''}" class="form-control form-control-sm" style="width: 80px" onchange="calcularMedia(${aluno.id})"></td>
                                    <td><input type="number" step="0.5" min="0" max="20" name="exame_especial[${aluno.id}]" value="${aluno.exame_especial || ''}" class="form-control form-control-sm" style="width: 80px" onchange="calcularMedia(${aluno.id})"></td>
                                    <td><span id="media_${aluno.id}" class="fw-bold">${aluno.media_final || '-'}</span></td>
                                    <td><span id="situacao_${aluno.id}" class="badge ${aluno.status === 'aprovado' ? 'bg-success' : (aluno.status === 'recuperacao' ? 'bg-warning' : 'bg-danger')}">${aluno.status || 'Pendente'}</span></td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                        </tbody>
                                    </table>
                                    <div class="text-end mt-3">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bi bi-save"></i> Salvar Todas as Notas
                                        </button>
                                    </div>
                                </form>
                            </div>
                        `;
                        
                        document.getElementById('lancamentoNotasArea').innerHTML = html;
                        
                        // Configurar submit do formulário
                        document.getElementById('formLancamentoNotas').addEventListener('submit', function(e) {
                            e.preventDefault();
                            salvarNotas(turmaId, disciplinaId, bimestre);
                        });
                    } else {
                        document.getElementById('lancamentoNotasArea').innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    document.getElementById('lancamentoNotasArea').innerHTML = `<div class="alert alert-danger">Erro ao carregar dados: ${error}</div>`;
                });
        }
        
        function calcularMedia(alunoId) {
            const mac = parseFloat(document.querySelector(`input[name="mac[${alunoId}]"]`).value) || 0;
            const npt = parseFloat(document.querySelector(`input[name="npt[${alunoId}]"]`).value) || 0;
            const exameNormal = parseFloat(document.querySelector(`input[name="exame_normal[${alunoId}]"]`).value) || 0;
            const exameRecurso = parseFloat(document.querySelector(`input[name="exame_recurso[${alunoId}]"]`).value) || 0;
            const exameEspecial = parseFloat(document.querySelector(`input[name="exame_especial[${alunoId}]"]`).value) || 0;
            
            let media = (mac + npt) / 2;
            let situacao = '';
            
            if(exameNormal > 0) {
                media = (media + exameNormal) / 2;
                situacao = media >= 10 ? 'aprovado' : (media >= 7 ? 'recuperacao' : 'reprovado');
            } else if(exameRecurso > 0) {
                media = (media + exameRecurso) / 2;
                situacao = media >= 10 ? 'aprovado' : (media >= 7 ? 'recuperacao' : 'reprovado');
            } else if(exameEspecial > 0) {
                media = (media + exameEspecial) / 2;
                situacao = media >= 10 ? 'aprovado' : (media >= 7 ? 'recuperacao' : 'reprovado');
            } else {
                situacao = media >= 10 ? 'aprovado' : (media >= 7 ? 'recuperacao' : 'reprovado');
            }
            
            document.getElementById(`media_${alunoId}`).innerHTML = media.toFixed(1);
            const situacaoSpan = document.getElementById(`situacao_${alunoId}`);
            situacaoSpan.innerHTML = situacao === 'aprovado' ? 'Aprovado' : (situacao === 'recuperacao' ? 'Recuperação' : 'Reprovado');
            situacaoSpan.className = `badge ${situacao === 'aprovado' ? 'bg-success' : (situacao === 'recuperacao' ? 'bg-warning' : 'bg-danger')}`;
        }
        
        function salvarNotas(turmaId, disciplinaId, bimestre) {
            const formData = new FormData(document.getElementById('formLancamentoNotas'));
            formData.append('turma_id', turmaId);
            formData.append('disciplina_id', disciplinaId);
            formData.append('bimestre', bimestre);
            formData.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
            
            Swal.fire({
                title: 'Salvando...',
                text: 'Por favor, aguarde',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            fetch('ajax/salvar_notas.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Sucesso!', 'Notas salvas com sucesso!', 'success');
                } else {
                    Swal.fire('Erro!', data.message || 'Erro ao salvar notas', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Erro!', 'Erro na comunicação com o servidor', 'error');
            });
        }
        
        function gerarRelatorioPDF(turma) {
            Swal.fire('Gerando PDF', `Relatório da turma ${turma} será gerado`, 'info');
        }
        
        function gerarRelatorioExcel(turma) {
            Swal.fire('Gerando Excel', `Planilha da turma ${turma} será gerada`, 'info');
        }
        
        // Submissão do formulário de nova atividade
        document.getElementById('formNovaAtividade')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            Swal.fire({ title: 'Salvando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            fetch('ajax/criar_atividade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Sucesso!', 'Atividade criada com sucesso!', 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Erro!', data.message, 'error');
                }
            })
            .catch(error => Swal.fire('Erro!', 'Erro ao criar atividade', 'error'));
        });
    </script>
</body>
</html>