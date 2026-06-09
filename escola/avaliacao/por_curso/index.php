<?php
// escola/avaliacao/por_curso/index.php - Avaliação por Curso/Série

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
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;

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
// FUNÇÃO PARA OBTER STATUS DA MÉDIA
// ============================================
function getStatusMedia($media) {
    if ($media === null || $media <= 0) {
        return ['texto' => 'Sem nota', 'classe' => 'text-secondary', 'icone' => 'fa-minus-circle'];
    }
    if ($media >= 14) {
        return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle'];
    }
    if ($media >= 10) {
        return ['texto' => 'Exame', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle'];
    }
    return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle'];
}

// ============================================
// BUSCAR ALUNOS E AVALIAÇÕES
// ============================================
$alunos = [];
$provas = [];
$estatisticas = [];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_base = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar provas/avaliações do trimestre
    $sql_provas = "SELECT p.*, ta.nome as tipo_nome, ta.cor as tipo_cor, ta.icone as tipo_icone
                   FROM provas p
                   LEFT JOIN tipos_avaliacao ta ON ta.id = p.tipo_prova
                   WHERE p.turma_id = :turma_id 
                   AND p.disciplina_id = :disciplina_id
                   AND p.ano_letivo_id = :ano_letivo_id
                   AND p.periodo = :periodo
                   ORDER BY p.data_prova ASC";
    $stmt_provas = $conn->prepare($sql_provas);
    $stmt_provas->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id,
        ':periodo' => $trimestre . 'º Bimestre'
    ]);
    $provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);
    
    // Se não houver provas cadastradas, criar uma entrada padrão
    if (empty($provas)) {
        $provas[] = [
            'id' => 0,
            'titulo' => 'Avaliação Trimestral',
            'tipo_nome' => 'Média do Trimestre',
            'tipo_cor' => '#006B3E',
            'tipo_icone' => 'fa-chart-line',
            'data_prova' => date('Y-m-d'),
            'valor_total' => 20
        ];
    }
    
    // Organizar alunos com suas notas
    foreach ($alunos_base as $aluno) {
        $aluno_notas = [];
        
        // CORRIGIDO: Buscar a média final do aluno para esta disciplina e trimestre
        $sql_nota = "SELECT media_final as nota, data_lancamento 
                    FROM notas 
                    WHERE estudante_id = :aluno_id 
                    AND disciplina_id = :disciplina_id 
                    AND bimestre = :trimestre
                    AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':aluno_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        
        $nota = $nota_data ? (float)$nota_data['nota'] : null;
        $data_lancamento = $nota_data ? $nota_data['data_lancamento'] : null;
        
        // Para cada prova, usar a mesma média (já que a nota é por trimestre)
        foreach ($provas as $prova) {
            $aluno_notas[] = [
                'prova_id' => $prova['id'],
                'prova_titulo' => $prova['titulo'],
                'prova_tipo' => $prova['tipo_nome'] ?? $prova['tipo_prova'] ?? 'Avaliação',
                'prova_tipo_cor' => $prova['tipo_cor'] ?? '#6c757d',
                'prova_tipo_icone' => $prova['tipo_icone'] ?? 'fa-file-alt',
                'data_prova' => $prova['data_prova'],
                'valor_total' => $prova['valor_total'],
                'nota' => $nota,
                'data_lancamento' => $data_lancamento
            ];
        }
        
        $media = $nota;
        $status = getStatusMedia($media);
        
        $alunos[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'avaliacoes' => $aluno_notas,
            'media' => $media,
            'status' => $status
        ];
    }
    
    // Calcular estatísticas gerais
    $estatisticas = [
        'total_alunos' => count($alunos),
        'total_avaliacoes' => count($provas),
        'media_geral' => 0,
        'aprovados' => 0,
        'exame' => 0,
        'reprovados' => 0,
        'sem_nota' => 0
    ];
    
    $soma_medias = 0;
    $total_com_media = 0;
    
    foreach ($alunos as $aluno) {
        if ($aluno['media'] !== null && $aluno['media'] > 0) {
            $soma_medias += $aluno['media'];
            $total_com_media++;
            
            if ($aluno['media'] >= 14) {
                $estatisticas['aprovados']++;
            } elseif ($aluno['media'] >= 10) {
                $estatisticas['exame']++;
            } else {
                $estatisticas['reprovados']++;
            }
        } else {
            $estatisticas['sem_nota']++;
        }
    }
    
    $estatisticas['media_geral'] = $total_com_media > 0 ? round($soma_medias / $total_com_media, 2) : 0;
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
    <title>Avaliação por Curso | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        
        .page-header h2 {
            margin: 0 0 5px 0;
            font-size: 28px;
        }
        
        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
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
        
        .disciplina-card.active .text-muted {
            color: rgba(255,255,255,0.8) !important;
        }
        
        .table-avaliacao th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-avaliacao td {
            text-align: center;
            vertical-align: middle;
        }
        
        .nota-cell {
            font-weight: bold;
        }
        
        .btn-print, .btn-excel, .btn-ajuda {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        
        .btn-excel {
            background: #28a745;
        }
        
        .btn-ajuda {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-ajuda:hover { background: #e0a800; color: #212529; }
        .btn-excel:hover { background: #1e7e34; }
        .btn-print:hover { background: #138496; }
        
        .help-modal-step {
            background: #f8f9fa;
            border-left: 4px solid #006B3E;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        
        .step-number {
            background: #006B3E;
            color: white;
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-right: 10px;
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
            .table-avaliacao th {
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
                    <h2><i class="fas fa-chart-line"></i> Avaliação por Curso</h2>
                    <p>Visualize o desempenho dos alunos por turma, disciplina e trimestre</p>
                </div>
                <div class="no-print">
                    <button type="button" class="btn-ajuda btn me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                        <i class="fas fa-question-circle"></i> Ajuda / Tutorial
                    </button>
                    <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
                        <button onclick="exportarExcel()" class="btn-excel btn me-2">
                            <i class="fas fa-file-excel"></i> Exportar Excel
                        </button>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn-print btn">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <a href="../dashboard.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Curso / Turma</label>
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
                <div class="col-md-3">
                    <label class="form-label fw-bold">Trimestre</label>
                    <select name="trimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                        <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                        <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Estatísticas -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas['total_alunos']; ?></div>
                    <small>Total de Alunos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number aprovados"><?php echo $estatisticas['aprovados']; ?></div>
                    <small>Aprovados (≥14)</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <div class="stat-number exame"><?php echo $estatisticas['exame']; ?></div>
                    <small>Exame (10-13)</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stat-number reprovados"><?php echo $estatisticas['reprovados']; ?></div>
                    <small>Reprovados (<10)</small>
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
                    <?php 
                    $disciplina_nome = '';
                    foreach ($disciplinas_turma as $disc) {
                        if ($disc['id'] == $disciplina_id) {
                            $disciplina_nome = $disc['nome'];
                            break;
                        }
                    }
                    echo htmlspecialchars($disciplina_nome);
                    ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="fas fa-calendar-alt"></i> Trimestre:</strong><br>
                    <?php echo $trimestre; ?>º Trimestre
                </div>
            </div>
        </div>
        
        <!-- Tabela de Avaliações -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-table"></i> Quadro de Avaliações</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-avaliacao" id="tabelaAvaliacoes">
                        <thead>
                            <tr>
                                <th rowspan="2" width="5%">#</th>
                                <th rowspan="2" width="12%">Matrícula</th>
                                <th rowspan="2" width="25%">Aluno</th>
                                <th rowspan="2" width="8%">Gênero</th>
                                <?php foreach ($provas as $prova): ?>
                                <th colspan="2" width="14%">
                                    <?php if (isset($prova['tipo_cor']) && $prova['tipo_cor']): ?>
                                        <span class="badge" style="background-color: <?php echo $prova['tipo_cor']; ?>;">
                                            <i class="fas <?php echo $prova['tipo_icone'] ?? 'fa-file-alt'; ?>"></i>
                                            <?php echo htmlspecialchars($prova['titulo']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($prova['titulo']); ?></span>
                                    <?php endif; ?>
                                    <br><small class="text-white-50"><?php echo number_format($prova['valor_total'], 1, ',', '.'); ?> val</small>
                                </th>
                                <?php endforeach; ?>
                                <th rowspan="2" width="10%">Média Final</th>
                                <th rowspan="2" width="12%">Situação</th>
                            </tr>
                            <tr>
                                <?php foreach ($provas as $prova): ?>
                                <th width="8%">Nota</th>
                                <th width="8%">Status</th>
                                <?php endforeach; ?>
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
                                <?php foreach ($aluno['avaliacoes'] as $avaliacao): ?>
                                    <td class="nota-cell">
                                        <?php echo $avaliacao['nota'] !== null ? number_format($avaliacao['nota'], 1, ',', '.') : '---'; ?>
                                    </td>
                                    <td class="<?php echo getStatusMedia($avaliacao['nota'])['classe']; ?>">
                                        <i class="fas <?php echo getStatusMedia($avaliacao['nota'])['icone']; ?>"></i>
                                        <?php echo getStatusMedia($avaliacao['nota'])['texto']; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="fw-bold <?php echo $aluno['status']['classe']; ?>">
                                    <?php echo $aluno['media'] !== null ? number_format($aluno['media'], 1, ',', '.') : '---'; ?>
                                </td>
                                <td class="<?php echo $aluno['status']['classe']; ?>">
                                    <i class="fas <?php echo $aluno['status']['icone']; ?>"></i> <?php echo $aluno['status']['texto']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="<?php echo 4 + (count($provas) * 2); ?>" class="text-end fw-bold">Média da Turma:</td>
                                <td colspan="2" class="fw-bold"><?php echo number_format($estatisticas['media_geral'], 1, ',', '.'); ?> valores</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id == 0 && !empty($disciplinas_turma)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione uma disciplina para visualizar as avaliações.
            </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos)): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Nenhum aluno encontrado para esta turma/disciplina.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione um curso/turma para começar.
            </div>
        <?php endif; ?>
        
        <!-- Disciplinas da Turma (quando nenhuma disciplina selecionada) -->
        <?php if ($turma_id > 0 && $disciplina_id == 0 && !empty($disciplinas_turma)): ?>
        <div class="card mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-book"></i> Disciplinas do Curso</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($disciplinas_turma as $disc): ?>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disc['id']; ?>&trimestre=<?php echo $trimestre; ?>" 
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
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda / Tutorial -->
    <div class="modal fade no-print" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Tutorial - Avaliação por Curso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>O que é esta página?</strong><br>
                        A página "Avaliação por Curso" permite visualizar o desempenho dos alunos de uma turma em uma disciplina específica durante um trimestre.
                    </div>
                    
                    <h6 class="mt-4 mb-3"><i class="fas fa-road"></i> Passo a Passo</h6>
                    
                    <div class="help-modal-step">
                        <span class="step-number">1</span>
                        <strong>Selecione o Curso/Turma</strong>
                        <p class="mt-2 mb-0 text-muted">Escolha a turma desejada no primeiro filtro.</p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">2</span>
                        <strong>Selecione a Disciplina</strong>
                        <p class="mt-2 mb-0 text-muted">Escolha a disciplina que deseja analisar.</p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">3</span>
                        <strong>Selecione o Trimestre</strong>
                        <p class="mt-2 mb-0 text-muted">Escolha qual trimestre deseja visualizar (1º, 2º ou 3º).</p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">4</span>
                        <strong>Visualize o Quadro de Avaliações</strong>
                        <p class="mt-2 mb-0 text-muted">A tabela exibe alunos, notas e situação final.</p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">5</span>
                        <strong>Entenda os Status</strong>
                        <p class="mt-2 mb-0 text-muted">
                            <span class="text-success">● Aprovado:</span> Média ≥ 14 valores<br>
                            <span class="text-warning">● Exame:</span> Média entre 10 e 13.9 valores<br>
                            <span class="text-danger">● Reprovado:</span> Média < 10 valores<br>
                            <span class="text-secondary">● Sem nota:</span> Nenhuma nota lançada
                        </p>
                    </div>
                    
                    <div class="alert alert-success mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dica:</strong> 
                        Utilize os filtros para refinar a visualização por turma, disciplina e trimestre.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir Tutorial</button>
                </div>
            </div>
        </div>
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
        
        function exportarExcel() {
            window.location.href = 'exportar_excel_por_curso.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>';
        }
    </script>
</body>
</html>