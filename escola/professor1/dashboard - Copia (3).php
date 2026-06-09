<?php
// escola/professor/dashboard.php - Dashboard do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ESTATÍSTICAS DO PROFESSOR
// ============================================

// Total de turmas
$sql_turmas = "SELECT COUNT(DISTINCT turma_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$total_turmas = $stmt_turmas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql_disciplinas = "SELECT COUNT(DISTINCT disciplina_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$total_disciplinas = $stmt_disciplinas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de alunos
$sql_alunos = "
    SELECT COUNT(DISTINCT m.estudante_id) as total 
    FROM matriculas m
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = m.turma_id
    WHERE pdt.professor_id = :professor_id AND m.status = 'ativa'
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':professor_id' => $professor_id]);
$total_alunos = $stmt_alunos->fetch(PDO::FETCH_ASSOC)['total'];

// Notas lançadas este mês
$sql_notas_mes = "
    SELECT COUNT(*) as total 
    FROM notas n
    INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = n.disciplina_id
    WHERE pdt.professor_id = :professor_id 
    AND MONTH(n.created_at) = MONTH(NOW()) 
    AND YEAR(n.created_at) = YEAR(NOW())
";
$stmt_notas = $conn->prepare($sql_notas_mes);
$stmt_notas->execute([':professor_id' => $professor_id]);
$notas_mes = $stmt_notas->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================
// MINHAS TURMAS
// ============================================
$sql_minhas_turmas = "
    SELECT DISTINCT 
        t.id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano DESC, t.nome
    LIMIT 4
";
$stmt_minhas_turmas = $conn->prepare($sql_minhas_turmas);
$stmt_minhas_turmas->execute([':professor_id' => $professor_id]);
$minhas_turmas = $stmt_minhas_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PRÓXIMAS ATIVIDADES (aulas de hoje)
// ============================================
$hoje = date('Y-m-d');
$sql_atividades_hoje = "
    SELECT DISTINCT 
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        'Aula' as tipo
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    LIMIT 5
";
$stmt_atividades = $conn->prepare($sql_atividades_hoje);
$stmt_atividades->execute([':professor_id' => $professor_id]);
$proximas_atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CHAMADA DE HOJE
// ============================================
$sql_chamada_hoje = "
    SELECT COUNT(*) as total 
    FROM chamada 
    WHERE professor_id = :professor_id AND data_aula = :hoje
";
$stmt_chamada = $conn->prepare($sql_chamada_hoje);
$stmt_chamada->execute([':professor_id' => $professor_id, ':hoje' => $hoje]);
$chamada_hoje = $stmt_chamada->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================
// HORÁRIO DE HOJE
// ============================================
$dia_semana = date('w'); // 0=Domingo, 1=Segunda, etc.
$dia_nome = '';
switch($dia_semana) {
    case 1: $dia_nome = 'Segunda-feira'; break;
    case 2: $dia_nome = 'Terça-feira'; break;
    case 3: $dia_nome = 'Quarta-feira'; break;
    case 4: $dia_nome = 'Quinta-feira'; break;
    case 5: $dia_nome = 'Sexta-feira'; break;
    case 6: $dia_nome = 'Sábado'; break;
    default: $dia_nome = 'Domingo';
}

$sql_horario_hoje = "
    SELECT DISTINCT 
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    LIMIT 4
";
$stmt_horario = $conn->prepare($sql_horario_hoje);
$stmt_horario->execute([':professor_id' => $professor_id]);
$horario_hoje = $stmt_horario->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALENDÁRIO DE PROVAS
// ============================================
$sql_calendario_provas = "
    SELECT 
        p.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome
    FROM provas p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    WHERE p.escola_id = :escola_id 
    AND p.data_prova >= CURDATE()
    ORDER BY p.data_prova ASC
    LIMIT 5
";
$stmt_provas = $conn->prepare($sql_calendario_provas);
$stmt_provas->execute([':escola_id' => $escola_id]);
$calendario_provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
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
            font-size: 1.3em;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.8;
        }
        
        /* Navegação */
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
            font-size: 0.95em;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            border-left: 4px solid #FFD700;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Submenu */
        .has-submenu {
            position: relative;
        }
        
        .has-submenu > .nav-link {
            cursor: pointer;
        }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s;
        }
        
        .has-submenu.open > .nav-link::after {
            transform: rotate(180deg);
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 55px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .has-submenu.open .nav-submenu {
            max-height: 500px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 25px;
            font-size: 0.85em;
        }
        
        .nav-submenu .nav-link i {
            font-size: 0.8em;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .welcome-text h2 {
            font-size: 1.5em;
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: #666;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
        }
        
        /* Cards */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(0,107,62,0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8em;
            color: #006B3E;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.85em;
        }
        
        /* Cards de Conteúdo */
        .content-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .content-card .card-header {
            background: white;
            border-bottom: 2px solid #006B3E;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .content-card .card-body {
            padding: 15px 20px;
        }
        
        .list-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            transition: transform 0.2s;
        }
        
        .list-item:hover {
            transform: translateX(5px);
        }
        
        .list-item:last-child {
            border-bottom: none;
        }
        
        .badge-turma {
            background: #006B3E;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
        }
        
        .badge-prova {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
        }
        
        .btn-ver-mais {
            background: #f8f9fa;
            color: #006B3E;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
            text-decoration: none;
        }
        
        .btn-ver-mais:hover {
            background: #006B3E;
            color: white;
        }
        
        .horario-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 10px;
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
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
            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        .menu-toggle {
            display: none;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .btn-primary:hover {
            background: #004d2d;
        }
        
        .btn-success {
            background: #28a745;
            border: none;
        }
        
        .btn-info {
            background: #17a2b8;
            border: none;
        }
        
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #212529;
        }
        
        .gap-2 {
            gap: 10px;
        }
        
        .flex-wrap {
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE Angola</h3>
            <p>Área do Professor</p>
        </div>
        
        <ul class="nav-menu">
            <!-- Dashboard -->
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
            <!-- Menu Acadêmico -->
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-graduation-cap"></i> Menu Acadêmico
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="minhas_turmas.php" class="nav-link"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                    <li class="nav-item"><a href="lancar_notas.php" class="nav-link"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                    <li class="nav-item"><a href="registrar_chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                    <li class="nav-item"><a href="atividades.php" class="nav-link"><i class="fas fa-tasks"></i> Atividades</a></li>
                    <li class="nav-item"><a href="meus_alunos.php" class="nav-link"><i class="fas fa-users"></i> Meus Alunos</a></li>
                </ul>
            </li>
            
            <!-- Meu Horário -->
            <li class="nav-item">
                <a href="meu_horario.php" class="nav-link">
                    <i class="fas fa-calendar-week"></i> Meu Horário
                </a>
            </li>
            
            <!-- Relatórios -->
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chart-line"></i> Relatórios
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="relatorios/index.php" class="nav-link"><i class="fas fa-home"></i> Index</a></li>
                    <li class="nav-item"><a href="relatorios/mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                    <li class="nav-item"><a href="relatorios/pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
                </ul>
            </li>
            
            <!-- Agenda -->
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                    <li class="nav-item"><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
                </ul>
            </li>
            
            <!-- Financeiro -->
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> Financeiro
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                    <li class="nav-item"><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                    <li class="nav-item"><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                    <li class="nav-item"><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                    <li class="nav-item"><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                    <li class="nav-item"><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
                </ul>
            </li>
            
            <!-- Conselho de Nota -->
            <li class="nav-item">
                <a href="conselho_nota.php" class="nav-link">
                    <i class="fas fa-chalkboard-user"></i> Conselho de Nota
                </a>
            </li>
            
            <!-- Chamada -->
            <li class="nav-item">
                <a href="chamada.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i> Chamada
                </a>
            </li>
            
            <!-- Lançamento de Nota -->
            <li class="nav-item">
                <a href="lancamento_nota.php" class="nav-link">
                    <i class="fas fa-edit"></i> Lançamento de Nota
                </a>
            </li>
            
            <!-- Biblioteca -->
            <li class="nav-item">
                <a href="biblioteca.php" class="nav-link">
                    <i class="fas fa-book"></i> Biblioteca
                </a>
            </li>
            
            <!-- Proposta de Prova -->
            <li class="nav-item">
                <a href="proposta_prova.php" class="nav-link">
                    <i class="fas fa-file-alt"></i> Proposta de Prova
                </a>
            </li>
            
            <!-- Sair -->
            <li class="nav-item">
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="welcome-text">
                <h2>Bem-vindo, <?php echo htmlspecialchars($professor['professor_nome']); ?>!</h2>
                <p><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?> | <i class="fas fa-clock"></i> Sistema de Gestão Escolar</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-chalkboard"></i>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($professor['professor_nome']); ?></strong><br>
                    <small class="text-muted">Professor</small>
                </div>
            </div>
        </div>
        
        <!-- Cards Estatísticos -->
        <div class="row">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?php echo $total_turmas; ?></div>
                            <div class="stat-label">Total de Turmas</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?php echo $total_disciplinas; ?></div>
                            <div class="stat-label">Disciplinas</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-book"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?php echo $total_alunos; ?></div>
                            <div class="stat-label">Alunos</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?php echo $notas_mes; ?></div>
                            <div class="stat-label">Notas (este mês)</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Próximas Atividades -->
            <div class="col-md-6">
                <div class="content-card">
                    <div class="card-header">
                        <i class="fas fa-tasks"></i> Próximas Atividades
                    </div>
                    <div class="card-body">
                        <?php if (empty($proximas_atividades)): ?>
                            <p class="text-muted text-center">Nenhuma atividade agendada.</p>
                        <?php else: ?>
                            <?php foreach ($proximas_atividades as $atividade): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $atividade['turma_ano'] . 'ª ' . htmlspecialchars($atividade['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($atividade['disciplina_nome']); ?>
                                        </small>
                                    </div>
                                    <span class="badge-turma">
                                        <i class="fas fa-clock"></i> Hoje
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <a href="atividades.php" class="btn-ver-mais">
                                Ver todas atividades <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Horário de Hoje -->
            <div class="col-md-6">
                <div class="content-card">
                    <div class="card-header">
                        <i class="fas fa-clock"></i> Horário de Hoje - <?php echo $dia_nome; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($horario_hoje)): ?>
                            <p class="text-muted text-center">Nenhuma aula agendada para hoje.</p>
                        <?php else: ?>
                            <?php foreach ($horario_hoje as $horario): ?>
                            <div class="horario-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($horario['disciplina_nome']); ?>
                                        </small>
                                    </div>
                                    <a href="registrar_chamada.php?turma_id=<?php echo $turma_id ?? ''; ?>&disciplina_id=<?php echo $disciplina_id ?? ''; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-clipboard-list"></i> Chamada
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <a href="meu_horario.php" class="btn-ver-mais">
                                Ver horário completo <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Minhas Turmas -->
            <div class="col-md-6">
                <div class="content-card">
                    <div class="card-header">
                        <i class="fas fa-chalkboard"></i> Minhas Turmas
                    </div>
                    <div class="card-body">
                        <?php if (empty($minhas_turmas)): ?>
                            <p class="text-muted text-center">Nenhuma turma atribuída.</p>
                        <?php else: ?>
                            <?php foreach ($minhas_turmas as $turma): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $turma['turma_ano'] . 'ª ' . htmlspecialchars($turma['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-users"></i> <?php echo $turma['total_alunos']; ?> alunos | 
                                            <i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno']); ?> | 
                                            Sala: <?php echo $turma['sala'] ?: 'N/D'; ?>
                                        </small>
                                    </div>
                                    <div>
                                        <a href="minhas_turmas.php?turma_id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-info text-white">
                                            <i class="fas fa-eye"></i> Ver
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <a href="minhas_turmas.php" class="btn-ver-mais">
                                Ver todas turmas <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Calendário de Provas -->
            <div class="col-md-6">
                <div class="content-card">
                    <div class="card-header">
                        <i class="fas fa-calendar-alt"></i> Calendário de Provas
                    </div>
                    <div class="card-body">
                        <?php if (empty($calendario_provas)): ?>
                            <p class="text-muted text-center">Nenhuma prova agendada.</p>
                        <?php else: ?>
                            <?php foreach ($calendario_provas as $prova): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-chalkboard"></i> <?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge-prova">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($prova['tipo'] ?? 'Prova'); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <a href="calendario_provas.php" class="btn-ver-mais">
                                Ver calendário completo <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="row mt-2">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header">
                        <i class="fas fa-bolt"></i> Ações Rápidas
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <a href="registrar_chamada.php" class="btn btn-primary">
                                <i class="fas fa-clipboard-list"></i> Registrar Chamada
                            </a>
                            <a href="lancar_notas.php" class="btn btn-success">
                                <i class="fas fa-graduation-cap"></i> Lançar Notas
                            </a>
                            <a href="minhas_turmas.php" class="btn btn-info text-white">
                                <i class="fas fa-chalkboard"></i> Minhas Turmas
                            </a>
                            <a href="meu_horario.php" class="btn btn-warning">
                                <i class="fas fa-calendar-week"></i> Meu Horário
                            </a>
                            <a href="relatorios/index.php" class="btn btn-secondary">
                                <i class="fas fa-chart-line"></i> Relatórios
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle submenu
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
            }
        }
        
        // Toggle sidebar mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Fechar sidebar ao clicar em link (mobile)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                }
            });
        });
        
        // Manter submenus abertos baseado na URL atual
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
            if (currentUrl.includes(link.getAttribute('href'))) {
                const parent = link.closest('.has-submenu');
                if (parent) parent.classList.add('open');
            }
        });
    </script>
</body>
</html>