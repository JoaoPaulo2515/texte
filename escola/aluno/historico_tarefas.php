<?php
// escola/aluno/tarefas/historico_tarefas.php - Histórico de Tarefas do Aluno

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
$titulo_pagina = 'Histórico de Tarefas';

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
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN disciplina_turma dt ON dt.disciplina_id = d.id
                    WHERE dt.turma_id = :turma_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR TAREFAS DO ALUNO (HISTÓRICO COMPLETO)
// ==============================================
$sql_tarefas = "SELECT 
                    t.id,
                    t.escola_id,
                    t.titulo,
                    t.descricao,
                    t.disciplina_id,
                    d.nome as disciplina_nome,
                    t.professor_id,
                    p.nome as professor_nome,
                    t.turma_id,
                    tur.nome as turma_nome,
                    tur.ano as turma_ano,
                    t.data_publicacao,
                    t.data_entrega,
                    t.max_pontos,
                    t.material_apoio,
                    t.status as tarefa_status,
                    r.id as resposta_id,
                    r.resposta_texto,
                    r.anexo_path,
                    r.status as resposta_status,
                    r.nota,
                    r.comentario_professor,
                    r.data_entrega as data_resposta,
                    CASE 
                        WHEN r.id IS NULL THEN 'pendente'
                        WHEN r.status = 'corrigido' THEN 'corrigido'
                        WHEN r.status = 'entregue' AND t.data_entrega < NOW() THEN 'entregue_atrasado'
                        WHEN r.status = 'entregue' AND t.data_entrega >= NOW() THEN 'entregue_no_prazo'
                        ELSE r.status
                    END as status_aluno
                FROM tarefas t
                INNER JOIN disciplinas d ON d.id = t.disciplina_id AND d.escola_id = t.escola_id
                INNER JOIN funcionarios p ON p.id = t.professor_id AND p.escola_id = t.escola_id
                INNER JOIN turmas tur ON tur.id = t.turma_id AND tur.escola_id = t.escola_id
                LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = :aluno_id
                WHERE t.turma_id = :turma_id
                AND t.escola_id = :escola_id";

if ($disciplina_filtro > 0) {
    $sql_tarefas .= " AND t.disciplina_id = :disciplina_id";
}
if ($status_filtro != 'todos') {
    if ($status_filtro == 'pendente') {
        $sql_tarefas .= " AND r.id IS NULL";
    } elseif ($status_filtro == 'entregue') {
        $sql_tarefas .= " AND r.id IS NOT NULL AND r.status IN ('entregue', 'corrigido')";
    } elseif ($status_filtro == 'corrigido') {
        $sql_tarefas .= " AND r.status = 'corrigido'";
    } elseif ($status_filtro == 'nao_entregue') {
        $sql_tarefas .= " AND r.id IS NULL AND t.data_entrega < NOW()";
    }
}
if (!empty($busca)) {
    $sql_tarefas .= " AND (t.titulo LIKE :busca OR t.descricao LIKE :busca)";
}

$sql_tarefas .= " ORDER BY t.data_entrega DESC";

$stmt_tarefas = $conn->prepare($sql_tarefas);
$params = [
    ':aluno_id' => $aluno_id,
    ':turma_id' => $turma_id,
    ':escola_id' => $escola_id
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_tarefas->execute($params);
$tarefas = $stmt_tarefas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_tarefas = count($tarefas);
$total_pendentes = 0;
$total_entregues = 0;
$total_corrigidas = 0;
$total_atrasadas = 0;
$soma_notas = 0;
$media_notas = 0;

foreach ($tarefas as $tarefa) {
    if ($tarefa['status_aluno'] == 'pendente') {
        $total_pendentes++;
        if (strtotime($tarefa['data_entrega']) < time()) {
            $total_atrasadas++;
        }
    } elseif ($tarefa['status_aluno'] == 'entregue_no_prazo' || $tarefa['status_aluno'] == 'entregue_atrasado') {
        $total_entregues++;
    } elseif ($tarefa['status_aluno'] == 'corrigido') {
        $total_corrigidas++;
        $soma_notas += $tarefa['nota'];
    }
}

if ($total_corrigidas > 0) {
    $media_notas = $soma_notas / $total_corrigidas;
}

// Funções auxiliares
function getStatusBadge($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'entregue_no_prazo':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Entregue no Prazo</span>';
        case 'entregue_atrasado':
            return '<span class="badge bg-info"><i class="fas fa-hourglass-half"></i> Entregue com Atraso</span>';
        case 'corrigido':
            return '<span class="badge bg-primary"><i class="fas fa-check-double"></i> Corrigido</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getStatusCor($status) {
    switch ($status) {
        case 'pendente': return '#ffc107';
        case 'entregue_no_prazo': return '#28a745';
        case 'entregue_atrasado': return '#17a2b8';
        case 'corrigido': return '#006B3E';
        default: return '#6c757d';
    }
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function formatarNota($nota, $max_pontos = 10) {
    if (is_null($nota)) return '-';
    return number_format($nota, 1, ',', '.') . ' / ' . number_format($max_pontos, 1, ',', '.');
}

function getNotaPercentual($nota, $max_pontos = 10) {
    if (is_null($nota)) return 0;
    return ($nota / $max_pontos) * 100;
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
        
        .tarefa-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .tarefa-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .tarefa-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .tarefa-body {
            padding: 20px;
        }
        .tarefa-footer {
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
        .nota-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
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
            .tarefa-card { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Histórico de Tarefas</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe o histórico completo de todas as tarefas atribuídas ao aluno.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status das Tarefas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-warning">Pendente</span> - Aguardando entrega<br>
                    <span class="badge bg-success">Entregue no Prazo</span> - Entregue antes da data limite<br>
                    <span class="badge bg-info">Entregue com Atraso</span> - Entregue após a data limite<br>
                    <span class="badge bg-primary">Corrigido</span> - Já corrigido pelo professor
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Filtros</div>
                <div class="ajuda-texto">Filtre por disciplina, status ou pesquise por título/descrição.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Notas</div>
                <div class="ajuda-texto">As notas são exibidas quando o professor corrigir a tarefa.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-history"></i> Histórico de Tarefas</h4>
            <p class="text-muted mb-0">Todas as tarefas atribuídas e seu desempenho</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            <button class="btn btn-primary ms-2" onclick="window.print();">
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
                    <small class="text-muted"><i class="fas fa-tasks"></i> Total Tarefas</small>
                    <h6 class="mb-0"><?php echo $total_tarefas; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_pendentes; ?></div>
                <div class="stat-label"><i class="fas fa-clock text-warning"></i> Pendentes</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_entregues; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Entregues</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_corrigidas; ?></div>
                <div class="stat-label"><i class="fas fa-check-double text-primary"></i> Corrigidas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo number_format($media_notas, 1, ',', '.'); ?></div>
                <div class="stat-label"><i class="fas fa-star text-info"></i> Média das Notas</div>
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
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="entregue" <?php echo $status_filtro == 'entregue' ? 'selected' : ''; ?>>Entregues</option>
                        <option value="corrigido" <?php echo $status_filtro == 'corrigido' ? 'selected' : ''; ?>>Corrigidas</option>
                        <option value="nao_entregue" <?php echo $status_filtro == 'nao_entregue' ? 'selected' : ''; ?>>Não Entregues</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título ou descrição..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="historico_tarefas.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Tarefas -->
    <?php if (empty($tarefas)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma tarefa encontrada</h5>
            <p>Não foram encontradas tarefas para os filtros selecionados.</p>
        </div>
    <?php else: ?>
        <div class="tarefas-list">
            <?php foreach ($tarefas as $tarefa): 
                $status_color = getStatusCor($tarefa['status_aluno']);
                $percentual_nota = getNotaPercentual($tarefa['nota'], $tarefa['max_pontos']);
                $nota_color = $percentual_nota >= 70 ? '#28a745' : ($percentual_nota >= 50 ? '#ffc107' : '#dc3545');
            ?>
            <div class="tarefa-card fade-in">
                <div class="tarefa-header" style="border-left: 4px solid <?php echo $status_color; ?>;">
                    <div>
                        <h5 class="mb-1"><?php echo htmlspecialchars($tarefa['titulo']); ?></h5>
                        <div>
                            <span class="disciplina-badge">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($tarefa['disciplina_nome']); ?>
                            </span>
                            <small class="text-muted ms-2">
                                <i class="fas fa-chalkboard-user"></i> Prof. <?php echo htmlspecialchars($tarefa['professor_nome']); ?>
                            </small>
                        </div>
                    </div>
                    <div>
                        <?php echo getStatusBadge($tarefa['status_aluno']); ?>
                    </div>
                </div>
                
                <div class="tarefa-body">
                    <div class="row">
                        <div class="col-md-8">
                            <p class="text-muted mb-2">
                                <i class="fas fa-align-left"></i> Descrição:
                            </p>
                            <p><?php echo nl2br(htmlspecialchars($tarefa['descricao'] ?? 'Sem descrição')); ?></p>
                            
                            <?php if ($tarefa['resposta_texto']): ?>
                            <div class="mt-3 p-2 bg-light rounded">
                                <p class="mb-1"><strong><i class="fas fa-reply"></i> Sua Resposta:</strong></p>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($tarefa['resposta_texto'])); ?></p>
                                <?php if ($tarefa['anexo_path']): ?>
                                <a href="<?php echo $tarefa['anexo_path']; ?>" target="_blank" class="btn btn-sm btn-outline-info mt-2">
                                    <i class="fas fa-paperclip"></i> Ver Anexo
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($tarefa['comentario_professor']): ?>
                            <div class="mt-3 p-2 bg-success bg-opacity-10 rounded">
                                <p class="mb-1"><strong><i class="fas fa-comment"></i> Comentário do Professor:</strong></p>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($tarefa['comentario_professor'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <small class="text-muted"><i class="fas fa-calendar-alt"></i> Publicação:</small>
                                <span><?php echo formatarData($tarefa['data_publicacao']); ?></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <small class="text-muted"><i class="fas fa-hourglass-end"></i> Data Limite:</small>
                                <span class="<?php echo strtotime($tarefa['data_entrega']) < time() ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatarData($tarefa['data_entrega']); ?>
                                </span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <small class="text-muted"><i class="fas fa-star"></i> Pontuação:</small>
                                <span><?php echo $tarefa['max_pontos']; ?> pontos</span>
                            </div>
                            
                            <?php if ($tarefa['resposta_data']): ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <small class="text-muted"><i class="fas fa-paper-plane"></i> Data Entrega:</small>
                                <span><?php echo formatarData($tarefa['data_resposta']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($tarefa['nota'] !== null): ?>
                            <div class="mt-3 text-center">
                                <div class="nota-circle mx-auto" style="background: <?php echo $nota_color; ?>20; border: 2px solid <?php echo $nota_color; ?>;">
                                    <span style="color: <?php echo $nota_color; ?>;"><?php echo number_format($tarefa['nota'], 1, ',', '.'); ?></span>
                                </div>
                                <small class="text-muted mt-1 d-block">Nota / <?php echo $tarefa['max_pontos']; ?></small>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $percentual_nota; ?>%; background: <?php echo $nota_color; ?>;"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="tarefa-footer">
                    <div>
                        <?php if ($tarefa['material_apoio']): ?>
                        <a href="<?php echo $tarefa['material_apoio']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-download"></i> Material de Apoio
                        </a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($tarefa['status_aluno'] == 'pendente' && strtotime($tarefa['data_entrega']) > time()): ?>
                        <a href="entregar_tarefa.php?id=<?php echo $tarefa['id']; ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-paper-plane"></i> Entregar Tarefa
                        </a>
                        <?php elseif ($tarefa['status_aluno'] == 'entregue_atrasado' || $tarefa['status_aluno'] == 'entregue_no_prazo'): ?>
                        <button class="btn btn-sm btn-secondary" disabled>
                            <i class="fas fa-check"></i> Já Entregue
                        </button>
                        <?php elseif ($tarefa['status_aluno'] == 'pendente' && strtotime($tarefa['data_entrega']) < time()): ?>
                        <button class="btn btn-sm btn-danger" disabled>
                            <i class="fas fa-times"></i> Prazo Expirado
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
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>