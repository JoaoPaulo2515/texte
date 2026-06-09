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
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 0;
$acao = isset($_GET['acao']) ? $_GET['acao'] : 'listar';

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
    if ($aulas == 0) return ['texto' => 'Sem aulas', 'classe' => 'text-secondary'];
    $percentual = ($faltas / $aulas) * 100;
    if ($percentual > 25) return ['texto' => 'Crítico', 'classe' => 'text-danger', 'percentual' => round($percentual, 1)];
    if ($percentual > 15) return ['texto' => 'Atenção', 'classe' => 'text-warning', 'percentual' => round($percentual, 1)];
    return ['texto' => 'Regular', 'classe' => 'text-success', 'percentual' => round($percentual, 1)];
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$aluno_info = null;
$faltas_por_ano = [];
$estatisticas_gerais = [
    'total_faltas' => 0,
    'total_aulas' => 0,
    'media_faltas' => 0,
    'maior_falta' => 0,
    'disciplinas_com_falta' => 0
];

if ($aluno_id > 0) {
    // Buscar informações do aluno
    $sql_aluno = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento, e.bi, e.nome_pai, e.nome_mae
                  FROM estudantes e
                  WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $aluno_info = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_info) {
        // Buscar faltas por ano letivo
        $sql_faltas = "SELECT 
                        a.ano as ano_letivo,
                        a.id as ano_letivo_id,
                        t.id as turma_id,
                        t.nome as turma_nome,
                        t.ano as turma_ano,
                        d.id as disciplina_id,
                        d.nome as disciplina_nome,
                        d.codigo as disciplina_codigo,
                        f.bimestre,
                        f.quantidade as faltas,
                        f.total_aulas,
                        f.data_registro,
                        f.observacao
                      FROM faltas f
                      INNER JOIN turmas t ON t.id = f.turma_id
                      INNER JOIN disciplinas d ON d.id = f.disciplina_id
                      INNER JOIN ano_letivo a ON a.id = f.ano_letivo_id
                      WHERE f.estudante_id = :aluno_id
                      AND f.escola_id = :escola_id";
        
        $params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
        
        if ($ano_letivo_id > 0) {
            $sql_faltas .= " AND f.ano_letivo_id = :ano_letivo_id";
            $params[':ano_letivo_id'] = $ano_letivo_id;
        }
        
        if ($disciplina_id > 0) {
            $sql_faltas .= " AND f.disciplina_id = :disciplina_id";
            $params[':disciplina_id'] = $disciplina_id;
        }
        
        if ($trimestre > 0) {
            $sql_faltas .= " AND f.bimestre = :trimestre";
            $params[':trimestre'] = $trimestre;
        }
        
        $sql_faltas .= " ORDER BY a.ano DESC, t.ano, t.nome, d.nome, f.bimestre";
        
        $stmt_faltas = $conn->prepare($sql_faltas);
        $stmt_faltas->execute($params);
        $faltas_raw = $stmt_faltas->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar faltas por ano e disciplina
        foreach ($faltas_raw as $falta) {
            $ano = $falta['ano_letivo'];
            $disciplina = $falta['disciplina_nome'];
            
            if (!isset($faltas_por_ano[$ano])) {
                $faltas_por_ano[$ano] = [
                    'ano_letivo' => $ano,
                    'ano_letivo_id' => $falta['ano_letivo_id'],
                    'turma' => $falta['turma_ano'] . 'ª ' . $falta['turma_nome'],
                    'disciplinas' => [],
                    'total_faltas_ano' => 0,
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
                    'total_aulas' => 0
                ];
            }
            
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['bimestres'][$falta['bimestre']] = [
                'faltas' => $falta['faltas'],
                'total_aulas' => $falta['total_aulas'],
                'data_registro' => $falta['data_registro'],
                'observacao' => $falta['observacao']
            ];
            
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['total_faltas'] += $falta['faltas'];
            $faltas_por_ano[$ano]['disciplinas'][$disciplina]['total_aulas'] += $falta['total_aulas'];
            $faltas_por_ano[$ano]['total_faltas_ano'] += $falta['faltas'];
            $faltas_por_ano[$ano]['total_aulas_ano'] += $falta['total_aulas'];
            
            $estatisticas_gerais['total_faltas'] += $falta['faltas'];
            $estatisticas_gerais['total_aulas'] += $falta['total_aulas'];
            if ($falta['faltas'] > $estatisticas_gerais['maior_falta']) {
                $estatisticas_gerais['maior_falta'] = $falta['faltas'];
            }
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

// ============================================
// GERAR PDF
// ============================================
if ($acao == 'pdf' && $aluno_id > 0 && !empty($faltas_por_ano)) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    
    use Dompdf\Dompdf;
    use Dompdf\Options;
    
    $html_pdf = '
    <!DOCTYPE html>
    <html lang="pt-AO">
    <head>
        <meta charset="UTF-8">
        <title>Histórico de Faltas - ' . htmlspecialchars($aluno_info['nome']) . '</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; padding: 20px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #006B3E; padding-bottom: 10px; }
            .header h1 { font-size: 18px; color: #006B3E; }
            .header p { font-size: 10px; color: #666; }
            .info-aluno { background: #f5f5f5; padding: 10px; margin-bottom: 15px; border-left: 4px solid #006B3E; }
            .estatisticas { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
            .card-estatistica { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 8px 12px; text-align: center; min-width: 100px; }
            .card-estatistica .numero { font-size: 18px; font-weight: bold; color: #006B3E; }
            .card-estatistica .label { font-size: 9px; color: #666; }
            .ano-card { margin-bottom: 25px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
            .ano-header { background: #006B3E; color: white; padding: 10px; }
            .ano-header h3 { margin: 0; font-size: 14px; }
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th, td { border: 1px solid #ddd; padding: 6px; text-align: center; vertical-align: middle; }
            th { background: #e9ecef; font-weight: bold; }
            .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
            .text-success { color: #28a745; }
            .text-warning { color: #ffc107; }
            .text-danger { color: #dc3545; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . htmlspecialchars($escola_info['nome']) . '</h1>
            <h2>HISTÓRICO DE FALTAS</h2>
            <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        
        <div class="info-aluno">
            <strong>Aluno:</strong> ' . htmlspecialchars($aluno_info['nome']) . ' | 
            <strong>Matrícula:</strong> ' . htmlspecialchars($aluno_info['matricula']) . ' | 
            <strong>BI:</strong> ' . htmlspecialchars($aluno_info['bi'] ?: '---') . '
        </div>
        
        <div class="estatisticas">
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas_gerais['total_faltas'] . '</div>
                <div class="label">Total Faltas</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas_gerais['total_aulas'] . '</div>
                <div class="label">Total Aulas</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas_gerais['media_faltas'] . '%</div>
                <div class="label">Percentual</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas_gerais['disciplinas_com_falta'] . '</div>
                <div class="label">Registros</div>
            </div>
        </div>';
    
    foreach ($faltas_por_ano as $ano => $dados_ano) {
        $percentual_ano = $dados_ano['total_aulas_ano'] > 0 
            ? round(($dados_ano['total_faltas_ano'] / $dados_ano['total_aulas_ano']) * 100, 1) 
            : 0;
        
        $status_ano = $percentual_ano > 25 ? 'text-danger' : ($percentual_ano > 15 ? 'text-warning' : 'text-success');
        
        $html_pdf .= '
        <div class="ano-card">
            <div class="ano-header">
                <h3>📅 Ano Letivo: ' . $ano . ' - Turma: ' . $dados_ano['turma'] . ' | Total Faltas: ' . $dados_ano['total_faltas_ano'] . ' | Percentual: <span class="' . $status_ano . '">' . $percentual_ano . '%</span></h3>
            </div>
            <div style="padding: 10px;">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">Disciplina</th>
                            <th colspan="2">1º Trimestre</th>
                            <th colspan="2">2º Trimestre</th>
                            <th colspan="2">3º Trimestre</th>
                            <th rowspan="2">Total Faltas</th>
                            <th rowspan="2">Total Aulas</th>
                            <th rowspan="2">%</th>
                        </tr>
                        <tr>
                            <th>Faltas</th><th>%</th>
                            <th>Faltas</th><th>%</th>
                            <th>Faltas</th><th>%</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($dados_ano['disciplinas'] as $disciplina_nome => $dados_disc) {
            $total_faltas_disc = $dados_disc['total_faltas'];
            $total_aulas_disc = $dados_disc['total_aulas'];
            $percentual_disc = $total_aulas_disc > 0 ? round(($total_faltas_disc / $total_aulas_disc) * 100, 1) : 0;
            $status_disc = $percentual_disc > 25 ? 'text-danger' : ($percentual_disc > 15 ? 'text-warning' : 'text-success');
            
            $html_pdf .= '<tr>';
            $html_pdf .= '<td class="text-start"><strong>' . htmlspecialchars($disciplina_nome) . '</strong></td>';
            
            for ($bim = 1; $bim <= 3; $bim++) {
                $falta = $dados_disc['bimestres'][$bim]['faltas'] ?? 0;
                $aulas = $dados_disc['bimestres'][$bim]['total_aulas'] ?? 0;
                $perc = $aulas > 0 ? round(($falta / $aulas) * 100, 1) : 0;
                $classe = $perc > 25 ? 'text-danger' : ($perc > 15 ? 'text-warning' : 'text-success');
                
                $html_pdf .= '<td>' . $falta . '</td>';
                $html_pdf .= '<td class="' . $classe . '">' . $perc . '%</td>';
            }
            
            $html_pdf .= '<td>' . $total_faltas_disc . '</td>';
            $html_pdf .= '<td>' . $total_aulas_disc . '</td>';
            $html_pdf .= '<td class="' . $status_disc . '">' . $percentual_disc . '%</td>';
            $html_pdf .= '</tr>';
        }
        
        $html_pdf .= '
                    </tbody>
                    <tfoot>
                        <tr style="background: #e9ecef;">
                            <td class="text-end fw-bold"><strong>Total do Ano</strong></td>
                            <td colspan="6"></td>
                            <td><strong>' . $dados_ano['total_faltas_ano'] . '</strong></td>
                            <td><strong>' . $dados_ano['total_aulas_ano'] . '</strong></td>
                            <td><strong class="' . $status_ano . '">' . $percentual_ano . '%</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>';
    }
    
    $html_pdf .= '
        <div class="footer">
            <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
            <p>Legenda: <span class="text-success">Regular (≤15%)</span> | <span class="text-warning">Atenção (15%-25%)</span> | <span class="text-danger">Crítico (>25%)</span></p>
        </div>
    </body>
    </html>';
    
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html_pdf, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    $filename = 'historico_faltas_' . preg_replace('/[^a-zA-Z0-9]/', '_', $aluno_info['nome']) . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ["Attachment" => true]);
    exit;
}
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
            cursor: pointer;
        }
        
        .ano-header h4 {
            margin: 0;
            display: inline-block;
        }
        
        .table-faltas th {
            background: #e9ecef;
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
        
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 10px; }
            .ano-header { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
                    <a href="?acao=pdf&aluno_id=<?php echo $aluno_id; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>&trimestre=<?php echo $trimestre; ?>" 
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
                    <label class="form-label fw-bold">Trimestre</label>
                    <select name="trimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                        <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                        <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
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
                    <i class="fas fa-chalkboard fa-2x text-primary mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['total_aulas']; ?></div>
                    <small>Total de Aulas</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                    <div class="stat-number percent"><?php echo $estatisticas_gerais['media_faltas']; ?>%</div>
                    <small>Percentual Geral</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-book fa-2x text-info mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['disciplinas_com_falta']; ?></div>
                    <small>Registros de Falta</small>
                </div>
            </div>
        </div>
        
        <!-- Histórico por Ano -->
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
                                <th colspan="3">1º Trimestre</th>
                                <th colspan="3">2º Trimestre</th>
                                <th colspan="3">3º Trimestre</th>
                                <th rowspan="2" width="10%">Total Faltas</th>
                                <th rowspan="2" width="10%">Total Aulas</th>
                                <th rowspan="2" width="10%">%</th>
                            </tr>
                            <tr>
                                <th>Faltas</th><th>Aulas</th><th>%</th>
                                <th>Faltas</th><th>Aulas</th><th>%</th>
                                <th>Faltas</th><th>Aulas</th><th>%</th>
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
                                    $falta = $dados_disc['bimestres'][$bim]['faltas'] ?? 0;
                                    $aulas = $dados_disc['bimestres'][$bim]['total_aulas'] ?? 0;
                                    $perc = $aulas > 0 ? round(($falta / $aulas) * 100, 1) : 0;
                                    $perc_class = $perc > 25 ? 'status-critico' : ($perc > 15 ? 'status-atencao' : 'status-regular');
                                ?>
                                <td><?php echo $falta; ?></td>
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
                                <td colspan="9"></td>
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
    <script>
        // Animação para expandir/colapsar os cards (opcional)
        document.querySelectorAll('.ano-header').forEach(header => {
            header.addEventListener('click', function() {
                const content = this.nextElementSibling;
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                } else {
                    content.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>