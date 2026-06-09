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
 session_start();
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
            
            // CORREÇÃO: Usar UNION ou OR com parâmetros separados
            // Opção 1: Usar OR com bind separado
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
            
            // Opção 2: Alternativa - buscar por email primeiro, depois por username
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


                       
         // Registrar acesso - versão mais simples
try {
    $escola_id = $user['escola_id'] ?? $user['escola_id'] ?? 0;
    
    // Primeiro tentar atualizar
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
    
    // Se não atualizou nenhum registro, inserir
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
    // Log do erro mas não interrompe o fluxo
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
    <title>Área do Professor | SIGE Angola</title>
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
            overflow-x: hidden;
            background: #0a1a2e;
        }
        
        /* Navbar */
        .navbar {
            background: rgba(10, 26, 46, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.2);
            padding: 15px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .navbar-brand {
            font-size: 1.8em;
            font-weight: bold;
            color: white !important;
        }
        
        .navbar-brand i {
            color: #FFD700;
            margin-right: 10px;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .nav-link:hover {
            color: #FFD700 !important;
        }
        
        .btn-professor-nav {
            background: linear-gradient(135deg, #006B3E, #FFD700);
            color: #1A2A6C !important;
            border-radius: 30px;
            padding: 8px 25px !important;
            font-weight: bold;
        }
        
        /* Hero Section com imagem de fundo */
        .hero {
            min-height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            padding-top: 80px;
            background: linear-gradient(rgba(0,0,0,0.65), rgba(0,0,0,0.75)), url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1920&h=1080&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        
        .hero-content {
            color: white;
            position: relative;
            z-index: 2;
        }
        
        .hero h1 {
            font-size: 3.5em;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .hero h1 .highlight {
            color: #FFD700;
        }
        
        .hero p {
            font-size: 1.2em;
            margin-bottom: 30px;
            opacity: 0.95;
        }
        
        .hero-stats {
            display: flex;
            gap: 30px;
            margin-top: 50px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            text-align: center;
            background: rgba(255,255,255,0.15);
            padding: 15px 25px;
            border-radius: 60px;
            backdrop-filter: blur(10px);
            transition: all 0.3s;
            border: 1px solid rgba(255,215,0,0.3);
        }
        
        .stat-item:hover {
            transform: scale(1.05);
            background: rgba(255,215,0,0.2);
            border-color: #FFD700;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #FFD700;
        }
        
        /* Features Section */
        .features {
            padding: 100px 0;
            background: linear-gradient(135deg, #0a1a2e 0%, #0d2a3e 100%);
            position: relative;
        }
        
        .features::before {
            content: '📚 ✏️ 🎓';
            position: absolute;
            font-size: 12em;
            opacity: 0.03;
            bottom: 0;
            right: 0;
            pointer-events: none;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title h2 {
            font-size: 2.8em;
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .section-title p {
            color: rgba(255,255,255,0.7);
            font-size: 1.1em;
        }
        
        .feature-card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 35px 25px;
            text-align: center;
            transition: all 0.4s;
            border: 1px solid rgba(255,255,255,0.1);
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            background: rgba(255,255,255,0.15);
            border-color: #FFD700;
        }
        
        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #006B3E, #FFD700);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
        }
        
        .feature-icon i {
            font-size: 2em;
            color: #1A2A6C;
        }
        
        .feature-card h3 {
            font-size: 1.4em;
            margin-bottom: 15px;
            color: #FFD700;
        }
        
        .feature-card p {
            color: rgba(255,255,255,0.7);
            line-height: 1.6;
        }
        
        /* Professor Info Section */
        .professor-info {
            padding: 100px 0;
            background: linear-gradient(rgba(10,26,46,0.95), rgba(10,26,46,0.95)), url('https://images.unsplash.com/photo-1577896851231-70ef18881754?w=1920&h=600&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        
        .professor-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid rgba(255,215,0,0.2);
            height: 100%;
        }
        
        .professor-card:hover {
            transform: translateY(-5px);
            background: rgba(255,215,0,0.1);
            border-color: #FFD700;
        }
        
        .professor-card i {
            font-size: 2.5em;
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .professor-card h4 {
            color: #FFD700;
            margin-bottom: 10px;
        }
        
        .professor-card p {
            color: rgba(255,255,255,0.7);
        }
        
        /* Values Section */
        .values {
            padding: 100px 0;
            background: linear-gradient(135deg, #0d2a3e 0%, #0a1a2e 100%);
        }
        
        .value-card {
            text-align: center;
            padding: 35px 25px;
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            transition: all 0.3s;
            border: 1px solid rgba(255,215,0,0.15);
            height: 100%;
        }
        
        .value-card:hover {
            transform: translateY(-5px);
            background: rgba(255,215,0,0.1);
            border-color: #FFD700;
        }
        
        .value-card i {
            font-size: 3em;
            color: #FFD700;
            margin-bottom: 20px;
        }
        
        .value-card h4 {
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .value-card p {
            color: rgba(255,255,255,0.7);
        }
        
        /* Contact Section */
        .contact {
            padding: 100px 0;
            background: linear-gradient(135deg, #0d2a3e 0%, #0a1a2e 100%);
        }
        
        .contact-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .contact-card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            padding: 35px;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid rgba(255,215,0,0.15);
        }
        
        .contact-card:hover {
            transform: translateY(-5px);
            border-color: #FFD700;
        }
        
        .contact-card i {
            font-size: 2.5em;
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .contact-card h4 {
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .contact-card p {
            color: rgba(255,255,255,0.7);
        }
        
        /* Footer */
        .footer {
            background: #061220;
            color: white;
            padding: 60px 0 30px;
            border-top: 1px solid rgba(255,215,0,0.2);
        }
        
        .footer h4 {
            color: #FFD700;
            margin-bottom: 20px;
        }
        
        .footer a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .footer a:hover {
            color: #FFD700;
        }
        
        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            color: white;
        }
        
        .social-links a:hover {
            background: #FFD700;
            color: #1A2A6C;
            transform: translateY(-3px);
        }
        
        /* Login Modal */
        .modal-login .modal-content {
            border-radius: 25px;
            background: linear-gradient(135deg, #0a1a2e, #0d2a3e);
            color: white;
            border: 1px solid rgba(255,215,0,0.3);
        }
        
        .modal-login .modal-header {
            border-bottom: 1px solid rgba(255,215,0,0.2);
        }
        
        .modal-login .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-login .form-control {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,0,0.3);
            color: white;
        }
        
        .modal-login .form-control:focus {
            background: rgba(255,255,255,0.15);
            border-color: #FFD700;
            box-shadow: 0 0 0 3px rgba(255,215,0,0.1);
        }
        
        .modal-login .form-control::placeholder {
            color: rgba(255,255,255,0.5);
        }
        
        .modal-login .btn-login {
            background: linear-gradient(135deg, #FFD700, #006B3E);
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            color: #1A2A6C;
        }
        
        .modal-login .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,215,0,0.3);
        }
        
        .btn-voltar-index {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,0,0.3);
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-voltar-index:hover {
            background: rgba(255,215,0,0.2);
            border-color: #FFD700;
            color: #FFD700;
        }
        
        .separator {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        
        .separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: rgba(255,215,0,0.3);
        }
        
        .separator span {
            background: #0d2a3e;
            padding: 0 15px;
            position: relative;
            color: #FFD700;
        }
        
        /* Botão Voltar ao Topo */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: #FFD700;
            color: #1A2A6C;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: all 0.3s;
            z-index: 999;
            border: none;
        }
        
        .back-to-top.show {
            opacity: 1;
        }
        
        .back-to-top:hover {
            transform: translateY(-5px);
            background: #006B3E;
            color: white;
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
        
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease-out;
        }
        
        .animate-on-scroll.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2em;
            }
            .hero-stats {
                flex-direction: column;
                gap: 15px;
            }
            .section-title h2 {
                font-size: 1.8em;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg" id="navbar">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chalkboard-user"></i>
                SIGE Angola - Professor
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon" style="filter: invert(1);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#home">Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="#recursos">Recursos</a></li>
                    <li class="nav-item"><a class="nav-link" href="#professor">Para Professores</a></li>
                    <li class="nav-item"><a class="nav-link" href="#valores">Valores</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contato">Contato</a></li>
                    <li class="nav-item"><a class="nav-link btn-professor-nav" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Acessar Área</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 hero-content">
                    <h1 class="animate-on-scroll">Área Exclusiva do<br><span class="highlight">Professor</span></h1>
                    <p class="animate-on-scroll">Gerencie suas turmas, notas, chamadas e muito mais. O SIGE Angola foi desenvolvido para facilitar o seu dia a dia em sala de aula.</p>
                    <button class="btn btn-light btn-lg animate-on-scroll" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="fas fa-rocket"></i> Acessar Agora
                    </button>
                    
                    <div class="hero-stats">
                        <div class="stat-item"><div class="stat-number">500+</div><div class="stat-label">Professores Ativos</div></div>
                        <div class="stat-item"><div class="stat-number">50k+</div><div class="stat-label">Alunos</div></div>
                        <div class="stat-item"><div class="stat-number">98%</div><div class="stat-label">Satisfação</div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="recursos" class="features">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Ferramentas para Professores</h2>
                <p class="animate-on-scroll">Tudo que você precisa para gerenciar suas atividades</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-chalkboard"></i></div>
                        <h3>Minhas Turmas</h3>
                        <p>Visualize todas as suas turmas, horários e informações dos alunos.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-book-open"></i></div>
                        <h3>Lançamento de Notas</h3>
                        <p>Lançamento rápido de notas com cálculo automático de médias.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-clipboard-list"></i></div>
                        <h3>Registro de Chamada</h3>
                        <p>Marque presença dos alunos de forma simples e rápida.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-calendar-alt"></i></div>
                        <h3>Meu Horário</h3>
                        <p>Consulte seu horário de aulas em tempo real.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                        <h3>Relatórios</h3>
                        <p>Gere relatórios de desempenho e frequência dos alunos.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-tasks"></i></div>
                        <h3>Atividades</h3>
                        <p>Gerencie atividades e trabalhos dos alunos.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Professor Info Section -->
    <section id="professor" class="professor-info">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Por que usar o SIGE Angola?</h2>
                <p class="animate-on-scroll">Tecnologia que facilita o seu trabalho</p>
            </div>
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="professor-card animate-on-scroll">
                        <i class="fas fa-clock"></i>
                        <h4>Economia de Tempo</h4>
                        <p>Processos automatizados que reduzem tarefas manuais.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card animate-on-scroll">
                        <i class="fas fa-mobile-alt"></i>
                        <h4>Acesso Mobile</h4>
                        <p>Acesse de qualquer lugar, a qualquer momento.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card animate-on-scroll">
                        <i class="fas fa-chart-simple"></i>
                        <h4>Relatórios Online</h4>
                        <p>Acompanhe o desempenho dos alunos em tempo real.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card animate-on-scroll">
                        <i class="fas fa-headset"></i>
                        <h4>Suporte Dedicado</h4>
                        <p>Equipe pronta para ajudar você.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section id="valores" class="values">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Compromisso com a Educação</h2>
                <p class="animate-on-scroll">Valores que nos guiam</p>
            </div>
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-handshake"></i>
                        <h4>Compromisso</h4>
                        <p>Comprometidos com a excelência na educação.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-lightbulb"></i>
                        <h4>Inovação</h4>
                        <p>Tecnologia de ponta para a educação.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-shield-alt"></i>
                        <h4>Transparência</h4>
                        <p>Dados seguros e confiáveis.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-users"></i>
                        <h4>Colaboração</h4>
                        <p>Trabalho em equipe para o sucesso.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contato" class="contact">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Precisa de Ajuda?</h2>
                <p class="animate-on-scroll">Nossa equipe está pronta para atender você</p>
            </div>
            <div class="contact-info">
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-phone-alt"></i>
                    <h4>Telefone</h4>
                    <p>+244 923 456 789<br>+244 923 456 788</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-envelope"></i>
                    <h4>E-mail</h4>
                    <p>suporte@sige.ao<br>professores@sige.ao</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fab fa-whatsapp"></i>
                    <h4>WhatsApp</h4>
                    <p>+244 923 456 789<br>Suporte 24h</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-video"></i>
                    <h4>Tutoriais</h4>
                    <p>Vídeos tutoriais disponíveis</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h4><i class="fas fa-chalkboard-user"></i> SIGE Angola</h4>
                    <p>A plataforma completa para gestão escolar.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="col-md-4">
                    <h4>Links Rápidos</h4>
                    <ul class="list-unstyled">
                        <li><a href="#home">Início</a></li>
                        <li><a href="#recursos">Recursos</a></li>
                        <li><a href="#professor">Para Professores</a></li>
                        <li><a href="#valores">Valores</a></li>
                        <li><a href="#contato">Contato</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h4>Newsletter</h4>
                    <p>Receba novidades e atualizações</p>
                    <div class="input-group">
                        <input type="email" class="form-control" placeholder="Seu e-mail" style="background: rgba(255,255,255,0.1); border: none; color: white;">
                        <button class="btn" style="background: #FFD700; color: #1A2A6C;">Assinar</button>
                    </div>
                </div>
            </div>
            <hr class="mt-4" style="border-color: rgba(255,215,0,0.1);">
            <div class="text-center">
                <p style="color: rgba(255,255,255,0.5);">&copy; 2026 SIGE Angola - Sistema Integrado de Gestão Escolar</p>
            </div>
        </div>
    </footer>

    <!-- Botão Voltar ao Topo -->
    <button class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Login Modal -->
    <div class="modal fade modal-login" id="loginModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Acessar Área do Professor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label>E-mail ou Usuário</label>
                            <input type="text" name="email" class="form-control" placeholder="seu@email.com ou usuario" required>
                        </div>
                        <div class="mb-3">
                            <label>Senha</label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember">
                                <label class="form-check-label">Lembrar-me</label>
                            </div>
                            <a href="#" class="text-muted" data-bs-toggle="modal" data-bs-target="#recuperarModal" data-bs-dismiss="modal" style="color: #FFD700 !important;">Esqueceu a senha?</a>
                        </div>
                        <button type="submit" class="btn btn-login w-100">
                            <i class="fas fa-arrow-right"></i> Entrar
                        </button>
                    </form>
                    
                    <div class="separator">
                        <span>ou</span>
                    </div>
                    
                    <div class="text-center">
                        <p class="text-muted">Não tem acesso? <a href="#contato" style="color: #FFD700;">Contacte a coordenação</a></p>
                    </div>
                    
                    <a href="../../login.php" class="btn-voltar-index">
                        <i class="fas fa-arrow-left"></i> Voltar para página inicial
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recuperar Senha Modal -->
    <div class="modal fade modal-login" id="recuperarModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Recuperar Senha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="recuperarContent">
                    <div id="passo1">
                        <p class="text-muted mb-4">Digite seu e-mail para receber as instruções de recuperação de senha.</p>
                        <div class="mb-3"><label>E-mail</label><input type="email" id="recuperarEmail" class="form-control" placeholder="seu@email.com"></div>
                        <button class="btn btn-primary w-100" onclick="enviarCodigo()"><i class="fas fa-paper-plane"></i> Enviar Código</button>
                    </div>
                    <div id="passo2" style="display: none;">
                        <p class="text-muted mb-4">Digite o código de verificação enviado para seu e-mail.</p>
                        <div class="mb-3"><label>Código de Verificação</label><div class="d-flex gap-2"><input type="text" id="codigo1" class="form-control text-center" maxlength="1" style="width:50px" onkeyup="moveToNext(this,'codigo2')"><input type="text" id="codigo2" class="form-control text-center" maxlength="1" style="width:50px" onkeyup="moveToNext(this,'codigo3')"><input type="text" id="codigo3" class="form-control text-center" maxlength="1" style="width:50px" onkeyup="moveToNext(this,'codigo4')"><input type="text" id="codigo4" class="form-control text-center" maxlength="1" style="width:50px" onkeyup="moveToNext(this,'codigo5')"><input type="text" id="codigo5" class="form-control text-center" maxlength="1" style="width:50px" onkeyup="moveToNext(this,'codigo6')"><input type="text" id="codigo6" class="form-control text-center" maxlength="1" style="width:50px"></div></div>
                        <button class="btn btn-primary w-100" onclick="verificarCodigo()"><i class="fas fa-check"></i> Verificar Código</button>
                        <div class="text-center mt-3"><a href="#" onclick="reenviarCodigo()" class="text-muted small">Reenviar código</a></div>
                    </div>
                    <div id="passo3" style="display: none;">
                        <p class="text-muted mb-4">Digite sua nova senha.</p>
                        <div class="mb-3"><label>Nova Senha</label><input type="password" id="novaSenha" class="form-control" placeholder="••••••••"><small class="text-muted">Mínimo 6 caracteres</small></div>
                        <div class="mb-3"><label>Confirmar Senha</label><input type="password" id="confirmarSenha" class="form-control" placeholder="••••••••"></div>
                        <button class="btn btn-primary w-100" onclick="alterarSenha()"><i class="fas fa-save"></i> Alterar Senha</button>
                    </div>
                    <div id="sucessoMsg" style="display:none"><div class="text-center"><i class="fas fa-check-circle fa-4x text-success mb-3"></i><h5>Senha alterada com sucesso!</h5><p class="text-muted">Sua senha foi redefinida. Faça login com sua nova senha.</p><button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal"><i class="fas fa-sign-in-alt"></i> Fazer Login</button></div></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            const backToTop = document.getElementById('backToTop');
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(10, 26, 46, 0.98)';
                backToTop.classList.add('show');
            } else {
                navbar.style.background = 'rgba(10, 26, 46, 0.95)';
                backToTop.classList.remove('show');
            }
        });
        
        // Back to top
        document.getElementById('backToTop').addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
        
        // Animation on scroll
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.animate-on-scroll').forEach(el => {
            observer.observe(el);
        });
        
        // Recuperação de senha
        let codigoEnviado = '';
        
        function enviarCodigo() {
            const email = $('#recuperarEmail').val();
            if (!email) { alert('Digite seu e-mail'); return; }
            codigoEnviado = Math.floor(100000 + Math.random() * 900000).toString();
            alert(`Código de teste: ${codigoEnviado}\n(Em produção, seria enviado para ${email})`);
            $('#passo1').hide(); $('#passo2').show();
            for(let i=1;i<=6;i++) $(`#codigo${i}`).val('');
            $('#codigo1').focus();
        }
        
        function moveToNext(current, nextId) { if (current.value.length === 1) document.getElementById(nextId).focus(); }
        
        function verificarCodigo() {
            let codigo = ''; for(let i=1;i<=6;i++) codigo += $(`#codigo${i}`).val();
            if (codigo === codigoEnviado) { $('#passo2').hide(); $('#passo3').show(); }
            else alert('Código inválido');
        }
        
        function reenviarCodigo() { codigoEnviado = Math.floor(100000 + Math.random() * 900000).toString(); alert(`Novo código: ${codigoEnviado}`); }
        
        function alterarSenha() {
            const nova = $('#novaSenha').val();
            const conf = $('#confirmarSenha').val();
            if (nova.length < 6) { alert('Mínimo 6 caracteres'); return; }
            if (nova !== conf) { alert('Senhas não coincidem'); return; }
            $('#passo3').hide(); $('#sucessoMsg').show();
        }
        
        $('#recuperarModal').on('hidden.bs.modal', function () {
            $('#passo1').show(); $('#passo2').hide(); $('#passo3').hide(); $('#sucessoMsg').hide();
            $('#recuperarEmail').val('');
            for(let i=1;i<=6;i++) $(`#codigo${i}`).val('');
            $('#novaSenha, #confirmarSenha').val('');
        });
    </script>
</body>
</html>