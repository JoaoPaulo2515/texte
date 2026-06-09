<?php
// escola/tesouraria/faturacao/visualizar_fatura.php - Visualizar Fatura

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'proforma';

if ($id <= 0) {
    header('Location: fatura_proforma.php?error=Fatura não encontrada');
    exit;
}

// Buscar dados da fatura
if ($tipo == 'proforma') {
    $sql = "SELECT fp.*, e.nome as estudante_nome, e.matricula, e.email, e.telefone, 
                   u.nome as usuario_nome, es.nome as escola_nome, es.endereco as escola_endereco, 
                   es.telefone as escola_telefone, es.email as escola_email, es.nuit as escola_nuit
            FROM faturas_proforma fp
            JOIN estudantes e ON e.id = fp.estudante_id
            JOIN usuarios u ON u.id = fp.usuario_id
            JOIN escolas es ON es.id = fp.escola_id
            WHERE fp.id = :id AND fp.escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fatura) {
        // Buscar itens da fatura
        $sql_itens = "SELECT * FROM fatura_proforma_itens WHERE fatura_id = :fatura_id";
        $stmt_itens = $conn->prepare($sql_itens);
        $stmt_itens->execute([':fatura_id' => $id]);
        $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $sql = "SELECT f.*, e.nome as estudante_nome, e.matricula, e.email, e.telefone, 
                   u.nome as usuario_nome, es.nome as escola_nome, es.endereco as escola_endereco, 
                   es.telefone as escola_telefone, es.email as escola_email, es.nuit as escola_nuit,
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
    }
}

if (!$fatura) {
    header('Location: fatura_proforma.php?error=Fatura não encontrada');
    exit;
}

// Funções auxiliares
function formatarMoedaFatura($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarNumeroFatura($numero, $tipo) {
    if ($tipo == 'proforma') {
        return 'PF-' . $numero;
    }
    return 'FT-' . $numero;
}

function getStatusFaturaTexto($status) {
    switch ($status) {
        case 'pendente': return 'Pendente';
        case 'aprovado': return 'Aprovado';
        case 'rejeitado': return 'Rejeitado';
        case 'convertida': return 'Convertida em Fatura';
        default: return ucfirst($status);
    }
}

function getStatusFaturaCor($status) {
    switch ($status) {
        case 'pendente': return '#ffc107';
        case 'aprovado': return '#28a745';
        case 'rejeitado': return '#dc3545';
        case 'convertida': return '#17a2b8';
        default: return '#6c757d';
    }
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
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tipo == 'proforma' ? 'Fatura Pró-Forma' : 'Factura'; ?> <?php echo $fatura['numero_fatura']; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .fatura-container {
            max-width: 1100px;
            margin: 0 auto;
        }
        
        .fatura-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .fatura-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 30px;
            position: relative;
        }
        
        .fatura-title {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .fatura-subtitle {
            opacity: 0.9;
        }
        
        .fatura-body {
            padding: 30px;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .info-title {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #006B3E;
        }
        
        .info-row {
            margin-bottom: 8px;
            display: flex;
        }
        
        .info-label {
            width: 120px;
            font-weight: 500;
            color: #666;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .table-fatura {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-fatura th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table-fatura td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table-fatura tfoot td {
            border-top: 2px solid #dee2e6;
            font-weight: bold;
        }
        
        .total-value {
            font-size: 1.2em;
            font-weight: bold;
            color: #006B3E;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
            color: white;
        }
        
        .btn-print {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-print:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-back {
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-back:hover {
            background: #004d2d;
            transform: translateY(-2px);
            color: white;
        }
        
        .btn-convert {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-convert:hover {
            background: #138496;
            transform: translateY(-2px);
        }
        
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            font-size: 0.85em;
            color: #6c757d;
            text-align: center;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .fatura-card {
                box-shadow: none;
            }
            .fatura-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .assinatura-area {
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
            margin: 30px auto 10px auto;
        }
        
        .qr-code {
            text-align: center;
            margin-top: 20px;
        }
        
        .qr-code img {
            width: 100px;
            height: 100px;
        }
    </style>
</head>
<body>
    <div class="fatura-container">
        <div class="fatura-card">
            <!-- Cabeçalho -->
            <div class="fatura-header">
                <div class="row">
                    <div class="col-md-8">
                        <div class="fatura-title">
                            <?php echo $tipo == 'proforma' ? 'FATURA PRÓ-FORMA' : 'FACTURA'; ?>
                        </div>
                        <div class="fatura-subtitle">
                            Nº: <?php echo htmlspecialchars($fatura['numero_fatura']); ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="mb-2">
                            <strong>Emissão:</strong> <?php echo getDataExtenso($fatura['data_emissao']); ?>
                        </div>
                        <div>
                            <strong>Validade:</strong> <?php echo getDataExtenso($fatura['data_validade']); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Corpo -->
            <div class="fatura-body">
                <!-- Informações da Empresa -->
                <div class="row info-section">
                    <div class="col-md-6">
                        <div class="info-title">
                            <i class="fas fa-building"></i> EMPRESA
                        </div>
                        <div class="info-row">
                            <div class="info-label">Nome:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['escola_nome']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Endereço:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['escola_endereco'] ?: 'Não informado'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Telefone:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['escola_telefone'] ?: 'Não informado'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['escola_email'] ?: 'Não informado'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">nuit:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['escola_nuit'] ?: 'Não informado'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-title">
                            <i class="fas fa-user-graduate"></i> CLIENTE
                        </div>
                        <div class="info-row">
                            <div class="info-label">Nome:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['estudante_nome']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Matrícula:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['matricula']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['email'] ?: 'Não informado'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Telefone:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['telefone'] ?: 'Não informado'); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Itens da Fatura -->
                <div class="info-title">
                    <i class="fas fa-list"></i> DISCRIMINAÇÃO
                </div>
                <table class="table-fatura">
                    <thead>
                        <tr>
                            <th>Descrição</th>
                            <th class="text-center">Qtd</th>
                            <th class="text-end">Valor Unitário</th>
                            <th class="text-end">Total</th>
                        </thead>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['descricao']); ?></td>
                            <td class="text-center"><?php echo number_format($item['quantidade'], 0, ',', '.'); ?></td>
                            <td class="text-end"><?php echo formatarMoedaFatura($item['valor_unitario']); ?></td>
                            <td class="text-end"><?php echo formatarMoedaFatura($item['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Subtotal:</strong></td>
                            <td class="text-end"><?php echo formatarMoedaFatura($fatura['subtotal']); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>IVA (14%):</strong></td>
                            <td class="text-end"><?php echo formatarMoedaFatura($fatura['iva']); ?></td>
                        </tr>
                        <?php if ($fatura['desconto'] > 0): ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Desconto:</strong></td>
                            <td class="text-end">- <?php echo formatarMoedaFatura($fatura['desconto']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>TOTAL:</strong></td>
                            <td class="text-end total-value"><?php echo formatarMoedaFatura($fatura['total']); ?></td>
                        </tr>
                    </tfoot>
                </table>
                
                <!-- Status -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="info-title">
                            <i class="fas fa-info-circle"></i> INFORMAÇÕES
                        </div>
                        <div class="info-row">
                            <div class="info-label">Status:</div>
                            <div class="info-value">
                                <span class="status-badge" style="background-color: <?php echo getStatusFaturaCor($fatura['status']); ?>">
                                    <?php echo getStatusFaturaTexto($fatura['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Emissor:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['usuario_nome']); ?></div>
                        </div>
                        <?php if ($tipo != 'proforma' && isset($fatura['fatura_proforma_numero'])): ?>
                        <div class="info-row">
                            <div class="info-label">Fatura Pró-Forma:</div>
                            <div class="info-value"><?php echo htmlspecialchars($fatura['fatura_proforma_numero']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if ($fatura['observacoes']): ?>
                        <div class="info-title">
                            <i class="fas fa-comment"></i> OBSERVAÇÕES
                        </div>
                        <p><?php echo nl2br(htmlspecialchars($fatura['observacoes'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Assinaturas -->
                <div class="assinatura-area">
                    <div class="assinatura">
                        <div class="linha-assinatura"></div>
                        <div class="assinatura-texto">Assinatura do Diretor</div>
                        <div class="assinatura-nome"><?php echo htmlspecialchars($fatura['usuario_nome']); ?></div>
                    </div>
                    <div class="assinatura">
                        <div class="linha-assinatura"></div>
                        <div class="assinatura-texto">Assinatura do Cliente</div>
                        <div class="assinatura-nome"><?php echo htmlspecialchars($fatura['estudante_nome']); ?></div>
                    </div>
                </div>
                
                <!-- Rodapé -->
                <div class="footer-note">
                    <p>Este documento é uma <?php echo $tipo == 'proforma' ? 'Fatura Pró-Forma' : 'Factura'; ?> emitida eletronicamente pelo Sistema SIGE Angola.</p>
                    <p><?php echo htmlspecialchars($fatura['escola_nome']); ?> - Todos os direitos reservados.</p>
                    <p>Documento emitido em <?php echo getDataExtenso($fatura['data_emissao']); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Botões de Ação -->
        <div class="text-center mt-4 no-print">
            <button onclick="window.print()" class="btn-print">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <a href="fatura_proforma.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            <?php if ($tipo == 'proforma' && $fatura['status'] == 'pendente'): ?>
            <a href="converter_fatura.php?id=<?php echo $id; ?>" class="btn-convert" onclick="return confirm('Deseja converter esta fatura pró-forma em fatura definitiva?')">
                <i class="fas fa-exchange-alt"></i> Converter em Factura
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>