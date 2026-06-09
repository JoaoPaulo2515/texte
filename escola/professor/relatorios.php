<?php
// escola/professor/relatorios.php - Relatórios do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$tipo_relatorio = isset($_GET['tipo']) ? $_GET['tipo'] : 'desempenho';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo_atual = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id_default = $ano_letivo_atual['id'] ?? 1;
$ano_letivo_valor = $ano_letivo_atual['ano'] ?? date('Y');

if ($ano_letivo_id == 0) {
    $ano_letivo_id = $ano_letivo_id_default;
}

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$anos_letivos = $conn->query($sql_anos)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DADOS PARA RELATÓRIOS
// ============================================

// 1. Dados para o gráfico de desempenho por turma
$sql_desempenho_turmas = "
    SELECT 
        t.nome as turma_nome,
        ROUND(AVG(n.media_final), 1) as media_turma,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
        COUNT(DISTINCT n.estudante_id) as total_alunos
    FROM notas n
    INNER JOIN matriculas m ON m.estudante_id = n.estudante_id AND m.status = 'ativa'
    INNER JOIN turmas t ON t.id = m.turma_id
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :professor_id 
    AND n.ano_letivo_id = :ano_letivo_id
    " . ($turma_id > 0 ? " AND t.id = :turma_id" : "") . "
    " . ($disciplina_id > 0 ? " AND n.disciplina_id = :disciplina_id" : "") . "
    " . ($bimestre > 0 ? " AND n.bimestre = :bimestre" : "") . "
    GROUP BY t.id
    ORDER BY media_turma DESC
";

$params = [
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id
];
if ($turma_id > 0) $params[':turma_id'] = $turma_id;
if ($disciplina_id > 0) $params[':disciplina_id'] = $disciplina_id;
if ($bimestre > 0) $params[':bimestre'] = $bimestre;

$stmt_desempenho = $conn->prepare($sql_desempenho_turmas);
$stmt_desempenho->execute($params);
$desempenho_turmas = $stmt_desempenho->fetchAll(PDO::FETCH_ASSOC);

// 2. Dados para o gráfico de evolução por bimestre
$sql_evolucao = "
    SELECT 
        n.bimestre,
        ROUND(AVG(n.media_final), 1) as media_geral,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados
    FROM notas n
    INNER JOIN matriculas m ON m.estudante_id = n.estudante_id AND m.status = 'ativa'
    INNER JOIN turmas t ON t.id = m.turma_id
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :professor_id 
    AND n.ano_letivo_id = :ano_letivo_id
    " . ($turma_id > 0 ? " AND t.id = :turma_id" : "") . "
    " . ($disciplina_id > 0 ? " AND n.disciplina_id = :disciplina_id" : "") . "
    GROUP BY n.bimestre
    ORDER BY n.bimestre
";

$stmt_evolucao = $conn->prepare($sql_evolucao);
$stmt_evolucao->execute($params);
$evolucao = $stmt_evolucao->fetchAll(PDO::FETCH_ASSOC);

// 3. Dados para a tabela de alunos (se turma e disciplina selecionadas)
$alunos_relatorio = [];
if ($turma_id > 0 && $disciplina_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.media_final,
            n.status as situacao
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        LEFT JOIN notas n ON n.estudante_id = e.id 
            AND n.disciplina_id = :disciplina_id 
            AND n.ano_letivo_id = :ano_letivo_id
            " . ($bimestre > 0 ? " AND n.bimestre = :bimestre" : "") . "
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    
    $params_alunos = [
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ];
    if ($bimestre > 0) $params_alunos[':bimestre'] = $bimestre;
    
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute($params_alunos);
    $alunos_relatorio = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// 4. Estatísticas gerais
$sql_stats = "
    SELECT 
        COUNT(DISTINCT t.id) as total_turmas,
        COUNT(DISTINCT d.id) as total_disciplinas,
        COUNT(DISTINCT e.id) as total_alunos,
        ROUND(AVG(n.media_final), 1) as media_geral,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as total_aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as total_recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as total_reprovados
    FROM professor_disciplina_turma pdt
    LEFT JOIN turmas t ON t.id = pdt.turma_id
    LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    LEFT JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.ano_letivo_id = :ano_letivo_id
    WHERE pdt.professor_id = :professor_id
";

$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

function getSituacaoBadge($situacao) {
    switch ($situacao) {
        case 'aprovado':
            return '<span class="badge bg-success">Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark">Recuperação</span>';
        case 'reprovado':
            return '<span class="badge bg-danger">Reprovado</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .stats-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .bg-primary-gradient { background: linear-gradient(135deg, #006B3E 0%, #008B5E 100%); }
        .bg-info-gradient { background: linear-gradient(135deg, #1A2A6C 0%, #2A3A7C 100%); }
        .bg-success-gradient { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .bg-warning-gradient { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .table-relatorio th {
            background: #f8f9fa;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .relatorio-tabs .nav-link {
            color: #006B3E;
        }
        .relatorio-tabs .nav-link.active {
            background-color: #006B3E;
            color: white;
            border-color: #006B3E;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-line"></i> Relatórios de Desempenho</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ano Letivo</label>
                    <select name="ano_letivo_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-primary-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Turmas</h6>
                                <h2 class="mb-0"><?php echo $stats['total_turmas'] ?? 0; ?></h2>
                            </div>
                            <i class="fas fa-users fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-info-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Disciplinas</h6>
                                <h2 class="mb-0"><?php echo $stats['total_disciplinas'] ?? 0; ?></h2>
                            </div>
                            <i class="fas fa-book fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-success-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Alunos</h6>
                                <h2 class="mb-0"><?php echo $stats['total_alunos'] ?? 0; ?></h2>
                            </div>
                            <i class="fas fa-user-graduate fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-warning-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Média Geral</h6>
                                <h2 class="mb-0"><?php echo number_format($stats['media_geral'] ?? 0, 1); ?></h2>
                            </div>
                            <i class="fas fa-chart-line fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-8">
                <div class="chart-container">
                    <h5><i class="fas fa-chart-bar text-primary"></i> Desempenho por Turma</h5>
                    <canvas id="graficoDesempenho" height="300"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <div class="chart-container">
                    <h5><i class="fas fa-chart-pie text-primary"></i> Distribuição de Resultados</h5>
                    <canvas id="graficoDistribuicao" height="250"></canvas>
                </div>
                <div class="chart-container mt-3">
                    <h5><i class="fas fa-chart-line text-primary"></i> Evolução por Bimestre</h5>
                    <canvas id="graficoEvolucao" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Alunos (quando turma e disciplina selecionadas) -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos_relatorio)): ?>
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><i class="fas fa-users"></i> Detalhamento por Aluno</h5>
                <div>
                    <button onclick="gerarPDF()" class="btn btn-sm btn-danger">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button onclick="gerarExcel()" class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-relatorio">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>MAC</th>
                            <th>NPT</th>
                            <th>Exame Normal</th>
                            <th>Exame Recurso</th>
                            <th>Média Final</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos_relatorio as $index => $aluno): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                            <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            <td><?php echo number_format($aluno['mac'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($aluno['npt'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($aluno['exame_normal'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($aluno['exame_recurso'] ?? 0, 1); ?></td>
                            <td><strong><?php echo number_format($aluno['media_final'] ?? 0, 1); ?></strong></td>
                            <td><?php echo getSituacaoBadge($aluno['situacao']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="7">Média da Turma</th>
                            <th colspan="2">
                                <?php 
                                $media_turma = 0;
                                $count = 0;
                                foreach ($alunos_relatorio as $a) {
                                    if ($a['media_final'] > 0) {
                                        $media_turma += $a['media_final'];
                                        $count++;
                                    }
                                }
                                echo number_format($count > 0 ? $media_turma / $count : 0, 1);
                                ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0): ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-info-circle"></i> Nenhum aluno encontrado para os filtros selecionados.
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dados para os gráficos
        const desempenhoData = <?php 
            $labels = [];
            $medias = [];
            $aprovados = [];
            $recuperacao = [];
            $reprovados = [];
            foreach ($desempenho_turmas as $t) {
                $labels[] = $t['turma_nome'];
                $medias[] = $t['media_turma'] ?? 0;
                $aprovados[] = $t['aprovados'] ?? 0;
                $recuperacao[] = $t['recuperacao'] ?? 0;
                $reprovados[] = $t['reprovados'] ?? 0;
            }
            echo json_encode(['labels' => $labels, 'medias' => $medias, 'aprovados' => $aprovados, 'recuperacao' => $recuperacao, 'reprovados' => $reprovados]);
        ?>;
        
        const evolucaoData = <?php 
            $bimestres = [];
            $medias_bimestre = [];
            foreach ($evolucao as $e) {
                $bimestres[] = $e['bimestre'] . 'º Bimestre';
                $medias_bimestre[] = $e['media_geral'] ?? 0;
            }
            echo json_encode(['bimestres' => $bimestres, 'medias' => $medias_bimestre]);
        ?>;
        
        const totalAprovados = <?php echo $stats['total_aprovados'] ?? 0; ?>;
        const totalRecuperacao = <?php echo $stats['total_recuperacao'] ?? 0; ?>;
        const totalReprovados = <?php echo $stats['total_reprovados'] ?? 0; ?>;
        
        // Gráfico de Desempenho por Turma
        const ctxDesempenho = document.getElementById('graficoDesempenho').getContext('2d');
        new Chart(ctxDesempenho, {
            type: 'bar',
            data: {
                labels: desempenhoData.labels,
                datasets: [
                    { label: 'Média da Turma', data: desempenhoData.medias, backgroundColor: '#006B3E', borderRadius: 5 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { y: { beginAtZero: true, max: 20, title: { display: true, text: 'Média (0-20)' } } },
                plugins: { legend: { position: 'top' } }
            }
        });
        
        // Gráfico de Distribuição de Resultados
        const ctxDistribuicao = document.getElementById('graficoDistribuicao').getContext('2d');
        new Chart(ctxDistribuicao, {
            type: 'pie',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [totalAprovados, totalRecuperacao, totalReprovados],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        // Gráfico de Evolução por Bimestre
        const ctxEvolucao = document.getElementById('graficoEvolucao').getContext('2d');
        new Chart(ctxEvolucao, {
            type: 'line',
            data: {
                labels: evolucaoData.bimestres,
                datasets: [{
                    label: 'Média Geral',
                    data: evolucaoData.medias,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { y: { beginAtZero: true, max: 20, title: { display: true, text: 'Média (0-20)' } } }
            }
        });
        
        function gerarPDF() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            var anoLetivoId = <?php echo $ano_letivo_id; ?>;
            window.open(`gerar_relatorio_pdf.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}&ano_letivo_id=${anoLetivoId}`, '_blank');
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            var anoLetivoId = <?php echo $ano_letivo_id; ?>;
            window.location.href = `gerar_relatorio_excel.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}&ano_letivo_id=${anoLetivoId}`;
        }
    </script>
</body>
</html>