<?php
// escola/professor/relatorios/estatistica_turma.php - Estatística por Turma

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala,
        (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA TURMA SELECIONADA
// ============================================
$turma_info = null;
$disciplinas_turma = [];
$estatisticas = [];

if ($turma_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas da turma
    $sql_disciplinas = "
        SELECT DISTINCT 
            d.id, d.nome, d.codigo
        FROM professor_disciplina_turma pdt
        INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
        WHERE pdt.professor_id = :professor_id AND pdt.turma_id = :turma_id
        ORDER BY d.nome
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
    $disciplinas_turma = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar estatísticas por disciplina
    foreach ($disciplinas_turma as $disciplina) {
        $sql_stats = "
            SELECT 
                COUNT(DISTINCT n.estudante_id) as total_alunos_com_nota,
                AVG(n.media_final) as media_geral,
                MAX(n.media_final) as maior_nota,
                MIN(CASE WHEN n.media_final > 0 THEN n.media_final END) as menor_nota,
                SUM(CASE WHEN n.status = 'aprovado' THEN 1 ELSE 0 END) as total_aprovados,
                SUM(CASE WHEN n.status = 'recuperacao' THEN 1 ELSE 0 END) as total_recuperacao,
                SUM(CASE WHEN n.status = 'reprovado' THEN 1 ELSE 0 END) as total_reprovados
            FROM notas n
            WHERE n.disciplina_id = :disciplina_id 
            AND n.bimestre = :bimestre 
            AND n.ano_letivo_id = :ano_letivo_id
        ";
        $stmt_stats = $conn->prepare($sql_stats);
        $stmt_stats->execute([
            ':disciplina_id' => $disciplina['id'],
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
        
        // Buscar total de alunos da turma
        $sql_total_alunos = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
        $stmt_total = $conn->prepare($sql_total_alunos);
        $stmt_total->execute([':turma_id' => $turma_id]);
        $total_alunos = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
        
        $estatisticas[$disciplina['id']] = [
            'disciplina_nome' => $disciplina['nome'],
            'disciplina_codigo' => $disciplina['codigo'],
            'total_alunos' => $total_alunos,
            'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
            'media_geral' => round($stats['media_geral'] ?? 0, 1),
            'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
            'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
            'aprovados' => $stats['total_aprovados'] ?? 0,
            'recuperacao' => $stats['total_recuperacao'] ?? 0,
            'reprovados' => $stats['total_reprovados'] ?? 0,
            'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0
        ];
    }
    
    // Calcular médias gerais da turma
    $turma_media_geral = 0;
    $turma_total_aprovados = 0;
    $turma_total_alunos = 0;
    $count_disciplinas = 0;
    
    foreach ($estatisticas as $est) {
        if ($est['media_geral'] > 0) {
            $turma_media_geral += $est['media_geral'];
            $count_disciplinas++;
        }
        $turma_total_aprovados += $est['aprovados'];
        $turma_total_alunos += $est['total_alunos'];
    }
    $turma_media_geral = $count_disciplinas > 0 ? round($turma_media_geral / $count_disciplinas, 1) : 0;
    $turma_taxa_aprovacao = $turma_total_alunos > 0 ? round(($turma_total_aprovados / $turma_total_alunos) * 100, 1) : 0;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatística por Turma | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #006B3E;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .table-stats th {
            background: #006B3E;
            color: white;
            text-align: center;
            font-size: 12px;
        }
        .table-stats td {
            text-align: center;
            vertical-align: middle;
        }
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .badge-recuperacao {
            background: #ffc107;
            color: #212529;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .btn-excel {
            background: #28a745;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        .btn-excel:hover {
            background: #1e7e34;
            color: white;
        }
        .btn-pdf {
            background: #dc3545;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        .btn-pdf:hover {
            background: #bd2130;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
            }
        }
        .progress {
            height: 10px;
            border-radius: 5px;
        }
        .disciplina-card {
            transition: transform 0.2s;
        }
        .disciplina-card:hover {
            transform: translateY(-3px);
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include '../includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chart-bar"></i> Estatística por Turma</h2>
                    <p>Análise estatística detalhada do desempenho da turma</p>
                </div>
                <div class="no-print">
                    <a href="index.php" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button onclick="window.print()" class="btn-excel btn me-2" style="background:#17a2b8;">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="gerarPDF()" class="btn-pdf btn me-2">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button onclick="gerarExcel()" class="btn-excel btn">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione uma turma</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ') - ' . $turma['total_alunos'] . ' alunos'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && $turma_info): ?>
        
        <!-- Informações da Turma -->
        <div class="card mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações da Turma</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <small class="text-muted">Turma</small>
                        <h6><?php echo $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']); ?></h6>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Turno</small>
                        <h6><?php echo ucfirst($turma_info['turno']); ?></h6>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Sala</small>
                        <h6><?php echo $turma_info['sala'] ?: 'Não definida'; ?></h6>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Ano Letivo</small>
                        <h6><?php echo $ano_letivo_ano; ?></h6>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas Gerais da Turma -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($disciplinas_turma); ?></div>
                    <div class="stat-label">Disciplinas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $turma_media_geral; ?></div>
                    <div class="stat-label">Média Geral da Turma</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $turma_taxa_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Estatísticas por Disciplina -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Estatísticas por Disciplina - <?php echo $bimestre; ?>º Bimestre</h5>
            </div>
            <div class="card-body">
                <?php if (empty($estatisticas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma estatística disponível para esta turma.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-stats">
                            <thead>
                                <tr>
                                    <th rowspan="2">Disciplina</th>
                                    <th rowspan="2">Código</th>
                                    <th colspan="2">Alunos</th>
                                    <th colspan="3">Notas</th>
                                    <th colspan="3">Resultados</th>
                                    <th rowspan="2">Aprovação</th>
                                </tr>
                                <tr>
                                    <th>Total</th><th>C/ Nota</th>
                                    <th>Média</th><th>Mínima</th><th>Máxima</th>
                                    <th>Aprov</th><th>Recup</th><th>Reprov</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estatisticas as $est): ?>
                                <tr>
                                    <td class="text-start"><strong><?php echo htmlspecialchars($est['disciplina_nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($est['disciplina_codigo'] ?? '-'); ?></td>
                                    <td><?php echo $est['total_alunos']; ?></td>
                                    <td><?php echo $est['com_nota']; ?> (\(\<?php echo $est['total_alunos'] > 0 ? round(($est['com_nota'] / $est['total_alunos']) * 100, 1) : 0; ?>%)</td>
                                    <td><strong><?php echo $est['media_geral']; ?></strong></td>
                                    <td><?php echo $est['menor_nota'] > 0 ? $est['menor_nota'] : '-'; ?></td>
                                    <td><?php echo $est['maior_nota'] > 0 ? $est['maior_nota'] : '-'; ?></td>
                                    <td><span class="badge-aprovado"><?php echo $est['aprovados']; ?></span></td>
                                    <td><span class="badge-recuperacao"><?php echo $est['recuperacao']; ?></span></td>
                                    <td><span class="badge-reprovado"><?php echo $est['reprovados']; ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="me-2"><?php echo $est['percentual_aprovacao']; ?>%</span>
                                            <div class="progress flex-grow-1">
                                                <div class="progress-bar bg-success" style="width: <?php echo $est['percentual_aprovacao']; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Gráfico de Comparação -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Média Geral por Disciplina</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="chartMedias" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Taxa de Aprovação por Disciplina</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="chartAprovacao" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && !$turma_info): ?>
            <div class="alert alert-danger text-center">
                <i class="fas fa-exclamation-triangle"></i> Turma não encontrada ou você não tem acesso a ela.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar as estatísticas.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($turma_id > 0 && !empty($estatisticas)): ?>
        // Gráfico de Médias
        const mediasCtx = document.getElementById('chartMedias').getContext('2d');
        const disciplinas = [<?php foreach ($estatisticas as $est) echo "'" . addslashes($est['disciplina_nome']) . "', "; ?>];
        const medias = [<?php foreach ($estatisticas as $est) echo $est['media_geral'] . ", "; ?>];
        
        new Chart(mediasCtx, {
            type: 'bar',
            data: {
                labels: disciplinas,
                datasets: [{
                    label: 'Média Geral',
                    data: medias,
                    backgroundColor: '#006B3E',
                    borderRadius: 5,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { callbacks: { label: function(context) { return 'Média: ' + context.raw; } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 20, title: { display: true, text: 'Nota' } },
                    x: { ticks: { autoSkip: false, rotation: 45, maxRotation: 45, minRotation: 45 } }
                }
            }
        });
        
        // Gráfico de Aprovação
        const aprovacaoCtx = document.getElementById('chartAprovacao').getContext('2d');
        const aprovacoes = [<?php foreach ($estatisticas as $est) echo $est['percentual_aprovacao'] . ", "; ?>];
        
        new Chart(aprovacaoCtx, {
            type: 'bar',
            data: {
                labels: disciplinas,
                datasets: [{
                    label: 'Taxa de Aprovação (%)',
                    data: aprovacoes,
                    backgroundColor: '#28a745',
                    borderRadius: 5,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { callbacks: { label: function(context) { return 'Aprovação: ' + context.raw + '%'; } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Percentual (%)' } },
                    x: { ticks: { autoSkip: false, rotation: 45, maxRotation: 45, minRotation: 45 } }
                }
            }
        });
        <?php endif; ?>
        
        function gerarPDF() {
            var turmaId = <?php echo $turma_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId) {
                window.open(`gerar_pdf_estatistica_turma.php?turma_id=${turmaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione uma turma primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId) {
                window.location.href = `gerar_excel_estatistica_turma.php?turma_id=${turmaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione uma turma primeiro!');
            }
        }
    </script>
</body>
</html>