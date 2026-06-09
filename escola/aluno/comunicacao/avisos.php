<?php
// escola/aluno/comunicacao/avisos.php - Avisos e Comunicados do Aluno

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
$titulo_pagina = 'Avisos e Comunicados';

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
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR AVISOS
// ==============================================
$sql_avisos = "SELECT 
                    a.id,
                    a.titulo,
                    a.conteudo,
                    a.categoria,
                    a.prioridade,
                    a.data_inicio,
                    a.data_fim,
                    a.anexo_path,
                    a.created_at,
                    a.updated_at,
                    u.nome as autor_nome,
                    CASE 
                        WHEN a.prioridade = 'alta' THEN 'danger'
                        WHEN a.prioridade = 'media' THEN 'warning'
                        ELSE 'info'
                    END as cor_prioridade
                FROM comunicados a
                LEFT JOIN usuarios u ON u.id = a.usuario_id
                WHERE a.escola_id = :escola_id
                AND a.status = 'ativo'
                AND (a.data_inicio <= NOW() OR a.data_inicio IS NULL)
                AND (a.data_fim >= NOW() OR a.data_fim IS NULL)
                AND (a.turma_id IS NULL OR a.turma_id = :turma_id)";

if ($categoria_filtro != 'todos') {
    $sql_avisos .= " AND a.categoria = :categoria";
}
if ($status_filtro == 'nao_lidos') {
    $sql_avisos .= " AND a.id NOT IN (SELECT aviso_id FROM avisos_lidos WHERE aluno_id = :aluno_id)";
}
if (!empty($busca)) {
    $sql_avisos .= " AND (a.titulo LIKE :busca OR a.conteudo LIKE :busca)";
}

$sql_avisos .= " ORDER BY a.prioridade = 'alta' DESC, a.created_at DESC";

$stmt_avisos = $conn->prepare($sql_avisos);
$params = [
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id
];
if ($categoria_filtro != 'todos') {
    $params[':categoria'] = $categoria_filtro;
}
if ($status_filtro == 'nao_lidos') {
    $params[':aluno_id'] = $aluno_id;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_avisos->execute($params);
$avisos = $stmt_avisos->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// MARCAR AVISOS COMO LIDOS
// ==============================================
foreach ($avisos as $aviso) {
    // Verificar se já foi lido
    $sql_check = "SELECT id FROM avisos_lidos WHERE aviso_id = :aviso_id AND aluno_id = :aluno_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':aviso_id' => $aviso['id'], ':aluno_id' => $aluno_id]);
    
    if ($stmt_check->rowCount() == 0) {
        // Marcar como lido
        $sql_insert = "INSERT INTO avisos_lidos (aviso_id, aluno_id, data_leitura) VALUES (:aviso_id, :aluno_id, NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([':aviso_id' => $aviso['id'], ':aluno_id' => $aluno_id]);
    }
}

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_avisos = count($avisos);
$total_avisos_nao_lidos = 0;

$sql_nao_lidos = "SELECT COUNT(*) as total FROM comunicados a
                  WHERE a.escola_id = :escola_id
                  AND a.status = 'ativo'
                  AND (a.turma_id IS NULL OR a.turma_id = :turma_id)
                  AND a.id NOT IN (SELECT aviso_id FROM avisos_lidos WHERE aluno_id = :aluno_id)";
$stmt_nao_lidos = $conn->prepare($sql_nao_lidos);
$stmt_nao_lidos->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id,
    ':aluno_id' => $aluno_id
]);
$total_avisos_nao_lidos = $stmt_nao_lidos->fetch(PDO::FETCH_ASSOC)['total'];

// Categorias para filtro
$categorias = [
    'geral' => 'Geral',
    'academico' => 'Acadêmico',
    'financeiro' => 'Financeiro',
    'eventos' => 'Eventos',
    'urgente' => 'Urgente',
    'feriados' => 'Feriados',
    'matricula' => 'Matrícula',
    'outro' => 'Outro'
];

// Funções auxiliares
function getCategoriaIcone($categoria) {
    $icones = [
        'geral' => '<i class="fas fa-bullhorn"></i>',
        'academico' => '<i class="fas fa-graduation-cap"></i>',
        'financeiro' => '<i class="fas fa-money-bill-wave"></i>',
        'eventos' => '<i class="fas fa-calendar-alt"></i>',
        'urgente' => '<i class="fas fa-exclamation-triangle"></i>',
        'feriados' => '<i class="fas fa-umbrella-beach"></i>',
        'matricula' => '<i class="fas fa-id-card"></i>',
        'outro' => '<i class="fas fa-envelope"></i>'
    ];
    return $icones[$categoria] ?? '<i class="fas fa-bell"></i>';
}

function getCategoriaLabel($categoria) {
    $labels = [
        'geral' => 'Geral',
        'academico' => 'Acadêmico',
        'financeiro' => 'Financeiro',
        'eventos' => 'Eventos',
        'urgente' => 'Urgente',
        'feriados' => 'Feriados',
        'matricula' => 'Matrícula',
        'outro' => 'Outro'
    ];
    return $labels[$categoria] ?? ucfirst($categoria);
}

function getPrioridadeBadge($prioridade) {
    switch ($prioridade) {
        case 'alta':
            return '<span class="badge bg-danger"><i class="fas fa-flag"></i> Urgente</span>';
        case 'media':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-flag"></i> Importante</span>';
        case 'baixa':
            return '<span class="badge bg-info"><i class="fas fa-flag"></i> Informativo</span>';
        default:
            return '<span class="badge bg-secondary">Normal</span>';
    }
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
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
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .aviso-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .aviso-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .aviso-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            cursor: pointer;
        }
        .aviso-header:hover {
            background: #f8f9fa;
        }
        .aviso-body {
            padding: 20px;
            display: none;
        }
        .aviso-body.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        .aviso-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .categoria-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .categoria-geral { background: #6c757d20; color: #6c757d; }
        .categoria-academico { background: #006B3E20; color: #006B3E; }
        .categoria-financeiro { background: #28a74520; color: #28a745; }
        .categoria-eventos { background: #17a2b820; color: #17a2b8; }
        .categoria-urgente { background: #dc354520; color: #dc3545; }
        .categoria-feriados { background: #fd7e1420; color: #fd7e14; }
        .categoria-matricula { background: #6610f220; color: #6610f2; }
        
        .priority-bar {
            height: 4px;
            width: 100%;
        }
        .priority-alta { background: #dc3545; }
        .priority-media { background: #ffc107; }
        .priority-baixa { background: #17a2b8; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
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
            .aviso-card { break-inside: avoid; page-break-inside: avoid; }
            .aviso-body { display: block !important; }
        }
        
        .aviso-nao-lido {
            background: #f0f7ff;
            border-left: 4px solid #006B3E;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
        }
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>

<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Avisos e Comunicados</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todos os avisos e comunicados da escola para os alunos.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Prioridades</div>
                <div class="ajuda-texto">
                    <span class="badge bg-danger">Urgente</span> - Ação imediata necessária<br>
                    <span class="badge bg-warning">Importante</span> - Atenção recomendada<br>
                    <span class="badge bg-info">Informativo</span> - Apenas para conhecimento
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Categorias</div>
                <div class="ajuda-texto">Filtre por categoria para encontrar avisos específicos (Acadêmico, Financeiro, Eventos, etc.).</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Visualização</div>
                <div class="ajuda-texto">Clique em qualquer aviso para expandir e ler o conteúdo completo.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-bullhorn"></i> Avisos e Comunicados</h4>
            <p class="text-muted mb-0">Fique por dentro de todas as novidades da escola</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_avisos; ?></div>
                <div class="stat-label"><i class="fas fa-envelope-open-text text-success"></i> Total de Avisos</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_avisos_nao_lidos; ?></div>
                <div class="stat-label"><i class="fas fa-envelope text-primary"></i> Novos Avisos</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Categoria</label>
                    <select name="categoria" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $categoria_filtro == 'todos' ? 'selected' : ''; ?>>Todas as categorias</option>
                        <?php foreach ($categorias as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $categoria_filtro == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="nao_lidos" <?php echo $status_filtro == 'nao_lidos' ? 'selected' : ''; ?>>Não lidos</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título ou conteúdo..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="avisos.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Avisos -->
    <?php if (empty($avisos)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhum aviso encontrado</h5>
            <p>Não foram encontrados avisos para os filtros selecionados.<?php echo $turma_id; ?></p>
        </div>
    <?php else: ?>
        <div class="avisos-list">
            <?php foreach ($avisos as $aviso): ?>
            <div class="aviso-card fade-in" id="aviso-<?php echo $aviso['id']; ?>">
                <div class="priority-bar priority-<?php echo $aviso['prioridade']; ?>"></div>
                <div class="aviso-header" onclick="toggleAviso(<?php echo $aviso['id']; ?>)">
                    <div style="flex: 1;">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="categoria-badge categoria-<?php echo $aviso['categoria']; ?>">
                                <?php echo getCategoriaIcone($aviso['categoria']); ?> <?php echo getCategoriaLabel($aviso['categoria']); ?>
                            </span>
                            <?php echo getPrioridadeBadge($aviso['prioridade']); ?>
                        </div>
                        <h5 class="mb-1"><?php echo htmlspecialchars($aviso['titulo']); ?></h5>
                        <div class="d-flex gap-3 flex-wrap">
                            <small class="text-muted">
                                <i class="fas fa-user"></i> Por: <?php echo htmlspecialchars($aviso['autor_nome'] ?? 'Administração'); ?>
                            </small>
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt"></i> Publicado: <?php echo formatarData($aviso['created_at']); ?>
                            </small>
                            <?php if ($aviso['data_fim']): ?>
                            <small class="text-muted">
                                <i class="fas fa-hourglass-end"></i> Válido até: <?php echo formatarData($aviso['data_fim'], 'd/m/Y'); ?>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <i class="fas fa-chevron-down toggle-icon" id="toggle-icon-<?php echo $aviso['id']; ?>"></i>
                    </div>
                </div>
                
                <div class="aviso-body" id="aviso-body-<?php echo $aviso['id']; ?>">
                    <div class="mb-3">
                        <?php echo nl2br(htmlspecialchars($aviso['conteudo'])); ?>
                    </div>
                    
                    <?php if ($aviso['anexo_path']): ?>
                    <div class="mt-3">
                        <a href="<?php echo $aviso['anexo_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-paperclip"></i> Baixar Anexo
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3 text-muted small">
                        <i class="fas fa-clock"></i> Última atualização: <?php echo formatarData($aviso['updated_at']); ?>
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
    
    // Função para expandir/colapsar aviso
    function toggleAviso(id) {
        const body = document.getElementById('aviso-body-' + id);
        const icon = document.getElementById('toggle-icon-' + id);
        
        body.classList.toggle('show');
        icon.classList.toggle('rotated');
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
    
    // Expandir aviso específico via URL (se tiver ?aviso=id)
    const urlParams = new URLSearchParams(window.location.search);
    const avisoId = urlParams.get('aviso');
    if (avisoId) {
        const body = document.getElementById('aviso-body-' + avisoId);
        const icon = document.getElementById('toggle-icon-' + avisoId);
        if (body) {
            body.classList.add('show');
            icon.classList.add('rotated');
            document.getElementById('aviso-' + avisoId)?.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    // Armazenar avisos lidos no localStorage (opcional)
    function marcarComoLido(id) {
        let lidos = JSON.parse(localStorage.getItem('avisos_lidos') || '[]');
        if (!lidos.includes(id)) {
            lidos.push(id);
            localStorage.setItem('avisos_lidos', JSON.stringify(lidos));
        }
    }
</script>
</body>
</html>