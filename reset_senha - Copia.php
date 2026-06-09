<?php
// reset_senha.php - Redefinir senha
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    header('Location: recuperar_senha.php');
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT r.*, u.id as usuario_id, u.email, u.nome
        FROM recuperacao_senha r
        JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.token = :token AND r.expira > NOW() AND r.usado = 0
    ");
    $stmt->execute([':token' => $token]);
    $recuperacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$recuperacao) {
        $error = 'Link inválido ou expirado. Solicite uma nova recuperação.';
    }
} catch (Exception $e) {
    $error = 'Erro ao processar solicitação';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
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
                ':id' => $recuperacao['usuario_id']
            ]);
            
            $stmt = $conn->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE id = :id");
            $stmt->execute([':id' => $recuperacao['id']]);
            
            $conn->commit();
            $success = true;
        } catch (Exception $e) {
            $conn->rollBack();
            $error = 'Erro ao alterar senha';
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
        }
        .card {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
            padding: 12px;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .success-icon {
            font-size: 4em;
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-lock fa-3x mb-3"></i>
                        <h3 class="mb-0">Redefinir Senha</h3>
                        <?php if (!empty($recuperacao) && !$error && !$success): ?>
                            <p class="mb-0 mt-2">Olá, <?php echo htmlspecialchars($recuperacao['nome']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="text-center">
                                <i class="fas fa-check-circle success-icon mb-3"></i>
                                <h4>Senha alterada com sucesso!</h4>
                                <p class="text-muted">Sua senha foi redefinida. Agora você pode fazer login com sua nova senha.</p>
                                <a href="login.php" class="btn btn-primary mt-3">
                                    <i class="fas fa-sign-in-alt"></i> Fazer Login
                                </a>
                            </div>
                        <?php elseif (!$error && !empty($recuperacao)): ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Nova Senha</label>
                                    <input type="password" name="nova_senha" class="form-control" placeholder="••••••••" required>
                                    <small class="text-muted">Mínimo 6 caracteres</small>
                                </div>
                                <div class="mb-3">
                                    <label>Confirmar Senha</label>
                                    <input type="password" name="confirmar_senha" class="form-control" placeholder="••••••••" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-save"></i> Salvar Nova Senha
                                </button>
                            </form>
                        <?php elseif (!$error && empty($recuperacao)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> Link inválido ou expirado.
                            </div>
                            <a href="recuperar_senha.php" class="btn btn-primary w-100">
                                Solicitar Nova Recuperação
                            </a>
                        <?php endif; ?>
                        
                        <hr>
                        <div class="text-center">
                            <a href="login.php" class="text-decoration-none">
                                <i class="fas fa-arrow-left"></i> Voltar para o login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>