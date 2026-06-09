<?php
// escola/professor/relatorios/gerar_pdf_estatistica_turma.php - Gerar PDF da Estatística por Turma

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
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($turma_id <= 0) {
    die('Parâmetros inválidos. Turma não selecionada.');
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada.');
}

$classe_ano = $turma['ano'] ?? 0;
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
$limite_positivo = $is_ensino_fundamental ? 4.5 : 9.5;

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR NA TURMA
// ============================================
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

// ============================================
// BUSCAR ESTATÍSTICAS
// ============================================
$estatisticas = [];
$total_alunos_turma = 0;

$sql_total = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute([':turma_id' => $turma_id]);
$total_alunos_turma = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

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
    
    $estatisticas[] = [
        'disciplina_nome' => $disciplina['nome'],
        'disciplina_codigo' => $disciplina['codigo'],
        'total_alunos' => $total_alunos_turma,
        'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
        'media_geral' => round($stats['media_geral'] ?? 0, 1),
        'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
        'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
        'aprovados' => $stats['total_aprovados'] ?? 0,
        'recuperacao' => $stats['total_recuperacao'] ?? 0,
        'reprovados' => $stats['total_reprovados'] ?? 0,
        'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
        'percentual_com_nota' => $total_alunos_turma > 0 ? round(($stats['total_alunos_com_nota'] / $total_alunos_turma) * 100, 1) : 0
    ];
}

// ============================================
// CALCULAR MÉDIAS GERAIS DA TURMA
// ============================================
$turma_media_geral = 0;
$turma_total_aprovados = 0;
$turma_total_alunos = 0;
$count_disciplinas = 0;
$melhor_disciplina = '';
$melhor_media = 0;
$pior_disciplina = '';
$pior_media = 100;
$total_aprovados_geral = 0;
$total_recuperacao_geral = 0;
$total_reprovados_geral = 0;

foreach ($estatisticas as $est) {
    if ($est['media_geral'] > 0) {
        $turma_media_geral += $est['media_geral'];
        $count_disciplinas++;
        
        if ($est['media_geral'] > $melhor_media) {
            $melhor_media = $est['media_geral'];
            $melhor_disciplina = $est['disciplina_nome'];
        }
        if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
            $pior_media = $est['media_geral'];
            $pior_disciplina = $est['disciplina_nome'];
        }
    }
    $turma_total_aprovados += $est['aprovados'];
    $turma_total_alunos += $est['total_alunos'];
    $total_aprovados_geral += $est['aprovados'];
    $total_recuperacao_geral += $est['recuperacao'];
    $total_reprovados_geral += $est['reprovados'];
}
$turma_media_geral = $count_disciplinas > 0 ? round($turma_media_geral / $count_disciplinas, 1) : 0;
$turma_taxa_aprovacao = $turma_total_alunos > 0 ? round(($turma_total_aprovados / $turma_total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getCorNota($nota, $is_ensino_fundamental) {
    if ($nota <= 0) return '#6c757d';
    if ($is_ensino_fundamental) {
        return $nota >= 4.5 ? '#28a745' : '#dc3545';
    } else {
        return $nota >= 9.5 ? '#28a745' : '#dc3545';
    }
}

function getBarColor($percentual) {
    if ($percentual >= 75) return '#28a745';
    if ($percentual >= 50) return '#ffc107';
    return '#dc3545';
}

function getNotaFormatada($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format($nota, 1);
}

$nome_bimestre = '';
switch($bimestre) {
    case 1: $nome_bimestre = '1º BIMESTRE'; break;
    case 2: $nome_bimestre = '2º BIMESTRE'; break;
    case 3: $nome_bimestre = '3º BIMESTRE'; break;
}

// ============================================
// GERAR HTML PARA PDF COM MARGENS AJUSTADAS
// ============================================
$html = '<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Estatística por Turma - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</title>
    <style>
        @page {
            margin: 1cm 2.5cm 1cm 3cm;
            /* margem: topo direita inferior esquerda */
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
            border-bottom: 1.5px solid #006B3E;
            padding-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 12pt;
            font-weight: bold;
            color: #1A2A6C;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 6pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 5px 0 2px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 7.5pt;
            color: #555;
            margin-bottom: 6px;
        }
        
        .info-section {
            margin-bottom: 6px;
            padding: 4px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 6.5pt;
            text-align: center;
        }
        
        .info-row {
            display: inline-block;
            margin: 0 8px;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        /* Estatísticas em linha única */
        .stats-line {
            text-align: center;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        .stats-box {
            display: inline-block;
            width: 55px;
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
            font-size: 5pt;
            color: #666;
            margin-top: 1px;
        }
        
        /* Destaques em linha */
        .destaque-line {
            text-align: center;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        .destaque-box {
            display: inline-block;
            width: 130px;
            margin: 0 3px;
            padding: 3px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 3px;
            border: 0.5px solid #e0e0e0;
        }
        
        .destaque-icon {
            font-size: 9pt;
        }
        
        .destaque-label {
            font-size: 5pt;
            color: #666;
        }
        
        .destaque-valor {
            font-size: 8pt;
            font-weight: bold;
            margin-top: 2px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            font-size: 6.5pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 4px 2px;
            text-align: center;
            font-weight: bold;
            font-size: 6pt;
        }
        
        td {
            border: 0.5px solid #ddd;
            padding: 3px 2px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-recuperacao {
            background: #ffc107;
            color: #333;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .progress-bar-bg {
            background: #e9ecef;
            border-radius: 6px;
            height: 4px;
            overflow: hidden;
            margin-top: 2px;
        }
        
        .progress-bar-fill {
            border-radius: 6px;
            height: 4px;
        }
        
        /* Observações do Professor */
        .observacao-box {
            margin-top: 12px;
            padding: 10px;
            background: #fff8e7;
            border-radius: 8px;
            border: 1px solid #ffc107;
            width: 100%;
            page-break-inside: avoid;
        }
        
        .observacao-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #856404;
            font-size: 9pt;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ffc107;
        }
        
        .observacao-texto {
            width: 100%;
        }
        
        .linha-observacao {
            width: 100%;
            border-bottom: 0.5px solid #e0e0e0;
            margin-bottom: 6px;
            padding-bottom: 4px;
            height: 12px;
        }
        
        .linha-observacao:last-child {
            margin-bottom: 0;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 5pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 3px;
            background: white;
        }
        
        .assinatura {
            margin-top: 8px;
            text-align: center;
        }
        
        .assinatura-linha {
            display: inline-block;
            width: 180px;
            border-top: 0.5px solid #000;
            margin-top: 10px;
        }
        
        .assinatura-texto {
            font-size: 7pt;
            margin-top: 2px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . '</div>
</div>

<div class="titulo">ESTATÍSTICA POR TURMA</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . $nome_bimestre . ' | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <span class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: '-') . '</span>
    <span class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</span>
    <span class="info-row"><span class="info-label">Data:</span> ' . date('d/m/Y') . '</span>
</div>

<!-- Estatísticas em linha única -->
<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . count($disciplinas_turma) . '</div><div class="stats-label">Disciplinas</div></div>
    <div class="stats-box"><div class="stats-number">' . $total_alunos_turma . '</div><div class="stats-label">Alunos</div></div>
    <div class="stats-box"><div class="stats-number">' . $turma_media_geral . '</div><div class="stats-label">Média Geral</div></div>
    <div class="stats-box"><div class="stats-number">' . $turma_taxa_aprovacao . '%</div><div class="stats-label">Aprovação</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #28a745;">' . $total_aprovados_geral . '</div><div class="stats-label">Aprovados</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #dc3545;">' . $total_reprovados_geral . '</div><div class="stats-label">Reprovados</div></div>
</div>

<!-- Destaques em linha -->
<div class="destaque-line">
    <div class="destaque-box">
        <div class="destaque-icon">🏆</div>
        <div class="destaque-label">Melhor Disciplina</div>
        <div class="destaque-valor" style="color: #28a745;">' . htmlspecialchars(substr($melhor_disciplina, 0, 18)) . '</div>
        <div class="destaque-valor" style="font-size: 6pt;">Média: ' . $melhor_media . '</div>
    </div>
    <div class="destaque-box">
        <div class="destaque-icon">📉</div>
        <div class="destaque-label">Menor Média</div>
        <div class="destaque-valor" style="color: #dc3545;">' . htmlspecialchars(substr($pior_disciplina, 0, 18)) . '</div>
        <div class="destaque-valor" style="font-size: 6pt;">Média: ' . $pior_media . '</div>
    </div>
    <div class="destaque-box">
        <div class="destaque-icon">✅</div>
        <div class="destaque-label">Recuperação</div>
        <div class="destaque-valor" style="color: #ffc107;">' . $total_recuperacao_geral . '</div>
        <div class="destaque-valor" style="font-size: 6pt;">alunos</div>
    </div>
</div>

<!-- Tabela de Estatísticas por Disciplina -->
<table style="width: 100%;">
    <thead>
        <tr>
            <th width="22%">Disciplina</th>
            <th width="6%">Código</th>
            <th width="8%">Alunos</th>
            <th width="8%">C/ Nota</th>
            <th width="8%">Média</th>
            <th width="7%">Min</th>
            <th width="7%">Max</th>
            <th width="8%">Aprov</th>
            <th width="8%">Recup</th>
            <th width="8%">Reprov</th>
            <th width="10%">Aprovação</th>
        </tr>
    </thead>
    <tbody>';

if (empty($estatisticas)) {
    $html .= '<tr><td colspan="11" style="text-align: center; padding: 20px;">Nenhuma estatística disponível para esta turma no bimestre selecionado.</td>';
} else {
    foreach ($estatisticas as $est) {
        $cor_media = getCorNota($est['media_geral'], $is_ensino_fundamental);
        $bar_color = getBarColor($est['percentual_aprovacao']);
        
        $media_display = $est['media_geral'] > 0 ? '<span style="color: ' . $cor_media . '; font-weight: bold;">' . $est['media_geral'] . '</span>' : '-';
        
        $html .= '
        <tr>
            <td class="text-left"><strong>' . htmlspecialchars(substr($est['disciplina_nome'], 0, 22)) . '</strong></td>
            <td class="text-center">' . htmlspecialchars($est['disciplina_codigo'] ?? '-') . '</td>
            <td class="text-center">' . $est['total_alunos'] . '</td>
            <td class="text-center">' . $est['com_nota'] . '<br><small>(' . $est['percentual_com_nota'] . '%)</small></td>
            <td class="text-center">' . $media_display . '</td>
            <td class="text-center">' . ($est['menor_nota'] > 0 ? $est['menor_nota'] : '-') . '</td>
            <td class="text-center">' . ($est['maior_nota'] > 0 ? $est['maior_nota'] : '-') . '</td>
            <td class="text-center"><span class="badge-aprovado">' . $est['aprovados'] . '</span></td>
            <td class="text-center"><span class="badge-recuperacao">' . $est['recuperacao'] . '</span></td>
            <td class="text-center"><span class="badge-reprovado">' . $est['reprovados'] . '</span></td>
            <td class="text-center">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: ' . $est['percentual_aprovacao'] . '%; background: ' . $bar_color . ';"></div>
                </div>
                <span>' . $est['percentual_aprovacao'] . '%</span>
            </td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- Observações do Professor -->
<div class="observacao-box">
    <div class="observacao-title">📝 OBSERVAÇÕES DO PROFESSOR</div>
    <div class="observacao-texto">';

for ($i = 1; $i <= 25; $i++) {
    $html .= '<div class="linha-observacao"></div>';
}

$html .= '
    </div>
</div>

<!-- Assinatura -->
<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div class="assinatura-texto">' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="assinatura-texto">Professor(a) Responsável</div>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Emitido em ' . date('d/m/Y H:i:s') . '
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

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $options->set('chroot', 'C:/xampp/htdocs');
}

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nome_arquivo = 'estatistica_turma_' . $turma['ano'] . 'ª_' . $turma['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>