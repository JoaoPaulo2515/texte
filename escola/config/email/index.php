<?php
// escola/config/email/index.php - Configuração de Email
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Verificar se o usuário tem permissão de administrador
$tipos_permitidos = ['super_admin', 'admin', 'administrador', 'diretor'];
if (!in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$escola_nome = $_SESSION['escola_nome'] ?? 'SIGE Angola';

// ============================================
// TABELA DE CONFIGURAÇÃO DE EMAIL
// ============================================
$check = $conn->query("SHOW TABLES LIKE 'email_config'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE email_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            smtp_host VARCHAR(255),
            smtp_port INT DEFAULT 587,
            smtp_user VARCHAR(255),
            smtp_password VARCHAR(255),
            smtp_secure VARCHAR(10) DEFAULT 'tls',
            from_email VARCHAR(255),
            from_name VARCHAR(255),
            reply_to VARCHAR(255),
            cc_email TEXT,
            bcc_email TEXT,
            ativo TINYINT DEFAULT 0,
            testado TINYINT DEFAULT 0,
            ultimo_teste DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
    
    // Inserir configuração padrão
    $conn->prepare("
        INSERT INTO email_config (escola_id, smtp_host, smtp_port, smtp_secure, ativo) 
        VALUES (:escola_id, 'smtp.gmail.com', 587, 'tls', 0)
    ")->execute([':escola_id' => $escola_id]);
}

// Tabela de templates de email
$check = $conn->query("SHOW TABLES LIKE 'email_templates'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE email_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            assunto VARCHAR(255) NOT NULL,
            corpo TEXT NOT NULL,
            tipo VARCHAR(50) DEFAULT 'geral',
            ativo TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_nome (nome, escola_id)
        )
    ");
    
    // Inserir templates padrão
    $templates = [
        [
            'nome' => 'boas_vindas',
            'assunto' => 'Bem-vindo ao SIGE Angola',
            'tipo' => 'usuario',
            'corpo' => '<h2>Bem-vindo ao SIGE Angola!</h2>
                        <p>Olá {{nome}},</p>
                        <p>Seu cadastro foi realizado com sucesso no SIGE Angola.</p>
                        <p><strong>Seus dados de acesso:</strong></p>
                        <ul>
                            <li>Usuário: {{email}}</li>
                            <li>Senha: {{senha}}</li>
                        </ul>
                        <p>Por favor, acesse o sistema e altere sua senha no primeiro login.</p>
                        <p><a href="{{link}}" class="btn">Acessar o Sistema</a></p>
                        <br>
                        <p>Atenciosamente,<br>{{escola_nome}}</p>'
        ],
        [
            'nome' => 'recuperar_senha',
            'assunto' => 'Recuperação de Senha - SIGE Angola',
            'tipo' => 'seguranca',
            'corpo' => '<h2>Recuperação de Senha</h2>
                        <p>Olá {{nome}},</p>
                        <p>Recebemos uma solicitação para recuperação de senha da sua conta no SIGE Angola.</p>
                        <p>Use o código abaixo para redefinir sua senha:</p>
                        <div style="text-align:center; font-size:24px; font-weight:bold; background:#f0f2f5; padding:15px; margin:15px 0; border-radius:8px;">{{codigo}}</div>
                        <p>Se você não solicitou esta alteração, ignore este email.</p>
                        <p><a href="{{link}}">Clique aqui para redefinir sua senha</a></p>
                        <br>
                        <p>Atenciosamente,<br>{{escola_nome}}</p>'
        ],
        [
            'nome' => 'notificacao_notas',
            'assunto' => 'Notas Lançadas - {{disciplina}}',
            'tipo' => 'academico',
            'corpo' => '<h2>Notas Lançadas</h2>
                        <p>Olá {{nome}},</p>
                        <p>Foram lançadas as notas da disciplina <strong>{{disciplina}}</strong> para o {{bimestre}} Bimestre.</p>
                        <p><strong>Nota: {{nota}}</strong></p>
                        <p>Status: {{status}}</p>
                        <p><a href="{{link}}">Visualizar detalhes</a></p>
                        <br>
                        <p>Atenciosamente,<br>{{escola_nome}}</p>'
        ],
        [
            'nome' => 'lancamento_notas_aberto',
            'assunto' => 'Lançamento de Notas - Período Aberto',
            'tipo' => 'academico',
            'corpo' => '<h2>Lançamento de Notas</h2>
                        <p>Prezado(a) Professor(a) {{nome}},</p>
                        <p>Informamos que o período de lançamento de notas do {{bimestre}} Bimestre está aberto.</p>
                        <p>Data limite: {{data_limite}}</p>
                        <p><a href="{{link}}">Acessar lançamento de notas</a></p>
                        <br>
                        <p>Atenciosamente,<br>Coordenação Pedagógica<br>{{escola_nome}}</p>'
        ]
    ];
    
    foreach ($templates as $template) {
        $conn->prepare("
            INSERT INTO email_templates (escola_id, nome, assunto, corpo, tipo) 
            VALUES (:escola_id, :nome, :assunto, :corpo, :tipo)
        ")->execute([
            ':escola_id' => $escola_id,
            ':nome' => $template['nome'],
            ':assunto' => $template['assunto'],
            ':corpo' => $template['corpo'],
            ':tipo' => $template['tipo']
        ]);
    }
}

// Tabela de envios de email
$check = $conn->query("SHOW TABLES LIKE 'email_logs'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE email_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            destinatario VARCHAR(255),
            assunto VARCHAR(255),
            status VARCHAR(20),
            erro TEXT,
            data_envio DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Buscar configuração atual
$config = $conn->prepare("SELECT * FROM email_config WHERE escola_id = :escola_id LIMIT 1");
$config->execute([':escola_id' => $escola_id]);
$config = $config->fetch(PDO::FETCH_ASSOC);

// Salvar configuração
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_config'])) {
    $smtp_host = $_POST['smtp_host'];
    $smtp_port = (int)$_POST['smtp_port'];
    $smtp_user = $_POST['smtp_user'];
    $smtp_password = $_POST['smtp_password'];
    $smtp_secure = $_POST['smtp_secure'];
    $from_email = $_POST['from_email'];
    $from_name = $_POST['from_name'];
    $reply_to = $_POST['reply_to'];
    $cc_email = $_POST['cc_email'];
    $bcc_email = $_POST['bcc_email'];
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    $stmt = $conn->prepare("
        UPDATE email_config 
        SET smtp_host = :host,
            smtp_port = :port,
            smtp_user = :user,
            smtp_password = :password,
            smtp_secure = :secure,
            from_email = :from_email,
            from_name = :from_name,
            reply_to = :reply_to,
            cc_email = :cc,
            bcc_email = :bcc,
            ativo = :ativo
        WHERE escola_id = :escola_id
    ");
    $stmt->execute([
        ':host' => $smtp_host,
        ':port' => $smtp_port,
        ':user' => $smtp_user,
        ':password' => $smtp_password,
        ':secure' => $smtp_secure,
        ':from_email' => $from_email,
        ':from_name' => $from_name,
        ':reply_to' => $reply_to,
        ':cc' => $cc_email,
        ':bcc' => $bcc_email,
        ':ativo' => $ativo,
        ':escola_id' => $escola_id
    ]);
    
    $msg_sucesso = "Configuração de email salva com sucesso!";
    
    if (empty($smtp_password)) {
        $conn->prepare("UPDATE email_config SET smtp_password = smtp_password WHERE escola_id = :escola_id")->execute([':escola_id' => $escola_id]);
    }
    
    header("Location: index.php?msg=" . urlencode($msg_sucesso));
    exit;
}

// Testar envio de email
if (isset($_GET['testar_email']) && isset($_GET['email'])) {
    $email_teste = $_GET['email'];
    
    // Validar se as configurações estão preenchidas
    if (empty($config['smtp_host']) || empty($config['smtp_user']) || empty($config['from_email'])) {
        header("Location: index.php?erro=" . urlencode('Configure os dados SMTP antes de testar'));
        exit;
    }
    
    // Verificar se o autoload existe
    $autoload_path = __DIR__ . '/../../../vendor/autoload.php';
    if (!file_exists($autoload_path)) {
        header("Location: index.php?erro=" . urlencode('PHPMailer não instalado. Execute: composer require phpmailer/phpmailer'));
        exit;
    }
    
    require_once $autoload_path;
    
    // Verificar se a classe existe
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        header("Location: index.php?erro=" . urlencode('Classe PHPMailer não encontrada. Verifique a instalação.'));
        exit;
    }
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($email_teste);
        $mail->isHTML(true);
        $mail->Subject = 'Teste de Configuração de Email - SIGE Angola';
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <div style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 20px; text-align: center;">
                    <h2>SIGE Angola</h2>
                </div>
                <div style="padding: 20px;">
                    <h3>Configuração de Email Funcionando!</h3>
                    <p>Este é um email de teste enviado pelo SIGE Angola.</p>
                    <p>Server: ' . $config['smtp_host'] . ':' . $config['smtp_port'] . '</p>
                    <p>Protocolo: ' . strtoupper($config['smtp_secure']) . '</p>
                    <p>Data/Hora: ' . date('d/m/Y H:i:s') . '</p>
                    <hr>
                    <p><strong>Informações da Conta:</strong></p>
                    <ul>
                        <li>E-mail do remetente: ' . $config['from_email'] . '</li>
                        <li>Nome do remetente: ' . $config['from_name'] . '</li>
                        <li>Escola: ' . $escola_nome . '</li>
                    </ul>
                </div>
                <div style="background: #f0f2f5; padding: 10px; text-align: center; font-size: 12px; color: #666;">
                    <p>Este é um email automático. Por favor, não responda.</p>
                    <p>&copy; ' . date('Y') . ' SIGE Angola - Sistema Integrado de Gestão Escolar</p>
                </div>
            </div>
        ';
        
        $mail->send();
        
        $conn->prepare("UPDATE email_config SET testado = 1, ultimo_teste = NOW() WHERE escola_id = :escola_id")->execute([':escola_id' => $escola_id]);
        
        $config['testado'] = 1;
        $config['ultimo_teste'] = date('Y-m-d H:i:s');
        
        $conn->prepare("INSERT INTO email_logs (escola_id, destinatario, assunto, status, data_envio) VALUES (:escola_id, :destinatario, 'Teste de Configuração', 'sucesso', NOW())")
            ->execute([':escola_id' => $escola_id, ':destinatario' => $email_teste]);
        
        header("Location: index.php?msg=Teste de email enviado com sucesso para $email_teste");
        exit;
        
    } catch (Exception $e) {
        $conn->prepare("INSERT INTO email_logs (escola_id, destinatario, assunto, status, erro, data_envio) VALUES (:escola_id, :destinatario, 'Teste de Configuração', 'erro', :erro, NOW())")
            ->execute([':escola_id' => $escola_id, ':destinatario' => $email_teste, ':erro' => $mail->ErrorInfo]);
        
        header("Location: index.php?erro=Erro ao enviar email: " . urlencode($mail->ErrorInfo));
        exit;
    }
}

// Salvar template
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_template'])) {
    $id = (int)$_POST['id'];
    $nome = $_POST['nome'];
    $assunto = $_POST['assunto'];
    $corpo = $_POST['corpo'];
    $tipo = $_POST['tipo'];
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE email_templates 
            SET assunto = :assunto, corpo = :corpo, tipo = :tipo, ativo = :ativo
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':assunto' => $assunto,
            ':corpo' => $corpo,
            ':tipo' => $tipo,
            ':ativo' => $ativo,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO email_templates (escola_id, nome, assunto, corpo, tipo, ativo)
            VALUES (:escola_id, :nome, :assunto, :corpo, :tipo, :ativo)
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':assunto' => $assunto,
            ':corpo' => $corpo,
            ':tipo' => $tipo,
            ':ativo' => $ativo
        ]);
    }
    
    header("Location: index.php?tab=templates&msg=Template salvo com sucesso!");
    exit;
}

// Excluir template
if (isset($_GET['excluir_template']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $conn->prepare("DELETE FROM email_templates WHERE id = :id AND escola_id = :escola_id")->execute([':id' => $id, ':escola_id' => $escola_id]);
    header("Location: index.php?tab=templates&msg=Template excluído com sucesso!");
    exit;
}

// Limpar logs
if (isset($_GET['limpar_logs'])) {
    $conn->prepare("DELETE FROM email_logs WHERE escola_id = :escola_id")->execute([':escola_id' => $escola_id]);
    header("Location: index.php?tab=logs&msg=Logs limpos com sucesso!");
    exit;
}

// Buscar templates
$templates = $conn->prepare("SELECT * FROM email_templates WHERE escola_id = :escola_id ORDER BY tipo, nome");
$templates->execute([':escola_id' => $escola_id]);
$templates = $templates->fetchAll(PDO::FETCH_ASSOC);

// Buscar logs de email
$logs = $conn->prepare("SELECT * FROM email_logs WHERE escola_id = :escola_id ORDER BY data_envio DESC LIMIT 50");
$logs->execute([':escola_id' => $escola_id]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET['msg'] ?? '';
$erro = $_GET['erro'] ?? '';
$tab = $_GET['tab'] ?? 'config';

// Incluir menu (com fallback)
$menu_path = __DIR__ . '/../../menu_escola.php';
if (file_exists($menu_path)) {
    include $menu_path;
} else {
    echo '<style>.main-content { margin-left: 0; }</style>';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuração de Email | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
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
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 15px 15px 0 0;
        }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .nav-tabs .nav-link {
            color: #006B3E;
            border: none;
            padding: 10px 20px;
        }
        
        .nav-tabs .nav-link.active {
            background: #006B3E;
            color: white;
            border-radius: 25px;
        }
        
        .status-ativo {
            background: #d4edda;
            color: #155724;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
        }
        
        .log-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .log-item.erro { border-left-color: #dc3545; }
        .log-item:hover { background: #e9ecef; }
        
        .template-item {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .template-item:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .code-example {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
        }
        
        .modal-help-icon {
            color: #006B3E;
            margin-right: 10px;
        }
        
        .help-section {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .help-section:last-child {
            border-bottom: none;
        }
        
        .help-title {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .help-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .help-number {
            width: 30px;
            height: 30px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .table-servicos {
            font-size: 13px;
        }
        
        .table-servicos th {
            background: #006B3E;
            color: white;
        }
        
        .badge-funcionalidade {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
            display: inline-block;
            margin: 2px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-envelope"></i> Configuração de Email</h2>
            <div>
                <button class="btn btn-info btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda
                </button>
                <span class="<?php echo $config['ativo'] ? 'status-ativo' : 'status-inativo'; ?>">
                    <i class="fas fa-<?php echo $config['ativo'] ? 'check-circle' : 'ban'; ?>"></i>
                    <?php echo $config['ativo'] ? 'Email Ativo' : 'Email Inativo'; ?>
                </span>
            </div>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($erro); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="emailTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tab == 'config' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#configuracao">
                            <i class="fas fa-sliders-h"></i> Configuração
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tab == 'templates' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#templates">
                            <i class="fas fa-file-alt"></i> Templates
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tab == 'logs' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#logs">
                            <i class="fas fa-history"></i> Logs de Envio
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    
                    <!-- Tab Configuração -->
                    <div class="tab-pane fade <?php echo $tab == 'config' ? 'show active' : ''; ?>" id="configuracao">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5 class="mb-3"><i class="fas fa-server"></i> Servidor SMTP</h5>
                                    <div class="mb-3">
                                        <label class="form-label">Servidor SMTP *</label>
                                        <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($config['smtp_host']); ?>" required>
                                        <small class="text-muted">Ex: smtp.gmail.com, smtp.office365.com</small>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Porta *</label>
                                            <input type="number" name="smtp_port" class="form-control" value="<?php echo $config['smtp_port']; ?>" required>
                                            <small>587 (TLS) | 465 (SSL)</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Segurança</label>
                                            <select name="smtp_secure" class="form-select">
                                                <option value="tls" <?php echo $config['smtp_secure'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                <option value="ssl" <?php echo $config['smtp_secure'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                <option value="">Nenhum</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Usuário *</label>
                                        <input type="email" name="smtp_user" class="form-control" value="<?php echo htmlspecialchars($config['smtp_user']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Senha</label>
                                        <input type="password" name="smtp_password" class="form-control" placeholder="••••••••">
                                        <small class="text-muted">Deixe em branco para manter a senha atual</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mb-3"><i class="fas fa-envelope-open-text"></i> Configurações do Remetente</h5>
                                    <div class="mb-3">
                                        <label class="form-label">E-mail do Remetente *</label>
                                        <input type="email" name="from_email" class="form-control" value="<?php echo htmlspecialchars($config['from_email']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nome do Remetente *</label>
                                        <input type="text" name="from_name" class="form-control" value="<?php echo htmlspecialchars($config['from_name'] ?: $escola_nome); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Reply To</label>
                                        <input type="email" name="reply_to" class="form-control" value="<?php echo htmlspecialchars($config['reply_to']); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">CC (Carbon Copy)</label>
                                        <input type="text" name="cc_email" class="form-control" value="<?php echo htmlspecialchars($config['cc_email']); ?>" placeholder="email1@dominio.com, email2@dominio.com">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">BCC (Blind Carbon Copy)</label>
                                        <input type="text" name="bcc_email" class="form-control" value="<?php echo htmlspecialchars($config['bcc_email']); ?>" placeholder="email1@dominio.com, email2@dominio.com">
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="ativo" id="email_ativo" value="1" <?php echo $config['ativo'] ? 'checked' : ''; ?> style="width: 50px; height: 25px;">
                                            <label class="form-check-label ms-2" for="email_ativo">
                                                <strong>Ativar envio de emails</strong>
                                            </label>
                                        </div>
                                        <small class="text-muted">Quando ativado, o sistema enviará emails automáticos</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" name="salvar_config" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Salvar Configuração
                                </button>
                                
                                <?php if ($config['testado']): ?>
                                    <span class="ms-3 text-success">
                                        <i class="fas fa-check-circle"></i> Último teste: <?php echo date('d/m/Y H:i:s', strtotime($config['ultimo_teste'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-vial"></i> Testar Envio de Email</h5>
                            <form class="row g-3" id="testeEmailForm">
                                <div class="col-md-8">
                                    <input type="email" id="email_teste" class="form-control" placeholder="email@teste.com" required>
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-success w-100" onclick="testarEmail()">
                                        <i class="fas fa-paper-plane"></i> Enviar Teste
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tab Templates -->
                    <div class="tab-pane fade <?php echo $tab == 'templates' ? 'show active' : ''; ?>" id="templates">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="list-group" id="templatesList">
                                    <a href="#" class="list-group-item list-group-item-action active" onclick="carregarTemplate(0, 'novo')">
                                        <i class="fas fa-plus"></i> Novo Template
                                    </a>
                                    <?php foreach ($templates as $template): ?>
                                    <a href="#" class="list-group-item list-group-item-action" onclick="carregarTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['nome']); ?>', '<?php echo addslashes($template['assunto']); ?>', `<?php echo addslashes($template['corpo']); ?>', '<?php echo $template['tipo']; ?>', <?php echo $template['ativo']; ?>)">
                                        <i class="fas fa-<?php echo $template['tipo'] == 'usuario' ? 'user' : ($template['tipo'] == 'academico' ? 'book' : 'shield-alt'); ?>"></i>
                                        <?php echo htmlspecialchars($template['nome']); ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <form method="POST" id="templateForm">
                                    <input type="hidden" name="id" id="template_id" value="0">
                                    <div class="mb-3">
                                        <label class="form-label">Nome do Template *</label>
                                        <input type="text" name="nome" id="template_nome" class="form-control" required>
                                        <small>Identificador único (ex: boas_vindas, recuperar_senha)</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Assunto *</label>
                                        <input type="text" name="assunto" id="template_assunto" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Conteúdo do Email (HTML) *</label>
                                        <textarea name="corpo" id="template_corpo" class="summernote" rows="10"></textarea>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Tipo</label>
                                            <select name="tipo" id="template_tipo" class="form-select">
                                                <option value="geral">Geral</option>
                                                <option value="usuario">Usuário</option>
                                                <option value="seguranca">Segurança</option>
                                                <option value="academico">Acadêmico</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="form-check form-switch mt-4">
                                                <input class="form-check-input" type="checkbox" name="ativo" id="template_ativo" value="1" style="width: 50px; height: 25px;">
                                                <label class="form-check-label ms-2" for="template_ativo">
                                                    <strong>Template Ativo</strong>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-code"></i> <strong>Variáveis disponíveis:</strong><br>
                                        <div class="code-example mt-2">
                                            {{nome}} - Nome do destinatário<br>
                                            {{email}} - E-mail do destinatário<br>
                                            {{escola_nome}} - Nome da escola<br>
                                            {{link}} - Link personalizado<br>
                                            {{codigo}} - Código de verificação<br>
                                            {{disciplina}} - Nome da disciplina<br>
                                            {{bimestre}} - Bimestre atual<br>
                                            {{nota}} - Nota do aluno<br>
                                            {{status}} - Status (Aprovado/Reprovado)<br>
                                            {{data_limite}} - Data limite<br>
                                            {{senha}} - Senha temporária
                                        </div>
                                    </div>
                                    
                                    <div class="text-center">
                                        <button type="submit" name="salvar_template" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Salvar Template
                                        </button>
                                        <button type="button" class="btn btn-danger" id="btnExcluirTemplate" onclick="excluirTemplate()" style="display: none;">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab Logs -->
                    <div class="tab-pane fade <?php echo $tab == 'logs' ? 'show active' : ''; ?>" id="logs">
                        <div class="d-flex justify-content-between mb-3">
                            <h5><i class="fas fa-list"></i> Histórico de Envios</h5>
                            <a href="?limpar_logs=1" class="btn btn-sm btn-danger" onclick="return confirm('Limpar todos os logs?')">
                                <i class="fas fa-trash"></i> Limpar Logs
                            </a>
                        </div>
                        
                        <?php if (empty($logs)): ?>
                            <p class="text-center text-muted">Nenhum envio registrado ainda.</p>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <div class="log-item <?php echo $log['status'] == 'sucesso' ? '' : 'erro'; ?>">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($log['destinatario']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i:s', strtotime($log['data_envio'])); ?> |
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($log['assunto']); ?>
                                        </small>
                                        <?php if ($log['erro']): ?>
                                            <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($log['erro']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php echo $log['status'] == 'sucesso' ? 'success' : 'danger'; ?>">
                                            <?php echo $log['status']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda Completo -->
    <div class="modal fade" id="modalAjuda" tabindex="-1" aria-labelledby="modalAjudaLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title" id="modalAjudaLabel">
                        <i class="fas fa-question-circle"></i> Central de Ajuda - Configuração de Email
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    
                    <!-- O que é -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-info-circle modal-help-icon"></i> O que é a Configuração de Email?
                        </div>
                        <p>A configuração de email permite que o sistema SIGE Angola envie notificações automáticas, recuperação de senha, comunicados e alertas para professores, alunos e encarregados de educação através do protocolo SMTP.</p>
                    </div>
                    
                    <!-- Funcionalidades -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-cogs modal-help-icon"></i> Funcionalidades Disponíveis
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2"><span class="badge-funcionalidade">Configuração SMTP</span> Servidor, porta, usuário, senha, segurança (TLS/SSL)</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Remetente</span> Configuração do e-mail e nome do remetente</div>
                                <div class="mb-2"><span class="badge-funcionalidade">CC/BCC</span> Cópia oculta para outros destinatários</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Ativação</span> Ligar/desligar envio de emails</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Teste de Envio</span> Enviar email de teste para verificar configuração</div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2"><span class="badge-funcionalidade">Templates</span> Criação/edição de modelos de email</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Variáveis</span> Suporte a variáveis dinâmicas ({{nome}}, {{link}}, etc.)</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Logs</span> Registro de todos os envios (sucesso/erro)</div>
                                <div class="mb-2"><span class="badge-funcionalidade">Editor HTML</span> Editor WYSIWYG para templates (Summernote)</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Como Configurar Passo a Passo -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-list-ol modal-help-icon"></i> Como Configurar - Passo a Passo
                        </div>
                        
                        <div class="help-step">
                            <div class="help-number">1</div>
                            <div class="help-content">
                                <h6>Acesse a página de Configuração de Email</h6>
                                <p>No menu lateral, vá em <strong>Configurações → Email</strong> ou clique diretamente no link da página.</p>
                            </div>
                        </div>
                        
                        <div class="help-step">
                            <div class="help-number">2</div>
                            <div class="help-content">
                                <h6>Configure os dados do Servidor SMTP</h6>
                                <p>Preencha os campos:</p>
                                <ul class="mt-1">
                                    <li><strong>Servidor SMTP:</strong> Endereço do servidor (ex: smtp.gmail.com)</li>
                                    <li><strong>Porta:</strong> 587 para TLS ou 465 para SSL</li>
                                    <li><strong>Usuário:</strong> Seu e-mail completo</li>
                                    <li><strong>Senha:</strong> Sua senha ou senha de aplicativo</li>
                                    <li><strong>Segurança:</strong> TLS ou SSL</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="help-step">
                            <div class="help-number">3</div>
                            <div class="help-content">
                                <h6>Configure os dados do Remetente</h6>
                                <p>Preencha:</p>
                                <ul class="mt-1">
                                    <li><strong>E-mail do Remetente:</strong> E-mail que aparecerá como remetente</li>
                                    <li><strong>Nome do Remetente:</strong> Nome que aparecerá (ex: SIGE Angola)</li>
                                    <li><strong>Reply To:</strong> E-mail para respostas (opcional)</li>
                                    <li><strong>CC/BCC:</strong> E-mails que receberão cópia (opcional)</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="help-step">
                            <div class="help-number">4</div>
                            <div class="help-content">
                                <h6>Teste a Configuração</h6>
                                <p>Clique em <strong>"Enviar Teste"</strong> e digite um e-mail válido para verificar se a configuração está funcionando.</p>
                                <p class="text-success mt-1"><i class="fas fa-check-circle"></i> Se receber o e-mail, a configuração está correta!</p>
                                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Se não receber, verifique os dados e tente novamente.</p>
                            </div>
                        </div>
                        
                        <div class="help-step">
                            <div class="help-number">5</div>
                            <div class="help-content">
                                <h6>Ative o Envio de Emails</h6>
                                <p>Marque a opção <strong>"Ativar envio de emails"</strong> para que o sistema comece a enviar notificações automaticamente.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Configurações por Serviço -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-server modal-help-icon"></i> Configurações por Serviço de Email
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-servicos">
                                <thead>
                                    <tr>
                                        <th>Serviço</th>
                                        <th>Servidor SMTP</th>
                                        <th>Porta</th>
                                        <th>Segurança</th>
                                        <th>Observação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>Gmail</td><td>smtp.gmail.com</td><td>587</td><td>TLS</td><td>Use "Senha de App" ou ative "Acesso a app menos seguro"</td></tr>
                                    <tr><td>Outlook/Hotmail</td><td>smtp-mail.outlook.com</td><td>587</td><td>TLS</td><td>-</td></tr>
                                    <tr><td>Office 365</td><td>smtp.office365.com</td><td>587</td><td>TLS</td><td>Para contas corporativas</td></tr>
                                    <tr><td>Yahoo</td><td>smtp.mail.yahoo.com</td><td>465</td><td>SSL</td><td>-</td></tr>
                                    <tr><td>Zoho Mail</td><td>smtp.zoho.com</td><td>587</td><td>TLS</td><td>-</td></tr>
                                    <tr><td>Amazon SES</td><td>email-smtp.us-east-1.amazonaws.com</td><td>587</td><td>TLS</td><td>Requer credenciais SMTP</td></tr>
                                    <tr><td>SendGrid</td><td>smtp.sendgrid.net</td><td>587</td><td>TLS</td><td>Use "apikey" como usuário</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Templates e Variáveis -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-file-alt modal-help-icon"></i> Templates de Email e Variáveis
                        </div>
                        <p>Os templates permitem personalizar o conteúdo dos emails enviados pelo sistema.</p>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Templates Padrão:</strong>
                                <ul>
                                    <li><strong>boas_vindas</strong> - Enviado no cadastro de novos usuários</li>
                                    <li><strong>recuperar_senha</strong> - Enviado na solicitação de recuperação de senha</li>
                                    <li><strong>notificacao_notas</strong> - Enviado quando notas são lançadas</li>
                                    <li><strong>lancamento_notas_aberto</strong> - Enviado aos professores sobre abertura de período</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <strong>Variáveis Disponíveis:</strong>
                                <div class="code-example">
                                    {{nome}} - Nome do destinatário<br>
                                    {{email}} - E-mail do destinatário<br>
                                    {{escola_nome}} - Nome da escola<br>
                                    {{link}} - Link personalizado<br>
                                    {{codigo}} - Código de verificação<br>
                                    {{disciplina}} - Nome da disciplina<br>
                                    {{bimestre}} - Bimestre atual<br>
                                    {{nota}} - Nota do aluno<br>
                                    {{status}} - Status (Aprovado/Reprovado)<br>
                                    {{data_limite}} - Data limite<br>
                                    {{senha}} - Senha temporária
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dicas Importantes -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-lightbulb modal-help-icon"></i> Dicas Importantes
                        </div>
                        <ul>
                            <li>✅ <strong>Sempre teste a configuração antes de ativar</strong> - Use o botão "Enviar Teste" para verificar se está tudo correto</li>
                            <li>✅ <strong>Use senhas de aplicativo</strong> - Para maior segurança, especialmente no Gmail, gere uma "Senha de App"</li>
                            <li>✅ <strong>Monitore os logs</strong> - Acompanhe a aba "Logs de Envio" para identificar falhas</li>
                            <li>✅ <strong>Personalize os templates</strong> - Adapte os modelos com a identidade visual da sua escola</li>
                            <li>✅ <strong>Evite enviar muitos emails de uma vez</strong> - Muitos envios podem ser bloqueados pelo servidor</li>
                            <li>✅ <strong>Mantenha as credenciais seguras</strong> - A senha do SMTP é sensível, mantenha em local seguro</li>
                        </ul>
                    </div>
                    
                    <!-- Perguntas Frequentes -->
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-question-circle modal-help-icon"></i> Perguntas Frequentes
                        </div>
                        <div class="accordion" id="faqEmail">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faq1">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                        Por que não estou recebendo os emails de teste?
                                    </button>
                                </h2>
                                <div id="collapse1" class="accordion-collapse collapse" data-bs-parent="#faqEmail">
                                    <div class="accordion-body">
                                        Verifique: (1) Se os dados SMTP estão corretos; (2) Se a porta está correta (587 para TLS, 465 para SSL); 
                                        (3) Se a senha está correta; (4) Se o servidor permite conexões SMTP; (5) Confira os logs de erro para mais detalhes.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faq2">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                        Como gerar uma Senha de App no Gmail?
                                    </button>
                                </h2>
                                <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#faqEmail">
                                    <div class="accordion-body">
                                        Acesse sua Conta Google → Segurança → Verificação em duas etapas (ative) → Senhas de App → Selecione "Outro" e digite "SIGE Angola" → Copie a senha gerada de 16 dígitos e use no campo "Senha" da configuração.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faq3">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                        Posso usar qualquer servidor SMTP?
                                    </button>
                                </h2>
                                <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#faqEmail">
                                    <div class="accordion-body">
                                        Sim, desde que o servidor suporte SMTP e você tenha credenciais válidas. Consulte a tabela de configurações por serviço para exemplos.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faq4">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                                        O que fazer se o email cair na pasta de SPAM?
                                    </button>
                                </h2>
                                <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#faqEmail">
                                    <div class="accordion-body">
                                        Verifique a autenticação do domínio (SPF, DKIM, DMARC), use um nome de remetente confiável, evite palavras como "urgente" ou "promoção", e solicite aos destinatários que marquem o email como "Não é SPAM".
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Suporte -->
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-headset"></i> <strong>Precisa de mais ajuda?</strong>
                        <p class="mb-0 mt-1">Entre em contato com o suporte técnico da sua escola ou envie um email para suporte@sigeangola.com</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir Ajuda
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.summernote').summernote({
                height: 300,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link', 'picture']],
                    ['view', ['codeview', 'help']]
                ]
            });
        });
        
        function carregarTemplate(id, nome, assunto, corpo, tipo, ativo) {
            $('#template_id').val(id);
            $('#template_nome').val(nome);
            $('#template_assunto').val(assunto);
            $('#template_corpo').summernote('code', corpo || '');
            $('#template_tipo').val(tipo || 'geral');
            $('#template_ativo').prop('checked', ativo == 1);
            
            if (id > 0) {
                $('#btnExcluirTemplate').show();
            } else {
                $('#btnExcluirTemplate').hide();
            }
            
            $('button[data-bs-target="#templates"]').tab('show');
        }
        
        function excluirTemplate() {
            let id = $('#template_id').val();
            if (id && confirm('Tem certeza que deseja excluir este template?')) {
                window.location.href = 'index.php?excluir_template=1&id=' + id + '&tab=templates';
            }
        }
        
        function testarEmail() {
            let email = $('#email_teste').val();
            if (!email) {
                alert('Digite um e-mail para teste');
                return;
            }
            
            if (!confirm(`Enviar email de teste para ${email}?`)) return;
            
            window.location.href = 'index.php?testar_email=1&email=' + encodeURIComponent(email);
        }
    </script>
</body>
</html>