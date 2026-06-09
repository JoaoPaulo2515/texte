<?php
// escola/aluno/financeiro/ver_fatura_proforma.php - Visualizar Fatura Pró-Forma

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$fatura_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$fatura_id) {
    die('Fatura não especificada.');
}

// ==============================================
// BUSCAR DADOS DA FATURA
// ==============================================
$sql_fatura = "SELECT fp.*, u.nome as usuario_nome, e.nome as escola_nome, e.endereco as escola_endereco,
                      e.telefone as escola_telefone, e.email as escola_email, e.nif as escola_nif, e.logo as escola_logo
               FROM faturas_proforma fp
               LEFT JOIN usuarios u ON u.id = fp.usuario_id
               CROSS JOIN escolas e ON e.id = fp.escola_id
               WHERE fp.id = :fatura_id 
               AND fp.estudante_id = :aluno_id 
               AND fp.escola_id = :escola_id";

$stmt_fatura = $conn->prepare($sql_fatura);
$stmt_fatura->execute([
    ':fatura_id' => $fatura_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$fatura = $stmt_fatura->fetch(PDO::FETCH_ASSOC);

if (!$fatura) {
    die('Fatura não encontrada ou acesso negado.');
}

// ==============================================
// BUSCAR ITENS DA FATURA
// ==============================================
$sql_itens = "SELECT * FROM fatura_proforma_itens WHERE fatura_id = :fatura_id ORDER BY id ASC";
$stmt_itens = $conn->prepare($sql_itens);
$stmt_itens->execute([':fatura_id' => $fatura_id]);
$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, email, telefone FROM estudantes WHERE id = :aluno_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'paga':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Paga</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelada':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
        case 'expirada':
            return '<span class="badge bg-secondary"><i class="fas fa-hourglass-end"></i> Expirada</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMetodoPagamentoLabel($metodo) {
    $metodos = [
        'dinheiro' => 'Dinheiro',
        'cartao_credito' => 'Cartão de Crédito',
        'cartao_debito' => 'Cartão de Débito',
        'transferencia' => 'Transferência Bancária',
        'pix' => 'PIX',
        'boleto' => 'Boleto Bancário'
    ];
    return $metodos[$metodo] ?? ucfirst($metodo);
}

function getDataExtenso($data) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = $meses[(int)date('n', $timestamp)];
    $ano = date('Y', $timestamp);
    return "$dia de $mes de $ano";
}

function getExtenso($valor) {
    // Função simples para converter número em extenso
    $inteiro = floor($valor);
    $centavos = round(($valor - $inteiro) * 100);
    
    $extenso = number_format($inteiro, 0, ',', '.') . ' KZ';
    if ($centavos > 0) {
        $extenso .= ' e ' . $centavos . ' centavos';
    }
    return $extenso;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura Pró-Forma <?php echo $fatura['numero_fatura']; ?> | SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #e0e0e0;
            font-family: 'Times New Roman', 'Arial', sans-serif;
            padding: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .fatura-container {
            max-width: 900px;
            width: 100%;
            background: white;
            margin: 0 auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .fatura {
            padding: 35px;
        }
        
        /* Header */
        .header {
            text-align: center;
            border-bottom: 3px solid #006B3E;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        
        .header h1 {
            color: #006B3E;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 12px;
        }
        
        .header .fatura-titulo {
            background: #006B3E;
            color: white;
            display: inline-block;
            padding: 8px 30px;
            margin-top: 15px;
            border-radius: 25px;
            font-size: 16px;
            letter-spacing: 2px;
        }
        
        /* Informações */
        .info-row {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-label {
            width: 120px;
            font-weight: bold;
            color: #555;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        /* Tabela de Itens */
        .itens-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
        }
        
        .itens-table th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-weight: bold;
        }
        
        .itens-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .itens-table .text-end {
            text-align: right;
        }
        
        .total-linha td {
            border-top: 2px solid #333;
            font-weight: bold;
            padding-top: 15px;
        }
        
        /* Totais */
        .totais {
            width: 100%;
            margin-top: 20px;
        }
        
        .totais-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 8px;
        }
        
        .totais-label {
            width: 150px;
            font-weight: bold;
        }
        
        .totais-value {
            width: 150px;
            text-align: right;
        }
        
        .total-final {
            font-size: 1.2em;
            border-top: 2px solid #333;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .total-final .totais-label {
            font-size: 1.1em;
        }
        
        .total-final .totais-value {
            font-size: 1.1em;
            color: #006B3E;
            font-weight: bold;
        }
        
        /* Extenso */
        .extenso {
            margin-top: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 13px;
            color: #555;
            border-left: 3px solid #006B3E;
        }
        
        /* Observações */
        .observacoes {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        
        /* Assinaturas */
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
        
        /* Footer */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        
        /* Botões */
        .botoes {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #ddd;
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
        }
        
        .btn-imprimir {
            background: #17a2b8;
            color: white;
        }
        
        .btn-imprimir:hover {
            background: #138496;
        }
        
        .btn-pagar {
            background: #28a745;
            color: white;
        }
        
        .btn-pagar:hover {
            background: #218838;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
        }
        
        .status-badge {
            font-size: 14px;
            padding: 5px 12px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .fatura-container {
                box-shadow: none;
                margin: 0;
            }
            
            .botoes {
                display: none;
            }
            
            .fatura {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="fatura-container">
    <div class="fatura">
        <!-- Cabeçalho -->
        <div class="header">
            <h1><?php echo htmlspecialchars($fatura['escola_nome'] ?? 'SIGE Angola'); ?></h1>
            <div class="subtitle">
                <?php echo htmlspecialchars($fatura['escola_endereco'] ?? ''); ?>
                <br>
                NIF: <?php echo $fatura['escola_nif'] ?? '---'; ?> | 
                Tel: <?php echo $fatura['escola_telefone'] ?? ''; ?> | 
                Email: <?php echo $fatura['escola_email'] ?? ''; ?>
            </div>
            <div class="fatura-titulo">
                FATURA PRÓ-FORMA
            </div>
        </div>
        
        <!-- Status da Fatura -->
        <div class="info-box" style="background: <?php echo $fatura['status'] == 'pendente' ? '#fff3cd' : ($fatura['status'] == 'paga' ? '#d4edda' : '#f8d7da'); ?>;">
            <div class="d-flex justify-content-between align-items-center">
                <strong>Status:</strong>
                <?php echo getStatusBadge($fatura['status']); ?>
                <?php if ($fatura['status'] == 'pendente' && strtotime($fatura['data_validade']) < time()): ?>
                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ATENÇÃO: Fatura expirada!</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Informações -->
        <div class="info-grid">
            <div class="info-box">
                <div class="info-row">
                    <div class="info-label">Nº da Fatura:</div>
                    <div class="info-value"><?php echo $fatura['numero_fatura']; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Data de Emissão:</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($fatura['data_emissao'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Data de Validade:</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($fatura['data_validade'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Emitido por:</div>
                    <div class="info-value"><?php echo htmlspecialchars($fatura['usuario_nome'] ?? 'Sistema'); ?></div>
                </div>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <div class="info-label">Cliente:</div>
                    <div class="info-value"><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Matrícula:</div>
                    <div class="info-value"><?php echo htmlspecialchars($aluno['matricula'] ?? ''); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Turma:</div>
                    <div class="info-value"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contacto:</div>
                    <div class="info-value"><?php echo htmlspecialchars($aluno['telefone'] ?? ''); ?> | <?php echo htmlspecialchars($aluno['email'] ?? ''); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Itens -->
        <table class="itens-table">
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th width="120">Quant.</th>
                    <th width="120">Valor Unit.</th>
                    <th width="120">Total (KZ)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($itens)): ?>
                <tr>
                    <td colspan="4" class="text-center">Nenhum item encontrado nesta fatura.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($itens as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['descricao']); ?></td>
                        <td class="text-end">1</td>
                        <td class="text-end"><?php echo formatarMoeda($item['valor_unitario']); ?></td>
                        <td class="text-end"><?php echo formatarMoeda($item['valor_unitario']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totais -->
        <div class="totais">
            <div class="totais-row">
                <div class="totais-label">Subtotal:</div>
                <div class="totais-value"><?php echo formatarMoeda($fatura['subtotal']); ?> KZ</div>
            </div>
            <?php if ($fatura['desconto'] > 0): ?>
            <div class="totais-row">
                <div class="totais-label">Desconto:</div>
                <div class="totais-value text-danger">- <?php echo formatarMoeda($fatura['desconto']); ?> KZ</div>
            </div>
            <?php endif; ?>
            <?php if ($fatura['iva'] > 0): ?>
            <div class="totais-row">
                <div class="totais-label">IVA (<?php echo round(($fatura['iva'] / $fatura['subtotal']) * 100); ?>%):</div>
                <div class="totais-value"><?php echo formatarMoeda($fatura['iva']); ?> KZ</div>
            </div>
            <?php endif; ?>
            <div class="totais-row total-final">
                <div class="totais-label">TOTAL:</div>
                <div class="totais-value"><?php echo formatarMoeda($fatura['total']); ?> KZ</div>
            </div>
        </div>
        
        <!-- Valor por Extenso -->
        <div class="extenso">
            <strong>Valor por Extenso:</strong> <?php echo getExtenso($fatura['total']); ?>
        </div>
        
        <!-- Observações -->
        <?php if (!empty($fatura['observacoes'])): ?>
        <div class="observacoes">
            <strong>Observações:</strong><br>
            <?php echo nl2br(htmlspecialchars($fatura['observacoes'])); ?>
        </div>
        <?php endif; ?>
        
        <div class="observacoes">
            <strong>Instruções de Pagamento:</strong><br>
            • O pagamento deve ser efetuado até a data de vencimento.<br>
            • Após o pagamento, enviar o comprovante para o e-mail: financeiro@<?php echo str_replace(' ', '', strtolower($fatura['escola_nome'] ?? 'sigeangola')); ?>.com<br>
            • Em caso de dúvidas, contactar a secretaria financeira.
        </div>
        
        <!-- Assinaturas -->
        <div class="assinaturas">
            <div class="assinatura">
                <div class="assinatura-linha"></div>
                <div>Aluno / Responsável</div>
                <div class="small text-muted">Data: ___/___/______</div>
            </div>
            <div class="assinatura">
                <div class="assinatura-linha"></div>
                <div><?php echo htmlspecialchars($fatura['usuario_nome'] ?? 'Secretaria Financeira'); ?></div>
                <div class="small text-muted">Data: <?php echo date('d/m/Y', strtotime($fatura['data_emissao'])); ?></div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Esta fatura pró-forma é um documento de cotação e não possui validade fiscal.</p>
            <p>Documento emitido eletronicamente por SIGE Angola - Sistema de Gestão Escolar</p>
            <p>Código de Autenticação: <?php echo md5($fatura['id'] . $fatura['numero_fatura'] . $fatura['created_at']); ?></p>
        </div>
    </div>
    
    <!-- Botões -->
    <div class="botoes">
        <button class="btn btn-imprimir" onclick="window.print();">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <?php if ($fatura['status'] == 'pendente' && strtotime($fatura['data_validade']) > time()): ?>
        <button class="btn btn-pagar" onclick="solicitarPagamento()">
            <i class="fas fa-credit-card"></i> Solicitar Pagamento
        </button>
        <?php endif; ?>
        <a href="fatura_pro_forma.php" class="btn btn-voltar">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
    </div>
</div>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function solicitarPagamento() {
        if (confirm('Deseja solicitar o pagamento desta fatura? Você receberá as instruções por e-mail.')) {
            $.ajax({
                url: 'solicitar_pagamento_fatura.php',
                method: 'POST',
                data: { 
                    fatura_id: <?php echo $fatura_id; ?>, 
                    metodo: 'pendente',
                    observacoes: 'Solicitação via visualização de fatura pró-forma'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Solicitação enviada com sucesso! Você receberá as instruções de pagamento por e-mail.');
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao enviar solicitação. Tente novamente.');
                }
            });
        }
    }
</script>
</body>
</html>