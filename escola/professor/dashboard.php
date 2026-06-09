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
// PRÓXIMAS ATIVIDADES
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
$dia_semana = date('w');
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
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.id as disciplina_id,
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
$calendario_provas = [];
try {
    $sql_check = "SHOW TABLES LIKE 'calendario_provas'";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute();
    $tabela_existe = $stmt_check->rowCount() > 0;
    
    if ($tabela_existe) {
        $sql_calendario_provas = "
            SELECT 
                cp.*,
                t.nome as turma_nome,
                t.ano as turma_ano,
                d.nome as disciplina_nome
            FROM calendario_provas cp
            INNER JOIN turmas t ON t.id = cp.turma_id
            INNER JOIN disciplinas d ON d.id = cp.disciplina_id
            WHERE cp.professor_id = :professor_id
            AND cp.data_prova >= CURDATE()
            ORDER BY cp.data_prova ASC
            LIMIT 5
        ";
        $stmt_provas = $conn->prepare($sql_calendario_provas);
        $stmt_provas->execute([':professor_id' => $professor_id]);
        $calendario_provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $calendario_provas = [];
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dashboard Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --orange: #fd7e14;
            --purple: #6f42c1;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           SIDEBAR
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--primary-gradient);
            color: white;
            transition: var(--transition);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.1);
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .sidebar-header {
            padding: 30px 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header .logo {
            font-size: 3rem;
            margin-bottom: 12px;
        }

        .sidebar-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.75rem;
            opacity: 0.7;
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
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: var(--transition);
            gap: 12px;
            font-size: 0.9rem;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding-left: 30px;
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left: 4px solid #FFD700;
        }

        .nav-link i {
            width: 22px;
            text-align: center;
            font-size: 1.1rem;
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
            font-size: 0.85rem;
        }

        .nav-submenu .nav-link i {
            font-size: 0.8rem;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Menu Toggle */
        .menu-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary-green);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            cursor: pointer;
            display: none;
            transition: var(--transition);
        }

        .menu-toggle:hover {
            transform: scale(1.05);
            background: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }

        /* ============================================
           TOP BAR
        ============================================ */
        .top-bar {
            background: white;
            border-radius: 24px;
            padding: 20px 25px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }

        .top-bar:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }

        .welcome-text h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #212529;
        }

        .welcome-text p {
            color: #6c757d;
            margin: 0;
            font-size: 0.85rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 55px;
            height: 55px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transition: var(--transition);
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 22px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, rgba(0, 107, 62, 0.1) 0%, rgba(26, 42, 108, 0.1) 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: var(--primary-green);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #212529;
            line-height: 1.2;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================================
           CONTENT CARDS
        ============================================ */
        .content-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: var(--transition);
            height: 100%;
        }

        .content-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 18px 22px;
            font-weight: 700;
            color: var(--primary-green);
            border-bottom: 2px solid var(--primary-green);
            font-size: 1rem;
        }

        .card-header-custom i {
            margin-right: 10px;
            color: var(--primary-green);
        }

        .card-body-custom {
            padding: 20px;
        }

        /* List Items */
        .list-item {
            padding: 14px 0;
            border-bottom: 1px solid #e9ecef;
            transition: var(--transition);
        }

        .list-item:hover {
            transform: translateX(8px);
            background: #f8f9fa;
            padding-left: 10px;
            border-radius: 12px;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        /* Badges */
        .badge-turma {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-prova {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Horário Item */
        .horario-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 12px;
            transition: var(--transition);
            border-left: 4px solid var(--primary-green);
        }

        .horario-item:hover {
            transform: translateX(5px);
            box-shadow: var(--card-shadow);
        }

        /* Botões */
        .btn-ver-mais {
            background: #f8f9fa;
            color: var(--primary-green);
            border-radius: 30px;
            padding: 6px 18px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-ver-mais:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
        }

        .btn-action {
            border-radius: 30px;
            padding: 8px 20px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
        }

        .btn-action-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .btn-action-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        .btn-action-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .btn-action-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-action-info {
            background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%);
            color: white;
        }

        .btn-action-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-action-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #212529;
        }

        .btn-action-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-action-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }

        .btn-action-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        /* Ações Rápidas */
        .actions-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .stat-icon {
                width: 45px;
                height: 45px;
                font-size: 1.3rem;
            }
            
            .card-header-custom {
                padding: 14px 18px;
                font-size: 0.9rem;
            }
            
            .actions-grid {
                gap: 8px;
            }
            
            .btn-action {
                padding: 6px 14px;
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Impressão */
        @media print {
            .no-print, .sidebar, .menu-toggle, .btn-action, .btn-ver-mais {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .top-bar {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .stat-card, .content-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
</br></br>
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar fade-in-up">
            <div class="welcome-text">
                <h2>Bem-vindo, <?php echo htmlspecialchars($professor['professor_nome']); ?>!</h2>
                <p><i class="fas fa-calendar-alt me-2"></i> <?php echo date('d/m/Y'); ?> | <i class="fas fa-clock me-2"></i> Sistema de Gestão Escolar</p>
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
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card slide-in-left delay-1">
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
            <div class="stat-card slide-in-left delay-2">
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
            <div class="stat-card slide-in-right delay-1">
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
            <div class="stat-card slide-in-right delay-2">
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
        
        <div class="row">
            <!-- Próximas Atividades -->
            <div class="col-md-6">
                <div class="content-card fade-in-up delay-1">
                    <div class="card-header-custom">
                        <i class="fas fa-tasks"></i> Próximas Atividades
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($proximas_atividades)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-calendar-check fa-2x mb-2"></i>
                                <p>Nenhuma atividade agendada.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($proximas_atividades as $atividade): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $atividade['turma_ano'] . 'ª ' . htmlspecialchars($atividade['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($atividade['disciplina_nome']); ?>
                                        </small>
                                    </div>
                                    <span class="badge-turma">
                                        <i class="fas fa-clock me-1"></i> Hoje
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
                <div class="content-card fade-in-up delay-2">
                    <div class="card-header-custom">
                        <i class="fas fa-clock"></i> Horário de Hoje - <?php echo $dia_nome; ?>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($horario_hoje)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-hourglass fa-2x mb-2"></i>
                                <p>Nenhuma aula agendada para hoje.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($horario_hoje as $horario): ?>
                            <div class="horario-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($horario['disciplina_nome']); ?>
                                        </small>
                                    </div>
                                    <a href="registrar_chamada.php?turma_id=<?php echo $horario['turma_id']; ?>&disciplina_id=<?php echo $horario['disciplina_id']; ?>" class="btn btn-action btn-action-primary btn-sm">
                                        <i class="fas fa-clipboard-list me-1"></i> Chamada
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
                <div class="content-card fade-in-up delay-3">
                    <div class="card-header-custom">
                        <i class="fas fa-chalkboard"></i> Minhas Turmas
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($minhas_turmas)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-school fa-2x mb-2"></i>
                                <p>Nenhuma turma atribuída.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($minhas_turmas as $turma): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $turma['turma_ano'] . 'ª ' . htmlspecialchars($turma['turma_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-users me-1"></i> <?php echo $turma['total_alunos']; ?> alunos | 
                                            <i class="fas fa-clock me-1"></i> <?php echo ucfirst($turma['turno']); ?> | 
                                            Sala: <?php echo $turma['sala'] ?: 'N/D'; ?>
                                        </small>
                                    </div>
                                    <div>
                                        <a href="minhas_turmas.php?turma_id=<?php echo $turma['id']; ?>" class="btn btn-action btn-action-info btn-sm">
                                            <i class="fas fa-eye me-1"></i> Ver
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
                <div class="content-card fade-in-up delay-4">
                    <div class="card-header-custom">
                        <i class="fas fa-calendar-alt"></i> Calendário de Provas
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($calendario_provas)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                <p>Nenhuma prova agendada.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($calendario_provas as $prova): ?>
                            <div class="list-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-chalkboard me-1"></i> <?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge-prova">
                                            <i class="fas fa-calendar me-1"></i> <?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?php echo isset($prova['tipo']) ? ucfirst($prova['tipo']) : 'Prova'; ?></small>
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
        <div class="content-card fade-in-up">
            <div class="card-header-custom">
                <i class="fas fa-bolt"></i> Ações Rápidas
            </div>
            <div class="card-body-custom">
                <div class="actions-grid">
                    <a href="registrar_chamada.php" class="btn btn-action btn-action-primary">
                        <i class="fas fa-clipboard-list me-2"></i> Registrar Chamada
                    </a>
                    <a href="lancar_notas.php" class="btn btn-action btn-action-success">
                        <i class="fas fa-graduation-cap me-2"></i> Lançar Notas
                    </a>
                    <a href="minhas_turmas.php" class="btn btn-action btn-action-info">
                        <i class="fas fa-chalkboard me-2"></i> Minhas Turmas
                    </a>
                    <a href="meu_horario.php" class="btn btn-action btn-action-warning">
                        <i class="fas fa-calendar-week me-2"></i> Meu Horário
                    </a>
                    <a href="relatorios/index.php" class="btn btn-action btn-action-secondary">
                        <i class="fas fa-chart-line me-2"></i> Relatórios
                    </a>
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
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar')?.classList.toggle('open');
        });
        
        // Fechar sidebar ao clicar em link (mobile)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar')?.classList.remove('open');
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
        
        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.stat-card, .content-card, .top-bar').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>