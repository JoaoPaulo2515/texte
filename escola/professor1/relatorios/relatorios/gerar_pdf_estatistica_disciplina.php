<?php
// escola/professor/relatorios/gerar_pdf_estatistica_disciplina.php - Gerar PDF da Estatística por Disciplina

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
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($disciplina_id <= 0) {
    die('Parâmetros inválidos. Disciplina não selecionada.');
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
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    die('Disciplina não encontrada.');
}

// ============================================
// BUSCAR TURMAS ONDE O PROFESSOR LECIONA ESTA DISCIPLINA
// ============================================
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

// ============================================
// BUSCAR ESTATÍSTICAS POR TURMA
// ============================================
$estatisticas = [];
$total_alunos_geral = 0;
$total_aprovados_geral = 0;
$media_geral_soma = 0;
$count_turmas_com_media = 0;

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
    
    $media = round($stats['media_geral'] ?? 0, 1);
    if ($media > 0) {
        $media_geral_soma += $media;
        $count_turmas_com_media++;
    }
    $total_aprovados_geral += $stats['total_aprovados'] ?? 0;
    $total_alunos_geral += $turma['total_alunos'];
    
    $estatisticas[] = [
        'turma_nome' => $turma['nome'],
        'turma_ano' => $turma['ano'],
        'turma_turno' => $turma['turno'],
        'turma_sala' => $turma['sala'],
        'total_alunos' => $turma['total_alunos'],
        'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
        'media_geral' => $media,
        'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
        'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
        'aprovados' => $stats['total_aprovados'] ?? 0,
        'recuperacao' => $stats['total_recuperacao'] ?? 0,
        'reprovados' => $stats['total_reprovados'] ?? 0,
        'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
        'percentual_com_nota' => $turma['total_alunos'] > 0 ? round(($stats['total_alunos_com_nota'] / $turma['total_alunos']) * 100, 1) : 0
    ];
}

// ============================================
// CALCULAR MÉDIAS GERAIS
// ============================================
$disciplina_media_geral = $count_turmas_com_media > 0 ? round($media_geral_soma / $count_turmas_com_media, 1) : 0;
$disciplina_taxa_aprovacao = $total_alunos_geral > 0 ? round(($total_aprovados_geral / $total_alunos_geral) * 100, 1) : 0;

// Encontrar melhor e pior turma
$melhor_turma = '';
$melhor_media = 0;
$pior_turma = '';
$pior_media = 100;

foreach ($estatisticas as $est) {
    if ($est['media_geral'] > $melhor_media) {
        $melhor_media = $est['media_geral'];
        $melhor_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
    }
    if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
        $pior_media = $est['media_geral'];
        $pior_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Estatística por Disciplina - <?php echo htmlspecialchars($disciplina['nome']); ?></title>
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
        
        .tabela-estatisticas {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 8pt;
        }
        
        .tabela-estatisticas th {
            background: #006B3E;
            color: white;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
        }
        
        .tabela-estatisticas td {
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
            border-radius: 10px;
            height: 8px;
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
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE ANGOLA</div>
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
    <div class="escola-info">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="titulo">ESTATÍSTICA POR DISCIPLINA</div>
<div class="subtitulo">
    ' . htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE | ANO LETIVO ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Disciplina:</span> ' . htmlspecialchars($disciplina['nome']) . '</div>
    <div class="info-row"><span class="info-label">Código:</span> ' . htmlspecialchars($disciplina['codigo'] ?? '-') . '</div>
    <div class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="info-row"><span class="info-label">Data Emissão:</span> ' . date('d/m/Y H:i:s') . '</div>
</div>

<div class="stats-section">
    <table class="stats-table">
        <tr>
            <td><div class="stats-number">' . count($turmas_disciplina) . '</div><div class="stats-label">Turmas</div></td>
            <td><div class="stats-number">' . $disciplina_media_geral . '</div><div class="stats-label">Média Geral</div></td>
            <td><div class="stats-number">' . $disciplina_taxa_aprovacao . '%</div><div class="stats-label">Aprovação</div></td>
        </tr>
    </table>
</div>

<div style="overflow: hidden; margin-bottom: 15px;">
    <div style="float: left; width: 48%; margin-right: 2%; background: #f8f9fa; border-radius: 8px; padding: 10px; text-align: center;">
        <div style="font-size: 8pt; color: #666;">🏆 Melhor Turma</div>
        <div style="font-size: 12pt; font-weight: bold; color: #006B3E;">' . htmlspecialchars($melhor_turma) . '</div>
        <div style="font-size: 8pt; color: #666;">Média: ' . $melhor_media . '</div>
    </div>
    <div style="float: left; width: 48%; background: #f8f9fa; border-radius: 8px; padding: 10px; text-align: center;">
        <div style="font-size: 8pt; color: #666;">⚠️ Turma com Menor Média</div>
        <div style="font-size: 12pt; font-weight: bold; color: #006B3E;">' . htmlspecialchars($pior_turma) . '</div>
        <div style="font-size: 8pt; color: #666;">Média: ' . $pior_media . '</div>
    </div>
</div>
<div style="clear: both;"></div>

<h3 style="margin-top: 15px; margin-bottom: 10px;">📊 ESTATÍSTICAS POR TURMA</h3>

<table class="tabela-estatisticas">
    <thead>
        <tr>
            <th>Turma</th>
            <th>Turno</th>
            <th>Sala</th>
            <th>Alunos</th>
            <th>C/ Nota</th>
            <th>Média</th>
            <th>Mín.</th>
            <th>Máx.</th>
            <th>Aprov</th>
            <th>Recup</th>
            <th>Reprov</th>
            <th>Aprovação</th>
        </tr>
    </thead>
    <tbody>
';

if (empty($estatisticas)) {
    $html .= '
        <tr>
            <td colspan="12" style="text-align: center; border: 1px solid #ddd; padding: 20px;">Nenhuma estatística disponível para esta disciplina.</td>
        </tr>
    ';
} else {
    foreach ($estatisticas as $est) {
        $bg_color = '';
        if ($est['media_geral'] >= 9.5) {
            $bg_color = '#d4edda';
        } elseif ($est['media_geral'] >= 5) {
            $bg_color = '#fff3cd';
        } elseif ($est['media_geral'] > 0) {
            $bg_color = '#f8d7da';
        }
        
        $bar_color = '#28a745';
        if ($est['percentual_aprovacao'] >= 75) {
            $bar_color = '#28a745';
        } elseif ($est['percentual_aprovacao'] >= 50) {
            $bar_color = '#ffc107';
        } else {
            $bar_color = '#dc3545';
        }
        
        $html .= '
        <tr>
            <td style="text-align: left; border: 1px solid #ddd; padding: 6px 5px;"><strong>' . $est['turma_ano'] . 'ª ' . htmlspecialchars($est['turma_nome']) . '</strong></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ucfirst($est['turma_turno']) . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ($est['turma_sala'] ?: '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . $est['total_alunos'] . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . $est['com_nota'] . ' (' . $est['percentual_com_nota'] . '%)</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px; background: ' . $bg_color . ';"><strong>' . $est['media_geral'] . '</strong></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ($est['menor_nota'] > 0 ? $est['menor_nota'] : '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">' . ($est['maior_nota'] > 0 ? $est['maior_nota'] : '-') . '</td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span class="badge-aprovado">' . $est['aprovados'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span class="badge-recuperacao">' . $est['recuperacao'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;"><span class="badge-reprovado">' . $est['reprovados'] . '</span></td>
            <td style="text-align: center; border: 1px solid #ddd; padding: 6px 5px;">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="background: ' . $bar_color . '; width: ' . $est['percentual_aprovacao'] . '%;"></div>
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
    <strong>📈 Resumo da Disciplina:</strong>
    <ul style="margin-top: 5px; font-size: 8pt;">
        <li>Total de Turmas: ' . count($turmas_disciplina) . '</li>
        <li>Total de Alunos: ' . $total_alunos_geral . '</li>
        <li>Média Geral da Disciplina: ' . $disciplina_media_geral . '</li>
        <li>Taxa de Aprovação da Disciplina: ' . $disciplina_taxa_aprovacao . '%</li>
        <li>Melhor Desempenho: ' . htmlspecialchars($melhor_turma) . ' (Média: ' . $melhor_media . ')</li>
        <li>Turma com Maior Dificuldade: ' . htmlspecialchars($pior_turma) . ' (Média: ' . $pior_media . ')</li>
    </ul>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Documento emitido eletronicamente em ' . date('d/m/Y H:i:s') . '<br>
    ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '
</div>

<div style="text-align: center; margin-top: 20px; font-size: 7pt;">
    Legenda: <span style="background:#d4edda; padding:2px 6px;">Verde</span> - Média ≥ 9.5 | 
    <span style="background:#fff3cd; padding:2px 6px;">Amarelo</span> - Média 5.0-9.4 | 
    <span style="background:#f8d7da; padding:2px 6px;">Vermelho</span> - Média &lt; 5.0
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

$nome_arquivo = 'estatistica_disciplina_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>