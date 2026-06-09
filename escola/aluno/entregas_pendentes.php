<?php
// aluno/tarefas/entregas_pendentes.php - Tarefas Pendentes do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, e.foto 
              FROM estudantes e 
              WHERE e.id = :id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR ENTREGA RÁPIDA (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'entregar_rapida') {
    header('Content-Type: application/json');
    
    $tarefa_id = (int)$_POST['tarefa_id'];
    $resposta_texto = trim($_POST['resposta_texto'] ?? '');
    $anexo_path = null;
    
    // Validar
    if (empty($resposta_texto)) {
        echo json_encode(['success' => false, 'message' => 'Digite uma resposta para a tarefa.']);
        exit;
    }
    
    // Processar anexo
    if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../../uploads/tarefas/respostas/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $extensao = pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION);
        $nome_arquivo = 'resposta_' . $aluno_id . '_' . $tarefa_id . '_' . time() . '.' . $extensao;
        $caminho_arquivo = $upload_dir . $nome_arquivo;
        
        if (move_uploaded_file($_FILES['anexo']['tmp_name'], $caminho_arquivo)) {
            $anexo_path = 'uploads/tarefas/respostas/' . $nome_arquivo;
        }
    }
    
    // Verificar se já existe resposta
    $sql_check = "SELECT id FROM tarefas_respostas 
                  WHERE tarefa_id = :tarefa_id AND aluno_id = :aluno_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([
        ':tarefa_id' => $tarefa_id,
        ':aluno_id' => $aluno_id
    ]);
    
    $existe = $stmt_check->fetch();
    
    // Verificar se está dentro do prazo
    $sql_prazo = "SELECT data_entrega FROM tarefas WHERE id = :tarefa_id";
    $stmt_prazo = $conn->prepare($sql_prazo);
    $stmt_prazo->execute([':tarefa_id' => $tarefa_id]);
    $tarefa = $stmt_prazo->fetch();
    $entregue_apos_prazo = $tarefa && strtotime($tarefa['data_entrega']) < time();
    
    if ($existe) {
        // Atualizar resposta existente
        $sql = "UPDATE tarefas_respostas 
                SET resposta_texto = :resposta_texto, 
                    anexo_path = COALESCE(:anexo_path, anexo_path),
                    data_atualizacao = NOW(),
                    status = 'entregue',
                    tentativas = tentativas + 1,
                    entregue_apos_prazo = :entregue_apos_prazo
                WHERE tarefa_id = :tarefa_id AND aluno_id = :aluno_id";
    } else {
        // Inserir nova resposta
        $sql = "INSERT INTO tarefas_respostas 
                (tarefa_id, aluno_id, resposta_texto, anexo_path, status, entregue_apos_prazo) 
                VALUES (:tarefa_id, :aluno_id, :resposta_texto, :anexo_path, 'entregue', :entregue_apos_prazo)";
    }
    
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute([
        ':tarefa_id' => $tarefa_id,
        ':aluno_id' => $aluno_id,
        ':resposta_texto' => $resposta_texto,
        ':anexo_path' => $anexo_path,
        ':entregue_apos_prazo' => $entregue_apos_prazo ? 1 : 0
    ]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Tarefa entregue com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao entregar tarefa. Tente novamente.']);
    }
    exit;
}

// ============================================
// FILTROS
// ============================================
$disciplina_filtro = isset($_GET['disciplina']) ? (int)$_GET['disciplina'] : 0;
$busca_filtro = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$ordenar = isset($_GET['ordenar']) ? $_GET['ordenar'] : 'prazo';

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome
                    FROM disciplinas d
                    JOIN tarefas t ON t.disciplina_id = d.id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND m.status = 'ativa'
                    AND t.status = 'publicada'
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar tarefas pendentes
$sql_pendentes = "SELECT 
                    t.id,
                    t.titulo,
                    t.descricao,
                    t.data_entrega,
                    t.max_pontos,
                    t.material_apoio,
                    t.permite_anexo,
                    d.id as disciplina_id,
                    d.nome as disciplina_nome,
                    p.id as professor_id,
                    p.nome as professor_nome,
                    r.id as resposta_id,
                    r.tentativas,
                    DATEDIFF(t.data_entrega, NOW()) as dias_restantes,
                    CASE 
                        WHEN t.data_entrega < NOW() THEN 'atrasado'
                        WHEN DATEDIFF(t.data_entrega, NOW()) <= 2 THEN 'urgente'
                        WHEN DATEDIFF(t.data_entrega, NOW()) <= 5 THEN 'proximo'
                        ELSE 'normal'
                    END as urgencia
                  FROM tarefas t
                  JOIN disciplinas d ON d.id = t.disciplina_id
                  JOIN funcionarios p ON p.id = t.professor_id
                  JOIN turmas tur ON tur.id = t.turma_id
                  JOIN matriculas m ON m.turma_id = tur.id
                  LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                  WHERE m.estudante_id = :aluno_id 
                  AND m.status = 'ativa'
                  AND t.status = 'publicada'
                  AND (r.id IS NULL OR (r.status != 'corrigido' AND t.data_entrega >= DATE_SUB(NOW(), INTERVAL 7 DAY)))";

if ($disciplina_filtro > 0) {
    $sql_pendentes .= " AND t.disciplina_id = :disciplina_id";
}

if (!empty($busca_filtro)) {
    $sql_pendentes .= " AND (t.titulo LIKE :busca OR t.descricao LIKE :busca)";
}

// Ordenação
switch ($ordenar) {
    case 'prazo':
        $sql_pendentes .= " ORDER BY t.data_entrega ASC";
        break;
    case 'disciplina':
        $sql_pendentes .= " ORDER BY d.nome ASC, t.data_entrega ASC";
        break;
    case 'urgencia':
        $sql_pendentes .= " ORDER BY CASE 
                            WHEN t.data_entrega < NOW() THEN 1
                            WHEN DATEDIFF(t.data_entrega, NOW()) <= 2 THEN 2
                            WHEN DATEDIFF(t.data_entrega, NOW()) <= 5 THEN 3
                            ELSE 4
                        END, t.data_entrega ASC";
        break;
    default:
        $sql_pendentes .= " ORDER BY t.data_entrega ASC";
}

$stmt_pendentes = $conn->prepare($sql_pendentes);
$params = [':aluno_id' => $aluno_id];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca_filtro)) {
    $params[':busca'] = "%$busca_filtro%";
}
$stmt_pendentes->execute($params);
$tarefas_pendentes = $stmt_pendentes->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_pendentes = count($tarefas_pendentes);
$total_atrasadas = count(array_filter($tarefas_pendentes, function($t) { 
    return $t['urgencia'] == 'atrasado'; 
}));
$total_urgentes = count(array_filter($tarefas_pendentes, function($t) { 
    return $t['urgencia'] == 'urgente'; 
}));
$total_proximas = count(array_filter($tarefas_pendentes, function($t) { 
    return $t['urgencia'] == 'proximo'; 
}));

// Tarefas por disciplina
$tarefas_por_disciplina = [];
foreach ($tarefas_pendentes as $tarefa) {
    $disc_id = $tarefa['disciplina_id'];
    if (!isset($tarefas_por_disciplina[$disc_id])) {
        $tarefas_por_disciplina[$disc_id] = [
            'nome' => $tarefa['disciplina_nome'],
            'cor' => $tarefa['disciplina_cor'],
            'total' => 0,
            'atrasadas' => 0
        ];
    }
    $tarefas_por_disciplina[$disc_id]['total']++;
    if ($tarefa['urgencia'] == 'atrasado') {
        $tarefas_por_disciplina[$disc_id]['atrasadas']++;
    }
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entregas Pendentes | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .tarefa-card { transition: all 0.3s; margin-bottom: 20px; }
        .tarefa-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .urgencia-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .urgencia-atrasado { background: #dc3545; color: white; }
        .urgencia-urgente { background: #ffc107; color: #000; }
        .urgencia-proximo { background: #fd7e14; color: white; }
        .urgencia-normal { background: #28a745; color: white; }
        
        .disciplina-tag { display: inline-block; padding: 3px 10px; border-radius: 15px; font-size: 11px; font-weight: 500; }
        
        .countdown { font-family: monospace; font-size: 1.1em; font-weight: bold; }
        .countdown-danger { color: #dc3545; }
        .countdown-warning { color: #fd7e14; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .entrega-rapida-modal .modal-content { border-radius: 15px; }
        .btn-entregar { transition: all 0.3s; }
        .btn-entregar:hover { transform: scale(1.05); }
        
        @media print {
            .no-print { display: none; }
            .tarefa-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
    </br> </br> </br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-clock"></i> Entregas Pendentes</h2>
                <p class="text-muted">Tarefas que precisam da sua atenção</p>
            </div>
            <div class="no-print">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_pendentes; ?></div>
                    <div class="stat-label"><i class="fas fa-tasks"></i> Total Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $total_atrasadas; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Atrasadas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_urgentes; ?></div>
                    <div class="stat-label"><i class="fas fa-bell"></i> Urgentes (2 dias)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_proximas; ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-week"></i> Próximas (5 dias)</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Disciplina</label>
                        <select name="disciplina" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                            <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Ordenar por</label>
                        <select name="ordenar" class="form-select">
                            <option value="prazo" <?php echo $ordenar == 'prazo' ? 'selected' : ''; ?>>Prazo (mais próximo)</option>
                            <option value="urgencia" <?php echo $ordenar == 'urgencia' ? 'selected' : ''; ?>>Urgência</option>
                            <option value="disciplina" <?php echo $ordenar == 'disciplina' ? 'selected' : ''; ?>>Disciplina</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título ou descrição..." value="<?php echo htmlspecialchars($busca_filtro); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($disciplina_filtro > 0 || !empty($busca_filtro)): ?>
                        <a href="entregas_pendentes.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Resumo por Disciplina -->
        <?php if (!empty($tarefas_por_disciplina)): ?>
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Resumo por Disciplina</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($tarefas_por_disciplina as $disc): ?>
                    <div class="col-md-3 mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="disciplina-tag" style="background: <?php echo $disc['cor'] ?? '#006B3E'; ?>20; color: <?php echo $disc['cor'] ?? '#006B3E'; ?>">
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </span>
                            <span>
                                <?php echo $disc['total']; ?> tarefa(s)
                                <?php if ($disc['atrasadas'] > 0): ?>
                                <span class="text-danger">(<?php echo $disc['atrasadas']; ?> atrasadas)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Lista de Tarefas Pendentes -->
        <?php if (empty($tarefas_pendentes)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h4>Parabéns! 🎉</h4>
                    <p>Você não tem tarefas pendentes no momento.</p>
                    <a href="minhas_tarefas.php" class="btn btn-primary mt-3">
                        <i class="fas fa-tasks"></i> Ver todas as tarefas
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($tarefas_pendentes as $tarefa): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card tarefa-card">
                        <div class="card-body">
                            <!-- Cabeçalho -->
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="disciplina-tag" style="background: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>20; color: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($tarefa['disciplina_nome']); ?>
                                </span>
                                <span class="urgencia-badge urgencia-<?php echo $tarefa['urgencia']; ?>">
                                    <?php if ($tarefa['urgencia'] == 'atrasado'): ?>
                                        <i class="fas fa-exclamation-circle"></i> ATRASADA
                                    <?php elseif ($tarefa['urgencia'] == 'urgente'): ?>
                                        <i class="fas fa-bell"></i> URGENTE
                                    <?php elseif ($tarefa['urgencia'] == 'proximo'): ?>
                                        <i class="fas fa-clock"></i> PRÓXIMA
                                    <?php else: ?>
                                        <i class="fas fa-calendar"></i> NORMAL
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <!-- Título -->
                            <h5 class="card-title mt-2"><?php echo htmlspecialchars($tarefa['titulo']); ?></h5>
                            <p class="card-text small text-muted">
                                <?php echo htmlspecialchars(substr($tarefa['descricao'], 0, 100)) . (strlen($tarefa['descricao']) > 100 ? '...' : ''); ?>
                            </p>
                            
                            <hr>
                            
                            <!-- Prazo -->
                            <div class="mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Entrega:</strong> 
                                <?php echo date('d/m/Y H:i', strtotime($tarefa['data_entrega'])); ?>
                            </div>
                            
                            <!-- Contagem Regressiva -->
                            <div class="mb-3">
                                <?php if ($tarefa['urgencia'] == 'atrasado'): ?>
                                    <div class="alert alert-danger mb-0 py-1 text-center">
                                        <i class="fas fa-exclamation-triangle"></i> Prazo expirado! Entregue o quanto antes.
                                    </div>
                                <?php else: ?>
                                    <div class="countdown countdown-<?php echo $tarefa['urgencia']; ?>" 
                                         data-entrega="<?php echo $tarefa['data_entrega']; ?>">
                                        <i class="fas fa-hourglass-half"></i> 
                                        <span class="dias"><?php echo abs($tarefa['dias_restantes']); ?></span> dias restantes
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Info Adicional -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <i class="fas fa-star text-warning"></i>
                                    <small>Max: <?php echo number_format($tarefa['max_pontos'], 1); ?> pts</small>
                                </div>
                                <div>
                                    <i class="fas fa-user-chalk"></i>
                                    <small><?php echo htmlspecialchars($tarefa['professor_nome']); ?></small>
                                </div>
                            </div>
                            
                            <!-- Botões -->
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary flex-grow-1 btn-entregar" 
                                        onclick="abrirEntregaRapida(<?php echo $tarefa['id']; ?>, '<?php echo addslashes($tarefa['titulo']); ?>', <?php echo $tarefa['permite_anexo'] ? 'true' : 'false'; ?>)">
                                    <i class="fas fa-paper-plane"></i> Entregar
                                </button>
                                <button class="btn btn-outline-secondary" 
                                        onclick="verDetalhes(<?php echo $tarefa['id']; ?>)">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                            </div>
                            
                            <?php if ($tarefa['tentativas'] > 0): ?>
                            <div class="mt-2 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-redo-alt"></i> Tentativas anteriores: <?php echo $tarefa['tentativas']; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Dicas -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Dicas para não perder prazos</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <i class="fas fa-calendar-check fa-2x text-success mb-2"></i>
                        <p><small>Organize seu tempo</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-bell fa-2x text-warning mb-2"></i>
                        <p><small>Ative as notificações</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-mobile-alt fa-2x text-info mb-2"></i>
                        <p><small>Acesse pelo celular</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-check-double fa-2x text-primary mb-2"></i>
                        <p><small>Entregue com antecedência</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Entrega Rápida -->
    <div class="modal fade" id="entregaRapidaModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane"></i> Entrega Rápida</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formEntregaRapida" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong id="tarefaTitulo"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sua Resposta</label>
                            <textarea class="form-control" name="resposta_texto" rows="6" required 
                                      placeholder="Digite sua resposta aqui..."></textarea>
                        </div>
                        
                        <div class="mb-3" id="anexoDiv" style="display: none;">
                            <label class="form-label fw-bold">Anexar Arquivo</label>
                            <input type="file" class="form-control" name="anexo" accept=".pdf,.doc,.docx,.jpg,.png,.zip">
                            <small class="text-muted">Formatos: PDF, DOC, DOCX, JPG, PNG, ZIP. Max: 10MB</small>
                        </div>
                        
                        <input type="hidden" name="action" value="entregar_rapida">
                        <input type="hidden" name="tarefa_id" id="tarefa_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Entregar Tarefa
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalhes -->
    <div class="modal fade" id="detalhesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Tarefa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesConteudo">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Abrir modal de entrega rápida
        function abrirEntregaRapida(tarefaId, titulo, permiteAnexo) {
            document.getElementById('tarefa_id').value = tarefaId;
            document.getElementById('tarefaTitulo').innerHTML = 'Entregando: ' + titulo;
            
            if (permiteAnexo) {
                document.getElementById('anexoDiv').style.display = 'block';
            } else {
                document.getElementById('anexoDiv').style.display = 'none';
            }
            
            document.getElementById('formEntregaRapida').reset();
            new bootstrap.Modal(document.getElementById('entregaRapidaModal')).show();
        }
        
        // Enviar entrega rápida via AJAX
        $('#formEntregaRapida').on('submit', function(e) {
            e.preventDefault();
            
            let formData = new FormData(this);
            
            $.ajax({
                url: 'entregas_pendentes.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.message);
                        location.reload();
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao enviar resposta. Tente novamente.');
                }
            });
        });
        
        // Ver detalhes da tarefa
        function verDetalhes(tarefaId) {
            $('#detalhesConteudo').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            `);
            
            $.ajax({
                url: 'ajax_carregar_tarefa.php',
                method: 'GET',
                data: { id: tarefaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#detalhesConteudo').html(response.html);
                    } else {
                        $('#detalhesConteudo').html('<div class="alert alert-danger">' + response.message + '</div>');
                    }
                },
                error: function() {
                    $('#detalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar detalhes.</div>');
                }
            });
            
            new bootstrap.Modal(document.getElementById('detalhesModal')).show();
        }
        
        // Atualizar contagem regressiva
        function atualizarCountdowns() {
            document.querySelectorAll('.countdown').forEach(el => {
                let dataEntrega = new Date(el.dataset.entrega);
                let agora = new Date();
                let diff = dataEntrega - agora;
                let dias = Math.ceil(diff / (1000 * 60 * 60 * 24));
                
                if (dias > 0) {
                    el.innerHTML = `<i class="fas fa-hourglass-half"></i> <span class="dias">${dias}</span> dias restantes`;
                } else if (dias === 0) {
                    el.innerHTML = `<i class="fas fa-hourglass-end"></i> Último dia!`;
                    el.classList.add('countdown-warning');
                } else {
                    el.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Prazo expirado!`;
                    el.classList.add('countdown-danger');
                }
            });
        }
        
        // Atualizar a cada hora
        atualizarCountdowns();
        setInterval(atualizarCountdowns, 3600000);
    </script>
</body>
</html>