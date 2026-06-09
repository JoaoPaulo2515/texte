<?php
// login.php - Página institucional com login - Ambiente Escolar com Imagens de Fundo
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

// Verificar se a sessão já está ativa antes de iniciar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se já está logado, redirecionar
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['usuario_tipo'] === 'super_admin') {
        header('Location: super-admin/dashboard.php');
    } else {
        header('Location: escola/index.php');
    }
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
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT u.*, e.id as escola_id, e.nome as escola_nome, e.status as escola_status
                FROM usuarios u
                LEFT JOIN escolas e ON e.id = u.escola_id
                WHERE u.email = :email 
                AND u.status = 'ativo'
                AND u.tipo NOT IN ('professor', 'encarregado', 'aluno')
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['senha'])) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nome'] = $user['nome'];
                $_SESSION['usuario_email'] = $user['email'];
                $_SESSION['usuario_tipo'] = $user['tipo'];
                
                if ($user['escola_id']) {
                    $_SESSION['escola_id'] = $user['escola_id'];
                    $_SESSION['escola_nome'] = $user['escola_nome'];
                }
                
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
                
                if ($user['tipo'] === 'super_admin') {
                    header('Location: super-admin/dashboard.php');
                } else {
                    header('Location: escola/index.php');
                }
                exit;
            } else {
                $error = 'E-mail ou senha inválidos, ou você não tem permissão para acessar';
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
    <title>SIGE Angola - Sistema Integrado de Gestão Escolar</title>
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
        
        /* Navbar com fundo escuro */
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
        
        .btn-login-nav {
            background: linear-gradient(135deg, #006B3E, #FFD700);
            color: #1A2A6C !important;
            border-radius: 30px;
            padding: 8px 25px !important;
            font-weight: bold;
        }
        
        /* Hero Section com imagem de fundo escolar */
        .hero {
            min-height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            padding-top: 80px;
            background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1541339907198-e08756dedf3f?w=1920&h=1080&fit=crop');
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
        
        /* Seção de Recursos - Fundo escuro elegante */
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
        
        /* Seção Sobre com imagem overlay */
        .about {
            padding: 100px 0;
            background: linear-gradient(rgba(10,26,46,0.95), rgba(10,26,46,0.95)), url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1920&h=800&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        
        .about-text h3 {
            font-size: 2.2em;
            color: #FFD700;
            margin-bottom: 20px;
        }
        
        .about-text p {
            color: rgba(255,255,255,0.8);
            line-height: 1.8;
        }
        
        .mission-vision {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 40px;
        }
        
        .mv-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid rgba(255,215,0,0.2);
        }
        
        .mv-card:hover {
            transform: translateY(-5px);
            border-color: #FFD700;
        }
        
        .mv-card i {
            font-size: 2.5em;
            color: #FFD700;
            margin-bottom: 20px;
        }
        
        .mv-card h4 {
            color: #FFD700;
            margin-bottom: 15px;
        }
        
        .mv-card p {
            color: rgba(255,255,255,0.7);
        }
        
        /* Seção de Valores */
        .values {
            padding: 100px 0;
            background: linear-gradient(135deg, #0d2a3e 0%, #0a1a2e 100%);
            position: relative;
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
        
        /* Galeria de Imagens */
        .gallery {
            padding: 100px 0;
            background: #0a1a2e;
        }
        
        .gallery-item {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .gallery-item img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            transition: all 0.5s;
        }
        
        .gallery-item:hover img {
            transform: scale(1.1);
        }
        
        .gallery-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.9), transparent);
            color: white;
            padding: 20px;
            transform: translateY(100%);
            transition: all 0.3s;
        }
        
        .gallery-item:hover .gallery-overlay {
            transform: translateY(0);
        }
        
        .gallery-overlay h5 {
            color: #FFD700;
        }
        
        /* Seção de Contacto */
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
        
        .map-container {
            margin-top: 50px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 2px solid rgba(255,215,0,0.3);
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
        
        .modal-login .form-check-label {
            color: rgba(255,255,255,0.7);
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
        
        .btn-professor {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,0,0.3);
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            color: white;
        }
        
        .btn-professor:hover {
            background: rgba(255,215,0,0.2);
            border-color: #FFD700;
            color: #FFD700;
        }
        
        /* NOVO BOTÃO TESOURARIA */
        .btn-tesouraria {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,0,0.3);
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            color: white;
            width: 100%;
            margin-top: 10px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-tesouraria:hover {
            background: rgba(255,215,0,0.2);
            border-color: #FFD700;
            color: #FFD700;
            transform: translateY(-2px);
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
            .mission-vision {
                grid-template-columns: 1fr;
            }
            .section-title h2 {
                font-size: 1.8em;
            }
        }
        
        /* Botão de voltar ao topo */
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
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg" id="navbar">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chalkboard-user"></i>
                SIGE Angola
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon" style="filter: invert(1);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#home">Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sobre">Sobre</a></li>
                    <li class="nav-item"><a class="nav-link" href="#recursos">Recursos</a></li>
                    <li class="nav-item"><a class="nav-link" href="#valores">Valores</a></li>
                    <li class="nav-item"><a class="nav-link" href="#galeria">Galeria</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contato">Contato</a></li>
                    <li class="nav-item"><a class="nav-link btn-login-nav" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Entrar</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section com imagem de fundo escolar -->
    <section id="home" class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 hero-content">
                    <h1 class="animate-on-scroll">Sistema Integrado de <br><span class="highlight">Gestão Escolar</span></h1>
                    <p class="animate-on-scroll">A solução completa para a gestão da sua escola. Controle alunos, professores, notas, frequência e muito mais em um único lugar.</p>
                    <button class="btn btn-light btn-lg animate-on-scroll" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="fas fa-rocket"></i> Começar Agora
                    </button>
                    
                    <div class="hero-stats">
                        <div class="stat-item">
                            <div class="stat-number">500+</div>
                            <div class="stat-label">Escolas Atendidas</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">50k+</div>
                            <div class="stat-label">Alunos Matriculados</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">98%</div>
                            <div class="stat-label">Satisfação</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section com imagem overlay -->
    <section id="sobre" class="about">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-12">
                    <div class="about-text">
                        <h3 class="animate-on-scroll">Quem Somos</h3>
                        <p class="animate-on-scroll">O SIGE Angola é uma plataforma inovadora desenvolvida para atender as necessidades específicas das escolas angolanas. Com mais de 5 anos de experiência no mercado educacional, oferecemos soluções tecnológicas que simplificam a gestão escolar.</p>
                        
                        <div class="mission-vision">
                            <div class="mv-card animate-on-scroll">
                                <i class="fas fa-bullseye"></i>
                                <h4>Missão</h4>
                                <p>Revolucionar a gestão escolar em Angola através da tecnologia, proporcionando eficiência, transparência e qualidade no ensino.</p>
                            </div>
                            <div class="mv-card animate-on-scroll">
                                <i class="fas fa-eye"></i>
                                <h4>Visão</h4>
                                <p>Ser a plataforma de gestão escolar mais reconhecida e utilizada em Angola, transformando a educação através da inovação.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="recursos" class="features">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Recursos Poderosos</h2>
                <p class="animate-on-scroll">Tudo que sua escola precisa em um só lugar</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-users"></i></div>
                        <h3>Gestão de Alunos</h3>
                        <p>Cadastre e gerencie todos os alunos, com histórico completo, documentos e fotos.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-chalkboard-user"></i></div>
                        <h3>Gestão de Professores</h3>
                        <p>Controle de professores, disciplinas, horários e alocações por turma.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-graduation-cap"></i></div>
                        <h3>Lançamento de Notas</h3>
                        <p>Lançamento de notas com cálculo automático de médias e aprovação.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-calendar-check"></i></div>
                        <h3>Chamada Digital</h3>
                        <p>Registro de presença online com notificações para os encarregados.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-book-open"></i></div>
                        <h3>Biblioteca Digital</h3>
                        <p>Acervo digital de livros com visualização online e downloads.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card animate-on-scroll">
                        <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                        <h3>Relatórios Avançados</h3>
                        <p>Relatórios completos de desempenho, frequência e financeiro.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Values Section -->
    <section id="valores" class="values">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Nossos Valores</h2>
                <p class="animate-on-scroll">Princípios que guiam nossa atuação</p>
            </div>
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-handshake"></i>
                        <h4>Compromisso</h4>
                        <p>Comprometidos com a excelência e satisfação dos nossos clientes.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-lightbulb"></i>
                        <h4>Inovação</h4>
                        <p>Buscamos constantemente soluções inovadoras para a educação.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-shield-alt"></i>
                        <h4>Integridade</h4>
                        <p>Atuamos com transparência e ética em todas as relações.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="value-card animate-on-scroll">
                        <i class="fas fa-users"></i>
                        <h4>Colaboração</h4>
                        <p>Trabalhamos em parceria com as escolas para o melhor resultado.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Galeria de Imagens Escolares -->
    <section id="galeria" class="gallery">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Nossa Escola em Imagens</h2>
                <p class="animate-on-scroll">Conheça um pouco do nosso ambiente escolar</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1577896851231-70ef18881754?w=400&h=250&fit=crop" alt="Sala de Aula">
                        <div class="gallery-overlay">
                            <h5>Sala de Aula Moderna</h5>
                            <p>Ambientes preparados para o aprendizado</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?w=400&h=250&fit=crop" alt="Biblioteca">
                        <div class="gallery-overlay">
                            <h5>Biblioteca Digital</h5>
                            <p>Acervo completo para pesquisa</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1562774053-701939374585?w=400&h=250&fit=crop" alt="Laboratório">
                        <div class="gallery-overlay">
                            <h5>Laboratório de Informática</h5>
                            <p>Tecnologia a favor da educação</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1592280776946-52e3aada7e05?w=400&h=250&fit=crop" alt="Pátio">
                        <div class="gallery-overlay">
                            <h5>Área de Convivência</h5>
                            <p>Espaço para integração dos alunos</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1595152772835-219674b2a8a6?w=400&h=250&fit=crop" alt="Quadra">
                        <div class="gallery-overlay">
                            <h5>Quadra Esportiva</h5>
                            <p>Esporte e lazer para todos</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="gallery-item animate-on-scroll">
                        <img src="https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&h=250&fit=crop" alt="Evento">
                        <div class="gallery-overlay">
                            <h5>Eventos Escolares</h5>
                            <p>Celebrando conquistas e aprendizados</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contato" class="contact">
        <div class="container">
            <div class="section-title">
                <h2 class="animate-on-scroll">Entre em Contacto</h2>
                <p class="animate-on-scroll">Estamos à sua disposição para tirar dúvidas e oferecer suporte</p>
            </div>
            <div class="contact-info">
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-map-marker-alt"></i>
                    <h4>Endereço</h4>
                    <p>Luanda, Angola<br>Edifício SIGE, 5º Andar</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-phone-alt"></i>
                    <h4>Telefone</h4>
                    <p>+244 923 456 789<br>+244 923 456 788</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fas fa-envelope"></i>
                    <h4>E-mail</h4>
                    <p>contato@sige.ao<br>suporte@sige.ao</p>
                </div>
                <div class="contact-card animate-on-scroll">
                    <i class="fab fa-whatsapp"></i>
                    <h4>WhatsApp</h4>
                    <p>+244 923 456 789<br>Atendimento 24h</p>
                </div>
            </div>
            <div class="map-container animate-on-scroll">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3940.0!2d13.2345!3d-8.8383!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x1a51f2b9b0b9b9b9%3A0x0!2zLcKwIFPDg8OCw4PCj8K-!5e0!3m2!1spt!2sao!4v1234567890" width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h4><i class="fas fa-chalkboard-user"></i> SIGE Angola</h4>
                    <p>O sistema de gestão escolar mais completo e moderno de Angola.</p>
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
                        <li><a href="#home" style="color: rgba(255,255,255,0.7);">Início</a></li>
                        <li><a href="#sobre" style="color: rgba(255,255,255,0.7);">Sobre</a></li>
                        <li><a href="#recursos" style="color: rgba(255,255,255,0.7);">Recursos</a></li>
                        <li><a href="#valores" style="color: rgba(255,255,255,0.7);">Valores</a></li>
                        <li><a href="#contato" style="color: rgba(255,255,255,0.7);">Contato</a></li>
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
                <p style="color: rgba(255,255,255,0.5);">&copy; 2026 SIGE Angola - Todos os direitos reservados</p>
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
                    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Acessar o Sistema</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label>E-mail</label>
                            <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
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
                        <p class="text-muted">Não tem uma conta? <a href="#" data-bs-toggle="modal" data-bs-target="#contato" data-bs-dismiss="modal" style="color: #FFD700;">Contacte-nos</a></p>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="escola/professor/dashboard.php" class="btn btn-professor w-100">
                            <i class="fas fa-chalkboard-user"></i> Acessar como Professor
                        </a>
                    </div>
                    
                    <!-- NOVA DIVISÓRIA E BOTÃO TESOURARIA ADICIONADOS -->
                    <div class="text-center mt-3">
                        <a href="escola/tesouraria/login.php" class="btn-tesouraria">
                            <i class="fas fa-coins"></i> Acessar Tesouraria
                        </a>
                    </div>
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
                        <div class="mb-3"><label>Código de Verificação</label><div class="d-flex gap-2"><input type="text" id="codigo1" class="form-control text-center" maxlength="1" style="width: 50px;" onkeyup="moveToNext(this, 'codigo2')"><input type="text" id="codigo2" class="form-control text-center" maxlength="1" style="width: 50px;" onkeyup="moveToNext(this, 'codigo3')"><input type="text" id="codigo3" class="form-control text-center" maxlength="1" style="width: 50px;" onkeyup="moveToNext(this, 'codigo4')"><input type="text" id="codigo4" class="form-control text-center" maxlength="1" style="width: 50px;" onkeyup="moveToNext(this, 'codigo5')"><input type="text" id="codigo5" class="form-control text-center" maxlength="1" style="width: 50px;" onkeyup="moveToNext(this, 'codigo6')"><input type="text" id="codigo6" class="form-control text-center" maxlength="1" style="width: 50px;"></div></div>
                        <button class="btn btn-primary w-100" onclick="verificarCodigo()"><i class="fas fa-check"></i> Verificar Código</button>
                        <div class="text-center mt-3"><a href="#" onclick="reenviarCodigo()" class="text-muted small">Reenviar código</a></div>
                    </div>
                    <div id="passo3" style="display: none;">
                        <p class="text-muted mb-4">Digite sua nova senha.</p>
                        <div class="mb-3"><label>Nova Senha</label><input type="password" id="novaSenha" class="form-control" placeholder="••••••••"><small class="text-muted">Mínimo 6 caracteres</small></div>
                        <div class="mb-3"><label>Confirmar Senha</label><input type="password" id="confirmarSenha" class="form-control" placeholder="••••••••"></div>
                        <button class="btn btn-primary w-100" onclick="alterarSenha()"><i class="fas fa-save"></i> Alterar Senha</button>
                    </div>
                    <div id="sucessoMsg" style="display: none;"><div class="text-center"><i class="fas fa-check-circle fa-4x text-success mb-3"></i><h5>Senha alterada com sucesso!</h5><p class="text-muted">Sua senha foi redefinida. Faça login com sua nova senha.</p><button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal"><i class="fas fa-sign-in-alt"></i> Fazer Login</button></div></div>
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
            alert(`Código enviado para ${email}\nCódigo de teste: ${codigoEnviado}`);
            $('#passo1').hide(); $('#passo2').show();
            for(let i = 1; i <= 6; i++) $(`#codigo${i}`).val('');
            $('#codigo1').focus();
        }
        
        function moveToNext(current, nextId) { if (current.value.length === 1) document.getElementById(nextId).focus(); }
        
        function verificarCodigo() {
            let codigo = ''; for(let i = 1; i <= 6; i++) codigo += $(`#codigo${i}`).val();
            if (codigo === codigoEnviado) { $('#passo2').hide(); $('#passo3').show(); }
            else alert('Código inválido. Tente novamente.');
        }
        
        function reenviarCodigo() { codigoEnviado = Math.floor(100000 + Math.random() * 900000).toString(); alert(`Novo código enviado: ${codigoEnviado}`); }
        
        function alterarSenha() {
            const novaSenha = $('#novaSenha').val();
            const confirmarSenha = $('#confirmarSenha').val();
            if (novaSenha.length < 6) { alert('A senha deve ter no mínimo 6 caracteres'); return; }
            if (novaSenha !== confirmarSenha) { alert('As senhas não coincidem'); return; }
            $('#passo3').hide(); $('#sucessoMsg').show();
        }
        
        $('#recuperarModal').on('hidden.bs.modal', function () {
            $('#passo1').show(); $('#passo2').hide(); $('#passo3').hide(); $('#sucessoMsg').hide();
            $('#recuperarEmail').val('');
            for(let i = 1; i <= 6; i++) $(`#codigo${i}`).val('');
            $('#novaSenha').val(''); $('#confirmarSenha').val('');
        });
    </script>
</body>
</html>