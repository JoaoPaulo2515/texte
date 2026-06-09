<?php
// super-admin/pagamentos/registrar.php - Registrar pagamento com comprovante PDF
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
$comprovante_pdf = '';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    // Limpar o valor: remover espaços, caracteres especiais e converter para número
    $valor = preg_replace('/[^0-9,.-]/', '', $valor);
    $valor = str_replace(',', '.', str_replace('.', '', $valor));
    $valor = floatval($valor);
    return 'KZ ' . number_format($valor, 2, ',', '.');
}

function formatarData($data) {
    return date('d/m/Y', strtotime($data));
}

function formatarNumeroComprovante($id, $escola_id, $data) {
    return 'CMP-' . date('Ymd', strtotime($data)) . '-' . str_pad($escola_id, 3, '0', STR_PAD_LEFT) . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

function enviarWhatsApp($telefone, $mensagem) {
    if (empty($telefone)) return false;
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        $telefone = '244' . $telefone;
    }
    $mensagem = urlencode($mensagem);
    $link = "https://wa.me/{$telefone}?text={$mensagem}";
    error_log("WhatsApp: {$link}");
    return true;
}
function enviarSMS($telefone, $mensagem) {
    // Integração com API de SMS (ex: Twilio, Clickatell)
    // Por enquanto, retorna true simulando envio
    return true;
}
// Processar AJAX para buscar assinaturas
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_assinaturas') {
    header('Content-Type: application/json');
    $escola_id = $_GET['escola_id'] ?? 0;
    
    if ($escola_id > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT a.id, a.valor, a.tipo_cobranca, a.data_fim, 
                       p.nome as plano_nome,
                       CASE 
                           WHEN a.data_fim < CURDATE() THEN 'Expirada'
                           WHEN DATEDIFF(a.data_fim, CURDATE()) <= 7 THEN 'A expirar'
                           ELSE 'Ativa'
                       END as status_texto
                FROM assinaturas a
                JOIN planos p ON p.id = a.plano_id
                WHERE a.escola_id = :escola_id AND a.status = 'ativa'
                ORDER BY a.created_at DESC
            ");
            $stmt->execute([':escola_id' => $escola_id]);
            $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $assinaturas,
                'count' => count($assinaturas)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'ID da escola não informado'
        ]);
    }
    exit;
}

// Buscar escolas ativas com assinaturas
$escolas = $conn->query("
    SELECT e.id, e.nome, e.subdominio, e.email, e.logo, e.nuit, e.telefone, e.celular,
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
        SELECT p.*, e.nome as escola_nome, e.subdominio, e.email as escola_email, e.nuit, e.logo, e.telefone, e.celular,
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
    $enviar_whatsapp = isset($_POST['enviar_whatsapp']);
    
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
        
        // Atualizar data_fim da assinatura
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
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .comprovante-container { max-width: 800px; margin: 0 auto; background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); overflow: hidden; }
                    .header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 30px; text-align: center; }
                    .header .logo { font-size: 3em; margin-bottom: 10px; }
                    .content { padding: 30px; }
                    .info-box { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #006B3E; }
                    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #eee; }
                    .info-label { width: 180px; font-weight: bold; color: #555; }
                    .info-value { flex: 1; color: #333; }
                    .total-box { background: #e8f5e9; border-radius: 10px; padding: 15px; text-align: center; margin: 20px 0; }
                    .total-value { font-size: 2em; font-weight: bold; color: #006B3E; }
                    .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #eee; }
                    .status-pago { display: inline-block; padding: 5px 15px; background: #28a745; color: white; border-radius: 20px; font-weight: bold; }
                    @media print { body { background: white; padding: 0; } .comprovante-container { box-shadow: none; } }
                </style>
            </head>
            <body>
                <div class="comprovante-container">
                    <div class="header">
                        <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
                        <h2>SIGE Angola</h2>
                        <p>Sistema Integrado de Gestão Escolar</p>
                    </div>
                    <div class="content">
                        <div style="text-align: center; margin-bottom: 20px;"><span class="status-pago">PAGAMENTO CONFIRMADO</span></div>
                        <div class="info-box">
                            <div class="info-row"><div class="info-label">Nº Comprovante:</div><div class="info-value"><strong>' . $numero_comprovante . '</strong></div></div>
                            <div class="info-row"><div class="info-label">Data de Emissão:</div><div class="info-value">' . formatarData($data_pagamento) . '</div></div>
                        </div>
                        <h3>Dados da Escola</h3>
                        <div class="info-box">
                            <div class="info-row"><div class="info-label">Escola:</div><div class="info-value">' . htmlspecialchars($dados['nome']) . '</div></div>
                            <div class="info-row"><div class="info-label">Subdomínio:</div><div class="info-value">' . $dados['subdominio'] . '.sige.ao</div></div>
                            <div class="info-row"><div class="info-label">NUIT:</div><div class="info-value">' . ($dados['nuit'] ?: 'Não informado') . '</div></div>
                        </div>
                        <h3>Detalhes do Pagamento</h3>
                        <div class="info-box">
                            <div class="info-row"><div class="info-label">Plano:</div><div class="info-value">' . htmlspecialchars($dados['plano_nome']) . '</div></div>
                            <div class="info-row"><div class="info-label">Referente:</div><div class="info-value">' . htmlspecialchars($referente) . '</div></div>
                            <div class="info-row"><div class="info-label">Método:</div><div class="info-value">' . ucfirst($metodo_pagamento) . '</div></div>
                            <div class="info-row"><div class="info-label">Data:</div><div class="info-value">' . formatarData($data_pagamento) . '</div></div>
                        </div>
                        <div class="total-box"><div>VALOR PAGO</div><div class="total-value">' . formatarMoeda($valor) . '</div></div>
                        <div class="info-box"><div class="info-row"><div class="info-label">Próxima data de vencimento:</div><div class="info-value">' . formatarData($nova_data_fim) . '</div></div></div>
                    </div>
                    <div class="footer">
                        <p>SIGE Angola - Sistema Integrado de Gestão Escolar | www.sige.ao</p>
                        <p>Documento emitido em ' . date('d/m/Y H:i:s') . '</p>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 20px;" class="btn-print no-print">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #006B3E; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-print"></i> Imprimir Comprovante
                    </button>
                </div>
            </body>
            </html>';
            
            $comprovante_dir = __DIR__ . '/../../uploads/comprovantes_pdf/';
            if (!is_dir($comprovante_dir)) mkdir($comprovante_dir, 0777, true);
            $comprovante_file = $comprovante_dir . $numero_comprovante . '.html';
            file_put_contents($comprovante_file, $comprovante_html);
        }
        
        // Enviar por e-mail
        if ($enviar_email && !empty($dados['email'])) {
            $assunto = "Comprovante de Pagamento - SIGE Angola";
            $mensagem_email = "<h2>Olá {$dados['nome']}!</h2><p>Seu pagamento foi confirmado.</p><p>Valor: " . formatarMoeda($valor) . "<br>Data: " . formatarData($data_pagamento) . "<br>Comprovante: {$numero_comprovante}</p>";
            mail($dados['email'], $assunto, $mensagem_email, "Content-Type: text/html; charset=utf-8\r\nFrom: SIGE Angola <noreply@sige.ao>\r\n");
        }
        
        // Enviar por WhatsApp
        if ($enviar_whatsapp && !empty($dados['celular'])) {
            $mensagem_whatsapp = "🏫 SIGE Angola - Pagamento Confirmado\n\nEscola: {$dados['nome']}\nValor: " . formatarMoeda($valor) . "\nData: " . formatarData($data_pagamento) . "\nComprovante: {$numero_comprovante}";
            enviarWhatsApp($dados['celular'], $mensagem_whatsapp);
        }
        
        $success = "Pagamento registrado com sucesso!";
        $comprovante_pdf = $comprovante_file;
        
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
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id > 0 ? 'Confirmar Pagamento' : 'Registrar Pagamento'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Navegação */
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 20px;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        
        .loading { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #006B3E; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
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
            <div class="user-info-sidebar">
                <small><i class="fas fa-user-shield"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <!-- Configurações com Submenu -->
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-cog"></i> Configurações</a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../config/sistema.php" class="nav-link"><i class="fas fa-globe"></i> Configurações do Sistema</a></li>
                    <li class="nav-item"><a href="../config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões e Papéis</a></li>
                </ul>
            </li>
            
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
                                        <option value="<?php echo $e['id']; ?>" 
                                                data-assinatura-id="<?php echo $e['assinatura_id']; ?>" 
                                                data-valor="<?php echo $e['valor']; ?>" 
                                                data-plano="<?php echo htmlspecialchars($e['plano_nome']); ?>"
                                                data-tipo="<?php echo $e['tipo_cobranca']; ?>"
                                                data-data-fim="<?php echo $e['data_fim']; ?>">
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
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label>Assinatura *</label>
                                    <select name="assinatura_id" id="assinatura_id" class="form-control" required disabled>
                                        <option value="">Primeiro selecione a escola</option>
                                    </select>
                                    <div id="loading-assinaturas" style="display: none;">
                                        <span class="loading"></span> Carregando assinaturas...
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
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
                                    <input type="text" name="valor" id="valor" class="form-control" required readonly>
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
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label><i class="fas fa-bell"></i> Opções de Notificação</label>
                            <div class="form-check">
                                <input type="checkbox" name="gerar_comprovante" class="form-check-input" id="gerar_comprovante" value="1" checked>
                                <label class="form-check-label"><i class="fas fa-file-pdf"></i> Gerar comprovante de pagamento</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="enviar_email" class="form-check-input" id="enviar_email" value="1" checked>
                                <label class="form-check-label"><i class="fas fa-envelope"></i> Enviar comprovante por e-mail</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="enviar_whatsapp" class="form-check-input" id="enviar_whatsapp" value="1">
                                <label class="form-check-label"><i class="fab fa-whatsapp"></i> Enviar comprovante por WhatsApp</label>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Carregar assinaturas
        $('#escola_id').change(function() {
            var escolaId = $(this).val();
            
            if (escolaId) {
                $('#loading-assinaturas').show();
                $('#assinatura_id').html('<option value="">Carregando...</option>').prop('disabled', true);
                $('#valor').val('');
                $('#plano_nome').val('');
                
                $.ajax({
                    url: 'registrar.php?ajax=get_assinaturas&escola_id=' + escolaId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        $('#loading-assinaturas').hide();
                        
                        if (response.success) {
                            var assinaturas = response.data;
                            var options = '<option value="">Selecione a assinatura...</option>';
                            
                            if (assinaturas.length === 0) {
                                options = '<option value="">Nenhuma assinatura ativa encontrada</option>';
                                $('#assinatura_id').html(options).prop('disabled', true);
                            } else {
                                for (var i = 0; i < assinaturas.length; i++) {
                                    var a = assinaturas[i];
                                    options += '<option value="' + a.id + '" data-valor="' + a.valor + '" data-plano="' + a.plano_nome + '" data-tipo="' + a.tipo_cobranca + '" data-fim="' + a.data_fim + '">';
                                    options += a.plano_nome + ' - ' + a.tipo_cobranca + ' - KZ ' + parseFloat(a.valor).toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' (vence: ' + formatarData(a.data_fim) + ')';
                                    options += '</option>';
                                }
                                $('#assinatura_id').html(options).prop('disabled', false);
                            }
                        } else {
                            $('#assinatura_id').html('<option value="">Erro ao carregar assinaturas</option>').prop('disabled', true);
                        }
                    },
                    error: function() {
                        $('#loading-assinaturas').hide();
                        $('#assinatura_id').html('<option value="">Erro ao carregar assinaturas</option>').prop('disabled', true);
                    }
                });
            } else {
                $('#assinatura_id').html('<option value="">Primeiro selecione a escola</option>').prop('disabled', true);
                $('#valor').val('');
                $('#plano_nome').val('');
            }
        });
        
        $('#assinatura_id').change(function() {
            var selectedOption = $(this).find('option:selected');
            var valor = selectedOption.data('valor');
            var planoNome = selectedOption.data('plano');
            
            if (valor) {
                $('#valor').val(parseFloat(valor).toLocaleString('pt-AO', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('#plano_nome').val(planoNome);
            } else {
                $('#valor').val('');
                $('#plano_nome').val('');
            }
        });
        
        function formatarData(data) {
            if (!data) return '';
            var partes = data.split('-');
            return partes[2] + '/' + partes[1] + '/' + partes[0];
        }
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatorios').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>