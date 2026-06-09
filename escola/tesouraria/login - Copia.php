<?php
// escola/tesouraria/redefinir_senha.php - Redefinir Senha

require_once __DIR__ . '/../../config/database.php';
session_start();

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Token inválido.');
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Verificar token
$stmt = $conn->prepare("
    SELECT * FROM recuperacao_senha 
    WHERE token = :token 
    AND expiracao > NOW() 
    AND usado = 0
");
$stmt->execute([':token' => $token]);
$recuperacao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recuperacao) {
    die('Token inválido ou expirado. Solicite uma nova recuperação de senha.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (strlen($senha) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres';
    } elseif ($senha !== $confirmar_senha) {
        $error = 'As senhas não coincidem';
    } else {
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
        $stmt->execute([':senha' => $senha_hash, ':id' => $recuperacao['usuario_id']]);
        
        $stmt = $conn->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE id = :id");
        $stmt->execute([':id' => $recuperacao['id']]);
        
        $success = 'Senha redefinida com sucesso! <a href="login.php">Clique aqui para fazer login</a>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .reset-container {
            max-width: 450px;
            width: 100%;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .reset-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .reset-header i {
            font-size: 3em;
            color: #006B3E;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px;
        }
        .btn-reset {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            width: 100%;
        }
    </style>
</head>
<body>
<div class="reset-container">
    <div class="reset-header">
        <i class="fas fa-key"></i>
        <h3>Redefinir Senha</h3>
        <p class="text-muted">Digite sua nova senha</p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php else: ?>
        <form method="POST">
            <div class="mb-3">
                <label>Nova Senha</label>
                <input type="password" name="senha" class="form-control" required minlength="6">
                <small class="text-muted">Mínimo de 6 caracteres</small>
            </div>
            <div class="mb-3">
                <label>Confirmar Senha</label>
                <input type="password" name="confirmar_senha" class="form-control" required>
            </div>
            <button type="submit" class="btn-reset">Redefinir Senha</button>
        </form>
    <?php endif; ?>
    
    <div class="text-center mt-3">
        <a href="login.php" class="text-muted">Voltar para o login</a>
    </div>
</div>
</body>
</html>