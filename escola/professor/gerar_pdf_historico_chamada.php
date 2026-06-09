<?php
// escola/professor/gerar_pdf_historico_chamada.php - Gerar PDF do Histórico de Chamadas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-t');
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$aluno_filtro = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;

// ============================================
// VALIDAÇÕES
// ============================================
if ($turma_id <= 0 || $disciplina_id <= 0) {
    die('Parâmetros inválidos');
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, logo, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT t.*, COUNT(DISTINCT m.estudante_id) as total_alunos
              FROM turmas t
              LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
              WHERE t.id = :turma_id AND t.escola_id = :escola_id
              GROUP BY t.id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada');
}

// ============================================
// BUSCAR DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    die('Disciplina não encontrada');
}

// ============================================
// BUSCAR HISTÓRICO DE CHAMADAS
// ============================================
$sql_historico = "
    SELECT 
        c.*,
        e.nome as aluno_nome,
        e.matricula as aluno_matricula,
        d.nome as disciplina_nome,
        t.nome as turma_nome,
        t.ano as turma_ano,
        p.nome as professor_nome
    FROM chamada c
    INNER JOIN estudantes e ON e.id = c.estudante_id
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    INNER JOIN funcionarios p ON p.id = c.professor_id
    WHERE c.turma_id = :turma_id 
    AND c.disciplina_id = :disciplina_id
    AND c.data_aula BETWEEN :data_inicio AND :data_fim
";

if ($aluno_filtro > 0) {
    $sql_historico .= " AND c.estudante_id = :aluno_id";
}
if ($status_filtro != 'todos') {
    $sql_historico .= " AND c.status = :status";
}

$sql_historico .= " ORDER BY c.data_aula DESC, e.nome";

$stmt_historico = $conn->prepare($sql_historico);
$params = [
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
];
if ($aluno_filtro > 0) {
    $params[':aluno_id'] = $aluno_filtro;
}
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
$stmt_historico->execute($params);
$chamadas = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_registros = count($chamadas);
$total_presentes = 0;
$total_faltas = 0;
$total_atrasos = 0;
$total_justificados = 0;
$dias_letivos = [];

foreach ($chamadas as $c) {
    switch ($c['status']) {
        case 'presente': $total_presentes++; break;
        case 'falta': $total_faltas++; break;
        case 'atraso': $total_atrasos++; break;
        case 'justificado': $total_justificados++; break;
    }
    $dias_letivos[$c['data_aula']] = true;
}
$total_dias = count($dias_letivos);
$percentual_presenca = $total_registros > 0 ? round(($total_presentes / $total_registros) * 100, 1) : 0;

// ============================================
// RESUMO POR ALUNO
// ============================================
$resumo_alunos = [];
foreach ($chamadas as $c) {
    $aluno_id = $c['estudante_id'];
    if (!isset($resumo_alunos[$aluno_id])) {
        $resumo_alunos[$aluno_id] = [
            'nome' => $c['aluno_nome'],
            'matricula' => $c['aluno_matricula'],
            'presentes' => 0,
            'faltas' => 0,
            'atrasos' => 0,
            'justificados' => 0,
            'total' => 0
        ];
    }
    $resumo_alunos[$aluno_id]['total']++;
    switch ($c['status']) {
        case 'presente': $resumo_alunos[$aluno_id]['presentes']++; break;
        case 'falta': $resumo_alunos[$aluno_id]['faltas']++; break;
        case 'atraso': $resumo_alunos[$aluno_id]['atrasos']++; break;
        case 'justificado': $resumo_alunos[$aluno_id]['justificados']++; break;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusTexto($status) {
    switch ($status) {
        case 'presente': return 'Presente';
        case 'falta': return 'Falta';
        case 'atraso': return 'Atraso';
        case 'justificado': return 'Justificado';
        default: return '-';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getSituacaoFrequencia($percentual) {
    if ($percentual >= 75) return 'Regular';
    if ($percentual >= 50) return 'Atenção';
    return 'Crítico';
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Chamadas - ' . htmlspecialchars($disciplina['nome']) . '</title>
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        .header h1 {
            color: #006B3E;
            margin: 0;
            font-size: 18px;
        }
        .header h2 {
            margin: 5px 0;
            font-size: 14px;
        }
        .header p {
            margin: 3px 0;
            font-size: 9px;
            color: #666;
        }
        .info-section {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .info-section table {
            width: 100%;
            font-size: 9px;
        }
        .info-section td {
            padding: 3px;
        }
        .stats-section {
            margin-bottom: 15px;
        }
        .stats-box {
            display: inline-block;
            width: 23%;
            margin: 0 1%;
            padding: 8px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 5px;
        }
        .stats-number {
            font-size: 18px;
            font-weight: bold;
            color: #006B3E;
        }
        .stats-label {
            font-size: 8px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        th {
            background: #006B3E;
            color: white;
            padding: 8px;
            text-align: center;
            font-size: 9px;
        }
        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            font-size: 9px;
        }
        .text-left {
            text-align: left;
        }
        .text-success {
            color: #28a745;
        }
        .text-danger {
            color: #dc3545;
        }
        .text-warning {
            color: #ffc107;
        }
        .text-info {
            color: #17a2b8;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 8px;
            color: #666;
        }
        .badge-presente {
            background: #d4edda;
            color: #155724;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .badge-falta {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .badge-atraso {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .badge-justificado {
            background: #d1ecf1;
            color: #0c5460;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .page-break {
            page-break-before: always;
        }
        .progress-bar-bg {
            background: #e9ecef;
            border-radius: 5px;
            height: 10px;
            width: 100%;
        }
        .progress-bar-fill {
            background: #28a745;
            border-radius: 5px;
            height: 10px;
        }
    </style>
</head>
<body>

    <!-- Cabeçalho -->
    <div class="header">
        <h1>' . htmlspecialchars($escola['nome'] ?? 'SIGE Angola') . '</h1>
        <h2>RELATÓRIO DE CHAMADAS - HISTÓRICO COMPLETO</h2>
        <p>' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</p>
    </div>

    <!-- Informações da Turma -->
    <div class="info-section">
        <table>
            <tr>
                <td width="20%"><strong>Turma:</strong></td>
                <td width="30%">' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</td>
                <td width="20%"><strong>Turno:</strong></td>
                <td width="30%">' . ucfirst($turma['turno']) . '</td>
            </tr>
            <tr>
                <td><strong>Disciplina:</strong></td>
                <td>' . htmlspecialchars($disciplina['nome']) . '</td>
                <td><strong>Código:</strong></td>
                <td>' . htmlspecialchars($disciplina['codigo'] ?? '-') . '</td>
            </tr>
            <tr>
                <td><strong>Professor:</strong></td>
                <td>' . htmlspecialchars($professor['professor_nome']) . '</td>
                <td><strong>Período:</strong></td>
                <td>' . formatarData($data_inicio) . ' a ' . formatarData($data_fim) . '</td>
            </tr>
            <tr>
                <td><strong>Data Emissão:</strong></td>
                <td>' . date('d/m/Y H:i:s') . '</td>
                <td><strong>Status Filtro:</strong></td>
                <td>' . ($status_filtro == 'todos' ? 'Todos' : ucfirst($status_filtro)) . '</td>
            </tr>
        </table>
    </div>

    <!-- Estatísticas Gerais -->
    <div class="stats-section">
        <div class="stats-box">
            <div class="stats-number">' . $total_dias . '</div>
            <div class="stats-label">Dias Letivos</div>
        </div>
        <div class="stats-box">
            <div class="stats-number">' . $total_registros . '</div>
            <div class="stats-label">Total Registros</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-success">' . $total_presentes . '</div>
            <div class="stats-label">Presentes</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-danger">' . $total_faltas . '</div>
            <div class="stats-label">Faltas</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-warning">' . $total_atrasos . '</div>
            <div class="stats-label">Atrasos</div>
        </div>
        <div class="stats-box">
            <div class="stats-number text-info">' . $total_justificados . '</div>
            <div class="stats-label">Justificados</div>
        </div>
        <div class="stats-box">
            <div class="stats-number">' . $percentual_presenca . '%</div>
            <div class="stats-label">Taxa de Presença</div>
        </div>
    </div>

';

// Tabela de Registros
if (!empty($chamadas)) {
    $html .= '
    <h3 style="margin-top: 15px; margin-bottom: 10px;">📋 REGISTROS DE CHAMADA</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="10%">Data</th>
                <th width="25%">Aluno</th>
                <th width="10%">Matrícula</th>
                <th width="12%">Status</th>
                <th width="23%">Observação</th>
                <th width="15%">Professor</th>
            </tr>
        </thead>
        <tbody>
';
    foreach ($chamadas as $index => $c) {
        $status_class = '';
        switch ($c['status']) {
            case 'presente': $status_class = 'badge-presente'; break;
            case 'falta': $status_class = 'badge-falta'; break;
            case 'atraso': $status_class = 'badge-atraso'; break;
            case 'justificado': $status_class = 'badge-justificado'; break;
        }
        $html .= '
            <tr>
                <td>' . ($index + 1) . '</td>
                <td>' . formatarData($c['data_aula']) . '</td>
                <td class="text-left">' . htmlspecialchars($c['aluno_nome']) . '</td>
                <td>' . htmlspecialchars($c['aluno_matricula']) . '</td>
                <td><span class="' . $status_class . '">' . getStatusTexto($c['status']) . '</span></td>
                <td class="text-left">' . htmlspecialchars(substr($c['observacao'] ?? '-', 0, 50)) . '</td>
                <td>' . htmlspecialchars($c['professor_nome']) . '</td>
            </tr>
        ';
    }
    $html .= '
        </tbody>
    </table>
';
}

// Resumo por Aluno
if ($aluno_filtro == 0 && !empty($resumo_alunos)) {
    $html .= '
    <div class="page-break"></div>
    <h3 style="margin-top: 15px; margin-bottom: 10px;">📊 RESUMO POR ALUNO</h3>
    <table>
        <thead>
            <tr>
                <th width="20%">Aluno</th>
                <th width="10%">Matrícula</th>
                <th width="8%">Presentes</th>
                <th width="8%">Faltas</th>
                <th width="8%">Atrasos</th>
                <th width="10%">Justificados</th>
                <th width="8%">Total</th>
                <th width="15%">Frequência</th>
                <th width="13%">Situação</th>
            </tr>
        </thead>
        <tbody>
';
    foreach ($resumo_alunos as $aluno) {
        $frequencia = $aluno['total'] > 0 ? round(($aluno['presentes'] / $aluno['total']) * 100, 1) : 0;
        $situacao = getSituacaoFrequencia($frequencia);
        $situacao_class = '';
        if ($frequencia >= 75) $situacao_class = 'text-success';
        elseif ($frequencia >= 50) $situacao_class = 'text-warning';
        else $situacao_class = 'text-danger';
        
        $html .= '
            <tr>
                <td class="text-left">' . htmlspecialchars($aluno['nome']) . '</td>
                <td>' . htmlspecialchars($aluno['matricula']) . '</td>
                <td class="text-success">' . $aluno['presentes'] . '</td>
                <td class="text-danger">' . $aluno['faltas'] . '</td>
                <td class="text-warning">' . $aluno['atrasos'] . '</td>
                <td class="text-info">' . $aluno['justificados'] . '</td>
                <td>' . $aluno['total'] . '</td>
                <td>
                    <div>' . $frequencia . '%</div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: ' . $frequencia . '%; background: ' . ($frequencia >= 75 ? '#28a745' : ($frequencia >= 50 ? '#ffc107' : '#dc3545')) . ';"></div>
                    </div>
                </td>
                <td class="' . $situacao_class . '">' . $situacao . '</td>
            </tr>
        ';
    }
    $html .= '
        </tbody>
    </table>
';
}

// Rodapé
$html .= '
    <div class="footer">
        <p>Documento emitido eletronicamente pelo SIGE Angola - Sistema Integrado de Gestão Escolar</p>
        <p>Este relatório é válido como comprovante de frequência escolar.</p>
    </div>
</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

// Configurar chroot para Windows
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $options->set('chroot', 'C:/xampp/htdocs');
}

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Nome do arquivo
$nome_arquivo = 'historico_chamadas_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . date('Ymd') . '.pdf';

// Enviar para download
$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>