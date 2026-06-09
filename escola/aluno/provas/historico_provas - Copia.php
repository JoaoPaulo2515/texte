<?php
// escola/aluno/provas/historico_provas.php - Histórico de Provas Realizadas

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
$titulo_pagina = 'Histórico de Provas';

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
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR HISTÓRICO DE PROVAS
// ==============================================
$sql_historico = "SELECT 
                    t.id as tentativa_id,
                    t.prova_id,
                    t.tentativa_numero,
                    t.data_inicio,
                    t.data_fim,
                    t.data_entrega,
                    t.tempo_gasto_segundos,
                    t.pontuacao_total,
                    t.porcentagem,
                    t.aprovado,
                    t.status as tentativa_status,
                    p.titulo,
                    p.descricao,
                    p.duracao_minutos,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    d.id as disciplina_id,
                    d.nome as disciplina_nome,
                    prof.nome as professor_nome,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id1) as respostas_respondidas,
                    (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id2 AND correta = 1) as respostas_corretas
                FROM online_provas_tentativas t
                JOIN online_provas p ON p.id = t.prova_id
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN funcionarios prof ON prof.id = p.professor_id
                WHERE t.aluno_id = :aluno_id3
                AND t.status IN ('finalizada', 'abandonada')
                AND p.escola_id = :escola_id";

if ($disciplina_filtro > 0) {
    $sql_historico .= " AND p.disciplina_id = :disciplina_id";
}
if ($ano_filtro > 0) {
    $sql_historico .= " AND YEAR(t.data_fim) = :ano";
}
if ($status_filtro == 'aprovado') {
    $sql_historico .= " AND t.aprovado = 1";
} elseif ($status_filtro == 'reprovado') {
    $sql_historico .= " AND t.aprovado = 0 AND t.status = 'finalizada'";
} elseif ($status_filtro == 'abandonada') {
    $sql_historico .= " AND t.status = 'abandonada'";
}
if (!empty($busca)) {
    $sql_historico .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}

$sql_historico .= " ORDER BY t.data_fim DESC";

$stmt_historico = $conn->prepare($sql_historico);
$params = [
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':aluno_id3' => $aluno_id,
    ':escola_id' => $escola_id
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_historico->execute($params);
$historico = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    JOIN online_provas_tentativas t ON t.prova_id = p.id
                    WHERE t.aluno_id = :aluno_id 
                    AND p.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR ANOS DISPONÍVEIS
// ==============================================
$sql_anos = "SELECT DISTINCT YEAR(t.data_fim) as ano 
             FROM online_provas_tentativas t
             WHERE t.aluno_id = :aluno_id 
             AND t.status = 'finalizada'
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($historico);
$total_aprovadas = 0;
$total_reprovadas = 0;
$total_abandonadas = 0;
$soma_notas = 0;
$melhor_nota = 0;
$pior_nota = 100;

foreach ($historico as $prova) {
    if ($prova['status'] == 'abandonada') {
        $total_abandonadas++;
    } elseif ($prova['aprovado'] == 1) {
        $total_aprovadas++;
        $soma_notas += $prova['pontuacao_total'];
        if ($prova['pontuacao_total'] > $melhor_nota) $melhor_nota = $prova['pontuacao_total'];
        if ($prova['pontuacao_total'] < $pior_nota) $pior_nota = $prova['pontuacao_total'];
    } else {
        $total_reprovadas++;
        $soma_notas += $prova['pontuacao_total'];
        if ($prova['pontuacao_total'] > $melhor_nota) $melhor_nota = $prova['pontuacao_total'];
        if ($prova['pontuacao_total'] < $pior_nota) $pior_nota = $prova['pontuacao_total'];
    }
}

$media_notas = $total_aprovadas + $total_reprovadas > 0 ? round($soma_notas / ($total_aprovadas + $total_reprovadas), 1) : 0;
$taxa_aprovacao = $total_aprovadas + $total_reprovadas > 0 ? round(($total_aprovadas / ($total_aprovadas + $total_reprovadas)) * 100) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusHistoricoBadge($aprovado, $status) {
    if ($status == 'abandonada') {
        return '<span class="badge bg-secondary"><i class="fas fa-ban"></i> Abandonada</span>';
    } elseif ($aprovado == 1) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}

function formatarDuracao($segundos) {
    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);
    $seg = $segundos % 60;
    
    if ($horas > 0) {
        return sprintf("%02d:%02d:%02d", $horas, $minutos, $seg);
    }
    return sprintf("%02d:%02d", $minutos, $seg);
}

function getDesempenhoClass($porcentagem) {
    if ($porcentagem >= 80) return 'excelente';
    if ($porcentagem >= 60) return 'bom';
    if ($porcentagem >= 40) return 'regular';
    return 'insuficiente';
}

function getDesempenhoTexto($porcentagem) {
    if ($porcentagem >= 80) return 'Excelente!';
    if ($porcentagem >= 60) return 'Bom';
    if ($porcentagem >= 40) return 'Regular';
    return 'Insuficiente';
}

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
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .prova-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .prova-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .prova-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .prova-body {
            padding: 20px;
        }
        .prova-footer {
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
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.3em;
        }
        
        .desempenho-excelente { background: #28a74520; color: #28a745; border-color: #28a745; }
        .desempenho-bom { background: #17a2b820; color: #17a2b8; border-color: #17a2b8; }
        .desempenho-regular { background: #ffc10720; color: #ffc107; border-color: #ffc107; }
        .desempenho-insuficiente { background: #dc354520; color: #dc3545; border-color: #dc3545; }
        
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
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Histórico de Provas</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todas as provas que você já realizou, com notas e desempenho.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Aprovado</span> - Nota igual ou superior à mínima<br>
                    <span class="badge bg-danger">Reprovado</span> - Nota inferior à mínima<br>
                    <span class="badge bg-secondary">Abandonada</span> - Prova não finalizada
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Filtros</div>
                <div class="ajuda-texto">Filtre por disciplina, ano, status ou pesquise por título.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Detalhes</div>
                <div class="ajuda-texto">Clique em "Ver Detalhes" para visualizar o resultado completo da prova.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-history"></i> Histórico de Provas</h4>
            <p class="text-muted mb-0">Todas as suas provas realizadas</p>
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
    
    <!-- Cards de Estatísticas -->
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
                <div class="stat-value text-secondary"><?php echo $total_abandonadas; ?></div>
                <div class="stat-label"><i class="fas fa-ban text-secondary"></i> Abandonadas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $taxa_aprovacao; ?>%</div>
                <div class="stat-label"><i class="fas fa-chart-line text-primary"></i> Taxa de Aprovação</div>
            </div>
        </div>
    </div>
    
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
                <div class="col-md-2">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="aprovado" <?php echo $status_filtro == 'aprovado' ? 'selected' : ''; ?>>Aprovados</option>
                        <option value="reprovado" <?php echo $status_filtro == 'reprovado' ? 'selected' : ''; ?>>Reprovados</option>
                        <option value="abandonada" <?php echo $status_filtro == 'abandonada' ? 'selected' : ''; ?>>Abandonadas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="historico_provas.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Histórico -->
    <?php if (empty($historico)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma prova encontrada</h5>
            <p>Você ainda não realizou nenhuma prova ou não há provas com os filtros selecionados.</p>
            <a href="disponiveis.php" class="btn btn-primary mt-2">
                <i class="fas fa-play-circle"></i> Ver Provas Disponíveis
            </a>
        </div>
    <?php else: ?>
        <div class="historico-list">
            <?php foreach ($historico as $prova): 
                $desempenho = getDesempenhoClass($prova['porcentagem']);
                $desempenho_texto = getDesempenhoTexto($prova['porcentagem']);
                $tempo_formatado = formatarDuracao($prova['tempo_gasto_segundos']);
                $nota_formatada = number_format($prova['pontuacao_total'], 1);
            ?>
            <div class="prova-card fade-in">
                <div class="prova-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2"><?php echo getStatusHistoricoBadge($prova['aprovado'], $prova['tentativa_status']); ?></span>
                        <span class="ms-2 text-muted">
                            <i class="fas fa-hashtag"></i> Tentativa <?php echo $prova['tentativa_numero']; ?>
                        </span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Professor: <?php echo htmlspecialchars($prova['professor_nome']); ?></small>
                    </div>
                </div>
                
                <div class="prova-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 100))); ?></p>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt text-primary"></i>
                                        <span>Realizada em: <strong><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></strong></span>
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
                                        <i class="fas fa-question-circle text-primary"></i>
                                        <span>Acertos: <strong><?php echo $prova['respostas_corretas']; ?> / <?php echo $prova['total_questoes']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-star text-warning"></i>
                                        <span>Nota mínima: <strong><?php echo $prova['nota_minima_aprovacao']; ?></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="mb-2">
                                <div class="nota-circle mx-auto border desempenho-<?php echo $desempenho; ?>">
                                    <?php echo $nota_formatada; ?>
                                </div>
                                <small class="text-muted">Nota / <?php echo $prova['nota_maxima']; ?></small>
                            </div>
                            <div class="mt-2">
                                <span class="badge bg-<?php echo $desempenho == 'excelente' ? 'success' : ($desempenho == 'bom' ? 'info' : ($desempenho == 'regular' ? 'warning' : 'danger')); ?>">
                                    <?php echo $desempenho_texto; ?>
                                </span>
                            </div>
                            <div class="progress mt-2" style="height: 5px;">
                                <div class="progress-bar <?php echo $prova['aprovado'] == 1 ? 'bg-success' : 'bg-danger'; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $prova['porcentagem']; ?>%;"></div>
                            </div>
                            <div class="mt-2">
                                <small><?php echo $prova['porcentagem']; ?>% de aproveitamento</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="prova-footer">
                    <div>
                        <?php if ($prova['aprovado'] == 1): ?>
                            <span class="text-success"><i class="fas fa-trophy"></i> Parabéns! Você foi aprovado.</span>
                        <?php elseif ($prova['tentativa_status'] == 'abandonada'): ?>
                            <span class="text-secondary"><i class="fas fa-ban"></i> Prova abandonada.</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="fas fa-frown"></i> Você não atingiu a nota mínima.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="resultado_prova.php?id=<?php echo $prova['tentativa_id']; ?>" class="btn-ver-detalhes">
                            <i class="fas fa-chart-line"></i> Ver Detalhes
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Resumo Acadêmico -->
    <?php if (!empty($historico) && ($total_aprovadas + $total_reprovadas) > 0): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-pie"></i> Resumo Acadêmico</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Média das Notas:</span>
                        <strong><?php echo number_format($media_notas, 1); ?> / <?php echo $prova['nota_maxima']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Melhor Nota:</span>
                        <strong class="text-success"><?php echo number_format($melhor_nota, 1); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Pior Nota:</span>
                        <strong class="text-danger"><?php echo number_format($pior_nota, 1); ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Taxa de Aprovação:</span>
                        <strong class="text-success"><?php echo $taxa_aprovacao; ?>%</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total de Provas Realizadas:</span>
                        <strong><?php echo $total_aprovadas + $total_reprovadas; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total de Provas Abandonadas:</span>
                        <strong class="text-secondary"><?php echo $total_abandonadas; ?></strong>
                    </div>
                </div>
            </div>
        </div>
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
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>