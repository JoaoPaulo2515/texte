<?php
// escola/professor/gerar_pdf_calendario_provas.php - Gerar PDF do Calendário de Provas

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
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');
$ano_letivo_inicio = $ano_letivo['data_inicio'] ?? '';
$ano_letivo_fim = $ano_letivo['data_fim'] ?? '';

// ============================================
// PARÂMETROS
// ============================================
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$status = isset($_GET['status']) ? $_GET['status'] : 'todas';

// ============================================
// BUSCAR DADOS DA ESCOLA COM LOGO
// ============================================
$sql_escola = "SELECT nome, logo, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Caminho do logo
$logo_path = '';
$logo_base64 = '';
if (!empty($escola['logo']) && file_exists('../../uploads/escolas/logos/' . $escola['logo'])) {
    $logo_path = '../../uploads/escolas/logos/' . $escola['logo'];
    // Converter logo para base64 para o PDF
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/' . pathinfo($logo_path, PATHINFO_EXTENSION) . ';base64,' . base64_encode($logo_data);
}

// ============================================
// BUSCAR TURMA (se selecionada)
// ============================================
$turma_nome = '';
$turma_ano = '';
if ($turma_id > 0) {
    $sql_turma = "SELECT nome, ano FROM turmas WHERE id = :id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    if ($turma) {
        $turma_nome = $turma['nome'];
        $turma_ano = $turma['ano'];
    }
}

// ============================================
// BUSCAR PROVAS DO CALENDÁRIO
// ============================================
$sql_provas = "
    SELECT 
        cp.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo
    FROM calendario_provas cp
    INNER JOIN turmas t ON t.id = cp.turma_id
    INNER JOIN disciplinas d ON d.id = cp.disciplina_id
    WHERE cp.professor_id = :professor_id
    AND cp.ano_letivo_id = :ano_letivo_id
";

if ($bimestre > 0) {
    $sql_provas .= " AND cp.bimestre = :bimestre";
}
if ($turma_id > 0) {
    $sql_provas .= " AND cp.turma_id = :turma_id";
}
if ($mes > 0 && $mes <= 12) {
    $sql_provas .= " AND MONTH(cp.data_prova) = :mes AND YEAR(cp.data_prova) = :ano";
}
if ($status != 'todas') {
    $sql_provas .= " AND cp.status = :status";
}

$sql_provas .= " ORDER BY cp.data_prova ASC, cp.horario ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id
];
if ($bimestre > 0) {
    $params[':bimestre'] = $bimestre;
}
if ($turma_id > 0) {
    $params[':turma_id'] = $turma_id;
}
if ($mes > 0 && $mes <= 12) {
    $params[':mes'] = $mes;
    $params[':ano'] = $ano;
}
if ($status != 'todas') {
    $params[':status'] = $status;
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_provas = count($provas);
$provas_agendadas = 0;
$provas_realizadas = 0;
$provas_canceladas = 0;
$provas_por_bimestre = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

foreach ($provas as $prova) {
    switch ($prova['status']) {
        case 'agendada': $provas_agendadas++; break;
        case 'realizada': $provas_realizadas++; break;
        case 'cancelada': $provas_canceladas++; break;
    }
    if (isset($provas_por_bimestre[$prova['bimestre']])) {
        $provas_por_bimestre[$prova['bimestre']]++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

function getStatusTexto($status) {
    switch ($status) {
        case 'agendada': return 'Agendada';
        case 'realizada': return 'Realizada';
        case 'cancelada': return 'Cancelada';
        default: return '-';
    }
}

function getTipoTexto($tipo) {
    switch ($tipo) {
        case 'prova': return 'Prova';
        case 'teste': return 'Teste';
        case 'trabalho': return 'Trabalho';
        case 'recuperacao': return 'Recuperação';
        case 'exame': return 'Exame';
        default: return '-';
    }
}

// Montar filtros aplicados
$filtros_aplicados = [];
if ($bimestre > 0) $filtros_aplicados[] = 'Bimestre: ' . $bimestre . 'º';
if ($turma_id > 0 && $turma_nome) $filtros_aplicados[] = 'Turma: ' . $turma_ano . 'ª ' . $turma_nome;
if ($mes > 0) {
    $meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $filtros_aplicados[] = 'Mês: ' . $meses[$mes - 1] . '/' . $ano;
}
if ($status != 'todas') $filtros_aplicados[] = 'Status: ' . getStatusTexto($status);
$filtros_texto = !empty($filtros_aplicados) ? implode(' | ', $filtros_aplicados) : 'Todos os registros';

// ============================================
// GERAR HTML PARA PDF COM LOGO
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Calendário de Provas - ' . htmlspecialchars($professor['professor_nome']) . '</title>
    <style>
        @page {
            margin: 2cm;
            size: A4 landscape;
        }
        
        body {
            font-family: "DejaVu Sans", "Arial", sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 0;
        }
        
        /* Cabeçalho principal com logo */
        .header-principal {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #006B3E;
        }
        
        .header-logo {
            display: inline-block;
            vertical-align: middle;
            width: 80px;
            text-align: center;
        }
        
        .header-logo img {
            max-width: 70px;
            max-height: 70px;
        }
        
        .header-textos {
            display: inline-block;
            vertical-align: middle;
            text-align: center;
            width: calc(100% - 100px);
        }
        
        .logo-sige {
            font-size: 16pt;
            font-weight: bold;
            color: #006B3E;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }
        
        .nome-escola {
            font-size: 14pt;
            font-weight: bold;
            color: #1A2A6C;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .endereco-escola {
            font-size: 8pt;
            color: #555;
            margin-bottom: 3px;
        }
        
        .contato-escola {
            font-size: 8pt;
            color: #555;
            margin-bottom: 3px;
        }
        
        /* Título do relatório */
        .titulo-relatorio {
            text-align: center;
            margin: 15px 0 10px 0;
        }
        
        .titulo-principal {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #006B3E;
            letter-spacing: 3px;
        }
        
        .subtitulo-relatorio {
            text-align: center;
            font-size: 10pt;
            color: #555;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #ddd;
        }
        
        /* Cards de informação */
        .info-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }
        
        .info-linha {
            margin-bottom: 6px;
        }
        
        .info-linha:last-child {
            margin-bottom: 0;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 110px;
            color: #006B3E;
        }
        
        .info-valor {
            display: inline-block;
            color: #333;
        }
        
        /* Filtros aplicados */
        .filtros-card {
            background: #e9ecef;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 15px;
        }
        
        .filtros-label {
            font-weight: bold;
            color: #006B3E;
            margin-right: 10px;
        }
        
        /* Tabela de estatísticas */
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .stats-table td {
            padding: 10px;
            text-align: center;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .stats-number {
            font-size: 20pt;
            font-weight: bold;
        }
        
        .stats-number.agendada { color: #856404; }
        .stats-number.realizada { color: #155724; }
        .stats-number.cancelada { color: #721c24; }
        .stats-number.total { color: #006B3E; }
        
        .stats-label {
            font-size: 8pt;
            color: #666;
            margin-top: 3px;
        }
        
        /* Bimestres */
        .bimestre-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .bimestre-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .bimestre-numero {
            font-size: 12pt;
            font-weight: bold;
            color: #006B3E;
        }
        
        .bimestre-qtd {
            font-size: 18pt;
            font-weight: bold;
            margin-top: 3px;
        }
        
        /* Tabela de provas */
        .tabela-provas {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 8pt;
        }
        
        .tabela-provas th {
            background: #006B3E;
            color: white;
            padding: 8px 6px;
            text-align: center;
            font-weight: bold;
        }
        
        .tabela-provas td {
            border: 1px solid #ddd;
            padding: 6px;
            vertical-align: middle;
        }
        
        .tabela-provas tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 7pt;
            font-weight: 500;
        }
        
        .status-agendada {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-realizada {
            background: #d4edda;
            color: #155724;
        }
        
        .status-cancelada {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Título das seções */
        .secao-titulo {
            background: #006B3E;
            color: white;
            padding: 6px 10px;
            font-weight: bold;
            font-size: 10pt;
            margin: 12px 0 8px 0;
            border-radius: 5px;
        }
        
        /* Rodapé */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7pt;
            color: #666;
            padding: 8px 0;
            background: white;
        }
        
        .footer-separator {
            border-top: 1px solid #ddd;
            margin-bottom: 6px;
        }
        
        .footer-content {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .footer-linha {
            margin: 2px 0;
        }
        
        .footer-sistema {
            font-weight: bold;
            color: #006B3E;
        }
        
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
    </style>
</head>
<body>

<!-- ============================================ -->
<!-- CABEÇALHO PRINCIPAL COM LOGO -->
<!-- ============================================ -->
<div class="header-principal">
    <div class="header-logo">
';

// Adicionar logo se existir
if ($logo_base64) {
    $html .= '<img src="' . $logo_base64 . '" alt="Logo da Escola">';
} else {
    $html .= '<div style="width: 70px; height: 70px; background: #f0f0f0; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24pt;">🏫</div>';
}

$html .= '
    </div>
    <div class="header-textos">
        <div class="logo-sige">SIGE ANGOLA</div>
        <div class="nome-escola">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SISTEMA INTEGRADO DE GESTÃO ESCOLAR')) . '</div>
        <div class="endereco-escola">' . htmlspecialchars($escola['endereco'] ?? '') . '</div>
        <div class="contato-escola">Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | E-mail: ' . htmlspecialchars($escola['email'] ?? '') . '</div>
    </div>
</div>

<!-- ============================================ -->
<!-- TÍTULO DO RELATÓRIO -->
<!-- ============================================ -->
<div class="titulo-relatorio">
    <div class="titulo-principal">CALENDÁRIO DE PROVAS</div>
</div>
<div class="subtitulo-relatorio">
    Professor(a): ' . htmlspecialchars($professor['professor_nome']) . ' | Ano Letivo: ' . $ano_letivo_ano . '
</div>

<!-- ============================================ -->
<!-- INFORMAÇÕES DO RELATÓRIO -->
<!-- ============================================ -->
<div class="info-card">
    <div class="info-linha">
        <span class="info-label">Professor(a):</span>
        <span class="info-valor">' . htmlspecialchars($professor['professor_nome']) . '</span>
    </div>
    <div class="info-linha">
        <span class="info-label">Ano Letivo:</span>
        <span class="info-valor">' . $ano_letivo_ano;
        if ($ano_letivo_inicio && $ano_letivo_fim) {
            $html .= ' (' . formatarData($ano_letivo_inicio) . ' a ' . formatarData($ano_letivo_fim) . ')';
        }
        $html .= '</span>
    </div>
    <div class="info-linha">
        <span class="info-label">Data de Emissão:</span>
        <span class="info-valor">' . date('d/m/Y H:i:s') . '</span>
    </div>
</div>

<!-- ============================================ -->
<!-- FILTROS APLICADOS -->
<!-- ============================================ -->
<div class="filtros-card">
    <span class="filtros-label">FILTROS APLICADOS:</span> ' . $filtros_texto . '
</div>

';

// ============================================ -->
// ESTATÍSTICAS GERAIS
// ============================================ -->
if (!empty($provas)) {
    $html .= '
<div class="secao-titulo">📊 ESTATÍSTICAS GERAIS</div>
<table class="stats-table">
    <tr>
        <td>
            <div class="stats-number total">' . $total_provas . '</div>
            <div class="stats-label">Total de Provas</div>
        </td>
        <td>
            <div class="stats-number agendada">' . $provas_agendadas . '</div>
            <div class="stats-label">Agendadas</div>
        </td>
        <td>
            <div class="stats-number realizada">' . $provas_realizadas . '</div>
            <div class="stats-label">Realizadas</div>
        </td>
        <td>
            <div class="stats-number cancelada">' . $provas_canceladas . '</div>
            <div class="stats-label">Canceladas</div>
        </td>
    </tr>
</table>

<!-- ============================================ -->
<!-- DISTRIBUIÇÃO POR BIMESTRE -->
<!-- ============================================ -->
<div class="secao-titulo">📅 DISTRIBUIÇÃO POR BIMESTRE</div>
<table class="bimestre-table">
    <tr>
        <td><div class="bimestre-numero">1º Bimestre</div><div class="bimestre-qtd">' . $provas_por_bimestre[1] . '</div></td>
        <td><div class="bimestre-numero">2º Bimestre</div><div class="bimestre-qtd">' . $provas_por_bimestre[2] . '</div></td>
        <td><div class="bimestre-numero">3º Bimestre</div><div class="bimestre-qtd">' . $provas_por_bimestre[3] . '</div></td>
        <td><div class="bimestre-numero">4º Bimestre</div><div class="bimestre-qtd">' . $provas_por_bimestre[4] . '</div></td>
    </tr>
</table>

';
}

// ============================================ -->
// LISTA DE PROVAS
// ============================================ -->
if (empty($provas)) {
    $html .= '<div class="text-center" style="padding: 20px; background: #f8f9fa; border-radius: 8px;">Nenhuma prova encontrada para os filtros selecionados.</div>';
} else {
    $html .= '<div class="secao-titulo">📋 LISTA DE PROVAS</div>';
    $html .= '<table class="tabela-provas">
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="10%">Data</th>
                <th width="8%">Horário</th>
                <th width="15%">Disciplina</th>
                <th width="8%">Código</th>
                <th width="10%">Turma</th>
                <th width="17%">Título</th>
                <th width="8%">Tipo</th>
                <th width="8%">Bim</th>
                <th width="11%">Status</th>
            </tr>
        </thead>
        <tbody>';
    
    $contador = 1;
    foreach ($provas as $prova) {
        $status_class = '';
        switch ($prova['status']) {
            case 'agendada': $status_class = 'status-agendada'; break;
            case 'realizada': $status_class = 'status-realizada'; break;
            case 'cancelada': $status_class = 'status-cancelada'; break;
        }
        
        $html .= '
            <tr>
                <td class="text-center">' . $contador++ . '</td>
                <td class="text-center">' . formatarData($prova['data_prova']) . '</td>
                <td class="text-center">' . formatarHorario($prova['horario']) . '</td>
                <td class="text-left"><strong>' . htmlspecialchars($prova['disciplina_nome']) . '</strong></td>
                <td class="text-center">' . htmlspecialchars($prova['disciplina_codigo'] ?? '-') . '</td>
                <td class="text-center">' . $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']) . '</td>
                <td class="text-left">' . htmlspecialchars($prova['titulo']) . '</td>
                <td class="text-center">' . getTipoTexto($prova['tipo']) . '</td>
                <td class="text-center">' . $prova['bimestre'] . 'º</td>
                <td class="text-center"><span class="status-badge ' . $status_class . '">' . getStatusTexto($prova['status']) . '</span></td>
            </tr>
        ';
    }
    
    $html .= '
        </tbody>
    </table>';
}

// ============================================ -->
// OBSERVAÇÕES E CONTEÚDO
// ============================================ -->
$hasContent = false;
foreach ($provas as $prova) {
    if (!empty($prova['observacoes']) || !empty($prova['conteudo'])) {
        $hasContent = true;
        break;
    }
}

if ($hasContent) {
    $html .= '<div class="secao-titulo">📝 OBSERVAÇÕES E CONTEÚDO DAS PROVAS</div>';
    $html .= '<table class="tabela-provas">
        <thead>
            <tr>
                <th width="20%">Disciplina</th>
                <th width="15%">Turma</th>
                <th width="35%">Conteúdo Programático</th>
                <th width="30%">Observações</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($provas as $prova) {
        if (!empty($prova['conteudo']) || !empty($prova['observacoes'])) {
            $html .= '
            <tr>
                <td class="text-left"><strong>' . htmlspecialchars($prova['disciplina_nome']) . '</strong></td>
                <td class="text-center">' . $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']) . '</td>
                <td class="text-left">' . nl2br(htmlspecialchars(substr($prova['conteudo'] ?? '', 0, 300))) . '</td>
                <td class="text-left">' . nl2br(htmlspecialchars(substr($prova['observacoes'] ?? '', 0, 300))) . '</td>
            </tr>
            ';
        }
    }
    
    $html .= '
        </tbody>
    </table>';
}

// ============================================ -->
//  RODAPÉ FIXO EM TODAS AS PÁGINAS -->
//  ============================================ -->
$html .= '
<div class="footer">
    <div class="footer-separator"></div>
    <div class="footer-content">
        <div class="footer-linha">
            <span class="footer-sistema">SIGE Angola</span> - Sistema Integrado de Gestão Escolar
        </div>
        <div class="footer-linha">
            Documento emitido eletronicamente em ' . date('d/m/Y H:i:s') . '
        </div>
        <div class="footer-linha">
            ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola['email'] ?? '') . '
        </div>
    </div>
</div>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font("DejaVu Sans", "7");
        $footerText = "Página " . $PAGE_NUM . " de " . $PAGE_COUNT;
        $pdf->page_text(520, 20, $footerText, $font, 7, array(0,0,0));
    }
</script>

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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$nome_arquivo = 'calendario_provas_' . $professor['professor_nome'] . '_' . date('Ymd_His') . '.pdf';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

$dompdf->stream($nome_arquivo, ['Attachment' => true]);
exit;
?>