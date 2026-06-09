<?php
// escola/avaliacao/sistema/index.php - Sistema de Avaliação Integrado

require_once __DIR__ . '/../../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$tipo_avaliacao = isset($_GET['tipo_avaliacao']) ? $_GET['tipo_avaliacao'] : 'todas';
$order_by = isset($_GET['order_by']) ? $_GET['order_by'] : 'nome';

// ============================================
// BUSCAR TURMAS (CURSOS)
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS POR TURMA
// ============================================
$disciplinas_turma = [];
if ($turma_id > 0) {
    $sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                        FROM disciplinas d
                        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                        WHERE pdt.turma_id = :turma_id
                        ORDER BY d.nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas_turma = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR TIPOS DE AVALIAÇÃO
// ============================================
$sql_tipos = "SELECT id, nome, cor, icone, peso_padrao, categoria
              FROM tipos_avaliacao 
              WHERE escola_id = :escola_id AND status = 'ativo'
              ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute([':escola_id' => $escola_id]);
$tipos_avaliacao = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÃO PARA OBTER STATUS
// ============================================
function getStatus($media) {
    if ($media === null || $media <= 0) {
        return ['texto' => 'Sem nota', 'classe' => 'text-secondary', 'icone' => 'fa-minus-circle', 'badge' => 'secondary'];
    }
    if ($media >= 14) {
        return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle', 'badge' => 'success'];
    }
    if ($media >= 10) {
        return ['texto' => 'Exame', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle', 'badge' => 'warning'];
    }
    return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle', 'badge' => 'danger'];
}

// ============================================
// BUSCAR DADOS PARA O SISTEMA DE AVALIAÇÃO
// ============================================
$alunos = [];
$estatisticas = [
    'total_alunos' => 0,
    'aprovados' => 0,
    'exame' => 0,
    'reprovados' => 0,
    'sem_nota' => 0,
    'media_geral' => 0,
    'maior_nota' => 0,
    'menor_nota' => 20,
    'total_faltas' => 0,
    'percentual_frequencia' => 0
];

$notas_data = [];
$distribuicao_notas = [
    '0-5' => 0,
    '5-9' => 0,
    '10-13' => 0,
    '14-17' => 0,
    '18-20' => 0
];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar informações da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY " . ($order_by == 'nome' ? 'e.nome' : 'e.matricula');
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_base = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    $soma_medias = 0;
    $total_com_media = 0;
    $total_faltas = 0;
    
    foreach ($alunos_base as $aluno) {
        // Buscar nota do aluno
        $sql_nota = "SELECT mac, npt, exame_normal, exame_oral, exame_escrito, media_final, data_lancamento
                    FROM notas 
                    WHERE estudante_id = :aluno_id 
                    AND disciplina_id = :disciplina_id 
                    AND bimestre = :bimestre
                    AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':aluno_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        
        $media = $nota_data ? (float)$nota_data['media_final'] : null;
        $mac = $nota_data ? (float)$nota_data['mac'] : null;
        $npt = $nota_data ? (float)$nota_data['npt'] : null;
        $exame_normal = $nota_data ? (float)$nota_data['exame_normal'] : null;
        
        // Calcular média se não existir
        if ($media === null && ($mac !== null || $npt !== null)) {
            if ($exame_normal !== null) {
                $media = ($mac + $npt) / 2 * 0.4 + $exame_normal * 0.6;
            } elseif ($mac !== null && $npt !== null) {
                $media = ($mac + $npt) / 2;
            } elseif ($mac !== null) {
                $media = $mac;
            } elseif ($npt !== null) {
                $media = $npt;
            }
            $media = round($media, 2);
        }
        
        // Buscar faltas do aluno
        $sql_faltas = "SELECT COUNT(*) as total_faltas 
                      FROM chamada 
                      WHERE estudante_id = :aluno_id 
                      AND disciplina_id = :disciplina_id 
                      AND bimestre = :bimestre
                      AND status IN ('falta', 'falta_justificada')";
        $stmt_faltas = $conn->prepare($sql_faltas);
        $stmt_faltas->execute([
            ':aluno_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre
        ]);
        $faltas_data = $stmt_faltas->fetch(PDO::FETCH_ASSOC);
        $faltas = $faltas_data['total_faltas'] ?? 0;
        $total_faltas += $faltas;
        
        $status = getStatus($media);
        
        // Distribuição de notas
        if ($media !== null && $media > 0) {
            if ($media < 5) $distribuicao_notas['0-5']++;
            elseif ($media < 10) $distribuicao_notas['5-9']++;
            elseif ($media < 14) $distribuicao_notas['10-13']++;
            elseif ($media < 18) $distribuicao_notas['14-17']++;
            else $distribuicao_notas['18-20']++;
            
            $soma_medias += $media;
            $total_com_media++;
            
            if ($media >= 14) $estatisticas['aprovados']++;
            elseif ($media >= 10) $estatisticas['exame']++;
            else $estatisticas['reprovados']++;
            
            if ($media > $estatisticas['maior_nota']) $estatisticas['maior_nota'] = $media;
            if ($media < $estatisticas['menor_nota']) $estatisticas['menor_nota'] = $media;
        } else {
            $estatisticas['sem_nota']++;
        }
        
        $alunos[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'data_nascimento' => $aluno['data_nascimento'],
            'mac' => $mac,
            'npt' => $npt,
            'exame_normal' => $exame_normal,
            'media' => $media,
            'faltas' => $faltas,
            'status' => $status
        ];
    }
    
    $estatisticas['total_alunos'] = count($alunos);
    $estatisticas['media_geral'] = $total_com_media > 0 ? round($soma_medias / $total_com_media, 2) : 0;
    $estatisticas['total_faltas'] = $total_faltas;
    $estatisticas['percentual_frequencia'] = $estatisticas['total_alunos'] > 0 ? 
        round((($estatisticas['total_alunos'] * 60 - $total_faltas) / ($estatisticas['total_alunos'] * 60)) * 100, 1) : 0;
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Avaliação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-number.total { color: #006B3E; }
        .stat-number.aprovados { color: #28a745; }
        .stat-number.exame { color: #ffc107; }
        .stat-number.reprovados { color: #dc3545; }
        
        .disciplina-card {
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        
        .disciplina-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .disciplina-card.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
        }
        
        .table-avaliacao th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            border-left: 4px solid #006B3E;
            padding-left: 10px;
        }
        
        .btn-print, .btn-excel, .btn-pdf {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        
        .btn-excel {
            background: #28a745;
        }
        
        .btn-pdf {
            background: #dc3545;
        }
        
        .btn-excel:hover { background: #1e7e34; }
        .btn-pdf:hover { background: #c82333; }
        .btn-print:hover { background: #138496; }
        
        .bimestre-btn {
            margin: 0 5px;
        }
        
        .bimestre-btn.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
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
                padding: 10px;
            }
            .page-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chart-line"></i> Sistema de Avaliação</h2>
                    <p>Dashboard completo de desempenho por turma, disciplina e bimestre</p>
                </div>
                <div class="no-print">
                    <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
                        <button onclick="exportarExcel()" class="btn-excel btn me-2">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button onclick="gerarPDF()" class="btn-pdf btn me-2">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn-print btn">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <a href="../../dashboard.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="0">Selecione uma turma...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" id="disciplina_id" onchange="this.form.submit()">
                        <option value="0">Selecione uma disciplina...</option>
                        <?php foreach ($disciplinas_turma as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_id == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Ordenar por</label>
                    <select name="order_by" class="form-select" onchange="this.form.submit()">
                        <option value="nome" <?php echo $order_by == 'nome' ? 'selected' : ''; ?>>Nome</option>
                        <option value="matricula" <?php echo $order_by == 'matricula' ? 'selected' : ''; ?>>Matrícula</option>
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
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas['total_alunos']; ?></div>
                    <small>Total de Alunos</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <div class="stat-number"><?php echo number_format($estatisticas['media_geral'], 1, ',', '.'); ?></div>
                    <small>Média da Turma</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-trophy fa-2x text-warning mb-2"></i>
                    <div class="stat-number"><?php echo number_format($estatisticas['maior_nota'], 1, ',', '.'); ?></div>
                    <small>Maior Nota</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-chart-simple fa-2x text-secondary mb-2"></i>
                    <div class="stat-number"><?php echo number_format($estatisticas['menor_nota'], 1, ',', '.'); ?></div>
                    <small>Menor Nota</small>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number aprovados"><?php echo $estatisticas['aprovados']; ?></div>
                    <small>Aprovados (≥14)</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <div class="stat-number exame"><?php echo $estatisticas['exame']; ?></div>
                    <small>Exame (10-13)</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stat-number reprovados"><?php echo $estatisticas['reprovados']; ?></div>
                    <small>Reprovados (<10)</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-minus-circle fa-2x text-secondary mb-2"></i>
                    <div class="stat-number"><?php echo $estatisticas['sem_nota']; ?></div>
                    <small>Sem Nota</small>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-chart-pie"></i> Distribuição por Status
                    </div>
                    <canvas id="statusChart" style="height: 250px;"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-chart-bar"></i> Distribuição de Notas
                    </div>
                    <canvas id="distribuicaoChart" style="height: 250px;"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Informações da Turma e Disciplina -->
        <div class="alert alert-info mb-4">
            <div class="row">
                <div class="col-md-4">
                    <strong><i class="fas fa-school"></i> Turma:</strong><br>
                    <?php echo $turma_info['ano'] . 'ª ' . $turma_info['nome'] . ' (' . ucfirst($turma_info['turno']) . ')'; ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="fas fa-book"></i> Disciplina:</strong><br>
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?>
                    <?php if ($disciplina_info['codigo']): ?>
                        <small class="text-muted">(<?php echo $disciplina_info['codigo']; ?>)</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="fas fa-calendar-alt"></i> Bimestre:</strong><br>
                    <?php echo $bimestre; ?>º Bimestre
                </div>
            </div>
        </div>
        
        <!-- Tabela de Alunos -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-table"></i> Desempenho dos Alunos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-avaliacao" id="tabelaAvaliacoes">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Matrícula</th>
                                <th width="25%">Aluno</th>
                                <th width="8%">Gênero</th>
                                <th width="8%">MAC</th>
                                <th width="8%">NPT</th>
                                <th width="8%">Exame</th>
                                <th width="8%">Média</th>
                                <th width="8%">Faltas</th>
                                <th width="10%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno): ?>
                            <tr>
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td class="text-center">
                                    <?php if ($aluno['genero'] == 'masculino'): ?>
                                        <i class="fas fa-mars text-primary"></i> M
                                    <?php else: ?>
                                        <i class="fas fa-venus text-danger"></i> F
                                    <?php endif; ?>
                                </td>
                                <td class="nota-cell"><?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1, ',', '.') : '---'; ?></td>
                                <td class="fw-bold <?php echo $aluno['status']['classe']; ?>">
                                    <?php echo $aluno['media'] !== null ? number_format($aluno['media'], 1, ',', '.') : '---'; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $aluno['faltas'] > 15 ? 'danger' : ($aluno['faltas'] > 10 ? 'warning' : 'success'); ?>">
                                        <?php echo $aluno['faltas']; ?>
                                    </span>
                                </td>
                                <td class="<?php echo $aluno['status']['classe']; ?>">
                                    <i class="fas <?php echo $aluno['status']['icone']; ?>"></i> <?php echo $aluno['status']['texto']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="8" class="text-end fw-bold">Média Geral da Turma:</td>
                                <td colspan="2" class="fw-bold"><?php echo number_format($estatisticas['media_geral'], 1, ',', '.'); ?> valores</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id == 0 && !empty($disciplinas_turma)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione uma disciplina para visualizar o sistema de avaliação.
            </div>
            
            <!-- Disciplinas da Turma -->
            <div class="card mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-book"></i> Disciplinas do Curso</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($disciplinas_turma as $disc): ?>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disc['id']; ?>&bimestre=<?php echo $bimestre; ?>" 
                               class="text-decoration-none">
                                <div class="card disciplina-card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-book fa-2x text-primary mb-2"></i>
                                        <h6 class="card-title mb-0"><?php echo htmlspecialchars($disc['nome']); ?></h6>
                                        <?php if ($disc['codigo']): ?>
                                            <small class="text-muted"><?php echo $disc['codigo']; ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar o sistema de avaliação.
            </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos)): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Nenhum aluno encontrado para esta turma/disciplina.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $('#tabelaAvaliacoes').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[2, 'asc']],
            pageLength: 25
        });
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        // Gráfico de Status
        const ctxStatus = document.getElementById('statusChart').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Exame', 'Reprovados', 'Sem nota'],
                datasets: [{
                    data: [<?php echo $estatisticas['aprovados']; ?>, <?php echo $estatisticas['exame']; ?>, <?php echo $estatisticas['reprovados']; ?>, <?php echo $estatisticas['sem_nota']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Gráfico de Distribuição de Notas
        const ctxDistribuicao = document.getElementById('distribuicaoChart').getContext('2d');
        new Chart(ctxDistribuicao, {
            type: 'bar',
            data: {
                labels: ['0-5', '5-9', '10-13', '14-17', '18-20'],
                datasets: [{
                    label: 'Número de Alunos',
                    data: [
                        <?php echo $distribuicao_notas['0-5']; ?>,
                        <?php echo $distribuicao_notas['5-9']; ?>,
                        <?php echo $distribuicao_notas['10-13']; ?>,
                        <?php echo $distribuicao_notas['14-17']; ?>,
                        <?php echo $distribuicao_notas['18-20']; ?>
                    ],
                    backgroundColor: '#006B3E',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        function exportarExcel() {
            window.location.href = 'exportar_excel_sistema.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&bimestre=<?php echo $bimestre; ?>';
        }
        
        function gerarPDF() {
            window.open('gerar_pdf_sistema.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&bimestre=<?php echo $bimestre; ?>', '_blank');
        }
    </script>
</body>
</html>