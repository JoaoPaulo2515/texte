<?php
// super-admin/pagamentos/registrar.php - Registrar pagamento manual
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$id = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Buscar pagamento pendente
$stmt = $conn->prepare("
    SELECT p.*, e.nome as escola_nome, e.subdominio, e.email as escola_email,
           a.tipo_cobranca, a.plano_id, pl.nome as plano_nome
    FROM pagamentos p
    JOIN escolas e ON e.id = p.escola_id
    JOIN assinaturas a ON a.id = p.assinatura_id
    JOIN planos pl ON pl.id = a.plano_id
    WHERE p.id = :id AND p.status = 'pendente'
");
$stmt->execute([':id' => $id]);
$pagamento = $stmt->fetch(PDO::FETCH_ASSOC);

// Se não encontrou pagamento específico, mostrar lista de pendentes
if (!$pagamento && $id > 0) {
    header('Location: index.php?error=Pagamento não encontrado ou já processado');
    exit;
}

// Buscar escolas para novo pagamento
$escolas = $conn->query("
    SELECT e.id, e.nome, e.subdominio, a.id as assinatura_id, a.valor, a.tipo_cobranca, pl.nome as plano_nome
    FROM escolas e
    JOIN assinaturas a ON a.escola_id = e.id
    JOIN planos pl ON pl.id = a.plano_id
    WHERE a.status = 'ativa'
    ORDER BY e.nome
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $escola_id = $_POST['escola_id'] ?? 0;
    $assinatura_id = $_POST['assinatura_id'] ?? 0;
    $valor = str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0'));
    $referente = $_POST['referente'] ?? date('F/Y');
    $data_pagamento = $_POST['data_pagamento'] ?? date('Y-m-d');
    $data_vencimento = $_POST['data_vencimento'] ?? date('Y-m-d');
    $metodo_pagamento = $_POST['metodo_pagamento'] ?? 'transferencia';
    $codigo_transacao = $_POST['codigo_transacao'] ?? '';
    $observacoes = $_POST['observacoes'] ?? '';
    
    // Upload do comprovante
    $comprovante = '';
    if (isset($_FILES['comprovante']) && $_FILES['comprovante']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/comprovantes/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = strtolower(pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($ext, $allowed)) {
            $comprovante = 'comp_' . time() . '_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['comprovante']['tmp_name'], $upload_dir . $comprovante);
        }
    }
    
    try {
        if ($id > 0) {
            // Atualizar pagamento existente
            $stmt = $conn->prepare("
                UPDATE pagamentos SET
                    status = 'pago',
                    data_pagamento = :data_pagamento,
                    metodo_pagamento = :metodo_pagamento,
                    codigo_transacao = :codigo_transacao,
                    comprovante = :comprovante,
                    observacoes = :observacoes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':data_pagamento' => $data_pagamento,
                ':metodo_pagamento' => $metodo_pagamento,
                ':codigo_transacao' => $codigo_transacao,
                ':comprovante' => $comprovante,
                ':observacoes' => $observacoes
            ]);
        } else {
            // Criar novo pagamento
            $stmt = $conn->prepare("
                INSERT INTO pagamentos (
                    escola_id, assinatura_id, valor, referente,
                    data_vencimento, data_pagamento, metodo_pagamento,
                    codigo_transacao, comprovante, observacoes, status, created_at
                ) VALUES (
                    :escola_id, :assinatura_id, :valor, :referente,
                    :data_vencimento, :data_pagamento, :metodo_pagamento,
                    :codigo_transacao, :comprovante, :observacoes, 'pago', NOW()
                )
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':assinatura_id' => $assinatura_id,
                ':valor' => $valor,
                ':referente' => $referente,
                ':data_vencimento' => $data_vencimento,
                ':data_pagamento' => $data_pagamento,
                ':metodo_pagamento' => $metodo_pagamento,
                ':codigo_transacao' => $codigo_transacao,
                ':comprovante' => $comprovante,
                ':observacoes' => $observacoes
            ]);
        }
        
        $success = "Pagamento registrado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'registrar_pagamento', 'pagamentos', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id > 0 ? $id : $conn->lastInsertId(),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        if ($id == 0) {
            header("refresh:2;url=index.php");
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id > 0 ? 'Registrar Pagamento' : 'Novo Pagamento'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
         <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../relatorios/" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-money-bill-wave"></i> <?php echo $id > 0 ? 'Registrar Pagamento' : 'Novo Pagamento Manual'; ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><?php echo $id > 0 ? 'Confirmar Recebimento' : 'Registrar Novo Pagamento'; ?></h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?> Redirecionando...</div><?php endif; ?>
                
                <?php if (!$success): ?>
                <form method="POST" enctype="multipart/form-data">
                    <?php if ($id > 0 && $pagamento): ?>
                        <div class="alert alert-info">
                            <strong>Escola:</strong> <?php echo htmlspecialchars($pagamento['escola_nome']); ?> (<?php echo $pagamento['subdominio']; ?>.sige.ao)<br>
                            <strong>Plano:</strong> <?php echo $pagamento['plano_nome']; ?><br>
                            <strong>Valor:</strong> KZ <?php echo number_format($pagamento['valor'], 2, ',', '.'); ?><br>
                            <strong>Referente:</strong> <?php echo $pagamento['referente']; ?>
                        </div>
                        <input type="hidden" name="escola_id" value="<?php echo $pagamento['escola_id']; ?>">
                        <input type="hidden" name="assinatura_id" value="<?php echo $pagamento['assinatura_id']; ?>">
                        <input type="hidden" name="valor" value="<?php echo $pagamento['valor']; ?>">
                        <input type="hidden" name="referente" value="<?php echo $pagamento['referente']; ?>">
                        <input type="hidden" name="data_vencimento" value="<?php echo $pagamento['data_vencimento']; ?>">
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Escola *</label>
                                    <select name="escola_id" class="form-control" required onchange="carregarAssinatura(this.value)">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($escolas as $e): ?>
                                        <option value="<?php echo $e['id']; ?>" data-assinatura="<?php echo $e['assinatura_id']; ?>" data-valor="<?php echo $e['valor']; ?>">
                                            <?php echo htmlspecialchars($e['nome']); ?> (<?php echo $e['subdominio']; ?>.sige.ao) - <?php echo $e['plano_nome']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Assinatura *</label>
                                    <select name="assinatura_id" class="form-control" id="assinatura_id" required>
                                        <option value="">Selecione a escola primeiro</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Valor (KZ) *</label>
                                    <input type="text" name="valor" class="form-control money" id="valor" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Referente *</label>
                                    <input type="text" name="referente" class="form-control" placeholder="Ex: Janeiro/2026" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Data de Vencimento</label>
                                    <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Data do Pagamento *</label>
                                <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Método de Pagamento</label>
                                <select name="metodo_pagamento" class="form-control">
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="transferencia">Transferência Bancária</option>
                                    <option value="deposito">Depósito</option>
                                    <option value="cartao">Cartão de Crédito/Débito</option>
                                    <option value="multicaixa">Multicaixa Express</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Código da Transação</label>
                                <input type="text" name="codigo_transacao" class="form-control" placeholder="Opcional">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Comprovante (PDF/Imagem)</label>
                                <input type="file" name="comprovante" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais sobre o pagamento..."></textarea>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-check"></i> Registrar Pagamento
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function carregarAssinatura(escolaId) {
            let select = $('select[name="escola_id"] option:selected');
            let assinaturaId = select.data('assinatura');
            let valor = select.data('valor');
            
            $('#assinatura_id').val(assinaturaId);
            $('#valor').val(valor.toLocaleString('pt-AO', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        }
        
        $('.money').on('input', function() {
            let value = this.value.replace(/[^0-9]/g, '');
            value = (parseInt(value) / 100).toFixed(2);
            this.value = value.replace('.', ',');
        });
    </script>
</body>
</html>