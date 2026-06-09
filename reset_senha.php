<?php
// reset_senha.php - Redefinir senha com token
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$user_data = null;

if (empty($token)) {
    header('Location: recuperar_senha.php');
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Verificar token
    $stmt = $conn->prepare("
        SELECT r.*, u.id as usuario_id, u.email, u.nome
        FROM recuperacao_senha r
        JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.token = :token AND r.expira > NOW() AND r.usado = 0
    ");
    $stmt->execute([':token' => $token]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        $error = 'Link inválido ou expirado. Solicite uma nova recuperação.';
    }
} catch (Exception $e) {
    $error = 'Erro ao processar solicitação';
    error_log("Erro reset: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $user_data) {
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (strlen($nova_senha) < 6) {
        $error = 'A senha deve ter no mínimo 6 caracteres';
    } elseif ($nova_senha !== $confirmar_senha) {
        $error = 'As senhas não coincidem';
    } else {
        try {
            $conn->beginTransaction();
            
            $hashed_password = password_hash($nova_senha, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $stmt->execute([
                ':senha' => $hashed_password,
                ':id' => $user_data['usuario_id']
            ]);
            
            $stmt = $conn->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE id = :id");
            $stmt->execute([':id' => $user_data['id']]);
            
            $conn->commit();
            $success = true;
            
            // Limpar sessão
            unset($_SESSION['reset_token']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_codigo']);
            unset($_SESSION['reset_user_id']);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = 'Erro ao alterar senha. Tente novamente.';
            error_log("Erro ao alterar senha: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .reset-container {
            max-width: 480px;
            width: 100%;
            margin: 0 auto;
        }
        
        .card {
            border-radius: 24px;
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 32px;
            text-align: center;
        }
        
        .card-header .icon {
            font-size: 3.5em;
            margin-bottom: 16px;
        }
        
        .card-header h3 {
            font-size: 1.5em;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 32px;
            background: white;
        }
        
        .form-control {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .password-strength {
            margin-top: 8px;
            font-size: 0.8em;
        }
        
        .strength-weak { color: #dc2626; }
        .strength-medium { color: #f59e0b; }
        .strength-strong { color: #10b981; }
        
        .link-text {
            color: #006B3E;
            text-decoration: none;
            font-weight: 500;
        }
        
        .link-text:hover {
            text-decoration: underline;
        }
        
        .success-icon {
            font-size: 4em;
            color: #10b981;
            margin-bottom: 20px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="card">
            <div class="card-header">
                <div class="icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h3>Redefinir Senha</h3>
                <?php if ($user_data && !$success): ?>
                    <p>Olá, <?php echo htmlspecialchars($user_data['nome']); ?></p>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger fade-in">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="text-center fade-in">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h4 class="mb-2">Senha alterada com sucesso!</h4>
                        <p class="text-muted mb-4">Sua senha foi redefinida. Agora você pode fazer login com sua nova senha.</p>
                        <a href="login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i> Fazer Login
                        </a>
                    </div>
                <?php elseif ($user_data): ?>
                    <form method="POST" id="formReset">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Nova Senha</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-0 rounded-start-3">
                                    <i class="fas fa-lock text-muted"></i>
                                </span>
                                <input type="password" name="nova_senha" id="novaSenha" class="form-control" 
                                       placeholder="••••••••" required autofocus>
                            </div>
                            <div class="password-strength" id="passwordStrength"></div>
                            <small class="text-muted">Mínimo 6 caracteres</small>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirmar Nova Senha</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-0 rounded-start-3">
                                    <i class="fas fa-check-circle text-muted"></i>
                                </span>
                                <input type="password" name="confirmar_senha" id="confirmarSenha" class="form-control" 
                                       placeholder="••••••••" required>
                            </div>
                            <div id="confirmError" class="text-danger small mt-1"></div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Salvar Nova Senha
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-danger fade-in">
                        <i class="fas fa-exclamation-triangle me-2"></i> 
                        Link inválido ou expirado. Solicite uma nova recuperação.
                    </div>
                    <div class="text-center mt-4">
                        <a href="recuperar_senha.php" class="btn btn-primary">
                            <i class="fas fa-key me-2"></i> Solicitar Nova Recuperação
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                    <hr class="my-4">
                    <div class="text-center">
                        <a href="login.php" class="link-text">
                            <i class="fas fa-arrow-left me-1"></i> Voltar para o login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Verificar força da senha
        const novaSenha = document.getElementById('novaSenha');
        const confirmarSenha = document.getElementById('confirmarSenha');
        const passwordStrength = document.getElementById('passwordStrength');
        const confirmError = document.getElementById('confirmError');
        const submitBtn = document.getElementById('submitBtn');
        let formSubmitted = false;
        
        function checkPasswordStrength(password) {
            let strength = 0;
            let message = '';
            let className = '';
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            if (strength <= 1) {
                message = 'Senha fraca';
                className = 'strength-weak';
            } else if (strength <= 3) {
                message = 'Senha média';
                className = 'strength-medium';
            } else {
                message = 'Senha forte';
                className = 'strength-strong';
            }
            
            if (password.length === 0) {
                message = '';
            }
            
            passwordStrength.innerHTML = message;
            passwordStrength.className = 'password-strength ' + className;
            
            return strength >= 2;
        }
        
        function checkPasswordMatch() {
            const senha = novaSenha.value;
            const confirm = confirmarSenha.value;
            
            if (confirm.length > 0 && senha !== confirm) {
                                confirmError.innerHTML = 'As senhas não coincidem';
                return false;
            } else {
                confirmError.innerHTML = '';
                return true;
            }
        }
        
        function updateSubmitButton() {
            const senhaValida = checkPasswordStrength(novaSenha.value);
            const senhaMatch = checkPasswordMatch();
            submitBtn.disabled = !(senhaValida && senhaMatch && novaSenha.value.length > 0);
        }
        
        novaSenha.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
            updateSubmitButton();
        });
        
        confirmarSenha.addEventListener('input', function() {
            checkPasswordMatch();
            updateSubmitButton();
        });
        
        // Prevenir envio duplicado
        document.getElementById('formReset')?.addEventListener('submit', function(e) {
            if (formSubmitted) {
                e.preventDefault();
                return false;
            }
            formSubmitted = true;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Alterando senha...';
        });
        
        // Inicializar
        updateSubmitButton();
    </script>
</body>
</html>