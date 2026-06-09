<?php
// escola/tesouraria/faturacao/converter_fatura.php - Converter Fatura Pró-Forma em Factura

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
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: fatura_proforma.php?error=Fatura não encontrada');
    exit;
}

// Buscar dados da fatura pró-forma
$sql = "SELECT fp.*, e.nome as estudante_nome, e.matricula, e.email, e.telefone,
               e.endereco as estudante_endereco, e.bi
        FROM faturas_proforma fp
        JOIN estudantes e ON e.id = fp.estudante_id
        WHERE fp.id = :id AND fp.escola_id = :escola_id AND fp.status = 'pendente'";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$fatura_proforma = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fatura_proforma) {
    header('Location: fatura_proforma.php?error=Fatura não encontrada ou já convertida');
    exit;
}

// Buscar itens da fatura pró-forma
$sql_itens = "SELECT * FROM fatura_proforma_itens WHERE fatura_id = :fatura_id";
$stmt_itens = $conn->prepare($sql_itens);
$stmt_itens->execute([':fatura_id' => $id]);
$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';
$numero_factura = '';

// Processar conversão
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_conversao'])) {
    $data_emissao = $_POST['data_emissao'] ?? date('Y-m-d');
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    try {
        $conn->beginTransaction();
        
        // Gerar número da factura
        $numero_factura = 'FT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Inserir factura
        $sql_insert = "INSERT INTO facturas (
                            escola_id, numero_factura, fatura_proforma_id, estudante_id,
                            data_emissao, subtotal, iva, desconto, total, observacoes,
                            usuario_id, created_at
                        ) VALUES (
                            :escola_id, :numero_factura, :fatura_proforma_id, :estudante_id,
                            :data_emissao, :subtotal, :iva, :desconto, :total, :observacoes,
                            :usuario_id, NOW()
                        )";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([
            ':escola_id' => $escola_id,
            ':numero_factura' => $numero_factura,
            ':fatura_proforma_id' => $id,
            ':estudante_id' => $fatura_proforma['estudante_id'],
            ':data_emissao' => $data_emissao,
            ':subtotal' => $fatura_proforma['subtotal'],
            ':iva' => $fatura_proforma['iva'],
            ':desconto' => $fatura_proforma['desconto'],
            ':total' => $fatura_proforma['total'],
            ':observacoes' => $observacoes ?: $fatura_proforma['observacoes'],
            ':usuario_id' => $usuario_id
        ]);
        $factura_id = $conn->lastInsertId();
        
        // Inserir itens da factura
        foreach ($itens as $item) {
            $sql_item = "INSERT INTO factura_itens (
                                factura_id, descricao, quantidade, valor_unitario, total
                            ) VALUES (
                                :factura_id, :descricao, :quantidade, :valor_unitario, :total
                            )";
            $stmt_item = $conn->prepare($sql_item);
            $stmt_item->execute([
                ':factura_id' => $factura_id,
                ':descricao' => $item['descricao'],
                ':quantidade' => $item['quantidade'],
                ':valor_unitario' => $item['valor_unitario'],
                ':total' => $item['total']
            ]);
        }
        
        // Atualizar status da fatura pró-forma
        $sql_update = "UPDATE faturas_proforma SET status = 'convertida', updated_at = NOW() WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $id]);
        
        // Registrar no caixa? (opcional - dependendo da política)
        // Se a fatura já foi paga, pode registrar no caixa aqui
        
        $conn->commit();
        $success = "Fatura convertida com sucesso! Número da factura: $numero_factura";
        
        // Redirecionar para visualizar a factura
        header("Location: visualizar_fatura.php?id=$factura_id&tipo=factura&msg=success");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao converter fatura: " . $e->getMessage();
    }
}

// Funções auxiliares
function formatarMoedaFatura($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Converter Fatura | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .container-converter {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 20px;
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .info-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-title {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
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
        
        .table-converter {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-converter th {
            background: #e9ecef;
            padding: 10px;
            text-align: left;
        }
        
        .table-converter td {
            padding: 8px 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .total-value {
            font-weight: bold;
            font-size: 1.1em;
            color: #006B3E;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
        }
        
        .btn-primary:hover {
            background: #004d2d;
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            border-radius: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
            background: #ffc107;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container-converter">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-exchange-alt"></i> Converter Fatura Pró-Forma em Factura
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Informações da Fatura Pró-Forma -->
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-file-invoice"></i> Dados da Fatura Pró-Forma
                    </div>
                    <div class="info-row">
                        <div class="info-label">Número:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($fatura_proforma['numero_fatura']); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data Emissão:</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($fatura_proforma['data_emissao'])); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Validade:</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($fatura_proforma['data_validade'])); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value"><span class="status-badge"><?php echo ucfirst($fatura_proforma['status']); ?></span></div>
                    </div>
                </div>
                
                <!-- Informações do Cliente -->
                <div class="info-section">
                    <div class="info-title">
                        <i class="fas fa-user-graduate"></i> Estudante
                    </div>
                    <div class="info-row">
                        <div class="info-label">Nome:</div>
                        <div class="info-value"><?php echo htmlspecialchars($fatura_proforma['estudante_nome']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Matrícula:</div>
                        <div class="info-value"><?php echo htmlspecialchars($fatura_proforma['matricula']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value"><?php echo htmlspecialchars($fatura_proforma['email'] ?: 'Não informado'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Telefone:</div>
                        <div class="info-value"><?php echo htmlspecialchars($fatura_proforma['telefone'] ?: 'Não informado'); ?></div>
                    </div>
                </div>
                
                <!-- Itens -->
                <div class="info-title mb-3">
                    <i class="fas fa-list"></i> Itens da Fatura
                </div>
                <table class="table-converter">
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
                            <td class="text-end"><?php echo formatarMoedaFatura($fatura_proforma['subtotal']); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>IVA (14%):</strong></td>
                            <td class="text-end"><?php echo formatarMoedaFatura($fatura_proforma['iva']); ?></td>
                        </tr>
                        <?php if ($fatura_proforma['desconto'] > 0): ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Desconto:</strong></td>
                            <td class="text-end">- <?php echo formatarMoedaFatura($fatura_proforma['desconto']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>TOTAL:</strong></td>
                            <td class="text-end total-value"><?php echo formatarMoedaFatura($fatura_proforma['total']); ?></td>
                        </tr>
                    </tfoot>
                </table>
                
                <!-- Formulário de Conversão -->
                <form method="POST" class="mt-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data de Emissão da Factura</label>
                            <input type="date" name="data_emissao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Observações (opcional)</label>
                            <textarea name="observacoes" class="form-control" rows="1" placeholder="Informações adicionais..."><?php echo htmlspecialchars($fatura_proforma['observacoes']); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção!</strong> Após a conversão, a fatura pró-forma será marcada como "Convertida" e não poderá ser mais modificada. 
                        A nova factura terá numeração fiscal sequencial.
                    </div>
                    
                    <div class="text-center mt-4">
                        <a href="fatura_proforma.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" name="confirmar_conversao" class="btn btn-primary ms-2">
                            <i class="fas fa-check"></i> Confirmar Conversão
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>