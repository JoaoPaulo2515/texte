<?php
// escola/tesouraria/imprimir_comprovativo.php
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    die('Acesso negado');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar configuração da fatura
$sql_config = "SELECT * FROM configuracao_fatura WHERE escola_id = :escola_id LIMIT 1";
$stmt_config = $conn->prepare($sql_config);
$stmt_config->execute([':escola_id' => $escola_id]);
$config = $stmt_config->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $config = [
        'nome_empresa' => 'SIGE Angola',
        'endereco' => '',
        'telefone' => '',
        'nif' => '',
        'capital_social' => ''
    ];
}

$fatura_numero = $_GET['fatura'] ?? $_GET['pagamento_id'] ?? '';
$pagamento_id = $_GET['pagamento_id'] ?? 0;

if ($fatura_numero) {
    // Buscar por fatura
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco 
            FROM pagamentos p
            JOIN estudantes e ON e.id = p.assinatura_id
            WHERE p.numero_fatura = :fatura AND p.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fatura' => $fatura_numero, ':escola_id' => $escola_id]);
    $pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($pagamentos)) {
        die('Fatura não encontrada');
    }
    
    $total = array_sum(array_column($pagamentos, 'valor'));
    $primeiro = $pagamentos[0];
    $comprovativo_numero = $primeiro['comprovativo_numero'];
    $forma_pagamento = $primeiro['metodo_pagamento'];
    $data = $primeiro['data_pagamento'];
    
} elseif ($pagamento_id) {
    // Buscar pagamento individual
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco 
            FROM pagamentos p
            JOIN estudantes e ON e.id = p.assinatura_id
            WHERE p.id = :id AND p.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $pagamento_id, ':escola_id' => $escola_id]);
    $pagamentos = [$stmt->fetch(PDO::FETCH_ASSOC)];
    
    if (!$pagamentos[0]) {
        die('Pagamento não encontrado');
    }
    
    $total = $pagamentos[0]['valor'];
    $comprovativo_numero = $pagamentos[0]['comprovativo_numero'];
    $forma_pagamento = $pagamentos[0]['metodo_pagamento'];
    $data = $pagamentos[0]['data_pagamento'];
    $fatura_numero = $pagamentos[0]['numero_fatura'];
} else {
    die('Parâmetros inválidos');
}

// Formatar forma de pagamento
$forma_display = '';
switch ($forma_pagamento) {
    case 'dinheiro': $forma_display = 'DINHEIRO'; break;
    case 'transferencia': $forma_display = 'TRANSFERÊNCIA BANCÁRIA'; break;
    case 'multicaixa': $forma_display = 'MULTICAIXA'; break;
    default: $forma_display = strtoupper($forma_pagamento);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovativo - <?php echo $comprovativo_numero; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            background: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        /* Estilo para impressora térmica (80mm) */
        .comprovativo {
            width: 80mm;
            max-width: 80mm;
            background: white;
            padding: 2mm 3mm;
            margin: 0 auto;
            font-size: 9pt;
            line-height: 1.3;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .comprovativo .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            margin-bottom: 5px;
            padding-bottom: 5px;
        }
        
        .comprovativo .empresa-nome {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .comprovativo .endereco {
            font-size: 7pt;
        }
        
        .comprovativo .info {
            margin: 5px 0;
        }
        
        .comprovativo .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        
        .comprovativo .titulo {
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
            font-size: 10pt;
        }
        
        .comprovativo .dados-cliente {
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 5px 0;
            margin: 5px 0;
        }
        
        .comprovativo .produtos {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0;
        }
        
        .comprovativo .produtos th,
        .comprovativo .produtos td {
            border-bottom: 1px dotted #ccc;
            padding: 2px 0;
            text-align: left;
        }
        
        .comprovativo .produtos th {
            border-bottom: 1px solid #000;
        }
        
        .comprovativo .produtos td:last-child,
        .comprovativo .produtos th:last-child {
            text-align: right;
        }
        
        .comprovativo .total-row {
            border-top: 1px dashed #000;
            margin-top: 5px;
            padding-top: 5px;
        }
        
        .comprovativo .total {
            font-weight: bold;
            font-size: 11pt;
        }
        
        .comprovativo .forma-pagamento {
            background: #f0f0f0;
            padding: 5px;
            text-align: center;
            margin: 5px 0;
            font-weight: bold;
        }
        
        .comprovativo .rodape {
            text-align: center;
            border-top: 1px dashed #000;
            margin-top: 10px;
            padding-top: 5px;
            font-size: 7pt;
        }
        
        .comprovativo .assinatura {
            margin-top: 15px;
            text-align: center;
        }
        
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
        
        .btn-print:hover {
            background: #004d2d;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .btn-print {
                display: none;
            }
            .comprovativo {
                box-shadow: none;
                padding: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="comprovativo" id="comprovativo">
        <div class="header">
            <div class="empresa-nome"><?php echo strtoupper(htmlspecialchars($config['nome_empresa'])); ?></div>
            <div class="endereco"><?php echo htmlspecialchars($config['endereco']); ?></div>
            <div class="endereco">Tel: <?php echo htmlspecialchars($config['telefone']); ?> | Email: <?php echo htmlspecialchars($config['email']); ?></div>
            <div class="info-row"><span>NIF: <?php echo htmlspecialchars($config['nif']); ?></span><span>Capital Social: <?php echo htmlspecialchars($config['capital_social']); ?></span></div>
        </div>
        
        <div class="info">
            <div class="info-row"><span>Documento: <?php echo $fatura_numero; ?></span><span>Data: <?php echo date('d-m-Y H:i:s', strtotime($data)); ?></span></div>
            <div class="info-row"><span>Comprovativo: <?php echo $comprovativo_numero; ?></span></div>
        </div>
        
        <div class="dados-cliente">
            <div class="info-row"><strong>Cliente:</strong> <?php echo htmlspecialchars($pagamentos[0]['aluno_nome']); ?></div>
            <div class="info-row"><span>Matrícula: <?php echo htmlspecialchars($pagamentos[0]['matricula']); ?></span></div>
        </div>
        
        <div class="titulo">DISCRIMINAÇÃO</div>
        
        <table class="produtos">
            <thead>
                <tr><th>DESC/QTD</th><th>PREÇO</th><th>VALOR</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pagamentos as $pg): ?>
                <tr>
                    <td><?php echo htmlspecialchars($pg['referente'] ?: ucfirst($pg['tipo_pagamento'])); ?><br>1 x <?php echo number_format($pg['valor'], 2, ',', '.'); ?></td>
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
            <?php echo $forma_display; ?> <?php echo number_format($total, 2, ',', '.'); ?> Kz
        </div>
        
        <div class="rodape">
            <?php echo "Os bens/serviços foram colocados à disposição do adquirente/prestados em " . date('d-m-Y'); ?><br>
            Processado por programa validado n° 54/AGT/2019 - SIGE
        </div>
        
        <div class="assinatura">
            _________________________<br>
            Assinatura do Cliente
        </div>
    </div>
    
    <button class="btn-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir Comprovativo
    </button>
    
    <script>
        // Auto-print ao carregar
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>