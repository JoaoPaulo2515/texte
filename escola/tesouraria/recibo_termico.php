<?php
// escola/tesouraria/recibo_termico.php - Recibo formato Talão Térmico (80mm/58mm)

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    die('Acesso negado');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

$pagamento_id = isset($_GET['pagamento_id']) ? (int)$_GET['pagamento_id'] : 0;
$fatura_numero = isset($_GET['fatura']) ? $_GET['fatura'] : '';

// Buscar dados do pagamento
if ($pagamento_id > 0) {
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco, e.telefone as aluno_telefone
            FROM pagamentos p
            JOIN estudantes e ON e.id = p.assinatura_id
            WHERE p.id = :id AND p.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $pagamento_id, ':escola_id' => $escola_id]);
    $pagamento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pagamento) {
        die('Pagamento não encontrado');
    }
    $fatura_numero = $pagamento['numero_fatura'];
} elseif (!empty($fatura_numero)) {
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco, e.telefone as aluno_telefone
            FROM pagamentos p
            JOIN estudantes e ON e.id = p.assinatura_id
            WHERE p.numero_fatura = :fatura AND p.escola_id = :escola_id
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fatura' => $fatura_numero, ':escola_id' => $escola_id]);
    $pagamento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pagamento) {
        die('Fatura não encontrada');
    }
    $pagamento_id = $pagamento['id'];
} else {
    die('Parâmetros inválidos');
}

// Buscar outros itens da mesma fatura
$sql_itens = "SELECT p.*, e.nome as aluno_nome
              FROM pagamentos p
              JOIN estudantes e ON e.id = p.assinatura_id
              WHERE p.numero_fatura = :fatura AND p.escola_id = :escola_id
              ORDER BY p.id ASC";
$stmt_itens = $conn->prepare($sql_itens);
$stmt_itens->execute([':fatura' => $fatura_numero, ':escola_id' => $escola_id]);
$itens_fatura = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

$total_valor = array_sum(array_column($itens_fatura, 'valor'));

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, capital_social 
               FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    $escola = [
        'nome' => 'SIGE Angola',
        'endereco' => '',
        'telefone' => '',
        'email' => '',
        'nif' => '',
        'capital_social' => ''
    ];
}

// Formatar forma de pagamento
$forma_pagamento_display = '';
switch ($pagamento['metodo_pagamento']) {
    case 'dinheiro': $forma_pagamento_display = 'DINHEIRO'; break;
    case 'transferencia': $forma_pagamento_display = 'TRANSFERÊNCIA'; break;
    case 'deposito': $forma_pagamento_display = 'DEPÓSITO'; break;
    case 'cheque': $forma_pagamento_display = 'CHEQUE'; break;
    case 'multicaixa': $forma_pagamento_display = 'MULTICAIXA'; break;
    default: $forma_pagamento_display = strtoupper($pagamento['metodo_pagamento']);
}

function formatarMoedaTicket($valor) {
    return number_format($valor, 2, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Ticket - <?php echo $fatura_numero; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* Estilo para impressora térmica 80mm / 58mm */
        body {
            background: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 10px;
            font-family: 'Courier New', 'Lucida Console', 'Monaco', monospace;
        }
        
        /* Container do ticket */
        .ticket {
            width: 80mm;
            max-width: 80mm;
            background: white;
            margin: 0 auto;
            padding: 2mm 3mm;
            font-family: 'Courier New', monospace;
            font-size: 9pt;
            line-height: 1.2;
            box-shadow: 0 0 5px rgba(0,0,0,0.2);
        }
        
        /* Para impressoras 58mm */
        @media (max-width: 200px) {
            .ticket {
                width: 58mm;
                font-size: 8pt;
                padding: 1.5mm 2mm;
            }
        }
        
        /* Estilos do ticket */
        .ticket-header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }
        
        .empresa-nome {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .empresa-dados {
            font-size: 7pt;
            margin-top: 2px;
        }
        
        .ticket-title {
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            margin: 5px 0;
            letter-spacing: 2px;
        }
        
        .divider {
            border-top: 1px dashed #000;
            margin: 4px 0;
        }
        
        .divider-double {
            border-top: 2px solid #000;
            margin: 4px 0;
        }
        
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        
        .row-small {
            font-size: 7pt;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .cliente-box {
            border: 1px dotted #000;
            padding: 4px;
            margin: 5px 0;
        }
        
        /* Tabela de itens */
        .itens-table {
            width: 100%;
            margin: 5px 0;
        }
        
        .itens-table .item-row {
            margin-bottom: 2px;
        }
        
        .item-desc {
            font-size: 8pt;
        }
        
        .item-valor {
            text-align: right;
            white-space: nowrap;
        }
        
        .item-qtd {
            text-align: center;
            white-space: nowrap;
        }
        
        .total-box {
            border-top: 1px dashed #000;
            padding-top: 4px;
            margin-top: 4px;
        }
        
        .total-valor {
            font-weight: bold;
            font-size: 11pt;
        }
        
        .pagamento-box {
            background: #f0f0f0;
            padding: 4px;
            margin: 5px 0;
            text-align: center;
            font-weight: bold;
        }
        
        .footer {
            text-align: center;
            border-top: 1px dashed #000;
            margin-top: 5px;
            padding-top: 4px;
            font-size: 7pt;
        }
        
        .qrcode {
            text-align: center;
            margin: 5px 0;
            font-family: monospace;
            font-size: 6pt;
        }
        
        .assinatura {
            margin: 8px 0;
            text-align: center;
        }
        
        .linha-assinatura {
            border-top: 1px dotted #000;
            width: 80%;
            margin: 5px auto;
        }
        
        /* Botão de impressão */
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #006B3E;
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 12px;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-print:hover {
            background: #004d2d;
        }
        
        .btn-voltar {
            position: fixed;
            bottom: 20px;
            left: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 12px;
            text-decoration: none;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        /* Impressão */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .ticket {
                box-shadow: none;
                padding: 0;
                margin: 0;
                width: 100%;
            }
            .btn-print, .btn-voltar {
                display: none !important;
            }
            @page {
                size: 80mm auto;
                margin: 0mm;
            }
        }
    </style>
</head>
<body>
    <div class="ticket" id="ticket">
        <!-- Cabeçalho -->
        <div class="ticket-header">
            <div class="empresa-nome"><?php echo mb_strtoupper(htmlspecialchars($escola['nome'])); ?></div>
            <div class="empresa-dados">
                <?php if (!empty($escola['endereco'])): ?>
                <?php echo htmlspecialchars($escola['endereco']); ?><br>
                <?php endif; ?>
                <?php if (!empty($escola['telefone'])): ?>
                TEL: <?php echo htmlspecialchars($escola['telefone']); ?>
                <?php endif; ?>
                <?php if (!empty($escola['nif'])): ?>
                | NIF: <?php echo htmlspecialchars($escola['nif']); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Título -->
        <div class="ticket-title">
            *** COMPROVATIVO DE PAGAMENTO ***
        </div>
        
        <div class="divider"></div>
        
        <!-- Informações do Documento -->
        <div class="row">
            <span>DATA:</span>
            <span><?php echo date('d/m/Y H:i:s', strtotime($pagamento['created_at'])); ?></span>
        </div>
        <div class="row">
            <span>DOCUMENTO:</span>
            <span><strong><?php echo htmlspecialchars($fatura_numero); ?></strong></span>
        </div>
        <div class="row">
            <span>PGTO DATA:</span>
            <span><?php echo date('d/m/Y', strtotime($pagamento['data_pagamento'])); ?></span>
        </div>
        
        <div class="divider"></div>
        
        <!-- Dados do Cliente -->
        <div class="cliente-box">
            <div class="row">
                <span class="info-label">CLIENTE:</span>
                <span><strong><?php echo mb_strtoupper(htmlspecialchars($pagamento['aluno_nome'])); ?></strong></span>
            </div>
            <div class="row row-small">
                <span>MATRÍCULA:</span>
                <span><?php echo htmlspecialchars($pagamento['matricula']); ?></span>
            </div>
        </div>
        
        <div class="divider"></div>
        
        <!-- Itens -->
        <div class="itens-table">
            <?php foreach ($itens_fatura as $item): ?>
            <div class="item-row row">
                <span class="item-desc"><?php echo htmlspecialchars($item['referente'] ?: ucfirst($item['tipo_pagamento'])); ?></span>
                <span class="item-qtd">1x</span>
                <span class="item-valor"><?php echo formatarMoedaTicket($item['valor']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="divider"></div>
        
        <!-- Total -->
        <div class="total-box">
            <div class="row">
                <span class="info-label">TOTAL:</span>
                <span class="total-valor"><?php echo formatarMoedaTicket($total_valor); ?> Kz</span>
            </div>
        </div>
        
        <!-- Forma de Pagamento -->
        <div class="pagamento-box">
            <div><?php echo $forma_pagamento_display; ?></div>
            <?php if (!empty($pagamento['numero_referencia'])): ?>
            <div class="row-small">REF: <?php echo htmlspecialchars($pagamento['numero_referencia']); ?></div>
            <?php endif; ?>
        </div>
        
        <!-- Quem Pagou e Recebeu -->
        <div class="row row-small">
            <span>PAGO POR:</span>
            <span><?php echo !empty($pagamento['quem_pagou']) ? htmlspecialchars($pagamento['quem_pagou']) : '-'; ?></span>
        </div>
        <div class="row row-small">
            <span>RECEBIDO POR:</span>
            <span><?php echo !empty($pagamento['quem_recebeu']) ? htmlspecialchars($pagamento['quem_recebeu']) : $usuario_nome; ?></span>
        </div>
        
        <!-- Observações -->
        <?php if (!empty($pagamento['observacoes'])): ?>
        <div class="divider"></div>
        <div class="row row-small">
            <span>OBS: <?php echo htmlspecialchars(substr($pagamento['observacoes'], 0, 60)); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- QR Code Simbólico (ASCII) -->
        <div class="qrcode">
            <?php
            // Gerar QR code simbólico em ASCII
            $qr_data = $fatura_numero . "|" . date('Ymd') . "|" . formatarMoedaTicket($total_valor);
            $qr_chars = ['█', '▓', '▒', '░'];
            echo str_repeat('█', 25) . '<br>';
            echo '█' . str_repeat(' ', 23) . '█<br>';
            echo '█   COMPROVANTE   █<br>';
            echo '█   DE PAGAMENTO  █<br>';
            echo '█' . str_repeat(' ', 23) . '█<br>';
            echo str_repeat('█', 25);
            ?>
        </div>
        
        <div class="divider-double"></div>
        
        <!-- Assinatura -->
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <div>Assinatura do Cliente</div>
        </div>
        
        <!-- Rodapé -->
        <div class="footer">
            <?php echo "Documento emitido por computador - Válido como recibo" . "<br>"; ?>
            <?php echo "SIGE Angola - www.sige.ao" . "<br>"; ?>
            <?php echo date('d/m/Y H:i:s'); ?>
        </div>
        
        <!-- Linha de corte -->
        <div class="divider-double"></div>
        <div class="footer" style="font-size: 6pt;">
            * Recibo impresso em impressora térmica *
        </div>
    </div>
    
    <a href="javascript:history.back()" class="btn-voltar">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
    
  <button class="btn-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir Comprovativo
    </button>
    
    <script>
        // Auto-print
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>