<?php
// escola/tesouraria/faturacao/imprimir_fatura.php - Imprimir Fatura

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'proforma';

if ($id <= 0) {
    die('Fatura não encontrada');
}

// Buscar dados da fatura
if ($tipo == 'proforma') {
    $sql = "SELECT fp.*, e.nome as estudante_nome, e.matricula, e.email, e.telefone, 
                   u.nome as usuario_nome, es.nome as escola_nome, es.endereco as escola_endereco, 
                   es.telefone as escola_telefone, es.email as escola_email, es.nuit as escola_nuit,
                   es.logo as escola_logo
            FROM faturas_proforma fp
            JOIN estudantes e ON e.id = fp.estudante_id
            JOIN usuarios u ON u.id = fp.usuario_id
            JOIN escolas es ON es.id = fp.escola_id
            WHERE fp.id = :id AND fp.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fatura) {
        $sql_itens = "SELECT * FROM fatura_proforma_itens WHERE fatura_id = :fatura_id";
        $stmt_itens = $conn->prepare($sql_itens);
        $stmt_itens->execute([':fatura_id' => $id]);
        $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
        $titulo = 'FATURA PRÓ-FORMA';
    }
} else {
    $sql = "SELECT f.*, e.nome as estudante_nome, e.matricula, e.email, e.telefone,
                   u.nome as usuario_nome, es.nome as escola_nome, es.endereco as escola_endereco, 
                   es.telefone as escola_telefone, es.email as escola_email, es.nuit as escola_nuit,
                   es.logo as escola_logo,
                   fp.numero_fatura as fatura_proforma_numero
            FROM facturas f
            JOIN estudantes e ON e.id = f.estudante_id
            JOIN usuarios u ON u.id = f.usuario_id
            JOIN escolas es ON es.id = f.escola_id
            LEFT JOIN faturas_proforma fp ON fp.id = f.fatura_proforma_id
            WHERE f.id = :id AND f.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fatura) {
        $sql_itens = "SELECT * FROM factura_itens WHERE factura_id = :factura_id";
        $stmt_itens = $conn->prepare($sql_itens);
        $stmt_itens->execute([':factura_id' => $id]);
        $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
        $titulo = 'FACTURA';
    }
}

if (!$fatura) {
    die('Fatura não encontrada');
}

// Funções auxiliares
function formatarMoedaFatura($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getMesNomeFatura($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '';
}

function getDataExtenso($data) {
    if (empty($data)) return '';
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = getMesNomeFatura((int)date('m', $timestamp));
    $ano = date('Y', $timestamp);
    return "$dia de $mes de $ano";
}

function getStatusFaturaTexto($status) {
    switch ($status) {
        case 'pendente': return 'Pendente';
        case 'aprovado': return 'Aprovado';
        case 'rejeitado': return 'Rejeitado';
        case 'convertida': return 'Convertida em Factura';
        case 'ativa': return 'Ativa';
        case 'cancelada': return 'Cancelada';
        default: return ucfirst($status);
    }
}

function getStatusFaturaCor($status) {
    switch ($status) {
        case 'pendente': return '#ffc107';
        case 'aprovado': return '#28a745';
        case 'rejeitado': return '#dc3545';
        case 'convertida': return '#17a2b8';
        case 'ativa': return '#28a745';
        case 'cancelada': return '#dc3545';
        default: return '#6c757d';
    }
}

function converterNumeroPorExtenso($valor) {
    $valor = intval($valor);
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $dez_vinte = ['dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $dezenas = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cem', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    
    if ($valor == 0) return 'zero';
    if ($valor < 10) return $unidades[$valor];
    if ($valor < 20) return $dez_vinte[$valor - 10];
    if ($valor < 100) {
        $d = intdiv($valor, 10);
        $u = $valor % 10;
        return $dezenas[$d] . ($u ? ' e ' . $unidades[$u] : '');
    }
    if ($valor < 1000) {
        $c = intdiv($valor, 100);
        $resto = $valor % 100;
        if ($c == 1 && $resto > 0) return 'cento' . ($resto ? ' e ' . converterNumeroPorExtenso($resto) : '');
        return $centenas[$c] . ($resto ? ' e ' . converterNumeroPorExtenso($resto) : '');
    }
    return number_format($valor, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?> <?php echo $fatura['numero_fatura']; ?> | SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: white;
            padding: 20px;
        }
        
        .fatura-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
        }
        
        .fatura-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .logo {
            max-width: 100px;
            max-height: 100px;
            margin-bottom: 10px;
        }
        
        .titulo-fatura {
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 10px 0;
        }
        
        .numero-fatura {
            font-size: 14px;
            margin-top: 5px;
        }
        
        .info-section {
            margin-bottom: 25px;
        }
        
        .info-title {
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 3px;
            border-bottom: 1px solid #333;
            font-size: 14px;
        }
        
        .info-row {
            margin-bottom: 5px;
            font-size: 12px;
            display: flex;
        }
        
        .info-label {
            width: 100px;
            font-weight: bold;
        }
        
        .info-value {
            flex: 1;
        }
        
        .table-fatura {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 12px;
        }
        
        .table-fatura th {
            background: #f0f0f0;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        
        .table-fatura td {
            padding: 6px 8px;
            border: 1px solid #ddd;
        }
        
        .table-fatura .text-end {
            text-align: right;
        }
        
        .table-fatura .text-center {
            text-align: center;
        }
        
        .totais {
            margin-top: 20px;
            text-align: right;
            font-size: 12px;
        }
        
        .totais table {
            width: 300px;
            margin-left: auto;
            border-collapse: collapse;
        }
        
        .totais td {
            padding: 4px 8px;
        }
        
        .total-final {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #333;
            margin-top: 5px;
            padding-top: 5px;
        }
        
        .valor-extenso {
            margin: 20px 0;
            padding: 10px;
            background: #f9f9f9;
            font-size: 11px;
            font-style: italic;
            border-left: 3px solid #333;
        }
        
        .assinaturas {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura {
            text-align: center;
            width: 45%;
        }
        
        .linha-assinatura {
            border-top: 1px solid #333;
            width: 80%;
            margin: 40px auto 10px auto;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            .no-print {
                display: none;
            }
            .fatura-container {
                margin: 0;
                padding: 10px;
            }
            .table-fatura th {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        
        .btn-print:hover {
            background: #004d2d;
        }
    </style>
</head>
<body>
    <div class="fatura-container">
        <!-- Cabeçalho -->
        <div class="fatura-header">
            <?php if (!empty($fatura['escola_logo']) && file_exists(__DIR__ . '/../../../uploads/escolas/' . $fatura['escola_logo'])): ?>
                <img src="../../../uploads/escolas/<?php echo $fatura['escola_logo']; ?>" class="logo">
            <?php endif; ?>
            <div class="titulo-fatura"><?php echo $titulo; ?></div>
            <div class="numero-fatura">Nº: <?php echo htmlspecialchars($fatura['numero_fatura']); ?></div>
            <div class="status-badge" style="background-color: <?php echo getStatusFaturaCor($fatura['status']); ?>; margin-top: 5px;">
                <?php echo getStatusFaturaTexto($fatura['status']); ?>
            </div>
        </div>
        
        <!-- Informações da Empresa e Cliente -->
        <div style="display: flex; justify-content: space-between; margin-bottom:3px;">
            <div class="info-section" style="width: 48%;">
                <div class="info-title">EMPRESA</div>
                <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($fatura['escola_nome']); ?></div></div>
                <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo htmlspecialchars($fatura['escola_endereco'] ?: 'Não informado'); ?></div></div>
                <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo htmlspecialchars($fatura['escola_telefone'] ?: 'Não informado'); ?></div></div>
                <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?php echo htmlspecialchars($fatura['escola_email'] ?: 'Não informado'); ?></div></div>
                <div class="info-row"><div class="info-label">NIF:</div><div class="info-value"><?php echo htmlspecialchars($fatura['escola_nuit'] ?: 'Não informado'); ?></div></div>
            </div>
            <div class="info-section" style="width: 48%;">
                <div class="info-title">CLIENTE</div>
                <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($fatura['estudante_nome']); ?></div></div>
                <div class="info-row"><div class="info-label">Matrícula:</div><div class="info-value"><?php echo htmlspecialchars($fatura['matricula']); ?></div></div>
                <div class="info-row"><div class="info-label">Email:</div><div class="info-value"><?php echo htmlspecialchars($fatura['email'] ?: 'Não informado'); ?></div></div>
                <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo htmlspecialchars($fatura['telefone'] ?: 'Não informado'); ?></div></div>
            </div>
        </div>
        
        <!-- Datas -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
            <div class="info-section" style="width: 48%;">
                <div class="info-title">EMISSÃO</div>
                <div class="info-row"><div class="info-label">Data:</div><div class="info-value"><?php echo getDataExtenso($fatura['data_emissao']); ?></div></div>
            </div>
            <?php if ($tipo == 'proforma'): ?>
            <div class="info-section" style="width: 48%;">
                <div class="info-title">VALIDADE</div>
                <div class="info-row"><div class="info-label">Data:</div><div class="info-value"><?php echo getDataExtenso($fatura['data_validade']); ?></div></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Itens da Fatura -->
        <div class="info-title">DISCRIMINAÇÃO</div>
        <table class="table-fatura">
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 55%">Descrição</th>
                    <th style="width: 10%" class="text-center">Qtd</th>
                    <th style="width: 15%" class="text-end">Valor Unitário</th>
                    <th style="width: 15%" class="text-end">Total</th>
                </thead>
            </thead>
            <tbody>
                <?php $contador = 1; ?>
                <?php foreach ($itens as $item): ?>
                <tr>
                    <td class="text-center"><?php echo $contador++; ?>-</td>
                    <td><?php echo htmlspecialchars($item['descricao']); ?></td>
                    <td class="text-center"><?php echo number_format($item['quantidade'], 0, ',', '.'); ?></td>
                    <td class="text-end"><?php echo formatarMoedaFatura($item['valor_unitario']); ?></td>
                    <td class="text-end"><?php echo formatarMoedaFatura($item['total']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totais -->
        <div class="totais">
            <table>
                <tr><td style="text-align: right;"><strong>Subtotal:</strong></td><td style="width: 120px; text-align: right;"><?php echo formatarMoedaFatura($fatura['subtotal']); ?></td></tr>
                <tr><td style="text-align: right;"><strong>IVA (14%):</strong></td><td style="text-align: right;"><?php echo formatarMoedaFatura($fatura['iva']); ?></td></tr>
                <?php if ($fatura['desconto'] > 0): ?>
                <tr><td style="text-align: right;"><strong>Desconto:</strong></td><td style="text-align: right;">- <?php echo formatarMoedaFatura($fatura['desconto']); ?></td></tr>
                <?php endif; ?>
                <tr class="total-final"><td style="text-align: right;"><strong>TOTAL:</strong></td><td style="text-align: right;"><strong><?php echo formatarMoedaFatura($fatura['total']); ?></strong></td></tr>
            </table>
        </div>
        
        <!-- Valor por Extenso -->
        <div class="valor-extenso">
            <strong>Valor por Extenso:</strong> <?php echo ucfirst(converterNumeroPorExtenso(floor($fatura['total']))) . ' mil e ' . str_pad(round(($fatura['total'] - floor($fatura['total'])) * 100), 2, '0', STR_PAD_LEFT) . '/100 Kz'; ?>
        </div>
        
        <?php if ($fatura['observacoes']): ?>
        <div class="info-section">
            <div class="info-title">OBSERVAÇÕES</div>
            <div class="info-row"><div class="info-value"><?php echo nl2br(htmlspecialchars($fatura['observacoes'])); ?></div></div>
        </div>
        <?php endif; ?>
        
        <?php if ($tipo == 'proforma' && $fatura['fatura_proforma_numero']): ?>
        <div class="info-section">
            <div class="info-title">FATURA PRÓ-FORMA ORIGINAL</div>
            <div class="info-row"><div class="info-value">Convertida da fatura pró-forma nº <?php echo htmlspecialchars($fatura['fatura_proforma_numero']); ?></div></div>
        </div>
        <?php endif; ?>
        
        <!-- Assinaturas -->
        <div class="assinaturas">
            <div class="assinatura">
                <div class="linha-assinatura"></div>
                <div><strong><?php echo htmlspecialchars($fatura['usuario_nome']); ?></strong></div>
                <div>Assinatura do Emitente</div>
            </div>
            <div class="assinatura">
                <div class="linha-assinatura"></div>
                <div><strong><?php echo htmlspecialchars($fatura['estudante_nome']); ?></strong></div>
                <div>Assinatura do Cliente</div>
            </div>
        </div>
        
        <!-- Rodapé -->
        <div class="footer">
            <p><?php echo htmlspecialchars($fatura['escola_nome']); ?> - Todos os direitos reservados</p>
            <p>Documento emitido eletronicamente pelo Sistema SIGE Angola</p>
            <p>Emitido em <?php echo getDataExtenso($fatura['data_emissao']); ?></p>
            <?php if ($tipo == 'proforma'): ?>
            <p><strong>Documento não tem validade fiscal. Este é um orçamento/pró-forma.</strong></p>
            <?php else: ?>
            <p><strong>Documento com validade fiscal - Factura emitida por sistema autorizado</strong></p>
            <?php endif; ?>
        </div>
    </div>
    
    <button class="btn-print no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Imprimir
    </button>
    
    <script>
        // Auto-print (opcional - descomente para imprimir automaticamente)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>