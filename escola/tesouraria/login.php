<?php
// escola/tesouraria/login.php - Login específico para Tesouraria/Financeiro

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';

// Verificar se a sessão já está ativa antes de iniciar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se já está logado como financeiro, redirecionar para o dashboard
if (isset($_SESSION['usuario_id']) && isset($_SESSION['escola_id'])) {
    $usuario_tipo = $_SESSION['usuario_tipo'] ?? '';
    $papel = $_SESSION['papel'] ?? '';
    
    if ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'financeiro' || $papel == 'admin') {
        header('Location: index.php');
        exit;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'recuperar') {
        // Processar recuperação de senha
        $email = $_POST['email'] ?? '';
        
        if (empty($email)) {
            $error = 'Digite seu e-mail';
        } else {
            try {
                $db = Database::getInstance();
                $conn = $db->getConnection();
                
                // Verificar se o e-mail existe
                $stmt = $conn->prepare("
                    SELECT u.id, u.nome, u.email 
                    FROM usuarios u
                    WHERE u.email = :email 
                    AND (u.tipo IN ('super_admin', 'admin_escola', 'diretor', 'tesouraria'))
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                             
         // Registrar acesso - versão mais simples
try {
    $escola_id = $user['escola_id'] ?? $user['escola_id'] ?? 0;
    
    // Primeiro tentar atualizar
    $stmt = $conn->prepare("
        UPDATE sessoes_ativas 
        SET ultima_atividade = NOW(), ip = :ip, user_agent = :user_agent
        WHERE usuario_id = :usuario_id AND escola_id = :escola_id
    ");
    
    $stmt->execute([
        ':usuario_id' => $user['id'],
        ':escola_id' => $escola_id,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Se não atualizou nenhum registro, inserir
    if ($stmt->rowCount() == 0) {
        $stmt = $conn->prepare("
            INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
            VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $user['id'],
            ':escola_id' => $escola_id,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
    }
    
} catch (PDOException $e) {
    // Log do erro mas não interrompe o fluxo
    error_log("Erro ao registrar sessão: " . $e->getMessage());
}


                if ($user) {
                    // Gerar token de recuperação
                    $token = bin2hex(random_bytes(32));
                    $expiracao = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    $stmt = $conn->prepare("
                        INSERT INTO recuperacao_senha (usuario_id, token, expiracao, ip_address) 
                        VALUES (:usuario_id, :token, :expiracao, :ip)
                    ");
                    $stmt->execute([
                        ':usuario_id' => $user['id'],
                        ':token' => $token,
                        ':expiracao' => $expiracao,
                        ':ip' => $_SERVER['REMOTE_ADDR']
                    ]);
                    
                    // Enviar e-mail de recuperação
                    $link = "http://" . $_SERVER['HTTP_HOST'] . "/sige_Plataforma/escola/tesouraria/redefinir_senha.php?token=" . $token;
                    $assunto = "Recuperação de Senha - Tesouraria SIGE Angola";
                    $mensagem = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; padding: 20px; text-align: center; }
                                .content { padding: 20px; background: #f8f9fa; }
                                .btn { background: #006B3E; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h2>SIGE Angola - Tesouraria</h2>
                                    <p>Recuperação de Senha</p>
                                </div>
                                <div class='content'>
                                    <p>Olá, <strong>" . htmlspecialchars($user['nome']) . "</strong>!</p>
                                    <p>Recebemos uma solicitação para redefinir sua senha de acesso à área financeira.</p>
                                    <p>Clique no botão abaixo para criar uma nova senha:</p>
                                    <p style='text-align: center;'>
                                        <a href='{$link}' class='btn'>Redefinir Senha</a>
                                    </p>
                                    <p>Este link é válido por 1 hora.</p>
                                    <p>Se você não solicitou esta alteração, ignore este e-mail.</p>
                                    <hr>
                                    <p><small>SIGE Angola - Sistema de Gestão Escolar</small></p>
                                </div>
                            </div>
                        </body>
                        </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: naoresponda@sigeangola.com\r\n";
                    
                    mail($user['email'], $assunto, $mensagem, $headers);
                    
                    $success = 'Link de recuperação enviado para seu e-mail. Verifique sua caixa de entrada.';
                } else {
                    $error = 'E-mail não encontrado ou usuário sem permissão de acesso à tesouraria.';
                }
            } catch (Exception $e) {
                $error = 'Erro ao processar solicitação. Tente novamente.';
            }
        }
    } else {
        // Processar login normal
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) ? true : false;
        
        if (empty($email) || empty($password)) {
            $error = 'Preencha todos os campos';
        } else {
            try {
                $db = Database::getInstance();
                $conn = $db->getConnection();
                
                $stmt = $conn->prepare("
                    SELECT u.*, e.id as escola_id, e.nome as escola_nome, e.status as escola_status
                    FROM usuarios u
                    LEFT JOIN escolas e ON e.id = u.escola_id
                    WHERE (u.email = :email OR u.nome = :username)
                    AND u.status = 'ativo'
                    AND (u.tipo IN ('super_admin', 'admin_escola', 'diretor', 'tesouraria'))
                ");
                $stmt->execute([
                    ':email' => $email,
                    ':username' => $email
                ]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    $stmt = $conn->prepare("
                        SELECT u.*, e.id as escola_id, e.nome as escola_nome, e.status as escola_status
                        FROM usuarios u
                        LEFT JOIN escolas e ON e.id = u.escola_id
                        WHERE u.email = :email
                        AND u.status = 'ativo'
                        AND (u.tipo IN ('super_admin', 'admin_escola', 'diretor', 'tesouraria'))
                    ");
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($user && password_verify($password, $user['senha'])) {
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['usuario_nome'] = $user['nome'];
                    $_SESSION['usuario_email'] = $user['email'];
                    $_SESSION['usuario_tipo'] = $user['tipo'];
                    $_SESSION['papel'] = $user['papel'] ?? 'financeiro';
                    
                    if ($user['escola_id']) {
                        $_SESSION['escola_id'] = $user['escola_id'];
                        $_SESSION['escola_nome'] = $user['escola_nome'];
                    }
                    
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), '/');
                        
                        $stmt = $conn->prepare("UPDATE usuarios SET remember_token = :token WHERE id = :id");
                        $stmt->execute([':token' => password_hash($token, PASSWORD_DEFAULT), ':id' => $user['id']]);
                    }
                    
                    $stmt = $conn->prepare("
                        UPDATE usuarios SET 
                            ultimo_acesso = NOW(),
                            ultimo_ip = :ip
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':ip' => $_SERVER['REMOTE_ADDR'],
                        ':id' => $user['id']
                    ]);
                    
                    $redirect = $_GET['redirect'] ?? 'index.php';
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $error = 'E-mail ou senha inválidos, ou você não tem permissão para acessar a tesouraria';
                }
            } catch (Exception $e) {
                $error = 'Erro ao conectar ao banco de dados: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-wrapper {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            display: flex;
            flex-wrap: wrap;
        }
        
        /* Lado Esquerdo - Informações */
        .info-side {
            flex: 1;
            min-width: 280px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .info-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.2;
        }
        
        .logo-area {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }
        
        .logo-area i {
            font-size: 4em;
            background: rgba(255,255,255,0.2);
            width: 100px;
            height: 100px;
            line-height: 100px;
            border-radius: 50%;
            margin-bottom: 15px;
        }
        
        .logo-area h2 {
            font-size: 1.8em;
            margin-bottom: 5px;
        }
        
        .logo-area p {
            opacity: 0.8;
        }
        
        .features-list {
            position: relative;
            z-index: 1;
            margin-top: 30px;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            transition: all 0.3s;
        }
        
        .feature-item:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(5px);
        }
        
        .feature-icon {
            width: 45px;
            height: 45px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3em;
        }
        
        .feature-text h4 {
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .feature-text p {
            font-size: 0.75rem;
            opacity: 0.7;
            margin: 0;
        }
        
        /* Lado Direito - Formulário */
        .form-side {
            flex: 1;
            min-width: 380px;
            padding: 40px;
            background: white;
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-header h3 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .form-header p {
            color: #666;
            font-size: 0.9em;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border-radius: 10px 0 0 10px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            width: 100%;
            color: white;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
        }
        
        .btn-recuperar {
            background: none;
            border: none;
            color: #006B3E;
            text-decoration: underline;
            cursor: pointer;
        }
        
        .alert {
            border-radius: 10px;
        }
        
        .separator {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }
        
        .separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #ddd;
        }
        
        .separator span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #999;
            font-size: 0.9em;
        }
        
        .btn-voltar {
            display: block;
            text-align: center;
            color: #666;
            text-decoration: none;
            margin-top: 15px;
        }
        
        .btn-voltar:hover {
            color: #006B3E;
        }
        
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }
            .info-side {
                padding: 30px;
            }
            .form-side {
                padding: 30px;
                min-width: auto;
            }
        }
        
        .password-strength {
            height: 3px;
            background: #ddd;
            margin-top: 5px;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        
        .modal-content {
            border-radius: 15px;
        }
        
        .modal-header {
            border-radius: 15px 15px 0 0;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-container">
        <!-- Lado Esquerdo - Informações da Tesouraria -->
        <div class="info-side">
            <div class="logo-area">
                <i class="fas fa-coins"></i>
                <h2>Tesouraria</h2>
                <p>Gestão Financeira Escolar</p>
            </div>
            
            <div class="features-list">
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="feature-text">
                        <h4>Dashboard Financeiro</h4>
                        <p>Visão geral de receitas, despesas e fluxo de caixa</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="feature-text">
                        <h4>Gestão de Pagamentos</h4>
                        <p>Controle de mensalidades, taxas e recibos</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="feature-text">
                        <h4>Relatórios Financeiros</h4>
                        <p>Extratos, balancetes e projeções</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-barcode"></i></div>
                    <div class="feature-text">
                        <h4>Emissão de Boletos</h4>
                        <p>Gere boletos e notas fiscais automaticamente</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-handshake"></i></div>
                    <div class="feature-text">
                        <h4>Negociação de Débitos</h4>
                        <p>Parcelamentos e acordos financeiros</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="feature-text">
                        <h4>Segurança</h4>
                        <p>Acesso seguro e criptografado</p>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 text-center" style="position: relative; z-index: 1;">
                <small><i class="fas fa-lock"></i> Acesso restrito à equipe financeira</small>
            </div>
        </div>
        
        <!-- Lado Direito - Formulário de Login -->
        <div class="form-side">
            <div class="form-header">
                <i class="fas fa-coins fa-2x" style="color: #006B3E;"></i>
                <h3>Acessar Tesouraria</h3>
                <p>Digite suas credenciais para continuar</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">E-mail ou Usuário</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="text" name="email" class="form-control" placeholder="seu@email.com" required autofocus>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Lembrar-me</label>
                    </div>
                    <button type="button" class="btn-recuperar" data-bs-toggle="modal" data-bs-target="#recuperarModal">
                        <i class="fas fa-key"></i> Esqueceu a senha?
                    </button>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Acessar Tesouraria
                </button>
                
                <div class="separator">
                    <span>ou</span>
                </div>
                
                <a href="../../login.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar para página inicial
                </a>
            </form>
        </div>
    </div>
</div>

<!-- Modal Recuperar Senha -->
<div class="modal fade" id="recuperarModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white;">
                <h5 class="modal-title"><i class="fas fa-key"></i> Recuperar Senha</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="recuperarForm">
                <div class="modal-body">
                    <p class="text-muted">Digite seu e-mail cadastrado para receber as instruções de recuperação de senha.</p>
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" id="recuperarEmail" class="form-control" placeholder="seu@email.com" required>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Um link de recuperação será enviado para seu e-mail. O link é válido por 1 hora.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" name="action" value="recuperar">
                        <i class="fas fa-paper-plane"></i> Enviar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mostrar/Esconder senha
    function togglePassword() {
        const password = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');
        
        if (password.type === 'password') {
            password.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            password.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    
    // Verificar força da senha (opcional)
    function checkPasswordStrength(password) {
        let strength = 0;
        if (password.length >= 6) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[$@#&!]+/)) strength++;
        
        const bar = document.getElementById('strengthBar');
        if (bar) {
            const width = (strength / 5) * 100;
            bar.style.width = width + '%';
            
            if (strength < 2) bar.style.background = '#dc3545';
            else if (strength < 4) bar.style.background = '#ffc107';
            else bar.style.background = '#28a745';
        }
    }
    
    // Validar formulário de recuperação
    document.getElementById('recuperarForm')?.addEventListener('submit', function(e) {
        const email = document.getElementById('recuperarEmail').value;
        if (!email) {
            e.preventDefault();
            alert('Por favor, digite seu e-mail.');
        }
    });
    
    // Fechar alertas automaticamente após 5 segundos
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            setTimeout(() => bsAlert.close(), 5000);
        });
    }, 1000);
</script>
</body>
</html>