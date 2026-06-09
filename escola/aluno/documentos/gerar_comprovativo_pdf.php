<?php
// aluno/documentos/gerar_comprovativo_pdf.php - Gerar Comprovativo PDF

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Receber dados da requisição
$pagamento_id = isset($_POST['pagamento_id']) ? (int)$_POST['pagamento_id'] : 0;
$origem = isset($_POST['origem']) ? $_POST['origem'] : 'pagamentos';

if ($pagamento_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID do pagamento não informado']);
    exit;
}

// ============================================
// BUSCAR DADOS DO PAGAMENTO
// ============================================

$dados = null;

if ($origem == 'pagamentos') {
    // Buscar da tabela pagamentos
    $sql = "SELECT 
                p.*,
                e.nome as aluno_nome,
                e.matricula,
                e.endereco as aluno_endereco,
                tur.nome as turma_nome,
                tur.ano as turma_ano,
                es.nome as escola_nome,
                es.endereco as escola_endereco,
                es.telefone as escola_telefone,
                es.email as escola_email,
                es.logo as escola_logo
            FROM pagamentos p
            JOIN estudantes e ON e.id = p.assinatura_id
            LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
            LEFT JOIN turmas tur ON tur.id = m.turma_id
            JOIN escolas es ON es.id = p.escola_id
            WHERE p.id = :id AND p.assinatura_id = :aluno_id AND p.escola_id = :escola_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $pagamento_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    
} else {
    // Buscar da tabela outros_pagamentos
    $sql = "SELECT 
                op.*,
                e.nome as aluno_nome,
                e.matricula,
                e.endereco as aluno_endereco,
                tur.nome as turma_nome,
                tur.ano as turma_ano,
                es.nome as escola_nome,
                es.endereco as escola_endereco,
                es.telefone as escola_telefone,
                es.email as escola_email,
                es.logo as escola_logo,
                tp.nome as tipo_pagamento_nome
            FROM outros_pagamentos op
            JOIN estudantes e ON e.id = op.aluno_id
            LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
            LEFT JOIN turmas tur ON tur.id = m.turma_id
            JOIN escolas es ON es.id = op.escola_id
            LEFT JOIN tipos_pagamento tp ON tp.id = op.tipo_pagamento_id
            WHERE op.id = :id AND op.aluno_id = :aluno_id AND op.escola_id = :escola_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $pagamento_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$dados) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Pagamento não encontrado']);
    exit;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHora($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i:s', strtotime($data));
}

function formatarNumero($numero) {
    return str_pad($numero, 6, '0', STR_PAD_LEFT);
}

function converterParaExtenso($valor) {
    $valor = floatval($valor);
    if ($valor <= 0) return 'Zero Kz';
    
    $inteiro = floor($valor);
    $centavos = round(($valor - $inteiro) * 100);
    
    $extenso = new NumberFormatter('pt_BR', NumberFormatter::SPELLOUT);
    $texto = ucfirst($extenso->format($inteiro)) . ' Kz';
    
    if ($centavos > 0) {
        $texto .= ' e ' . $extenso->format($centavos) . ' Centavos';
    }
    
    return $texto;
}

// ============================================
// GERAR HTML DO COMPROVATIVO
// ============================================

$logo_html = '';
if (!empty($dados['escola_logo']) && file_exists(__DIR__ . '/../../../' . $dados['escola_logo'])) {
    $logo_path = __DIR__ . '/../../../' . $dados['escola_logo'];
    $logo_data = base64_encode(file_get_contents($logo_path));
    $logo_html = '<img src="data:image/png;base64,' . $logo_data . '" style="height: 80px;">';
}

$tipo_pagamento = '';
if ($origem == 'pagamentos') {
    $tipos = [
        'mensalidade' => 'Mensalidade Escolar',
        'matricula' => 'Taxa de Matrícula',
        'certificado' => 'Certificado',
        'material' => 'Material Didático',
        'taxa' => 'Taxa Escolar',
        'uniforme' => 'Uniforme'
    ];
    $tipo_pagamento = $tipos[$dados['tipo_pagamento']] ?? 'Pagamento';
} else {
    $tipo_pagamento = $dados['tipo_pagamento_nome'] ?? 'Pagamento Diverso';
}

$metodo_pagamento = '';
if ($origem == 'pagamentos') {
    $metodos = [
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferência Bancária',
        'deposito' => 'Depósito Bancário',
        'cheque' => 'Cheque',
        'multicaixa' => 'Multicaixa',
        'pix' => 'PIX',
        'cartao' => 'Cartão'
    ];
    $metodo_pagamento = $metodos[$dados['metodo_pagamento']] ?? 'Outro';
}

$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Comprovativo de Pagamento</title>
    <style>
        @page {
            margin: 2cm;
            size: A4;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
        }
        .logo {
            margin-bottom: 10px;
        }
        .titulo {
            font-size: 24px;
            font-weight: bold;
            color: #006B3E;
            margin: 10px 0;
        }
        .subtitulo {
            font-size: 14px;
            color: #666;
        }
        .info-aluno {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            border-left: 4px solid #006B3E;
        }
        .info-aluno h4 {
            margin: 0 0 10px 0;
            color: #006B3E;
            font-size: 16px;
        }
        .detalhes {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .detalhes td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .detalhes td:first-child {
            font-weight: bold;
            width: 40%;
            background: #f9f9f9;
        }
        .total {
            background: #e8f5e9;
            font-size: 16px;
            font-weight: bold;
        }
        .total td {
            border-top: 2px solid #006B3E;
            border-bottom: none;
        }
        .assinaturas {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .assinatura {
            display: inline-block;
            width: 45%;
            text-align: center;
        }
        .linha-assinatura {
            border-top: 1px solid #000;
            width: 80%;
            margin: 30px auto 5px auto;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .codigo-verificacao {
            background: #f0f0f0;
            padding: 10px;
            text-align: center;
            font-family: monospace;
            font-size: 14px;
            margin: 15px 0;
            border-radius: 5px;
        }
        .status-pago {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .valor-destaque {
            font-size: 22px;
            font-weight: bold;
            color: #006B3E;
        }
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Cabeçalho -->
        <div class="header">
            <div class="logo">' . $logo_html . '</div>
            <div class="titulo">' . htmlspecialchars($dados['escola_nome']) . '</div>
            <div class="subtitulo">COMPROVATIVO DE PAGAMENTO</div>
        </div>
        
        <!-- Status -->
        <div style="text-align: center; margin-bottom: 20px;">
            <span class="status-pago"><i class="fas fa-check-circle"></i> PAGAMENTO CONFIRMADO</span>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno">
            <h4>DADOS DO ALUNO</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%;"><strong>Nome:</strong> ' . htmlspecialchars($dados['aluno_nome']) . '</td>
                    <td style="width: 50%;"><strong>Matrícula:</strong> ' . htmlspecialchars($dados['matricula']) . '</td>
                </tr>
                <tr>
                    <td><strong>Turma:</strong> ' . ($dados['turma_ano'] ? $dados['turma_ano'] . 'ª ' . htmlspecialchars($dados['turma_nome']) : 'Não atribuída') . '</td>
                    <td><strong>Escola:</strong> ' . htmlspecialchars($dados['escola_nome']) . '</td>
                </tr>
            </table>
        </div>
        
        <!-- Detalhes do Pagamento -->
        <h4 style="color: #006B3E; margin-bottom: 10px;">DETALHES DO PAGAMENTO</h4>
        <table class="detalhes">
            <tr>
                <td>Nº do Comprovativo:</td>
                <td><strong>' . formatarNumero($dados['id']) . '/' . date('Y') . '</strong></td>
            </tr>
            <tr>
                <td>Data do Pagamento:</td>
                <td>' . formatarDataHora($dados['data_pagamento']) . '</td>
            </tr>
            <tr>
                <td>Tipo de Pagamento:</td>
                <td>' . $tipo_pagamento . '</td>
            </tr>
            <tr>
                <td>Referente:</td>
                <td>' . htmlspecialchars($dados['referente'] ?? $dados['observacoes'] ?? 'Pagamento realizado') . '</td>
            </tr>';

if ($origem == 'pagamentos') {
    $html .= '
            <tr>
                <td>Forma de Pagamento:</td>
                <td>' . $metodo_pagamento . '</td>
            </tr>';
}

if (!empty($dados['numero_fatura'])) {
    $html .= '
            <tr>
                <td>Nº da Fatura:</td>
                <td>' . htmlspecialchars($dados['numero_fatura']) . '</td>
            </tr>';
}

if (!empty($dados['numero_referencia'])) {
    $html .= '
            <tr>
                <td>Nº de Referência:</td>
                <td>' . htmlspecialchars($dados['numero_referencia']) . '</td>
            </tr>';
}

if (!empty($dados['codigo_transacao'])) {
    $html .= '
            <tr>
                <td>Código da Transação:</td>
                <td>' . htmlspecialchars($dados['codigo_transacao']) . '</td>
            </tr>';
}

if (!empty($dados['comprovativo_numero'])) {
    $html .= '
            <tr>
                <td>Nº do Comprovante:</td>
                <td>' . htmlspecialchars($dados['comprovativo_numero']) . '</td>
            </tr>';
}

if ($origem == 'outros_pagamentos') {
    if (!empty($dados['mes_referencia']) && !empty($dados['ano_referencia'])) {
        $html .= '
            <tr>
                <td>Período de Referência:</td>
                <td>' . $dados['mes_referencia'] . '/' . $dados['ano_referencia'] . '</td>
            </tr>';
    }
    if ($dados['desconto'] > 0) {
        $html .= '
            <tr>
                <td>Desconto:</td>
                <td>' . formatarMoeda($dados['desconto']) . '</td>
            </tr>';
    }
    if ($dados['multa'] > 0) {
        $html .= '
            <tr>
                <td>Multa:</td>
                <td>' . formatarMoeda($dados['multa']) . '</td>
            </tr>';
    }
    if ($dados['juros'] > 0) {
        $html .= '
            <tr>
                <td>Juros:</td>
                <td>' . formatarMoeda($dados['juros']) . '</td>
            </tr>';
    }
}

if ($origem == 'pagamentos' && !empty($dados['troco']) && $dados['troco'] > 0) {
    $html .= '
            <tr>
                <td>Troco:</td>
                <td>' . formatarMoeda($dados['troco']) . '</td>
            </tr>';
}

if (!empty($dados['quem_recebeu'])) {
    $html .= '
            <tr>
                <td>Recebido por:</td>
                <td>' . htmlspecialchars($dados['quem_recebeu']) . '</td>
            </tr>';
}

if (!empty($dados['quem_pagou'])) {
    $html .= '
            <tr>
                <td>Pago por:</td>
                <td>' . htmlspecialchars($dados['quem_pagou']) . '</td>
            </tr>';
}

$html .= '
            <tr class="total">
                <td><strong>VALOR TOTAL PAGO:</strong></td>
                <td><span class="valor-destaque">' . formatarMoeda($dados['valor_pago'] ?? $dados['valor']) . '</span></td>
            </tr>
        </table>
        
        <!-- Valor por Extenso -->
        <div style="background: #f0f0f0; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
            <strong>Valor por Extenso:</strong> ' . converterParaExtenso($dados['valor_pago'] ?? $dados['valor']) . '
        </div>
        
        <!-- Código de Verificação -->
        <div class="codigo-verificacao">
            <strong>Código de Verificação:</strong> ' . md5($dados['id'] . $dados['data_pagamento'] . $dados['aluno_nome']) . '
        </div>
        
        <!-- Assinaturas -->
        <div class="assinaturas">
            <div style="display: flex; justify-content: space-between;">
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>Assinatura do Aluno/Responsável</div>
                </div>
                <div class="assinatura">
                    <div class="linha-assinatura"></div>
                    <div>Carimbo e Assinatura da Escola</div>
                </div>
            </div>
        </div>
        
        <!-- Observações -->
        <div style="margin-top: 20px; padding: 10px; background: #fff3cd; border-radius: 5px; font-size: 11px;">
            <strong>Observações:</strong><br>
            - Este comprovativo é válido como prova de pagamento.<br>
            - Guarde este documento para futuras referências.<br>
            - Em caso de dúvidas, entre em contato com a secretaria da escola.
        </div>
        
        <!-- Footer -->
        <div class="footer">
            Documento gerado eletronicamente em ' . date('d/m/Y H:i:s') . '<br>
            ' . htmlspecialchars($dados['escola_nome']) . ' - ' . htmlspecialchars($dados['escola_endereco']) . '<br>
            Telefone: ' . htmlspecialchars($dados['escola_telefone']) . ' | Email: ' . htmlspecialchars($dados['escola_email']) . '
        </div>
    </div>
</body>
</html>';

// ============================================
// GERAR PDF
// ============================================

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Salvar o PDF em arquivo
$upload_dir = __DIR__ . '/../../../uploads/comprovativos/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$nome_arquivo = 'comprovativo_' . $origem . '_' . $pagamento_id . '_' . date('Ymd_His') . '.pdf';
$caminho_arquivo = 'uploads/comprovativos/' . $nome_arquivo;
$caminho_completo = $upload_dir . $nome_arquivo;

file_put_contents($caminho_completo, $dompdf->output());

// Atualizar o caminho do comprovativo no banco de dados
if ($origem == 'pagamentos') {
    $sql_update = "UPDATE pagamentos SET comprovativo_path = :caminho WHERE id = :id";
} else {
    $sql_update = "UPDATE outros_pagamentos SET comprovativo_path = :caminho WHERE id = :id";
}

$stmt_update = $conn->prepare($sql_update);
$stmt_update->execute([
    ':caminho' => $caminho_arquivo,
    ':id' => $pagamento_id
]);

// Retornar resposta JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'arquivo' => $caminho_arquivo,
    'message' => 'Comprovativo gerado com sucesso!'
]);