<?php
// escola/professor/meu_horario.php - Meu Horário de Aulas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "SELECT nome, email, telefone, foto FROM funcionarios WHERE id = :id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':id' => $professor_id]);
$professor_dados = $stmt_professor->fetch(PDO::FETCH_ASSOC);

// ============================================
// VERIFICAR SE AS COLUNAS EXISTEM
// ============================================
$tem_dia_semana = false;
$tem_horario = false;

try {
    $sql_check = "SHOW COLUMNS FROM professor_disciplina_turma LIKE 'dia_semana'";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute();
    $tem_dia_semana = $stmt_check->rowCount() > 0;
    
    $sql_check = "SHOW COLUMNS FROM professor_disciplina_turma LIKE 'horario_inicio'";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute();
    $tem_horario = $stmt_check->rowCount() > 0;
} catch (PDOException $e) {
    $tem_dia_semana = false;
    $tem_horario = false;
}

// ============================================
// BUSCAR HORÁRIO DO PROFESSOR
// ============================================
if ($tem_dia_semana && $tem_horario) {
    $sql_horario = "
        SELECT 
            pdt.id,
            pdt.dia_semana,
            pdt.horario_inicio,
            pdt.horario_fim,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano,
            t.turno,
            t.sala,
            d.id as disciplina_id,
            d.nome as disciplina_nome,
            d.codigo as disciplina_codigo
        FROM professor_disciplina_turma pdt
        INNER JOIN turmas t ON t.id = pdt.turma_id
        INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
        WHERE pdt.professor_id = :professor_id
        ORDER BY 
            FIELD(pdt.dia_semana, 'SEGUNDA', 'TERCA', 'QUARTA', 'QUINTA', 'SEXTA', 'SABADO'),
            pdt.horario_inicio
    ";
} else {
    $sql_horario = "
        SELECT 
            pdt.id,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano,
            t.turno,
            t.sala,
            d.id as disciplina_id,
            d.nome as disciplina_nome,
            d.codigo as disciplina_codigo
        FROM professor_disciplina_turma pdt
        INNER JOIN turmas t ON t.id = pdt.turma_id
        INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
        WHERE pdt.professor_id = :professor_id
        ORDER BY t.ano, t.nome
    ";
}

$stmt_horario = $conn->prepare($sql_horario);
$stmt_horario->execute([':professor_id' => $professor_id]);
$horarios = $stmt_horario->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ORGANIZAR HORÁRIO POR DIA
// ============================================
$dias_semana = [
    'SEGUNDA' => 'Segunda-feira',
    'TERCA' => 'Terça-feira',
    'QUARTA' => 'Quarta-feira',
    'QUINTA' => 'Quinta-feira',
    'SEXTA' => 'Sexta-feira',
    'SABADO' => 'Sábado'
];

$horario_por_dia = [];
foreach ($dias_semana as $key => $nome) {
    $horario_por_dia[$key] = [];
}

if ($tem_dia_semana && $tem_horario) {
    foreach ($horarios as $horario) {
        $dia = $horario['dia_semana'];
        if (isset($horario_por_dia[$dia])) {
            $horario_por_dia[$dia][] = $horario;
        }
    }
} else {
    $horario_por_dia['INDEFINIDO'] = $horarios;
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_aulas = count($horarios);
$dias_com_aula = 0;
foreach ($horario_por_dia as $dia => $aulas) {
    if (!empty($aulas)) {
        $dias_com_aula++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

function getStatusDia($dia) {
    $hoje = date('N');
    $dias_map = [
        'SEGUNDA' => 1,
        'TERCA' => 2,
        'QUARTA' => 3,
        'QUINTA' => 4,
        'SEXTA' => 5,
        'SABADO' => 6
    ];
    if (isset($dias_map[$dia]) && $dias_map[$dia] == $hoje) {
        return 'hoje';
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Horário | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
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
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
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
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #008B4E 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
        }
        
        .horario-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        
        .horario-card:hover {
            transform: translateY(-3px);
        }
        
        .horario-card.hoje {
            border-left: 4px solid #ffc107;
            background: #fff3cd;
        }
        
        .dia-titulo {
            font-size: 1.2em;
            font-weight: bold;
            color: #006B3E;
            padding-bottom: 10px;
            margin-bottom: 15px;
            border-bottom: 2px solid #006B3E;
        }
        
        .aula-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .aula-item:last-child {
            border-bottom: none;
        }
        
        .aula-horario {
            font-weight: bold;
            color: #006B3E;
            font-size: 1.1em;
        }
        
        .aula-disciplina {
            font-weight: bold;
            font-size: 1em;
        }
        
        .aula-turma {
            color: #666;
            font-size: 0.9em;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        .badge-sala {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
        }
        
        .alert-info-custom {
            background: #e9ecef;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        /* Modal Preview */
        .modal-preview .modal-dialog {
            max-width: 90%;
            margin: 30px auto;
        }
        
        .modal-preview .modal-body {
            padding: 0;
            height: 80vh;
        }
        
        .preview-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .btn-preview {
            background: #17a2b8;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        
        .btn-preview:hover {
            background: #138496;
            color: white;
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
            }
            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            .modal-preview .modal-dialog {
                max-width: 95%;
                margin: 10px auto;
            }
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
            }
        }
        
        .carregando-preview {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar com Menu Completo -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE Angola</h3>
            <p>Área do Professor</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
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
            
            <li class="nav-item">
                <a href="meu_horario.php" class="nav-link active">
                    <i class="fas fa-calendar-week"></i> Meu Horário
                </a>
            </li>
            
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
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                    <li class="nav-item"><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
                </ul>
            </li>
            
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
            
            <li class="nav-item">
                <a href="conselho_nota.php" class="nav-link">
                    <i class="fas fa-chalkboard-user"></i> Conselho de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="chamada.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i> Chamada
                </a>
            </li>
            
            <li class="nav-item">
                <a href="lancamento_nota.php" class="nav-link">
                    <i class="fas fa-edit"></i> Lançamento de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="biblioteca.php" class="nav-link">
                    <i class="fas fa-book"></i> Biblioteca
                </a>
            </li>
            
            <li class="nav-item">
                <a href="proposta_prova.php" class="nav-link">
                    <i class="fas fa-file-alt"></i> Proposta de Prova
                </a>
            </li>
            
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
        <div class="top-bar no-print">
            <div class="welcome-text">
                <h2>Meu Horário de Aulas</h2>
                <p><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?> | <i class="fas fa-clock"></i> Sistema de Gestão Escolar</p>
            </div>
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-chalkboard"></i>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($professor_dados['nome'] ?? $professor['professor_nome']); ?></strong><br>
                    <small class="text-muted">Professor</small>
                </div>
            </div>
        </div>
        
        <!-- Cabeçalho da Página -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-calendar-week"></i> Meu Horário de Aulas
                    </h2>
                    <p class="mb-0">
                        <i class="fas fa-chalkboard-user"></i> Professor: <?php echo htmlspecialchars($professor_dados['nome'] ?? $professor['professor_nome']); ?>
                    </p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                    <button onclick="abrirPreviewPDF()" class="btn-preview btn me-2">
                        <i class="fas fa-eye"></i> Visualizar PDF
                    </button>
                    <button onclick="window.print()" class="btn-voltar btn" style="background: #28a745;">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Aviso se as colunas não existirem -->
        <?php if (!$tem_dia_semana || !$tem_horario): ?>
        <div class="alert alert-info-custom no-print">
            <i class="fas fa-info-circle"></i> 
            <strong>Informação:</strong> Para configurar o horário completo (dias e horários), 
            é necessário adicionar as colunas na tabela. 
            <button class="btn btn-sm btn-primary ms-2" onclick="mostrarInstrucoes()">
                <i class="fas fa-code"></i> Ver instruções
            </button>
        </div>
        
        <div id="instrucoesSQL" style="display: none;" class="alert alert-secondary mt-2">
            <h6><i class="fas fa-database"></i> SQL para adicionar os campos:</h6>
            <pre class="bg-dark text-white p-2 rounded" style="font-size: 11px;">
ALTER TABLE `professor_disciplina_turma` 
ADD COLUMN `dia_semana` ENUM('SEGUNDA', 'TERCA', 'QUARTA', 'QUINTA', 'SEXTA', 'SABADO') NULL,
ADD COLUMN `horario_inicio` TIME NULL,
ADD COLUMN `horario_fim` TIME NULL;
            </pre>
            <button class="btn btn-sm btn-secondary mt-2" onclick="fecharInstrucoes()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_aulas; ?></div>
                    <div class="stat-label">Total de Aulas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $dias_com_aula; ?></div>
                    <div class="stat-label">Dias com Aula</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo date('d/m/Y'); ?></div>
                    <div class="stat-label">Data Atual</div>
                </div>
            </div>
        </div>
        
        <!-- Horário de Aulas -->
        <div class="row">
            <?php 
            $dias_exibir = $tem_dia_semana ? $dias_semana : ['INDEFINIDO' => 'Minhas Aulas'];
            foreach ($dias_exibir as $key => $nome_dia): 
                $aulas_dia = $horario_por_dia[$key] ?? [];
                $status_dia = ($key != 'INDEFINIDO') ? getStatusDia($key) : '';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="horario-card <?php echo $status_dia; ?>">
                    <div class="dia-titulo">
                        <i class="fas fa-calendar-day"></i> <?php echo $nome_dia; ?>
                        <?php if ($status_dia == 'hoje'): ?>
                            <span class="badge bg-warning text-dark ms-2">HOJE</span>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($aulas_dia)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-clock"></i> Nenhuma aula agendada
                        </div>
                    <?php else: ?>
                        <?php foreach ($aulas_dia as $aula): ?>
                        <div class="aula-item">
                            <div class="row align-items-center">
                                <div class="col-4">
                                    <div class="aula-horario">
                                        <?php 
                                        if ($tem_horario && isset($aula['horario_inicio'])) {
                                            echo formatarHorario($aula['horario_inicio']) . ' - ' . formatarHorario($aula['horario_fim']);
                                        } else {
                                            echo '<i class="fas fa-clock"></i> Horário não definido';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="col-8">
                                    <div class="aula-disciplina">
                                        <?php echo htmlspecialchars($aula['disciplina_nome']); ?>
                                        <span class="badge-sala ms-1">
                                            <i class="fas fa-door-open"></i> <?php echo $aula['sala'] ?: 'Sala N/D'; ?>
                                        </span>
                                    </div>
                                    <div class="aula-turma">
                                        <i class="fas fa-chalkboard"></i> <?php echo $aula['turma_ano'] . 'ª ' . htmlspecialchars($aula['turma_nome']); ?>
                                        <br>
                                        <i class="fas fa-clock"></i> <?php echo ucfirst($aula['turno']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Resumo Semanal -->
        <div class="card mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Resumo Semanal</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Turma</th>
                                <th>Disciplina</th>
                                <th>Turno</th>
                                <th>Sala</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $resumo_turmas = [];
                            foreach ($horarios as $horario) {
                                $key = $horario['turma_id'];
                                if (!isset($resumo_turmas[$key])) {
                                    $resumo_turmas[$key] = [
                                        'turma' => $horario['turma_ano'] . 'ª ' . $horario['turma_nome'],
                                        'disciplinas' => [],
                                        'turno' => $horario['turno'],
                                        'sala' => $horario['sala']
                                    ];
                                }
                                if (!in_array($horario['disciplina_nome'], $resumo_turmas[$key]['disciplinas'])) {
                                    $resumo_turmas[$key]['disciplinas'][] = $horario['disciplina_nome'];
                                }
                            }
                            ?>
                            <?php foreach ($resumo_turmas as $resumo): ?>
                            <tr>
                                <td><strong><?php echo $resumo['turma']; ?></strong></td>
                                <td><?php echo implode(', ', array_map('htmlspecialchars', $resumo['disciplinas'])); ?></td>
                                <td><?php echo ucfirst($resumo['turno']); ?></td>
                                <td><?php echo $resumo['sala'] ?: 'Não definida'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($resumo_turmas)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">Nenhuma turma atribuída</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Legenda -->
        <div class="card mt-4 no-print">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Legenda</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <span class="badge bg-warning text-dark me-2">HOJE</span>
                        Dia atual (destaque amarelo)
                    </div>
                    <div class="col-md-4">
                        <span class="badge bg-info me-2">Sala</span>
                        Número da sala de aula
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-clock text-success me-2"></i>
                        Horário de início e término
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Preview PDF -->
    <div class="modal fade modal-preview" id="modalPreviewPDF" tabindex="-1" aria-labelledby="modalPreviewPDFLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title" id="modalPreviewPDFLabel">
                        <i class="fas fa-eye"></i> Visualizar PDF - Meu Horário
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="previewBody">
                    <div class="carregando-preview">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                            <p>Gerando PDF, aguarde...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="imprimirPDF()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button type="button" class="btn btn-success" onclick="baixarPDF()">
                        <i class="fas fa-download"></i> Baixar PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let pdfUrl = '';
        
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
        
        // Mostrar instruções SQL
        function mostrarInstrucoes() {
            document.getElementById('instrucoesSQL').style.display = 'block';
        }
        
        function fecharInstrucoes() {
            document.getElementById('instrucoesSQL').style.display = 'none';
        }
        
        // Abrir preview do PDF
        function abrirPreviewPDF() {
            const modal = new bootstrap.Modal(document.getElementById('modalPreviewPDF'));
            const previewBody = document.getElementById('previewBody');
            
            previewBody.innerHTML = `
                <div class="carregando-preview">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                        <p>Gerando PDF, aguarde...</p>
                    </div>
                </div>
            `;
            
            modal.show();
            
            // Gerar PDF
            const dados = new FormData();
            dados.append('gerar_pdf', '1');
            
            fetch('gerar_pdf_horario.php', {
                method: 'POST',
                body: dados
            })
            .then(response => response.blob())
            .then(blob => {
                pdfUrl = URL.createObjectURL(blob);
                const iframe = `<iframe src="${pdfUrl}" class="preview-iframe" frameborder="0"></iframe>`;
                previewBody.innerHTML = iframe;
            })
            .catch(error => {
                console.error('Erro:', error);
                previewBody.innerHTML = `
                    <div class="alert alert-danger text-center m-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <p>Erro ao gerar o PDF. Tente novamente.</p>
                        <button class="btn btn-danger mt-2" onclick="abrirPreviewPDF()">
                            <i class="fas fa-redo"></i> Tentar novamente
                        </button>
                    </div>
                `;
            });
        }
        
        // Imprimir PDF
        function imprimirPDF() {
            if (pdfUrl) {
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = pdfUrl;
                document.body.appendChild(iframe);
                iframe.contentWindow.print();
            } else {
                alert('PDF ainda não carregado. Aguarde um momento.');
            }
        }
        
        // Baixar PDF
        function baixarPDF() {
            if (pdfUrl) {
                const link = document.createElement('a');
                link.href = pdfUrl;
                link.download = 'meu_horario.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert('PDF ainda não carregado. Aguarde um momento.');
            }
        }
        
        // Função para gerar PDF diretamente (fallback)
        function gerarPDFDireto() {
            window.open('gerar_pdf_horario.php', '_blank');
        }
    </script>
</body>
</html>