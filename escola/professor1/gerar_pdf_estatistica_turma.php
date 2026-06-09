<?php
// escola/professor/relatorios/gerar_pdf_estatistica_turma.php - Gerar PDF da Estatística por Turma

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF
// ============================================
require_once __DIR__ . '/../../../vendor/autoload.php';

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
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
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

// Buscar total de alunos da turma
$sql_total = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute([':turma_id' => $turma_id]);
$total_alunos_turma = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

foreach ($disciplinas_turma as $disciplina) {
    // Buscar estatísticas das notas
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
}
$turma_media_geral = $count_disciplinas > 0 ? round($turma_media_geral / $count_disciplinas, 1) : 0;
$turma_taxa_aprovacao = $turma_total_alunos > 0 ? round(($turma_total_aprovados / $turma_total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusClass($media) {
    if ($media >= 9.5) return 'nota-alta';
    if ($media >= 5) return 'nota-media';
    return 'nota-baixa';
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Estatística por Turma - <?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></title>
    <style>
        @page {
            margin: 1.5cm;
            size: A4 portrait;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        
        .logo {
            font-size: 18pt;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 14pt;
            font-weight: bold;
            color: #1A2A6C;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .escola-info {
            font-size: 7pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0 5px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 10pt;
            color: #555;
            margin-bottom: 15px;
        }
        
        .info-section {
            margin-bottom: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 8pt;
        }
        
        .info-row {
            margin-bottom: 3px;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 80px;
        }
        
        .stats-section {
            margin-bottom: 15px;
        }
        
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .stats-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .stats-number {
            font-size: 16pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .stats-label {
            font-size: 7pt;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 8pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
        }
        
        td {
            border: 1px solid #ddd;
            padding: 6px 5px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 7pt;
        }
        
        .badge-recuperacao {
            background: #ffc107;
            color: #212529;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 7pt;
        }
        
        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 7pt;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 6pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 6px;
            background: white;
        }
        
        .progress-bar-bg {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            background: #28a745;
            border-radius: 10px;
            height: 8px;
        }
        
        .destaque-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .destaque-label {
            font-size: 8pt;
            color: #666;
        }
        
        .destaque-valor {
            font-size: 14pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .nota-alta {
            background-color: #d4edda;
        }
        .nota-media {
            background-color: #fff3cd;
        }
        .nota-baixa {
            background-color: #f8d7da;
        }
        
        .clearfix {
            clear: both;
        }
        .float-left {
            float: left;
        }
        .width-48 {
            width: 48%;
        }
        .mr-2 {
            margin-right: 2%;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE ANGOLA</div>
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
    <div class="escola-info">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="titulo">ESTATÍSTICA POR TURMA</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . $bimestre . 'º BIMESTRE | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</div>
    <div class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</div>
    <div class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: 'Não definida') . '</div>
    <div class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="info-row"><span class="info-label">Data Emissão:</span> ' . date('d/m/Y H:i:s') . '</div>
</div>

<div class="stats-section">
    <table class="stats-table">
        <tr>
            <td><div class="stats-number">' . count($disciplinas_turma) . '</div><div class="stats-label">Disciplinas</div></td>
            <td><div class="stats-number">' . $total_alunos_turma . '</div><div class="stats-label">Alunos</div></td>
            <td><div class="stats-number">' . $turma_media_geral . '</div><div class="stats-label">Média Geral</div></td>
            <td><div class="stats-number">' . $turma_taxa_aprovacao . '%</div><div class="stats-label">Aprovação</div></td>
        </tr>
    </table>
</div>

';

if ($melhor_disciplina) {
    $html .= '
<div style="overflow: hidden; margin-bottom: 15px;">
    <div style="float: left; width: 48%; margin-right: 2%; background: #f8f9fa; border-radius: 8px; padding: 10px; text-align: center;">
        <div style="font-size: 8pt; color: #666;">⭐ Melhor Disciplina</div>
        <div style="font-size: 14pt; font-weight: bold; color: #006B3E;">' . htmlspecialchars($melhor_disciplina) . '</div>
        <div style="font-size: 8pt; color: #666;">Média: ' . $melhor_media . '</div>
    </div>
    <div style="float: left; width: 48%; background: #f8f9fa; border-radius: 8px; padding: 10px; text-align: center;">
        <div style="font-size: 8pt; color: #666;">⚠️ Disciplina com Menor Média</div>
        <div style="font-size: 14pt; font-weight: bold; color: #006B3E;">' . htmlspecialchars($pior_disciplina) . '</div>
        <div style="font-size: 8pt; color: #666;">Média: ' . $pior_media . '</div>
    </div>
</div>
<div style="clear: both;"></div>
';
}

$html .= '
<h3 style="margin-top: 15px; margin-bottom: 10px;">📊 ESTATÍSTICAS POR DISCIPLINA</h3>

<table style="width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 8pt;">
    <thead>
        <tr>
            <th rowspan="2" style="background: #006B3E; color: white; padding: 8px 5px;">Disciplina</th>
            <th rowspan="2" style="background: #006B3E; color: white; padding: 8px 5px;">Código</th>
            <th colspan="2" style="background: #006B3E; color: white; padding: 8px 5px;">Alunos</th>
            <th colspan="3" style="background: #006B3E; color: white; padding: 8px 5px;">Notas</th>
            <th colspan="3" style="background: #006B3E; color: white; padding: 8px 5px;">Resultados</th>
            <th rowspan="2" style="background: #006B3E; color: white; padding: 8px 5px;">Aprovação</th>
        </tr>
        <tr>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Total</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">C/ Nota</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Média</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Mín.</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Máx.</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Aprov</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Recup</th>
            <th style="background: #006B3E; color: white; padding: 8px 5px;">Reprov</th>
        </tr>
    </thead>
    <tbody>
';

if (empty($estatisticas)) {
    $html .= '
        <tr>
            <td colspan="11" style="text-align: center; border: 1px solid #ddd; padding: 20px;">Nenhuma estatística disponível para esta turma no bimestre selecionado.</td>
        </tr>
    ';
} else {
    foreach ($estatisticas as $est) {
        $media_class = getStatusClass($est['media_geral']);
        $bg_color = '';
        if ($media_class == 'nota-alta') $bg_color = '#d4edda';
        elseif ($media_class == 'nota-media') $bg_color = '#fff3cd';
        elseif ($est['media_geral'] > 0) $bg_color = '#f8d7da';
        
        $bar_color = $est['percentual_aprovacao'] >= 75 ? '#28a745' : ($est['percentual_aprovacao'] >= 50 ? '#ffc107' : '#dc3545');
        
        $html .= '
        <tr>
            <td style="text-align: left; border: 1px solid #ddd; padding: 6px 5px;"><strong>' . htmlspecialchars($est['disciplina_nome']) . '</strong></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . htmlspecialchars($est['disciplina_codigo'] ?? '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . $est['total_alunos'] . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . $est['com_nota'] . ' (' . $est['percentual_com_nota'] . '%)</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px; background: ' . $bg_color . ';"><strong>' . $est['media_geral'] . '</strong></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ($est['menor_nota'] > 0 ? $est['menor_nota'] : '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ($est['maior_nota'] > 0 ? $est['maior_nota'] : '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 12px; font-size: 7pt;">' . $est['aprovados'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span style="background: #ffc107; color: #212529; padding: 2px 8px; border-radius: 12px; font-size: 7pt;">' . $est['recuperacao'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-size: 7pt;">' . $est['reprovados'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">
                <div style="background: #e9ecef; border-radius: 10px; height: 8px; overflow: hidden;">
                    <div style="background: ' . $bar_color . '; border-radius: 10px; height: 8px; width: ' . $est['percentual_aprovacao'] . '%;"></div>
                </div>
                <span>' . $est['percentual_aprovacao'] . '%</span>
            </td>
        </tr>
        ';
    }
}

$html .= '
    </tbody>
</table>

<div style="margin-top: 20px;">
    <strong>📈 Resumo da Turma:</strong>
    <ul style="margin-top: 5px; font-size: 8pt;">
        <li>Total de Disciplinas: ' . count($disciplinas_turma) . '</li>
        <li>Total de Alunos: ' . $total_alunos_turma . '</li>
        <li>Média Geral da Turma: ' . $turma_media_geral . '</li>
        <li>Taxa de Aprovação da Turma: ' . $turma_taxa_aprovacao . '%</li>
';

if ($melhor_disciplina) {
    $html .= '<li>Melhor Desempenho: ' . htmlspecialchars($melhor_disciplina) . ' (Média: ' . $melhor_media . ')</li>';
    $html .= '<li>Disciplina com Maior Dificuldade: ' . htmlspecialchars($pior_disciplina) . ' (Média: ' . $pior_media . ')</li>';
}

$html .= '
    </ul>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Documento emitido eletronicamente em ' . date('d/m/Y H:i:s') . '<br>
    ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '
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