<?php
// escola/professor/relatorios/gerar_pdf_mini_pautas.php - Gerar PDF das Mini Pautas

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
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($turma_id <= 0 || $disciplina_id <= 0) {
    die('Parâmetros inválidos');
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

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_final,
        n.status as situacao
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id 
        AND n.disciplina_id = :disciplina_id 
        AND n.bimestre = :bimestre 
        AND n.ano_letivo_id = :ano_letivo_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':bimestre' => $bimestre,
    ':ano_letivo_id' => $ano_letivo_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao'] == 'reprovado') $total_reprovados++;
    
    if ($aluno['media_final'] > 0) {
        $soma_notas += $aluno['media_final'];
    }
}
$media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getSituacaoTexto($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'Aprovado';
        case 'recuperacao': return 'Recuperação';
        case 'reprovado': return 'Reprovado';
        default: return 'Pendente';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
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
    <title>Mini Pauta - ' . htmlspecialchars($disciplina['nome']) . ' - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</title>
    <style>
        @page {
            margin: 2cm;
            size: A4 portrait;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        
        .logo {
            font-size: 20pt;
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
            font-size: 8pt;
            color: #666;
        }
        
        .titulo {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 15px 0 5px 0;
            color: #006B3E;
        }
        
        .subtitulo {
            text-align: center;
            font-size: 10pt;
            color: #555;
            margin-bottom: 15px;
        }
        
        .info-section {
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 9pt;
        }
        
        .info-row {
            margin-bottom: 5px;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 90px;
        }
        
        .stats-section {
            margin-bottom: 15px;
            overflow: hidden;
        }
        
        .stats-box {
            float: left;
            width: 31%;
            margin: 0 1%;
            padding: 8px;
            background: #f8f9fa;
            text-align: center;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        
        .stats-number {
            font-size: 18pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .stats-label {
            font-size: 8pt;
            color: #666;
            margin-top: 3px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 8px;
            text-align: center;
            font-weight: bold;
        }
        
        td {
            border: 1px solid #ddd;
            padding: 6px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .badge-aprovado {
            background: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 8pt;
        }
        
        .badge-recuperacao {
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 8pt;
        }
        
        .badge-reprovado {
            background: #f8d7da;
            color: #721c24;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 8pt;
        }
        
        .badge-pendente {
            background: #e9ecef;
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
            font-size: 8pt;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7pt;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            background: white;
        }
        
        .assinatura {
            margin-top: 30px;
            text-align: center;
        }
        
        .assinatura-linha {
            display: inline-block;
            width: 200px;
            border-top: 1px solid #000;
            margin-top: 30px;
        }
        
        .assinatura-texto {
            font-size: 9pt;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
    <div class="escola-info">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="titulo">MINI PAUTA DE NOTAS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</div>
    <div class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</div>
    <div class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: 'Não definida') . '</div>
    <div class="info-row"><span class="info-label">Disciplina:</span> ' . htmlspecialchars($disciplina['nome']) . '</div>
    <div class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="info-row"><span class="info-label">Ano Letivo:</span> ' . $ano_letivo_ano . '</div>
    <div class="info-row"><span class="info-label">Data Emissão:</span> ' . date('d/m/Y H:i:s') . '</div>
</div>

<div class="stats-section">
    <div class="stats-box">
        <div class="stats-number">' . $total_alunos . '</div>
        <div class="stats-label">Total Alunos</div>
    </div>
    <div class="stats-box">
        <div class="stats-number" style="color: #28a745;">' . $total_aprovados . '</div>
        <div class="stats-label">Aprovados</div>
    </div>
    <div class="stats-box">
        <div class="stats-number" style="color: #ffc107;">' . $total_recuperacao . '</div>
        <div class="stats-label">Recuperação</div>
    </div>
</div>

<div class="stats-section">
    <div class="stats-box">
        <div class="stats-number" style="color: #dc3545;">' . $total_reprovados . '</div>
        <div class="stats-label">Reprovados</div>
    </div>
    <div class="stats-box">
        <div class="stats-number">' . $media_geral . '</div>
        <div class="stats-label">Média Geral</div>
    </div>
    <div class="stats-box">
        <div class="stats-number">' . $percentual_aprovacao . '%</div>
        <div class="stats-label">Aprovação</div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th width="5%">#</th>
            <th width="40%">Aluno</th>
            <th width="15%">Matrícula</th>
            <th width="12%">MAC</th>
            <th width="12%">NPT</th>
            <th width="12%">Média</th>
            <th width="15%">Situação</th>
        </tr>
    </thead>
    <tbody>
';

foreach ($alunos as $index => $aluno) {
    $situacao = $aluno['situacao'] ?? 'pendente';
    $badge_class = '';
    switch ($situacao) {
        case 'aprovado': $badge_class = 'badge-aprovado'; break;
        case 'recuperacao': $badge_class = 'badge-recuperacao'; break;
        case 'reprovado': $badge_class = 'badge-reprovado'; break;
        default: $badge_class = 'badge-pendente';
    }
    $situacao_texto = getSituacaoTexto($situacao);
    
    $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-left"><strong>' . htmlspecialchars($aluno['nome']) . '</strong></td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <td class="text-center">' . number_format($aluno['mac'] ?? 0, 1) . '</td>
            <td class="text-center">' . number_format($aluno['npt'] ?? 0, 1) . '</td>
            <td class="text-center"><strong>' . number_format($aluno['media_final'] ?? 0, 1) . '</strong></td>
            <td class="text-center"><span class="' . $badge_class . '">' . $situacao_texto . '</span></td>
        </tr>
    ';
}

$html .= '
    </tbody>
</table>

<div class="assinatura">
    <div class="assinatura-linha"></div>
    <div class="assinatura-texto">' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="assinatura-texto">Professor(a) Responsável</div>
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

$nome_arquivo = 'mini_pauta_' . $turma['ano'] . 'ª_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>