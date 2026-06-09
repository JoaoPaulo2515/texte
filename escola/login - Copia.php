<?php
// escola/login.php - Login específico para cada escola
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Identificar a escola pelo parâmetro
$escola_identificador = $_GET['escola'] ?? $_POST['escola'] ?? '';
$escola = null;

if ($escola_identificador) {
    // Buscar escola por subdomínio ou ID
    $stmt = $conn->prepare("SELECT * FROM escolas WHERE subdominio = :subdominio OR id = :id");
    $stmt->execute([':subdominio' => $escola_identificador, ':id' => $escola_identificador]);
    $escola = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Se não encontrou escola, redirecionar
if (!$escola) {
    header('Location: ../login.php');
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
            // Buscar usuário da escola específica
            $stmt = $conn->prepare("
                SELECT u.*, e.id as escola_id, e.nome as escola_nome, e.subdominio, e.logo
                FROM usuarios u
                JOIN escolas e ON e.id = u.escola_id
                WHERE u.email = :email 
                AND u.status = 'ativo'
                AND e.id = :escola_id
            ");
            $stmt->execute([
                ':email' => $email,
                ':escola_id' => $escola['id']
            ]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['senha'])) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nome'] = $user['nome'];
                $_SESSION['usuario_email'] = $user['email'];
                $_SESSION['usuario_tipo'] = $user['tipo'];
                $_SESSION['escola_id'] = $user['escola_id'];
                $_SESSION['escola_nome'] = $user['escola_nome'];
                $_SESSION['escola_subdominio'] = $user['subdominio'];
                
                // Registrar último acesso
                $stmt = $conn->prepare("UPDATE usuarios SET ultimo_acesso = NOW(), ultimo_ip = :ip WHERE id = :id");
                $stmt->execute([':ip' => $_SERVER['REMOTE_ADDR'], ':id' => $user['id']]);
                
                // Redirecionar para o dashboard da escola
                header('Location: index.php');
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
    <title><?php echo htmlspecialchars($escola['nome']); ?> - Login | SIGE Angola</title>
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
        .logo-escola {
            max-width: 100px;
            max-height: 100px;
            margin-bottom: 15px;
            border-radius: 10px;
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
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <?php if ($escola['logo']): ?>
                    <img src="../uploads/escolas/<?php echo $escola['logo']; ?>" class="logo-escola">
                <?php else: ?>
                    <i class="fas fa-school fa-3x mb-3"></i>
                <?php endif; ?>
                <h3><?php echo htmlspecialchars($escola['nome']); ?></h3>
                <p>Acesse sua conta</p>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="escola" value="<?php echo $escola['subdominio']; ?>">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0"><i class="fas fa-envelope text-muted"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="E-mail" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0"><i class="fas fa-lock text-muted"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Senha" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
                
                <hr>
                <div class="text-center">
                    <a href="../login.php" class="text-muted small">
                        <i class="fas fa-arrow-left"></i> Voltar para o portal
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>