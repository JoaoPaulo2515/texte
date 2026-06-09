<?php
// escola/professor/relatorios/estatistica_disciplina.php - Estatística por Disciplina

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
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
// BUSCAR DADOS DA DISCIPLINA SELECIONADA
// ============================================
$disciplina_info = null; 
$turmas_disciplina = [];
$estatisticas = [];

if ($disciplina_id > 0) {
    // Buscar informações da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar turmas onde o professor leciona esta disciplina
    $sql_turmas = "
        SELECT DISTINCT 
            t.id, t.nome, t.ano, t.turno, t.sala,
            (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
        FROM professor_disciplina_turma pdt
        INNER JOIN turmas t ON t.id = pdt.turma_id
        WHERE pdt.professor_id = :professor_id AND pdt.disciplina_id = :disciplina_id
        ORDER BY t.ano, t.nome
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':professor_id' => $professor_id, ':disciplina_id' => $disciplina_id]);
    $turmas_disciplina = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar estatísticas por turma
    foreach ($turmas_disciplina as $turma) {
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
            AND n.estudante_id IN (SELECT estudante_id FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa')
        ";
        $stmt_stats = $conn->prepare($sql_stats);
        $stmt_stats->execute([
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id,
            ':turma_id' => $turma['id']
        ]);
        $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
        
        $estatisticas[] = [
            'turma_nome' => $turma['nome'],
            'turma_ano' => $turma['ano'],
            'turma_turno' => $turma['turno'],
            'turma_sala' => $turma['sala'],
            'total_alunos' => $turma['total_alunos'],
            'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
            'media_geral' => round($stats['media_geral'] ?? 0, 1),
            'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
            'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
            'aprovados' => $stats['total_aprovados'] ?? 0,
            'recuperacao' => $stats['total_recuperacao'] ?? 0,
            'reprovados' => $stats['total_reprovados'] ?? 0,
            'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
            'percentual_com_nota' => $turma['total_alunos'] > 0 ? round(($stats['total_alunos_com_nota'] / $turma['total_alunos']) * 100, 1) : 0
        ];
    }
    
    // Calcular médias gerais da disciplina
    $disciplina_media_geral = 0;
    $disciplina_total_aprovados = 0;
    $disciplina_total_alunos = 0;
    $count_turmas = 0;
    $melhor_turma = '';
    $melhor_media = 0;
    $pior_turma = '';
    $pior_media = 100;
    
    foreach ($estatisticas as $est) {
        if ($est['media_geral'] > 0) {
            $disciplina_media_geral += $est['media_geral'];
            $count_turmas++;
            
            if ($est['media_geral'] > $melhor_media) {
                $melhor_media = $est['media_geral'];
                $melhor_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
            }
            if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
                $pior_media = $est['media_geral'];
                $pior_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
            }
        }
        $disciplina_total_aprovados += $est['aprovados'];
        $disciplina_total_alunos += $est['total_alunos'];
    }
    $disciplina_media_geral = $count_turmas > 0 ? round($disciplina_media_geral / $count_turmas, 1) : 0;
    $disciplina_taxa_aprovacao = $disciplina_total_alunos > 0 ? round(($disciplina_total_aprovados / $disciplina_total_alunos) * 100, 1) : 0;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatística por Disciplina | Professor | SIGE Angola</title>
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
        .turma-card {
            transition: transform 0.2s;
        }
        .turma-card:hover {
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
                    <h2><i class="fas fa-chart-pie"></i> Estatística por Disciplina</h2>
                    <p>Análise estatística detalhada do desempenho por disciplina</p>
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
                    <label class="form-label">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione uma disciplina</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']) . ' (' . htmlspecialchars($disciplina['codigo'] ?? '-') . ')'; ?>
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
        
        <?php if ($disciplina_id > 0 && $disciplina_info): ?>
        
        <!-- Informações da Disciplina -->
        <div class="card mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações da Disciplina</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <small class="text-muted">Disciplina</small>
                        <h6><?php echo htmlspecialchars($disciplina_info['nome']); ?></h6>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Código</small>
                        <h6><?php echo htmlspecialchars($disciplina_info['codigo'] ?? '-'); ?></h6>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted">Ano Letivo</small>
                        <h6><?php echo $ano_letivo_ano; ?></h6>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas Gerais da Disciplina -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($turmas_disciplina); ?></div>
                    <div class="stat-label">Turmas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $disciplina_media_geral; ?></div>
                    <div class="stat-label">Média Geral</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $disciplina_taxa_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
        </div>
        
        <!-- Destaques -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-number text-success">🏆</div>
                    <div class="stat-label">Melhor Turma</div>
                    <h5><?php echo $melhor_turma ?: '-'; ?></h5>
                    <small>Média: <?php echo $melhor_media > 0 ? $melhor_media : '-'; ?></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-number text-warning">⚠️</div>
                    <div class="stat-label">Turma com Menor Média</div>
                    <h5><?php echo $pior_turma ?: '-'; ?></h5>
                    <small>Média: <?php echo $pior_media < 100 ? $pior_media : '-'; ?></small>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Estatísticas por Turma -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Estatísticas por Turma - <?php echo htmlspecialchars($disciplina_info['nome']); ?> (<?php echo $bimestre; ?>º Bimestre)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($estatisticas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma estatística disponível para esta disciplina.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-stats">
                            <thead>
                                <tr>
                                    <th rowspan="2">Turma</th>
                                    <th rowspan="2">Turno</th>
                                    <th rowspan="2">Sala</th>
                                    <th colspan="2">Alunos</th>
                                    <th colspan="3">Notas</th>
                                    <th colspan="3">Resultados</th>
                                    <th rowspan="2">Aprovação</th>
                                </tr>
                                <tr>
                                    <th>Total</th><th>C/ Nota</th>
                                    <th>Média</th><th>Mín.</th><th>Máx.</th>
                                    <th>Aprov</th><th>Recup</th><th>Reprov</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estatisticas as $est): ?>
                                <tr>
                                    <td class="text-start"><strong><?php echo $est['turma_ano'] . 'ª ' . htmlspecialchars($est['turma_nome']); ?></strong></td>
                                    <td><?php echo ucfirst($est['turma_turno']); ?></td>
                                    <td><?php echo $est['turma_sala'] ?: '-'; ?></td>
                                    <td><?php echo $est['total_alunos']; ?></td>
                                    <td><?php echo $est['com_nota']; ?> (<?php echo $est['percentual_com_nota']; ?>%)</td>
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
                                <tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Média por Turma</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="chartMedias" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Taxa de Aprovação por Turma</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="chartAprovacao" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($disciplina_id > 0 && !$disciplina_info): ?>
            <div class="alert alert-danger text-center">
                <i class="fas fa-exclamation-triangle"></i> Disciplina não encontrada ou você não tem acesso a ela.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma disciplina para visualizar as estatísticas.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($disciplina_id > 0 && !empty($estatisticas)): ?>
        // Gráfico de Médias
        const mediasCtx = document.getElementById('chartMedias').getContext('2d');
        const turmas = [<?php foreach ($estatisticas as $est) echo "'" . $est['turma_ano'] . 'ª ' . addslashes($est['turma_nome']) . "', "; ?>];
        const medias = [<?php foreach ($estatisticas as $est) echo $est['media_geral'] . ", "; ?>];
        
        new Chart(mediasCtx, {
            type: 'bar',
            data: {
                labels: turmas,
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
                labels: turmas,
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
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (disciplinaId) {
                window.open(`gerar_pdf_estatistica_disciplina.php?disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione uma disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (disciplinaId) {
                window.location.href = `gerar_excel_estatistica_disciplina.php?disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione uma disciplina primeiro!');
            }
        }
    </script>
</body>
</html>