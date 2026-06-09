<?php
// escola/relatorios/historico_faltas.php - Histórico de Faltas do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_letivos)) {
    $anos_letivos = [['id' => 1, 'ano' => date('Y')]];
}
if ($ano_letivo_id == 0) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS
// ============================================
$sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id ORDER BY nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA SELECIONADA
// ============================================
$alunos_turma = [];
if ($turma_id > 0) {
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusFalta($faltas, $aulas) {
    if ($aulas == 0) return ['texto' => 'Sem aulas', 'classe' => 'text-secondary', 'percentual' => 0];
    $percentual = ($faltas / $aulas) * 100;
    if ($percentual > 25) return ['texto' => 'Crítico', 'classe' => 'text-danger', 'percentual' => round($percentual, 1)];
    if ($percentual > 15) return ['texto' => 'Atenção', 'classe' => 'text-warning', 'percentual' => round($percentual, 1)];
    return ['texto' => 'Regular', 'classe' => 'text-success', 'percentual' => round($percentual, 1)];
}

function getStatusBadge($status) {
    switch ($status) {
        case 'presente':
            return '<span class="badge bg-success">Presente</span>';
        case 'falta':
            return '<span class="badge bg-danger">Falta</span>';
        case 'falta_justificada':
            return '<span class="badge bg-warning text-dark">Falta Justificada</span>';
        case 'atraso':
            return '<span class="badge bg-info">Atraso</span>';
        default:
            return '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
    }
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$aluno_info = null;
$faltas_por_ano = [];
$historico_detalhado = [];
$estatisticas_gerais = [
    'total_faltas' => 0,
    'total_aulas' => 0,
    'media_faltas' => 0,
    'total_justificadas' => 0,
    'total_atrasos' => 0,
    'disciplinas_com_falta' => 0
];

if ($aluno_id > 0) {
    // Buscar informações do aluno
    $sql_aluno = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento, e.bi, e.pai_nome, e.mae_nome
                  FROM estudantes e
                  WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $aluno_info = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_info) {
        // ============================================
        // QUERY 1: HISTÓRICO DETALHADO
        // ============================================
        $sql_detalhado = "SELECT 
                            c.id,
                            c.data_aula,
                            c.status,
                            c.minutos_atraso,
                            c.justificativa,
                            c.observacao,
                            c.data_lancamento,
                            d.nome as disciplina_nome,
                            d.codigo as disciplina_codigo,
                            t.nome as turma_nome,
                            t.ano as turma_ano,
                            a.ano as ano_letivo,
                            c.bimestre
                          FROM chamada c
                          INNER JOIN disciplinas d ON d.id = c.disciplina_id
                          INNER JOIN turmas t ON t.id = c.turma_id
                          INNER JOIN ano_letivo a ON a.id = c.ano_letivo_id
                          WHERE c.estudante_id = :aluno_id
                          AND c.escola_id = :escola_id
                          AND c.status IN ('falta', 'falta_justificada', 'atraso')";
        
        $params_detalhado = [
            ':aluno_id' => $aluno_id,
            ':escola_id' => $escola_id
        ];
        
        if ($ano_letivo_id > 0) {
            $sql_detalhado .= " AND c.ano_letivo_id = :ano_letivo_id";
            $params_detalhado[':ano_letivo_id'] = $ano_letivo_id;
        }
        
        if ($turma_id > 0) {
            $sql_detalhado .= " AND c.turma_id = :turma_id";
            $params_detalhado[':turma_id'] = $turma_id;
        }
        
        if ($disciplina_id > 0) {
            $sql_detalhado .= " AND c.disciplina_id = :disciplina_id";
            $params_detalhado[':disciplina_id'] = $disciplina_id;
        }
        
        if ($bimestre > 0) {
            $sql_detalhado .= " AND c.bimestre = :bimestre";
            $params_detalhado[':bimestre'] = $bimestre;
        }
        
        $sql_detalhado .= " ORDER BY c.data_aula DESC";
        
        $stmt_detalhado = $conn->prepare($sql_detalhado);
        $stmt_detalhado->execute($params_detalhado);
        $historico_detalhado = $stmt_detalhado->fetchAll(PDO::FETCH_ASSOC);
        
        // ============================================
        // QUERY 2: RESUMO POR ANO E DISCIPLINA (COM FILTRO BIMESTRE)
        // ============================================
        $sql_resumo = "SELECT 
                        a.ano as ano_letivo,
                        a.id as ano_letivo_id,
                        c.turma_id,
                        t.nome as turma_nome,
                        t.ano as turma_ano,
                        c.disciplina_id,
                        d.nome as disciplina_nome,
                        d.codigo as disciplina_codigo,
                        c.bimestre,
                        SUM(CASE WHEN c.status IN ('falta', 'falta_justificada') THEN 1 ELSE 0 END) as faltas,
                        SUM(CASE WHEN c.status = 'atraso' THEN 1 ELSE 0 END) as atrasos,
                        SUM(CASE WHEN c.status = 'falta_justificada' THEN 1 ELSE 0 END) as faltas_justificadas,
                        COUNT(*) as total_aulas
                      FROM chamada c
                      INNER JOIN turmas t ON t.id = c.turma_id
                      INNER JOIN disciplinas d ON d.id = c.disciplina_id
                      INNER JOIN ano_letivo a ON a.id = c.ano_letivo_id
                      WHERE c.estudante_id = :aluno_id
                      AND c.escola_id = :escola_id
                      AND c.status IN ('falta', 'falta_justificada', 'atraso')";
        
        $params_resumo = [
            ':aluno_id' => $aluno_id,
            ':escola_id' => $escola_id
        ];
        
        if ($ano_letivo_id > 0) {
            $sql_resumo .= " AND c.ano_letivo_id = :ano_letivo_id";
            $params_resumo[':ano_letivo_id'] = $ano_letivo_id;
        }
        
        if ($turma_id > 0) {
            $sql_resumo .= " AND c.turma_id = :turma_id";
            $params_resumo[':turma_id'] = $turma_id;
        }
        
        if ($disciplina_id > 0) {
            $sql_resumo .= " AND c.disciplina_id = :disciplina_id";
            $params_resumo[':disciplina_id'] = $disciplina_id;
        }
        
        if ($bimestre > 0) {
            $sql_resumo .= " AND c.bimestre = :bimestre";
            $params_resumo[':bimestre'] = $bimestre;
        }
        
        $sql_resumo .= " GROUP BY a.ano, a.id, c.turma_id, c.disciplina_id, c.bimestre
                         ORDER BY a.ano DESC, t.ano, t.nome, d.nome, c.bimestre";
        
        $stmt_resumo = $conn->prepare($sql_resumo);
        $stmt_resumo->execute($params_resumo);
        $faltas_raw = $stmt_resumo->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar faltas por ano e disciplina
        foreach ($faltas_raw as $falta) {
            $ano = $falta['ano_letivo'];
            $disciplina = $falta['disciplina_nome'];
            $bim = $falta['bimestre'];
            
            if (!isset($faltas_por_ano[$ano])) {
                $faltas_por_ano[$ano] = [
                    'ano_letivo' => $ano,
                    'ano_letivo_id' => $falta['ano_letivo_id'],
                    'turma' => $falta['turma_ano'] . 'ª ' . $falta['turma_nome'],
                    'disciplinas' => [],
                    'total_faltas_ano' => 0,
                    'total_atrasos_ano' => 0,
                    'total_aulas_ano' => 0
                ];
            }
            
            if (!isset($faltas_por_ano[$ano]['disciplinas'][$disciplina])) {
                $faltas_por_ano[$ano]['disciplinas'][$disciplina] = [
                    'disciplina_id' => $falta['disciplina_id'],
                    'disciplina_nome' => $disciplina,
                    'disciplina_codigo' => $falta['disciplina_codigo'],
                    'bimestres' => [],
                    'total_faltas' => 0,
                    'total_atrasos' => 0,
                    'total_aulas' => 0
                ];
            }
            
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['bimestres'][$bim] = [
                'faltas' => $falta['faltas'],
                'atrasos' => $falta['atrasos'],
                'total_aulas' => $falta['total_aulas']
            ];
            
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['total_faltas'] += $falta['faltas'];
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['total_atrasos'] += $falta['atrasos'];
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['total_aulas'] += $falta['total_aulas'];
            $faltas_por_ano[$ano]['total_faltas_ano'] += $falta['faltas'];
            $faltas_por_ano[$ano]['total_atrasos_ano'] += $falta['atrasos'];
            $faltas_por_ano[$ano]['total_aulas_ano'] += $falta['total_aulas'];
            
            $estatisticas_gerais['total_faltas'] += $falta['faltas'];
            $estatisticas_gerais['total_atrasos'] += $falta['atrasos'];
            $estatisticas_gerais['total_aulas'] += $falta['total_aulas'];
            $estatisticas_gerais['total_justificadas'] += $falta['faltas_justificadas'];
        }
        
        $estatisticas_gerais['disciplinas_com_falta'] = count($faltas_raw);
        $estatisticas_gerais['media_faltas'] = $estatisticas_gerais['total_aulas'] > 0 
            ? round(($estatisticas_gerais['total_faltas'] / $estatisticas_gerais['total_aulas']) * 100, 1) 
            : 0;
    }
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Faltas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-number.total { color: #006B3E; }
        .stat-number.percent { color: #ffc107; }
        
        .ano-card {
            background: white;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .ano-header {
            background: #006B3E;
            color: white;
            padding: 12px 20px;
        }
        
        .ano-header h4 {
            margin: 0;
            display: inline-block;
        }
        
        .table-faltas th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-faltas td {
            text-align: center;
            vertical-align: middle;
        }
        
        .btn-print, .btn-pdf {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
        }
        
        .btn-pdf {
            background: #dc3545;
        }
        
        .btn-pdf:hover { background: #c82333; }
        .btn-print:hover { background: #138496; }
        
        .status-regular { color: #28a745; font-weight: bold; }
        .status-atencao { color: #ffc107; font-weight: bold; }
        .status-critico { color: #dc3545; font-weight: bold; }
        
        .table-historico th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-historico td {
            vertical-align: middle;
        }
        
        .btn-toggle-table {
            background: #006B3E;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-toggle-table:hover {
            background: #004d2d;
        }
        
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 10px; }
            .ano-header { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-faltas th { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-historico th { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-calendar-times"></i> Histórico de Faltas</h2>
            <div class="no-print">
                <?php if ($aluno_id > 0 && !empty($faltas_por_ano)): ?>
                    <a href="gerar_pdf_historico_faltas.php?aluno_id=<?php echo $aluno_id; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre; ?>" 
                       class="btn btn-pdf" target="_blank">
                        <i class="fas fa-file-pdf"></i> Baixar PDF
                    </a>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="0">Todas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_id == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Aluno</label>
                    <select name="aluno_id" class="form-select" id="aluno_id" onchange="this.form.submit()">
                        <option value="0">Selecione um aluno...</option>
                        <?php foreach ($alunos_turma as $aluno): ?>
                        <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_id == $aluno['id'] ? 'selected' : ''; ?>>
                            <?php echo $aluno['matricula'] . ' - ' . $aluno['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <!-- Estatísticas Gerais -->
        <?php if ($aluno_id > 0 && !empty($faltas_por_ano)): ?>
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-calendar-times fa-2x text-danger mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['total_faltas']; ?></div>
                    <small>Total de Faltas</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['total_atrasos']; ?></div>
                    <small>Total de Atrasos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chalkboard fa-2x text-primary mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['total_aulas']; ?></div>
                    <small>Total de Aulas</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                    <div class="stat-number percent"><?php echo $estatisticas_gerais['media_faltas']; ?>%</div>
                    <small>Percentual de Faltas</small>
                </div>
            </div>
        </div>
        
        <!-- Resumo por Ano (Tabela Estruturada) -->
        <?php foreach ($faltas_por_ano as $ano => $dados_ano): 
            $percentual_ano = $dados_ano['total_aulas_ano'] > 0 
                ? round(($dados_ano['total_faltas_ano'] / $dados_ano['total_aulas_ano']) * 100, 1) 
                : 0;
            $status_ano = $percentual_ano > 25 ? 'status-critico' : ($percentual_ano > 15 ? 'status-atencao' : 'status-regular');
        ?>
        <div class="ano-card">
            <div class="ano-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4><i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $ano; ?></h4>
                        <small>Turma: <?php echo $dados_ano['turma']; ?></small>
                    </div>
                    <div>
                        <span class="badge bg-light text-dark me-2">
                            <i class="fas fa-chart-line"></i> Total Faltas: <?php echo $dados_ano['total_faltas_ano']; ?>
                        </span>
                        <span class="badge bg-light text-dark">
                            <i class="fas fa-percent"></i> Percentual: <span class="<?php echo $status_ano; ?>"><?php echo $percentual_ano; ?>%</span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-faltas">
                        <thead>
                            <tr>
                                <th rowspan="2" width="25%">Disciplina</th>
                                <th colspan="4">1º Bimestre</th>
                                <th colspan="4">2º Bimestre</th>
                                <th colspan="4">3º Bimestre</th>
                                <th rowspan="2" width="8%">Total Faltas</th>
                                <th rowspan="2" width="8%">Total Aulas</th>
                                <th rowspan="2" width="8%">%</th>
                            </tr>
                            <tr>
                                <th>Faltas</th><th>Atrasos</th><th>Aulas</th><th>%</th>
                                <th>Faltas</th><th>Atrasos</th><th>Aulas</th><th>%</th>
                                <th>Faltas</th><th>Atrasos</th><th>Aulas</th><th>%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_ano['disciplinas'] as $disciplina_nome => $dados_disc): 
                                $total_faltas_disc = $dados_disc['total_faltas'];
                                $total_aulas_disc = $dados_disc['total_aulas'];
                                $percentual_disc = $total_aulas_disc > 0 ? round(($total_faltas_disc / $total_aulas_disc) * 100, 1) : 0;
                                $status_disc = $percentual_disc > 25 ? 'status-critico' : ($percentual_disc > 15 ? 'status-atencao' : 'status-regular');
                            ?>
                            <tr>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($disciplina_nome); ?></strong>
                                    <?php if ($dados_disc['disciplina_codigo']): ?>
                                        <br><small class="text-muted"><?php echo $dados_disc['disciplina_codigo']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <?php for ($bim = 1; $bim <= 3; $bim++): 
                                    $falta = isset($dados_disc['bimestres'][$bim]['faltas']) ? $dados_disc['bimestres'][$bim]['faltas'] : 0;
                                    $atraso = isset($dados_disc['bimestres'][$bim]['atrasos']) ? $dados_disc['bimestres'][$bim]['atrasos'] : 0;
                                    $aulas = isset($dados_disc['bimestres'][$bim]['total_aulas']) ? $dados_disc['bimestres'][$bim]['total_aulas'] : 0;
                                    $perc = $aulas > 0 ? round(($falta / $aulas) * 100, 1) : 0;
                                    $perc_class = $perc > 25 ? 'status-critico' : ($perc > 15 ? 'status-atencao' : 'status-regular');
                                ?>
                                    <td><?php echo $falta; ?></td>
                                    <td><?php echo $atraso; ?></td>
                                    <td><?php echo $aulas; ?></td>
                                    <td class="<?php echo $perc_class; ?>"><?php echo $perc; ?>%</td>
                                <?php endfor; ?>
                                <td><span class="badge bg-danger"><?php echo $total_faltas_disc; ?></span></td>
                                <td><?php echo $total_aulas_disc; ?></td>
                                <td class="<?php echo $status_disc; ?>"><?php echo $percentual_disc; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td class="text-end fw-bold"><strong>Total do Ano</strong></td>
                                <td colspan="12"></td>
                                <td><span class="badge bg-danger"><?php echo $dados_ano['total_faltas_ano']; ?></span></td>
                                <td><strong><?php echo $dados_ano['total_aulas_ano']; ?></strong></td>
                                <td class="<?php echo $status_ano; ?>"><strong><?php echo $percentual_ano; ?>%</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Botão para ativar/desativar tabela de histórico detalhado -->
        <div class="row mt-4 mb-3 no-print">
            <div class="col-12 text-center">
                <button class="btn-toggle-table" id="btnToggleHistorico" onclick="toggleHistorico()">
                    <i class="fas fa-list-ul"></i> Mostrar Histórico Detalhado
                </button>
            </div>
        </div>
        
        <!-- Tabela de Histórico Detalhado (oculta por padrão) -->
        <div class="card" id="tabelaHistorico" style="display: none;">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-history"></i> Histórico Detalhado de Faltas e Atrasos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-historico" id="dataTableHistorico">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="10%">Data</th>
                                <th width="10%">Ano Letivo</th>
                                <th width="10%">Bimestre</th>
                                <th width="15%">Disciplina</th>
                                <th width="10%">Turma</th>
                                <th width="12%">Status</th>
                                <th width="8%">Atraso (min)</th>
                                <th width="15%">Justificativa</th>
                                <th width="10%">Data Lançamento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($historico_detalhado)): ?>
                                <?php foreach ($historico_detalhado as $index => $item): ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td class="text-center"><?php echo date('d/m/Y', strtotime($item['data_aula'])); ?></td>
                                    <td class="text-center"><?php echo $item['ano_letivo']; ?></td>
                                    <td class="text-center"><?php echo $item['bimestre']; ?>º Bimestre</td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['disciplina_nome']); ?></strong>
                                        <?php if ($item['disciplina_codigo']): ?>
                                            <br><small class="text-muted"><?php echo $item['disciplina_codigo']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo $item['turma_ano'] . 'ª ' . htmlspecialchars($item['turma_nome']); ?></td>
                                    <td class="text-center"><?php echo getStatusBadge($item['status']); ?></td>
                                    <td class="text-center">
                                        <?php echo $item['minutos_atraso'] > 0 ? $item['minutos_atraso'] . ' min' : '---'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['justificativa'] ?: '---'); ?></td>
                                    <td class="text-center"><?php echo date('d/m/Y H:i', strtotime($item['data_lancamento'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center">Nenhum registro encontrado</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Legenda -->
        <div class="alert alert-info mt-3 no-print">
            <i class="fas fa-info-circle"></i> 
            <strong>Legenda de Percentual de Faltas:</strong><br>
            <span class="status-regular">● Regular:</span> Até 15% - Frequência adequada<br>
            <span class="status-atencao">● Atenção:</span> Entre 15% e 25% - Frequência preocupante<br>
            <span class="status-critico">● Crítico:</span> Acima de 25% - Risco de reprovação por falta
        </div>
        
        <?php elseif ($aluno_id > 0 && empty($faltas_por_ano)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum registro de falta encontrado para este aluno.
            </div>
        <?php elseif ($turma_id > 0 && $aluno_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione um aluno para visualizar o histórico de faltas.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma e um aluno para visualizar o histórico de faltas.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        var dataTable = null;
        
        function toggleHistorico() {
            var tabela = document.getElementById('tabelaHistorico');
            var btn = document.getElementById('btnToggleHistorico');
            
            if (tabela.style.display === 'none') {
                tabela.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> Ocultar Histórico Detalhado';
                // Inicializar DataTable se não existir
                if (!dataTable) {
                    dataTable = $('#dataTableHistorico').DataTable({
                        language: {
                            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                        },
                        order: [[1, 'desc']],
                        pageLength: 25
                    });
                }
            } else {
                tabela.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-list-ul"></i> Mostrar Histórico Detalhado';
                // Destruir DataTable para evitar conflitos
                if (dataTable) {
                    dataTable.destroy();
                    dataTable = null;
                }
            }
        }
    </script>
</body>
</html>