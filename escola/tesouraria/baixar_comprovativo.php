<?php
// escola/tesouraria/baixar_comprovativo.php - Gerar e baixar comprovativo PDF

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    die('Acesso negado');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$fatura_numero = $_GET['fatura'] ?? '';

if (empty($fatura_numero)) {
    die('Número da fatura não informado');
}

// Buscar dados da fatura
$sql = "SELECT p.*, e.nome as aluno_nome, e.matricula 
        FROM pagamentos p
        JOIN estudantes e ON e.id = p.assinatura_id
        WHERE p.numero_fatura = :fatura AND p.escola_id = :escola_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':fatura' => $fatura_numero, ':escola_id' => $escola_id]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pagamentos)) {
    die('Fatura não encontrada');
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

$total = array_sum(array_column($pagamentos, 'valor'));
$data_pagamento = date('d/m/Y H:i:s', strtotime($pagamentos[0]['data_pagamento']));
$forma_pagamento = $pagamentos[0]['metodo_pagamento'];
$numero_referencia = $pagamentos[0]['numero_referencia'] ?? '-';

// Formatar forma de pagamento
$forma_display = '';
switch ($forma_pagamento) {
    case 'dinheiro': $forma_display = 'DINHEIRO'; break;
    case 'transferencia': $forma_display = 'TRANSFERÊNCIA BANCÁRIA'; break;
    case 'deposito': $forma_display = 'DEPÓSITO'; break;
    case 'cheque': $forma_display = 'CHEQUE'; break;
    case 'multicaixa': $forma_display = 'MULTICAIXA'; break;
    default: $forma_display = strtoupper($forma_pagamento);
}

// Configurar cabeçalhos para download
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="comprovativo_' . $fatura_numero . '.html"');
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Comprovativo - <?php echo $fatura_numero; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Courier New', 'Lucida Console', monospace; 
            background: #e0e0e0; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            padding: 20px;
        }
        .comprovativo { 
            width: 80mm; 
            background: white; 
            padding: 3mm 4mm; 
            margin: 0 auto; 
            font-size: 9pt; 
            line-height: 1.3;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header { text-align: center; border-bottom: 1px dashed #000; margin-bottom: 5px; padding-bottom: 5px; }
        .empresa-nome { font-size: 14pt; font-weight: bold; text-transform: uppercase; }
        .endereco { font-size: 7pt; }
        .info { margin: 5px 0; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .dados-cliente { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; margin: 5px 0; }
        .produtos { width: 100%; border-collapse: collapse; margin: 5px 0; }
        .produtos th, .produtos td { border-bottom: 1px dotted #ccc; padding: 3px 0; text-align: left; }
        .produtos td:last-child, .produtos th:last-child { text-align: right; }
        .total-row { border-top: 1px dashed #000; margin-top: 5px; padding-top: 5px; }
        .total { font-weight: bold; font-size: 11pt; }
        .forma-pagamento { background: #f0f0f0; padding: 5px; text-align: center; margin: 5px 0; font-weight: bold; }
        .rodape { text-align: center; border-top: 1px dashed #000; margin-top: 10px; padding-top: 5px; font-size: 7pt; }
        .btn-print { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            padding: 10px 20px; 
            background: #006B3E; 
            color: white; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer;
            font-family: Arial, sans-serif;
            z-index: 1000;
        }
        .btn-print:hover { background: #004d2d; }
        @media print { 
            body { background: white; padding: 0; margin: 0; }
            .btn-print { display: none; }
            .comprovativo { box-shadow: none; padding: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="comprovativo" id="comprovativo">
        <div class="header">
            <div class="empresa-nome"><?php echo strtoupper(htmlspecialchars($escola['nome'] ?? 'SIGE ANGOLA')); ?></div>
            <div class="endereco"><?php echo htmlspecialchars($escola['endereco'] ?? ''); ?></div>
            <div class="endereco">Tel: <?php echo htmlspecialchars($escola['telefone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($escola['email'] ?? ''); ?></div>
            <div class="info-row"><span>NIF: <?php echo htmlspecialchars($escola['nif'] ?? ''); ?></span></div>
        </div>
        
        <div class="info">
            <div class="info-row"><span>FATURA Nº: <?php echo $fatura_numero; ?></span><span>Data: <?php echo $data_pagamento; ?></span></div>
            <div class="info-row"><span>COMPROVATIVO: <?php echo $numero_referencia; ?></span></div>
        </div>
        
        <div class="dados-cliente">
            <div class="info-row"><strong>CLIENTE:</strong> <?php echo htmlspecialchars($pagamentos[0]['aluno_nome']); ?></div>
            <div class="info-row"><span>MATRÍCULA: <?php echo $pagamentos[0]['matricula']; ?></span></div>
        </div>
        
        <table class="produtos">
            <thead>
                <tr><th>DESCRIÇÃO</th><th>QTD</th><th>PREÇO</th><th>TOTAL</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pagamentos as $pg): ?>
                <tr>
                    <td><?php echo htmlspecialchars($pg['referente'] ?: ucfirst($pg['tipo_pagamento'])); ?></td>
                    <td>1</td>
                    <td><?php echo number_format($pg['valor'], 2, ',', '.'); ?></td>
                    <td><?php echo number_format($pg['valor'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="total-row">
            <div class="info-row"><span>TOTAL</span><span class="total"><?php echo number_format($total, 2, ',', '.'); ?> Kz</span></div>
        </div>
        
        <div class="forma-pagamento">
            <?php echo $forma_display; ?> - <?php echo number_format($total, 2, ',', '.'); ?> Kz
            <?php if ($numero_referencia != '-'): ?>
            <br>Ref: <?php echo $numero_referencia; ?>
            <?php endif; ?>
        </div>
        
        <div class="rodape">
            Documento emitido por computador - Válido como recibo<br>
            <?php echo "Os bens/serviços foram pagos em " . date('d/m/Y'); ?>
        </div>
    </div>
    
   
<button class="btn-print no-print" onclick="gerarPDF()">
    <i class="fas fa-file-pdf"></i> Baixar PDF
</button>

<script>
function gerarPDF() {
    const { jsPDF } = window.jspdf;
    const element = document.getElementById('ticket');
    
    html2canvas(element, {
        scale: 2,
        backgroundColor: '#ffffff'
    }).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jsPDF({
            unit: 'mm',
            format: [80, canvas.height * 0.264583],
            orientation: 'portrait'
        });
        const imgWidth = 80;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
        pdf.save('recibo_' + new Date().getTime() + '.pdf');
    });
}
</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
   
</body>
</html>