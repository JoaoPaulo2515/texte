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
    $badges = [
        'agendada' => '<span class="badge-custom badge-agendada"><i class="fas fa-calendar-alt"></i> Agendada</span>',
        'publicada' => '<span class="badge-custom badge-publicada"><i class="fas fa-bullhorn"></i> Publicada</span>',
        'realizada' => '<span class="badge-custom badge-realizada"><i class="fas fa-check-circle"></i> Realizada</span>',
        'cancelada' => '<span class="badge-custom badge-cancelada"><i class="fas fa-times-circle"></i> Cancelada</span>'
    ];
    return $badges[$status] ?? '<span class="badge-custom badge-secondary">-</span>';
}

function getTipoBadge($tipo_id, $conn) {
    if (empty($tipo_id)) {
        return '<span class="badge-custom badge-secondary">-</span>';
    }
    $sql = "SELECT nome, cor, icone FROM tipos_avaliacao WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tipo_id]);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tipo) {
        return '<span class="badge-custom" style="background: ' . ($tipo['cor'] ?? '#6c757d') . ';"><i class="fas ' . ($tipo['icone'] ?? 'fa-file-alt') . '"></i> ' . htmlspecialchars($tipo['nome']) . '</span>';
    }
    return '<span class="badge-custom badge-secondary">-</span>';
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

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// ============================================
// BUSCAR PROVAS
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Calendário de Provas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }

        /* ============================================
           TOP BAR
        ============================================ */
        .top-bar {
            background: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .welcome-text h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #333;
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
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .page-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.85;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-excel {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-excel:hover {
            background: #1e7e34;
            transform: translateY(-2px);
        }

        .btn-pdf {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-pdf:hover {
            background: #bd2130;
            transform: translateY(-2px);
        }

        /* ============================================
           FILTER CARD
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        /* ============================================
           STAT CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .stat-icon i {
            font-size: 24px;
            color: #006B3E;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           DISTRIBUTION CARD
        ============================================ */
        .dist-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .dist-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .dist-body {
            padding: 25px;
        }

        .progress-custom {
            height: 8px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        /* ============================================
           TABLE
        ============================================ */
        .table-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .table-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .table-container {
            padding: 0;
            overflow-x: auto;
        }

        .provas-table {
            width: 100%;
            border-collapse: collapse;
        }

        .provas-table thead th {
            background: #f8f9fa;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            border-bottom: 2px solid #e9ecef;
        }

        .provas-table tbody td {
            padding: 12px 15px;
            font-size: 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }

        .provas-table tbody tr:hover {
            background: #f8f9fa;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge-custom {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-agendada { background: #6c757d; color: white; }
        .badge-publicada { background: #17a2b8; color: white; }
        .badge-realizada { background: #28a745; color: white; }
        .badge-cancelada { background: #dc3545; color: white; }
        .badge-secondary { background: #6c757d; color: white; }

        /* ============================================
           PROVA CARDS (VISUALIZAÇÃO POR DATA)
        ============================================ */
        .visual-card {
            background: white;
            border-radius: 20px;
            margin-top: 25px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .visual-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .visual-body {
            padding: 25px;
        }

        .prova-card {
            background: white;
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 15px;
            border-left: 4px solid #006B3E;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .prova-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .prova-card.passado {
            border-left-color: #6c757d;
            opacity: 0.7;
        }

        .prova-card.hoje {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);
        }

        .btn-sm-outline-info {
            background: transparent;
            border: 1px solid #17a2b8;
            color: #17a2b8;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-sm-outline-info:hover {
            background: #17a2b8;
            color: white;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
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

        .animate-up {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin: 0;
                padding: 0;
            }
            .sidebar {
                display: none;
            }
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .top-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
</br></br></br>
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar no-print animate-up">
            <div class="welcome-text">
                <h2><i class="fas fa-calendar-alt me-2"></i> Calendário de Provas</h2>
                <p><i class="fas fa-calendar-day me-1"></i> <?php echo date('d/m/Y'); ?> | <i class="fas fa-clock me-1"></i> Sistema de Gestão Escolar SIGE Angola</p>
            </div>
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user-chalkboard"></i></div>
                <div>
                    <strong><?php echo htmlspecialchars($professor['professor_nome']); ?></strong>
                    <br><small class="text-muted"><i class="fas fa-graduation-cap"></i> Professor</small>
                </div>
            </div>
        </div>
        
        <!-- Page Header -->
        <div class="page-header animate-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-calendar-alt me-2"></i> Calendário de Provas</h2>
                    <p><i class="fas fa-calendar me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?> | <i class="fas fa-chalkboard-user me-1"></i> Professor: <?php echo htmlspecialchars($professor['professor_nome']); ?></p>
                    <?php if ($ano_letivo_inicio && $ano_letivo_fim): ?>
                    <small><i class="fas fa-clock me-1"></i> Período: <?php echo formatarData($ano_letivo_inicio); ?> a <?php echo formatarData($ano_letivo_fim); ?></small>
                    <?php endif; ?>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button onclick="gerarExcel()" class="btn-excel ms-2"><i class="fas fa-file-excel"></i> Excel</button>
                    <button onclick="gerarPDF()" class="btn-pdf ms-2"><i class="fas fa-file-pdf"></i> PDF</button>
                </div>
            </div>
        </div>
        
        <!-- Filter Card -->
        <div class="filter-card no-print animate-up">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-chart-line"></i> Período</label>
                    <select name="periodo" class="form-select">
                        <option value="0">Todos</option>
                        <option value="1º Bimestre" <?php echo $periodo == '1º Bimestre' ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2º Bimestre" <?php echo $periodo == '2º Bimestre' ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3º Bimestre" <?php echo $periodo == '3º Bimestre' ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="Exame" <?php echo $periodo == 'Exame' ? 'selected' : ''; ?>>Exame</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-users"></i> Turma</label>
                    <select name="turma_id" class="form-select">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-calendar-month"></i> Mês</label>
                    <select name="mes" class="form-select">
                        <option value="0">Todos</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $mes == $i ? 'selected' : ''; ?>><?php echo $meses[$i]; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-calendar-year"></i> Ano</label>
                    <select name="ano" class="form-select">
                        <option value="<?php echo $ano_letivo_ano; ?>" selected><?php echo $ano_letivo_ano; ?></option>
                        <option value="<?php echo $ano_letivo_ano + 1; ?>"><?php echo $ano_letivo_ano + 1; ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="fas fa-tag"></i> Status</label>
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
        
        <!-- Stats Cards -->
        <div class="stats-grid animate-up">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-number text-primary"><?php echo $total_provas; ?></div>
                <div class="stat-label">Total de Provas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-number text-secondary"><?php echo $provas_agendadas; ?></div>
                <div class="stat-label">Agendadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
                <div class="stat-number text-info"><?php echo $provas_publicadas; ?></div>
                <div class="stat-label">Publicadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number text-success"><?php echo $provas_realizadas; ?></div>
                <div class="stat-label">Realizadas</div>
            </div>
        </div>
        
        <!-- Distribution by Bimestre -->
        <div class="dist-card animate-up">
            <div class="dist-header">
                <i class="fas fa-chart-bar me-2"></i> Distribuição por Bimestre
            </div>
            <div class="dist-body">
                <div class="row text-center">
                    <?php for ($b = 1; $b <= 3; $b++): ?>
                    <div class="col-md-4">
                        <div class="stat-card mb-0">
                            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                            <div class="stat-number text-<?php echo $b == 1 ? 'danger' : ($b == 2 ? 'warning' : 'info'); ?>"><?php echo $provas_por_bimestre[$b]; ?></div>
                            <div class="stat-label"><?php echo $b; ?>º Bimestre</div>
                            <div class="progress-custom mt-2">
                                <div class="progress-bar bg-<?php echo $b == 1 ? 'danger' : ($b == 2 ? 'warning' : 'info'); ?>" style="width: <?php echo $total_provas > 0 ? ($provas_por_bimestre[$b] / $total_provas) * 100 : 0; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        
        <!-- Table List -->
        <div class="table-card animate-up">
            <div class="table-header">
                <i class="fas fa-list me-2"></i> Lista de Provas
            </div>
            <div class="table-container">
                <?php if (empty($provas)): ?>
                    <div class="text-center p-5">
                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                        <h5>Nenhuma prova encontrada</h5>
                        <p class="text-muted">Não há provas para os filtros selecionados.</p>
                    </div>
                <?php else: ?>
                    <table class="provas-table" id="tabelaProvas">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><i class="fas fa-calendar-day"></i> Data</th>
                                <th><i class="fas fa-clock"></i> Hora</th>
                                <th><i class="fas fa-book"></i> Disciplina</th>
                                <th><i class="fas fa-users"></i> Turma</th>
                                <th><i class="fas fa-heading"></i> Título</th>
                                <th><i class="fas fa-tag"></i> Tipo</th>
                                <th><i class="fas fa-chart-line"></i> Período</th>
                                <th><i class="fas fa-flag-checkered"></i> Status</th>
                                <th><i class="fas fa-cogs"></i> Ações</th>
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
                                <td><strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong></td>
                                <td><?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?></td>
                                <td><?php echo htmlspecialchars($prova['titulo']); ?></td>
                                <td class="text-center"><?php echo getTipoBadge($prova['tipo_prova'], $conn); ?></td>
                                <td class="text-center"><?php echo $prova['periodo']; ?></td>
                                <td class="text-center"><?php echo getStatusBadge($prova['status']); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-info" onclick="verDetalhes(<?php echo $prova['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Visualização por Data -->
        <div class="visual-card animate-up">
            <div class="visual-header">
                <i class="fas fa-calendar-week me-2"></i> Visualização por Data
            </div>
            <div class="visual-body">
                <?php if (empty($provas)): ?>
                    <p class="text-muted text-center mb-0">Nenhuma prova agendada.</p>
                <?php else: ?>
                    <?php
                    $provas_agrupadas = [];
                    foreach ($provas as $prova) { 
                        $provas_agrupadas[$prova['data_prova']][] = $prova; 
                    }
                    ksort($provas_agrupadas);
                    ?>
                    <?php foreach ($provas_agrupadas as $data => $provas_data): 
                        $situacao = getSituacaoData($data);
                    ?>
                    <div class="prova-card <?php echo $situacao; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                <i class="fas fa-calendar-day me-2"></i> 
                                <strong><?php echo formatarData($data); ?></strong>
                                <?php if ($situacao == 'hoje'): ?>
                                    <span class="badge bg-warning ms-2"><i class="fas fa-bell"></i> HOJE</span>
                                <?php elseif ($situacao == 'passado'): ?>
                                    <span class="badge bg-secondary ms-2"><i class="fas fa-check-double"></i> REALIZADA</span>
                                <?php else: ?>
                                    <span class="badge bg-primary ms-2"><i class="fas fa-clock"></i> PRÓXIMA</span>
                                <?php endif; ?>
                            </h6>
                            <span class="badge bg-info"><i class="fas fa-file-alt"></i> <?php echo count($provas_data); ?> prova(s)</span>
                        </div>
                        <?php foreach ($provas_data as $prova): ?>
                        <div class="row mb-2 pb-2 border-bottom">
                            <div class="col-md-3"><strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong></div>
                            <div class="col-md-3"><?php echo $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']); ?></div>
                            <div class="col-md-2"><i class="fas fa-clock me-1"></i> <?php echo formatarHorario($prova['hora_inicio']) . ' - ' . formatarHorario($prova['hora_fim']); ?></div>
                            <div class="col-md-2"><?php echo getTipoBadge($prova['tipo_prova'], $conn); ?></div>
                            <div class="col-md-1"><?php echo getStatusBadge($prova['status']); ?></div>
                            <div class="col-md-1 text-end">
                                <button class="btn-sm-outline-info" onclick="verDetalhes(<?php echo $prova['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalhes -->
    <div class="modal fade" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i> Detalhes da Prova</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalDetalhesBody">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                        <p class="mt-2">Carregando detalhes...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
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
            $('#modalDetalhesBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Carregando detalhes...</p></div>');
            $('#modalDetalhes').modal('show');
            
            $.ajax({
                url: 'ajax_prova_detalhes.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        var html = `
                            <table class="table table-bordered">
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
                        $('#modalDetalhesBody').html('<div class="alert alert-danger">Erro ao carregar detalhes da prova.</div>');
                    }
                },
                error: function() {
                    $('#modalDetalhesBody').html('<div class="alert alert-danger">Erro de conexão. Tente novamente.</div>');
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