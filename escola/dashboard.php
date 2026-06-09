<?php
// escola/dashboard.php - Dashboard Principal

require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');

// Buscar estatísticas
$stats = [];

// Total de alunos
$sql = "SELECT COUNT(*) as total FROM estudantes e INNER JOIN matriculas m ON m.estudante_id = e.id WHERE m.escola_id = :escola_id AND m.status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$sql = "SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = :escola_id AND cargo LIKE '%professor%'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$sql = "SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql = "SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$stats['disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Buscar escola
$sql = "SELECT nome, logo FROM escolas WHERE id = :id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card { text-align: center; padding: 20px; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 2.5rem; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.9rem; }
        .stat-icon { font-size: 2rem; color: #1A2A6C; margin-bottom: 10px; }
        
        .btn-ajuda {
            position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000;
            transition: all 0.3s ease; display: flex; align-items: center; justify-content: center;
        }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text {
            position: absolute; right: 70px; background: #333; color: white;
            padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap;
            opacity: 0; transition: opacity 0.3s; pointer-events: none;
        }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; }
        .btn-ajuda i { font-size: 24px; } }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-primary-custom { background: #006B3E; border: none; }
        .btn-primary-custom:hover { background: #004d2d; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
                    <p>Bem-vindo, <?php echo htmlspecialchars($usuario_nome); ?>!</p>
                </div>
                <div><span><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?></span></div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-number"><?php echo $stats['alunos']; ?></div>
                    <div class="stat-label">Alunos Matriculados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                    <div class="stat-number"><?php echo $stats['professores']; ?></div>
                    <div class="stat-label">Professores</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-number"><?php echo $stats['turmas']; ?></div>
                    <div class="stat-label">Turmas Ativas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-icon"><i class="fas fa-book"></i></div>
                    <div class="stat-number"><?php echo $stats['disciplinas']; ?></div>
                    <div class="stat-label">Disciplinas</div>
                </div>
            </div>
        </div>
        
        <!-- Acessos Rápidos -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                <h5 class="mb-0"><i class="fas fa-rocket"></i> Acessos Rápidos</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2"><a href="notas/index.php" class="btn btn-outline-success w-100"><i class="fas fa-edit"></i> Lançar Notas</a></div>
                    <div class="col-md-3 mb-2"><a href="perfil.php" class="btn btn-outline-primary w-100"><i class="fas fa-user-circle"></i> Meu Perfil</a></div>
                    <div class="col-md-3 mb-2"><a href="suporte/chamados.php" class="btn btn-outline-info w-100"><i class="fas fa-headset"></i> Suporte</a></div>
                    <div class="col-md-3 mb-2"><a href="suporte/faq.php" class="btn btn-outline-secondary w-100"><i class="fas fa-question-circle"></i> FAQ</a></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Dashboard</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-chart-line"></i> Sobre o Dashboard</h5>
                        <p>O Dashboard é a página inicial onde você acompanha as principais métricas da sua escola.</p>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-info-circle"></i> O que você encontra aqui:</h5>
                        <ul>
                            <li><strong>Cards de Estatísticas:</strong> Alunos, professores, turmas e disciplinas</li>
                            <li><strong>Acessos Rápidos:</strong> Botões para as funcionalidades mais usadas</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-lightbulb"></i> Dicas:</h5>
                        <ul>
                            <li>Clique nos cards para acessar páginas detalhadas</li>
                            <li>Use o menu lateral para navegar entre as funcionalidades</li>
                            <li>Em caso de dúvidas, consulte a FAQ ou abra um chamado</li>
                        </ul>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> <strong>Precisa de mais ajuda?</strong>
                        <a href="suporte/faq.php" class="alert-link">Veja as perguntas frequentes</a> ou 
                        <a href="suporte/chamados.php" class="alert-link">abra um chamado de suporte</a>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="suporte/faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a>
                    <a href="suporte/chamados.php" class="btn btn-info"><i class="fas fa-headset"></i> Abrir Chamado</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        document.getElementById('btnAjuda')?.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        });
    </script>
</body>
</html>