<?php
// escola/aluno/financeiro/imprimir_recibo.php - Impressão de Recibo Individual

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
$recibo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados do recibo
$sql = "SELECT 
            p.id,
            p.escola_id,
            p.tipo_pagamento_id,
            p.tipo_pagamento,
            p.valor,
            p.referente,
            p.usuario_id,
            p.metodo_pagamento,
            p.status,
            p.numero_fatura,
            p.numero_referencia,
            p.comprovativo_path,
            p.comprovativo_numero,
            p.comprovante,
            p.data_pagamento,
            p.data_vencimento,
            p.codigo_transacao,
            p.observacoes,
            p.quem_recebeu,
            p.quem_pagou,
            p.created_at,
            p.updated_at,
            p.assinatura_id,
            e.nome as escola_nome,
            e.endereco as escola_endereco,
            e.telefone as escola_telefone,
            e.email as escola_email,
            e.logo as escola_logo,
            u.nome as operador_nome,
            est.nome as aluno_nome,
            est.matricula as aluno_matricula,
            est.email as aluno_email,
            t.nome as turma_nome,
            t.ano as turma_ano
        FROM pagamentos p
        LEFT JOIN escolas e ON e.id = p.escola_id
        LEFT JOIN usuarios u ON u.id = p.usuario_id
        LEFT JOIN estudantes est ON est.id = p.assinatura_id
        LEFT JOIN (
            SELECT m.estudante_id, m.turma_id, t.nome as nome, t.ano as ano
            FROM matriculas m
            LEFT JOIN turmas t ON t.id = m.turma_id
            WHERE m.status = 'ativa'
            GROUP BY m.estudante_id
        ) t ON t.estudante_id = p.assinatura_id
        WHERE p.id = :recibo_id 
        AND p.assinatura_id = :aluno_id 
        AND p.escola_id = :escola_id";

$stmt = $conn->prepare($sql);
$stmt->execute([
    ':recibo_id' => $recibo_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$recibo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recibo) {
    die('Recibo não encontrado ou acesso negado.');
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => 'Mensalidade',
        'matricula' => 'Matrícula',
        'material' => 'Material Escolar',
        'atividade' => 'Atividade Extracurricular',
        'taxa' => 'Taxa Escolar',
        'laboratorio' => 'Laboratório',
        'campo' => 'Saída de Campo',
        'uniforme' => 'Uniforme',
        'outro' => 'Outro'
    ];
    return $tipos[$tipo] ?? ucfirst(str_replace('_', ' ', $tipo));
}

function getMetodoPagamentoLabel($metodo) {
    $metodos = [
        'dinheiro' => 'Dinheiro',
        'cartao_credito' => 'Cartão de Crédito',
        'cartao_debito' => 'Cartão de Débito',
        'transferencia' => 'Transferência Bancária',
        'pix' => 'PIX',
        'boleto' => 'Boleto Bancário',
        'deposito' => 'Depósito Bancário'
    ];
    return $metodos[$metodo] ?? ucfirst(str_replace('_', ' ', $metodo));
}

function getStatusLabel($status) {
    $status_list = [
        'pago' => 'Pago',
        'confirmado' => 'Confirmado',
        'pendente' => 'Pendente',
        'cancelado' => 'Cancelado',
        'parcial' => 'Parcial'
    ];
    return $status_list[$status] ?? ucfirst($status);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo - <?php echo $recibo['numero_fatura'] ? 'Fatura nº ' . $recibo['numero_fatura'] : 'Recibo nº ' . str_pad($recibo['id'], 8, '0', STR_PAD_LEFT); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Times New Roman', Arial, sans-serif;
            background: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .recibo-container {
            max-width: 800px;
            width: 100%;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-radius: 8px;
            overflow: hidden;
        }
        .recibo {
            padding: 30px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .header h1 { color: #006B3E; margin-bottom: 5px; font-size: 24px; }
        .header p { color: #666; margin: 3px 0; font-size: 12px; }
        .recibo-title {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 10px;
            text-align: center;
            margin: 20px 0;
            border-radius: 5px;
        }
        .recibo-title h2 { margin: 0; font-size: 18px; }
        .info-section {
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
            border-bottom: 1px dashed #eee;
            padding-bottom: 5px;
        }
        .info-label {
            width: 140px;
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
            margin: 20px 0;
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
        .total {
            text-align: right;
            font-size: 1.2em;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
        .total strong { font-size: 1.3em; color: #006B3E; }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        .assinatura {
            text-align: center;
            width: 200px;
        }
        .assinatura-line {
            border-top: 1px solid #333;
            margin-top: 30px;
            padding-top: 5px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-pago { background: #28a745; color: white; }
        .status-pendente { background: #ffc107; color: #333; }
        .status-cancelado { background: #dc3545; color: white; }
        .codigo-transacao {
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
        }
        @media print {
            body { background: white; padding: 0; }
            .recibo-container { box-shadow: none; margin: 0; }
            .btn-print { display: none; }
        }
        .btn-print {
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 20px;
            font-size: 16px;
        }
        .btn-print:hover { background: #004d2e; }
        .destaque { font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
<div class="recibo-container">
    <div class="recibo">
        <div class="header">
            <h1><?php echo htmlspecialchars($recibo['escola_nome'] ?? 'Escola'); ?></h1>
            <p><?php echo htmlspecialchars($recibo['escola_endereco'] ?? ''); ?></p>
            <p>Tel: <?php echo htmlspecialchars($recibo['escola_telefone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($recibo['escola_email'] ?? ''); ?></p>
        </div>
        
        <div class="recibo-title">
            <h2>RECIBO DE PAGAMENTO</h2>
        </div>
        
        <div class="info-section">
            <div class="info-row">
                <div class="info-label">Nº da Fatura:</div>
                <div class="info-value"><?php echo $recibo['numero_fatura'] ?: str_pad($recibo['id'], 8, '0', STR_PAD_LEFT); ?></div>
            </div>
            <?php if ($recibo['numero_referencia']): ?>
            <div class="info-row">
                <div class="info-label">Nº de Referência:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['numero_referencia']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Data de Emissão:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($recibo['created_at'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <span class="status-badge status-<?php echo $recibo['status']; ?>">
                        <?php echo getStatusLabel($recibo['status']); ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Aluno:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['aluno_nome']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Matrícula:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['aluno_matricula']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Turma:</div>
                <div class="info-value"><?php echo ($recibo['turma_ano'] ?? '') . 'ª - ' . htmlspecialchars($recibo['turma_nome'] ?? '-'); ?></div>
            </div>
        </div>
        
        <table class="detalhes-table">
            <thead>
                <tr><th>Descrição</th><th width="150">Valor (KZ)</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($recibo['referente'] ?? getTipoPagamentoLabel($recibo['tipo_pagamento'])); ?></td>
                    <td><?php echo formatarMoeda($recibo['valor']); ?> KZ</strong><?php echo ''; ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="total">
            <span>Total Pago: </span>
            <strong><?php echo formatarMoeda($recibo['valor']); ?> KZ</strong>
        </div>
        
        <div class="info-section" style="margin-top: 20px;">
            <div class="info-row">
                <div class="info-label">Forma de Pagamento:</div>
                <div class="info-value"><?php echo getMetodoPagamentoLabel($recibo['metodo_pagamento']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Data Pagamento:</div>
                <div class="info-value"><?php echo $recibo['data_pagamento'] ? date('d/m/Y', strtotime($recibo['data_pagamento'])) : '-'; ?></div>
            </div>
            <?php if ($recibo['data_vencimento']): ?>
            <div class="info-row">
                <div class="info-label">Data Vencimento:</div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($recibo['data_vencimento'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($recibo['codigo_transacao']): ?>
            <div class="info-row">
                <div class="info-label">Código Transação:</div>
                <div class="info-value">
                    <span class="codigo-transacao"><?php echo htmlspecialchars($recibo['codigo_transacao']); ?></span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($recibo['comprovativo_numero']): ?>
            <div class="info-row">
                <div class="info-label">Nº Comprovante:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['comprovativo_numero']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Quem Pagou:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['quem_pagou'] ?? $recibo['aluno_nome']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Quem Recebeu:</div>
                <div class="info-value"><?php echo htmlspecialchars($recibo['quem_recebeu'] ?? $recibo['operador_nome'] ?? 'Sistema'); ?></div>
            </div>
            <?php if ($recibo['observacoes']): ?>
            <div class="info-row">
                <div class="info-label">Observações:</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($recibo['observacoes'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="assinaturas">
            <div class="assinatura">
                <div class="assinatura-line"></div>
                <div>Aluno/Responsável</div>
            </div>
            <div class="assinatura">
                <div class="assinatura-line"></div>
                <div><?php echo htmlspecialchars($recibo['quem_recebeu'] ?? 'Secretaria Escolar'); ?></div>
            </div>
        </div>
        
        <div class="footer">
            <p>Este recibo é comprovante de pagamento válido em todo território nacional.</p>
            <p>Emitido por sistema SIGE - Plataforma de Gestão Escolar</p>
            <?php if ($recibo['codigo_transacao']): ?>
            <p style="margin-top: 5px; font-size: 10px;">Código de autenticação: <?php echo htmlspecialchars($recibo['codigo_transacao']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div style="text-align: center; padding-bottom: 20px;">
        <button class="btn-print" onclick="window.print();"><i class="fas fa-print"></i> Imprimir Recibo</button>
    </div>
</div>

<script>
    // Imprimir automaticamente ao carregar (opcional - descomente se quiser)
    // window.print();
</script>
</body>
</html>