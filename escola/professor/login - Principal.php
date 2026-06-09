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

<!-- O RESTO DO HTML PERMANECE IGUAL AO CÓDIGO ANTERIOR -->
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área do Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ESTILOS IGUAIS AO CÓDIGO ANTERIOR */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow-x: hidden; }
        .navbar { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); box-shadow: 0 2px 20px rgba(0,0,0,0.1); padding: 15px 0; position: fixed; width: 100%; top: 0; z-index: 1000; }
        .navbar-brand { font-size: 1.5em; font-weight: bold; color: #006B3E !important; }
        .navbar-brand i { margin-right: 10px; }
        .nav-link { color: #333 !important; font-weight: 500; transition: all 0.3s; }
        .nav-link:hover { color: #006B3E !important; }
        .btn-professor-nav { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white !important; border-radius: 30px; padding: 8px 25px !important; }
        .btn-professor-nav:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        .hero { min-height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); position: relative; overflow: hidden; display: flex; align-items: center; padding-top: 80px; }
        .hero::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.1)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom; background-size: cover; opacity: 0.3; }
        .hero-content { color: white; position: relative; z-index: 2; }
        .hero h1 { font-size: 3.5em; font-weight: 700; margin-bottom: 20px; }
        .hero p { font-size: 1.2em; margin-bottom: 30px; opacity: 0.9; }
        .hero-stats { display: flex; gap: 40px; margin-top: 50px; }
        .stat-item { text-align: center; }
        .stat-number { font-size: 2.5em; font-weight: bold; }
        .stat-label { font-size: 0.9em; opacity: 0.8; }
        .features { padding: 80px 0; background: #f8f9fa; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: 2.5em; color: #1A2A6C; margin-bottom: 15px; }
        .section-title p { color: #666; font-size: 1.1em; }
        .feature-card { background: white; border-radius: 15px; padding: 30px; text-align: center; transition: all 0.3s; box-shadow: 0 5px 20px rgba(0,0,0,0.05); height: 100%; }
        .feature-card:hover { transform: translateY(-10px); box-shadow: 0 15px 40px rgba(0,0,0,0.1); }
        .feature-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #006B3E, #1A2A6C); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .feature-icon i { font-size: 2em; color: white; }
        .feature-card h3 { font-size: 1.3em; margin-bottom: 15px; color: #1A2A6C; }
        .feature-card p { color: #666; line-height: 1.6; }
        .professor-info { background: linear-gradient(135deg, #e8f5e9, #e3f2fd); padding: 60px 0; }
        .professor-card { background: white; border-radius: 15px; padding: 25px; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.08); transition: all 0.3s; height: 100%; }
        .professor-card:hover { transform: translateY(-5px); }
        .professor-card i { font-size: 2.5em; color: #006B3E; margin-bottom: 15px; }
        .professor-card h4 { font-size: 1.2em; margin-bottom: 10px; color: #1A2A6C; }
        .values { padding: 80px 0; background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; }
        .values .section-title h2, .values .section-title p { color: white; }
        .value-card { text-align: center; padding: 30px; }
        .value-card i { font-size: 3em; margin-bottom: 20px; }
        .value-card h4 { margin-bottom: 15px; }
        .contact { padding: 80px 0; background: #f8f9fa; }
        .contact-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-top: 40px; }
        .contact-card { background: white; padding: 30px; border-radius: 15px; text-align: center; transition: all 0.3s; }
        .contact-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .contact-card i { font-size: 2.5em; color: #006B3E; margin-bottom: 15px; }
        .contact-card h4 { margin-bottom: 10px; color: #1A2A6C; }
        .modal-login .modal-content { border-radius: 20px; border: none; }
        .modal-login .modal-header { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; border-radius: 20px 20px 0 0; border: none; }
        .modal-login .modal-header .btn-close { filter: brightness(0) invert(1); }
        .modal-login .form-control { border-radius: 10px; padding: 12px; border: 1px solid #ddd; }
        .modal-login .form-control:focus { border-color: #006B3E; box-shadow: 0 0 0 3px rgba(0,107,62,0.1); }
        .modal-login .btn-login { background: linear-gradient(135deg, #006B3E, #1A2A6C); border: none; padding: 12px; border-radius: 10px; font-weight: 600; }
        .btn-voltar-index { background: #6c757d; border: none; padding: 12px; border-radius: 10px; font-weight: 600; color: white; width: 100%; transition: all 0.2s; margin-top: 10px; }
        .btn-voltar-index:hover { background: #5a6268; transform: translateY(-2px); }
        .footer { background: #1a1a2e; color: white; padding: 40px 0 20px; }
        .footer a { color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.3s; }
        .footer a:hover { color: white; }
        .social-links { display: flex; gap: 20px; justify-content: center; margin-top: 20px; }
        .social-links a { width: 40px; height: 40px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s; }
        .social-links a:hover { background: #006B3E; transform: translateY(-3px); }
        @media (max-width: 768px) { .hero h1 { font-size: 2em; } .hero-stats { flex-direction: column; gap: 20px; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .animate { animation: fadeInUp 0.6s ease-out; }
        .separator { text-align: center; margin: 15px 0; position: relative; }
        .separator::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #ddd; }
        .separator span { background: white; padding: 0 15px; position: relative; color: #999; font-size: 0.9em; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chalkboard-user"></i>
                SIGE Angola - Professor
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#home">Início</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sobre">Sobre</a></li>
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
                <div class="col-lg-7 hero-content">
                    <h1 class="animate">Área Exclusiva do<br>Professor</h1>
                    <p class="animate">Gerencie suas turmas, notas, chamadas e muito mais. O SIGE Angola foi desenvolvido para facilitar o seu dia a dia em sala de aula.</p>
                    <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="fas fa-rocket"></i> Acessar Agora
                    </button>
                    
                    <div class="hero-stats">
                        <div class="stat-item"><div class="stat-number">500+</div><div class="stat-label">Professores Ativos</div></div>
                        <div class="stat-item"><div class="stat-number">50k+</div><div class="stat-label">Alunos</div></div>
                        <div class="stat-item"><div class="stat-number">98%</div><div class="stat-label">Satisfação</div></div>
                    </div>
                </div>
                <div class="col-lg-5 d-none d-lg-block">
                    <div style="filter: brightness(0) invert(1); opacity: 0.8; text-align: center;">
                        <i class="fas fa-chalkboard-user" style="font-size: 200px;"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="recursos" class="features">
        <div class="container">
            <div class="section-title">
                <h2>Ferramentas para Professores</h2>
                <p>Tudo que você precisa para gerenciar suas atividades</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-chalkboard"></i></div>
                        <h3>Minhas Turmas</h3>
                        <p>Visualize todas as suas turmas, horários e informações dos alunos.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-book-open"></i></div>
                        <h3>Lançamento de Notas</h3>
                        <p>Lançamento rápido de notas com cálculo automático de médias.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-clipboard-list"></i></div>
                        <h3>Registro de Chamada</h3>
                        <p>Marque presença dos alunos de forma simples e rápida.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-calendar-alt"></i></div>
                        <h3>Meu Horário</h3>
                        <p>Consulte seu horário de aulas em tempo real.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                        <h3>Relatórios</h3>
                        <p>Gere relatórios de desempenho e frequência dos alunos.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-tasks"></i></div>
                        <h3>Atividades</h3>
                        <p>Gerencie atividades e trabalhos dos alunos.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Info Professores -->
    <section id="professor" class="professor-info">
        <div class="container">
            <div class="section-title">
                <h2>Por que usar o SIGE Angola?</h2>
                <p>Tecnologia que facilita o seu trabalho</p>
            </div>
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="professor-card">
                        <i class="fas fa-clock"></i>
                        <h4>Economia de Tempo</h4>
                        <p>Processos automatizados que reduzem tarefas manuais.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card">
                        <i class="fas fa-mobile-alt"></i>
                        <h4>Acesso Mobile</h4>
                        <p>Acesse de qualquer lugar, a qualquer momento.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card">
                        <i class="fas fa-chart-simple"></i>
                        <h4>Relatórios Online</h4>
                        <p>Acompanhe o desempenho dos alunos em tempo real.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="professor-card">
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
                <h2>Compromisso com a Educação</h2>
                <p>Valores que nos guiam</p>
            </div>
            <div class="row g-4">
                <div class="col-md-3"><div class="value-card"><i class="fas fa-handshake"></i><h4>Compromisso</h4><p>Comprometidos com a excelência na educação.</p></div></div>
                <div class="col-md-3"><div class="value-card"><i class="fas fa-lightbulb"></i><h4>Inovação</h4><p>Tecnologia de ponta para a educação.</p></div></div>
                <div class="col-md-3"><div class="value-card"><i class="fas fa-shield-alt"></i><h4>Transparência</h4><p>Dados seguros e confiáveis.</p></div></div>
                <div class="col-md-3"><div class="value-card"><i class="fas fa-users"></i><h4>Colaboração</h4><p>Trabalho em equipe para o sucesso.</p></div></div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contato" class="contact">
        <div class="container">
            <div class="section-title">
                <h2>Precisa de Ajuda?</h2>
                <p>Nossa equipe está pronta para atender você</p>
            </div>
            <div class="contact-info">
                <div class="contact-card"><i class="fas fa-phone-alt"></i><h4>Telefone</h4><p>+244 923 456 789<br>+244 923 456 788</p></div>
                <div class="contact-card"><i class="fas fa-envelope"></i><h4>E-mail</h4><p>suporte@sige.ao<br>professores@sige.ao</p></div>
                <div class="contact-card"><i class="fab fa-whatsapp"></i><h4>WhatsApp</h4><p>+244 923 456 789<br>Suporte 24h</p></div>
                <div class="contact-card"><i class="fas fa-video"></i><h4>Tutoriais</h4><p>Vídeos tutoriais disponíveis</p></div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-4"><h4><i class="fas fa-chalkboard-user"></i> SIGE Angola</h4><p>A plataforma completa para gestão escolar.</p><div class="social-links"><a href="#"><i class="fab fa-facebook-f"></i></a><a href="#"><i class="fab fa-instagram"></i></a><a href="#"><i class="fab fa-linkedin-in"></i></a><a href="#"><i class="fab fa-youtube"></i></a></div></div>
                <div class="col-md-4"><h4>Links Rápidos</h4><ul class="list-unstyled"><li><a href="#home">Início</a></li><li><a href="#sobre">Sobre</a></li><li><a href="#recursos">Recursos</a></li><li><a href="#professor">Para Professores</a></li><li><a href="#valores">Valores</a></li></ul></div>
                <div class="col-md-4"><h4>Newsletter</h4><p>Receba novidades e atualizações</p><div class="input-group"><input type="email" class="form-control" placeholder="Seu e-mail"><button class="btn btn-primary">Assinar</button></div></div>
            </div>
            <hr class="mt-4" style="border-color: rgba(255,255,255,0.1);">
            <div class="text-center"><p>&copy; 2026 SIGE Angola - Sistema Integrado de Gestão Escolar</p></div>
        </div>
    </footer>

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
                        <div class="mb-3"><label>E-mail ou Usuário</label><input type="text" name="email" class="form-control" placeholder="seu@email.com ou usuario" required></div>
                        <div class="mb-3"><label>Senha</label><input type="password" name="password" class="form-control" placeholder="••••••••" required></div>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check"><input type="checkbox" class="form-check-input" id="remember"><label class="form-check-label">Lembrar-me</label></div>
                            <a href="#" class="text-muted" data-bs-toggle="modal" data-bs-target="#recuperarModal" data-bs-dismiss="modal">Esqueceu a senha?</a>
                        </div>
                        <button type="submit" class="btn btn-login w-100"><i class="fas fa-arrow-right"></i> Entrar</button>
                    </form>
                    
                    <hr>
                    <div class="text-center"><p class="text-muted">Não tem acesso? <a href="#contato">Contacte a coordenação</a></p></div>
                    <div class="separator"><span>ou</span></div>
                    <a href="../../login.php" class="btn-voltar-index"><i class="fas fa-arrow-left"></i> Voltar para página inicial</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recuperar Senha Modal -->
    <div class="modal fade modal-login" id="recuperarModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-key"></i> Recuperar Senha</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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
        document.querySelectorAll('a[href^="#"]').forEach(anchor => { anchor.addEventListener('click', function (e) { e.preventDefault(); const target = document.querySelector(this.getAttribute('href')); if (target) target.scrollIntoView({ behavior: 'smooth' }); }); });
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.style.opacity = '1'; entry.target.style.transform = 'translateY(0)'; } }); }, observerOptions);
        document.querySelectorAll('.feature-card, .professor-card, .value-card, .contact-card').forEach(el => { el.style.opacity = '0'; el.style.transform = 'translateY(30px)'; el.style.transition = 'all 0.6s ease-out'; observer.observe(el); });
        let codigoEnviado = '';
        function enviarCodigo() { const email = $('#recuperarEmail').val(); if (!email) { alert('Digite seu e-mail'); return; } codigoEnviado = Math.floor(100000 + Math.random() * 900000).toString(); alert(`Código de teste: ${codigoEnviado}\n(Em produção, seria enviado para ${email})`); $('#passo1').hide(); $('#passo2').show(); for(let i=1;i<=6;i++) $(`#codigo${i}`).val(''); $('#codigo1').focus(); }
        function moveToNext(current, nextId) { if (current.value.length === 1) document.getElementById(nextId).focus(); }
        function verificarCodigo() { let codigo = ''; for(let i=1;i<=6;i++) codigo += $(`#codigo${i}`).val(); if (codigo === codigoEnviado) { $('#passo2').hide(); $('#passo3').show(); } else alert('Código inválido'); }
        function reenviarCodigo() { codigoEnviado = Math.floor(100000 + Math.random() * 900000).toString(); alert(`Novo código: ${codigoEnviado}`); }
        function alterarSenha() { const nova = $('#novaSenha').val(); const conf = $('#confirmarSenha').val(); if (nova.length < 6) { alert('Mínimo 6 caracteres'); return; } if (nova !== conf) { alert('Senhas não coincidem'); return; } $('#passo3').hide(); $('#sucessoMsg').show(); }
        $('#recuperarModal').on('hidden.bs.modal', function () { $('#passo1').show(); $('#passo2').hide(); $('#passo3').hide(); $('#sucessoMsg').hide(); $('#recuperarEmail').val(''); for(let i=1;i<=6;i++) $(`#codigo${i}`).val(''); $('#novaSenha, #confirmarSenha').val(''); });
    </script>
</body>
</html>