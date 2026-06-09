<?php
// escola/aluno/academico/frequencia.php - Frequência do Aluno

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
$titulo_pagina = 'Minha Frequência';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$ano_letivo = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;

// Buscar anos letivos disponíveis
$sql_anos = "SELECT DISTINCT ano_letivo FROM frequencias WHERE estudante_id = :aluno_id ORDER BY ano_letivo DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM frequencias f
                    JOIN disciplinas d ON d.id = f.disciplina_id
                    WHERE f.estudante_id = :aluno_id AND f.ano_letivo = :ano
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id, ':ano' => $ano_letivo]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar frequências do aluno
$sql_frequencias = "SELECT f.*, d.nome as disciplina
                    FROM frequencias f
                    JOIN disciplinas d ON d.id = f.disciplina_id
                    WHERE f.estudante_id = :aluno_id AND f.ano_letivo = :ano";
if ($disciplina_filtro > 0) {
    $sql_frequencias .= " AND f.disciplina_id = :disciplina_id";
}
$sql_frequencias .= " ORDER BY d.nome ASC, f.mes ASC, f.bimestre ASC";

$stmt_frequencias = $conn->prepare($sql_frequencias);
$params = [':aluno_id' => $aluno_id, ':ano' => $ano_letivo];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
$stmt_frequencias->execute($params);
$frequencias = $stmt_frequencias->fetchAll(PDO::FETCH_ASSOC);

// Agrupar frequências por disciplina
$frequencias_por_disciplina = [];
foreach ($frequencias as $freq) {
    $disciplina = $freq['disciplina'];
    if (!isset($frequencias_por_disciplina[$disciplina])) {
        $frequencias_por_disciplina[$disciplina] = [
            'meses' => [],
            'total_presencas' => 0,
            'total_faltas' => 0,
            'total_aulas' => 0,
            'percentual' => 0
        ];
    }
    $frequencias_por_disciplina[$disciplina]['meses'][$freq['mes']] = [
        'presencas' => $freq['presencas'],
        'faltas' => $freq['faltas'],
        'total_aulas' => $freq['total_aulas'],
        'percentual' => $freq['total_aulas'] > 0 ? ($freq['presencas'] / $freq['total_aulas']) * 100 : 0
    ];
    $frequencias_por_disciplina[$disciplina]['total_presencas'] += $freq['presencas'];
    $frequencias_por_disciplina[$disciplina]['total_faltas'] += $freq['faltas'];
    $frequencias_por_disciplina[$disciplina]['total_aulas'] += $freq['total_aulas'];
}

// Calcular percentuais
foreach ($frequencias_por_disciplina as $disciplina => $dados) {
    $total = $dados['total_presencas'] + $dados['total_faltas'];
    if ($total > 0) {
        $frequencias_por_disciplina[$disciplina]['percentual'] = ($dados['total_presencas'] / $total) * 100;
    }
}

// Estatísticas gerais
$total_presencas_geral = 0;
$total_faltas_geral = 0;
$total_aulas_geral = 0;

foreach ($frequencias_por_disciplina as $dados) {
    $total_presencas_geral += $dados['total_presencas'];
    $total_faltas_geral += $dados['total_faltas'];
    $total_aulas_geral += $dados['total_aulas'];
}

$percentual_geral = $total_aulas_geral > 0 ? ($total_presencas_geral / $total_aulas_geral) * 100 : 0;

// Funções auxiliares
function getStatusFrequencia($percentual) {
    if ($percentual >= 75) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Regular</span>';
    } elseif ($percentual >= 50) {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Atenção</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Irregular</span>';
    }
}

function getCorPercentual($percentual) {
    if ($percentual >= 75) return '#28a745';
    if ($percentual >= 50) return '#ffc107';
    return '#dc3545';
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}

include '../includes/menu_aluno.php';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
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
        
        .table-frequencia th, .table-frequencia td {
            vertical-align: middle;
            text-align: center;
        }
        
        .table-frequencia tr:hover {
            background: #f8f9fa;
        }
        
        .progress-bar-bom { background: #28a745; }
        .progress-bar-atencao { background: #ffc107; }
        .progress-bar-ruim { background: #dc3545; }
        
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
    </style>
</head>
<body>

<!-- Botão de Ajuda Flutuante -->
<button class="btn-ajuda" id="btnAjuda">
    <i class="fas fa-question fa-lg"></i>
</button>

<!-- Modal de Ajuda -->
<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Minha Frequência</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">
                    Esta página exibe o registro de sua frequência escolar, mostrando presenças e faltas por disciplina.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Classificação da Frequência</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Regular</span> - Percentual ≥ 75%<br>
                    <span class="badge bg-warning text-dark">Atenção</span> - Percentual entre 50% e 74%<br>
                    <span class="badge bg-danger">Irregular</span> - Percentual &lt; 50%
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Filtros</div>
                <div class="ajuda-texto">
                    Utilize os filtros de ano letivo e disciplina para visualizar períodos específicos.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Dicas</div>
                <div class="ajuda-texto">
                    • Mantenha sua frequência acima de 75% para não ter problemas de aprovação<br>
                    • A frequência é registrada diariamente pelos professores<br>
                    • Em caso de dúvidas, contacte a secretaria da escola
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-calendar-check"></i> Minha Frequência</h4>
            <p class="text-muted mb-0">Acompanhe seu registro de presenças e faltas</p>
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
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome']); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno['matricula']; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-chart-line"></i> Percentual</small>
                    <h6 class="mb-0"><?php echo number_format($percentual_geral, 1, ',', '.'); ?>%</h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-filter"></i> Filtros
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano" class="form-select">
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_letivo == $ano ? 'selected' : ''; ?>>
                            <?php echo $ano; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_presencas_geral; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Total de Presenças</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_faltas_geral; ?></div>
                <div class="stat-label"><i class="fas fa-times-circle text-danger"></i> Total de Faltas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_aulas_geral; ?></div>
                <div class="stat-label"><i class="fas fa-chalkboard-user"></i> Total de Aulas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value <?php echo $percentual_geral >= 75 ? 'text-success' : ($percentual_geral >= 50 ? 'text-warning' : 'text-danger'); ?>">
                    <?php echo number_format($percentual_geral, 1, ',', '.'); ?>%
                </div>
                <div class="stat-label"><i class="fas fa-chart-line"></i> Percentual Geral</div>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Frequência por Disciplina -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Frequência por Disciplina - <?php echo $ano_letivo; ?>
            <?php if ($disciplina_filtro > 0): ?>
            <span class="badge bg-primary ms-2">Filtrado por disciplina</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($frequencias_por_disciplina)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhum registro de frequência encontrado para o período selecionado.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-frequencia">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="vertical-align: middle;">Disciplina</th>
                                <th colspan="3" class="text-center">Janeiro</th>
                                <th colspan="3" class="text-center">Fevereiro</th>
                                <th colspan="3" class="text-center">Março</th>
                                <th colspan="3" class="text-center">Abril</th>
                                <th colspan="3" class="text-center">Maio</th>
                                <th colspan="3" class="text-center">Junho</th>
                                <th colspan="3" class="text-center">Julho</th>
                                <th colspan="3" class="text-center">Agosto</th>
                                <th colspan="3" class="text-center">Setembro</th>
                                <th colspan="3" class="text-center">Outubro</th>
                                <th colspan="3" class="text-center">Novembro</th>
                                <th colspan="3" class="text-center">Dezembro</th>
                                <th rowspan="2" class="text-center">Total<br>Presenças</th>
                                <th rowspan="2" class="text-center">Total<br>Faltas</th>
                                <th rowspan="2" class="text-center">%</th>
                                <th rowspan="2" class="text-center">Status</th>
                            </tr>
                            <tr class="table-dark">
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                                <th>P</th><th>F</th><th>%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frequencias_por_disciplina as $disciplina => $dados): 
                                $cor_percentual = getCorPercentual($dados['percentual']);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($disciplina); ?></strong></td>
                                
                                <!-- Meses 1 a 12 -->
                                <?php for ($mes = 1; $mes <= 12; $mes++): 
                                    $mes_dado = isset($dados['meses'][$mes]) ? $dados['meses'][$mes] : null;
                                    $presencas = $mes_dado ? $mes_dado['presencas'] : '-';
                                    $faltas = $mes_dado ? $mes_dado['faltas'] : '-';
                                    $percentual_mes = $mes_dado ? number_format($mes_dado['percentual'], 0) : '-';
                                ?>
                                <td class="text-center"><?php echo $presencas; ?></td>
                                <td class="text-center"><?php echo $faltas; ?></td>
                                <td class="text-center"><?php echo $percentual_mes; ?>%</small><?php echo ''; ?></td>
                                <?php endfor; ?>
                                
                                <!-- Totais -->
                                <td class="text-center fw-bold text-success"><?php echo $dados['total_presencas']; ?></td>
                                <td class="text-center fw-bold text-danger"><?php echo $dados['total_faltas']; ?></td>
                                <td class="text-center fw-bold" style="color: <?php echo $cor_percentual; ?>;">
                                    <?php echo number_format($dados['percentual'], 1, ',', '.'); ?>%
                                </td>
                                <td class="text-center"><?php echo getStatusFrequencia($dados['percentual']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Gráfico de Frequência por Disciplina -->
    <?php if (!empty($frequencias_por_disciplina)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-chart-bar"></i> Gráfico de Frequência por Disciplina
        </div>
        <div class="card-body">
            <canvas id="graficoFrequencia" height="250"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-info-circle"></i> Legenda
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Regular:</strong> Frequência ≥ 75%</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning text-dark">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Atenção:</strong> Frequência entre 50% e 74%</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Irregular:</strong> Frequência &lt; 50%</span>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-12">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> P = Presenças | F = Faltas | % = Percentual de presença
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() {
        modalAjuda.classList.add('show');
    });
    
    closeAjuda.addEventListener('click', function() {
        modalAjuda.classList.remove('show');
    });
    
    modalAjuda.addEventListener('click', function(e) {
        if (e.target === modalAjuda) {
            modalAjuda.classList.remove('show');
        }
    });
    
    // Gráfico de Frequência
    const disciplinas = <?php echo json_encode(array_keys($frequencias_por_disciplina)); ?>;
    const percentuais = <?php echo json_encode(array_column($frequencias_por_disciplina, 'percentual')); ?>;
    const cores = percentuais.map(p => {
        if (p >= 75) return '#28a745';
        if (p >= 50) return '#ffc107';
        return '#dc3545';
    });
    
    new Chart(document.getElementById('graficoFrequencia'), {
        type: 'bar',
        data: {
            labels: disciplinas,
            datasets: [{
                label: 'Percentual de Frequência (%)',
                data: percentuais,
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
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    },
                    title: {
                        display: true,
                        text: 'Percentual (%)'
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
                            return 'Frequência: ' + context.raw.toFixed(1) + '%';
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

</body>
</html>