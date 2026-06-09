<?php
// escola/professor/relatorios/pautas_gerais.php - Pautas Gerais de Notas (Todos os Bimestres)

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

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
// BUSCAR ALUNOS E NOTAS (TODOS OS BIMESTRES)
// ============================================
$alunos = [];
$turma_info = null;
$disciplina_info = null;
$is_ensino_fundamental = false;
$classe_ano = 0;

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar info da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    $classe_ano = $turma_info['ano'] ?? 0;
    $is_ensino_fundamental = ($classe_ano <= 6);
    
    // Buscar info da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos e notas (todos os bimestres)
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            n.bimestre,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.media_final,
            n.status as situacao
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        LEFT JOIN notas n ON n.estudante_id = e.id 
            AND n.disciplina_id = :disciplina_id 
            AND n.ano_letivo_id = :ano_letivo_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome, n.bimestre
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $notas_raw = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar notas por aluno e bimestre
    $alunos_data = [];
    foreach ($notas_raw as $nota) {
        $aluno_id = $nota['id'];
        if (!isset($alunos_data[$aluno_id])) {
            $alunos_data[$aluno_id] = [
                'nome' => $nota['nome'],
                'matricula' => $nota['matricula'],
                'bimestres' => []
            ];
        }
        $bim = $nota['bimestre'];
        if ($bim) {
            $alunos_data[$aluno_id]['bimestres'][$bim] = [
                'mac' => $nota['mac'],
                'npt' => $nota['npt'],
                'media_final' => $nota['media_final'],
                'situacao' => $nota['situacao']
            ];
        }
    }
    
    // Completar bimestres faltantes e calcular média anual
    foreach ($alunos_data as $aluno_id => &$aluno) {
        $soma_medias = 0;
        $bimestres_com_nota = 0;
        for ($b = 1; $b <= 3; $b++) {
            if (!isset($aluno['bimestres'][$b])) {
                $aluno['bimestres'][$b] = [
                    'mac' => null,
                    'npt' => null,
                    'media_final' => 0,
                    'situacao' => 'pendente'
                ];
            } else {
                if ($aluno['bimestres'][$b]['media_final'] > 0) {
                    $soma_medias += $aluno['bimestres'][$b]['media_final'];
                    $bimestres_com_nota++;
                }
            }
        }
        $aluno['media_anual'] = $bimestres_com_nota > 0 ? round($soma_medias / $bimestres_com_nota, 1) : 0;
        
        // Calcular situação final baseada na escala
        if ($is_ensino_fundamental) {
            if ($aluno['media_anual'] >= 5) {
                $aluno['situacao_final'] = 'aprovado';
            } elseif ($aluno['media_anual'] >= 4.5) {
                $aluno['situacao_final'] = 'recuperacao';
            } elseif ($aluno['media_anual'] > 0) {
                $aluno['situacao_final'] = 'reprovado';
            } else {
                $aluno['situacao_final'] = 'pendente';
            }
        } else {
            if ($aluno['media_anual'] >= 10) {
                $aluno['situacao_final'] = 'aprovado';
            } elseif ($aluno['media_anual'] >= 9.5) {
                $aluno['situacao_final'] = 'recuperacao';
            } elseif ($aluno['media_anual'] > 0) {
                $aluno['situacao_final'] = 'reprovado';
            } else {
                $aluno['situacao_final'] = 'pendente';
            }
        }
    }
    unset($aluno);
    $alunos = $alunos_data;
}

// ============================================
// FUNÇÃO PARA COR DA NOTA
// ============================================
function getCorNota($nota, $is_ensino_fundamental) {
    if ($nota === null || $nota === '' || $nota <= 0) {
        return '';
    }
    
    if ($is_ensino_fundamental) {
        // Ensino Fundamental (1ª à 6ª Classe) - Escala 0-10
        if ($nota >= 4.5) {
            return 'cor-verde';
        } else {
            return 'cor-vermelha';
        }
    } else {
        // Ensino Secundário (7ª à 12ª Classe) - Escala 0-20
        if ($nota >= 9.5) {
            return 'cor-verde';
        } else {
            return 'cor-vermelha';
        }
    }
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_medias_anuais = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao_final'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao_final'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao_final'] == 'reprovado') $total_reprovados++;
    $soma_medias_anuais += $aluno['media_anual'];
}
$media_geral_anual = $total_alunos > 0 ? round($soma_medias_anuais / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getSituacaoBadge($situacao) {
    switch ($situacao) {
        case 'aprovado':
            return '<span class="badge-status badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge-status badge-recuperacao"><i class="fas fa-clock"></i> Recuperação</span>';
        case 'reprovado':
            return '<span class="badge-status badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
        default:
            return '<span class="badge-status badge-pendente"><i class="fas fa-hourglass-half"></i> Pendente</span>';
    }
}

function getNotaFormatada($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format($nota, 1);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Pautas Gerais | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
           STAT CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
           TABELA DE NOTAS
        ============================================ */
        .table-card {
            background: white;
            border-radius: 24px;
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

        .pauta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .pauta-table thead th {
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

        .pauta-table tbody td {
            padding: 10px 6px;
            font-size: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .pauta-table tbody tr:hover {
            background: #f8f9fa;
        }

        .pauta-table tbody tr:last-child td {
            border-bottom: none;
        }

        .aluno-cell {
            text-align: left !important;
            font-weight: 600;
            color: #333;
        }

        /* ============================================
           CORES DAS NOTAS
        ============================================ */
        .cor-verde {
            color: #28a745 !important;
            font-weight: 700;
        }

        .cor-vermelha {
            color: #dc3545 !important;
            font-weight: 700;
        }

        /* ============================================
           BADGES DE STATUS
        ============================================ */
        .badge-status {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-aprovado {
            background: #28a745;
            color: white;
        }

        .badge-recuperacao {
            background: #ffc107;
            color: #333;
        }

        .badge-reprovado {
            background: #dc3545;
            color: white;
        }

        .badge-pendente {
            background: #6c757d;
            color: white;
        }

        /* ============================================
           LEGENDA
        ============================================ */
        .legend-card {
            background: white;
            border-radius: 20px;
            margin-top: 30px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .legend-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 12px 25px;
            font-weight: 600;
        }

        .legend-body {
            padding: 20px;
        }

        .legend-item {
            display: inline-block;
            margin-right: 25px;
            margin-bottom: 10px;
            font-size: 0.75rem;
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

        .alert-warning-custom {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);
            color: #856404;
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
            .table-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .pauta-table tbody tr {
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
            
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.2rem;
            }
            
            .pauta-table thead th,
            .pauta-table tbody td {
                padding: 6px 3px;
                font-size: 0.65rem;
            }
            
            .badge-status {
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
                    <h2><i class="fas fa-file-alt me-2"></i> Pautas Gerais</h2>
                    <p>Relatório completo com todas as notas e bimestres da turma</p>
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
                <div class="col-md-5">
                    <label class="form-label"><i class="fas fa-users"></i> Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label"><i class="fas fa-book"></i> Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid fade-in">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number text-primary"><?php echo $total_alunos; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number text-success"><?php echo $total_aprovados; ?></div>
                <div class="stat-label">Aprovados</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number text-warning"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Recuperação</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-number text-danger"><?php echo $total_reprovados; ?></div>
                <div class="stat-label">Reprovados</div>
            </div>
        </div>
        
        <div class="stats-grid fade-in">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-number text-info"><?php echo $media_geral_anual; ?></div>
                <div class="stat-label">Média Anual da Turma</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-percent"></i></div>
                <div class="stat-number text-success"><?php echo $percentual_aprovacao; ?>%</div>
                <div class="stat-label">Taxa de Aprovação</div>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <div class="table-card fade-in">
            <div class="table-header">
                <h5>
                    <i class="fas fa-chalkboard me-2"></i> 
                    <?php echo $turma_info['ano'] . 'ª - ' . $turma_info['nome']; ?> | 
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?>
                </h5>
            </div>
            <div class="table-container">
                <table class="pauta-table">
                    <thead>
                        <tr>
                            <th rowspan="2" width="4%">#</th>
                            <th rowspan="2" width="22%">Aluno</th>
                            <th rowspan="2" width="9%">Matrícula</th>
                            <th colspan="3" class="text-center">1º Bimestre</th>
                            <th colspan="3" class="text-center">2º Bimestre</th>
                            <th colspan="3" class="text-center">3º Bimestre</th>
                            <th rowspan="2" width="8%">Média<br>Anual</th>
                            <th rowspan="2" width="12%">Situação<br>Final</th>
                        </tr>
                        <tr>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $contador = 1; ?>
                        <?php foreach ($alunos as $aluno_id => $aluno): ?>
                        <?php
                            $b1 = $aluno['bimestres'][1];
                            $b2 = $aluno['bimestres'][2];
                            $b3 = $aluno['bimestres'][3];
                            
                            $cor_b1_mac = getCorNota($b1['mac'], $is_ensino_fundamental);
                            $cor_b1_npt = getCorNota($b1['npt'], $is_ensino_fundamental);
                            $cor_b1_media = getCorNota($b1['media_final'], $is_ensino_fundamental);
                            
                            $cor_b2_mac = getCorNota($b2['mac'], $is_ensino_fundamental);
                            $cor_b2_npt = getCorNota($b2['npt'], $is_ensino_fundamental);
                            $cor_b2_media = getCorNota($b2['media_final'], $is_ensino_fundamental);
                            
                            $cor_b3_mac = getCorNota($b3['mac'], $is_ensino_fundamental);
                            $cor_b3_npt = getCorNota($b3['npt'], $is_ensino_fundamental);
                            $cor_b3_media = getCorNota($b3['media_final'], $is_ensino_fundamental);
                            
                            $cor_media_anual = getCorNota($aluno['media_anual'], $is_ensino_fundamental);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $contador++; ?></td>
                            <td class="aluno-cell"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            
                            <!-- 1º Bimestre -->
                            <td class="text-center <?php echo $cor_b1_mac; ?>"><?php echo getNotaFormatada($b1['mac']); ?></td>
                            <td class="text-center <?php echo $cor_b1_npt; ?>"><?php echo getNotaFormatada($b1['npt']); ?></td>
                            <td class="text-center <?php echo $cor_b1_media; ?>"><strong><?php echo getNotaFormatada($b1['media_final']); ?></strong></td>
                            
                            <!-- 2º Bimestre -->
                            <td class="text-center <?php echo $cor_b2_mac; ?>"><?php echo getNotaFormatada($b2['mac']); ?></td>
                            <td class="text-center <?php echo $cor_b2_npt; ?>"><?php echo getNotaFormatada($b2['npt']); ?></td>
                            <td class="text-center <?php echo $cor_b2_media; ?>"><strong><?php echo getNotaFormatada($b2['media_final']); ?></strong></td>
                            
                            <!-- 3º Bimestre -->
                            <td class="text-center <?php echo $cor_b3_mac; ?>"><?php echo getNotaFormatada($b3['mac']); ?></td>
                            <td class="text-center <?php echo $cor_b3_npt; ?>"><?php echo getNotaFormatada($b3['npt']); ?></td>
                            <td class="text-center <?php echo $cor_b3_media; ?>"><strong><?php echo getNotaFormatada($b3['media_final']); ?></strong></td>
                            
                            <!-- Média Anual -->
                            <td class="text-center <?php echo $cor_media_anual; ?>"><strong><?php echo number_format($aluno['media_anual'], 1); ?></strong></td>
                            
                            <!-- Situação Final -->
                            <td class="text-center"><?php echo getSituacaoBadge($aluno['situacao_final']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Legenda -->
        <div class="legend-card no-print fade-in">
            <div class="legend-header">
                <i class="fas fa-info-circle me-2"></i> Legenda de Cores e Status
            </div>
            <div class="legend-body">
                <div class="legend-item">
                    <span class="badge-status badge-aprovado me-1"><i class="fas fa-check-circle"></i> Aprovado</span>
                    Média anual ≥ <?php echo $is_ensino_fundamental ? '5' : '10'; ?>
                </div>
                <div class="legend-item">
                    <span class="badge-status badge-recuperacao me-1"><i class="fas fa-clock"></i> Recuperação</span>
                    Média anual ≥ <?php echo $is_ensino_fundamental ? '4.5' : '9.5'; ?>
                </div>
                <div class="legend-item">
                    <span class="badge-status badge-reprovado me-1"><i class="fas fa-times-circle"></i> Reprovado</span>
                    Média anual < <?php echo $is_ensino_fundamental ? '4.5' : '9.5'; ?>
                </div>
                <div class="legend-item">
                    <span class="badge-status badge-pendente me-1"><i class="fas fa-hourglass-half"></i> Pendente</span>
                    Sem notas lançadas
                </div>
                <div class="legend-item">
                    <span class="cor-verde">●</span> Nota positiva (≥ <?php echo $is_ensino_fundamental ? '4.5' : '9.5'; ?>)
                </div>
                <div class="legend-item">
                    <span class="cor-vermelha">●</span> Nota negativa (< <?php echo $is_ensino_fundamental ? '4.5' : '9.5'; ?>)
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id > 0): ?>
            <div class="alert-custom alert-info-custom fade-in">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>Nenhum aluno encontrado</h5>
                <p class="mb-0">Não há alunos matriculados nesta turma.</p>
            </div>
        <?php elseif ($turma_id > 0): ?>
            <div class="alert-custom alert-warning-custom fade-in">
                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                <h5>Selecione uma disciplina</h5>
                <p class="mb-0">Escolha uma disciplina para visualizar as notas.</p>
            </div>
        <?php else: ?>
            <div class="alert-custom alert-secondary-custom fade-in">
                <i class="fas fa-filter fa-3x mb-3"></i>
                <h5>Selecione os filtros</h5>
                <p class="mb-0">Escolha uma turma e disciplina para visualizar as notas.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function gerarPDF() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_pautas_gerais.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_pautas_gerais.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}`;
            } else {
                alert('Selecione a turma e disciplina primeiro!');
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
        
        document.querySelectorAll('.page-header, .filter-card, .stat-card, .table-card, .legend-card, .alert-custom').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>