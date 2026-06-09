<?php
// aluno/login.php - Login da Área do Aluno (Layout Moderno com Imagem de Fundo)
// Verifica acesso na tabela usuarios

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
    $username = trim($_POST['username'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    
    if (empty($username) || empty($senha)) {
        $error = "Preencha todos os campos.";
    } else {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        // ==============================================
        // VERIFICAR NA TABELA USUARIOS
        // ==============================================
        // Buscar usuário por username (pode ser email ou matrícula)
        $sql = "SELECT u.*, e.id as estudante_id, e.nome as estudante_nome, e.matricula, e.escola_id as estudante_escola_id
                FROM usuarios u
                LEFT JOIN estudantes e ON u.id = e.usuario_id
                WHERE (u.usuario = :username1 OR u.email = :username) 
                AND u.tipo = 'aluno'
                AND u.status = 'ativo'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':username1' => $username,
            ':username' => $username
            ]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Login bem-sucedido via tabela usuarios
            
            // Se não tiver estudante vinculado, buscar por matrícula
            if (!$usuario['estudante_id']) {
                $sql_est = "SELECT id, nome, matricula, escola_id 
                            FROM estudantes 
                            WHERE email = :email OR matricula = :username";
                $stmt_est = $conn->prepare($sql_est);
                $stmt_est->execute([':email' => $usuario['email'], ':username' => $username]);
                $estudante = $stmt_est->fetch(PDO::FETCH_ASSOC);
                
                if ($estudante) {
                    $usuario['estudante_id'] = $estudante['id'];
                    $usuario['estudante_nome'] = $estudante['nome'];
                    $usuario['matricula'] = $estudante['matricula'];
                    $usuario['estudante_escola_id'] = $estudante['escola_id'];
                }
            }
            
            // Verificar se o estudante está ativo
            if ($usuario['estudante_id']) {
                $sql_check = "SELECT status FROM estudantes WHERE id = :id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([':id' => $usuario['estudante_id']]);
                $est_status = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($est_status && $est_status['status'] != 'ativo') {
                    $error = "Sua conta de estudante está inativa. Contacte a secretaria.";
                    $usuario = null;
                }
            }
        }
        
        // Se não encontrou na tabela usuarios, tentar buscar apenas por matrícula (fallback)
        if (!$usuario || !$usuario['estudante_id']) {
            $sql_fallback = "SELECT e.*, u.id as usuario_id, u.usuario, u.senha as usuario_senha, u.email as usuario_email
                             FROM estudantes e
                             LEFT JOIN usuarios u ON u.id = e.usuario_id
                             WHERE e.matricula = :matricula AND e.status = 'ativo'";
            $stmt_fallback = $conn->prepare($sql_fallback);
            $stmt_fallback->execute([':matricula' => $username]);
            $estudante_fallback = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
            
            if ($estudante_fallback) {
                // Verificar senha (pode estar na tabela estudantes ou usuarios)
                $senha_valida = false;
                if (!empty($estudante_fallback['usuario_senha']) && password_verify($senha, $estudante_fallback['usuario_senha'])) {
                    $senha_valida = true;
                } elseif (isset($estudante_fallback['senha']) && password_verify($senha, $estudante_fallback['senha'])) {
                    $senha_valida = true;
                }
                
                if ($senha_valida) {
                    $usuario = [
                        'id' => $estudante_fallback['usuario_id'],
                        'estudante_id' => $estudante_fallback['id'],
                        'estudante_nome' => $estudante_fallback['nome'],
                        'matricula' => $estudante_fallback['matricula'],
                        'email' => $estudante_fallback['usuario_email'] ?: $estudante_fallback['email'],
                        'estudante_escola_id' => $estudante_fallback['escola_id'],
                        'tipo' => 'aluno',
                        'status' => 'ativo'
                    ];
                }
            }
        }
        
        if ($usuario && isset($usuario['estudante_id']) && $usuario['estudante_id']) {
            // Login bem-sucedido
            $_SESSION['aluno_id'] = $usuario['estudante_id'];
            $_SESSION['aluno_nome'] = $usuario['estudante_nome'];
            $_SESSION['aluno_matricula'] = $usuario['matricula'];
            $_SESSION['aluno_email'] = $usuario['email'] ?? '';
            $_SESSION['escola_id'] = $usuario['estudante_escola_id'];
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['logged_in'] = true;
            $_SESSION['user_type'] = 'aluno';
            
            // Atualizar último acesso na tabela estudantes
            $sql_update = "UPDATE estudantes SET ultimo_acesso = NOW() WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $usuario['estudante_id']]);


              
         // Registrar acesso - versão mais simples
try {
    $escola_id = $usuario['estudante_escola_id'] ?? $usuario['escola_id'] ?? 0;
    
    // Primeiro tentar atualizar
    $stmt = $conn->prepare("
        UPDATE sessoes_ativas 
        SET ultima_atividade = NOW(), ip = :ip, user_agent = :user_agent
        WHERE usuario_id = :usuario_id AND escola_id = :escola_id
    ");
    
    $stmt->execute([
        ':usuario_id' => $usuario['id'],
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
            ':usuario_id' => $usuario['id'],
            ':escola_id' => $escola_id,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
    }
    
} catch (PDOException $e) {
    // Log do erro mas não interrompe o fluxo
    error_log("Erro ao registrar sessão: " . $e->getMessage());
}

            
            // Atualizar último login na tabela usuarios
            if ($usuario['id']) {
                $sql_update_user = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id";
                $stmt_update_user = $conn->prepare($sql_update_user);
                $stmt_update_user->execute([':id' => $usuario['id']]);
            }
            
            header('Location: index.php');
            exit;
        } else {
            $error = "Usuário ou senha incorretos.";
        }
    }
}

// Buscar informações da escola (se houver session)
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
            background: linear-gradient(135deg, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.6) 100%), 
                        url('https://images.pexels.com/photos/267885/pexels-photo-267885.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
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
                            <input type="text" name="username" placeholder=" Usuário / Matrícula / Email" required>
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