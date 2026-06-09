<?php
// recuperar_senha.php - Página de recuperação de senha
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        $email = $_POST['email'] ?? '';
        
        if (empty($email)) {
            $error = 'Digite seu e-mail';
        } else {
            try {
                $db = Database::getInstance();
                $conn = $db->getConnection();
                
                $stmt = $conn->prepare("SELECT id, nome FROM usuarios WHERE email = :email AND status = 'ativo'");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Gerar token único
                    $token = bin2hex(random_bytes(32));
                    $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    $stmt = $conn->prepare("
                        INSERT INTO recuperacao_senha (usuario_id, token, expira, created_at)
                        VALUES (:usuario_id, :token, :expira, NOW())
                    ");
                    $stmt->execute([
                        ':usuario_id' => $user['id'],
                        ':token' => $token,
                        ':expira' => $expira
                    ]);
                    
                    // Em produção, enviar e-mail com link
                    $link = APP_URL . "/reset_senha.php?token=" . $token;
                    
                    // Simular envio
                    $_SESSION['reset_token'] = $token;
                    $_SESSION['reset_email'] = $email;
                    
                    header("Location: recuperar_senha.php?step=2&email=" . urlencode($email));
                    exit;
                } else {
                    $error = 'E-mail não encontrado';
                }
            } catch (Exception $e) {
                $error = 'Erro ao processar solicitação';
            }
        }
    } elseif ($step == 2) {
        $codigo = $_POST['codigo'] ?? '';
        $token = $_SESSION['reset_token'] ?? '';
        
        if ($codigo === '123456') { // Código de exemplo
            header("Location: reset_senha.php?token=" . $token);
            exit;
        } else {
            $error = 'Código inválido';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha | SIGE Angola</title>
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
        .code-input {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        .code-input input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.5em;
            border-radius: 10px;
            border: 1px solid #ddd;
        }
        .code-input input:focus {
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-key fa-3x mb-3"></i>
                        <h3 class="mb-0">Recuperar Senha</h3>
                        <p class="mb-0 mt-2">Digite seu e-mail para receber o código</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($step == 1): ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label>E-mail</label>
                                    <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-paper-plane"></i> Enviar Código
                                </button>
                            </form>
                        <?php elseif ($step == 2): ?>
                            <form method="POST">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Enviamos um código para <strong><?php echo htmlspecialchars($_GET['email'] ?? ''); ?></strong>
                                </div>
                                <div class="code-input">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 1)">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 2)">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 3)">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 4)">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 5)">
                                    <input type="text" maxlength="1" class="code-digit" onkeyup="moveToNext(this, 6)">
                                </div>
                                <input type="hidden" name="codigo" id="codigoCompleto">
                                <button type="submit" class="btn btn-primary w-100" onclick="completarCodigo()">
                                    <i class="fas fa-check"></i> Verificar Código
                                </button>
                                <div class="text-center mt-3">
                                    <a href="#" class="text-muted small">Reenviar código</a>
                                </div>
                            </form>
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
    
    <script>
        function moveToNext(current, index) {
            if (current.value.length === 1 && index < 6) {
                document.querySelectorAll('.code-digit')[index].focus();
            }
        }
        
        function completarCodigo() {
            let codigo = '';
            document.querySelectorAll('.code-digit').forEach(input => {
                codigo += input.value;
            });
            document.getElementById('codigoCompleto').value = codigo;
        }
    </script>
</body>
</html>