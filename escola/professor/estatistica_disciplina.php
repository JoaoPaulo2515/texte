<?php
// escola/professor/relatorios/estatistica_disciplina.php - Estatística por Disciplina

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA DISCIPLINA SELECIONADA
// ============================================
$disciplina_info = null; 
$turmas_disciplina = [];
$estatisticas = [];

if ($disciplina_id > 0) {
    // Buscar informações da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar turmas onde o professor leciona esta disciplina
    $sql_turmas = "
        SELECT DISTINCT 
            t.id, t.nome, t.ano, t.turno, t.sala,
            (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
        FROM professor_disciplina_turma pdt
        INNER JOIN turmas t ON t.id = pdt.turma_id
        WHERE pdt.professor_id = :professor_id AND pdt.disciplina_id = :disciplina_id
        ORDER BY t.ano, t.nome
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':professor_id' => $professor_id, ':disciplina_id' => $disciplina_id]);
    $turmas_disciplina = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar estatísticas por turma
    foreach ($turmas_disciplina as $turma) {
        $sql_stats = "
            SELECT 
                COUNT(DISTINCT n.estudante_id) as total_alunos_com_nota,
                AVG(n.media_final) as media_geral,
                MAX(n.media_final) as maior_nota,
                MIN(CASE WHEN n.media_final > 0 THEN n.media_final END) as menor_nota,
                SUM(CASE WHEN n.status = 'aprovado' THEN 1 ELSE 0 END) as total_aprovados,
                SUM(CASE WHEN n.status = 'recuperacao' THEN 1 ELSE 0 END) as total_recuperacao,
                SUM(CASE WHEN n.status = 'reprovado' THEN 1 ELSE 0 END) as total_reprovados
            FROM notas n
            WHERE n.disciplina_id = :disciplina_id 
            AND n.bimestre = :bimestre 
            AND n.ano_letivo_id = :ano_letivo_id
            AND n.estudante_id IN (SELECT estudante_id FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa')
        ";
        $stmt_stats = $conn->prepare($sql_stats);
        $stmt_stats->execute([
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id,
            ':turma_id' => $turma['id']
        ]);
        $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
        
        $estatisticas[] = [
            'turma_nome' => $turma['nome'],
            'turma_ano' => $turma['ano'],
            'turma_turno' => $turma['turno'],
            'turma_sala' => $turma['sala'],
            'total_alunos' => $turma['total_alunos'],
            'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
            'media_geral' => round($stats['media_geral'] ?? 0, 1),
            'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
            'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
            'aprovados' => $stats['total_aprovados'] ?? 0,
            'recuperacao' => $stats['total_recuperacao'] ?? 0,
            'reprovados' => $stats['total_reprovados'] ?? 0,
            'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
            'percentual_com_nota' => $turma['total_alunos'] > 0 ? round(($stats['total_alunos_com_nota'] / $turma['total_alunos']) * 100, 1) : 0
        ];
    }
    
    // Calcular médias gerais da disciplina
    $disciplina_media_geral = 0;
    $disciplina_total_aprovados = 0;
    $disciplina_total_alunos = 0;
    $count_turmas = 0;
    $melhor_turma = '';
    $melhor_media = 0;
    $pior_turma = '';
    $pior_media = 100;
    $total_aprovados_geral = 0;
    $total_recuperacao_geral = 0;
    $total_reprovados_geral = 0;
    
    foreach ($estatisticas as $est) {
        if ($est['media_geral'] > 0) {
            $disciplina_media_geral += $est['media_geral'];
            $count_turmas++;
            
            if ($est['media_geral'] > $melhor_media) {
                $melhor_media = $est['media_geral'];
                $melhor_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
            }
            if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
                $pior_media = $est['media_geral'];
                $pior_turma = $est['turma_ano'] . 'ª ' . $est['turma_nome'];
            }
        }
        $disciplina_total_aprovados += $est['aprovados'];
        $disciplina_total_alunos += $est['total_alunos'];
        $total_aprovados_geral += $est['aprovados'];
        $total_recuperacao_geral += $est['recuperacao'];
        $total_reprovados_geral += $est['reprovados'];
    }
    $disciplina_media_geral = $count_turmas > 0 ? round($disciplina_media_geral / $count_turmas, 1) : 0;
    $disciplina_taxa_aprovacao = $disciplina_total_alunos > 0 ? round(($disciplina_total_aprovados / $disciplina_total_alunos) * 100, 1) : 0;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Estatística por Disciplina | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
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
            text-decoration: none;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-print {
            background: #17a2b8;
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

        .btn-print:hover {
            background: #138496;
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

        /* ============================================
           FILTER CARD
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .filter-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-select:focus {
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
           INFO CARD
        ============================================ */
        .info-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .info-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .info-body {
            padding: 25px;
        }

        /* ============================================
           STAT CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .stat-icon i {
            font-size: 28px;
            color: #006B3E;
        }

        .stat-number {
            font-size: 2rem;
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
           TABELA DE ESTATÍSTICAS
        ============================================ */
        .table-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .table-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .table-header h5 {
            margin: 0;
            font-size: 1rem;
        }

        .table-container {
            padding: 0;
            overflow-x: auto;
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .stats-table thead th {
            background: #f8f9fa;
            padding: 12px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
            vertical-align: middle;
        }

        .stats-table tbody td {
            padding: 10px 8px;
            font-size: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .stats-table tbody tr:hover {
            background: #f8f9fa;
        }

        .stats-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-recuperacao {
            background: #ffc107;
            color: #333;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ============================================
           PROGRESS BAR
        ============================================ */
        .progress-custom {
            height: 8px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* ============================================
           GRÁFICOS
        ============================================ */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .chart-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .chart-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .chart-body {
            padding: 20px;
        }

        canvas {
            max-height: 300px;
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            border: none;
        }

        .alert-info-custom {
            background: linear-gradient(135deg, #cfe2ff 0%, #b8d4ff 100%);
            color: #084298;
        }

        .alert-danger-custom {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .alert-secondary-custom {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            color: #495057;
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

        .fade-in {
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
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .sidebar {
                display: none !important;
            }
            .page-header {
                background: #006B3E;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .info-card, .table-card, .chart-card {
                break-inside: avoid;
                page-break-inside: avoid;
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
            
            .charts-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.2rem;
            }
            
            .stats-table thead th,
            .stats-table tbody td {
                padding: 6px 4px;
                font-size: 0.65rem;
            }
            
            .badge-aprovado,
            .badge-recuperacao,
            .badge-reprovado {
                padding: 2px 6px;
                font-size: 0.6rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-card .row {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header fade-in">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-chart-pie me-2"></i> Estatística por Disciplina</h2>
                    <p>Análise estatística detalhada do desempenho por disciplina</p>
                </div>
                <div class="no-print d-flex flex-wrap gap-2">
                    <a href="index.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="gerarPDF()" class="btn-pdf">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button onclick="gerarExcel()" class="btn-excel">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print fade-in">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-book"></i> Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione uma disciplina</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']) . ' (' . htmlspecialchars($disciplina['codigo'] ?? '-') . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-chart-line"></i> Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($disciplina_id > 0 && $disciplina_info): ?>
        
        <!-- Informações da Disciplina -->
        <div class="info-card fade-in">
            <div class="info-header">
                <i class="fas fa-info-circle me-2"></i> Informações da Disciplina
            </div>
            <div class="info-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <small class="text-muted text-uppercase fw-bold">Disciplina</small>
                        <h6 class="mt-1"><?php echo htmlspecialchars($disciplina_info['nome']); ?></h6>
                    </div>
                    <div class="col-md-4 mb-3">
                        <small class="text-muted text-uppercase fw-bold">Código</small>
                        <h6 class="mt-1"><?php echo htmlspecialchars($disciplina_info['codigo'] ?? '-'); ?></h6>
                    </div>
                    <div class="col-md-4 mb-3">
                        <small class="text-muted text-uppercase fw-bold">Ano Letivo</small>
                        <h6 class="mt-1"><?php echo $ano_letivo_ano; ?></h6>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas Gerais da Disciplina -->
        <div class="stats-grid fade-in">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-school"></i></div>
                <div class="stat-number text-primary"><?php echo count($turmas_disciplina); ?></div>
                <div class="stat-label">Turmas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-number text-info"><?php echo $disciplina_media_geral; ?></div>
                <div class="stat-label">Média Geral</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number text-success"><?php echo $disciplina_taxa_aprovacao; ?>%</div>
                <div class="stat-label">Taxa de Aprovação</div>
            </div>
        </div>
        
        <!-- Destaques -->
        <div class="stats-grid fade-in">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                <div class="stat-number" style="color: #28a745;">🏆</div>
                <div class="stat-label">Melhor Turma</div>
                <h6 class="mt-2"><?php echo $melhor_turma ?: '-'; ?></h6>
                <small class="text-muted">Média: <?php echo $melhor_media > 0 ? $melhor_media : '-'; ?></small>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-number" style="color: #ffc107;">📉</div>
                <div class="stat-label">Turma com Menor Média</div>
                <h6 class="mt-2"><?php echo $pior_turma ?: '-'; ?></h6>
                <small class="text-muted">Média: <?php echo $pior_media < 100 ? $pior_media : '-'; ?></small>
            </div>
        </div>
        
        <!-- Tabela de Estatísticas por Turma -->
        <div class="table-card fade-in">
            <div class="table-header">
                <h5><i class="fas fa-chart-line me-2"></i> Estatísticas por Turma - <?php echo htmlspecialchars($disciplina_info['nome']); ?> (<?php echo $bimestre; ?>º Bimestre)</h5>
            </div>
            <div class="table-container">
                <?php if (empty($estatisticas)): ?>
                    <div class="text-center p-5">
                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                        <h5>Nenhuma estatística disponível</h5>
                        <p class="text-muted">Não há dados para esta disciplina no período selecionado.</p>
                    </div>
                <?php else: ?>
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th rowspan="2" width="20%"><i class="fas fa-users"></i> Turma</th>
                                <th rowspan="2" width="8%"><i class="fas fa-clock"></i> Turno</th>
                                <th rowspan="2" width="8%"><i class="fas fa-door-open"></i> Sala</th>
                                <th colspan="2" width="12%"><i class="fas fa-user-graduate"></i> Alunos</th>
                                <th colspan="3" width="18%"><i class="fas fa-star"></i> Notas</th>
                                <th colspan="3" width="20%"><i class="fas fa-flag-checkered"></i> Resultados</th>
                                <th rowspan="2" width="12%"><i class="fas fa-chart-line"></i> Aprovação</th>
                            </tr>
                            <tr>
                                <th>Total</th><th>C/ Nota</th>
                                <th>Média</th><th>Mín.</th><th>Máx.</th>
                                <th>Aprov</th><th>Recup</th><th>Reprov</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estatisticas as $est): ?>
                            <tr>
                                <td class="text-left"><strong><?php echo $est['turma_ano'] . 'ª ' . htmlspecialchars($est['turma_nome']); ?></strong></td>
                                <td class="text-center"><?php echo ucfirst($est['turma_turno']); ?></td>
                                <td class="text-center"><?php echo $est['turma_sala'] ?: '-'; ?></td>
                                <td class="text-center"><?php echo $est['total_alunos']; ?></td>
                                <td class="text-center"><?php echo $est['com_nota']; ?> <small class="text-muted">(<?php echo $est['percentual_com_nota']; ?>%)</small></td>
                                <td class="text-center"><strong class="text-primary"><?php echo $est['media_geral']; ?></strong></td>
                                <td class="text-center <?php echo $est['menor_nota'] < 5 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo $est['menor_nota'] > 0 ? $est['menor_nota'] : '-'; ?>
                                </td>
                                <td class="text-center <?php echo $est['maior_nota'] >= 14 ? 'text-success' : 'text-muted'; ?>">
                                    <?php echo $est['maior_nota'] > 0 ? $est['maior_nota'] : '-'; ?>
                                </td>
                                <td class="text-center"><span class="badge-aprovado"><?php echo $est['aprovados']; ?></span></td>
                                <td class="text-center"><span class="badge-recuperacao"><?php echo $est['recuperacao']; ?></span></td>
                                <td class="text-center"><span class="badge-reprovado"><?php echo $est['reprovados']; ?></span></td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold text-success"><?php echo $est['percentual_aprovacao']; ?>%</span>
                                        <div class="progress-custom flex-grow-1">
                                            <div class="progress-fill bg-success" style="width: <?php echo $est['percentual_aprovacao']; ?>%;"></div>
                                        </div>
                                    </div>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="charts-grid fade-in no-print">
            <div class="chart-card">
                <div class="chart-header">
                    <i class="fas fa-chart-bar me-2"></i> Média por Turma
                </div>
                <div class="chart-body">
                    <canvas id="chartMedias"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <i class="fas fa-chart-pie me-2"></i> Taxa de Aprovação por Turma
                </div>
                <div class="chart-body">
                    <canvas id="chartAprovacao"></canvas>
                </div>
            </div>
        </div>
        
        <?php elseif ($disciplina_id > 0 && !$disciplina_info): ?>
            <div class="alert-custom alert-danger-custom fade-in">
                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                <h5>Disciplina não encontrada</h5>
                <p class="mb-0">Você não tem acesso a esta disciplina ou ela não existe.</p>
            </div>
        <?php else: ?>
            <div class="alert-custom alert-secondary-custom fade-in">
                <i class="fas fa-filter fa-3x mb-3"></i>
                <h5>Selecione uma disciplina</h5>
                <p class="mb-0">Escolha uma disciplina para visualizar as estatísticas.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($disciplina_id > 0 && !empty($estatisticas)): ?>
        // Gráfico de Médias
        const mediasCtx = document.getElementById('chartMedias').getContext('2d');
        const turmas = [<?php foreach ($estatisticas as $est) echo "'" . $est['turma_ano'] . 'ª ' . addslashes($est['turma_nome']) . "', "; ?>];
        const medias = [<?php foreach ($estatisticas as $est) echo $est['media_geral'] . ", "; ?>];
        
        new Chart(mediasCtx, {
            type: 'bar',
            data: {
                labels: turmas,
                datasets: [{
                    label: 'Média Geral',
                    data: medias,
                    backgroundColor: '#006B3E',
                    borderRadius: 8,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 10 } } },
                    tooltip: { callbacks: { label: function(context) { return 'Média: ' + context.raw; } } }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        max: 20, 
                        title: { display: true, text: 'Nota', font: { size: 10 } },
                        grid: { color: '#e9ecef' }
                    },
                    x: { 
                        ticks: { 
                            autoSkip: false, 
                            rotation: 30, 
                            maxRotation: 30, 
                            minRotation: 30,
                            font: { size: 8 }
                        } 
                    }
                }
            }
        });
        
        // Gráfico de Aprovação
        const aprovacaoCtx = document.getElementById('chartAprovacao').getContext('2d');
        const aprovacoes = [<?php foreach ($estatisticas as $est) echo $est['percentual_aprovacao'] . ", "; ?>];
        
        new Chart(aprovacaoCtx, {
            type: 'bar',
            data: {
                labels: turmas,
                datasets: [{
                    label: 'Taxa de Aprovação (%)',
                    data: aprovacoes,
                    backgroundColor: '#28a745',
                    borderRadius: 8,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 10 } } },
                    tooltip: { callbacks: { label: function(context) { return 'Aprovação: ' + context.raw + '%'; } } }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        max: 100, 
                        title: { display: true, text: 'Percentual (%)', font: { size: 10 } },
                        grid: { color: '#e9ecef' }
                    },
                    x: { 
                        ticks: { 
                            autoSkip: false, 
                            rotation: 30, 
                            maxRotation: 30, 
                            minRotation: 30,
                            font: { size: 8 }
                        } 
                    }
                }
            }
        });
        <?php endif; ?>
        
        function gerarPDF() {
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (disciplinaId) {
                window.open(`gerar_pdf_estatistica_disciplina.php?disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione uma disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (disciplinaId) {
                window.location.href = `gerar_excel_estatistica_disciplina.php?disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione uma disciplina primeiro!');
            }
        }
        
        // Animações ao scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.page-header, .filter-card, .info-card, .stat-card, .table-card, .chart-card, .alert-custom').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>