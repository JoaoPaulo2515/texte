<?php
// escola/aluno/academico/frequencia.php - Frequência do Aluno usando tabela chamada

require_once __DIR__ . '/../../config/database.php';
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
$turma_id = $turma['id'] ?? 0;

// ==============================================
// FILTROS - Carregar ano atual por padrão
// ==============================================
$ano_letivoselecinado = date('Y');
$ano_atual = date('Y');
$ano_letivo = isset($_GET['ano']) ? (int)$_GET['ano'] : $ano_atual;
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// ==============================================
// BUSCAR ANOS LETIVOS DA TABELA ano_letivo
// ==============================================
$sql_anos = "SELECT id, ano, ativo FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

$anos_disponiveis = [];
foreach ($anos_letivos as $ano_item) {
    $anos_disponiveis[] = $ano_item['ano'];
}

// Se não encontrar na tabela ano_letivo, busca na periodo_letivo
if (empty($anos_disponiveis)) {
    $sql_anos = "SELECT DISTINCT ano_letivo FROM periodo_letivo WHERE escola_id = :escola_id ORDER BY ano_letivo DESC";
    $stmt_anos = $conn->prepare($sql_anos);
    $stmt_anos->execute([':escola_id' => $escola_id]);
    $anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Se ainda estiver vazio, busca na chamada
if (empty($anos_disponiveis)) {
    $sql_anos = "SELECT DISTINCT ano_letivo_id FROM chamada WHERE escola_id = :escola_id ORDER BY ano_letivo_id DESC";
    $stmt_anos = $conn->prepare($sql_anos);
    $stmt_anos->execute([':escola_id' => $escola_id]);
    $anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);
}

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [$ano_atual];
}

// Garantir que o ano atual está na lista
if (!in_array($ano_atual, $anos_disponiveis)) {
    array_unshift($anos_disponiveis, $ano_atual);
}

// Ordenar para ano atual primeiro
usort($anos_disponiveis, function($a, $b) use ($ano_atual) {
    if ($a == $ano_atual) return -1;
    if ($b == $ano_atual) return 1;
    return $b - $a;
});

// Buscar TODAS as disciplinas da escola
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    WHERE d.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR FREQUÊNCIAS
// ==============================================
$sql_frequencias = "SELECT 
                        c.id,
                        c.data_aula,
                        c.status,
                        c.minutos_atraso,
                        c.justificativa,
                        c.bimestre,
                        d.id as disciplina_id,
                        d.nome as disciplina_nome,
                        al.ano as ano,
                        MONTH(c.data_aula) as mes,
                        YEAR(c.data_aula) as ano
                    FROM chamada c
                    INNER JOIN disciplinas d ON d.id = c.disciplina_id
                    INNER JOIN ano_letivo al ON al.id = c.ano_letivo_id
               WHERE c.estudante_id = :aluno_id 
                    AND c.escola_id = :escola_id 
                    AND c.ano_letivo_id = :ano";

if ($disciplina_filtro > 0) {
    $sql_frequencias .= " AND c.disciplina_id = :disciplina_id";
}
if ($bimestre_filtro > 0) {
    $sql_frequencias .= " AND c.bimestre = :bimestre";
}

$sql_frequencias .= " ORDER BY d.nome ASC, c.data_aula ASC";

$stmt_frequencias = $conn->prepare($sql_frequencias);
$params = [
    ':aluno_id' => $aluno_id, 
    ':escola_id' => $escola_id, 
    ':ano' => $ano_letivo
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_frequencias->execute($params);
$chamadas = $stmt_frequencias->fetchAll(PDO::FETCH_ASSOC);

// Processar os dados: agrupar por disciplina, mês e bimestre
$frequencias_por_disciplina = [];

foreach ($chamadas as $chamada) {
    $disciplina = $chamada['disciplina_nome'];
    $mes = (int)$chamada['mes'];
    $bimestre = $chamada['bimestre'];
    $status = $chamada['status'];
    $ano_letivoselecinado = $chamada['ano'];
    
    // Inicializar estrutura
    if (!isset($frequencias_por_disciplina[$disciplina])) {
        $frequencias_por_disciplina[$disciplina] = [
            'disciplina_id' => $chamada['disciplina_id'],
            'meses' => [],
            'bimestres' => [],
            'total_presencas' => 0,
            'total_ausencias' => 0,
            'total_justificados' => 0,
            'total_atrasos' => 0,
            'total_registros' => 0
        ];
    }
    
    // Contar por mês
    if (!isset($frequencias_por_disciplina[$disciplina]['meses'][$mes])) {
        $frequencias_por_disciplina[$disciplina]['meses'][$mes] = [
            'presente' => 0,
            'ausente' => 0,
            'justificado' => 0,
            'atrasado' => 0,
            'total' => 0
        ];
    }
    
    // Contar por bimestre
    if (!isset($frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre])) {
        $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre] = [
            'presente' => 0,
            'ausente' => 0,
            'justificado' => 0,
            'atrasado' => 0,
            'total' => 0
        ];
    }
    
    // Incrementar contadores
    switch ($status) {
        case 'presente':
            $frequencias_por_disciplina[$disciplina]['meses'][$mes]['presente']++;
            $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['presente']++;
            $frequencias_por_disciplina[$disciplina]['total_presencas']++;
            break;
        case 'ausente':
            $frequencias_por_disciplina[$disciplina]['meses'][$mes]['ausente']++;
            $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['ausente']++;
            $frequencias_por_disciplina[$disciplina]['total_ausencias']++;
            break;
        case 'justificado':
            $frequencias_por_disciplina[$disciplina]['meses'][$mes]['justificado']++;
            $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['justificado']++;
            $frequencias_por_disciplina[$disciplina]['total_justificados']++;
            break;
        case 'atrasado':
            $frequencias_por_disciplina[$disciplina]['meses'][$mes]['atrasado']++;
            $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['atrasado']++;
            $frequencias_por_disciplina[$disciplina]['total_atrasos']++;
            break;
    }
    
    $frequencias_por_disciplina[$disciplina]['meses'][$mes]['total']++;
    $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['total']++;
    $frequencias_por_disciplina[$disciplina]['total_registros']++;
}

// Calcular percentuais
foreach ($frequencias_por_disciplina as $disciplina => $dados) {
    foreach ($dados['meses'] as $mes => $contagens) {
        $total = $contagens['presente'] + $contagens['ausente'] + $contagens['justificado'];
        $frequencias_por_disciplina[$disciplina]['meses'][$mes]['percentual'] = $total > 0 ? ($contagens['presente'] / $total) * 100 : 0;
    }
    
    foreach ($dados['bimestres'] as $bimestre => $contagens) {
        $total = $contagens['presente'] + $contagens['ausente'] + $contagens['justificado'];
        $frequencias_por_disciplina[$disciplina]['bimestres'][$bimestre]['percentual'] = $total > 0 ? ($contagens['presente'] / $total) * 100 : 0;
    }
    
    $total_aulas = $dados['total_presencas'] + $dados['total_ausencias'] + $dados['total_justificados'];
    $frequencias_por_disciplina[$disciplina]['percentual_geral'] = $total_aulas > 0 ? ($dados['total_presencas'] / $total_aulas) * 100 : 0;
}

// Estatísticas gerais
$total_presencas_geral = 0;
$total_ausencias_geral = 0;
$total_justificados_geral = 0;
$total_atrasos_geral = 0;
$total_aulas_geral = 0;

foreach ($frequencias_por_disciplina as $dados) {
    $total_presencas_geral += $dados['total_presencas'];
    $total_ausencias_geral += $dados['total_ausencias'];
    $total_justificados_geral += $dados['total_justificados'];
    $total_atrasos_geral += $dados['total_atrasos'];
    $total_aulas_geral += $dados['total_registros'];
}

$percentual_geral = $total_aulas_geral > 0 ? ($total_presencas_geral / $total_aulas_geral) * 100 : 0;

$bimestres_disponiveis = [1, 2, 3];

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


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card { transition: transform 0.2s, box-shadow 0.2s; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .table-frequencia th, .table-frequencia td { vertical-align: middle; text-align: center; }
        .table-frequencia tr:hover { background: #f8f9fa; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        .btn-ajuda { position: fixed; bottom: 80px; right: 30px; width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .modal-ajuda { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .modal-ajuda.show { display: flex; }
        .modal-ajuda-content { background: white; border-radius: 20px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; animation: fadeInUp 0.3s ease; }
        .modal-ajuda-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 15px 20px; border-radius: 20px 20px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-ajuda-body { padding: 20px; }
        .modal-ajuda-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; }
        .ajuda-item { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .ajuda-item:last-child { border-bottom: none; }
        .ajuda-titulo { font-weight: bold; color: #006B3E; margin-bottom: 8px; }
        .ajuda-texto { color: #666; font-size: 0.9rem; line-height: 1.4; }
        .ajuda-badge { display: inline-block; width: 30px; height: 30px; background: #e8f5e9; border-radius: 8px; text-align: center; line-height: 30px; margin-right: 10px; color: #006B3E; font-weight: bold; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .badge-atrasado { background-color: #ff9800; color: white; }
        .badge-justificado { background-color: #2196f3; color: white; }
    </style>
</head>
<body>
<?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Minha Frequência</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe o registro de sua frequência escolar, mostrando presenças, faltas, justificativas e atrasos por disciplina.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Classificação da Frequência</div>
                <div class="ajuda-texto"><span class="badge bg-success">Regular</span> - Percentual ≥ 75%<br><span class="badge bg-warning text-dark">Atenção</span> - Percentual entre 50% e 74%<br><span class="badge bg-danger">Irregular</span> - Percentual &lt; 50%</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Status de Chamada</div>
                <div class="ajuda-texto"><span class="badge bg-success">Presente</span> - Aluno compareceu à aula<br><span class="badge bg-danger">Ausente</span> - Aluno não compareceu<br><span class="badge badge-justificado">Justificado</span> - Falta justificada<br><span class="badge badge-atrasado">Atrasado</span> - Aluno chegou atrasado</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Utilize os filtros de ano letivo, bimestre e disciplina para visualizar períodos específicos.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-calendar-check"></i> Minha Frequência</h4>
            <p class="text-muted mb-0">Acompanhe seu registro de presenças e faltas</p>
        </div>
        <div><button class="btn btn-secondary" onclick="window.print();"><i class="fas fa-print"></i> Imprimir</button></div>
    </div>
    
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4"><small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small><h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome'] ?? $aluno_nome); ?></h6></div>
                <div class="col-md-3"><small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small><h6 class="mb-0"><?php echo htmlspecialchars($aluno['matricula'] ?? $aluno_matricula); ?></h6></div>
                <div class="col-md-3"><small class="text-muted"><i class="fas fa-users"></i> Turma</small><h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6></div>
                <div class="col-md-2"><small class="text-muted"><i class="fas fa-chart-line"></i> Percentual</small><h6 class="mb-0 <?php echo $percentual_geral >= 75 ? 'text-success' : ($percentual_geral >= 50 ? 'text-warning' : 'text-danger'); ?>"><?php echo number_format($percentual_geral, 1, ',', '.'); ?>%</h6></div>
            </div>
        </div>
    </div>
    
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano["id"]; ?>" <?php echo $ano["id"] ? 'selected' : ''; ?>><?php echo $ano["ano"]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os bimestres</option>
                        <?php foreach ($bimestres_disponiveis as $bim): ?>
                        <option value="<?php echo $bim; ?>" <?php echo $bimestre_filtro == $bim ? 'selected' : ''; ?>><?php echo $bim; ?>º Bimestre</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($disc['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end"><a href="frequencia.php" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser"></i> Limpar</a></div>
            </form>
        </div>
    </div>
    
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3"><div class="stat-card"><div class="stat-value text-success"><?php echo $total_presencas_geral; ?></div><div class="stat-label"><i class="fas fa-check-circle text-success"></i> Presenças</div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-value text-danger"><?php echo $total_ausencias_geral; ?></div><div class="stat-label"><i class="fas fa-times-circle text-danger"></i> Faltas</div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-value text-info"><?php echo $total_justificados_geral; ?></div><div class="stat-label"><i class="fas fa-file-alt text-info"></i> Justificadas</div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-value text-warning"><?php echo $total_atrasos_geral; ?></div><div class="stat-label"><i class="fas fa-clock text-warning"></i> Atrasos</div></div></div>
    </div>
    
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-table"></i> Frequência por Disciplina - <?php echo $ano_letivoselecinado; ?>
            <?php if ($bimestre_filtro > 0): ?><span class="badge bg-info ms-2"><?php echo $bimestre_filtro; ?>º Bimestre</span><?php endif; ?>
            <?php if ($disciplina_filtro > 0): ?><span class="badge bg-primary ms-2">Disciplina filtrada</span><?php endif; ?>
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
                            <tr><th rowspan="2" style="vertical-align: middle;">Disciplina</th>
                                <?php for ($mes = 1; $mes <= 12; $mes++): ?>
                                <th colspan="4" class="text-center"><?php echo getMesNome($mes); ?></th>
                                <?php endfor; ?>
                                <th rowspan="2" class="text-center">Total<br>Presenças</th><th rowspan="2" class="text-center">Total<br>Faltas</th><th rowspan="2" class="text-center">Justif.</th><th rowspan="2" class="text-center">Atrasos</th><th rowspan="2" class="text-center">%</th><th rowspan="2" class="text-center">Status</th>
                            </tr>
                            <tr class="table-dark"><?php for ($mes = 1; $mes <= 12; $mes++): ?><th>P</th><th>F</th><th>J</th><th>A</th><?php endfor; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frequencias_por_disciplina as $disciplina => $dados): $cor_percentual = getCorPercentual($dados['percentual_geral']); ?>
                            <tr><td><strong><?php echo htmlspecialchars($disciplina); ?></strong></td>
                                <?php for ($mes = 1; $mes <= 12; $mes++): $mes_dado = isset($dados['meses'][$mes]) ? $dados['meses'][$mes] : null; ?>
                                <td class="text-center"><?php echo $mes_dado ? $mes_dado['presente'] : '-'; ?></td>
                                <td class="text-center"><?php echo $mes_dado ? $mes_dado['ausente'] : '-'; ?></td>
                                <td class="text-center"><?php echo $mes_dado ? $mes_dado['justificado'] : '-'; ?></td>
                                <td class="text-center"><?php echo $mes_dado ? $mes_dado['atrasado'] : '-'; ?></td>
                                <?php endfor; ?>
                                <td class="text-center fw-bold text-success"><?php echo $dados['total_presencas']; ?></td>
                                <td class="text-center fw-bold text-danger"><?php echo $dados['total_ausencias']; ?></td>
                                <td class="text-center fw-bold text-info"><?php echo $dados['total_justificados']; ?></td>
                                <td class="text-center fw-bold text-warning"><?php echo $dados['total_atrasos']; ?></td>
                                <td class="text-center fw-bold" style="color: <?php echo $cor_percentual; ?>;"><?php echo number_format($dados['percentual_geral'], 1, ',', '.'); ?>%</td>
                                <td class="text-center"><?php echo getStatusFrequencia($dados['percentual_geral']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($frequencias_por_disciplina) && $bimestre_filtro == 0): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-pie"></i> Resumo por Bimestre (1º, 2º e 3º)</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr><th>Disciplina</th><?php for ($bim = 1; $bim <= 3; $bim++): ?><th colspan="4" class="text-center"><?php echo $bim; ?>º Bimestre</th><?php endfor; ?></tr>
                        <tr class="table-light"><th></th><?php for ($bim = 1; $bim <= 3; $bim++): ?><th>P</th><th>F</th><th>%</th><th>Status</th><?php endfor; ?> </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($frequencias_por_disciplina as $disciplina => $dados): ?>
                        <tr><td><strong><?php echo htmlspecialchars($disciplina); ?></strong></td>
                            <?php for ($bim = 1; $bim <= 3; $bim++): $bim_dado = isset($dados['bimestres'][$bim]) ? $dados['bimestres'][$bim] : null; ?>
                            <td class="text-center"><?php echo $bim_dado ? $bim_dado['presente'] : 0; ?></td>
                            <td class="text-center"><?php echo $bim_dado ? $bim_dado['ausente'] : 0; ?></td>
                            <td class="text-center <?php echo ($bim_dado['percentual'] ?? 0) >= 75 ? 'text-success' : (($bim_dado['percentual'] ?? 0) >= 50 ? 'text-warning' : 'text-danger'); ?>"><?php echo number_format($bim_dado['percentual'] ?? 0, 1, ',', '.'); ?>%</td>
                            <td class="text-center"><?php echo getStatusFrequencia($bim_dado['percentual'] ?? 0); ?></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($frequencias_por_disciplina)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-bar"></i> Gráfico de Frequência por Disciplina</div>
        <div class="card-body">
            <canvas id="graficoFrequencia" style="max-height: 150px; height: 150px;"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-info-circle"></i> Legenda</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><div class="d-flex align-items-center gap-2"><span class="badge bg-success">&nbsp;&nbsp;&nbsp;&nbsp;</span><span><strong>Regular:</strong> ≥ 75%</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center gap-2"><span class="badge bg-warning text-dark">&nbsp;&nbsp;&nbsp;&nbsp;</span><span><strong>Atenção:</strong> 50% - 74%</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center gap-2"><span class="badge bg-danger">&nbsp;&nbsp;&nbsp;&nbsp;</span><span><strong>Irregular:</strong> &lt; 50%</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center gap-2"><span class="badge bg-info">&nbsp;&nbsp;&nbsp;&nbsp;</span><span><strong>Justificado</strong></span></div></div>
            </div>
            <div class="row mt-2"><div class="col-md-12"><small class="text-muted"><i class="fas fa-info-circle"></i> P = Presente | F = Falta | J = Justificado | A = Atraso</small></div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    const disciplinas = <?php echo json_encode(array_keys($frequencias_por_disciplina)); ?>;
    const percentuais = <?php echo json_encode(array_column($frequencias_por_disciplina, 'percentual_geral')); ?>;
    const cores = percentuais.map(p => { if (p >= 75) return '#28a745'; if (p >= 50) return '#ffc107'; return '#dc3545'; });
    
    if (disciplinas.length > 0) {
        new Chart(document.getElementById('graficoFrequencia'), {
            type: 'bar',
            data: { labels: disciplinas, datasets: [{ label: 'Percentual de Frequência (%)', data: percentuais, backgroundColor: cores, borderRadius: 8, borderWidth: 0 }] },
            options: { 
                responsive: true, 
                maintainAspectRatio: true,
                scales: { 
                    y: { beginAtZero: true, max: 100, ticks: { callback: function(value) { return value + '%'; } }, title: { display: true, text: 'Percentual (%)' } }, 
                    x: { ticks: { autoSkip: false, rotation: 45, font: { size: 10 } } } 
                }, 
                plugins: { tooltip: { callbacks: { label: function(context) { return 'Frequência: ' + context.raw.toFixed(1) + '%'; } } }, legend: { display: false } } 
            }
        });
    }
</script>
</body>
</html>