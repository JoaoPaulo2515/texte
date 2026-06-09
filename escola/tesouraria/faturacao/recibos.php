<?php
// escola/tesouraria/faturacao/recibos.php - Gestão de Recibos de Faturação 

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// VARIÁVEIS
// ============================================
$pagamento_id = isset($_GET['pagamento_id']) ? (int)$_GET['pagamento_id'] : 0;
$fatura_numero = isset($_GET['fatura']) ? $_GET['fatura'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : 'view';
$format = isset($_GET['format']) ? $_GET['format'] : 'html'; // html, pdf, termico

// ============================================
// BUSCAR DADOS DO PAGAMENTO
// ============================================
if ($pagamento_id > 0) {
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco, e.telefone as aluno_telefone, e.email as aluno_email
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
    $sql = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.endereco as aluno_endereco, e.telefone as aluno_telefone, e.email as aluno_email
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
    // Listar todos os recibos
    $sql_recibos = "SELECT p.*, e.nome as aluno_nome, e.matricula
                    FROM pagamentos p
                    JOIN estudantes e ON e.id = p.assinatura_id
                    WHERE p.escola_id = :escola_id
                    ORDER BY p.id DESC
                    LIMIT 50";
    $stmt_recibos = $conn->prepare($sql_recibos);
    $stmt_recibos->execute([':escola_id' => $escola_id]);
    $lista_recibos = $stmt_recibos->fetchAll(PDO::FETCH_ASSOC);
    
    // Mostrar lista de recibos
    include 'lista_recibos.php';
    exit;
}

// Buscar outros itens da mesma fatura (se houver mais de um)
$sql_itens = "SELECT p.*, e.nome as aluno_nome
              FROM pagamentos p
              JOIN estudantes e ON e.id = p.assinatura_id
              WHERE p.numero_fatura = :fatura AND p.escola_id = :escola_id
              ORDER BY p.id ASC";
$stmt_itens = $conn->prepare($sql_itens);
$stmt_itens->execute([':fatura' => $fatura_numero, ':escola_id' => $escola_id]);
$itens_fatura = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$total_valor = array_sum(array_column($itens_fatura, 'valor'));
$total_pago = array_sum(array_column($itens_fatura, 'valor'));

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, capital_social, logotipo 
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
    case 'transferencia': $forma_pagamento_display = 'TRANSFERÊNCIA BANCÁRIA'; break;
    case 'deposito': $forma_pagamento_display = 'DEPÓSITO'; break;
    case 'cheque': $forma_pagamento_display = 'CHEQUE'; break;
    case 'multicaixa': $forma_pagamento_display = 'MULTICAIXA'; break;
    default: $forma_pagamento_display = strtoupper($pagamento['metodo_pagamento']);
}

function formatarMoedaRecibo($valor) {
    return number_format($valor, 2, ',', '.');
}

// Se for formato térmico
if ($format == 'termico') {
    include 'recibo_termico.php';
    exit;
}

// Se for formato PDF
if ($format == 'pdf') {
    // Aqui você pode implementar geração de PDF
    // Por enquanto, redireciona para o HTML
    header('Content-Type: text/html; charset=utf-8');
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo - <?php echo $fatura_numero; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #e0e0e0;
            font-family: 'Courier New', 'Lucida Console', monospace;
            padding: 20px;
        }
        
        .recibo-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .recibo {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .recibo {
                box-shadow: none;
                padding: 15px;
                margin: 0;
            }
            .btn-print, .btn-voltar, .no-print {
                display: none !important;
            }
            .recibo {
                width: 100%;
            }
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .empresa-nome {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .empresa-dados {
            font-size: 9pt;
            margin-top: 5px;
        }
        
        .titulo-recibo {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 15px 0;
            text-transform: uppercase;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 120px;
        }
        
        .info-value {
            flex: 1;
        }
        
        .cliente-box {
            border: 1px solid #ccc;
            padding: 10px;
            margin: 15px 0;
            background: #f9f9f9;
        }
        
        .tabela-itens {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10pt;
        }
        
        .tabela-itens th,
        .tabela-itens td {
            border-bottom: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .tabela-itens th {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .tabela-itens td:last-child,
        .tabela-itens th:last-child {
            text-align: right;
        }
        
        .total-box {
            text-align: right;
            margin: 15px 0;
            padding-top: 10px;
            border-top: 2px solid #000;
        }
        
        .total-valor {
            font-size: 14pt;
            font-weight: bold;
        }
        
        .pagamento-box {
            background: #f0f0f0;
            padding: 10px;
            margin: 15px 0;
            text-align: center;
            font-weight: bold;
        }
        
        .rodape {
            text-align: center;
            font-size: 8pt;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .assinatura {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .linha-assinatura {
            border-top: 1px solid #000;
            width: 100%;
            margin-top: 30px;
            padding-top: 5px;
        }
        
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #006B3E;
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .btn-print:hover {
            background: #004d2d;
        }
        
        .btn-voltar {
            position: fixed;
            bottom: 20px;
            left: 20px;
            padding: 12px 24px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 14px;
            text-decoration: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        .btn-termico {
            position: fixed;
            bottom: 20px;
            right: 160px;
            padding: 12px 24px;
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-family: Arial, sans-serif;
            font-size: 14px;
            text-decoration: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .btn-termico:hover {
            background: #138496;
            color: white;
        }
        
        .qr-code {
            text-align: center;
            margin: 15px 0;
        }
        
        .codigo-barras {
            font-family: 'Courier New', monospace;
            font-size: 20pt;
            letter-spacing: 2px;
            text-align: center;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="recibo-container">
        <div class="recibo" id="recibo">
            <!-- Cabeçalho -->
            <div class="header">
                <div class="empresa-nome"><?php echo mb_strtoupper(htmlspecialchars($escola['nome'])); ?></div>
                <div class="empresa-dados">
                    <?php if (!empty($escola['endereco'])): ?>
                    <?php echo htmlspecialchars($escola['endereco']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($escola['telefone'])): ?>
                    Tel: <?php echo htmlspecialchars($escola['telefone']); ?> | 
                    <?php endif; ?>
                    <?php if (!empty($escola['email'])): ?>
                    Email: <?php echo htmlspecialchars($escola['email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($escola['nif'])): ?>
                    NIF: <?php echo htmlspecialchars($escola['nif']); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Título -->
            <div class="titulo-recibo">
                <i class="fas fa-receipt"></i> RECIBO DE PAGAMENTO
            </div>
            
            <!-- Informações do Documento -->
            <div class="info-row">
                <span class="info-label">Nº do Documento:</span>
                <span class="info-value"><?php echo htmlspecialchars($fatura_numero); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Data de Emissão:</span>
                <span class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($pagamento['created_at'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Data de Pagamento:</span>
                <span class="info-value"><?php echo date('d/m/Y', strtotime($pagamento['data_pagamento'])); ?></span>
            </div>
            
            <!-- Código de Barras Simbólico -->
            <div class="codigo-barras">
                <?php echo str_repeat('█', 40); ?><br>
                <?php echo $fatura_numero; ?><br>
                <?php echo str_repeat('█', 40); ?>
            </div>
            
            <!-- Dados do Cliente -->
            <div class="cliente-box">
                <div class="info-row">
                    <span class="info-label">Cliente:</span>
                    <span class="info-value"><strong><?php echo mb_strtoupper(htmlspecialchars($pagamento['aluno_nome'])); ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Matrícula:</span>
                    <span class="info-value"><?php echo htmlspecialchars($pagamento['matricula']); ?></span>
                </div>
                <?php if (!empty($pagamento['aluno_endereco'])): ?>
                <div class="info-row">
                    <span class="info-label">Endereço:</span>
                    <span class="info-value"><?php echo htmlspecialchars($pagamento['aluno_endereco']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($pagamento['quem_pagou'])): ?>
                <div class="info-row">
                    <span class="info-label">Pago por:</span>
                    <span class="info-value"><?php echo htmlspecialchars($pagamento['quem_pagou']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tabela de Itens -->
            <table class="tabela-itens">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th width="60">Qtd</th>
                        <th width="100">Preço Unit.</th>
                        <th width="100">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itens_fatura as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['referente'] ?: ucfirst($item['tipo_pagamento'])); ?></td>
                        <td class="text-center">1</td>
                        <td class="text-end"><?php echo formatarMoedaRecibo($item['valor']); ?> Kz</td>
                        <td class="text-end"><?php echo formatarMoedaRecibo($item['valor']); ?> Kz</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end fw-bold">TOTAL:</td>
                        <td class="text-end fw-bold"><?php echo formatarMoedaRecibo($total_valor); ?> Kz</td>
                    </tr>
                </tfoot>
            </table>
            
            <!-- QR Code Simbólico -->
            <div class="qr-code">
                <?php
                $qr_data = $fatura_numero . "|" . date('Ymd') . "|" . formatarMoedaRecibo($total_valor);
                ?>
                <div style="font-family: monospace; font-size: 8pt;">
                    ╔════════════════════════════╗<br>
                    ║                            ║<br>
                    ║     COMPROVANTE VÁLIDO      ║<br>
                    ║       DE PAGAMENTO          ║<br>
                    ║                            ║<br>
                    ║  <?php echo $fatura_numero; ?>  ║<br>
                    ║                            ║<br>
                    ╚════════════════════════════╝
                </div>
            </div>
            
            <!-- Valor por Extenso -->
            <div class="info-row mt-2">
                <span class="info-label">Valor por Extenso:</span>
                <span class="info-value"><?php echo numeroPorExtenso($total_valor); ?> Kwanzas</span>
            </div>
            
            <!-- Forma de Pagamento -->
            <div class="pagamento-box">
                <div>FORMA DE PAGAMENTO: <?php echo $forma_pagamento_display; ?></div>
                <?php if (!empty($pagamento['numero_referencia'])): ?>
                <div>Nº DE REFERÊNCIA: <?php echo htmlspecialchars($pagamento['numero_referencia']); ?></div>
                <?php endif; ?>
                <?php if (!empty($pagamento['quem_recebeu'])): ?>
                <div>RECEBIDO POR: <?php echo htmlspecialchars($pagamento['quem_recebeu']); ?></div>
                <?php endif; ?>
            </div>
            
            <!-- Observações -->
            <?php if (!empty($pagamento['observacoes'])): ?>
            <div class="info-row">
                <span class="info-label">Observações:</span>
                <span class="info-value"><?php echo htmlspecialchars($pagamento['observacoes']); ?></span>
            </div>
            <?php endif; ?>
            
            <!-- Assinaturas -->
            <div class="assinatura">
                <div class="assinatura-item">
                    <div class="linha-assinatura"></div>
                    <div>Assinatura do Cliente</div>
                </div>
                <div class="assinatura-item">
                    <div class="linha-assinatura"></div>
                    <div>Assinatura do Recebedor</div>
                </div>
            </div>
            
            <!-- Rodapé -->
            <div class="rodape">
                Documento emitido por computador - Válido como recibo de pagamento<br>
                <?php echo "SIGE Angola - Sistema Integrado de Gestão Escolar " . date('Y'); ?><br>
                <?php echo "Processado em: " . date('d/m/Y H:i:s'); ?>
            </div>
        </div>
    </div>
    
    <a href="javascript:history.back()" class="btn-voltar no-print">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
    
    <a href="?pagamento_id=<?php echo $pagamento_id; ?>&format=termico" class="btn-termico no-print" target="_blank">
        <i class="fas fa-print"></i> Ticket Térmico
    </a>
    
    <button class="btn-print no-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir Recibo
    </button>
    
    <script>
        // Função para imprimir automaticamente se veio com parâmetro print=1
        <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
        setTimeout(function() {
            window.print();
        }, 500);
        <?php endif; ?>
    </script>
</body>
</html>

<?php
// Função para converter número em extenso
function numeroPorExtenso($numero) {
    $numero = round($numero, 2);
    $inteiro = floor($numero);
    $centavos = round(($numero - $inteiro) * 100);
    
    $extenso = new NumberFormatter('pt', NumberFormatter::SPELLOUT);
    $texto = $extenso->format($inteiro);
    
    if ($centavos > 0) {
        $texto .= ' e ' . $extenso->format($centavos) . ' centavos';
    }
    
    return ucfirst($texto);
}

// Se a classe NumberFormatter não existir
if (!class_exists('NumberFormatter')) {
    function numeroPorExtenso($numero) {
        return number_format($numero, 2, ',', '.') . ' Kwanzas';
    }
}
?>