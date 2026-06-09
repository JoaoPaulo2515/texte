<?php
// escola/aluno/financeiro/gerar_nota_fiscal.php - Gerar Nota Fiscal em PDF

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die('Acesso negado.');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$nota_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'normal';
$acao = isset($_GET['acao']) ? $_GET['acao'] : 'visualizar';

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escola WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
}

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, email, telefone, endereco FROM estudantes WHERE id = :aluno_id AND escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Verificar se a tabela notas_fiscais existe
$sql_check = "SHOW TABLES LIKE 'notas_fiscais'";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->execute();
$tabela_notas_existe = $stmt_check->rowCount() > 0;

if ($tabela_notas_existe && $tipo != 'virtual') {
    // Buscar da tabela notas_fiscais
    $sql_nota = "SELECT nf.*, p.referente, p.valor as pagamento_valor, p.numero_fatura, p.metodo_pagamento, p.data_pagamento
                 FROM notas_fiscais nf
                 LEFT JOIN pagamentos p ON p.id = nf.pagamento_id
                 WHERE nf.id = :nota_id AND nf.aluno_id = :aluno_id AND nf.escola_id = :escola_id";
    $stmt_nota = $conn->prepare($sql_nota);
    $stmt_nota->execute([':nota_id' => $nota_id, ':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
    $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
    
} else {
    // Buscar do pagamento (virtual)
    $sql_pagamento = "SELECT p.* FROM pagamentos p
                      WHERE p.id = :pagamento_id 
                      AND p.assinatura_id = :aluno_id 
                      AND p.escola_id = :escola_id
                      AND p.status IN ('pago', 'confirmado')";
    $stmt_pagamento = $conn->prepare($sql_pagamento);
    $stmt_pagamento->execute([':pagamento_id' => $nota_id, ':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
    $pagamento = $stmt_pagamento->fetch(PDO::FETCH_ASSOC);
    
    if ($pagamento) {
        $nota = [
            'id' => $pagamento['id'],
            'numero_nota' => $pagamento['numero_fatura'] ?? ('NF-' . str_pad($pagamento['id'], 6, '0', STR_PAD_LEFT)),
            'serie' => '001',
            'modelo' => 'NF-e',
            'chave_acesso' => 'NFE-' . md5($pagamento['id'] . $pagamento['data_pagamento']),
            'data_emissao' => $pagamento['data_pagamento'],
            'valor' => $pagamento['valor'],
            'referente' => $pagamento['referente'],
            'metodo_pagamento' => $pagamento['metodo_pagamento'],
            'numero_fatura' => $pagamento['numero_fatura'],
            'status' => $pagamento['status'],
            'virtual' => true
        ];
    }
}

if (!$nota) {
    die('Nota fiscal não encontrada.');
}

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5($nota['chave_acesso'] . date('Ymd')), 0, 16));

// Gerar HTML da nota fiscal
$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Nota Fiscal - ' . $nota['numero_nota'] . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Times New Roman", Arial, sans-serif;
            background: white;
            padding: 20px;
        }
        
        .nota-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 12px;
            color: #666;
        }
        
        .titulo-nota {
            background: #006B3E;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .info-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .info-title {
            background: #f5f5f5;
            padding: 8px 12px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
        }
        
        .info-content {
            padding: 12px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            width: 130px;
            font-weight: bold;
            color: #555;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .detalhes-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .detalhes-table th, .detalhes-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        
        .detalhes-table th {
            background: #f5f5f5;
            font-weight: bold;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-end {
            text-align: right;
        }
        
        .total {
            text-align: right;
            font-size: 1.2em;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
        
        .total strong {
            font-size: 1.3em;
            color: #006B3E;
        }
        
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 20px;
        }
        
        .assinatura {
            text-align: center;
            width: 250px;
        }
        
        .assinatura-linha {
            border-top: 1px solid #333;
            margin-top: 40px;
            padding-top: 5px;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .codigo-autenticacao {
            font-family: monospace;
            font-size: 14px;
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 5px;
            text-align: center;
            display: inline-block;
        }
        
        .qr-code {
            text-align: center;
            margin: 20px 0;
        }
        
        /* Botões */
        .btn-container {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-top: 1px solid #ddd;
            position: sticky;
            bottom: 0;
            z-index: 100;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
            text-decoration: none;
        }
        
        .btn-print {
            background: #006B3E;
            color: white;
        }
        
        .btn-print:hover {
            background: #004d2e;
        }
        
        .btn-download {
            background: #17a2b8;
            color: white;
        }
        
        .btn-download:hover {
            background: #138496;
        }
        
        .btn-close {
            background: #6c757d;
            color: white;
        }
        
        .btn-close:hover {
            background: #5a6268;
        }
        
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            .btn-container {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="nota-container">
    <div class="header">
        <h1>' . htmlspecialchars($escola['nome']) . '</h1>
        <div class="subtitle">
            ' . htmlspecialchars($escola['endereco']) . '<br>
            NIF: ' . htmlspecialchars($escola['nif']) . ' | 
            Tel: ' . htmlspecialchars($escola['telefone']) . ' |
            Email: ' . htmlspecialchars($escola['email']) . '
        </div>
    </div>
    
    <div class="titulo-nota">
        NOTA FISCAL ' . ($nota['modelo'] ?? 'NF-e') . ' - Documento Fiscal Eletrônico
    </div>
    
    <div class="info-section">
        <div class="info-title">DADOS DO EMITENTE</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Razão Social:</div>
                <div class="info-value">' . htmlspecialchars($escola['nome']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">NIF:</div>
                <div class="info-value">' . htmlspecialchars($escola['nif']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Endereço:</div>
                <div class="info-value">' . htmlspecialchars($escola['endereco']) . '</div>
            </div>
        </div>
    </div>
    
    <div class="info-section">
        <div class="info-title">DADOS DO DESTINATÁRIO</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Nome:</div>
                <div class="info-value">' . htmlspecialchars($aluno['nome']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Matrícula:</div>
                <div class="info-value">' . htmlspecialchars($aluno['matricula']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Endereço:</div>
                <div class="info-value">' . htmlspecialchars($aluno['endereco'] ?: 'Não informado') . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Telefone:</div>
                <div class="info-value">' . htmlspecialchars($aluno['telefone'] ?: 'Não informado') . '</div>
            </div>
        </div>
    </div>
    
    <div class="info-section">
        <div class="info-title">DADOS DA NOTA FISCAL</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Número da Nota:</div>
                <div class="info-value">' . $nota['numero_nota'] . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Série:</div>
                <div class="info-value">' . ($nota['serie'] ?? '001') . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Data de Emissão:</div>
                <div class="info-value">' . date('d/m/Y H:i:s', strtotime($nota['data_emissao'])) . '</div>
            </div>
            <div class="info-row">
                <div class="info-label">Natureza da Operação:</div>
                <div class="info-value">Prestação de Serviços Educacionais</div>
            </div>
        </div>
    </div>
    
    <table class="detalhes-table">
        <thead>
            <tr>
                <th>Descrição</th>
                <th width="120">Quantidade</th>
                <th width="120">Valor Unitário</th>
                <th width="150">Valor Total (KZ)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>' . htmlspecialchars($nota['referente'] ?? 'Serviços Educacionais') . '</td>
                <td class="text-center">1</td>
                <td class="text-end">' . number_format($nota['valor'], 2, ',', '.') . ' KZ</td>
                <td class="text-end"><strong>' . number_format($nota['valor'], 2, ',', '.') . ' KZ</strong></td>
            </tr>
        </tbody>
    </table>
    
    <div class="total">
        <span>VALOR TOTAL DA NOTA: </span>
        <strong>' . number_format($nota['valor'], 2, ',', '.') . ' KZ</strong>
    </div>
    
    <div class="info-section">
        <div class="info-title">INFORMAÇÕES ADICIONAIS</div>
        <div class="info-content">
            <div class="info-row">
                <div class="info-label">Forma de Pagamento:</div>
                <div class="info-value">' . (isset($nota['metodo_pagamento']) ? ucfirst(str_replace('_', ' ', $nota['metodo_pagamento'])) : 'À Vista') . '</div>
            </div>';
            
            if (isset($nota['numero_fatura']) && $nota['numero_fatura']) {
                $html .= '
            <div class="info-row">
                <div class="info-label">Fatura Referência:</div>
                <div class="info-value">' . $nota['numero_fatura'] . '</div>
            </div>';
            }
            
            $html .= '
            <div class="info-row">
                <div class="info-label">Tributos:</div>
                <div class="info-value">Sem incidência de impostos (Educação)</div>
            </div>
        </div>
    </div>
    
    <div class="qr-code">
        <div class="codigo-autenticacao">
            <strong>Código de Autenticação:</strong> ' . $codigo_autenticacao . '
        </div>
        <small>Consulte a autenticidade no portal da AT</small>
    </div>
    
    <div class="assinaturas">
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>Aluno / Responsável</div>
            <div>Data: ___/___/______</div>
        </div>
        <div class="assinatura">
            <div class="assinatura-linha"></div>
            <div>' . htmlspecialchars($escola['nome']) . '</div>
            <div>Data: ' . date('d/m/Y') . '</div>
        </div>
    </div>
    
    <div class="footer">
        <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
        <p>Data e hora da emissão: ' . date('d/m/Y H:i:s') . '</p>
        <p>Chave de Acesso: ' . $nota['chave_acesso'] . '</p>
        <p>Este documento é uma representação eletrônica da Nota Fiscal, válida em todo território nacional.</p>
    </div>
</div>

<!-- Botões de ação -->
<div class="btn-container">
    <button class="btn btn-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir
    </button>
    <button class="btn btn-download" onclick="baixarPDF();">
        <i class="fas fa-download"></i> Baixar PDF
    </button>
    <button class="btn btn-close" onclick="window.close();">
        <i class="fas fa-times"></i> Fechar
    </button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></script>
<script>
    function baixarPDF() {
        window.location.href = "gerar_nota_fiscal.php?id=' . $nota_id . '&tipo=' . $tipo . '&acao=baixar";
    }
</script>
</body>
</html>';

// Verificar se é para baixar
if ($acao == 'baixar') {
    // Tentar usar DOMPDF se disponível
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        use Dompdf\Dompdf;
        use Dompdf\Options;
        
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream("nota_fiscal_{$nota['numero_nota']}.pdf", array("Attachment" => true));
        exit;
    } else {
        // Fallback: forçar impressão como PDF
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        echo '<script>setTimeout(function() { window.print(); }, 500);</script>';
        exit;
    }
} else {
    // Visualizar em HTML
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
?>