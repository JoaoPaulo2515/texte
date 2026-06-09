<?php
// aluno/tarefas/notas_tarefas.php - Notas e Desempenho do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, e.foto, e.data_nascimento,
                     tur.nome as turma_nome, tur.ano as turma_ano
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              WHERE e.id = :id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// FILTROS
// ============================================
$disciplina_filtro = isset($_GET['disciplina']) ? (int)$_GET['disciplina'] : 0;
$periodo_filtro = isset($_GET['periodo']) ? $_GET['periodo'] : 'todas';
$ano_letivo = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                    FROM disciplinas d
                    JOIN tarefas t ON t.disciplina_id = d.id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND m.status = 'ativa'
                    AND d.escola_id = :escola_id
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos disponíveis
$sql_anos = "SELECT DISTINCT YEAR(t.data_entrega) as ano
             FROM tarefas t
             JOIN turmas tur ON tur.id = t.turma_id
             JOIN matriculas m ON m.turma_id = tur.id
             WHERE m.estudante_id = :aluno_id
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

// ============================================
// BUSCAR NOTAS
// ============================================

$sql_notas = "SELECT 
                t.id as tarefa_id,
                t.titulo,
                t.descricao,
                t.data_entrega as data_limite,
                t.max_pontos,
                d.id as disciplina_id,
                d.nome as disciplina_nome,
                d.codigo as disciplina_codigo,
                p.nome as professor_nome,
                r.id as resposta_id,
                r.nota,
                r.comentario_professor,
                r.data_entrega as data_entrega,
                r.status as resposta_status,
                CASE 
                    WHEN r.status = 'corrigido' THEN 'corrigida'
                    WHEN r.status = 'entregue' THEN 'entregue'
                    ELSE 'pendente'
                END as situacao,
                CASE 
                    WHEN r.nota IS NULL THEN NULL
                    WHEN r.nota >= (t.max_pontos * 0.9) THEN 'excelente'
                    WHEN r.nota >= (t.max_pontos * 0.7) THEN 'bom'
                    WHEN r.nota >= (t.max_pontos * 0.5) THEN 'regular'
                    ELSE 'insuficiente'
                END as desempenho
              FROM tarefas t
              JOIN disciplinas d ON d.id = t.disciplina_id
              JOIN funcionarios p ON p.id = t.professor_id
              JOIN turmas tur ON tur.id = t.turma_id
              JOIN matriculas m ON m.turma_id = tur.id
              LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
              WHERE m.estudante_id = :aluno_id 
              AND m.status = 'ativa'
              AND t.status = 'publicada'
              AND YEAR(t.data_entrega) = :ano_letivo";

if ($disciplina_filtro > 0) {
    $sql_notas .= " AND t.disciplina_id = :disciplina_id";
}

if ($periodo_filtro == 'corrigidas') {
    $sql_notas .= " AND r.status = 'corrigido'";
} elseif ($periodo_filtro == 'pendentes') {
    $sql_notas .= " AND (r.status IS NULL OR r.status != 'corrigido')";
} elseif ($periodo_filtro == 'excelente') {
    $sql_notas .= " AND r.nota >= (t.max_pontos * 0.9)";
} elseif ($periodo_filtro == 'insuficiente') {
    $sql_notas .= " AND r.nota < (t.max_pontos * 0.5)";
}

$sql_notas .= " ORDER BY t.data_entrega DESC";

$stmt_notas = $conn->prepare($sql_notas);
$params = [
    ':aluno_id' => $aluno_id,
    ':ano_letivo' => $ano_letivo
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
$stmt_notas->execute($params);
$tarefas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS E MÉDIAS
// ============================================

// Total de tarefas
$total_tarefas = count($tarefas);
$total_corrigidas = count(array_filter($tarefas, function($t) { 
    return $t['situacao'] == 'corrigida'; 
}));
$total_entregues = count(array_filter($tarefas, function($t) { 
    return $t['situacao'] == 'entregue'; 
}));
$total_pendentes = count(array_filter($tarefas, function($t) { 
    return $t['situacao'] == 'pendente'; 
}));

// Média geral (apenas corrigidas)
$notas_corrigidas = array_filter(array_column($tarefas, 'nota'), function($n) { 
    return $n !== null; 
});
$media_geral = !empty($notas_corrigidas) ? array_sum($notas_corrigidas) / count($notas_corrigidas) : 0;
$media_percentual = $media_geral > 0 ? ($media_geral / 10) * 100 : 0;

// Melhor e pior nota
$melhor_nota = !empty($notas_corrigidas) ? max($notas_corrigidas) : 0;
$pior_nota = !empty($notas_corrigidas) ? min($notas_corrigidas) : 0;

// Médias por disciplina
$medias_disciplinas = [];
foreach ($disciplinas as $disciplina) {
    $tarefas_disc = array_filter($tarefas, function($t) use ($disciplina) {
        return $t['disciplina_id'] == $disciplina['id'] && $t['nota'] !== null;
    });
    $notas_disc = array_column($tarefas_disc, 'nota');
    $max_pontos = array_sum(array_column($tarefas_disc, 'max_pontos'));
    $total_notas = array_sum($notas_disc);
    
    $medias_disciplinas[] = [
        'id' => $disciplina['id'],
        'nome' => $disciplina['nome'],
        'codigo' => $disciplina['codigo'],
        'cor' => $disciplina['cor'] ?? '#006B3E',
        'total_tarefas' => count($tarefas_disc),
        'media' => !empty($notas_disc) ? array_sum($notas_disc) / count($notas_disc) : 0,
        'soma_notas' => $total_notas,
        'max_possivel' => $max_pontos,
        'percentual' => $max_pontos > 0 ? ($total_notas / $max_pontos) * 100 : 0
    ];
}

// Estatísticas por mês
$estatisticas_mensais = [];
foreach ($tarefas as $tarefa) {
    if ($tarefa['nota'] === null) continue;
    
    $mes = date('Y-m', strtotime($tarefa['data_entrega']));
    if (!isset($estatisticas_mensais[$mes])) {
        $estatisticas_mensais[$mes] = [
            'mes' => $mes,
            'total_notas' => 0,
            'quantidade' => 0,
            'melhor_nota' => 0,
            'pior_nota' => 10
        ];
    }
    $estatisticas_mensais[$mes]['total_notas'] += $tarefa['nota'];
    $estatisticas_mensais[$mes]['quantidade']++;
    $estatisticas_mensais[$mes]['melhor_nota'] = max($estatisticas_mensais[$mes]['melhor_nota'], $tarefa['nota']);
    $estatisticas_mensais[$mes]['pior_nota'] = min($estatisticas_mensais[$mes]['pior_nota'], $tarefa['nota']);
}

foreach ($estatisticas_mensais as &$est) {
    $est['media'] = $est['total_notas'] / $est['quantidade'];
}
$estatisticas_mensais = array_reverse($estatisticas_mensais);

// Distribuição de desempenho
$distribuicao = [
    'excelente' => count(array_filter($tarefas, function($t) { return $t['desempenho'] == 'excelente'; })),
    'bom' => count(array_filter($tarefas, function($t) { return $t['desempenho'] == 'bom'; })),
    'regular' => count(array_filter($tarefas, function($t) { return $t['desempenho'] == 'regular'; })),
    'insuficiente' => count(array_filter($tarefas, function($t) { return $t['desempenho'] == 'insuficiente'; })),
    'pendente' => $total_pendentes + $total_entregues
];


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Notas | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .media-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(#006B3E 0deg, #e0e0e0 0deg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            position: relative;
        }
        .media-inner {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .media-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        
        .disciplina-card { cursor: pointer; transition: all 0.3s; }
        .disciplina-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .nota-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .nota-excelente { background: #28a745; color: white; }
        .nota-bom { background: #17a2b8; color: white; }
        .nota-regular { background: #ffc107; color: #000; }
        .nota-insuficiente { background: #dc3545; color: white; }
        .nota-pendente { background: #6c757d; color: white; }
        
        .progress-bar-custom { height: 8px; border-radius: 4px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .btn-export { background: #17a2b8; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; }
        .btn-export:hover { background: #138496; }
        
        .tabela-notas th { background: #f8f9fa; }
        .tabela-notas tr:hover { background: #f5f5f5; }
        
        @media print {
            .no-print { display: none; }
            .card { break-inside: avoid; }
        }
    </style>
</head>
<body>
  <?php include 'includes/menu_aluno.php'; ?>
   </br> </br> </br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-chart-line"></i> Minhas Notas</h2>
                <p class="text-muted">Acompanhe seu desempenho acadêmico</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir Relatório
                </button>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_tarefas; ?></div>
                    <div class="stat-label"><i class="fas fa-tasks"></i> Total de Tarefas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_corrigidas; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Corrigidas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_entregues; ?></div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Aguardando Correção</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-secondary"><?php echo $total_pendentes; ?></div>
                    <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pendentes</div>
                </div>
            </div>
        </div>
        
        <!-- Média Geral e Melhores Notas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="media-circle" id="mediaGeralCircle">
                            <div class="media-inner">
                                <div class="media-value"><?php echo number_format($media_geral, 1); ?></div>
                                <small>de 10</small>
                            </div>
                        </div>
                        <h6 class="mt-3">Média Geral</h6>
                        <p class="text-muted"><?php echo number_format($media_percentual, 1); ?>% de aproveitamento</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-star fa-3x text-warning mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($melhor_nota, 1); ?></h3>
                        <p class="text-muted">Melhor Nota</p>
                        <hr>
                        <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($pior_nota, 1); ?></h3>
                        <p class="text-muted">Pior Nota</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6><i class="fas fa-chart-pie"></i> Distribuição de Desempenho</h6>
                        <canvas id="distribuicaoChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Ano Letivo</label>
                        <select name="ano" class="form-select">
                            <?php foreach ($anos_disponiveis as $ano): ?>
                            <option value="<?php echo $ano; ?>" <?php echo $ano_letivo == $ano ? 'selected' : ''; ?>>
                                <?php echo $ano; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Disciplina</label>
                        <select name="disciplina" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                            <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Período</label>
                        <select name="periodo" class="form-select">
                            <option value="todas" <?php echo $periodo_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="corrigidas" <?php echo $periodo_filtro == 'corrigidas' ? 'selected' : ''; ?>>Corrigidas</option>
                            <option value="pendentes" <?php echo $periodo_filtro == 'pendentes' ? 'selected' : ''; ?>>Pendentes</option>
                            <option value="excelente" <?php echo $periodo_filtro == 'excelente' ? 'selected' : ''; ?>>Nota Excelente (≥90%)</option>
                            <option value="insuficiente" <?php echo $periodo_filtro == 'insuficiente' ? 'selected' : ''; ?>>Insuficiente (<50%)</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($disciplina_filtro > 0 || $periodo_filtro != 'todas'): ?>
                        <a href="notas_tarefas.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Médias por Disciplina -->
        <?php if (!empty($medias_disciplinas)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Médias por Disciplina</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($medias_disciplinas as $disciplina): ?>
                    <div class="col-md-4 mb-3">
                        <div class="disciplina-card p-3 border rounded" onclick="filtrarDisciplina(<?php echo $disciplina['id']; ?>)">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">
                                    <i class="fas fa-book" style="color: <?php echo $disciplina['cor']; ?>"></i>
                                    <?php echo htmlspecialchars($disciplina['nome']); ?>
                                </h6>
                                <span class="badge bg-secondary"><?php echo $disciplina['total_tarefas']; ?> tarefas</span>
                            </div>
                            <div class="mt-2">
                                <div class="d-flex justify-content-between">
                                    <small>Média: <?php echo number_format($disciplina['media'], 1); ?></small>
                                    <small>Aproveitamento: <?php echo number_format($disciplina['percentual'], 1); ?>%</small>
                                </div>
                                <div class="progress progress-bar-custom mt-1">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo $disciplina['percentual']; ?>%; background: <?php echo $disciplina['cor']; ?>"
                                         aria-valuenow="<?php echo $disciplina['percentual']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Evolução Mensal -->
        <?php if (!empty($estatisticas_mensais)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Evolução Mensal das Notas</h5>
            </div>
            <div class="card-body">
                <canvas id="evolucaoChart" height="100"></canvas>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Tabela de Notas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Detalhamento das Notas</h5>
                <small><?php echo $total_corrigidas; ?> tarefas corrigidas | Média: <?php echo number_format($media_geral, 1); ?></small>
            </div>
            <div class="card-body">
                <?php if (empty($tarefas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma tarefa encontrada com os filtros selecionados.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover tabela-notas">
                            <thead>
                                <tr>
                                    <th>Tarefa</th>
                                    <th>Disciplina</th>
                                    <th>Data Entrega</th>
                                    <th>Professor</th>
                                    <th>Nota</th>
                                    <th>Máx</th>
                                    <th>%</th>
                                    <th>Status</th>
                                    <th>Comentário</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tarefas as $tarefa): ?>
                                <?php
                                $percentual = $tarefa['nota'] ? ($tarefa['nota'] / $tarefa['max_pontos']) * 100 : 0;
                                $notaClass = '';
                                if ($tarefa['nota'] !== null) {
                                    if ($percentual >= 90) $notaClass = 'nota-excelente';
                                    elseif ($percentual >= 70) $notaClass = 'nota-bom';
                                    elseif ($percentual >= 50) $notaClass = 'nota-regular';
                                    else $notaClass = 'nota-insuficiente';
                                } else {
                                    $notaClass = 'nota-pendente';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($tarefa['titulo']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($tarefa['descricao'], 0, 50)) . (strlen($tarefa['descricao']) > 50 ? '...' : ''); ?></small>
                                    </td>
                                    <td>
                                        <i class="fas fa-book" style="color: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>"></i>
                                        <?php echo htmlspecialchars($tarefa['disciplina_nome']); ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($tarefa['data_limite'])); ?></td>
                                    <td><?php echo htmlspecialchars($tarefa['professor_nome']); ?></td>
                                    <td>
                                        <?php if ($tarefa['nota'] !== null): ?>
                                            <span class="nota-badge <?php echo $notaClass; ?>">
                                                <?php echo number_format($tarefa['nota'], 1); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="nota-badge <?php echo $notaClass; ?>">
                                                <i class="fas fa-clock"></i> Aguardando
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($tarefa['max_pontos'], 1); ?></td>
                                    <td>
                                        <?php if ($tarefa['nota'] !== null): ?>
                                            <div class="progress" style="height: 5px; width: 60px;">
                                                <div class="progress-bar" role="progressbar" 
                                                     style="width: <?php echo $percentual; ?>%; background: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>"></div>
                                            </div>
                                            <small><?php echo number_format($percentual, 0); ?>%</small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tarefa['situacao'] == 'corrigida'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle"></i> Corrigida</span>
                                        <?php elseif ($tarefa['situacao'] == 'entregue'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Aguardando</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-hourglass"></i> Pendente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tarefa['comentario_professor']): ?>
                                        <button class="btn btn-sm btn-info" onclick="verComentario('<?php echo addslashes($tarefa['comentario_professor']); ?>')">
                                            <i class="fas fa-comment-dots"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAL/MÉDIA:</td>
                                    <td colspan="4">
                                        Média Geral: <?php echo number_format($media_geral, 1); ?>
                                        (<?php echo number_format($media_percentual, 1); ?>%)
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Legenda e Informações -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Legenda de Desempenho</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <span class="nota-badge nota-excelente">Excelente (≥90%)</span>
                        <span class="ms-2">Desempenho excepcional</span>
                    </div>
                    <div class="col-md-3">
                        <span class="nota-badge nota-bom">Bom (70-89%)</span>
                        <span class="ms-2">Bom desempenho</span>
                    </div>
                    <div class="col-md-3">
                        <span class="nota-badge nota-regular">Regular (50-69%)</span>
                        <span class="ms-2">Dentro da média</span>
                    </div>
                    <div class="col-md-3">
                        <span class="nota-badge nota-insuficiente">Insuficiente (<50%)</span>
                        <span class="ms-2">Precisa melhorar</span>
                    </div>
                </div>
                <hr>
                <div class="alert alert-info mt-2">
                    <i class="fas fa-lightbulb"></i> <strong>Dica:</strong> 
                    Acompanhe seu desempenho regularmente. Se estiver com notas insuficientes, procure o professor para orientações de recuperação.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Comentário -->
    <div class="modal fade" id="comentarioModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-comment-dots"></i> Comentário do Professor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="comentarioConteudo">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico de Distribuição
        const ctxDist = document.getElementById('distribuicaoChart')?.getContext('2d');
        if (ctxDist) {
            new Chart(ctxDist, {
                type: 'doughnut',
                data: {
                    labels: ['Excelente', 'Bom', 'Regular', 'Insuficiente', 'Pendente'],
                    datasets: [{
                        data: [<?php echo $distribuicao['excelente']; ?>, 
                               <?php echo $distribuicao['bom']; ?>, 
                               <?php echo $distribuicao['regular']; ?>, 
                               <?php echo $distribuicao['insuficiente']; ?>, 
                               <?php echo $distribuicao['pendente']; ?>],
                        backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { size: 11 } } },
                        tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw + ' tarefas'; } } }
                    }
                }
            });
        }
        
        // Gráfico de Evolução Mensal
        const ctxEvol = document.getElementById('evolucaoChart')?.getContext('2d');
        if (ctxEvol && <?php echo count($estatisticas_mensais); ?> > 0) {
            const mesesLabels = [<?php 
                $labels = [];
                foreach ($estatisticas_mensais as $est) {
                    $labels[] = "'" . date('M/Y', strtotime($est['mes'] . '-01')) . "'";
                }
                echo implode(',', $labels);
            ?>];
            const mediasValues = [<?php 
                $medias = [];
                foreach ($estatisticas_mensais as $est) {
                    $medias[] = number_format($est['media'], 1);
                }
                echo implode(',', $medias);
            ?>];
            
            new Chart(ctxEvol, {
                type: 'line',
                data: {
                    labels: mesesLabels,
                    datasets: [{
                        label: 'Média das Notas',
                        data: mediasValues,
                        borderColor: '#006B3E',
                        backgroundColor: 'rgba(0, 107, 62, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#006B3E',
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 10,
                            title: { display: true, text: 'Nota (0-10)' }
                        },
                        x: { title: { display: true, text: 'Mês' } }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Média: ' + context.raw + ' pontos';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Gráfico de Média Geral (Circular)
        const mediaPercentual = <?php echo $media_percentual; ?>;
        const circle = document.getElementById('mediaGeralCircle');
        if (circle) {
            const degrees = (mediaPercentual / 100) * 360;
            circle.style.background = `conic-gradient(#006B3E 0deg ${degrees}deg, #e0e0e0 ${degrees}deg 360deg)`;
        }
        
        // Filtrar por disciplina
        function filtrarDisciplina(disciplinaId) {
            window.location.href = `notas_tarefas.php?disciplina=${disciplinaId}&ano=<?php echo $ano_letivo; ?>`;
        }
        
        // Ver comentário
        function verComentario(comentario) {
            document.getElementById('comentarioConteudo').innerHTML = `
                <div class="alert alert-light">
                    <i class="fas fa-quote-left"></i>
                    <p class="mt-2">${comentario.replace(/\n/g, '<br>')}</p>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('comentarioModal')).show();
        }
        
        // Atualizar ao mudar ano/disciplina
        document.querySelectorAll('select[name="ano"], select[name="disciplina"], select[name="periodo"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>