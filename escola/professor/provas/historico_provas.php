<?php
// escola/professor/provas/historico_provas.php - Histórico de Provas do Professor

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
// Verificar se o professor está logado
if (!isset($_SESSION['professor_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}*/

$db = Database::getInstance();
$conn = $db->getConnection();
$professor_id = $_SESSION['professor_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['professor_nome'] ?? 'Professor';

// Definir título da página
$titulo_pagina = 'Histórico de Provas';

// Filtros
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN professor_disciplina_turma pd ON pd.disciplina_id = d.id
                    WHERE pd.professor_id = :professor_id AND d.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR TURMAS DO PROFESSOR
// ==============================================
$sql_turmas = "SELECT DISTINCT t.id, t.nome, t.ano 
               FROM turmas t
               JOIN professor_disciplina_turma pt ON pt.turma_id = t.id
               WHERE pt.professor_id = :professor_id AND t.escola_id = :escola_id
               ORDER BY t.ano DESC, t.nome ASC";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id, ':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR HISTÓRICO DE PROVAS
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.tipo,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.status,
                    p.created_at,
                    d.id as disciplina_id,
                    d.nome as disciplina_nome,
                    t.id as turma_id,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(DISTINCT aluno_id) FROM online_provas_tentativas WHERE prova_id = p.id) as total_alunos,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_finalizadas,
                    (SELECT AVG(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as media_notas,
                    (SELECT MAX(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as maior_nota,
                    (SELECT MIN(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as menor_nota
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN turmas t ON t.id = p.turma_id
                WHERE p.professor_id = :professor_id 
                AND p.escola_id = :escola_id";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if ($turma_filtro > 0) {
    $sql_provas .= " AND p.turma_id = :turma_id";
}
if ($status_filtro != 'todas') {
    $sql_provas .= " AND p.status = :status";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR p.descricao LIKE :busca)";
}

$sql_provas .= " ORDER BY p.created_at DESC, p.data_inicio DESC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
];
if ($disciplina_filtro > 0) $params[':disciplina_id'] = $disciplina_filtro;
if ($turma_filtro > 0) $params[':turma_id'] = $turma_filtro;
if ($status_filtro != 'todas') $params[':status'] = $status_filtro;
if (!empty($busca)) $params[':busca'] = "%$busca%";
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$total_ativas = 0;
$total_agendadas = 0;
$total_finalizadas = 0;
$total_canceladas = 0;
$total_alunos_atingidos = 0;
$soma_medias = 0;

foreach ($provas as $prova) {
    if ($prova['status'] == 'em_andamento') $total_ativas++;
    elseif ($prova['status'] == 'agendada') $total_agendadas++;
    elseif ($prova['status'] == 'finalizada') $total_finalizadas++;
    elseif ($prova['status'] == 'cancelada') $total_canceladas++;
    
    $total_alunos_atingidos += $prova['total_alunos'];
    if ($prova['media_notas']) $soma_medias += $prova['media_notas'];
}

$media_geral_notas = $total_finalizadas > 0 ? round($soma_medias / $total_finalizadas, 1) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'agendada':
            return '<span class="badge bg-secondary"><i class="fas fa-calendar-alt"></i> Agendada</span>';
        case 'em_andamento':
            return '<span class="badge bg-success"><i class="fas fa-play-circle"></i> Em andamento</span>';
        case 'finalizada':
            return '<span class="badge bg-info"><i class="fas fa-check-circle"></i> Finalizada</span>';
        case 'cancelada':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoProvaLabel($tipo) {
    $tipos = [
        'prova' => 'Prova',
        'teste' => 'Teste',
        'quiz' => 'Quiz',
        'simulado' => 'Simulado'
    ];
    return $tipos[$tipo] ?? ucfirst($tipo);
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function getNotaClass($nota) {
    if ($nota === null) return 'text-secondary';
    if ($nota >= 14) return 'text-success fw-bold';
    if ($nota >= 10) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Professor</title>
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
        
        .btn-ver-detalhes {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
        }
        
        .btn-ver-resultados {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
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
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-professor { display: none; }
        }
        
        .progress-bar-custom {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
    </style>
</head>
<body>

     <?php include '../includes/menu_professor.php'; ?>
     
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
                <div class="ajuda-texto">Esta página exibe o histórico completo de todas as provas criadas por você.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status das Provas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-secondary">Agendada</span> - Prova agendada para futuro<br>
                    <span class="badge bg-success">Em andamento</span> - Prova disponível para alunos<br>
                    <span class="badge bg-info">Finalizada</span> - Prova encerrada<br>
                    <span class="badge bg-danger">Cancelada</span> - Prova cancelada
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Estatísticas</div>
                <div class="ajuda-texto">Visualize médias, maior e menor nota, participação dos alunos.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Ações</div>
                <div class="ajuda-texto">Ver detalhes da prova e resultados dos alunos.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-professor">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-history"></i> Histórico de Provas</h4>
            <p class="text-muted mb-0">Todas as provas criadas por você</p>
        </div>
        <div>
            <a href="criar_prova.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Nova Prova
            </a>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Professor -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-chalkboard-user"></i> Professor</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($professor_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-book"></i> Disciplinas</small>
                    <h6 class="mb-0"><?php echo count($disciplinas); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turmas</small>
                    <h6 class="mb-0"><?php echo count($turmas); ?></h6>
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
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?php echo $total_agendadas; ?></div>
                <div class="stat-label">Agendadas</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                <div class="stat-label">Em andamento</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $total_finalizadas; ?></div>
                <div class="stat-label">Finalizadas</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_canceladas; ?></div>
                <div class="stat-label">Canceladas</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_alunos_atingidos; ?></div>
                <div class="stat-label">Alunos Atingidos</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo number_format($media_geral_notas, 1); ?></div>
                <div class="stat-label">Média Geral</div>
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
                <div class="col-md-3">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $tur): ?>
                        <option value="<?php echo $tur['id']; ?>" <?php echo $turma_filtro == $tur['id'] ? 'selected' : ''; ?>>
                            <?php echo $tur['ano'] . 'ª - ' . htmlspecialchars($tur['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todas">Todas</option>
                        <option value="agendada" <?php echo $status_filtro == 'agendada' ? 'selected' : ''; ?>>Agendadas</option>
                        <option value="em_andamento" <?php echo $status_filtro == 'em_andamento' ? 'selected' : ''; ?>>Em andamento</option>
                        <option value="finalizada" <?php echo $status_filtro == 'finalizada' ? 'selected' : ''; ?>>Finalizadas</option>
                        <option value="cancelada" <?php echo $status_filtro == 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="historico_provas.php" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
                <div class="col-md-12">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por título ou descrição..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Provas -->
    <?php if (empty($provas)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma prova encontrada</h5>
            <p>Você ainda não criou nenhuma prova ou não há provas com os filtros selecionados.</p>
            <a href="criar_prova.php" class="btn btn-primary mt-2">
                <i class="fas fa-plus-circle"></i> Criar Nova Prova
            </a>
        </div>
    <?php else: ?>
        <div class="provas-list">
            <?php foreach ($provas as $prova): 
                $percentual_participacao = $prova['total_alunos'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_alunos']) * 100, 1) : 0;
                $media_nota_class = getNotaClass($prova['media_notas']);
            ?>
            <div class="prova-card fade-in" id="prova-<?php echo $prova['id']; ?>">
                <div class="prova-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2 badge bg-secondary"><?php echo getTipoProvaLabel($prova['tipo']); ?></span>
                        <span class="ms-2"><?php echo getStatusBadge($prova['status']); ?></span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></small>
                    </div>
                </div>
                
                <div class="prova-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))); ?></p>
                            
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt text-primary"></i>
                                        <span>Início: <strong><?php echo formatarData($prova['data_inicio']); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-times text-danger"></i>
                                        <span>Término: <strong><?php echo formatarData($prova['data_fim']); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-clock text-info"></i>
                                        <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-question-circle text-primary"></i>
                                        <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-star text-warning"></i>
                                        <span>Nota Máxima: <strong><?php echo $prova['nota_maxima']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item">
                                        <i class="fas fa-repeat text-info"></i>
                                        <span>Tentativas: <strong><?php echo $prova['tentativas_permitidas']; ?></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Participação</small>
                                    <small><?php echo $percentual_participacao; ?>%</small>
                                </div>
                                <div class="progress-bar-custom">
                                    <div class="progress-fill" style="width: <?php echo $percentual_participacao; ?>%; background: #17a2b8;"></div>
                                </div>
                                <div class="text-center mt-2">
                                    <small><?php echo $prova['total_finalizadas']; ?> / <?php echo $prova['total_alunos']; ?> alunos</small>
                                </div>
                            </div>
                            
                            <?php if ($prova['status'] == 'finalizada'): ?>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Média das Notas</small>
                                    <small class="<?php echo $media_nota_class; ?>"><?php echo number_format($prova['media_notas'], 1); ?></small>
                                </div>
                                <div class="progress-bar-custom">
                                    <div class="progress-fill" style="width: <?php echo ($prova['media_notas'] / $prova['nota_maxima']) * 100; ?>%; background: #006B3E;"></div>
                                </div>
                                <div class="row mt-2 text-center">
                                    <div class="col-6">
                                        <small class="text-success">Maior: <?php echo number_format($prova['maior_nota'], 1); ?></small>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-danger">Menor: <?php echo number_format($prova['menor_nota'], 1); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="prova-footer">
                    <div>
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> Criada em: <?php echo formatarData($prova['created_at'], 'd/m/Y H:i'); ?>
                        </small>
                    </div>
                    <div>
                        <a href="detalhes_prova.php?id=<?php echo $prova['id']; ?>" class="btn-ver-detalhes">
                            <i class="fas fa-info-circle"></i> Ver Detalhes
                        </a>
                        <?php if ($prova['status'] == 'finalizada'): ?>
                        <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn-ver-resultados ms-2">
                            <i class="fas fa-chart-line"></i> Ver Resultados
                        </a>
                        <?php endif; ?>
                        <?php if ($prova['status'] == 'agendada'): ?>
                        <button class="btn btn-sm btn-outline-danger ms-2" onclick="cancelarProva(<?php echo $prova['id']; ?>)">
                            <i class="fas fa-ban"></i> Cancelar
                        </button>
                        <?php endif; ?>
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
    
    // Função para cancelar prova
    function cancelarProva(id) {
        if (confirm('Tem certeza que deseja cancelar esta prova? Esta ação não pode ser desfeita.')) {
            $.ajax({
                url: 'cancelar_prova.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Prova cancelada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao cancelar prova. Tente novamente.');
                }
            });
        }
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>