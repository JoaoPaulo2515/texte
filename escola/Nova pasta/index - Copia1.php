<?php
// escola/index.php - Dashboard da Escola
require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Estatísticas da Escola
$stats = [];

// Total de Alunos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM professores WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_turmas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de Disciplinas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_disciplinas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Média Geral de Notas
$stmt = $conn->prepare("
    SELECT AVG(media_final) as media_geral 
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media_geral'] ?? 0, 1);

// Taxa de Aprovação
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(*) as total
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$aprovacao = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['taxa_aprovacao'] = $aprovacao['total'] > 0 ? round(($aprovacao['aprovados'] / $aprovacao['total']) * 100, 1) : 0;

// Alunos por Turma (para gráfico)
$stmt = $conn->prepare("
    SELECT t.nome as turma_nome, COUNT(m.id) as total
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    GROUP BY t.id
    ORDER BY t.nome
");
$stmt->execute([':escola_id' => $escola_id]);
$alunos_por_turma = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Próximos Eventos
$proximos_eventos = [];
try {
    $stmt = $conn->query("SHOW TABLES LIKE 'calendario_escolar'");
    if ($stmt->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT * FROM calendario_escolar 
            WHERE escola_id = :escola_id AND data_inicio >= CURDATE()
            ORDER BY data_inicio ASC
            LIMIT 5
        ");
        $stmt->execute([':escola_id' => $escola_id]);
        $proximos_eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $proximos_eventos = [];
}

// Últimas Notificações
$stmt = $conn->prepare("
    SELECT * FROM notificacoes 
    WHERE escola_id = :escola_id OR escola_id IS NULL
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([':escola_id' => $escola_id]);
$notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Navegação com Submenu */
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-submenu .nav-link i {
            font-size: 0.9em;
            width: 20px;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
        }
        
        .stat-icon.primary { background: #e3f2fd; color: #1976d2; }
        .stat-icon.success { background: #e8f5e9; color: #388e3c; }
        .stat-icon.warning { background: #fff3e0; color: #f57c00; }
        .stat-icon.info { background: #e0f7fa; color: #0097a7; }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        
        .notification-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .event-item {
            border-left: 3px solid #ffc107;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
            <div class="user-info-sidebar mt-2">
                <small><i class="fas fa-user"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <!-- Dashboard -->
            <li class="nav-item">
                <a href="index.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- Secretaria com Submenu (NOVO) -->
            <li class="nav-item has-submenu" id="menuSecretaria">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-building"></i>
                    <span>Secretaria</span>
                </a>
                <ul class="nav-submenu" id="submenuSecretaria">
                    <li class="nav-item">
                        <a href="secretaria/lista_alunos.php" class="nav-link">
                            <i class="fas fa-list"></i>
                            <span>Lista de Alunos</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="secretaria/alunos_matriculados.php" class="nav-link">
                            <i class="fas fa-check-circle"></i>
                            <span>Alunos Matriculados</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="secretaria/inscricoes.php" class="nav-link">
                            <i class="fas fa-file-signature"></i>
                            <span>Inscrições</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="secretaria/rematricula.php" class="nav-link">
                            <i class="fas fa-sync-alt"></i>
                            <span>Rematrícula</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="secretaria/matricula.php" class="nav-link">
                            <i class="fas fa-user-plus"></i>
                            <span>Matrícula</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Alunos com Submenu -->
            <li class="nav-item has-submenu" id="menuAlunos">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-users"></i>
                    <span>Alunos</span>
                </a>
                <ul class="nav-submenu" id="submenuAlunos">
                    <li class="nav-item">
                        <a href="alunos/index.php" class="nav-link">
                            <i class="fas fa-list"></i>
                            <span>Listar Alunos</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="alunos/cadastrar.php" class="nav-link">
                            <i class="fas fa-user-plus"></i>
                            <span>Cadastrar Aluno</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Professores com Submenu -->
            <li class="nav-item has-submenu" id="menuProfessores">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chalkboard-user"></i>
                    <span>Professores</span>
                </a>
                <ul class="nav-submenu" id="submenuProfessores">
                    <li class="nav-item">
                        <a href="professores/index.php" class="nav-link">
                            <i class="fas fa-list"></i>
                            <span>Listar Professores</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="professores/cadastrar.php" class="nav-link">
                            <i class="fas fa-user-plus"></i>
                            <span>Cadastrar Professor</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Turmas com Submenu -->
            <li class="nav-item has-submenu" id="menuTurmas">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-users-group"></i>
                    <span>Turmas</span>
                </a>
                <ul class="nav-submenu" id="submenuTurmas">
                    <li class="nav-item">
                        <a href="turmas/index.php" class="nav-link">
                            <i class="fas fa-list"></i>
                            <span>Listar Turmas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="turmas/cadastrar.php" class="nav-link">
                            <i class="fas fa-plus-circle"></i>
                            <span>Cadastrar Turma</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Disciplinas com Submenu -->
            <li class="nav-item has-submenu" id="menuDisciplinas">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-book"></i>
                    <span>Disciplinas</span>
                </a>
                <ul class="nav-submenu" id="submenuDisciplinas">
                    <li class="nav-item">
                        <a href="disciplinas/index.php" class="nav-link">
                            <i class="fas fa-list"></i>
                            <span>Listar Disciplinas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="disciplinas/cadastrar.php" class="nav-link">
                            <i class="fas fa-plus-circle"></i>
                            <span>Cadastrar Disciplina</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Notas com Submenu -->
            <li class="nav-item has-submenu" id="menuNotas">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Notas</span>
                </a>
                <ul class="nav-submenu" id="submenuNotas">
                    <li class="nav-item">
                        <a href="notas/index.php" class="nav-link">
                            <i class="fas fa-edit"></i>
                            <span>Lançar Notas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="notas/conselho.php" class="nav-link">
                            <i class="fas fa-chalkboard"></i>
                            <span>Conselho de Notas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="notas/relatorios.php" class="nav-link">
                            <i class="fas fa-chart-line"></i>
                            <span>Relatórios</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Chamada com Submenu -->
            <li class="nav-item has-submenu" id="menuChamada">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-calendar-check"></i>
                    <span>Chamada</span>
                </a>
                <ul class="nav-submenu" id="submenuChamada">
                    <li class="nav-item">
                        <a href="chamada/index.php" class="nav-link">
                            <i class="fas fa-check-circle"></i>
                            <span>Registrar Chamada</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="chamada/relatorio.php" class="nav-link">
                            <i class="fas fa-chart-bar"></i>
                            <span>Relatório de Frequência</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="chamada/justificar.php" class="nav-link">
                            <i class="fas fa-notes-medical"></i>
                            <span>Justificar Faltas</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Biblioteca com Submenu -->
            <li class="nav-item has-submenu" id="menuBiblioteca">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-book-open"></i>
                    <span>Biblioteca</span>
                </a>
                <ul class="nav-submenu" id="submenuBiblioteca">
                    <li class="nav-item">
                        <a href="biblioteca/index.php" class="nav-link">
                            <i class="fas fa-search"></i>
                            <span>Visualizar Acervo</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="biblioteca/cadastrar.php" class="nav-link">
                            <i class="fas fa-plus-circle"></i>
                            <span>Cadastrar Livro</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chart-line"></i>
                    <span>Relatórios</span>
                </a>
                <ul class="nav-submenu" id="submenuRelatoriosEscola">
                    <li class="nav-item">
                        <a href="relatorios/notas.php" class="nav-link">
                            <i class="fas fa-graduation-cap"></i>
                            <span>Relatório de Notas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="relatorios/frequencia.php" class="nav-link">
                            <i class="fas fa-calendar-check"></i>
                            <span>Relatório de Frequência</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="relatorios/desempenho.php" class="nav-link">
                            <i class="fas fa-chart-line"></i>
                            <span>Análise de Desempenho</span>
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Sair -->
            <li class="nav-item">
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
            <div class="user-menu">
                <i class="fas fa-bell"></i>
                <span><?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></span>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon primary"><i class="fas fa-users"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_alunos']); ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon success"><i class="fas fa-chalkboard-user"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_professores']); ?></div>
                <div class="stat-label">Professores</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon warning"><i class="fas fa-users-group"></i></div></div>
                <div class="stat-value"><?php echo number_format($stats['total_turmas']); ?></div>
                <div class="stat-label">Turmas Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon info"><i class="fas fa-graduation-cap"></i></div></div>
                <div class="stat-value"><?php echo $stats['media_geral']; ?></div>
                <div class="stat-label">Média Geral</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Alunos por Turma</div>
                    <div class="card-body">
                        <canvas id="turmasChart" height="300"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Taxa de Aprovação</div>
                    <div class="card-body text-center">
                        <canvas id="aprovacaoChart" height="200"></canvas>
                        <h3 class="mt-3"><?php echo $stats['taxa_aprovacao']; ?>%</h3>
                        <p class="text-muted">Taxa de aprovação geral</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar-alt"></i> Próximos Eventos</div>
                    <div class="card-body">
                        <?php if (!empty($proximos_eventos)): ?>
                            <?php foreach ($proximos_eventos as $evento): ?>
                            <div class="event-item">
                                <strong><?php echo date('d/m/Y', strtotime($evento['data_inicio'])); ?></strong>
                                <h6 class="mb-1"><?php echo htmlspecialchars($evento['titulo']); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars($evento['descricao']); ?></small>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted">Nenhum evento programado</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-bell"></i> Últimas Notificações</div>
                    <div class="card-body">
                        <?php foreach ($notificacoes as $notif): ?>
                        <div class="notification-item">
                            <strong><?php echo htmlspecialchars($notif['titulo']); ?></strong>
                            <p class="mb-0 small"><?php echo htmlspecialchars($notif['mensagem']); ?></p>
                            <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($notificacoes)): ?>
                        <p class="text-center text-muted">Nenhuma notificação</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Função para toggle dos submenus
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            
            // Fecha outros submenus abertos
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            
            // Alterna o submenu atual
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Manter submenu aberto baseado na página atual
        const currentPage = window.location.pathname;
        
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
        if (currentPage.includes('alunos')) {
            $('#menuAlunos').addClass('open');
            $('#submenuAlunos').addClass('show');
        }
        if (currentPage.includes('professores')) {
            $('#menuProfessores').addClass('open');
            $('#submenuProfessores').addClass('show');
        }
        if (currentPage.includes('turmas')) {
            $('#menuTurmas').addClass('open');
            $('#submenuTurmas').addClass('show');
        }
        if (currentPage.includes('disciplinas')) {
            $('#menuDisciplinas').addClass('open');
            $('#submenuDisciplinas').addClass('show');
        }
        if (currentPage.includes('notas')) {
            $('#menuNotas').addClass('open');
            $('#submenuNotas').addClass('show');
        }
        if (currentPage.includes('chamada')) {
            $('#menuChamada').addClass('open');
            $('#submenuChamada').addClass('show');
        }
        if (currentPage.includes('biblioteca')) {
            $('#menuBiblioteca').addClass('open');
            $('#submenuBiblioteca').addClass('show');
        }
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatoriosEscola').addClass('show');
        }
        
        // Gráfico de alunos por turma
        const ctx1 = document.getElementById('turmasChart').getContext('2d');
        const turmasLabels = <?php echo json_encode(array_column($alunos_por_turma, 'turma_nome')); ?>;
        const turmasValues = <?php echo json_encode(array_column($alunos_por_turma, 'total')); ?>;
        
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: turmasLabels,
                datasets: [{
                    label: 'Alunos',
                    data: turmasValues,
                    backgroundColor: '#006B3E'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        
        // Gráfico de aprovação
        const ctx2 = document.getElementById('aprovacaoChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $stats['taxa_aprovacao']; ?>, <?php echo 100 - $stats['taxa_aprovacao']; ?>],
                    backgroundColor: ['#28a745', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>