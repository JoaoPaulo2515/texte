<?php
// escola/pedagogico/gerar_pdf_historico_faltas.php - Gerar PDF do Histórico de Faltas do Aluno

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];
$funcionario_nome = $funcionario['nome'] ?? 'Secretaria Escolar';

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// PARÂMETROS
// ============================================
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

if ($aluno_id <= 0) {
    die('ID do aluno não informado');
}

// ============================================
// BUSCAR ANO LETIVO
// ============================================
if ($ano_letivo_id == 0) {
    $sql_ano_letivo = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano_letivo = $conn->query($sql_ano_letivo);
    $ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_id = $ano_letivo['id'] ?? 1;
    $ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');
} else {
    $sql_ano_letivo = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
    $stmt_ano_letivo->execute([':id' => $ano_letivo_id]);
    $ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "SELECT id, nome, matricula, bi, data_nascimento, encarregado_nome, encarregado_telefone FROM estudantes WHERE id = :id AND escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    die('Aluno não encontrado');
}

// ============================================
// BUSCAR TURMA ATUAL DO ALUNO
// ============================================
$sql_turma_atual = "
    SELECT t.id, t.nome, t.ano, t.turno, t.sala
    FROM matriculas m
    INNER JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
    LIMIT 1
";
$stmt_turma_atual = $conn->prepare($sql_turma_atual);
$stmt_turma_atual->execute([':aluno_id' => $aluno_id]);
$turma_atual = $stmt_turma_atual->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR HISTÓRICO DE FALTAS DO ALUNO
// ============================================
$sql_faltas = "
    SELECT 
        c.id,
        c.data_aula,
        c.status,
        c.minutos_atraso,
        c.justificativa,
        c.observacao,
        c.bimestre,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        t.nome as turma_nome,
        t.ano as turma_ano,
        al.ano as ano_letivo
    FROM chamada c
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    LEFT JOIN ano_letivo al ON al.id = c.ano_letivo_id
    WHERE c.estudante_id = :aluno_id
    AND c.status IN ('falta', 'atrasado')
";

$params = [':aluno_id' => $aluno_id];

if ($turma_id > 0) {
    $sql_faltas .= " AND c.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}
if ($ano_letivo_id > 0) {
    $sql_faltas .= " AND c.ano_letivo_id = :ano_letivo_id";
    $params[':ano_letivo_id'] = $ano_letivo_id;
}

$sql_faltas .= " ORDER BY c.data_aula DESC";

$stmt_faltas = $conn->prepare($sql_faltas);
$stmt_faltas->execute($params);
$faltas = $stmt_faltas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_registros = count($faltas);
$total_faltas = count(array_filter($faltas, function($f) { return $f['status'] == 'falta'; }));
$total_atrasos = count(array_filter($faltas, function($f) { return $f['status'] == 'atrasado'; }));

// Agrupar por disciplina
$faltas_por_disciplina = [];
foreach ($faltas as $falta) {
    $disciplina = $falta['disciplina_nome'];
    if (!isset($faltas_por_disciplina[$disciplina])) {
        $faltas_por_disciplina[$disciplina] = [
            'faltas' => 0,
            'atrasos' => 0
        ];
    }
    if ($falta['status'] == 'falta') {
        $faltas_por_disciplina[$disciplina]['faltas']++;
    } elseif ($falta['status'] == 'atrasado') {
        $faltas_por_disciplina[$disciplina]['atrasos']++;
    }
}

// Agrupar por bimestre
$faltas_por_bimestre = [];
foreach ($faltas as $falta) {
    $bimestre = $falta['bimestre'];
    if (!isset($faltas_por_bimestre[$bimestre])) {
        $faltas_por_bimestre[$bimestre] = [
            'faltas' => 0,
            'atrasos' => 0
        ];
    }
    if ($falta['status'] == 'falta') {
        $faltas_por_bimestre[$bimestre]['faltas']++;
    } elseif ($falta['status'] == 'atrasado') {
        $faltas_por_bimestre[$bimestre]['atrasos']++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarDataPDF($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Histórico de Faltas - ' . htmlspecialchars($aluno['nome']) . '</title>
    <style>
        @page {
            margin: 1.2cm 0.8cm;
            size: A4 portrait;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 8pt;
            line-height: 1.2;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 8px;
            border-bottom: 1.5px solid #1e5799;
            padding-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 12pt;
            font-weight: bold;
            color: #1e5799;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 6.5pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 5px 0 2px 0;
            color: #1e5799;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 8pt;
            color: #555;
            margin-bottom: 8px;
        }
        
        .info-section {
            margin-bottom: 8px;
            padding: 5px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 7pt;
        }
        
        .info-row {
            display: inline-block;
            margin: 0 8px;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .stats-line {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .stats-box {
            display: inline-block;
            width: 60px;
            margin: 0 2px;
            padding: 3px 2px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 3px;
            border: 0.5px solid #e0e0e0;
        }
        
        .stats-number {
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
        }
        
        .stats-label {
            font-size: 5.5pt;
            color: #666;
            margin-top: 1px;
            line-height: 1;
        }
        
        .badge-falta {
            background: #fadbd8;
            color: #c0392b;
            padding: 1px 6px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
            font-weight: bold;
        }
        
        .badge-atrasado {
            background: #fef9e7;
            color: #f39c12;
            padding: 1px 6px;
            border-radius: 8px;
            display: inline-block;
            font-size: 6pt;
            font-weight: bold;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            font-size: 6.5pt;
        }
        
        th {
            background: #1e5799;
            color: white;
            padding: 5px 3px;
            text-align: center;
            font-weight: bold;
            font-size: 6.5pt;
        }
        
        td {
            border: 0.5px solid #ddd;
            padding: 4px 3px;
            vertical-align: middle;
            font-size: 6.5pt;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 5.5pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 4px;
            background: white;
        }
        
        .assinatura {
            margin-top: 15px;
            text-align: center;
        }
        
        .assinatura-linha {
            display: inline-block;
            width: 160px;
            border-top: 0.5px solid #000;
            margin-top: 15px;
        }
        
        .assinatura-texto {
            font-size: 7pt;
            margin-top: 2px;
        }
        
        .resumo-box {
            margin: 10px 0;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .resumo-title {
            font-weight: bold;
            font-size: 8pt;
            margin-bottom: 5px;
            color: #1e5799;
        }
        
        .resumo-item {
            display: inline-block;
            width: 45%;
            margin: 2px 2%;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . '</div>
</div>

<div class="titulo">HISTÓRICO DE FALTAS E ATRASOS</div>
<div class="subtitulo">
    RELATÓRIO INDIVIDUAL DO ALUNO
</div>

<!-- Informações do Aluno -->
<div class="info-section">
    <span class="info-row"><span class="info-label">Aluno:</span> ' . strtoupper(htmlspecialchars($aluno['nome'])) . '</span>
    <span class="info-row"><span class="info-label">Matrícula:</span> ' . htmlspecialchars($aluno['matricula']) . '</span>
    <span class="info-row"><span class="info-label">BI:</span> ' . htmlspecialchars($aluno['bi'] ?? '---') . '</span>
    <span class="info-row"><span class="info-label">Data Nasc.:</span> ' . ($aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '---') . '</span>
</div>

<!-- Informações da Turma -->
<div class="info-section">
    <span class="info-row"><span class="info-label">Turma Atual:</span> ' . ($turma_atual['ano'] ?? '') . 'ª ' . htmlspecialchars($turma_atual['nome'] ?? '---') . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma_atual['turno'] ?? '---') . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma_atual['sala'] ?: '---') . '</span>
    <span class="info-row"><span class="info-label">Ano Letivo:</span> ' . $ano_letivo_ano . '</span>
</div>

<!-- Encarregado -->
<div class="info-section">
    <span class="info-row"><span class="info-label">Encarregado:</span> ' . htmlspecialchars($aluno['encarregado_nome'] ?? '---') . '</span>
    <span class="info-row"><span class="info-label">Telefone:</span> ' . htmlspecialchars($aluno['encarregado_telefone'] ?? '---') . '</span>
</div>

<!-- Estatísticas Gerais -->
<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . $total_registros . '</div><div class="stats-label">Registros</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #dc3545;">' . $total_faltas . '</div><div class="stats-label">Faltas</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #f39c12;">' . $total_atrasos . '</div><div class="stats-label">Atrasos</div></div>
</div>

<!-- Resumo por Disciplina -->
<div class="resumo-box">
    <div class="resumo-title">📊 RESUMO POR DISCIPLINA</div>';

foreach ($faltas_por_disciplina as $disciplina => $stats) {
    $html .= '
    <div class="resumo-item">
        <strong>' . htmlspecialchars($disciplina) . ':</strong> 
        <span style="color: #dc3545;">❌ ' . $stats['faltas'] . ' faltas</span>
        ' . ($stats['atrasos'] > 0 ? '<span style="color: #f39c12;"> | ⏰ ' . $stats['atrasos'] . ' atrasos</span>' : '') . '
    </div>';
}
$html .= '</div>';

// Resumo por Bimestre
$html .= '<div class="resumo-box">
    <div class="resumo-title">📅 RESUMO POR BIMESTRE</div>';

for ($b = 1; $b <= 4; $b++) {
    $faltas_bim = isset($faltas_por_bimestre[$b]['faltas']) ? $faltas_por_bimestre[$b]['faltas'] : 0;
    $atrasos_bim = isset($faltas_por_bimestre[$b]['atrasos']) ? $faltas_por_bimestre[$b]['atrasos'] : 0;
    $html .= '
    <div class="resumo-item">
        <strong>' . $b . 'º Bimestre:</strong> 
        <span style="color: #dc3545;">❌ ' . $faltas_bim . ' faltas</span>
        ' . ($atrasos_bim > 0 ? '<span style="color: #f39c12;"> | ⏰ ' . $atrasos_bim . ' atrasos</span>' : '') . '
    </div>';
}
$html .= '</div>';

// Tabela Detalhada
$html .= '<table>
    <thead>
        <tr>
            <th width="8%">Data</th>
            <th width="25%">Disciplina</th>
            <th width="15%">Turma</th>
            <th width="10%">Bimestre</th>
            <th width="12%">Status</th>
            <th width="30%">Justificativa</th>
        </tr>
    </thead>
    <tbody>';

if (empty($faltas)) {
    $html .= '
        <tr>
            <td colspan="6" class="text-center" style="padding: 20px;">Nenhuma falta ou atraso registrado para este aluno.</td>
        </tr>';
} else {
    foreach ($faltas as $falta) {
        $badge_class = $falta['status'] == 'falta' ? 'badge-falta' : 'badge-atrasado';
        $status_texto = $falta['status'] == 'falta' ? '❌ Falta' : '⏰ Atrasado';
        if ($falta['status'] == 'atrasado' && $falta['minutos_atraso'] > 0) {
            $status_texto .= ' (' . $falta['minutos_atraso'] . ' min)';
        }
        
        $html .= '
        <tr>
            <td class="text-center">' . formatarDataPDF($falta['data_aula']) . '</td>
            <td class="text-left">' . htmlspecialchars($falta['disciplina_nome']) . '<br><small>' . htmlspecialchars($falta['disciplina_codigo']) . '</small></td>
            <td class="text-center">' . $falta['turma_ano'] . 'ª ' . htmlspecialchars($falta['turma_nome']) . '</td>
            <td class="text-center">' . $falta['bimestre'] . 'º Bim</td>
            <td class="text-center"><span class="' . $badge_class . '">' . $status_texto . '</span></td>
            <td class="text-left">' . htmlspecialchars(substr($falta['justificativa'] ?? '', 0, 60)) . (strlen($falta['justificativa'] ?? '') > 60 ? '...' : '') . '</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- Observações -->
<div style="margin-top: 10px; padding: 5px; background: #f8f9fa; border-radius: 4px; font-size: 6pt;">
    <strong>📌 OBSERVAÇÕES:</strong><br>
    • Este relatório considera apenas faltas e atrasos registrados no sistema.<br>
    • Faltas justificadas são contabilizadas normalmente, mas podem ser analisadas separadamente.<br>
    • Atrasos são contabilizados com registro dos minutos de atraso.<br>
    • Em caso de dúvidas, contactar a secretaria escolar para validação das informações.
</div>

<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div class="assinatura-texto">' . htmlspecialchars($funcionario_nome) . '</div>
    <div class="assinatura-texto">Responsável pela Emissão</div>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Documento emitido eletronicamente em ' . date('d/m/Y H:i:s') . '
</div>

</body>
</html>';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isFontSubsettingEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nome_arquivo = 'historico_faltas_' . preg_replace('/[^a-zA-Z0-9]/', '_', $aluno['nome']) . '_' . date('Ymd_His') . '.pdf';

$dompdf->stream($nome_arquivo, ['Attachment' => false]);
exit;
?>