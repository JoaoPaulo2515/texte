<?php
// aluno/comunicacao/notificacoes.php - Central de Notificações do Aluno

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
$sql_aluno = "SELECT e.nome, e.matricula, e.foto, tur.nome as turma_nome, tur.ano as turma_ano
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              WHERE e.id = :id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Marcar notificação como lida (AJAX)
if (isset($_POST['action']) && $_POST['action'] == 'marcar_lida') {
    header('Content-Type: application/json');
    $notificacao_id = (int)$_POST['notificacao_id'];
    
    $sql = "UPDATE notificacoes_aluno 
            SET lida = 1, data_leitura = NOW() 
            WHERE id = :id AND aluno_id = :aluno_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute([
        ':id' => $notificacao_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    
    echo json_encode(['success' => $result]);
    exit;
}

// Marcar todas como lidas
if (isset($_POST['marcar_todas'])) {
    $sql = "UPDATE notificacoes_aluno 
            SET lida = 1, data_leitura = NOW() 
            WHERE aluno_id = :aluno_id AND escola_id = :escola_id AND lida = 0";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    header('Location: notificacoes.php');
    exit;
}

// Remover notificação
if (isset($_GET['remover'])) {
    $notificacao_id = (int)$_GET['remover'];
    $sql = "DELETE FROM notificacoes_aluno 
            WHERE id = :id AND aluno_id = :aluno_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $notificacao_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    header('Location: notificacoes.php');
    exit;
}

// ============================================
// FILTROS
// ============================================
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todas';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$busca_filtro = isset($_GET['busca']) ? trim($_GET['busca']) : '';

// ============================================
// BUSCAR NOTIFICAÇÕES
// ============================================

$sql_notificacoes = "SELECT n.*,
                            CASE 
                                WHEN n.tipo = 'aviso' THEN 'Aviso'
                                WHEN n.tipo = 'comunicado' THEN 'Comunicado'
                                WHEN n.tipo = 'lembrete' THEN 'Lembrete'
                                WHEN n.tipo = 'tarefa' THEN 'Tarefa'
                                WHEN n.tipo = 'mensagem' THEN 'Mensagem'
                                WHEN n.tipo = 'sistema' THEN 'Sistema'
                                ELSE 'Geral'
                            END as tipo_label,
                            CASE 
                                WHEN n.prioridade = 'alta' THEN 'danger'
                                WHEN n.prioridade = 'media' THEN 'warning'
                                ELSE 'info'
                            END as prioridade_cor,
                            CASE 
                                WHEN DATEDIFF(NOW(), n.data_envio) = 0 THEN 'Hoje'
                                WHEN DATEDIFF(NOW(), n.data_envio) = 1 THEN 'Ontem'
                                ELSE CONCAT(DATEDIFF(NOW(), n.data_envio), ' dias atrás')
                            END as tempo_relativo
                     FROM notificacoes_aluno n
                     WHERE n.aluno_id = :aluno_id 
                     AND n.escola_id = :escola_id";

if ($tipo_filtro != 'todas') {
    $sql_notificacoes .= " AND n.tipo = :tipo";
}
if ($status_filtro == 'nao_lidas') {
    $sql_notificacoes .= " AND n.lida = 0";
} elseif ($status_filtro == 'lidas') {
    $sql_notificacoes .= " AND n.lida = 1";
}
if (!empty($busca_filtro)) {
    $sql_notificacoes .= " AND (n.titulo LIKE :busca OR n.mensagem LIKE :busca)";
}

$sql_notificacoes .= " ORDER BY n.prioridade = 'alta' DESC, n.data_envio DESC, n.id DESC";

$stmt_notificacoes = $conn->prepare($sql_notificacoes);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
];
if ($tipo_filtro != 'todas') {
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($busca_filtro)) {
    $params[':busca'] = "%$busca_filtro%";
}
$stmt_notificacoes->execute($params);
$notificacoes = $stmt_notificacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================

$total_notificacoes = count($notificacoes);
$total_nao_lidas = count(array_filter($notificacoes, function($n) { 
    return $n['lida'] == 0; 
}));
$total_lidas = $total_notificacoes - $total_nao_lidas;

// Estatísticas por tipo
$stats_tipo = [];
foreach ($notificacoes as $n) {
    $tipo = $n['tipo'];
    if (!isset($stats_tipo[$tipo])) {
        $stats_tipo[$tipo] = ['total' => 0, 'nao_lidas' => 0];
    }
    $stats_tipo[$tipo]['total']++;
    if ($n['lida'] == 0) {
        $stats_tipo[$tipo]['nao_lidas']++;
    }
}

// Notificações recentes (últimos 7 dias)
$notificacoes_recentes = array_filter($notificacoes, function($n) {
    return strtotime($n['data_envio']) > strtotime('-7 days');
});


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Notificações | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .notificacao-item {
            transition: all 0.3s;
            cursor: pointer;
            border-left: 4px solid transparent;
        }
        .notificacao-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        .notificacao-nao-lida {
            background: #f0fdf4;
            border-left-color: #006B3E;
        }
        .notificacao-lida {
            opacity: 0.7;
        }
        .notificacao-alta {
            border-left-color: #dc3545 !important;
        }
        .notificacao-media {
            border-left-color: #ffc107 !important;
        }
        
        .tipo-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .tipo-aviso { background: #17a2b8; color: white; }
        .tipo-comunicado { background: #28a745; color: white; }
        .tipo-lembrete { background: #ffc107; color: #000; }
        .tipo-tarefa { background: #fd7e14; color: white; }
        .tipo-mensagem { background: #6f42c1; color: white; }
        .tipo-sistema { background: #6c757d; color: white; }
        
        .btn-marcar-lida { transition: all 0.3s; }
        .btn-marcar-lida:hover { transform: scale(1.05); }
        
        .filtro-btn.active {
            background: #006B3E !important;
            color: white !important;
            border-color: #006B3E !important;
        }
        
        @media print {
            .no-print { display: none; }
            .notificacao-item { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
   <?php include 'includes/menu_aluno.php'; ?>
   
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-bell"></i> Central de Notificações</h2>
                <p class="text-muted">Acompanhe avisos, comunicados e mensagens importantes</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <?php if ($total_nao_lidas > 0): ?>
                <form method="POST" class="d-inline">
                    <button type="submit" name="marcar_todas" class="btn btn-outline-success" onclick="return confirm('Marcar todas as notificações como lidas?')">
                        <i class="fas fa-check-double"></i> Marcar todas como lidas
                    </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-outline-primary ms-2" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_notificacoes; ?></div>
                    <div class="stat-label"><i class="fas fa-envelope"></i> Total de Notificações</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_nao_lidas; ?></div>
                    <div class="stat-label"><i class="fas fa-bell"></i> Não Lidas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_lidas; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Lidas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo count($notificacoes_recentes); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-week"></i> Últimos 7 dias</div>
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
                        <label class="form-label fw-bold">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="todas" <?php echo $tipo_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="aviso" <?php echo $tipo_filtro == 'aviso' ? 'selected' : ''; ?>>Avisos</option>
                            <option value="comunicado" <?php echo $tipo_filtro == 'comunicado' ? 'selected' : ''; ?>>Comunicados</option>
                            <option value="lembrete" <?php echo $tipo_filtro == 'lembrete' ? 'selected' : ''; ?>>Lembretes</option>
                            <option value="tarefa" <?php echo $tipo_filtro == 'tarefa' ? 'selected' : ''; ?>>Tarefas</option>
                            <option value="mensagem" <?php echo $tipo_filtro == 'mensagem' ? 'selected' : ''; ?>>Mensagens</option>
                            <option value="sistema" <?php echo $tipo_filtro == 'sistema' ? 'selected' : ''; ?>>Sistema</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="nao_lidas" <?php echo $status_filtro == 'nao_lidas' ? 'selected' : ''; ?>>Não Lidas</option>
                            <option value="lidas" <?php echo $status_filtro == 'lidas' ? 'selected' : ''; ?>>Lidas</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título ou mensagem..." value="<?php echo htmlspecialchars($busca_filtro); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($tipo_filtro != 'todas' || $status_filtro != 'todas' || !empty($busca_filtro)): ?>
                        <a href="notificacoes.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Notificações -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Suas Notificações</h5>
                <small><?php echo $total_notificacoes; ?> notificação(ões) encontrada(s)</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($notificacoes)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <h5>Nenhuma notificação encontrada</h5>
                        <p class="text-muted">Não há notificações com os filtros selecionados.</p>
                        <a href="notificacoes.php" class="btn btn-primary mt-2">
                            <i class="fas fa-sync-alt"></i> Limpar filtros
                        </a>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notificacoes as $notificacao): ?>
                        <div class="list-group-item notificacao-item 
                                    <?php echo $notificacao['lida'] ? 'notificacao-lida' : 'notificacao-nao-lida'; ?> 
                                    notificacao-<?php echo $notificacao['prioridade']; ?>"
                             data-id="<?php echo $notificacao['id']; ?>">
                            <div class="row align-items-center">
                                <div class="col-md-1 text-center">
                                    <?php if ($notificacao['tipo'] == 'aviso'): ?>
                                        <i class="fas fa-bullhorn fa-2x text-info"></i>
                                    <?php elseif ($notificacao['tipo'] == 'comunicado'): ?>
                                        <i class="fas fa-envelope-open-text fa-2x text-success"></i>
                                    <?php elseif ($notificacao['tipo'] == 'lembrete'): ?>
                                        <i class="fas fa-bell fa-2x text-warning"></i>
                                    <?php elseif ($notificacao['tipo'] == 'tarefa'): ?>
                                        <i class="fas fa-tasks fa-2x text-danger"></i>
                                    <?php elseif ($notificacao['tipo'] == 'mensagem'): ?>
                                        <i class="fas fa-comment-dots fa-2x text-purple"></i>
                                    <?php else: ?>
                                        <i class="fas fa-info-circle fa-2x text-secondary"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-8">
                                    <div class="d-flex align-items-center mb-1 flex-wrap">
                                        <span class="tipo-badge tipo-<?php echo $notificacao['tipo']; ?> me-2">
                                            <?php echo $notificacao['tipo_label']; ?>
                                        </span>
                                        <?php if ($notificacao['prioridade'] == 'alta'): ?>
                                        <span class="badge bg-danger me-2">
                                            <i class="fas fa-exclamation-triangle"></i> Urgente
                                        </span>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <i class="far fa-clock"></i> <?php echo $notificacao['tempo_relativo']; ?>
                                        </small>
                                        <?php if (!$notificacao['lida']): ?>
                                        <span class="badge bg-success ms-2">Nova</span>
                                        <?php endif; ?>
                                    </div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($notificacao['titulo']); ?></h6>
                                    <p class="mb-1 text-muted small">
                                        <?php echo htmlspecialchars(substr($notificacao['mensagem'], 0, 150)) . (strlen($notificacao['mensagem']) > 150 ? '...' : ''); ?>
                                    </p>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt"></i> 
                                        <?php echo date('d/m/Y H:i', strtotime($notificacao['data_envio'])); ?>
                                        <?php if ($notificacao['data_leitura']): ?>
                                        | <i class="fas fa-check-circle text-success"></i> 
                                        Lida em <?php echo date('d/m/Y H:i', strtotime($notificacao['data_leitura'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-md-3 text-end no-print">
                                    <?php if (!$notificacao['lida']): ?>
                                    <button class="btn btn-sm btn-outline-success btn-marcar-lida me-1" 
                                            onclick="marcarLida(<?php echo $notificacao['id']; ?>)">
                                        <i class="fas fa-check"></i> Marcar lida
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-info me-1" 
                                            onclick="verDetalhes(<?php echo $notificacao['id']; ?>, '<?php echo addslashes($notificacao['titulo']); ?>', '<?php echo addslashes($notificacao['mensagem']); ?>', '<?php echo $notificacao['data_envio']; ?>')">
                                        <i class="fas fa-eye"></i> Ver
                                    </button>
                                    <a href="?remover=<?php echo $notificacao['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Remover esta notificação?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Estatísticas por Tipo -->
        <?php if (!empty($stats_tipo)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Resumo por Tipo</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($stats_tipo as $tipo => $stats): ?>
                    <?php
                    $percentual = $stats['total'] > 0 ? ($stats['nao_lidas'] / $stats['total']) * 100 : 0;
                    $tipo_label = [
                        'aviso' => 'Avisos',
                        'comunicado' => 'Comunicados',
                        'lembrete' => 'Lembretes',
                        'tarefa' => 'Tarefas',
                        'mensagem' => 'Mensagens',
                        'sistema' => 'Sistema'
                    ][$tipo] ?? ucfirst($tipo);
                    ?>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-<?php echo $tipo == 'aviso' ? 'bullhorn' : ($tipo == 'comunicado' ? 'envelope' : ($tipo == 'lembrete' ? 'bell' : ($tipo == 'tarefa' ? 'tasks' : 'info-circle'))); ?>"></i>
                                <?php echo $tipo_label; ?>
                            </span>
                            <span>
                                <?php echo $stats['nao_lidas']; ?>/<?php echo $stats['total']; ?> não lidas
                            </span>
                        </div>
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar bg-warning" role="progressbar" 
                                 style="width: <?php echo $percentual; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Detalhes -->
    <div class="modal fade" id="detalhesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" id="modalHeader">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Notificação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Conteúdo carregado dinamicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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
        
        // Marcar notificação como lida (AJAX)
        function marcarLida(notificacaoId) {
            $.ajax({
                url: 'notificacoes.php',
                method: 'POST',
                data: {
                    action: 'marcar_lida',
                    notificacao_id: notificacaoId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erro ao marcar notificação como lida.');
                    }
                },
                error: function() {
                    alert('Erro ao processar requisição.');
                }
            });
        }
        
        // Ver detalhes da notificação
        function verDetalhes(id, titulo, mensagem, dataEnvio) {
            let modalBody = `
                <div class="mb-3">
                    <small class="text-muted">Data de envio: ${new Date(dataEnvio).toLocaleString('pt-BR')}</small>
                </div>
                <div class="alert alert-light">
                    <h6>${titulo}</h6>
                    <p class="mt-3">${mensagem.replace(/\n/g, '<br>')}</p>
                </div>
            `;
            
            $('#modalBody').html(modalBody);
            new bootstrap.Modal(document.getElementById('detalhesModal')).show();
            
            // Marcar como lida automaticamente ao visualizar
            $.ajax({
                url: 'notificacoes.php',
                method: 'POST',
                data: {
                    action: 'marcar_lida',
                    notificacao_id: id
                },
                dataType: 'json'
            });
        }
        
        // Atualizar contador de não lidas no menu
        function atualizarContadorNotificacoes() {
            $.ajax({
                url: 'ajax_contador_notificacoes.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.total_nao_lidas > 0) {
                        $('.badge-notificacao').text(response.total_nao_lidas).show();
                    } else {
                        $('.badge-notificacao').hide();
                    }
                }
            });
        }
        
        // Auto-refresh a cada 5 minutos
        setInterval(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>