<?php
// escola/professor/gerar_pdf_horarios.php - Gerar PDF dos Horários

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
// BUSCAR HORÁRIOS DO PROFESSOR
// ============================================
$sql_horarios = "
    SELECT h.*, 
           t.nome as turma_nome, t.ano as turma_ano, t.turno, t.sala,
           d.nome as disciplina_nome, d.codigo as disciplina_codigo
    FROM horarios h
    INNER JOIN turmas t ON t.id = h.turma_id
    INNER JOIN disciplinas d ON d.id = h.disciplina_id
    WHERE h.professor_id = :professor_id
    ORDER BY FIELD(h.dia_semana, 'SEGUNDA', 'TERCA', 'QUARTA', 'QUINTA', 'SEXTA', 'SABADO'), h.horario_inicio
";
$stmt_horarios = $conn->prepare($sql_horarios);
$stmt_horarios->execute([':professor_id' => $professor_id]);
$horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ORGANIZAR HORÁRIOS POR DIA
// ============================================
$dias_semana = [
    'SEGUNDA' => 'Segunda-feira',
    'TERCA' => 'Terça-feira',
    'QUARTA' => 'Quarta-feira',
    'QUINTA' => 'Quinta-feira',
    'SEXTA' => 'Sexta-feira',
    'SABADO' => 'Sábado'
];

$horarios_por_dia = [];
foreach ($dias_semana as $key => $nome) {
    $horarios_por_dia[$key] = [];
}

foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    if (isset($horarios_por_dia[$dia])) {
        $horarios_por_dia[$dia][] = $horario;
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_aulas = count($horarios);
$dias_com_aula = 0;
foreach ($horarios_por_dia as $dia => $aulas) {
    if (!empty($aulas)) {
        $dias_com_aula++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

function getDiaSemanaExtenso($dia) {
    switch ($dia) {
        case 'SEGUNDA': return 'Segunda-feira';
        case 'TERCA': return 'Terça-feira';
        case 'QUARTA': return 'Quarta-feira';
        case 'QUINTA': return 'Quinta-feira';
        case 'SEXTA': return 'Sexta-feira';
        case 'SABADO': return 'Sábado';
        default: return $dia;
    }
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Meus Horários - ' . htmlspecialchars($professor['professor_nome']) . '</title>
    <style>
        @page {
            margin: 1.5cm;
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
            width: 100px;
        }
        
        .stats-section {
            margin-bottom: 15px;
            overflow: hidden;
        }
        
        .stats-box {
            float: left;
            width: 48%;
            margin: 0 1%;
            padding: 10px;
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
            margin-top: 5px;
        }
        
        .clearfix {
            clear: both;
        }
        
        .horario-card {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        
        .dia-titulo {
            background: #006B3E;
            color: white;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 11pt;
        }
        
        .horario-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .horario-item:last-child {
            border-bottom: none;
        }
        
        .horario-horario {
            font-weight: bold;
            color: #006B3E;
        }
        
        .badge-sala {
            background: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 8pt;
            display: inline-block;
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
        
        .page-number:before {
            content: "Página " counter(page);
        }
        
        .text-center { text-align: center; }
        .text-left { text-align: left; }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">SIGE ANGOLA</div>
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
    <div class="escola-info">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
</div>

<div class="titulo">MEU HORÁRIO DE AULAS</div>
<div class="subtitulo">
    Professor(a): ' . htmlspecialchars($professor['professor_nome']) . ' | Ano Letivo: ' . $ano_letivo_ano . '
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Professor(a):</span> ' . htmlspecialchars($professor['professor_nome']) . '</div>
    <div class="info-row"><span class="info-label">Data Emissão:</span> ' . date('d/m/Y H:i:s') . '</div>
</div>

<div class="stats-section">
    <div class="stats-box">
        <div class="stats-number">' . $total_aulas . '</div>
        <div class="stats-label">Total de Aulas</div>
    </div>
    <div class="stats-box">
        <div class="stats-number">' . $dias_com_aula . '</div>
        <div class="stats-label">Dias com Aula</div>
    </div>
</div>
<div class="clearfix"></div>

';

if (empty($horarios)) {
    $html .= '<div class="text-center" style="padding: 40px; background: #f8f9fa; border-radius: 8px;">Nenhum horário cadastrado.</div>';
} else {
    $html .= '<div class="row">';
    foreach ($dias_semana as $key => $nome_dia) {
        $aulas_dia = $horarios_por_dia[$key];
        $html .= '
    <div class="horario-card">
        <div class="dia-titulo">' . $nome_dia . '</div>';
        
        if (empty($aulas_dia)) {
            $html .= '<div class="text-center text-muted" style="padding: 20px;">Nenhuma aula agendada</div>';
        } else {
            foreach ($aulas_dia as $aula) {
                $html .= '
        <div class="horario-item">
            <div class="horario-horario">
                ' . formatarHorario($aula['horario_inicio']) . ' - ' . formatarHorario($aula['horario_fim']) . '
            </div>
            <div>
                <strong>' . htmlspecialchars($aula['disciplina_nome']) . '</strong>
                <br>
                <small>
                    <i class="fas fa-chalkboard"></i> ' . $aula['turma_ano'] . 'ª ' . htmlspecialchars($aula['turma_nome']) . '
                    <span class="badge-sala">Sala ' . ($aula['sala'] ?: 'N/D') . '</span>
                </small>
            </div>
        </div>';
            }
        }
        $html .= '
    </div>';
    }
    $html .= '</div>';
}

// Tabela de Horários
if (!empty($horarios)) {
    $html .= '
<h3 style="margin-top: 20px; margin-bottom: 10px;">📋 LISTA COMPLETA DE HORÁRIOS</h3>
<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
    <thead>
        <tr>
            <th style="background: #006B3E; color: white; padding: 8px; text-align: center;">Dia</th>
            <th style="background: #006B3E; color: white; padding: 8px; text-align: center;">Horário</th>
            <th style="background: #006B3E; color: white; padding: 8px; text-align: center;">Disciplina</th>
            <th style="background: #006B3E; color: white; padding: 8px; text-align: center;">Turma</th>
            <th style="background: #006B3E; color: white; padding: 8px; text-align: center;">Sala</th>
        </tr>
    </thead>
    <tbody>';
    
    foreach ($horarios as $horario) {
        $html .= '
        <tr>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">' . getDiaSemanaExtenso($horario['dia_semana']) . '</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">' . formatarHorario($horario['horario_inicio']) . ' - ' . formatarHorario($horario['horario_fim']) . '</td>
            <td style="border: 1px solid #ddd; padding: 6px;"><strong>' . htmlspecialchars($horario['disciplina_nome']) . '</strong><br><small>' . htmlspecialchars($horario['disciplina_codigo'] ?? '') . '</small></td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">' . $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']) . '<br><small>' . ucfirst($horario['turno']) . '</small></td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span class="badge-sala">Sala ' . ($horario['sala'] ?: 'N/D') . '</span></td>
        </tr>
        ';
    }
    
    $html .= '
    </tbody>
92table';
}

$html .= '
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

$nome_arquivo = 'meus_horarios_' . $professor['professor_nome'] . '_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

if (isset($_POST['gerar_pdf'])) {
    $dompdf->stream($nome_arquivo, ['Attachment' => false]);
} else {
    $dompdf->stream($nome_arquivo, ['Attachment' => true]);
}
exit;
?>