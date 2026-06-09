<?php
// super-admin/config/sistema.php - Configurações Gerais do Sistema
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar configurações atuais
$stmt = $conn->query("SELECT * FROM configuracoes_sistema LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    // Configurações padrão
    $config = [
        'id' => 1,
        'nome_sistema' => 'SIGE Angola',
        'sigla' => 'SIGE',
        'versao' => '2.0.0',
        'email_geral' => 'contato@sige.ao',
        'telefone' => '+244 923 456 789',
        'whatsapp' => '+244 923 456 789',
        'endereco' => 'Luanda, Angola',
        'logo' => '',
        'favicon' => '',
        'timezone' => 'Africa/Luanda',
        'moeda' => 'KZ',
        'moeda_simbolo' => 'KZ',
        'ano_letivo_atual' => date('Y'),
        'bimestre_atual' => 1,
        'nota_maxima' => 20,
        'nota_minima_aprovacao' => 10,
        'permite_recuperacao' => 1,
        'limite_faltas' => 20,
        'enviar_email' => 1,
        'email_host' => 'smtp.gmail.com',
        'email_porta' => 587,
        'email_seguranca' => 'tls',
        'email_usuario' => '',
        'email_senha' => '',
        'email_remetente' => 'noreply@sige.ao',
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
        'manutencao' => 0,
        'manutencao_mensagem' => 'Sistema em manutenção. Voltamos em breve!',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_sistema = $_POST['nome_sistema'] ?? 'SIGE Angola';
    $sigla = $_POST['sigla'] ?? 'SIGE';
    $email_geral = $_POST['email_geral'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $whatsapp = $_POST['whatsapp'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $timezone = $_POST['timezone'] ?? 'Africa/Luanda';
    $moeda = $_POST['moeda'] ?? 'KZ';
    $moeda_simbolo = $_POST['moeda_simbolo'] ?? 'KZ';
    $ano_letivo_atual = $_POST['ano_letivo_atual'] ?? date('Y');
    $bimestre_atual = $_POST['bimestre_atual'] ?? 1;
    $nota_maxima = $_POST['nota_maxima'] ?? 20;
    $nota_minima_aprovacao = $_POST['nota_minima_aprovacao'] ?? 10;
    $permite_recuperacao = isset($_POST['permite_recuperacao']) ? 1 : 0;
    $limite_faltas = $_POST['limite_faltas'] ?? 20;
    $enviar_email = isset($_POST['enviar_email']) ? 1 : 0;
    $email_host = $_POST['email_host'] ?? '';
    $email_porta = $_POST['email_porta'] ?? 587;
    $email_seguranca = $_POST['email_seguranca'] ?? 'tls';
    $email_usuario = $_POST['email_usuario'] ?? '';
    $email_senha = $_POST['email_senha'] ?? '';
    $email_remetente = $_POST['email_remetente'] ?? 'noreply@sige.ao';
    $recaptcha_site_key = $_POST['recaptcha_site_key'] ?? '';
    $recaptcha_secret_key = $_POST['recaptcha_secret_key'] ?? '';
    $manutencao = isset($_POST['manutencao']) ? 1 : 0;
    $manutencao_mensagem = $_POST['manutencao_mensagem'] ?? '';
    
    // Upload da logo
    $logo = $config['logo'];
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/config/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $logo = 'logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo);
            
            // Remover logo antiga
            if ($config['logo'] && file_exists($upload_dir . $config['logo'])) {
                unlink($upload_dir . $config['logo']);
            }
        }
    }
    
    // Upload do favicon
    $favicon = $config['favicon'];
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/config/';
        $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
        if ($ext == 'ico' || $ext == 'png') {
            $favicon = 'favicon_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_dir . $favicon);
            
            if ($config['favicon'] && file_exists($upload_dir . $config['favicon'])) {
                unlink($upload_dir . $config['favicon']);
            }
        }
    }
    
    try {
        // Verificar se a tabela existe, se não, criar
        $stmt = $conn->query("SHOW TABLES LIKE 'configuracoes_sistema'");
        if ($stmt->rowCount() == 0) {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS `configuracoes_sistema` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `nome_sistema` VARCHAR(100) NOT NULL DEFAULT 'SIGE Angola',
                    `sigla` VARCHAR(20) DEFAULT 'SIGE',
                    `versao` VARCHAR(20) DEFAULT '2.0.0',
                    `email_geral` VARCHAR(100) NULL,
                    `telefone` VARCHAR(20) NULL,
                    `whatsapp` VARCHAR(20) NULL,
                    `endereco` TEXT NULL,
                    `logo` VARCHAR(255) NULL,
                    `favicon` VARCHAR(255) NULL,
                    `timezone` VARCHAR(50) DEFAULT 'Africa/Luanda',
                    `moeda` VARCHAR(10) DEFAULT 'KZ',
                    `moeda_simbolo` VARCHAR(10) DEFAULT 'KZ',
                    `ano_letivo_atual` YEAR DEFAULT NULL,
                    `bimestre_atual` INT DEFAULT 1,
                    `nota_maxima` INT DEFAULT 20,
                    `nota_minima_aprovacao` INT DEFAULT 10,
                    `permite_recuperacao` BOOLEAN DEFAULT TRUE,
                    `limite_faltas` INT DEFAULT 20,
                    `enviar_email` BOOLEAN DEFAULT TRUE,
                    `email_host` VARCHAR(100) NULL,
                    `email_porta` INT DEFAULT 587,
                    `email_seguranca` VARCHAR(10) DEFAULT 'tls',
                    `email_usuario` VARCHAR(100) NULL,
                    `email_senha` VARCHAR(255) NULL,
                    `email_remetente` VARCHAR(100) NULL,
                    `recaptcha_site_key` VARCHAR(100) NULL,
                    `recaptcha_secret_key` VARCHAR(100) NULL,
                    `manutencao` BOOLEAN DEFAULT FALSE,
                    `manutencao_mensagem` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
                )
            ");
        }
        
        $stmt = $conn->prepare("
            INSERT INTO configuracoes_sistema (
                id, nome_sistema, sigla, email_geral, telefone, whatsapp, endereco,
                logo, favicon, timezone, moeda, moeda_simbolo, ano_letivo_atual,
                bimestre_atual, nota_maxima, nota_minima_aprovacao, permite_recuperacao,
                limite_faltas, enviar_email, email_host, email_porta, email_seguranca,
                email_usuario, email_senha, email_remetente, recaptcha_site_key,
                recaptcha_secret_key, manutencao, manutencao_mensagem, updated_at
            ) VALUES (
                1, :nome_sistema, :sigla, :email_geral, :telefone, :whatsapp, :endereco,
                :logo, :favicon, :timezone, :moeda, :moeda_simbolo, :ano_letivo_atual,
                :bimestre_atual, :nota_maxima, :nota_minima_aprovacao, :permite_recuperacao,
                :limite_faltas, :enviar_email, :email_host, :email_porta, :email_seguranca,
                :email_usuario, :email_senha, :email_remetente, :recaptcha_site_key,
                :recaptcha_secret_key, :manutencao, :manutencao_mensagem, NOW()
            ) ON DUPLICATE KEY UPDATE
                nome_sistema = VALUES(nome_sistema),
                sigla = VALUES(sigla),
                email_geral = VALUES(email_geral),
                telefone = VALUES(telefone),
                whatsapp = VALUES(whatsapp),
                endereco = VALUES(endereco),
                logo = VALUES(logo),
                favicon = VALUES(favicon),
                timezone = VALUES(timezone),
                moeda = VALUES(moeda),
                moeda_simbolo = VALUES(moeda_simbolo),
                ano_letivo_atual = VALUES(ano_letivo_atual),
                bimestre_atual = VALUES(bimestre_atual),
                nota_maxima = VALUES(nota_maxima),
                nota_minima_aprovacao = VALUES(nota_minima_aprovacao),
                permite_recuperacao = VALUES(permite_recuperacao),
                limite_faltas = VALUES(limite_faltas),
                enviar_email = VALUES(enviar_email),
                email_host = VALUES(email_host),
                email_porta = VALUES(email_porta),
                email_seguranca = VALUES(email_seguranca),
                email_usuario = VALUES(email_usuario),
                email_senha = VALUES(email_senha),
                email_remetente = VALUES(email_remetente),
                recaptcha_site_key = VALUES(recaptcha_site_key),
                recaptcha_secret_key = VALUES(recaptcha_secret_key),
                manutencao = VALUES(manutencao),
                manutencao_mensagem = VALUES(manutencao_mensagem),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            ':nome_sistema' => $nome_sistema,
            ':sigla' => $sigla,
            ':email_geral' => $email_geral,
            ':telefone' => $telefone,
            ':whatsapp' => $whatsapp,
            ':endereco' => $endereco,
            ':logo' => $logo,
            ':favicon' => $favicon,
            ':timezone' => $timezone,
            ':moeda' => $moeda,
            ':moeda_simbolo' => $moeda_simbolo,
            ':ano_letivo_atual' => $ano_letivo_atual,
            ':bimestre_atual' => $bimestre_atual,
            ':nota_maxima' => $nota_maxima,
            ':nota_minima_aprovacao' => $nota_minima_aprovacao,
            ':permite_recuperacao' => $permite_recuperacao,
            ':limite_faltas' => $limite_faltas,
            ':enviar_email' => $enviar_email,
            ':email_host' => $email_host,
            ':email_porta' => $email_porta,
            ':email_seguranca' => $email_seguranca,
            ':email_usuario' => $email_usuario,
            ':email_senha' => !empty($email_senha) ? $email_senha : $config['email_senha'],
            ':email_remetente' => $email_remetente,
            ':recaptcha_site_key' => $recaptcha_site_key,
            ':recaptcha_secret_key' => $recaptcha_secret_key,
            ':manutencao' => $manutencao,
            ':manutencao_mensagem' => $manutencao_mensagem
        ]);
        
        $success = "Configurações salvas com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, ip, created_at)
            VALUES (:usuario_id, 'atualizar_config_sistema', 'configuracoes_sistema', :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Recarregar configurações
        $stmt = $conn->query("SELECT * FROM configuracoes_sistema LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Timezones disponíveis
$timezones = [
    'Africa/Luanda' => 'Luanda (Angola)',
    'Africa/Lagos' => 'Lagos (Nigéria)',
    'Africa/Johannesburg' => 'Joanesburgo (África do Sul)',
    'Europe/Lisbon' => 'Lisboa (Portugal)',
    'America/Sao_Paulo' => 'São Paulo (Brasil)'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações do Sistema | SIGE Angola</title>
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
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .logo-preview { width: 80px; height: 80px; border-radius: 10px; object-fit: cover; border: 2px solid #006B3E; }
        .favicon-preview { width: 32px; height: 32px; object-fit: cover; }
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
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../relatorios/" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="sistema.php" class="nav-link active"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-cog"></i> Configurações do Sistema</h2>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="configTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#geral" type="button" role="tab">Geral</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#academico" type="button" role="tab">Acadêmico</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">E-mail</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#seguranca" type="button" role="tab">Segurança</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#manutencao" type="button" role="tab">Manutenção</button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="tab-content">
                        <!-- Aba Geral -->
                        <div class="tab-pane fade show active" id="geral" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Sistema</label>
                                        <input type="text" name="nome_sistema" class="form-control" value="<?php echo htmlspecialchars($config['nome_sistema']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Sigla</label>
                                        <input type="text" name="sigla" class="form-control" value="<?php echo htmlspecialchars($config['sigla']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail Geral</label>
                                        <input type="email" name="email_geral" class="form-control" value="<?php echo htmlspecialchars($config['email_geral']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($config['telefone']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>WhatsApp</label>
                                        <input type="text" name="whatsapp" class="form-control" value="<?php echo htmlspecialchars($config['whatsapp']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Fuso Horário</label>
                                        <select name="timezone" class="form-control">
                                            <?php foreach ($timezones as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $config['timezone'] == $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Endereço</label>
                                <textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($config['endereco']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Logo do Sistema</label>
                                        <input type="file" name="logo" class="form-control" accept="image/*">
                                        <?php if ($config['logo']): ?>
                                            <div class="mt-2">
                                                <img src="../../uploads/config/<?php echo $config['logo']; ?>" class="logo-preview">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Favicon</label>
                                        <input type="file" name="favicon" class="form-control" accept=".ico,.png">
                                        <?php if ($config['favicon']): ?>
                                            <div class="mt-2">
                                                <img src="../../uploads/config/<?php echo $config['favicon']; ?>" class="favicon-preview">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Moeda</label>
                                        <select name="moeda" class="form-control">
                                            <option value="KZ" <?php echo $config['moeda'] == 'KZ' ? 'selected' : ''; ?>>Kwanza (KZ)</option>
                                            <option value="USD" <?php echo $config['moeda'] == 'USD' ? 'selected' : ''; ?>>Dólar Americano (USD)</option>
                                            <option value="EUR" <?php echo $config['moeda'] == 'EUR' ? 'selected' : ''; ?>>Euro (EUR)</option>
                                            <option value="BRL" <?php echo $config['moeda'] == 'BRL' ? 'selected' : ''; ?>>Real Brasileiro (BRL)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Símbolo da Moeda</label>
                                        <input type="text" name="moeda_simbolo" class="form-control" value="<?php echo htmlspecialchars($config['moeda_simbolo']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Aba Acadêmico -->
                        <div class="tab-pane fade" id="academico" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Ano Letivo Atual</label>
                                        <select name="ano_letivo_atual" class="form-control">
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $config['ano_letivo_atual'] == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Bimestre Atual</label>
                                        <select name="bimestre_atual" class="form-control">
                                            <option value="1" <?php echo $config['bimestre_atual'] == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                                            <option value="2" <?php echo $config['bimestre_atual'] == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                                            <option value="3" <?php echo $config['bimestre_atual'] == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                                            <option value="4" <?php echo $config['bimestre_atual'] == 4 ? 'selected' : ''; ?>>4º Bimestre</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nota Máxima</label>
                                        <input type="number" name="nota_maxima" class="form-control" value="<?php echo $config['nota_maxima']; ?>" step="0.5">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nota Mínima para Aprovação</label>
                                        <input type="number" name="nota_minima_aprovacao" class="form-control" value="<?php echo $config['nota_minima_aprovacao']; ?>" step="0.5">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="permite_recuperacao" class="form-check-input" id="permite_recuperacao" <?php echo $config['permite_recuperacao'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Permite Recuperação de Notas</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Limite de Faltas (%)</label>
                                        <input type="number" name="limite_faltas" class="form-control" value="<?php echo $config['limite_faltas']; ?>">
                                        <small class="text-muted">Percentual máximo de faltas permitido</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Aba E-mail -->
                        <div class="tab-pane fade" id="email" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="enviar_email" class="form-check-input" id="enviar_email" <?php echo $config['enviar_email'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Habilitar envio de e-mails</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Servidor SMTP</label>
                                        <input type="text" name="email_host" class="form-control" value="<?php echo htmlspecialchars($config['email_host']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Porta SMTP</label>
                                        <input type="number" name="email_porta" class="form-control" value="<?php echo $config['email_porta']; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Segurança</label>
                                        <select name="email_seguranca" class="form-control">
                                            <option value="tls" <?php echo $config['email_seguranca'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            <option value="ssl" <?php echo $config['email_seguranca'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="none" <?php echo $config['email_seguranca'] == 'none' ? 'selected' : ''; ?>>Nenhum</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail Remetente</label>
                                        <input type="email" name="email_remetente" class="form-control" value="<?php echo htmlspecialchars($config['email_remetente']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Usuário SMTP</label>
                                        <input type="email" name="email_usuario" class="form-control" value="<?php echo htmlspecialchars($config['email_usuario']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Senha SMTP</label>
                                        <input type="password" name="email_senha" class="form-control" placeholder="Deixe em branco para manter a atual">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Aba Segurança -->
                        <div class="tab-pane fade" id="seguranca" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>reCAPTCHA Site Key</label>
                                        <input type="text" name="recaptcha_site_key" class="form-control" value="<?php echo htmlspecialchars($config['recaptcha_site_key']); ?>">
                                        <small class="text-muted">Google reCAPTCHA v2 ou v3</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>reCAPTCHA Secret Key</label>
                                        <input type="text" name="recaptcha_secret_key" class="form-control" value="<?php echo htmlspecialchars($config['recaptcha_secret_key']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Aba Manutenção -->
                        <div class="tab-pane fade" id="manutencao" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="manutencao" class="form-check-input" id="manutencao" <?php echo $config['manutencao'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label text-danger">Ativar Modo de Manutenção</label>
                                        </div>
                                        <small class="text-muted">Quando ativado, apenas administradores podem acessar o sistema</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Mensagem de Manutenção</label>
                                        <textarea name="manutencao_mensagem" class="form-control" rows="3"><?php echo htmlspecialchars($config['manutencao_mensagem']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Salvar Configurações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    </script>
</body>
</html>