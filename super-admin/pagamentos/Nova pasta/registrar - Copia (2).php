<?php
// super-admin/pagamentos/registrar.php - Registrar pagamento e gerar comprovante
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
$comprovante_html = '';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    return 'KZ ' . number_format($valor, 2, ',', '.');
}

function formatarData($data) {
    return date('d/m/Y', strtotime($data));
}

function formatarNumeroComprovante($id, $escola_id, $data) {
    return 'CMP-' . date('Ymd', strtotime($data)) . '-' . str_pad($escola_id, 3, '0', STR_PAD_LEFT) . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

// Buscar escolas ativas com assinaturas
$escolas = $conn->query("
    SELECT e.id, e.nome, e.subdominio, e.email, e.logo, e.nuit,
           a.id as assinatura_id, a.valor, a.tipo_cobranca, a.data_fim,
           p.nome as plano_nome
    FROM escolas e
    JOIN assinaturas a ON a.escola_id = e.id
    JOIN planos p ON p.id = a.plano_id
    WHERE e.status IN ('ativa', 'trial') AND a.status = 'ativa'
    ORDER BY e.nome
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamento pendente específico
$pagamento = null;
if ($id > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, e.nome as escola_nome, e.subdominio, e.email as escola_email, e.nuit, e.logo,
               a.tipo_cobranca, a.plano_id, pl.nome as plano_nome
        FROM pagamentos p
        JOIN escolas e ON e.id = p.escola_id
        JOIN assinaturas a ON a.id = p.assinatura_id
        JOIN planos pl ON pl.id = a.plano_id
        WHERE p.id = :id AND p.status = 'pendente'
    ");
    $stmt->execute([':id' => $id]);
    $pagamento = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Processar registro de pagamento
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
    $gerar_comprovante = isset($_POST['gerar_comprovante']);
    $enviar_email = isset($_POST['enviar_email']);
    
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
        $conn->beginTransaction();
        
        // Buscar dados da escola e assinatura
        $stmt = $conn->prepare("
            SELECT e.*, a.data_fim, a.valor as assinatura_valor, a.tipo_cobranca, p.nome as plano_nome
            FROM escolas e
            JOIN assinaturas a ON a.escola_id = e.id
            JOIN planos p ON p.id = a.plano_id
            WHERE e.id = :escola_id AND a.id = :assinatura_id
        ");
        $stmt->execute([':escola_id' => $escola_id, ':assinatura_id' => $assinatura_id]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dados) {
            throw new Exception("Dados da escola ou assinatura não encontrados");
        }
        
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
            $pagamento_id = $id;
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
            $pagamento_id = $conn->lastInsertId();
        }
        
        // Atualizar status da escola para ativa
        $stmt = $conn->prepare("
            UPDATE escolas SET 
                status = 'ativa',
                updated_at = NOW()
            WHERE id = :escola_id AND status != 'ativa'
        ");
        $stmt->execute([':escola_id' => $escola_id]);
        
        // Atualizar data_fim da assinatura (renovar por +1 mês/ano)
        $tipo = $dados['tipo_cobranca'];
        $nova_data_fim = ($tipo == 'mensal') 
            ? date('Y-m-d', strtotime('+1 month', strtotime($dados['data_fim'])))
            : date('Y-m-d', strtotime('+1 year', strtotime($dados['data_fim'])));
        
        $stmt = $conn->prepare("
            UPDATE assinaturas SET
                data_fim = :data_fim,
                status = 'ativa',
                updated_at = NOW()
            WHERE id = :assinatura_id
        ");
        $stmt->execute([
            ':data_fim' => $nova_data_fim,
            ':assinatura_id' => $assinatura_id
        ]);
        
        $conn->commit();
        
        // Gerar comprovante HTML
        if ($gerar_comprovante) {
            $numero_comprovante = formatarNumeroComprovante($pagamento_id, $escola_id, $data_pagamento);
            
            $comprovante_html = '
            <!DOCTYPE html>
            <html lang="pt-AO">
            <head>
                <meta charset="UTF-8">
                <title>Comprovante de Pagamento - ' . htmlspecialchars($dados['nome']) . '</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 0;
                        padding: 20px;
                        background: #f0f2f5;
                    }
                    .comprovante-container {
                        max-width: 800px;
                        margin: 0 auto;
                        background: white;
                        border-radius: 15px;
                        box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                        overflow: hidden;
                    }
                    .header {
                        background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
                        color: white;
                        padding: 30px;
                        text-align: center;
                    }
                    .header .logo {
                        font-size: 3em;
                        margin-bottom: 10px;
                    }
                    .header h2 {
                        margin: 0;
                        font-size: 1.5em;
                    }
                    .header p {
                        margin: 5px 0 0;
                        opacity: 0.9;
                    }
                    .content {
                        padding: 30px;
                    }
                    .info-box {
                        background: #f8f9fa;
                        border-radius: 10px;
                        padding: 20px;
                        margin-bottom: 20px;
                        border-left: 4px solid #006B3E;
                    }
                    .info-row {
                        display: flex;
                        padding: 8px 0;
                        border-bottom: 1px solid #eee;
                    }
                    .info-label {
                        width: 180px;
                        font-weight: bold;
                        color: #555;
                    }
                    .info-value {
                        flex: 1;
                        color: #333;
                    }
                    .total-box {
                        background: #e8f5e9;
                        border-radius: 10px;
                        padding: 15px;
                        text-align: center;
                        margin: 20px 0;
                    }
                    .total-value {
                        font-size: 2em;
                        font-weight: bold;
                        color: #006B3E;
                    }
                    .footer {
                        background: #f8f9fa;
                        padding: 20px;
                        text-align: center;
                        font-size: 12px;
                        color: #666;
                        border-top: 1px solid #eee;
                    }
                    .status-pago {
                        display: inline-block;
                        padding: 5px 15px;
                        background: #28a745;
                        color: white;
                        border-radius: 20px;
                        font-weight: bold;
                    }
                    @media print {
                        body { background: white; padding: 0; }
                        .comprovante-container { box-shadow: none; }
                        .btn-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="comprovante-container">
                    <div class="header">
                        <div class="logo">
                            <i class="fas fa-chalkboard-user"></i>
                        </div>
                        <h2>SIGE Angola</h2>
                        <p>Sistema Integrado de Gestão Escolar</p>
                    </div>
                    <div class="content">
                        <div style="text-align: center; margin-bottom: 20px;">
                            <span class="status-pago">PAGAMENTO CONFIRMADO</span>
                        </div>
                        
                        <div class="info-box">
                            <div class="info-row">
                                <div class="info-label">Nº Comprovante:</div>
                                <div class="info-value"><strong>' . $numero_comprovante . '</strong></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Data de Emissão:</div>
                                <div class="info-value">' . formatarData($data_pagamento) . '</div>
                            </div>
                        </div>
                        
                        <h3 style="margin-bottom: 15px;">Dados da Escola</h3>
                        <div class="info-box">
                            <div class="info-row">
                                <div class="info-label">Escola:</div>
                                <div class="info-value">' . htmlspecialchars($dados['nome']) . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Subdomínio:</div>
                                <div class="info-value">' . $dados['subdominio'] . '.sige.ao</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">NUIT:</div>
                                <div class="info-value">' . ($dados['nuit'] ?: 'Não informado') . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">E-mail:</div>
                                <div class="info-value">' . htmlspecialchars($dados['email']) . '</div>
                            </div>
                        </div>
                        
                        <h3 style="margin-bottom: 15px;">Detalhes do Pagamento</h3>
                        <div class="info-box">
                            <div class="info-row">
                                <div class="info-label">Plano:</div>
                                <div class="info-value">' . htmlspecialchars($dados['plano_nome']) . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Tipo de Cobrança:</div>
                                <div class="info-value">' . ucfirst($dados['tipo_cobranca']) . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Referente:</div>
                                <div class="info-value">' . htmlspecialchars($referente) . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Método de Pagamento:</div>
                                <div class="info-value">' . ucfirst($metodo_pagamento) . '</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Data do Pagamento:</div>
                                <div class="info-value">' . formatarData($data_pagamento) . '</div>
                            </div>
                            ' . ($codigo_transacao ? '
                            <div class="info-row">
                                <div class="info-label">Código Transação:</div>
                                <div class="info-value">' . htmlspecialchars($codigo_transacao) . '</div>
                            </div>' : '') . '
                        </div>
                        
                        <div class="total-box">
                            <div>VALOR PAGO</div>
                            <div class="total-value">' . formatarMoeda($valor) . '</div>
                        </div>
                        
                        <div class="info-box">
                            <div class="info-row">
                                <div class="info-label">Próxima data de vencimento:</div>
                                <div class="info-value">' . formatarData($nova_data_fim) . '</div>
                            </div>
                        </div>
                    </div>
                    <div class="footer">
                        <p>Este comprovante é gerado eletronicamente e tem validade legal.</p>
                        <p>SIGE Angola - Sistema Integrado de Gestão Escolar | www.sige.ao</p>
                        <p>Documento emitido em ' . date('d/m/Y H:i:s') . '</p>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 20px;" class="btn-print">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #006B3E; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-print"></i> Imprimir Comprovante
                    </button>
                </div>
                <script>
                    document.querySelector(".btn-print button")?.addEventListener("click", () => window.print());
                </script>
            </body>
            </html>';
            
            // Salvar comprovante em arquivo
            $comprovante_dir = __DIR__ . '/../../uploads/comprovantes_pdf/';
            if (!is_dir($comprovante_dir)) mkdir($comprovante_dir, 0777, true);
            $comprovante_file = $comprovante_dir . $numero_comprovante . '.html';
            file_put_contents($comprovante_file, $comprovante_html);
        }
        
        // Enviar e-mail com comprovante
        if ($enviar_email && !empty($dados['email'])) {
            $email = new Email();
            $assunto = "Comprovante de Pagamento - SIGE Angola";
            $mensagem = "
                <h2>Olá {$dados['nome']}!</h2>
                <p>Seu pagamento foi confirmado com sucesso.</p>
                <div class='info-box'>
                    <strong>Detalhes:</strong><br>
                    Valor: " . formatarMoeda($valor) . "<br>
                    Data: " . formatarData($data_pagamento) . "<br>
                    Referente: {$referente}
                </div>
                <p>Em anexo segue o comprovante de pagamento.</p>
                <p>Atenciosamente,<br>Equipe SIGE Angola</p>
            ";
            $email->send($dados['email'], $assunto, $mensagem);
        }
        
        $success = "Pagamento registrado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'registrar_pagamento', 'pagamentos', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $pagamento_id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Buscar assinaturas da escola via AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_assinaturas') {
    $escola_id = $_GET['escola_id'] ?? 0;
    $stmt = $conn->prepare("
        SELECT a.id, a.valor, a.tipo_cobranca, a.data_fim, p.nome as plano_nome
        FROM assinaturas a
        JOIN planos p ON p.id = a.plano_id
        WHERE a.escola_id = :escola_id AND a.status = 'ativa'
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($assinaturas);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id > 0 ? 'Confirmar Pagamento' : 'Registrar Pagamento'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
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
        .comprovante-modal { max-width: 900px; }
        .comprovante-preview { max-height: 500px; overflow-y: auto; background: #f8f9fa; padding: 15px; border-radius: 10px; }
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
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-money-bill-wave"></i> <?php echo $id > 0 ? 'Confirmar Pagamento' : 'Registrar Pagamento'; ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Dados do Pagamento</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($comprovante_html): ?>
                <div class="alert alert-info">
                    <i class="fas fa-file-pdf"></i> Comprovante gerado com sucesso!
                    <button type="button" class="btn btn-sm btn-primary float-end" data-bs-toggle="modal" data-bs-target="#modalComprovante">
                        <i class="fas fa-eye"></i> Visualizar Comprovante
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                <form method="POST" enctype="multipart/form-data" id="formPagamento">
                    <?php if ($id > 0 && $pagamento): ?>
                        <!-- Pagamento existente -->
                        <div class="alert alert-info">
                            <strong>Escola:</strong> <?php echo htmlspecialchars($pagamento['escola_nome']); ?> (<?php echo $pagamento['subdominio']; ?>.sige.ao)<br>
                            <strong>Plano:</strong> <?php echo $pagamento['plano_nome']; ?><br>
                            <strong>Valor:</strong> <?php echo formatarMoeda($pagamento['valor']); ?><br>
                            <strong>Referente:</strong> <?php echo $pagamento['referente']; ?>
                        </div>
                        <input type="hidden" name="escola_id" value="<?php echo $pagamento['escola_id']; ?>">
                        <input type="hidden" name="assinatura_id" value="<?php echo $pagamento['assinatura_id']; ?>">
                        <input type="hidden" name="valor" value="<?php echo $pagamento['valor']; ?>">
                        <input type="hidden" name="referente" value="<?php echo $pagamento['referente']; ?>">
                        <input type="hidden" name="data_vencimento" value="<?php echo $pagamento['data_vencimento']; ?>">
                        
                    <?php else: ?>
                        <!-- Novo pagamento -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Escola *</label>
                                    <select name="escola_id" id="escola_id" class="form-control" required>
                                        <option value="">Selecione a escola...</option>
                                        <?php foreach ($escolas as $e): ?>
                                        <option value="<?php echo $e['id']; ?>" data-assinatura-id="<?php echo $e['assinatura_id']; ?>" data-valor="<?php echo $e['valor']; ?>" data-plano="<?php echo htmlspecialchars($e['plano_nome']); ?>">
                                            <?php echo htmlspecialchars($e['nome']); ?> (<?php echo $e['subdominio']; ?>.sige.ao) - <?php echo htmlspecialchars($e['plano_nome']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($escolas)): ?>
                                    <small class="text-danger">Nenhuma escola com assinatura ativa encontrada.</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Assinatura *</label>
                                    <select name="assinatura_id" id="assinatura_id" class="form-control" required disabled>
                                        <option value="">Primeiro selecione a escola</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Plano</label>
                                    <input type="text" id="plano_nome" class="form-control" readonly disabled>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Valor (KZ) *</label>
                                    <input type="text" name="valor" id="valor" class="form-control money" required readonly>
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
                                    <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
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
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check mb-3">
                                <input type="checkbox" name="gerar_comprovante" class="form-check-input" id="gerar_comprovante" value="1" checked>
                                <label class="form-check-label">
                                    <i class="fas fa-file-pdf"></i> Gerar comprovante de pagamento
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mb-3">
                                <input type="checkbox" name="enviar_email" class="form-check-input" id="enviar_email" value="1" checked>
                                <label class="form-check-label">
                                    <i class="fas fa-envelope"></i> Enviar comprovante por e-mail
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção:</strong> Após confirmar o pagamento, o status da escola será atualizado para "Ativa" e a assinatura será renovada automaticamente.
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-check-circle"></i> Confirmar Pagamento
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
    
    <!-- Modal Visualizar Comprovante -->
    <?php if ($comprovante_html): ?>
    <div class="modal fade" id="modalComprovante" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-pdf"></i> Comprovante de Pagamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body comprovante-preview">
                    <?php echo $comprovante_html; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Carregar assinaturas da escola selecionada
        $('#escola_id').change(function() {
            var escolaId = $(this).val();
            var selectedOption = $(this).find('option:selected');
            var assinaturaId = selectedOption.data('assinatura-id');
            var valor = selectedOption.data('valor');
            var planoNome = selectedOption.data('plano');
            
            if (escolaId && assinaturaId) {
                $('#assinatura_id').val(assinaturaId).prop('disabled', false);
                $('#valor').val(formatarMoedaValor(valor));
                $('#plano_nome').val(planoNome);
            } else if (escolaId) {
                // Buscar assinaturas via AJAX
                $.ajax({
                    url: 'registrar.php?ajax=get_assinaturas&escola_id=' + escolaId,
                    method: 'GET',
                    success: function(data) {
                        var assinaturas = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < assinaturas.length; i++) {
                            options += '<option value="' + assinaturas[i].id + '" data-valor="' + assinaturas[i].valor + '" data-plano="' + assinaturas[i].plano_nome + '">';
                            options += assinaturas[i].plano_nome + ' - ' + assinaturas[i].tipo_cobranca + ' (até ' + assinaturas[i].data_fim + ')</option>';
                        }
                        $('#assinatura_id').html(options).prop('disabled', false);
                    }
                });
            } else {
                $('#assinatura_id').html('<option value="">Primeiro selecione a escola</option>').prop('disabled', true);
                $('#valor').val('');
                $('#plano_nome').val('');
            }
        });
        
        // Atualizar valor quando selecionar assinatura
        $('#assinatura_id').change(function() {
            var selectedOption = $(this).find('option:selected');
            var valor = selectedOption.data('valor');
            var planoNome = selectedOption.data('plano');
            
            if (valor) {
                $('#valor').val(formatarMoedaValor(valor));
                $('#plano_nome').val(planoNome);
            }
        });
        
        // Formatar moeda
        function formatarMoedaValor(valor) {
            return parseFloat(valor).toLocaleString('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Formatar input monetário
        $('.money').on('input', function() {
            let value = this.value.replace(/[^0-9]/g, '');
            value = (parseInt(value) / 100).toFixed(2);
            this.value = value.replace('.', ',');
        });
    </script>
</body>
</html>