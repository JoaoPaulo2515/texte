<?php
// escola/professor/exportar_lista_pdf.php - Exportar Lista Nominal de Alunos

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

if ($turma_id <= 0) {
    die('Parâmetros inválidos. Turma não especificada.');
}

// ============================================
// VERIFICAR ACESSO DO PROFESSOR À TURMA
// ============================================
$sql_verifica = "
    SELECT COUNT(*) 
    FROM professor_disciplina_turma 
    WHERE professor_id = :professor_id AND turma_id = :turma_id
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
if ($stmt_verifica->fetchColumn() == 0) {
    die('Acesso negado! Você não tem permissão para acessar esta turma.');
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT id, nome, ano, turno, sala, capacidade FROM turmas WHERE id = :id AND escola_id = :escola_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada!');
}

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.data_nascimento,
        e.email,
        e.telefone,
        e.bi,
        e.genero,
        e.endereco,
        e.pai_nome,
        e.mae_nome,
        e.telefone,
        e.encarregado_nome,
        m.data_matricula
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function calcularIdadePDF($data_nascimento) {
    if (empty($data_nascimento)) return '-';
    $data_nasc = new DateTime($data_nascimento);
    $hoje = new DateTime();
    return $data_nasc->diff($hoje)->y;
}

function formatarDataPDF($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getSexoTexto($sexo) {
    switch ($sexo) {
        case 'M': return 'Masculino';
        case 'F': return 'Feminino';
        default: return '-';
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_masculino = 0;
$total_feminino = 0;
$soma_idades = 0;

foreach ($alunos as $aluno) {
    if ($aluno['genero'] == 'M') $total_masculino++;
    if ($aluno['genero'] == 'F') $total_feminino++;
    
    $idade = calcularIdadePDF($aluno['data_nascimento']);
    if (is_numeric($idade)) $soma_idades += $idade;
}
$media_idades = $total_alunos > 0 ? round($soma_idades / $total_alunos, 1) : 0;

// ============================================
// GERAR HTML PARA PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Lista Nominal - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</title>
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
            border-bottom: 1.5px solid #006B3E;
            padding-bottom: 5px;
        }
        
        .escola-nome {
            font-size: 12pt;
            font-weight: bold;
            color: #006B3E;
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
            color: #006B3E;
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
            width: 70px;
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0;
            font-size: 6.5pt;
        }
        
        th {
            background: #006B3E;
            color: white;
            padding: 4px 2px;
            text-align: center;
            font-weight: bold;
            font-size: 6.5pt;
        }
        
        td {
            border: 0.5px solid #ddd;
            padding: 3px 2px;
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
            width: 180px;
            border-top: 0.5px solid #000;
            margin-top: 15px;
        }
        
        .assinatura-texto {
            font-size: 7pt;
            margin-top: 2px;
        }
        
        .assinaturas {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .page-break {
            page-break-before: avoid;
            page-break-inside: avoid;
        }
        
        .badge-masculino {
            background: #cfe2ff;
            color: #084298;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
        
        .badge-feminino {
            background: #f8d7da;
            color: #721c24;
            padding: 1px 4px;
            border-radius: 8px;
            display: inline-block;
            font-size: 5.5pt;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | NIF: ' . htmlspecialchars($escola['nif'] ?? '') . '</div>
</div>

<div class="titulo">LISTA NOMINAL DE ALUNOS</div>
<div class="subtitulo">
    ' . $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | TURNO: ' . ucfirst($turma['turno']) . ' | SALA: ' . ($turma['sala'] ?: 'Não definida')
;

if ($turma['capacidade']) {
    $html .= ' | CAPACIDADE: ' . $turma['capacidade'] . ' alunos';
}

$html .= '
</div>

<div class="info-section">
    <span class="info-row"><span class="info-label">Turma:</span> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '</span>
    <span class="info-row"><span class="info-label">Turno:</span> ' . ucfirst($turma['turno']) . '</span>
    <span class="info-row"><span class="info-label">Sala:</span> ' . ($turma['sala'] ?: '-') . '</span>
    <span class="info-row"><span class="info-label">Professor:</span> ' . htmlspecialchars($professor['nome']) . '</span>
    <span class="info-row"><span class="info-label">Data:</span> ' . date('d/m/Y') . '</span>
</div>

<!-- Estatísticas em linha única -->
<div class="stats-line">
    <div class="stats-box"><div class="stats-number">' . $total_alunos . '</div><div class="stats-label">Total Alunos</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #084298;">' . $total_masculino . '</div><div class="stats-label">Masculino</div></div>
    <div class="stats-box"><div class="stats-number" style="color: #721c24;">' . $total_feminino . '</div><div class="stats-label">Feminino</div></div>
    <div class="stats-box"><div class="stats-number">' . $media_idades . '</div><div class="stats-label">Média Idade</div></div>
    <div class="stats-box"><div class="stats-number">' . ($turma['capacidade'] ? round(($total_alunos / $turma['capacidade']) * 100, 1) : '0') . '%</div><div class="stats-label">Ocupação</div></div>
</div>

<table>
    <thead>
        <tr>
            <th width="4%">Nº</th>
            <th width="25%">Nome do Aluno</th>
            <th width="10%">Matrícula</th>
            <th width="8%">Genero</th>
            <th width="8%">Idade</th>
            <th width="10%">Data Nasc.</th>
            <th width="12%">BI</th>
            <th width="12%">Telefone</th>
            <th width="11%">Contato Emerg.</th>
        </tr>
    </thead>
    <tbody>';

foreach ($alunos as $index => $aluno) {
    $sexo_texto = getSexoTexto($aluno['genero']);
    $sexo_badge = $aluno['genero'] == 'M' ? 'badge-masculino' : 'badge-feminino';
    $idade = calcularIdadePDF($aluno['data_nascimento']);
    
    $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-left"><strong>' . strtoupper(htmlspecialchars($aluno['nome'])) . '</strong></td>
            <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
            <td class="text-center"><span class="' . $sexo_badge . '">' . $sexo_texto . '</span></td>
            <td class="text-center">' . $idade . ' anos</td>
            <td class="text-center">' . formatarDataPDF($aluno['data_nascimento']) . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['bi'] ?? '---') . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['telefone'] ?? '---') . '</td>
            <td class="text-center">' . htmlspecialchars($aluno['telefone'] ?? '---') . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>';

// Adicionar segunda página se necessário para informações adicionais
if ($total_alunos > 20) {
    $html .= '
<div class="page-break"></div>

<div class="titulo">INFORMAÇÕES COMPLEMENTARES</div>

<table>
    <thead>
        <tr>
            <th width="4%">Nº</th>
            <th width="30%">Nome do Aluno</th>
            <th width="20%">Nome do Pai</th>
            <th width="20%">Nome da Mãe</th>
            <th width="13%">E-mail</th>
            <th width="13%">Necessidades Especiais</th>
        </tr>
    </thead>
    <tbody>';
    
    foreach ($alunos as $index => $aluno) {
        $html .= '
        <tr>
            <td class="text-center">' . ($index + 1) . '</td>
            <td class="text-left">' . htmlspecialchars(substr($aluno['nome'], 0, 35)) . '</td>
            <td class="text-left">' . htmlspecialchars($aluno['pai_nome'] ?? '---') . '</td>
            <td class="text-left">' . htmlspecialchars($aluno['mae_nome'] ?? '---') . '</td>
            <td class="text-left">' . htmlspecialchars($aluno['email'] ?? '---') . '</td>
            <td class="text-left">' . htmlspecialchars($aluno['encarregado_nome'] ?? 'Nenhuma') . '</td>
        </tr>';
    }
    
    $html .= '
    </tbody>
</table>';
}

$html .= '
<div class="assinaturas">
    <div class="assinatura-item">
        <div class="assinatura-linha"></div>
        <div class="assinatura-texto">' . htmlspecialchars($professor['nome']) . '</div>
        <div class="assinatura-texto">Professor(a) Responsável</div>
    </div>
    <div class="assinatura-item">
        <div class="assinatura-linha"></div>
        <div class="assinatura-texto">' . htmlspecialchars($escola['nome'] ?? 'Direção') . '</div>
        <div class="assinatura-texto">Direção / Carimbo da Escola</div>
    </div>
</div>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Lista Nominal emitida em ' . date('d/m/Y H:i:s') . ' | Página {PAGE_NUM} de {PAGE_COUNT}
</div>

</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF
// ============================================
try {
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    
    // Configuração para Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $options->set('chroot', realpath(__DIR__ . '/../../'));
    }
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Adicionar numeração de páginas
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->get_font("DejaVu Sans", "normal");
    $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($font) {
        $text = "Página $pageNumber de $pageCount";
        $font = $fontMetrics->getFont("DejaVu Sans", "normal");
        $width = $fontMetrics->getTextWidth($text, $font, 8);
        $x = ($canvas->get_width() - $width) / 2;
        $y = $canvas->get_height() - 15;
        $canvas->text($x, $y, $text, $font, 8, array(0.6, 0.6, 0.6));
    });
    
    $nome_arquivo = 'lista_nominal_' . $turma['ano'] . 'ª_' . preg_replace('/[^a-zA-Z0-9]/', '_', $turma['nome']) . '_' . date('Ymd_His') . '.pdf';
    $nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);
    
    // Limpar buffer de saída
    if (ob_get_level()) ob_end_clean();
    
    $dompdf->stream($nome_arquivo, ['Attachment' => true]);
    exit;
    
} catch (Exception $e) {
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
?>