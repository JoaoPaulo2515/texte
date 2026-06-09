<?php
// escola/aluno/provas/resultados.php - Meus Resultados das Provas

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
$titulo_pagina = 'Meus Resultados';

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
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$periodo_filtro = isset($_GET['periodo']) ? $_GET['periodo'] : 'todas';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR RESULTADOS DAS PROVAS
// ==============================================
$sql_resultados = "SELECT 
                        t.id as tentativa_id,
                        t.prova_id,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.data_fim,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        p.titulo,
                        p.descricao,
                        p.duracao_minutos,
                        p.nota_maxima,
                        p.nota_minima_aprovacao,
                        d.id as disciplina_id,
                        d.nome as disciplina_nome,
                        prof.nome as professor_nome,
                        (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                        (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id1 AND correta = 1) as respostas_corretas,
                        (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id2 AND correta = 0 AND resposta_texto IS NOT NULL) as respostas_incorretas,
                        (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id3 AND resposta_texto IS NULL AND resposta_boolean IS NULL AND alternativa_id IS NULL) as respostas_nao_respondidas
                    FROM online_provas_tentativas t
                    JOIN online_provas p ON p.id = t.prova_id
                    JOIN disciplinas d ON d.id = p.disciplina_id
                    JOIN funcionarios prof ON prof.id = p.professor_id
                    WHERE t.aluno_id = :aluno_id4
                    AND t.status = 'finalizada'
                    AND p.escola_id = :escola_id";

if ($disciplina_filtro > 0) {
    $sql_resultados .= " AND p.disciplina_id = :disciplina_id";
}
if ($periodo_filtro != 'todas') {
    if ($periodo_filtro == 'aprovadas') {
        $sql_resultados .= " AND t.aprovado = 1";
    } elseif ($periodo_filtro == 'reprovadas') {
        $sql_resultados .= " AND t.aprovado = 0";
    } elseif ($periodo_filtro == 'ultimo_mes') {
        $sql_resultados .= " AND t.data_fim >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($periodo_filtro == 'ultimo_semestre') {
        $sql_resultados .= " AND t.data_fim >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    }
}
if (!empty($busca)) {
    $sql_resultados .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}

$sql_resultados .= " ORDER BY t.data_fim DESC";

$stmt_resultados = $conn->prepare($sql_resultados);
$params = [
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':aluno_id3' => $aluno_id,
    ':aluno_id4' => $aluno_id,
    ':escola_id' => $escola_id
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_resultados->execute($params);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    JOIN online_provas_tentativas t ON t.prova_id = p.id
                    WHERE t.aluno_id = :aluno_id 
                    AND t.status = 'finalizada'
                    AND p.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS GERAIS
// ==============================================
$total_provas = count($resultados);
$total_aprovadas = 0;
$total_reprovadas = 0;
$soma_notas = 0;
$soma_porcentagens = 0;
$melhor_nota = 0;
$melhor_nota_titulo = '';
$pior_nota = 100;
$pior_nota_titulo = '';
$total_questoes_corretas = 0;
$total_questoes_geral = 0;

foreach ($resultados as $resultado) {
    if ($resultado['aprovado'] == 1) {
        $total_aprovadas++;
    } else {
        $total_reprovadas++;
    }
    
    $soma_notas += $resultado['pontuacao_total'];
    $soma_porcentagens += $resultado['porcentagem'];
    $total_questoes_corretas += $resultado['respostas_corretas'];
    $total_questoes_geral += $resultado['total_questoes'];
    
    if ($resultado['pontuacao_total'] > $melhor_nota) {
        $melhor_nota = $resultado['pontuacao_total'];
        $melhor_nota_titulo = $resultado['titulo'];
    }
    if ($resultado['pontuacao_total'] < $pior_nota) {
        $pior_nota = $resultado['pontuacao_total'];
        $pior_nota_titulo = $resultado['titulo'];
    }
}

$media_notas = $total_provas > 0 ? round($soma_notas / $total_provas, 1) : 0;
$media_porcentagem = $total_provas > 0 ? round($soma_porcentagens / $total_provas, 1) : 0;
$taxa_acerto = $total_questoes_geral > 0 ? round(($total_questoes_corretas / $total_questoes_geral) * 100, 1) : 0;

// ==============================================
// DADOS PARA GRÁFICO
// ==============================================
$labels = [];
$notas = [];
$aprovados_bg = [];
foreach ($resultados as $resultado) {
    $labels[] = "'" . addslashes(substr($resultado['titulo'], 0, 20)) . "'";
    $notas[] = $resultado['pontuacao_total'];
    $aprovados_bg[] = $resultado['aprovado'] == 1 ? "'#28a745'" : "'#dc3545'";
}

$labels_js = implode(', ', $labels);
$notas_js = implode(', ', $notas);
$cores_js = implode(', ', $aprovados_bg);

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarDuracao($segundos) {
    $minutos = floor($segundos / 60);
    $seg = $segundos % 60;
    return sprintf("%02d:%02d", $minutos, $seg);
}

function getDesempenhoClass($porcentagem) {
    if ($porcentagem >= 80) return 'excelente';
    if ($porcentagem >= 60) return 'bom';
    if ($porcentagem >= 40) return 'regular';
    return 'insuficiente';
}

function getDesempenhoIcone($porcentagem) {
    if ($porcentagem >= 80) return '<i class="fas fa-star"></i>';
    if ($porcentagem >= 60) return '<i class="fas fa-thumbs-up"></i>';
    if ($porcentagem >= 40) return '<i class="fas fa-meh"></i>';
    return '<i class="fas fa-thumbs-down"></i>';
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
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .resultado-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .resultado-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .resultado-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .resultado-body {
            padding: 20px;
        }
        .resultado-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .disciplina-badge {
            background: #e8f5e9;
            color: #006B3E;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
        }
        
        .nota-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 auto;
        }
        
        .desempenho-excelente { background: #28a74520; color: #28a745; border: 2px solid #28a745; }
        .desempenho-bom { background: #17a2b820; color: #17a2b8; border: 2px solid #17a2b8; }
        .desempenho-regular { background: #ffc10720; color: #ffc107; border: 2px solid #ffc107; }
        .desempenho-insuficiente { background: #dc354520; color: #dc3545; border: 2px solid #dc3545; }
        
        .stats-mini {
            background: white;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .grafico-container { display: none; }
        }
        
        .btn-ver-detalhes {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
        }
        .btn-ver-detalhes:hover {
            background: #004d2e;
        }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Meus Resultados</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todos os seus resultados de provas realizadas.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Gráfico de Desempenho</div>
                <div class="ajuda-texto">O gráfico mostra sua evolução ao longo das provas realizadas.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Estatísticas</div>
                <div class="ajuda-texto">Acompanhe sua média, taxa de acerto e classificação de desempenho.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Filtre por disciplina, período ou pesquise por título da prova.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-line"></i> Meus Resultados</h4>
            <p class="text-muted mb-0">Acompanhe seu desempenho nas provas online</p>
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
                    <small class="text-muted"><i class="fas fa-file-alt"></i> Total Provas</small>
                    <h6 class="mb-0"><?php echo $total_provas; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas Principais -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_aprovadas; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Aprovadas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_reprovadas; ?></div>
                <div class="stat-label"><i class="fas fa-times-circle text-danger"></i> Reprovadas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo number_format($media_notas, 1); ?></div>
                <div class="stat-label"><i class="fas fa-calculator text-primary"></i> Média Geral</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $taxa_acerto; ?>%</div>
                <div class="stat-label"><i class="fas fa-percent text-info"></i> Taxa de Acerto</div>
            </div>
        </div>
    </div>
    
    <!-- Estatísticas Detalhadas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stats-mini">
                <small class="text-muted">Melhor Nota</small>
                <div class="h5 mb-0 text-success"><?php echo number_format($melhor_nota, 1); ?></div>
                <small><?php echo htmlspecialchars(substr($melhor_nota_titulo, 0, 30)); ?></small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-mini">
                <small class="text-muted">Pior Nota</small>
                <div class="h5 mb-0 text-danger"><?php echo number_format($pior_nota, 1); ?></div>
                <small><?php echo htmlspecialchars(substr($pior_nota_titulo, 0, 30)); ?></small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-mini">
                <small class="text-muted">Média de Aproveitamento</small>
                <div class="h5 mb-0 text-primary"><?php echo $media_porcentagem; ?>%</div>
                <small><?php echo $total_provas; ?> prova(s) realizada(s)</small>
            </div>
        </div>
    </div>
    
    <!-- Gráfico de Desempenho -->
    <?php if (!empty($resultados)): ?>
    <div class="card border-0 shadow-sm mb-4 fade-in grafico-container">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-bar"></i> Gráfico de Desempenho por Prova</div>
        <div class="card-body">
            <canvas id="graficoDesempenho" height="250"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Período</label>
                    <select name="periodo" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $periodo_filtro == 'todas' ? 'selected' : ''; ?>>Todas as provas</option>
                        <option value="aprovadas" <?php echo $periodo_filtro == 'aprovadas' ? 'selected' : ''; ?>>Somente aprovadas</option>
                        <option value="reprovadas" <?php echo $periodo_filtro == 'reprovadas' ? 'selected' : ''; ?>>Somente reprovadas</option>
                        <option value="ultimo_mes" <?php echo $periodo_filtro == 'ultimo_mes' ? 'selected' : ''; ?>>Último mês</option>
                        <option value="ultimo_semestre" <?php echo $periodo_filtro == 'ultimo_semestre' ? 'selected' : ''; ?>>Último semestre</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="resultados.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Resultados -->
    <?php if (empty($resultados)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhum resultado encontrado</h5>
            <p>Você ainda não realizou nenhuma prova ou não há resultados com os filtros selecionados.</p>
            <a href="disponiveis.php" class="btn btn-primary mt-2">
                <i class="fas fa-play-circle"></i> Ver Provas Disponíveis
            </a>
        </div>
    <?php else: ?>
        <div class="resultados-list">
            <?php foreach ($resultados as $resultado): 
                $desempenho = getDesempenhoClass($resultado['porcentagem']);
                $desempenho_icone = getDesempenhoIcone($resultado['porcentagem']);
                $tempo_formatado = formatarDuracao($resultado['tempo_gasto_segundos']);
                $nota_formatada = number_format($resultado['pontuacao_total'], 1);
                $percentual_acerto = $resultado['total_questoes'] > 0 ? round(($resultado['respostas_corretas'] / $resultado['total_questoes']) * 100, 1) : 0;
            ?>
            <div class="resultado-card fade-in">
                <div class="resultado-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($resultado['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2 badge <?php echo $resultado['aprovado'] == 1 ? 'bg-success' : 'bg-danger'; ?>">
                            <i class="fas <?php echo $resultado['aprovado'] == 1 ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                            <?php echo $resultado['aprovado'] == 1 ? 'Aprovado' : 'Reprovado'; ?>
                        </span>
                        <span class="ms-2 text-muted">
                            <i class="fas fa-hashtag"></i> Tentativa <?php echo $resultado['tentativa_numero']; ?>
                        </span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Professor: <?php echo htmlspecialchars($resultado['professor_nome']); ?></small>
                    </div>
                </div>
                
                <div class="resultado-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($resultado['titulo']); ?></h5>
                            
                            <div class="row g-2 mt-2">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt text-primary"></i>
                                        <span>Realizada em: <strong><?php echo date('d/m/Y H:i', strtotime($resultado['data_fim'])); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-clock text-info"></i>
                                        <span>Tempo gasto: <strong><?php echo $tempo_formatado; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-check-circle text-success"></i>
                                        <span>Acertos: <strong><?php echo $resultado['respostas_corretas']; ?> / <?php echo $resultado['total_questoes']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-times-circle text-danger"></i>
                                        <span>Erros: <strong><?php echo $resultado['respostas_incorretas']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $percentual_acerto; ?>%;">
                                        </div>
                                        <div class="progress-bar bg-danger" style="width: <?php echo 100 - $percentual_acerto; ?>%;">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small>Acertos: <?php echo $percentual_acerto; ?>%</small>
                                        <small>Nota: <?php echo $nota_formatada; ?> / <?php echo $resultado['nota_maxima']; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="nota-circle mx-auto desempenho-<?php echo $desempenho; ?>">
                                <span style="font-size: 1.5em;"><?php echo $nota_formatada; ?></span>
                                <small style="font-size: 0.6rem;">/<?php echo $resultado['nota_maxima']; ?></small>
                            </div>
                            <div class="mt-2">
                                <span class="badge bg-<?php echo $desempenho == 'excelente' ? 'success' : ($desempenho == 'bom' ? 'info' : ($desempenho == 'regular' ? 'warning' : 'danger')); ?>">
                                    <?php echo $desempenho_icone; ?> <?php echo ucfirst($desempenho); ?>
                                </span>
                            </div>
                            <div class="mt-2">
                                <small><?php echo $resultado['porcentagem']; ?>% de aproveitamento</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="resultado-footer">
                    <div>
                        <?php if ($resultado['aprovado'] == 1): ?>
                            <span class="text-success"><i class="fas fa-trophy"></i> Parabéns! Continue assim!</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="fas fa-frown"></i> Estude mais e tente novamente.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="resultado_prova.php?id=<?php echo $resultado['tentativa_id']; ?>" class="btn-ver-detalhes">
                            <i class="fas fa-chart-line"></i> Ver Detalhes
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
    
    // Gráfico de Desempenho
    <?php if (!empty($resultados)): ?>
    const ctx = document.getElementById('graficoDesempenho').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [<?php echo $labels_js; ?>],
            datasets: [{
                label: 'Nota Obtida',
                data: [<?php echo $notas_js; ?>],
                backgroundColor: [<?php echo $cores_js; ?>],
                borderRadius: 5,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
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
                            return 'Nota: ' + context.raw.toFixed(1);
                        }
                    }
                },
                legend: {
                    position: 'top'
                }
            }
        }
    });
    <?php endif; ?>
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>