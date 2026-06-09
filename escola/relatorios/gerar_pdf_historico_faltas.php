<?php
// escola/relatorios/gerar_pdf_historico_faltas.php - Gerar PDF do Histórico de Faltas

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
// RECEBER PARÂMETROS
// ============================================
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

if ($aluno_id == 0) {
    die('Aluno não selecionado.');
}

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
            return 'Presente';
        case 'falta':
            return 'Falta';
        case 'falta_justificada':
            return 'Falta Justificada';
        case 'atraso':
            return 'Atraso';
        default:
            return ucfirst($status);
    }
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento, e.bi, e.pai_nome, e.mae_nome
              FROM estudantes e
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno_info = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno_info) {
    die('Aluno não encontrado.');
}

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
// QUERY 2: RESUMO POR ANO E DISCIPLINA
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
$faltas_por_ano = [];
$estatisticas_gerais = [
    'total_faltas' => 0,
    'total_atrasos' => 0,
    'total_aulas' => 0,
    'total_justificadas' => 0,
    'disciplinas_com_falta' => 0
];

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

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// GERAR PDF COM MESMO LAYOUT DA PÁGINA
// ============================================
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
        body { 
            font-family: "DejaVu Sans", Arial, sans-serif; 
            font-size: 11px; 
            padding: 20px;
            background: white;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
        }
        .header h1 { font-size: 20px; color: #006B3E; margin-bottom: 5px; }
        .header h2 { font-size: 14px; font-weight: normal; margin-bottom: 5px; }
        .header p { font-size: 10px; color: #666; }
        
        .info-aluno {
            background: #f5f5f5;
            padding: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #006B3E;
            font-size: 11px;
        }
        
      .estatisticas {
    display: flex;        /* Isto coloca os cards em linha */
    gap: 15px;           /* Espaço entre os cards */
    margin-bottom: 25px; /* Margem inferior */
    flex-wrap: wrap;     /* Se não couber, quebra para próxima linha */
}
.card-estatistica {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 10px 15px;
    min-width: 3%;    /* Largura mínima de cada card */
    text-align: center;
}
        .card-estatistica .numero {
            font-size: 22px;
            font-weight: bold;
            color: #006B3E;
        }
        .card-estatistica .label {
            font-size: 10px;
            color: #666;
        }
        
        .ano-card {
            margin-bottom: 25px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .ano-header {
            background: #006B3E;
            color: white;
            padding: 10px 15px;
        }
        .ano-header h3 {
            margin: 0;
            font-size: 13px;
        }
        .ano-header .badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px 4px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background: #006B3E;
            color: white;
            font-weight: bold;
        }
        .text-start { text-align: left; }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        
        .status-regular { color: #28a745; font-weight: bold; }
        .status-atencao { color: #ffc107; font-weight: bold; }
        .status-critico { color: #dc3545; font-weight: bold; }
        
        .table-historico {
            margin-top: 20px;
            font-size: 9px;
        }
        .table-historico th {
            background: #006B3E;
            color: white;
        }
        
        .footer {
            margin-top: 25px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 12px;
        }
        
        .legenda {
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 9px;
        }
        .legenda span {
            display: inline-block;
            margin-right: 15px;
        }
        .text-success { color: #28a745; }
        .text-warning { color: #ffc107; }
        .text-danger { color: #dc3545; }
        
        @page {
            margin: 1.5cm;
        }
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
        <strong>BI:</strong> ' . htmlspecialchars($aluno_info['bi'] ?: '---') . ' |
        <strong>Pai:</strong> ' . htmlspecialchars($aluno_info['pai_nome'] ?: '---') . ' |
        <strong>Mãe:</strong> ' . htmlspecialchars($aluno_info['mae_nome'] ?: '---') . '
    </div>
    
    <div class="estatisticas">
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas_gerais['total_faltas'] . '</div>
            <div class="label">Total de Faltas</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas_gerais['total_atrasos'] . '</div>
            <div class="label">Total de Atrasos</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas_gerais['total_aulas'] . '</div>
            <div class="label">Total de Aulas</div>
        </div>
        <div class="card-estatistica">
            <div class="numero">' . $estatisticas_gerais['media_faltas'] . '%</div>
            <div class="label">Percentual de Faltas</div>
        </div>
    </div>';

// Tabela Resumo por Ano
foreach ($faltas_por_ano as $ano => $dados_ano):
    $percentual_ano = $dados_ano['total_aulas_ano'] > 0 
        ? round(($dados_ano['total_faltas_ano'] / $dados_ano['total_aulas_ano']) * 100, 1) 
        : 0;
    $status_ano_class = $percentual_ano > 25 ? 'status-critico' : ($percentual_ano > 15 ? 'status-atencao' : 'status-regular');
    
    $html_pdf .= '
    <div class="ano-card">
        <div class="ano-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3>📅 Ano Letivo: ' . $ano . '</h3>
                    <small>Turma: ' . $dados_ano['turma'] . '</small>
                </div>
                <div>
                    <span class="badge">Total Faltas: ' . $dados_ano['total_faltas_ano'] . '</span>
                    <span class="badge">Percentual: <span class="' . $status_ano_class . '">' . $percentual_ano . '%</span></span>
                </div>
            </div>
        </div>
        <div style="padding: 10px;">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" width="22%">Disciplina</th>
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
                <tbody>';
    
    foreach ($dados_ano['disciplinas'] as $disciplina_nome => $dados_disc):
        $total_faltas_disc = $dados_disc['total_faltas'];
        $total_aulas_disc = $dados_disc['total_aulas'];
        $percentual_disc = $total_aulas_disc > 0 ? round(($total_faltas_disc / $total_aulas_disc) * 100, 1) : 0;
        $status_disc_class = $percentual_disc > 25 ? 'status-critico' : ($percentual_disc > 15 ? 'status-atencao' : 'status-regular');
        
        $html_pdf .= '<tr>';
        $html_pdf .= '<td class="text-start"><strong>' . htmlspecialchars($disciplina_nome) . '</strong>';
        if ($dados_disc['disciplina_codigo']):
            $html_pdf .= '<br><span style="color:#666; font-size:8px;">' . htmlspecialchars($dados_disc['disciplina_codigo']) . '</span>';
        endif;
        $html_pdf .= '</td>';
        
        for ($bim = 1; $bim <= 3; $bim++):
            $falta = isset($dados_disc['bimestres'][$bim]['faltas']) ? $dados_disc['bimestres'][$bim]['faltas'] : 0;
            $atraso = isset($dados_disc['bimestres'][$bim]['atrasos']) ? $dados_disc['bimestres'][$bim]['atrasos'] : 0;
            $aulas = isset($dados_disc['bimestres'][$bim]['total_aulas']) ? $dados_disc['bimestres'][$bim]['total_aulas'] : 0;
            $perc = $aulas > 0 ? round(($falta / $aulas) * 100, 1) : 0;
            $perc_class = $perc > 25 ? 'status-critico' : ($perc > 15 ? 'status-atencao' : 'status-regular');
            
            $html_pdf .= '<td>' . $falta . '</td>';
            $html_pdf .= '<td>' . $atraso . '</td>';
            $html_pdf .= '<td>' . $aulas . '</td>';
            $html_pdf .= '<td class="' . $perc_class . '">' . $perc . '%</td>';
        endfor;
        
        $html_pdf .= '<td><strong>' . $total_faltas_disc . '</strong></td>';
        $html_pdf .= '<td>' . $total_aulas_disc . '</td>';
        $html_pdf .= '<td class="' . $status_disc_class . '">' . $percentual_disc . '%</td>';
        $html_pdf .= '</tr>';
    endforeach;
    
    $html_pdf .= '
                </tbody>
                <tfoot>
                    <tr style="background: #e9ecef;">
                        <td class="text-end fw-bold"><strong>Total do Ano</strong></td>
                        <td colspan="12"></td>
                        <td><strong>' . $dados_ano['total_faltas_ano'] . '</strong></td>
                        <td><strong>' . $dados_ano['total_aulas_ano'] . '</strong></td>
                        <td><strong class="' . $status_ano_class . '">' . $percentual_ano . '%</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>';
endforeach;

// Tabela de Histórico Detalhado
$html_pdf .= '
    <h3 style="margin: 20px 0 10px 0; color: #006B3E;"><i class="fas fa-history"></i> Histórico Detalhado de Faltas e Atrasos</h3>
    <div style="overflow-x: auto;">
        <table class="table-historico">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="10%">Data</th>
                    <th width="10%">Ano Letivo</th>
                    <th width="8%">Bimestre</th>
                    <th width="18%">Disciplina</th>
                    <th width="10%">Turma</th>
                    <th width="12%">Status</th>
                    <th width="8%">Atraso (min)</th>
                    <th width="12%">Justificativa</th>
                    <th width="12%">Data Lançamento</th>
                </tr>
            </thead>
            <tbody>';

if (!empty($historico_detalhado)):
    foreach ($historico_detalhado as $index => $item):
        $status_color = '';
        $status_text = getStatusBadge($item['status']);
        switch ($item['status']):
            case 'falta':
                $status_color = 'color: #dc3545; font-weight: bold;';
                break;
            case 'falta_justificada':
                $status_color = 'color: #ffc107; font-weight: bold;';
                break;
            case 'atraso':
                $status_color = 'color: #17a2b8; font-weight: bold;';
                break;
        endswitch;
        
        $html_pdf .= '<tr>';
        $html_pdf .= '<td class="text-center">' . ($index + 1) . '</td>';
        $html_pdf .= '<td class="text-center">' . date('d/m/Y', strtotime($item['data_aula'])) . '</td>';
        $html_pdf .= '<td class="text-center">' . $item['ano_letivo'] . '</td>';
        $html_pdf .= '<td class="text-center">' . $item['bimestre'] . 'º</td>';
        $html_pdf .= '<td>';
        $html_pdf .= '<strong>' . htmlspecialchars($item['disciplina_nome']) . '</strong>';
        if ($item['disciplina_codigo']):
            $html_pdf .= '<br><span style="color:#666; font-size:8px;">' . htmlspecialchars($item['disciplina_codigo']) . '</span>';
        endif;
        $html_pdf .= '</td>';
        $html_pdf .= '<td class="text-center">' . $item['turma_ano'] . 'ª ' . htmlspecialchars($item['turma_nome']) . '</td>';
        $html_pdf .= '<td class="text-center" style="' . $status_color . '">' . $status_text . '</td>';
        $html_pdf .= '<td class="text-center">' . ($item['minutos_atraso'] > 0 ? $item['minutos_atraso'] . ' min' : '---') . '</td>';
        $html_pdf .= '<td>' . htmlspecialchars(substr($item['justificativa'] ?? '---', 0, 50)) . '</td>';
        $html_pdf .= '<td class="text-center">' . date('d/m/Y H:i', strtotime($item['data_lancamento'])) . '</td>';
        $html_pdf .= '</tr>';
    endforeach;
else:
    $html_pdf .= '<tr><td colspan="10" class="text-center">Nenhum registro encontrado</td></tr>';
endif;

$html_pdf .= '
            </tbody>
        </table>
    </div>
    
    <div class="legenda">
        <strong>Legenda de Percentual de Faltas:</strong><br>
        <span class="status-regular">● Regular:</span> Até 15% - Frequência adequada
        <span class="status-atencao">● Atenção:</span> Entre 15% e 25% - Frequência preocupante
        <span class="status-critico">● Crítico:</span> Acima de 25% - Risco de reprovação por falta
    </div>
    
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>' . htmlspecialchars($escola_info['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola_info['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola_info['email'] ?? '') . '</p>
    </div>
</body>
</html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html_pdf, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'historico_faltas_' . preg_replace('/[^a-zA-Z0-9]/', '_', $aluno_info['nome']) . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>