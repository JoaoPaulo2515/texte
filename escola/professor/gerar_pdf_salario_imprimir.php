<?php
// escola/professor/gerar_pdf_salario.php - Gerar PDF do Recibo de Vencimento

// Ativar exibição de erros para debug (remover em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR DOMPDF - Verificar caminhos
// ============================================
$vendor_path = __DIR__ . '/../../vendor/autoload.php';
$alt_vendor_path = __DIR__ . '/../vendor/autoload.php';
$alt2_vendor_path = __DIR__ . '/vendor/autoload.php';

if (file_exists($vendor_path)) {
    require_once $vendor_path;
} elseif (file_exists($alt_vendor_path)) {
    require_once $alt_vendor_path;
} elseif (file_exists($alt2_vendor_path)) {
    require_once $alt2_vendor_path;
} else {
    die('ERRO: Biblioteca DOMPDF não encontrada. Execute: composer require dompdf/dompdf');
}

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// PARÂMETROS
// ============================================
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.*, u.email, u.nome 
    FROM funcionarios p
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ID DO FUNCIONÁRIO
// ============================================
$sql_funcionario_id = "
    SELECT f.id 
    FROM funcionarios f
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
    WHERE p.id = :professor_id
";
$stmt_func_id = $conn->prepare($sql_funcionario_id);
$stmt_func_id->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_func_id->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['id'] ?? $professor_id;

// ============================================
// BUSCAR INFORMAÇÕES SALARIAIS
// ============================================
$sql_folha = "
    SELECT 
        fpf.*,
        COALESCE(fpf.salario_base, 0) as salario_base,
        COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
        COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
        COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
        COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
        COALESCE(fpf.gratificacao, 0) as gratificacao,
        COALESCE(fpf.seguro_saude, 0) as seguro_saude,
        COALESCE(fpf.faltas_valor, 0) as faltas_valor,
        COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
        COALESCE(fpf.desconto_irps, 0) as desconto_irps,
        COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
        COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
        COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social,
        COALESCE(fpf.outros_descontos, 0) as outros_descontos,
        COALESCE(fpf.total_descontos, 0) as total_descontos,
        COALESCE(fpf.salario_liquido, 0) as salario_liquido,
        fpf.mes_competencia,
        fpf.ano_competencia,
        fpf.data_processamento,
        fpf.status,
        fpf.observacoes,
        u.nome as processado_por_nome
    FROM folha_processamento_funcionarios fpf
    LEFT JOIN usuarios u ON u.id = fpf.processado_por
    WHERE fpf.funcionario_id = :funcionario_id
    AND fpf.mes_competencia = :mes_competencia
    AND fpf.ano_competencia = :ano_competencia
    ORDER BY fpf.id DESC
    LIMIT 1
";

$stmt_folha = $conn->prepare($sql_folha);
$stmt_folha->execute([
    ':funcionario_id' => $funcionario_id,
    ':mes_competencia' => $mes_filtro,
    ':ano_competencia' => $ano_filtro
]);
$salario = $stmt_folha->fetch(PDO::FETCH_ASSOC);

// Se não houver registro para o período selecionado, buscar o último processado
if (!$salario) {
    $sql_ultimo = "
        SELECT 
            fpf.*,
            COALESCE(fpf.salario_base, 0) as salario_base,
            COALESCE(fpf.subsidio_transporte, 0) as subsidio_transporte,
            COALESCE(fpf.subsidio_alimentacao, 0) as subsidio_alimentacao,
            COALESCE(fpf.outros_vencimentos, 0) as outros_vencimentos,
            COALESCE(fpf.total_vencimentos, 0) as total_vencimentos,
            COALESCE(fpf.gratificacao, 0) as gratificacao,
            COALESCE(fpf.seguro_saude, 0) as seguro_saude,
            COALESCE(fpf.faltas_valor, 0) as faltas_valor,
            COALESCE(fpf.horas_extras_valor, 0) as horas_extras_valor,
            COALESCE(fpf.desconto_irps, 0) as desconto_irps,
            COALESCE(fpf.desconto_atrasos, 0) as desconto_atrasos,
            COALESCE(fpf.desconto_emprestimo, 0) as desconto_emprestimo,
            COALESCE(fpf.desconto_seguranca_social, 0) as desconto_seguranca_social,
            COALESCE(fpf.outros_descontos, 0) as outros_descontos,
            COALESCE(fpf.total_descontos, 0) as total_descontos,
            COALESCE(fpf.salario_liquido, 0) as salario_liquido,
            fpf.mes_competencia,
            fpf.ano_competencia,
            fpf.data_processamento,
            fpf.status,
            fpf.observacoes,
            u.nome as processado_por_nome
        FROM folha_processamento_funcionarios fpf
        LEFT JOIN usuarios u ON u.id = fpf.processado_por
        WHERE fpf.funcionario_id = :funcionario_id
        ORDER BY fpf.ano_competencia DESC, fpf.mes_competencia DESC
        LIMIT 1
    ";
    $stmt_ultimo = $conn->prepare($sql_ultimo);
    $stmt_ultimo->execute([':funcionario_id' => $funcionario_id]);
    $salario = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);
}

// Se ainda não houver, criar array padrão
if (!$salario) {
    $salario = [
        'salario_base' => 0,
        'subsidio_transporte' => 0,
        'subsidio_alimentacao' => 0,
        'outros_vencimentos' => 0,
        'total_vencimentos' => 0,
        'gratificacao' => 0,
        'seguro_saude' => 0,
        'faltas_valor' => 0,
        'horas_extras_valor' => 0,
        'desconto_irps' => 0,
        'desconto_atrasos' => 0,
        'desconto_emprestimo' => 0,
        'desconto_seguranca_social' => 0,
        'outros_descontos' => 0,
        'total_descontos' => 0,
        'salario_liquido' => 0,
        'status' => 'pendente',
        'mes_competencia' => $mes_filtro,
        'ano_competencia' => $ano_filtro,
        'data_processamento' => date('Y-m-d H:i:s'),
        'observacoes' => 'Aguardando processamento da folha de pagamento',
        'processado_por_nome' => 'Sistema'
    ];
}

// Calcular totais se necessário
if ($salario['total_vencimentos'] == 0) {
    $salario['total_vencimentos'] = $salario['salario_base'] + $salario['subsidio_transporte'] + 
                                     $salario['subsidio_alimentacao'] + $salario['outros_vencimentos'] + 
                                     $salario['gratificacao'] + $salario['seguro_saude'] + 
                                     $salario['horas_extras_valor'];
}
if ($salario['total_descontos'] == 0) {
    $salario['total_descontos'] = $salario['faltas_valor'] + $salario['desconto_irps'] + 
                                   $salario['desconto_atrasos'] + $salario['desconto_emprestimo'] + 
                                   $salario['desconto_seguranca_social'] + $salario['outros_descontos'];
}
if ($salario['salario_liquido'] == 0) {
    $salario['salario_liquido'] = $salario['total_vencimentos'] - $salario['total_descontos'];
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoedaPDF($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusTextoPDF($status) {
    switch ($status) {
        case 'pago': return 'PAGO';
        case 'aprovado': return 'APROVADO';
        case 'processado': return 'PROCESSADO';
        case 'pendente': return 'PENDENTE';
        case 'cancelado': return 'CANCELADO';
        default: return 'INDEFINIDO';
    }
}

function getStatusCorPDF($status) {
    switch ($status) {
        case 'pago': return '#28a745';
        case 'aprovado': return '#17a2b8';
        case 'processado': return '#006B3E';
        case 'pendente': return '#ffc107';
        case 'cancelado': return '#dc3545';
        default: return '#6c757d';
    }
}

function getMesExtensoPDF($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[(int)$mes];
}

function getNumeroExtensoPDF($numero) {
    $numero = (int)$numero;
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $dezenas = ['', 'dez', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cem', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    
    if ($numero == 0) return 'zero';
    if ($numero < 10) return $unidades[$numero];
    if ($numero < 100) {
        $d = floor($numero / 10);
        $u = $numero % 10;
        return $dezenas[$d] . ($u ? ' e ' . $unidades[$u] : '');
    }
    if ($numero < 1000) {
        $c = floor($numero / 100);
        $r = $numero % 100;
        return $centenas[$c] . ($r ? ' e ' . getNumeroExtensoPDF($r) : '');
    }
    return number_format($numero, 0, ',', '.');
}

function valorPorExtensoPDF($valor) {
    $inteiro = (int)$valor;
    $centavos = round(($valor - $inteiro) * 100);
    $extenso = getNumeroExtensoPDF($inteiro) . ' kwanzas';
    if ($centavos > 0) {
        $extenso .= ' e ' . getNumeroExtensoPDF($centavos) . ' cêntimos';
    }
    return ucfirst($extenso);
}

$mes_atual = getMesExtensoPDF($salario['mes_competencia']);
$ano_atual = $salario['ano_competencia'];
$percentual_desconto = $salario['total_vencimentos'] > 0 ? 
                       round(($salario['total_descontos'] / $salario['total_vencimentos']) * 100, 1) : 0;
$percentual_liquido = $salario['total_vencimentos'] > 0 ? 
                      round(($salario['salario_liquido'] / $salario['total_vencimentos']) * 100, 1) : 0;

// ============================================
// GERAR HTML PARA PDF (VERSÃO SIMPLIFICADA E COMPATÍVEL)
// ============================================
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Recibo de Vencimento - ' . $mes_atual . '/' . $ano_atual . '</title>
    <style>
        @page {
            margin: 1cm;
            size: A4 portrait;
        }
        
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        
        .escola-nome {
            font-size: 14pt;
            font-weight: bold;
            color: #006B3E;
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
            margin: 10px 0;
            color: #1A2A6C;
        }
        
        .periodo {
            text-align: center;
            font-size: 10pt;
            margin-bottom: 15px;
        }
        
        .info-section {
            margin-bottom: 15px;
            padding: 8px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
        }
        
        .info-row {
            display: inline-block;
            margin: 0 10px;
            font-size: 9pt;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .values-grid {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        
        .values-grid td {
            padding: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        
        .value-card {
            background: #f8f9fa;
        }
        
        .value-card.total {
            background: #006B3E;
            color: white;
        }
        
        .value-amount {
            font-size: 14pt;
            font-weight: bold;
        }
        
        .positive {
            color: #28a745;
        }
        
        .negative {
            color: #dc3545;
        }
        
        .table-salary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .table-salary th {
            background: #006B3E;
            color: white;
            padding: 6px;
            text-align: left;
        }
        
        .table-salary td {
            border: 0.5px solid #ddd;
            padding: 5px;
        }
        
        .table-salary td:last-child {
            text-align: right;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .valor-extenso {
            background: #e8f5e9;
            padding: 8px;
            text-align: center;
            margin: 15px 0;
        }
        
        .observacoes {
            background: #fff3cd;
            padding: 8px;
            margin: 15px 0;
            border-left: 3px solid #ffc107;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 8pt;
            font-weight: bold;
            color: white;
            background: ' . getStatusCorPDF($salario['status']) . ';
        }
        
        .assinaturas {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }
        
        .assinaturas td {
            text-align: center;
            padding-top: 30px;
        }
        
        .linha-assinatura {
            width: 150px;
            border-top: 0.5px solid #000;
            margin: 0 auto;
        }
        
        .assinatura-texto {
            font-size: 8pt;
            margin-top: 5px;
        }
        
        .footer {
            text-align: center;
            font-size: 7pt;
            color: #999;
            border-top: 0.5px solid #ddd;
            padding-top: 5px;
            margin-top: 20px;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-danger { color: #dc3545; }
    </style>
</head>
<body>

<div class="header">
    <div class="escola-nome">' . strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')) . '</div>
    <div class="escola-info">' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . '</div>
</div>

<div class="titulo">RECIBO DE VENCIMENTO</div>

<div class="periodo">
    Competência: ' . $mes_atual . '/' . $ano_atual . '
    &nbsp;&nbsp;|&nbsp;&nbsp;
    <span class="status-badge">' . getStatusTextoPDF($salario['status']) . '</span>
</div>

<div class="info-section">
    <div class="info-row"><span class="info-label">Funcionário:</span> ' . htmlspecialchars($professor_dados['nome'] ?? '') . '</div>
    <div class="info-row"><span class="info-label">Nº Funcionário:</span> ' . htmlspecialchars($professor_dados['numero_processo'] ?? $professor_id) . '</div>
    <div class="info-row"><span class="info-label">Cargo:</span> Professor</div>
</div>

<!-- Cards de Valores -->
<table class="values-grid">
    <tr>
        <td class="value-card"><div><strong>Total Vencimentos</strong></div><div class="value-amount positive">KZ ' . formatarMoedaPDF($salario['total_vencimentos']) . '</div></td>
        <td class="value-card"><div><strong>Total Descontos</strong></div><div class="value-amount negative">KZ ' . formatarMoedaPDF($salario['total_descontos']) . '</div></td>
        <td class="value-card total"><div><strong>Salário Líquido</strong></div><div class="value-amount">KZ ' . formatarMoedaPDF($salario['salario_liquido']) . '</div></td>
    </tr>
</table>

<!-- Tabela Vencimentos -->
<h4>VENCIMENTOS</h4>
<table class="table-salary">
    <thead><tr><th>Descrição</th><th>Valor (KZ)</th></tr></thead>
    <tbody>
        <tr><td>Salário Base</td><td>' . formatarMoedaPDF($salario['salario_base']) . '</td></tr>
        <tr><td>Subsídio de Transporte</td><td>' . formatarMoedaPDF($salario['subsidio_transporte']) . '</td></tr>
        <tr><td>Subsídio de Alimentação</td><td>' . formatarMoedaPDF($salario['subsidio_alimentacao']) . '</td></tr>
        <tr><td>Gratificação</td><td>' . formatarMoedaPDF($salario['gratificacao']) . '</td></tr>
        <tr><td>Seguro Saúde</td><td>' . formatarMoedaPDF($salario['seguro_saude']) . '</td></tr>
        <tr><td>Horas Extras</td><td>' . formatarMoedaPDF($salario['horas_extras_valor']) . '</td></tr>
        <tr class="total-row"><td><strong>TOTAL VENCIMENTOS</strong></td><td><strong>' . formatarMoedaPDF($salario['total_vencimentos']) . '</strong></td></tr>
    </tbody>
</table>

<!-- Tabela Descontos -->
<h4>DESCONTOS</h4>
<table class="table-salary">
    <thead><tr><th>Descrição</th><th>Valor (KZ)</th></tr></thead>
    <tbody>
        <tr><td>Faltas</td><td class="text-danger">' . formatarMoedaPDF($salario['faltas_valor']) . '</td></tr>
        <tr><td>IRPS (Imposto)</td><td class="text-danger">' . formatarMoedaPDF($salario['desconto_irps']) . '</td></tr>
        <tr><td>Segurança Social</td><td class="text-danger">' . formatarMoedaPDF($salario['desconto_seguranca_social']) . '</td></tr>
        <tr><td>Atrasos</td><td class="text-danger">' . formatarMoedaPDF($salario['desconto_atrasos']) . '</td></tr>
        <tr><td>Empréstimo</td><td class="text-danger">' . formatarMoedaPDF($salario['desconto_emprestimo']) . '</td></tr>
        <tr class="total-row"><td><strong>TOTAL DESCONTOS</strong></td><td class="text-danger"><strong>' . formatarMoedaPDF($salario['total_descontos']) . '</strong></td></tr>
    </tbody>
</table>

<!-- Valor por Extenso -->
<div class="valor-extenso">
    <strong>Valor por extenso:</strong> ' . valorPorExtensoPDF($salario['salario_liquido']) . '
</div>

' . (!empty($salario['observacoes']) ? '
<div class="observacoes">
    <strong>Observações:</strong> ' . htmlspecialchars($salario['observacoes']) . '
</div>' : '') . '

<div class="info-section">
    <div class="info-row"><span class="info-label">Data Processamento:</span> ' . date('d/m/Y H:i:s', strtotime($salario['data_processamento'])) . '</div>
    <div class="info-row"><span class="info-label">Processado por:</span> ' . htmlspecialchars($salario['processado_por_nome'] ?? 'Sistema') . '</div>
</div>

<!-- Assinaturas -->
<table class="assinaturas">
    <tr>
        <td><div class="linha-assinatura"></div><div class="assinatura-texto">Funcionário</div></td>
        <td><div class="linha-assinatura"></div><div class="assinatura-texto">Direção Pedagógica</div></td>
        <td><div class="linha-assinatura"></div><div class="assinatura-texto">Administração</div></td>
    </tr>
</table>

<div class="footer">
    SIGE Angola - Sistema Integrado de Gestão Escolar | Emitido em ' . date('d/m/Y H:i:s') . '
</div>

</body>
</html>
';

// ============================================
// CONFIGURAR E GERAR PDF (VERSÃO CORRIGIDA)
// ============================================
try {
    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isFontSubsettingEnabled', true);
    
    // Não usar chroot no Windows para evitar problemas
    // $options->set('chroot', realpath(__DIR__ . '/../../'));
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $nome_arquivo = 'recibo_vencimento_' . preg_replace('/[^a-zA-Z0-9]/', '_', $professor_dados['nome'] ?? 'professor') . '_' . $mes_atual . '_' . $ano_atual . '.pdf';
    
    $dompdf->stream($nome_arquivo, ['Attachment' => false]);
    exit;
    
} catch (Exception $e) {
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
?>