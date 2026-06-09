<?php
// escola/aluno/academico/boletim.php - Boletim do Aluno

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Boletim Escolar';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
$turma_id = $turma['id'] ?? 0;

// Filtros
$ano_letivo = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// ==============================================
// BUSCAR ANOS LETIVOS DISPONÍVEIS
// ==============================================
$sql_anos = "SELECT DISTINCT ano_letivo FROM notas WHERE aluno_id = :aluno_id AND escola_id = :escola_id ORDER BY ano_letivo DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// BUSCAR DISCIPLINAS DO ALUNO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo, d.carga_horaria
                    FROM disciplinas d
                    JOIN disciplina_turma dt ON dt.disciplina_id = d.id
                    WHERE dt.turma_id = :turma_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR NOTAS DO ALUNO
// ==============================================
$sql_notas = "SELECT 
                    n.id,
                    n.disciplina_id,
                    n.bimestre,
                    n.nota,
                    n.nota_recuperacao,
                    n.faltas,
                    n.ano_letivo,
                    n.media_final,
                    n.situacao,
                    n.observacoes,
                    d.nome as disciplina_nome,
                    d.codigo as disciplina_codigo,
                    d.carga_horaria
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.aluno_id = :aluno_id 
              AND n.escola_id = :escola_id
              AND n.ano_letivo = :ano";

if ($bimestre_filtro > 0) {
    $sql_notas .= " AND n.bimestre = :bimestre";
}

$sql_notas .= " ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':ano' => $ano_letivo
];
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_notas->execute($params);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// PROCESSAR NOTAS POR DISCIPLINA E BIMESTRE
// ==============================================
$boletim = [];
foreach ($disciplinas as $disciplina) {
    $boletim[$disciplina['id']] = [
        'disciplina_nome' => $disciplina['nome'],
        'disciplina_codigo' => $disciplina['codigo'],
        'carga_horaria' => $disciplina['carga_horaria'],
        'bimestres' => [
            1 => ['nota' => null, 'faltas' => null, 'situacao' => null],
            2 => ['nota' => null, 'faltas' => null, 'situacao' => null],
            3 => ['nota' => null, 'faltas' => null, 'situacao' => null],
            4 => ['nota' => null, 'faltas' => null, 'situacao' => null]
        ],
        'nota_recuperacao' => null,
        'media_final' => null,
        'situacao_final' => null
    ];
}

foreach ($notas as $nota) {
    $disc_id = $nota['disciplina_id'];
    $bimestre = $nota['bimestre'];
    
    if (isset($boletim[$disc_id])) {
        $boletim[$disc_id]['bimestres'][$bimestre] = [
            'nota' => $nota['nota'],
            'faltas' => $nota['faltas'],
            'situacao' => $nota['situacao']
        ];
        $boletim[$disc_id]['nota_recuperacao'] = $nota['nota_recuperacao'];
        $boletim[$disc_id]['media_final'] = $nota['media_final'];
        $boletim[$disc_id]['situacao_final'] = $nota['situacao'];
    }
}

// ==============================================
// CALCULAR MÉDIAS GERAIS
// ==============================================
$medias_bimestres = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$total_disciplinas = count($disciplinas);
$total_aprovado = 0;
$total_reprovado = 0;
$total_recuperacao = 0;

foreach ($boletim as $disc_id => $dados) {
    for ($b = 1; $b <= 4; $b++) {
        if ($dados['bimestres'][$b]['nota'] !== null) {
            $medias_bimestres[$b] += $dados['bimestres'][$b]['nota'];
        }
    }
    
    if ($dados['situacao_final'] == 'aprovado') {
        $total_aprovado++;
    } elseif ($dados['situacao_final'] == 'reprovado') {
        $total_reprovado++;
    } elseif ($dados['situacao_final'] == 'recuperacao') {
        $total_recuperacao++;
    }
}

for ($b = 1; $b <= 4; $b++) {
    $medias_bimestres[$b] = $total_disciplinas > 0 ? round($medias_bimestres[$b] / $total_disciplinas, 1) : 0;
}

$media_geral = array_sum($medias_bimestres) / 4;
$taxa_aprovacao = $total_disciplinas > 0 ? round(($total_aprovado / $total_disciplinas) * 100, 1) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getSituacaoBadge($situacao) {
    switch ($situacao) {
        case 'aprovado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Recuperação</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}

function getCorNota($nota) {
    if ($nota === null) return 'text-secondary';
    if ($nota >= 14) return 'text-success fw-bold';
    if ($nota >= 10) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

function getCorMedia($media) {
    if ($media >= 14) return '#28a745';
    if ($media >= 10) return '#ffc107';
    return '#dc3545';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
        }
        
        .boletim-table {
            width: 100%;
            border-collapse: collapse;
        }
        .boletim-table th, .boletim-table td {
            padding: 12px;
            text-align: center;
            border: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        .boletim-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .boletim-table tr:hover {
            background: #f5f5f5;
        }
        
        .media-geral-card {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .nota-bimestre {
            font-size: 1.1em;
            font-weight: bold;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno { display: none; }
        }
        
        .legenda {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
 <?php include '../includes/menu_aluno.php'; ?>
   
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Boletim Escolar</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe seu boletim escolar com notas por bimestre e situação final.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Legenda das Cores</div>
                <div class="ajuda-texto">
                    <span class="legenda" style="background: #28a745;"></span> Nota ≥ 14 (Excelente)<br>
                    <span class="legenda" style="background: #ffc107;"></span> Nota entre 10 e 13 (Regular)<br>
                    <span class="legenda" style="background: #dc3545;"></span> Nota &lt; 10 (Insuficiente)
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Situação</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Aprovado</span> - Média final ≥ 10<br>
                    <span class="badge bg-danger">Reprovado</span> - Média final &lt; 10<br>
                    <span class="badge bg-warning">Recuperação</span> - Aluno em exame final
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Filtre por ano letivo e bimestre para visualizar períodos específicos.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-bar"></i> Boletim Escolar</h4>
            <p class="text-muted mb-0">Visualize suas notas e desempenho por bimestre</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-calendar"></i> Ano Letivo</small>
                    <h6 class="mb-0"><?php echo $ano_letivo; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Resumo -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_aprovado; ?></div>
                <div class="stat-label">Disciplinas Aprovadas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_reprovado; ?></div>
                <div class="stat-label">Disciplinas Reprovadas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Em Recuperação</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $taxa_aprovacao; ?>%</div>
                <div class="stat-label">Taxa de Aprovação</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_letivo == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os bimestres</option>
                        <option value="1" <?php echo $bimestre_filtro == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre_filtro == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre_filtro == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="4" <?php echo $bimestre_filtro == 4 ? 'selected' : ''; ?>>4º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <a href="boletim.php" class="btn btn-outline-secondary"><i class="fas fa-eraser"></i> Limpar Filtros</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tabela do Boletim -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Boletim - <?php echo $ano_letivo; ?>
            <?php if ($bimestre_filtro > 0): ?>
            <span class="badge bg-info ms-2"><?php echo $bimestre_filtro; ?>º Bimestre</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma disciplina encontrada para sua turma.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="boletim-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Disciplina</th>
                                <?php if ($bimestre_filtro == 0): ?>
                                <th colspan="4" class="text-center">Notas por Bimestre</th>
                                <th rowspan="2">Recuperação</th>
                                <th rowspan="2">Média Final</th>
                                <th rowspan="2">Situação</th>
                                <?php else: ?>
                                <th>Nota</th>
                                <th>Faltas</th>
                                <th>Situação</th>
                                <?php endif; ?>
                            </tr>
                            <?php if ($bimestre_filtro == 0): ?>
                            <tr class="text-center">
                                <th>1º Bim</th>
                                <th>2º Bim</th>
                                <th>3º Bim</th>
                                <th>4º Bim</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($boletim as $disc_id => $dados): ?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo htmlspecialchars($dados['disciplina_nome']); ?></td>
                                
                                <?php if ($bimestre_filtro == 0): ?>
                                    <!-- Todos os bimestres -->
                                    <?php for ($b = 1; $b <= 4; $b++): 
                                        $nota = $dados['bimestres'][$b]['nota'];
                                        $cor_nota = getCorNota($nota);
                                    ?>
                                    <td class="text-center <?php echo $cor_nota; ?>">
                                        <?php echo $nota !== null ? number_format($nota, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <?php endfor; ?>
                                    
                                    <td class="text-center">
                                        <?php echo $dados['nota_recuperacao'] !== null ? number_format($dados['nota_recuperacao'], 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center fw-bold" style="color: <?php echo getCorMedia($dados['media_final'] ?? 0); ?>">
                                        <?php echo $dados['media_final'] !== null ? number_format($dados['media_final'], 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getSituacaoBadge($dados['situacao_final']); ?>
                                    </td>
                                    
                                <?php else: ?>
                                    <!-- Bimestre específico -->
                                    <?php 
                                        $nota = $dados['bimestres'][$bimestre_filtro]['nota'] ?? null;
                                        $faltas = $dados['bimestres'][$bimestre_filtro]['faltas'] ?? null;
                                        $situacao = $dados['bimestres'][$bimestre_filtro]['situacao'] ?? 'pendente';
                                        $cor_nota = getCorNota($nota);
                                    ?>
                                    <td class="text-center <?php echo $cor_nota; ?>">
                                        <?php echo $nota !== null ? number_format($nota, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $faltas !== null ? $faltas : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getSituacaoBadge($situacao); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($bimestre_filtro == 0): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <td class="text-end fw-bold">Média da Turma</td>
                                <?php for ($b = 1; $b <= 4; $b++): ?>
                                <td class="text-center fw-bold"><?php echo number_format($medias_bimestres[$b], 1, ',', '.'); ?></td>
                                <?php endfor; ?>
                                <td class="text-center">-</td>
                                <td class="text-center fw-bold" style="color: <?php echo getCorMedia($media_geral); ?>">
                                    <?php echo number_format($media_geral, 1, ',', '.'); ?>
                                </td>
                                <td class="text-center">-</td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Gráfico de Desempenho -->
    <?php if ($bimestre_filtro == 0 && !empty($disciplinas)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-line"></i> Gráfico de Desempenho por Disciplina</div>
        <div class="card-body">
            <canvas id="graficoDesempenho" height="300"></canvas>
        </div>
    </div>
    
    <script>
        // Gráfico de desempenho
        const disciplinasNomes = <?php 
            $nomes = [];
            foreach ($boletim as $dados) {
                $nomes[] = "'" . addslashes($dados['disciplina_nome']) . "'";
            }
            echo '[' . implode(', ', $nomes) . ']';
        ?>;
        
        const mediasFinais = <?php 
            $medias = [];
            foreach ($boletim as $dados) {
                $medias[] = $dados['media_final'] ?? 0;
            }
            echo '[' . implode(', ', $medias) . ']';
        ?>;
        
        const cores = mediasFinais.map(m => {
            if (m >= 14) return '#28a745';
            if (m >= 10) return '#ffc107';
            return '#dc3545';
        });
        
        new Chart(document.getElementById('graficoDesempenho'), {
            type: 'bar',
            data: {
                labels: disciplinasNomes,
                datasets: [{
                    label: 'Média Final',
                    data: mediasFinais,
                    backgroundColor: cores,
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 20,
                        ticks: {
                            callback: function(value) {
                                return value;
                            }
                        },
                        title: {
                            display: true,
                            text: 'Nota'
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: false,
                            rotation: 45,
                            font: { size: 10 }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Média: ' + context.raw.toFixed(1);
                            }
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-info-circle"></i> Legenda</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #28a745;"></div>
                        <span><strong>Excelente:</strong> Nota ≥ 14</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #ffc107;"></div>
                        <span><strong>Regular:</strong> Nota entre 10 e 13</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #dc3545;"></div>
                        <span><strong>Insuficiente:</strong> Nota &lt; 10</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-info-circle text-info"></i>
                        <span><strong>Média de Aprovação:</strong> 10 pontos</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
</script>
</body>
</html>