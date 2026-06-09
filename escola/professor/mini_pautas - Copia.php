<?php
// escola/professor/relatorios/mini_pautas.php - Mini Pautas de Notas

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
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos = [];
$turma_info = null;
$disciplina_info = null;
$is_classe_exame = false;
$is_ensino_fundamental = false;
$is_disciplina_lingua = false;

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar info da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Determinar se é classe de exame (6ª, 9ª, 12ª)
    $classe_ano = $turma_info['ano'] ?? 0;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    $is_ensino_fundamental = ($classe_ano <= 6);
    $limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
    
    // Buscar info da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $disciplina_nome = $disciplina_info['nome'] ?? '';
    $is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || stripos($disciplina_nome, 'inglês') !== false);
    
    // Buscar alunos e notas
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.exame_oral,
            n.exame_escrito,
            n.media_final,
            n.status as situacao
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        LEFT JOIN notas n ON n.estudante_id = e.id 
            AND n.disciplina_id = :disciplina_id 
            AND n.bimestre = :bimestre 
            AND n.ano_letivo_id = :ano_letivo_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular média final para cada aluno (caso não exista no banco)
    foreach ($alunos as &$aluno) {
        if ($bimestre == 3) {
            $media_final = calcularMediaFinal3Bimestre($aluno, $is_classe_exame, $is_disciplina_lingua, $is_ensino_fundamental);
            if ($aluno['media_final'] == 0 || $aluno['media_final'] == null) {
                $aluno['media_final'] = $media_final;
                
                // Determinar situação
                if ($media_final > $limite_aprovacao) {
                    $aluno['situacao'] = 'aprovado';
                } elseif ($media_final == $limite_aprovacao && $media_final > 0) {
                    $aluno['situacao'] = 'recuperacao';
                } elseif ($media_final > 0 && $media_final < $limite_aprovacao) {
                    $aluno['situacao'] = 'reprovado';
                }
            }
        }
    }
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA DO 3º BIMESTRE
// ============================================
function calcularMediaFinal3Bimestre($aluno, $is_classe_exame, $is_disciplina_lingua, $is_ensino_fundamental) {
    $mac = floatval($aluno['mac'] ?? 0);
    $npt = floatval($aluno['npt'] ?? 0);
    $exame_normal = floatval($aluno['exame_normal'] ?? 0);
    $exame_recurso = floatval($aluno['exame_recurso'] ?? 0);
    $exame_oral = floatval($aluno['exame_oral'] ?? 0);
    $exame_escrito = floatval($aluno['exame_escrito'] ?? 0);
    
    if ($is_classe_exame) {
        // Classes de Exame (6ª, 9ª, 12ª) - NPT NÃO é considerado
        $media_parcial = $mac; // Apenas MAC
        
        if ($exame_recurso > 0) {
            // Se houver exame de recurso, a média final é a nota do recurso
            return $exame_recurso;
        } else {
            if ($is_disciplina_lingua) {
                // Língua Portuguesa ou Inglês
                if ($exame_oral > 0 && $exame_escrito > 0) {
                    $media_exame = ($exame_oral + $exame_escrito) / 2;
                    return round(($media_parcial * 0.4) + ($media_exame * 0.6), 1);
                } elseif ($exame_oral > 0) {
                    return round(($media_parcial * 0.4) + ($exame_oral * 0.6), 1);
                } elseif ($exame_escrito > 0) {
                    return round(($media_parcial * 0.4) + ($exame_escrito * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            } else {
                // Outras disciplinas
                if ($exame_normal > 0) {
                    return round(($media_parcial * 0.4) + ($exame_normal * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            }
        }
    } else {
        // Classes normais (1ª,2ª,3ª,4ª,5ª,7ª,8ª,10ª,11ª) - NPT é considerado
        $media_parcial = ($mac + $npt) / 2;
        
        if ($exame_recurso > 0) {
            return round(($media_parcial + $exame_recurso) / 2, 1);
        } elseif ($exame_normal > 0) {
            return round(($media_parcial + $exame_normal) / 2, 1);
        } else {
            return round($media_parcial, 1);
        }
    }
}

// ============================================
// FUNÇÃO PARA FORMATAR NOTA
// ============================================
function formatarNota($valor) {
    if ($valor === null || $valor === '') return '-';
    return number_format(floatval($valor), 1);
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao'] == 'reprovado') $total_reprovados++;
    
    if ($aluno['media_final'] > 0) {
        $soma_notas += $aluno['media_final'];
    }
}
$media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;
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

function getNotaClass($nota, $is_ensino_fundamental) {
    $limite = $is_ensino_fundamental ? 5 : 10;
    if ($nota > $limite) return 'nota-excelente';
    elseif ($nota == $limite) return 'nota-regular';
    elseif ($nota > 0 && $nota < $limite) return 'nota-ruim';
    return '';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Mini Pautas | Professor | SIGE Angola</title>
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
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           CARD DA TABELA
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

        /* ============================================
           TABELA DE NOTAS
        ============================================ */
        .pauta-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pauta-table thead th {
            background: #f8f9fa;
            padding: 14px 12px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .pauta-table tbody td {
            padding: 12px;
            font-size: 0.85rem;
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
           CORES DAS NOTAS
        ============================================ */
        .nota-excelente {
            color: #28a745;
            font-weight: 700;
        }

        .nota-regular {
            color: #ffc107;
            font-weight: 700;
        }

        .nota-ruim {
            color: #dc3545;
            font-weight: 700;
        }

        /* ============================================
           RODAPÉ
        ============================================ */
        .footer {
            text-align: center;
            padding: 20px;
            margin-top: 20px;
        }

        .footer hr {
            margin: 15px 0;
            border-color: rgba(0, 0, 0, 0.1);
        }

        .footer-text {
            font-size: 0.7rem;
            color: #6c757d;
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
                padding: 8px;
                font-size: 0.75rem;
            }
            
            .badge-status {
                padding: 3px 8px;
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
                    <h2><i class="fas fa-file-alt me-2"></i> Mini Pautas</h2>
                    <p>Visualize as notas dos alunos de forma rápida e resumida</p>
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
                <div class="col-md-4">
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
                <div class="col-md-4">
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
                    <label class="form-label"><i class="fas fa-chart-line"></i> Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
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
        
        <!-- Info de Regras -->
        <?php if ($bimestre == 3): ?>
        <div class="alert alert-info mb-3 fade-in">
            <i class="fas fa-info-circle"></i> 
            <strong>Regras do 3º Bimestre:</strong>
            <?php 
            $classe_ano = $turma_info['ano'] ?? 0;
            $is_classe_exame_local = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
            $disciplina_nome_local = $disciplina_info['nome'] ?? '';
            $is_disciplina_lingua_local = (stripos($disciplina_nome_local, 'português') !== false || stripos($disciplina_nome_local, 'inglês') !== false);
            
            if ($is_classe_exame_local): ?>
                <strong>Classe de Exame (<?php echo $classe_ano; ?>ª)</strong> - 
                Média = 40% (MAC) + 60% (Exame)
                <strong>(NPT não é considerado)</strong>
                <?php if ($is_disciplina_lingua_local): ?>
                    <br>Disciplina de Língua: Exame Oral + Escrito
                <?php endif; ?>
                <br>Se houver Exame Recurso, a nota final será a nota do recurso.
            <?php else: ?>
                <strong>Classe Normal</strong> - 
                Média = (MAC + NPT)/2. Com exame: (Média Parcial + Exame)/2
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Resumo da Turma -->
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
                <div class="stat-number text-info"><?php echo $media_geral; ?></div>
                <div class="stat-label">Média Geral da Turma</div>
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
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?> | 
                    <?php echo $bimestre; ?>º Bimestre
                </h5>
            </div>
            <div class="table-container">
                <table class="pauta-table">
                    <thead>
                        <tr>
                            <th width="3%">#</th>
                            <th width="20%"><i class="fas fa-user-graduate"></i> Aluno</th>
                            <th width="8%"><i class="fas fa-id-card"></i> Matrícula</th>
                            <th width="8%"><i class="fas fa-calculator"></i> MAC</th>
                            <!-- NPT: Só aparece para classes normais (NÃO classes de exame) -->
                            <?php if ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame)): ?>
                            <th width="8%"><i class="fas fa-edit"></i> NPT</th>
                            <?php endif; ?>
                            <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                            <th width="10%"><i class="fas fa-file-alt"></i> Exame Normal</th>
                            <th width="10%"><i class="fas fa-repeat"></i> Exame Recurso</th>
                            <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                            <th width="8%"><i class="fas fa-microphone-alt"></i> Exame Oral</th>
                            <th width="8%"><i class="fas fa-pen-fancy"></i> Exame Escrito</th>
                            <th width="8%"><i class="fas fa-repeat"></i> Exame Recurso</th>
                            <?php elseif ($bimestre == 3): ?>
                            <th width="8%"><i class="fas fa-file-alt"></i> Exame</th>
                            <?php endif; ?>
                            <th width="8%"><i class="fas fa-star"></i> Média</th>
                            <th width="12%"><i class="fas fa-flag-checkered"></i> Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos as $index => $aluno): 
                            $media = $aluno['media_final'] ?? 0;
                            $nota_class = getNotaClass($media, $is_ensino_fundamental);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td class="text-start"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                            <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            <td class="text-center"><?php echo formatarNota($aluno['mac']); ?></td>
                            <!-- NPT: Só aparece para classes normais -->
                            <?php if ($bimestre != 3 || ($bimestre == 3 && !$is_classe_exame)): ?>
                            <td class="text-center"><?php echo formatarNota($aluno['npt']); ?></td>
                            <?php endif; ?>
                            <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_normal']); ?></td>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_recurso']); ?></td>
                            <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_oral']); ?></td>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_escrito']); ?></td>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_recurso']); ?></td>
                            <?php elseif ($bimestre == 3): ?>
                            <td class="text-center"><?php echo formatarNota($aluno['exame_normal']); ?></td>
                            <?php endif; ?>
                            <td class="text-center"><span class="<?php echo $nota_class; ?>"><?php echo number_format($media, 1); ?></span></td>
                            <td class="text-center"><?php echo getSituacaoBadge($aluno['situacao']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Rodapé do Relatório -->
        <div class="footer fade-in no-print">
            <hr>
            <div class="footer-text">
                <i class="fas fa-file-alt"></i> 
                Relatório gerado em <?php echo date('d/m/Y H:i:s'); ?> | 
                SIGE Angola - Sistema Integrado de Gestão Escolar
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
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_mini_pautas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_mini_pautas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
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
        
        document.querySelectorAll('.page-header, .filter-card, .stat-card, .table-card, .alert-custom').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>