<?php
// escola/professor/login.php - Login específico para professores

session_start();
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT p.*, u.email, u.senha, u.tipo, u.status as usuario_status,
               e.nome_fantasia as escola_nome, e.id as escola_id
        FROM professores p
        INNER JOIN usuarios u ON u.id = p.usuario_id
        INNER JOIN escolas e ON e.id = p.escola_id
        WHERE u.email = :email AND u.tipo = 'professor' AND u.status = 'ativo'
    ");
    $stmt->execute([':email' => $email]);
    $professor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($professor && password_verify($senha, $professor['senha'])) {
        $_SESSION['usuario_id'] = $professor['usuario_id'];
        $_SESSION['usuario_nome'] = $professor['nome'];
        $_SESSION['usuario_email'] = $professor['email'];
        $_SESSION['usuario_tipo'] = 'professor';
        $_SESSION['professor_id'] = $professor['id'];
        $_SESSION['escola_id'] = $professor['escola_id'];
        $_SESSION['escola_nome'] = $professor['escola_nome'];
        
        header('Location: dashboard.php');
        exit;
    } else {
        $erro = "Email ou senha inválidos!";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 450px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo i {
            font-size: 60px;
            color: #006B3E;
        }
        .login-logo h3 {
            color: #006B3E;
            margin-top: 10px;
        }
        .btn-login {
            background: #006B3E;
            border: none;
            padding: 12px;
            font-weight: bold;
        }
        .btn-login:hover {
            background: #004d2d;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <i class="fas fa-chalkboard-user"></i>
            <h3>Área do Professor</h3>
            <p class="text-muted">SIGE Angola</p>
        </div>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" class="form-control" required placeholder="professor@escola.ao">
            </div>
            <div class="mb-3">
                <label class="form-label">Senha</label>
                <input type="password" name="senha" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-login w-100">
                <i class="fas fa-sign-in-alt"></i> Entrar
            </button>
        </form>
        
        <div class="back-link">
            <a href="../../index.php" class="text-muted">
                <i class="fas fa-arrow-left"></i> Voltar ao início
            </a>
        </div>
    </div>
</body>
</html>