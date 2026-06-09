<?php
// escola/professor/calendario_provas.php - Calendário de Provas do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');
$ano_letivo_inicio = $ano_letivo['data_inicio'] ?? '';
$ano_letivo_fim = $ano_letivo['data_fim'] ?? '';

// ============================================
// FILTROS
// ============================================
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$status = isset($_GET['status']) ? $_GET['status'] : 'todas';

// ============================================
// FUNÇÃO PARA EXTRAIR BIMESTRE DO PERÍODO
// ============================================
function extrairBimestre($periodo) {
    if (strpos($periodo, 'Bimestre') !== false) {
        return (int)substr($periodo, 0, 1);
    }
    return 0;
}

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano DESC, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'agendada':
            return '<span class="badge bg-primary">📅 Agendada</span>';
        case 'publicada':
            return '<span class="badge bg-info">📢 Publicada</span>';
        case 'realizada':
            return '<span class="badge bg-success">✅ Realizada</span>';
        case 'cancelada':
            return '<span class="badge bg-danger">❌ Cancelada</span>';
        default:
            return '<span class="badge bg-secondary">-</span>';
    }
}

function getTipoBadge($tipo_id, $conn) {
    if (empty($tipo_id)) {
        return '<span class="badge bg-secondary">-</span>';
    }
    // Buscar o nome do tipo de avaliação
    $sql = "SELECT nome, cor, icone FROM tipos_avaliacao WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tipo_id]);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tipo) {
        return '<span class="badge" style="background-color: ' . ($tipo['cor'] ?? '#6c757d') . '; color: white;"><i class="fas ' . ($tipo['icone'] ?? 'fa-file-alt') . '"></i> ' . htmlspecialchars($tipo['nome']) . '</span>';
    }
    return '<span class="badge bg-secondary">-</span>';
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

function getSituacaoData($data_prova) {
    $hoje = date('Y-m-d');
    if ($data_prova < $hoje) {
        return 'passado';
    } elseif ($data_prova == $hoje) {
        return 'hoje';
    } else {
        return 'futuro';
    }
}

// Nomes dos meses
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// ============================================
// BUSCAR PROVAS DA TABELA PROVAS COM JOIN EM tipos_avaliacao
// ============================================
$sql_provas = "
    SELECT 
        p.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala as turma_sala,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        f.nome as docente_nome,
        ta.nome as tipo_avaliacao_nome,
        ta.cor as tipo_cor,
        ta.icone as tipo_icone
    FROM provas p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    LEFT JOIN funcionarios f ON f.id = p.docente_responsavel
    LEFT JOIN tipos_avaliacao ta ON ta.id = p.tipo_prova
    WHERE p.escola_id = :escola_id
    AND p.ano_letivo_id = :ano_letivo_id
    AND p.turma_id IN (
        SELECT DISTINCT t2.id 
        FROM professor_disciplina_turma pdt2 
        INNER JOIN turmas t2 ON t2.id = pdt2.turma_id 
        WHERE pdt2.professor_id = :professor_id
    )
";

$params = [
    ':escola_id' => $escola_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':professor_id' => $professor_id
];

if (!empty($periodo) && $periodo != 0) {
    $sql_provas .= " AND p.periodo = :periodo";
    $params[':periodo'] = $periodo;
}

if ($turma_id > 0) {
    $sql_provas .= " AND p.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

if ($mes > 0 && $mes <= 12) {
    $sql_provas .= " AND MONTH(p.data_prova) = :mes AND YEAR(p.data_prova) = :ano";
    $params[':mes'] = $mes;
    $params[':ano'] = $ano;
}

if ($status != 'todas') {
    $sql_provas .= " AND p.status = :status";
    $params[':status'] = $status;
}

$sql_provas .= " ORDER BY p.data_prova ASC, p.hora_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_provas = count($provas);
$provas_agendadas = 0;
$provas_realizadas = 0;
$provas_canceladas = 0;
$provas_publicadas = 0;
$provas_por_bimestre = [1 => 0, 2 => 0, 3 => 0];

foreach ($provas as $prova) {
    switch ($prova['status']) {
        case 'agendada': $provas_agendadas++; break;
        case 'publicada': $provas_publicadas++; break;
        case 'realizada': $provas_realizadas++; break;
        case 'cancelada': $provas_canceladas++; break;
    }
    
    $bim = extrairBimestre($prova['periodo']);
    if ($bim >= 1 && $bim <= 3) {
        $provas_por_bimestre[$bim]++;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendário de Provas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        /* Estilos mantidos iguais ao original */
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .sidebar-header h3 { font-size: 1.3em; margin-bottom: 5px; }
        .sidebar-header p { font-size: 0.8em; opacity: 0.8; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-item { margin-bottom: 5px; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.3s; gap: 12px; font-size: 0.95em; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-link.active { background: rgba(255,255,255,0.15); color: white; border-left: 4px solid #FFD700; }
        .nav-link i { width: 20px; text-align: center; }
        .has-submenu { position: relative; }
        .has-submenu > .nav-link { cursor: pointer; }
        .has-submenu > .nav-link::after { content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900; margin-left: auto; transition: transform 0.3s; }
        .has-submenu.open > .nav-link::after { transform: rotate(180deg); }
        .nav-submenu { list-style: none; padding-left: 55px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; }
        .has-submenu.open .nav-submenu { max-height: 500px; }
        .nav-submenu .nav-link { padding: 10px 25px; font-size: 0.85em; }
        .nav-submenu .nav-link i { font-size: 0.8em; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        .top-bar { background: white; border-radius: 15px; padding: 15px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .welcome-text h2 { font-size: 1.5em; margin-bottom: 5px; }
        .welcome-text p { color: #666; margin: 0; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-avatar { width: 50px; height: 50px; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5em; }
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #008B4E 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .filter-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card { background: #f8f9fa; border-radius: 10px; padding: 15px; text-align: center; transition: transform 0.2s; margin-bottom: 15px; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 12px; color: #6c757d; }
        .table-provas th { background: #006B3E; color: white; text-align: center; }
        .table-provas td { vertical-align: middle; }
        .prova-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 15px; border-left: 4px solid #006B3E; box-shadow: 0 2px 5px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .prova-card:hover { transform: translateX(5px); }
        .prova-card.passado { border-left-color: #6c757d; opacity: 0.7; }
        .prova-card.hoje { border-left-color: #ffc107; background: #fff3cd; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .btn-excel { background: #28a745; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-excel:hover { background: #1e7e34; color: white; }
        .btn-pdf { background: #dc3545; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-pdf:hover { background: #bd2130; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } .top-bar { flex-direction: column; text-align: center; gap: 15px; } }
        @media print { .no-print { display: none !important; } .main-content { margin: 0; padding: 0; } .sidebar { display: none; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Área do Professor</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-graduation-cap"></i> Menu Acadêmico</a>
                <ul class="nav-submenu">
                    <li><a href="minhas_turmas.php" class="nav-link"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                    <li><a href="lancar_notas.php" class="nav-link"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                    <li><a href="registrar_chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                    <li><a href="atividades.php" class="nav-link"><i class="fas fa-tasks"></i> Atividades</a></li>
                    <li><a href="meus_alunos.php" class="nav-link"><i class="fas fa-users"></i> Meus Alunos</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="meu_horario.php" class="nav-link"><i class="fas fa-calendar-week"></i> Meu Horário</a></li>
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu">
                    <li><a href="relatorios/index.php" class="nav-link"><i class="fas fa-home"></i> Index</a></li>
                    <li><a href="relatorios/mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                    <li><a href="relatorios/pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                    <li><a href="relatorios/estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                    <li><a href="relatorios/estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
                </ul>
            </li>
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-calendar-alt"></i> Agenda</a>
                <ul class="nav-submenu">
                    <li><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                    <li><a href="calendario_provas.php" class="nav-link active"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
                </ul>
            </li>
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-coins"></i> Financeiro</a>
                <ul class="nav-submenu">
                    <li><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                    <li><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                    <li><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                    <li><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                    <li><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                    <li><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="conselho_nota.php" class="nav-link"><i class="fas fa-chalkboard-user"></i> Conselho de Nota</a></li>
            <li class="nav-item"><a href="chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Chamada</a></li>
            <li class="nav-item"><a href="lancamento_nota.php" class="nav-link"><i class="fas fa-edit"></i> Lançamento de Nota</a></li>
            <li class="nav-item"><a href="biblioteca.php" class="nav-link"><i class="fas fa-book"></i> Biblioteca</a></li>
            <li class="nav-item"><a href="proposta_prova.php" class="nav-link"><i class="fas fa-file-alt"></i> Proposta de Prova</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar no-print">
            <div class="welcome-text">
                <h2>Calendário de Provas</h2>
                <p><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?> | <i class="fas fa-clock"></i> Sistema de Gestão Escolar</p>
            </div>
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user-chalkboard"></i></div>
                <div><strong><?php echo htmlspecialchars($professor['professor_nome']); ?></strong><br><small class="text-muted">Professor</small></div>
            </div>
        </div>
        
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1"><i class="fas fa-calendar-alt"></i> Calendário de Provas</h2>
                    <p class="mb-0"><i class="fas fa-calendar"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?> | <i class="fas fa-chalkboard-user"></i> Professor: <?php echo htmlspecialchars($professor['professor_nome']); ?></p>
                    <?php if ($ano_letivo_inicio && $ano_letivo_fim): ?>
                    <small><i class="fas fa-clock"></i> Período: <?php echo formatarData($ano_letivo_inicio); ?> a <?php echo formatarData($ano_letivo_fim); ?></small>
                    <?php endif; ?>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar ao Dashboard</a>
                    <button onclick="gerarExcel()" class="btn-excel btn me-2"><i class="fas fa-file-excel"></i> Excel</button>
                    <button onclick="gerarPDF()" class="btn-pdf btn"><i class="fas fa-file-pdf"></i> PDF</button>
                </div>
            </div>
        </div>
        
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Período</label>
                    <select name="periodo" class="form-select">
                        <option value="0">Todos</option>
                        <option value="1º Bimestre" <?php echo $periodo == '1º Bimestre' ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2º Bimestre" <?php echo $periodo == '2º Bimestre' ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3º Bimestre" <?php echo $periodo == '3º Bimestre' ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="Exame" <?php echo $periodo == 'Exame' ? 'selected' : ''; ?>>Exame</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>><?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mês</label>
                    <select name="mes" class="form-select">
                        <option value="0">Todos</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $mes == $i ? 'selected' : ''; ?>><?php echo $meses[$i]; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ano</label>
                    <select name="ano" class="form-select">
                        <option value="<?php echo $ano_letivo_ano; ?>" selected><?php echo $ano_letivo_ano; ?></option>
                        <option value="<?php echo $ano_letivo_ano + 1; ?>"><?php echo $ano_letivo_ano + 1; ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="todas" <?php echo $status == 'todas' ? 'selected' : ''; ?>>Todas</option>
                        <option value="agendada" <?php echo $status == 'agendada' ? 'selected' : ''; ?>>Agendadas</option>
                        <option value="publicada" <?php echo $status == 'publicada' ? 'selected' : ''; ?>>Publicadas</option>
                        <option value="realizada" <?php echo $status == 'realizada' ? 'selected' : ''; ?>>Realizadas</option>
                        <option value="cancelada" <?php echo $status == 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3"><div class="stat-card"><div class="stat-number"><?php echo $total_provas; ?></div><div class="stat-label">Total de Provas</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-primary"><?php echo $provas_agendadas; ?></div><div class="stat-label">Agendadas</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-info"><?php echo $provas_publicadas; ?></div><div class="stat-label">Publicadas</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-success"><?php echo $provas_realizadas; ?></div><div class="stat-label">Realizadas</div></div></div>
        </div>
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-chart-bar"></i> Distribuição por Bimestre</h5></div>
                    <div class="card-body">
                        <div class="row text-center">
                            <?php for ($b = 1; $b <= 3; $b++): ?>
                            <div class="col-4">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $provas_por_bimestre[$b]; ?></div>
                                    <div class="stat-label"><?php echo $b; ?>º Bimestre</div>
                                    <div class="progress mt-2" style="height: 8px;"><div class="progress-bar bg-<?php echo $b == 1 ? 'danger' : ($b == 2 ? 'warning' : 'info'); ?>" style="width: <?php echo $total_provas > 0 ? ($provas_por_bimestre[$b] / $total_provas) * 100 : 0; ?>%"></div></div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-list"></i> Lista de Provas</h5></div>
            <div class="card-body">
                <?php if (empty($provas)): ?>
                    <div class="alert alert-info text-center"><i class="fas fa-info-circle"></i> Nenhuma prova encontrada para os filtros selecionados.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-provas" id="tabelaProvas">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Hora Início</th>
                                    <th>Hora Fim</th>
                                    <th>Disciplina</th>
                                    <th>Turma</th>
                                    <th>Título</th>
                                    <th>Tipo</th>
                                    <th>Período</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($provas as $index => $prova): 
                                    $situacao = getSituacaoData($prova['data_prova']);
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td class="text-center <?php echo $situacao == 'hoje' ? 'fw-bold text-warning' : ''; ?>"><?php echo formatarData($prova['data_prova']); ?></td>
                                    <td class="text-center"><?php echo formatarHorario($prova['hora_inicio']); ?></td>
                                    <td class="text-center"><?php echo formatarHorario($prova['hora_fim']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($prova['disciplina_codigo'] ?? ''); ?></small></td>
                                    <td><?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($prova['titulo']); ?></td>
                                    <td class="text-center"><?php echo getTipoBadge($prova['tipo_prova'], $conn); ?></td>
                                    <td class="text-center"><?php echo $prova['periodo']; ?></td>
                                    <td class="text-center"><?php echo getStatusBadge($prova['status']); ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $prova['id']; ?>)"><i class="fas fa-eye"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-calendar-week"></i> Visualização por Data</h5></div>
            <div class="card-body">
                <?php if (empty($provas)): ?>
                    <p class="text-muted text-center">Nenhuma prova agendada.</p>
                <?php else: ?>
                    <?php
                    $provas_agrupadas = [];
                    foreach ($provas as $prova) { $provas_agrupadas[$prova['data_prova']][] = $prova; }
                    ksort($provas_agrupadas);
                    ?>
                    <?php foreach ($provas_agrupadas as $data => $provas_data): 
                        $situacao = getSituacaoData($data);
                    ?>
                    <div class="prova-card <?php echo $situacao; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="fas fa-calendar-day"></i> <strong><?php echo formatarData($data); ?></strong>
                                <?php if ($situacao == 'hoje'): ?><span class="badge bg-warning ms-2">HOJE</span>
                                <?php elseif ($situacao == 'passado'): ?><span class="badge bg-secondary ms-2">JÁ REALIZADA</span>
                                <?php else: ?><span class="badge bg-primary ms-2">PRÓXIMA</span><?php endif; ?>
                            </h6>
                            <span class="badge bg-info"><?php echo count($provas_data); ?> prova(s)</span>
                        </div>
                        <?php foreach ($provas_data as $prova): ?>
                        <div class="row mb-2 pb-2 border-bottom">
                            <div class="col-md-3"><strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong></div>
                            <div class="col-md-3"><?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?></div>
                            <div class="col-md-2"><?php echo formatarHorario($prova['hora_inicio']) . ' - ' . formatarHorario($prova['hora_fim']); ?></div>
                            <div class="col-md-2"><?php echo getTipoBadge($prova['tipo_prova'], $conn); ?></div>
                            <div class="col-md-1"><?php echo getStatusBadge($prova['status']); ?></div>
                            <div class="col-md-1 text-end"><button class="btn btn-sm btn-outline-info" onclick="verDetalhes(<?php echo $prova['id']; ?>)"><i class="fas fa-eye"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Prova</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalDetalhesBody">Carregando...</div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) parent.classList.toggle('open');
        }
        
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');
            });
        });
        
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
            if (currentUrl.includes(link.getAttribute('href'))) {
                const parent = link.closest('.has-submenu');
                if (parent) parent.classList.add('open');
            }
        });
        
        $(document).ready(function() {
            if ($('#tabelaProvas tbody tr').length > 0) {
                $('#tabelaProvas').DataTable({
                    language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
                    order: [[1, 'asc']],
                    pageLength: 25,
                    responsive: true
                });
            }
        });
        
        function verDetalhes(id) {
            $('#modalDetalhesBody').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>');
            $('#modalDetalhes').modal('show');
            $.ajax({
                url: 'ajax_prova_detalhes.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        var html = `<table class="table table-bordered">
                            <tr><th width="35%">Título:</th><td>${data.titulo || '-'}</td></tr>
                            <tr><th>Disciplina:</th><td>${data.disciplina_nome || '-'}</td></tr>
                            <tr><th>Turma:</th><td>${data.turma_ano || ''}ª ${data.turma_nome || '-'}</td></tr>
                            <tr><th>Data:</th><td>${data.data_prova || '-'}</td></tr>
                            <tr><th>Horário:</th><td>${data.hora_inicio || '-'} - ${data.hora_fim || '-'}</td></tr>
                            <tr><th>Tipo:</th><td>${data.tipo_avaliacao_nome || data.tipo_prova || '-'}</td></tr>
                            <tr><th>Período:</th><td>${data.periodo || '-'}</td></tr>
                            <tr><th>Valor:</th><td>${data.valor_total || '-'} valores</td></tr>
                            <tr><th>Sala:</th><td>${data.sala || '-'}</td></tr>
                            <tr><th>Status:</th><td>${data.status || '-'}</td></tr>
                            <tr><th>Descrição:</th><td>${data.descricao || '-'}</td></tr>
                            <tr><th>Instruções:</th><td>${data.instrucoes || '-'}</td></tr>
                            <tr><th>Material Permitido:</th><td>${data.material_permitido || '-'}</td></tr>
                            <tr><th>Docente Responsável:</th><td>${data.docente_nome || '-'}</td></tr>
                        </table>`;
                        $('#modalDetalhesBody').html(html);
                    } else {
                        $('#modalDetalhesBody').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                    }
                },
                error: function() {
                    $('#modalDetalhesBody').html('<div class="alert alert-danger">Erro de conexão.</div>');
                }
            });
        }
        
        function gerarExcel() {
            var params = new URLSearchParams(window.location.search);
            window.location.href = 'gerar_excel_calendario_provas.php?' + params.toString();
        }
        
        function gerarPDF() {
            var params = new URLSearchParams(window.location.search);
            window.open('gerar_pdf_calendario_provas.php?' + params.toString(), '_blank');
        }
    </script>
</body>
</html>