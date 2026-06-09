<?php
// escola/professor/login.php - Login específico para Professores

// Definir o caminho da raiz do projeto
define('ROOT_PATH', dirname(__DIR__, 2)); // Vai para a pasta escola, depois para a raiz

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Verificar se a sessão já está ativa antes de iniciar
if (session_status() === PHP_SESSION_NONE) {
   session_start(); 
}

// Se já está logado como professor, redirecionar para o dashboard
if (isset($_SESSION['usuario_id']) && isset($_SESSION['usuario_tipo']) && $_SESSION['usuario_tipo'] === 'professor') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

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
                SELECT u.*, p.id as professor_id, p.nome as professor_nome, p.foto,
                       e.id as escola_id, e.nome as escola_nome, e.logo as escola_logo
                FROM usuarios u
                INNER JOIN funcionarios p ON p.usuario_id = u.id
                LEFT JOIN escolas e ON e.id = u.escola_id
                WHERE (u.email = :email OR u.nome = :username) 
                AND u.tipo = 'professor'
                AND u.status = 'ativo'
            ");
            $stmt->execute([
                ':email' => $email,
                ':username' => $email
            ]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $stmt = $conn->prepare("
                    SELECT u.*, p.id as professor_id, p.nome as professor_nome, p.foto,
                           e.id as escola_id, e.nome as escola_nome, e.logo as escola_logo
                    FROM usuarios u
                    INNER JOIN funcionarios p ON p.usuario_id = u.id
                    LEFT JOIN escolas e ON e.id = u.escola_id
                    WHERE u.email = :email
                    AND u.tipo = 'professor'
                    AND u.status = 'ativo'
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    $stmt = $conn->prepare("
                        SELECT u.*, p.id as professor_id, p.nome as professor_nome, p.foto,
                               e.id as escola_id, e.nome as escola_nome, e.logo as escola_logo
                        FROM usuarios u
                        INNER JOIN funcionarios p ON p.usuario_id = u.id
                        LEFT JOIN escolas e ON e.id = u.escola_id
                        WHERE u.nome = :nome
                        AND u.tipo = 'professor'
                        AND u.status = 'ativo'
                    ");
                    $stmt->execute([':nome' => $email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
            
            if ($user && password_verify($password, $user['senha'])) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nome'] = $user['nome'];
                $_SESSION['usuario_email'] = $user['email'];
                $_SESSION['usuario_tipo'] = $user['tipo'];
                $_SESSION['professor_id'] = $user['professor_id'];
                $_SESSION['professor_nome'] = $user['professor_nome'];
                $_SESSION['professor_foto'] = $user['foto'];
                $_SESSION['escola_id'] = $user['escola_id'];
                $_SESSION['escola_nome'] = $user['escola_nome'];
                
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

                // Registrar acesso
                try {
                    $escola_id = $user['escola_id'] ?? 0;
                    
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
                    error_log("Erro ao registrar sessão: " . $e->getMessage());
                }
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'E-mail ou senha inválidos';
            }
        } catch (Exception $e) {
            $error = 'Erro ao conectar ao banco de dados: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Área do Professor | SIGE Angola</title>
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
            background: linear-gradient(135deg, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.6) 100%), 
                        url('https://images.pexels.com/photos/5212345/pexels-photo-5212345.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            overflow-x: hidden;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

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

        /* Lado Esquerdo - Gradiente Azul/Verde (igual ao login do aluno) */
        .info-side {
            flex: 1;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
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
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
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
            color: #1A2A6C;
            margin-bottom: 5px;
        }

        .login-header p {
            color: #6c757d;
            font-size: 0.9rem;
        }

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

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
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
                    <h2>Área do Professor</h2>
                    <p>Gerencie suas turmas, notas e atividades escolares</p>
                </div>

                <div class="features-list">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chalkboard-user"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Minhas Turmas</h4>
                            <p>Visualize todas as suas turmas e horários</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Lançamento de Notas</h4>
                            <p>Registre notas e calcule médias automaticamente</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Registro de Chamada</h4>
                            <p>Marque presença de forma rápida e fácil</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Meu Horário</h4>
                            <p>Consulte seu horário de aulas em tempo real</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Relatórios</h4>
                            <p>Gere relatórios de desempenho dos alunos</p>
                        </div>
                    </div>
                </div>

                <div class="stats-box">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <h3><i class="fas fa-users"></i></h3>
                            <p>+500 Professores</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-chart-simple"></i></h3>
                            <p>98% Satisfação</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-clock"></i></h3>
                            <p>24/7 Disponível</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lado Direito - Login -->
            <div class="login-side">
                <div class="login-header">
                    <div class="logo-icon">
                        <i class="fas fa-chalkboard-user"></i>
                    </div>
                    <h3>Bem-vindo, Professor(a)!</h3>
                    <p>Acesso exclusivo para professores da instituição</p>
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
                            <i class="fas fa-user-graduate"></i>
                            <input type="text" name="email" placeholder="Usuário / Email / Número de Processo" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-group-custom">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" placeholder="Sua Senha" required>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>

                <div class="login-footer">
                    <p><i class="fas fa-info-circle"></i> Acesso exclusivo para professores cadastrados</p>
                    <p class="mt-2">
                        <small>
                            <i class="fas fa-shield-alt"></i> Ambiente seguro e protegido
                        </small>
                    </p>
                    <p class="mt-3">
                        <a href="../../login.php" style="color: #006B3E; text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Voltar para página inicial
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>