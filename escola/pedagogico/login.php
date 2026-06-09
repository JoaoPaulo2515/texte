<?php
// escola/pedagogico/login.php - Login da Área Pedagógica (Layout Moderno com Imagem de Fundo)

define('ROOT_PATH', dirname(__DIR__, 2)); // Vai para a raiz do projeto

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';
session_start();

// Se já estiver logado, redireciona para o dashboard pedagógico
if (isset($_SESSION['pedagogo_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
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
        // VERIFICAR NA TABELA USUARIOS (ÁREA PEDAGÓGICA)
        // ==============================================
        // Buscar usuário por username (pode ser email ou usuário)
        $sql = "SELECT u.*, f.id as funcionario_id, f.nome as funcionario_nome, f.cargo, f.escola_id, f.foto
                FROM usuarios u
                INNER JOIN funcionarios f ON u.id = f.usuario_id
                WHERE (u.usuario = :username1 OR u.email = :username2) 
                AND u.status = 'ativo'
                AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':username1' => $username,
            ':username2' => $username
        ]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Login bem-sucedido via tabela usuarios
            
            // Verificar se o funcionário está ativo
            $sql_check = "SELECT status FROM funcionarios WHERE id = :id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $usuario['funcionario_id']]);
            $func_status = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($func_status && $func_status['status'] != 'ativo') {
                $error = "Sua conta de funcionário está inativa. Contacte o administrador.";
                $usuario = null;
            }
        }
        
        // Se não encontrou na tabela usuarios, tentar buscar apenas por funcionário (fallback)
        if (!$usuario) {
            $sql_fallback = "SELECT f.*, u.id as usuario_id, u.usuario, u.senha as usuario_senha, u.email as usuario_email
                             FROM funcionarios f
                             LEFT JOIN usuarios u ON u.id = f.usuario_id
                             WHERE (f.email = :email OR f.numero_processo  = :codigo)
                             AND f.status = 'ativo'
                             AND f.cargo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola')";
            $stmt_fallback = $conn->prepare($sql_fallback);
            $stmt_fallback->execute([
                ':email' => $username,
                ':codigo' => $username
            ]);
            $funcionario_fallback = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
            
            if ($funcionario_fallback) {
                // Verificar senha (pode estar na tabela funcionarios ou usuarios)
                $senha_valida = false;
                if (!empty($funcionario_fallback['usuario_senha']) && password_verify($senha, $funcionario_fallback['usuario_senha'])) {
                    $senha_valida = true;
                } elseif (isset($funcionario_fallback['senha']) && password_verify($senha, $funcionario_fallback['senha'])) {
                    $senha_valida = true;
                } elseif ($funcionario_fallback['senha_temporaria'] && password_verify($senha, $funcionario_fallback['senha_temporaria'])) {
                    $senha_valida = true;
                    $success = "Bem-vindo! Por favor, altere sua senha temporária após o login.";
                }
                
                if ($senha_valida) {
                    $usuario = [
                        'id' => $funcionario_fallback['usuario_id'],
                        'funcionario_id' => $funcionario_fallback['id'],
                        'funcionario_nome' => $funcionario_fallback['nome'],
                        'cargo' => $funcionario_fallback['cargo'],
                        'escola_id' => $funcionario_fallback['escola_id'],
                        'email' => $funcionario_fallback['usuario_email'] ?: $funcionario_fallback['email'],
                        'foto' => $funcionario_fallback['foto'],
                        'tipo' => 'pedagogo',
                        'status' => 'ativo'
                    ];
                }
            }
        }
        
        if ($usuario && isset($usuario['funcionario_id']) && $usuario['funcionario_id']) {
            // Buscar nome da escola
            $sql_escola = "SELECT nome, logo FROM escolas WHERE id = :id";
            $stmt_escola = $conn->prepare($sql_escola);
            $stmt_escola->execute([':id' => $usuario['escola_id']]);
            $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
            
            // Login bem-sucedido
            $_SESSION['pedagogo_id'] = $usuario['funcionario_id'];
            $_SESSION['pedagogo_nome'] = $usuario['funcionario_nome'];
            $_SESSION['pedagogo_cargo'] = $usuario['cargo'];
            $_SESSION['pedagogo_email'] = $usuario['email'] ?? '';
            $_SESSION['pedagogo_foto'] = $usuario['foto'] ?? '';
            $_SESSION['escola_id'] = $usuario['escola_id'];
            $_SESSION['escola_nome'] = $escola['nome'] ?? '';
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['logged_in'] = true;
            $_SESSION['user_type'] = 'pedagogo';
            
            // Atualizar último acesso na tabela funcionarios
            $sql_update = "UPDATE funcionarios SET ultimo_acesso = NOW() WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $usuario['funcionario_id']]);
            
            // Registrar acesso na tabela sessoes_ativas
            try {
                $stmt = $conn->prepare("
                    UPDATE sessoes_ativas 
                    SET ultima_atividade = NOW(), ip = :ip, user_agent = :user_agent
                    WHERE usuario_id = :usuario_id AND escola_id = :escola_id
                ");
                $stmt->execute([
                    ':usuario_id' => $usuario['id'],
                    ':escola_id' => $usuario['escola_id'],
                    ':ip' => $_SERVER['REMOTE_ADDR'],
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]);
                
                if ($stmt->rowCount() == 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO sessoes_ativas (usuario_id, escola_id, ip, user_agent, ultima_atividade)
                        VALUES (:usuario_id, :escola_id, :ip, :user_agent, NOW())
                    ");
                    $stmt->execute([
                        ':usuario_id' => $usuario['id'],
                        ':escola_id' => $usuario['escola_id'],
                        ':ip' => $_SERVER['REMOTE_ADDR'],
                        ':user_agent' => $_SERVER['HTTP_USER_AGENT']
                    ]);
                }
            } catch (PDOException $e) {
                error_log("Erro ao registrar sessão: " . $e->getMessage());
            }
            
            // Atualizar último login na tabela usuarios
            if ($usuario['id']) {
                $sql_update_user = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id";
                $stmt_update_user = $conn->prepare($sql_update_user);
                $stmt_update_user->execute([':id' => $usuario['id']]);
            }
            
            // Redirecionar para o dashboard pedagógico
            header('Location: index.php');
            exit;
        } else {
            $error = "Usuário ou senha incorretos. Apenas pedagogos, coordenadores e diretores têm acesso.";
        }
    }
}

// Buscar informações da escola padrão (se houver)
$escola_nome = "SIGE Angola";
$escola_logo = "";
$db = Database::getInstance();
$conn = $db->getConnection();
$sql_escola_padrao = "SELECT nome, logo FROM escolas LIMIT 1";
$stmt_escola_padrao = $conn->prepare($sql_escola_padrao);
$stmt_escola_padrao->execute();
$escola_padrao = $stmt_escola_padrao->fetch(PDO::FETCH_ASSOC);
if ($escola_padrao) {
    $escola_nome = $escola_padrao['nome'];
    $escola_logo = $escola_padrao['logo'];
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Área Pedagógica | SIGE Angola</title>
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
                    <h2>Área Pedagógica</h2>
                    <p>Gerencie o desempenho académico e processos pedagógicos</p>
                </div>

                <div class="features-list">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Análise de Desempenho</h4>
                            <p>Métricas e indicadores de rendimento escolar</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-gavel"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Conselho de Nota</h4>
                            <p>Gerencie solicitações e votações do conselho</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chalkboard-user"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Gestão de Professores</h4>
                            <p>Acompanhe o corpo docente e suas turmas</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Gestão de Turmas</h4>
                            <p>Organize e monitore todas as turmas da escola</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Relatórios Pedagógicos</h4>
                            <p>Gere relatórios detalhados de aproveitamento</p>
                        </div>
                    </div>
                </div>

                <div class="stats-box">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <h3><i class="fas fa-graduation-cap"></i></h3>
                            <p>Gestão Completa</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-chart-simple"></i></h3>
                            <p>Métricas Avançadas</p>
                        </div>
                        <div class="stat-item">
                            <h3><i class="fas fa-shield-alt"></i></h3>
                            <p>Segurança Total</p>
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
                    <h3>Bem-vindo à Área Pedagógica!</h3>
                    <p>Acesso restrito a pedagogos, coordenadores e diretores</p>
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
                            <i class="fas fa-user-tie"></i>
                            <input type="text" name="username" placeholder="Usuário / Email / Código" required>
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
                    <p><i class="fas fa-info-circle"></i> Acesso exclusivo para pedagogos, coordenadores e diretores</p>
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