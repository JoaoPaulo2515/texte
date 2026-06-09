<?php
// login.php - Página de Login
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
//session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se já está logado, redirecionar
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['usuario_tipo'] === 'super_admin') {
        header('Location: super-admin/dashboard.php');
    } else {
        header('Location: escola/index.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
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
                WHERE u.email = :email AND u.status = 'ativo'
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['senha'])) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nome'] = $user['nome'];
                $_SESSION['usuario_email'] = $user['email'];
                $_SESSION['usuario_tipo'] = $user['tipo'];
                
                if ($user['escola_id']) {
                    $_SESSION['escola_id'] = $user['escola_id'];
                    $_SESSION['escola_nome'] = $user['escola_nome'];
                }
                
                // Registrar último acesso
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
                
                if ($user['tipo'] === 'super_admin') {
                    header('Location: super-admin/dashboard.php');
                } else {
                    header('Location: escola/index.php');
                }
                exit;
            } else {
                $error = 'E-mail ou senha inválidos';
            }
        } catch (Exception $e) {
            $error = 'Erro ao conectar ao banco de dados';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SIGE Angola</title>
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
        }
        .login-container {
            max-width: 450px;
            width: 100%;
            margin: 20px;
        }
        .card {
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
        .card-header .logo {
            font-size: 3.5em;
            margin-bottom: 15px;
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.5em;
        }
        .card-header p {
            margin: 5px 0 0;
            opacity: 0.8;
            font-size: 0.9em;
        }
        .card-body {
            padding: 30px;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
        }
        .btn-primary:hover {
            background: #004d2d;
            transform: translateY(-2px);
        }
        .input-group-text {
            background: #f0f2f5;
            border: none;
            border-radius: 10px 0 0 10px;
        }
        .form-control {
            border: none;
            background: #f0f2f5;
            padding: 12px;
            border-radius: 0 10px 10px 0;
        }
        .form-control:focus {
            box-shadow: none;
            background: #e8eaed;
        }
        .input-group {
            border-radius: 10px;
            overflow: hidden;
        }
        .alert {
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <div class="logo">
                    <i class="fas fa-chalkboard-user"></i>
                </div>
                <h3>SIGE Angola</h3>
                <p>Sistema Integrado de Gestão Escolar</p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="E-mail" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Senha" required>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember">
                            <label class="form-check-label" for="remember">Lembrar-me</label>
                        </div>
                        <a href="recuperar_senha.php" class="text-muted small">Esqueceu a senha?</a>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
            </div>
        </div>
        <div class="text-center mt-3">
            <small class="text-white opacity-75">
                <i class="fas fa-copyright"></i> <?php echo date('Y'); ?> SIGE Angola - Todos os direitos reservados
            </small>
        </div>
    </div>
</body>
</html>