<?php
// aluno/login.php - Login da Área do Aluno (Layout Moderno)

define('ROOT_PATH', dirname(__DIR__, 2)); // Vai para a pasta escola, depois para a raiz

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';
session_start();

// Se já estiver logado, redireciona para o dashboard
if (isset($_SESSION['aluno_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matricula = trim($_POST['matricula'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    
    if (empty($matricula) || empty($senha)) {
        $error = "Preencha todos os campos.";
    } else {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // Buscar aluno por matrícula
        $sql = "SELECT id, nome, matricula, senha, email, telefone, status, escola_id 
                FROM estudantes 
                WHERE matricula = :matricula AND status = 'ativo'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':matricula' => $matricula]);
        $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($aluno && password_verify($senha, $aluno['senha'])) {
            // Login bem-sucedido
            $_SESSION['aluno_id'] = $aluno['id'];
            $_SESSION['aluno_nome'] = $aluno['nome'];
            $_SESSION['aluno_matricula'] = $aluno['matricula'];
            $_SESSION['aluno_email'] = $aluno['email'];
            $_SESSION['escola_id'] = $aluno['escola_id'];
            $_SESSION['logged_in'] = true;
            $_SESSION['user_type'] = 'aluno';
            
            // Atualizar último acesso
            $sql_update = "UPDATE estudantes SET ultimo_acesso = NOW() WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $aluno['id']]);
            
            header('Location: index.php');
            exit;
        } else {
            $error = "Matrícula ou senha incorretos.";
        }
    }
}

// Buscar informações da escola
$escola_nome = "SIGE Angola";
$escola_logo = "";
if (isset($_SESSION['escola_id'])) {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $sql_escola = "SELECT nome, logo FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $_SESSION['escola_id']]);
    $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
    if ($escola) {
        $escola_nome = $escola['nome'];
        $escola_logo = $escola['logo'];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Área do Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            overflow-x: hidden;
        }

        /* Container principal */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Card principal */
        .login-wrapper {
            display: flex;
            max-width: 1300px;
            width: 100%;
            background: white;
            border-radius: 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        /* Lado Esquerdo - Informações */
        .info-side {
            flex: 1;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            padding: 50px 40px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .info-side::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(45deg);
        }

        .info-header {
            position: relative;
            z-index: 2;
            margin-bottom: 50px;
        }

        .info-header h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .info-header p {
            opacity: 0.9;
            font-size: 1rem;
        }

        /* Features List */
        .features-list {
            position: relative;
            z-index: 2;
            margin-bottom: 50px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 35px;
            padding: 10px;
            border-radius: 15px;
            transition: transform 0.3s ease, background 0.3s ease;
        }

        .feature-item:hover {
            transform: translateX(10px);
            background: rgba(255, 255, 255, 0.1);
        }

        .feature-icon {
            width: 55px;
            height: 55px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            transition: all 0.3s ease;
        }

        .feature-item:hover .feature-icon {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }

        .feature-text h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .feature-text p {
            font-size: 0.85rem;
            opacity: 0.8;
            margin: 0;
        }

        /* Estatísticas */
        .stats-box {
            position: relative;
            z-index: 2;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 20px;
            backdrop-filter: blur(10px);
            margin-top: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            text-align: center;
        }

        .stat-item h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-item p {
            font-size: 0.75rem;
            opacity: 0.8;
            margin: 0;
        }

        /* Lado Direito - Login */
        .login-side {
            flex: 0.8;
            padding: 50px;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .login-header .logo-icon i {
            font-size: 2rem;
            color: white;
        }

        .login-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a2a6c;
            margin-bottom: 5px;
        }

        .login-header p {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Formulário */
        .form-group {
            margin-bottom: 25px;
        }

        .input-group-custom {
            position: relative;
            margin-bottom: 5px;
        }

        .input-group-custom i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #006B3E;
            font-size: 1.1rem;
        }

        .input-group-custom input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .input-group-custom input:focus {
            outline: none;
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        /* Botão */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 107, 62, 0.3);
        }

        /* Links */
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .login-footer p {
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* Alertas */
        .alert-custom {
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger-custom {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }

        .alert-success-custom {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }

        /* Responsivo */
        @media (max-width: 992px) {
            .login-wrapper {
                flex-direction: column;
                max-width: 500px;
            }
            .info-side {
                padding: 30px;
            }
            .login-side {
                padding: 40px 30px;
            }
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }
            .feature-item {
                margin-bottom: 20px;
            }
        }

        /* Animações */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .info-side, .login-side {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-wrapper">
            <!-- Lado Esquerdo - Informações -->
            <div class="info-side">
                <div class="info-header">
                    <h2>Área do Aluno</h2>
                    <p>Gerencie sua vida acadêmica de forma simples e eficiente</p>
                </div>

                <div class="features-list">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Acompanhamento Académico</h4>
                            <p>Notas, frequência e boletim em tempo real</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Gestão Financeira</h4>
                            <p>Mensalidades, extratos e recibos online</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Documentos Escolares</h4>
                            <p>Certificados, declarações e comprovativos</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Comunicação Direta</h4>
                            <p>Avisos, notificações e contacto com a escola</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Biblioteca Virtual</h4>
                            <p>Consultar acervo e fazer reservas online</p>
                        </div>
                    </div>
                </div>

                <div class="stats-box">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <h3>24/7</h3>
                            <p>Acesso Ilimitado</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-lock"></i></h3>
                            <p>Dados Seguros</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-mobile-alt"></i></h3>
                            <p>Responsivo</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lado Direito - Login -->
            <div class="login-side">
                <div class="login-header">
                    <div class="logo-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Bem-vindo de volta!</h3>
                    <p>Faça login para acessar sua área</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert-custom alert-danger-custom">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert-custom alert-success-custom">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <div class="input-group-custom">
                            <i class="fas fa-id-card"></i>
                            <input type="text" name="matricula" placeholder="Número de Matrícula" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-group-custom">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="senha" placeholder="Sua Senha" required>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>

                <div class="login-footer">
                    <p><i class="fas fa-info-circle"></i> Primeiro acesso? Use sua matrícula e senha fornecida pela escola</p>
                    <p class="mt-2">
                        <small>
                            <i class="fas fa-shield-alt"></i> Ambiente seguro e protegido
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>