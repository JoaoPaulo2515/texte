<?php
// escola/suporte/tutoriais.php - Central de Vídeos Tutoriais

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$nivel_filtro = isset($_GET['nivel']) ? $_GET['nivel'] : 'todos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Admin: Adicionar tutorial
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_tutorial'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $nivel = $_POST['nivel'] ?? 'iniciante';
    $url_video = trim($_POST['url_video'] ?? '');
    $duracao = trim($_POST['duracao'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título do tutorial é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição do tutorial é obrigatória.";
    } elseif (empty($url_video)) {
        $error = "A URL do vídeo é obrigatória.";
    } else {
        // Extrair ID do vídeo (YouTube ou Vimeo)
        $video_id = '';
        $plataforma = 'youtube';
        
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/', $url_video, $matches)) {
            $video_id = $matches[1];
            $plataforma = 'youtube';
            $embed_url = "https://www.youtube.com/embed/" . $video_id;
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $url_video, $matches)) {
            $video_id = $matches[1];
            $plataforma = 'vimeo';
            $embed_url = "https://player.vimeo.com/video/" . $video_id;
        } else {
            $embed_url = $url_video;
            $plataforma = 'outro';
        }
        
        try {
            $sql = "INSERT INTO tutoriais (titulo, descricao, categoria, nivel, url_video, embed_url, plataforma, video_id, duracao, escola_id, ativo, created_at) 
                    VALUES (:titulo, :descricao, :categoria, :nivel, :url_video, :embed_url, :plataforma, :video_id, :duracao, :escola_id, 1, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':nivel' => $nivel,
                ':url_video' => $url_video,
                ':embed_url' => $embed_url,
                ':plataforma' => $plataforma,
                ':video_id' => $video_id,
                ':duracao' => $duracao,
                ':escola_id' => $escola_id
            ]);
            $success = "Tutorial adicionado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao adicionar tutorial: " . $e->getMessage();
        }
    }
}

// Admin: Editar tutorial
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_tutorial'])) {
    $tutorial_id = (int)$_POST['tutorial_id'];
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $nivel = $_POST['nivel'] ?? 'iniciante';
    $url_video = trim($_POST['url_video'] ?? '');
    $duracao = trim($_POST['duracao'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição é obrigatória.";
    } elseif (empty($url_video)) {
        $error = "A URL do vídeo é obrigatória.";
    } else {
        $video_id = '';
        $plataforma = 'youtube';
        
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/', $url_video, $matches)) {
            $video_id = $matches[1];
            $plataforma = 'youtube';
            $embed_url = "https://www.youtube.com/embed/" . $video_id;
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $url_video, $matches)) {
            $video_id = $matches[1];
            $plataforma = 'vimeo';
            $embed_url = "https://player.vimeo.com/video/" . $video_id;
        } else {
            $embed_url = $url_video;
            $plataforma = 'outro';
        }
        
        try {
            $sql = "UPDATE tutoriais SET titulo = :titulo, descricao = :descricao, categoria = :categoria, nivel = :nivel, url_video = :url_video, embed_url = :embed_url, plataforma = :plataforma, video_id = :video_id, duracao = :duracao, updated_at = NOW() 
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':nivel' => $nivel,
                ':url_video' => $url_video,
                ':embed_url' => $embed_url,
                ':plataforma' => $plataforma,
                ':video_id' => $video_id,
                ':duracao' => $duracao,
                ':id' => $tutorial_id,
                ':escola_id' => $escola_id
            ]);
            $success = "Tutorial atualizado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar tutorial: " . $e->getMessage();
        }
    }
}

// Admin: Excluir tutorial
if (($is_admin || $is_suporte) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $tutorial_id = (int)$_GET['excluir'];
    try {
        $sql = "DELETE FROM tutoriais WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $tutorial_id, ':escola_id' => $escola_id]);
        $success = "Tutorial excluído com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao excluir tutorial: " . $e->getMessage();
    }
}

// Admin: Alternar status
if (($is_admin || $is_suporte) && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tutorial_id = (int)$_GET['toggle'];
    try {
        $sql = "UPDATE tutoriais SET ativo = NOT ativo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $tutorial_id, ':escola_id' => $escola_id]);
        $success = "Status alterado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao alterar status: " . $e->getMessage();
    }
}

// Registrar visualização
if (isset($_GET['visualizar']) && is_numeric($_GET['visualizar'])) {
    $tutorial_id = (int)$_GET['visualizar'];
    $sql = "UPDATE tutoriais SET visualizacoes = visualizacoes + 1 WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tutorial_id]);
}

// Buscar tutoriais
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "ativo = 1";
}

if ($categoria_filtro != 'todos') {
    $where_conditions[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if ($nivel_filtro != 'todos') {
    $where_conditions[] = "nivel = :nivel";
    $params[':nivel'] = $nivel_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(titulo LIKE :busca OR descricao LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_tutoriais = "SELECT * FROM tutoriais $where_sql ORDER BY destaque DESC, ordem ASC, visualizacoes DESC, id ASC";
$stmt_tutoriais = $conn->prepare($sql_tutoriais);
$stmt_tutoriais->execute($params);
$tutoriais = $stmt_tutoriais->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$tutoriais_por_categoria = [];
$categorias = [
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop', 'cor' => 'primary', 'descricao' => 'Tutoriais sobre o funcionamento geral do sistema'],
    'notas' => ['nome' => 'Lançamento de Notas', 'icone' => 'fas fa-edit', 'cor' => 'success', 'descricao' => 'Como lançar e gerenciar notas'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate', 'cor' => 'info', 'descricao' => 'Processos de matrícula e rematrícula'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill', 'cor' => 'danger', 'descricao' => 'Gestão financeira e pagamentos'],
    'relatorios' => ['nome' => 'Relatórios', 'icone' => 'fas fa-chart-bar', 'cor' => 'warning', 'descricao' => 'Emissão de relatórios e boletins'],
    'perfil' => ['nome' => 'Perfil', 'icone' => 'fas fa-user', 'cor' => 'secondary', 'descricao' => 'Configurações do perfil do usuário']
];

$niveis = [
    'iniciante' => ['nome' => 'Iniciante', 'icone' => 'fas fa-star', 'cor' => 'success'],
    'intermediario' => ['nome' => 'Intermediário', 'icone' => 'fas fa-star-half-alt', 'cor' => 'warning'],
    'avancado' => ['nome' => 'Avançado', 'icone' => 'fas fa-star-of-life', 'cor' => 'danger']
];

foreach ($tutoriais as $tutorial) {
    $cat = $tutorial['categoria'];
    if (!isset($tutoriais_por_categoria[$cat])) {
        $tutoriais_por_categoria[$cat] = [];
    }
    $tutoriais_por_categoria[$cat][] = $tutorial;
}

// Estatísticas
$total_tutoriais = count($tutoriais);
$total_visualizacoes = array_sum(array_column($tutoriais, 'visualizacoes'));

// Funções auxiliares
function getCategoriaInfo($categoria) {
    global $categorias;
    return $categorias[$categoria] ?? ['nome' => ucfirst($categoria), 'icone' => 'fas fa-folder', 'cor' => 'secondary', 'descricao' => ''];
}

function getNivelInfo($nivel) {
    global $niveis;
    return $niveis[$nivel] ?? ['nome' => ucfirst($nivel), 'icone' => 'fas fa-circle', 'cor' => 'secondary'];
}

function getPlataformaIcone($plataforma) {
    switch ($plataforma) {
        case 'youtube': return '<i class="fab fa-youtube text-danger"></i>';
        case 'vimeo': return '<i class="fab fa-vimeo text-primary"></i>';
        default: return '<i class="fas fa-video"></i>';
    }
}

function getStatusBadge($ativo) {
    return $ativo == 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
}

function formatarData($data) {
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}

function formatarDuracao($duracao) {
    if (empty($duracao)) return '';
    return '<i class="fas fa-clock"></i> ' . $duracao;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vídeos Tutoriais | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary-custom { background: #006B3E; border: none; }
        .btn-primary-custom:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .categoria-card { transition: all 0.3s ease; cursor: pointer; text-align: center; padding: 15px; border-radius: 12px; height: 100%; }
        .categoria-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .categoria-card.active { background: #006B3E; color: white; }
        .categoria-card.active .categoria-icon { color: white; }
        .categoria-icon { font-size: 2rem; margin-bottom: 10px; color: #006B3E; }
        
        .tutorial-card { transition: all 0.3s ease; height: 100%; cursor: pointer; }
        .tutorial-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .video-thumb { position: relative; background: #000; border-radius: 10px; overflow: hidden; height: 180px; }
        .video-thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.8; transition: 0.3s; }
        .video-thumb:hover img { opacity: 0.6; }
        .play-btn { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 3rem; color: white; text-shadow: 0 2px 5px rgba(0,0,0,0.5); }
        .video-duration { position: absolute; bottom: 10px; right: 10px; background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .tutorial-stats { background: linear-gradient(135deg, #e8f5e9 0%, #e3f2fd 100%); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .admin-actions { position: absolute; top: 15px; right: 15px; z-index: 10; }
        .tutorial-card { position: relative; }
        .search-box { max-width: 500px; margin: 0 auto; }
        .embed-responsive { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; }
        .embed-responsive iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .destaque-badge { position: absolute; top: 15px; left: 15px; z-index: 10; background: #ffc107; color: #333; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2><i class="fas fa-video"></i> Vídeos Tutoriais</h2>
                    <p>Aprenda a usar o sistema com nossos vídeos tutoriais</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="../dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-light ms-2" data-bs-toggle="modal" data-bs-target="#modalAdicionarTutorial">
                            <i class="fas fa-plus"></i> Adicionar Tutorial
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="tutorial-stats">
            <div class="row text-center">
                <div class="col-md-4">
                    <h3 class="text-primary"><?php echo $total_tutoriais; ?></h3>
                    <small>Vídeos Tutoriais</small>
                </div>
                <div class="col-md-4">
                    <h3 class="text-success"><?php echo number_format($total_visualizacoes); ?></h3>
                    <small>Visualizações Totais</small>
                </div>
                <div class="col-md-4">
                    <h3 class="text-info"><?php echo count($tutoriais_por_categoria); ?></h3>
                    <small>Categorias</small>
                </div>
            </div>
        </div>
        
        <!-- Busca -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="search-box">
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Buscar tutoriais..." value="<?php echo htmlspecialchars($busca); ?>">
                        <input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>">
                        <input type="hidden" name="nivel" value="<?php echo $nivel_filtro; ?>">
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i> Buscar</button>
                        <?php if (!empty($busca) || $categoria_filtro != 'todos' || $nivel_filtro != 'todos'): ?>
                            <a href="tutoriais.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Limpar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Filtros Rápidos -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="filter-label"><i class="fas fa-tag"></i> Categorias</label>
                <div class="row g-2">
                    <div class="col-4 col-md-3">
                        <div class="categoria-card <?php echo $categoria_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarCategoria('todos')">
                            <div class="categoria-icon"><i class="fas fa-list-ul"></i></div>
                            <small>Todos</small>
                        </div>
                    </div>
                    <?php foreach ($categorias as $key => $cat): ?>
                        <div class="col-4 col-md-3">
                            <div class="categoria-card <?php echo $categoria_filtro == $key ? 'active' : ''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')">
                                <div class="categoria-icon"><i class="<?php echo $cat['icone']; ?>"></i></div>
                                <small><?php echo $cat['nome']; ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-6">
                <label class="filter-label"><i class="fas fa-signal"></i> Nível de Dificuldade</label>
                <div class="row g-2">
                    <div class="col-4 col-md-3">
                        <div class="categoria-card <?php echo $nivel_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarNivel('todos')">
                            <div class="categoria-icon"><i class="fas fa-list-ul"></i></div>
                            <small>Todos</small>
                        </div>
                    </div>
                    <?php foreach ($niveis as $key => $nivel): ?>
                        <div class="col-4 col-md-3">
                            <div class="categoria-card <?php echo $nivel_filtro == $key ? 'active' : ''; ?>" onclick="filtrarNivel('<?php echo $key; ?>')">
                                <div class="categoria-icon"><i class="<?php echo $nivel['icone']; ?>"></i></div>
                                <small><?php echo $nivel['nome']; ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Lista de Tutoriais -->
        <?php if (empty($tutoriais)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-video-slash fa-3x text-muted mb-3"></i>
                    <h4>Nenhum tutorial encontrado</h4>
                    <p>Não há vídeos tutoriais disponíveis nesta categoria.</p>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAdicionarTutorial">
                            <i class="fas fa-plus"></i> Adicionar Primeiro Tutorial
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($tutoriais as $tutorial): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card tutorial-card" onclick="abrirTutorial(<?php echo $tutorial['id']; ?>, '<?php echo addslashes($tutorial['titulo']); ?>', '<?php echo addslashes($tutorial['descricao']); ?>', '<?php echo $tutorial['embed_url']; ?>', '<?php echo $tutorial['plataforma']; ?>', '<?php echo $tutorial['url_video']; ?>')">
                            <?php if ($is_admin || $is_suporte): ?>
                                <div class="admin-actions">
                                    <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); editarTutorial(<?php echo $tutorial['id']; ?>, '<?php echo addslashes($tutorial['titulo']); ?>', '<?php echo addslashes($tutorial['descricao']); ?>', '<?php echo $tutorial['categoria']; ?>', '<?php echo $tutorial['nivel']; ?>', '<?php echo addslashes($tutorial['url_video']); ?>', '<?php echo $tutorial['duracao']; ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?excluir=<?php echo $tutorial['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); return confirm('Tem certeza que deseja excluir este tutorial?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="?toggle=<?php echo $tutorial['id']; ?>" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation();">
                                        <i class="fas fa-<?php echo $tutorial['ativo'] == 1 ? 'eye-slash' : 'eye'; ?>"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if ($tutorial['destaque'] == 1): ?>
                                <div class="destaque-badge"><i class="fas fa-star"></i> Destaque</div>
                            <?php endif; ?>
                            <div class="video-thumb">
                                <img src="https://img.youtube.com/vi/<?php echo $tutorial['video_id']; ?>/mqdefault.jpg" alt="<?php echo htmlspecialchars($tutorial['titulo']); ?>">
                                <div class="play-btn"><i class="fas fa-play-circle"></i></div>
                                <?php if ($tutorial['duracao']): ?>
                                    <div class="video-duration"><i class="fas fa-clock"></i> <?php echo $tutorial['duracao']; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge bg-<?php echo getCategoriaInfo($tutorial['categoria'])['cor']; ?>">
                                        <?php echo getCategoriaInfo($tutorial['categoria'])['nome']; ?>
                                    </span>
                                    <span class="badge bg-<?php echo getNivelInfo($tutorial['nivel'])['cor']; ?>">
                                        <?php echo getNivelInfo($tutorial['nivel'])['nome']; ?>
                                    </span>
                                </div>
                                <h5 class="card-title"><?php echo htmlspecialchars($tutorial['titulo']); ?></h5>
                                <p class="card-text small text-muted"><?php echo htmlspecialchars(substr($tutorial['descricao'], 0, 100)) . '...'; ?></p>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-eye"></i> <?php echo number_format($tutorial['visualizacoes']); ?> visualizações
                                    </small>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt"></i> <?php echo formatarData($tutorial['created_at']); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Adicionar Tutorial -->
    <?php if ($is_admin || $is_suporte): ?>
    <div class="modal fade" id="modalAdicionarTutorial" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Adicionar Vídeo Tutorial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Título do Tutorial <span class="text-danger">*</span></label>
                            <input type="text" name="titulo" class="form-control" required placeholder="Ex: Como lançar notas no sistema">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <textarea name="descricao" class="form-control" rows="3" required placeholder="Descreva o conteúdo do tutorial..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Categoria</label>
                                <select name="categoria" class="form-select">
                                    <option value="sistema">📀 Sistema</option>
                                    <option value="notas">📝 Lançamento de Notas</option>
                                    <option value="matricula">👨‍🎓 Matrículas</option>
                                    <option value="financeiro">💰 Financeiro</option>
                                    <option value="relatorios">📊 Relatórios</option>
                                    <option value="perfil">👤 Perfil</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nível</label>
                                <select name="nivel" class="form-select">
                                    <option value="iniciante">⭐ Iniciante</option>
                                    <option value="intermediario">⭐ Intermediário</option>
                                    <option value="avancado">⭐⭐⭐ Avançado</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">URL do Vídeo <span class="text-danger">*</span></label>
                                <input type="url" name="url_video" class="form-control" required placeholder="https://www.youtube.com/watch?v=... ou https://vimeo.com/...">
                                <small class="text-muted">Suporta YouTube, Vimeo e outras plataformas</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Duração</label>
                                <input type="text" name="duracao" class="form-control" placeholder="Ex: 5:30">
                                <small class="text-muted">Ex: 10:25, 1:30:00</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="adicionar_tutorial" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Tutorial -->
    <div class="modal fade" id="modalEditarTutorial" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Tutorial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tutorial_id" id="edit_tutorial_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Título</label>
                            <input type="text" name="titulo" id="edit_titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="edit_descricao" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Categoria</label>
                                <select name="categoria" id="edit_categoria" class="form-select">
                                    <option value="sistema">Sistema</option>
                                    <option value="notas">Lançamento de Notas</option>
                                    <option value="matricula">Matrículas</option>
                                    <option value="financeiro">Financeiro</option>
                                    <option value="relatorios">Relatórios</option>
                                    <option value="perfil">Perfil</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nível</label>
                                <select name="nivel" id="edit_nivel" class="form-select">
                                    <option value="iniciante">Iniciante</option>
                                    <option value="intermediario">Intermediário</option>
                                    <option value="avancado">Avançado</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">URL do Vídeo</label>
                                <input type="url" name="url_video" id="edit_url_video" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Duração</label>
                                <input type="text" name="duracao" id="edit_duracao" class="form-control" placeholder="Ex: 5:30">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_tutorial" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Atualizar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Modal Visualizar Tutorial -->
    <div class="modal fade" id="modalVerTutorial" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTutorialTitulo"><i class="fas fa-video"></i> Tutorial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="embed-responsive" id="videoContainer"></div>
                    <div class="mt-3">
                        <p id="modalTutorialDescricao"></p>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <a href="#" id="linkVideo" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="fas fa-external-link-alt"></i> Abrir no YouTube
                                </a>
                            </div>
                            <small class="text-muted" id="modalInfo"></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="tutoriais.php" class="btn btn-primary-custom">Ver mais tutoriais</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Vídeos Tutoriais</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-video"></i> Sobre os Vídeos Tutoriais</h5>
                        <p>Aprenda a usar todas as funcionalidades do sistema através de nossos vídeos tutoriais.</p>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-search"></i> Como encontrar tutoriais</h5>
                        <ul>
                            <li><strong>Busca:</strong> Digite palavras-chave para encontrar tutoriais específicos</li>
                            <li><strong>Categorias:</strong> Filtre por tema (Sistema, Notas, Matrículas, etc.)</li>
                            <li><strong>Nível:</strong> Filtre por dificuldade (Iniciante, Intermediário, Avançado)</li>
                            <li><strong>Clique no vídeo:</strong> Abre o player para assistir</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-play-circle"></i> Como assistir</h5>
                        <ul>
                            <li>Clique no card do tutorial para abrir o player</li>
                            <li>Os vídeos abrem diretamente no sistema (embed)</li>
                            <li>Você também pode abrir no YouTube ou Vimeo clicando no link</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-shield-alt"></i> Para Administradores</h5>
                        <ul>
                            <li>Adicionar, editar e excluir vídeos tutoriais</li>
                            <li>Definir categoria e nível de dificuldade</li>
                            <li>Ativar/desativar vídeos conforme necessidade</li>
                            <li>Acompanhar estatísticas de visualizações</li>
                        </ul>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dica:</strong> Assista todos os tutoriais para aproveitar ao máximo o sistema!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a>
                    <a href="chamados.php" class="btn btn-info"><i class="fas fa-headset"></i> Abrir Chamado</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        document.getElementById('btnAjuda')?.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        });
        
        function filtrarCategoria(categoria) {
            const url = new URL(window.location.href);
            if (categoria === 'todos') {
                url.searchParams.delete('categoria');
            } else {
                url.searchParams.set('categoria', categoria);
            }
            window.location.href = url.toString();
        }
        
        function filtrarNivel(nivel) {
            const url = new URL(window.location.href);
            if (nivel === 'todos') {
                url.searchParams.delete('nivel');
            } else {
                url.searchParams.set('nivel', nivel);
            }
            window.location.href = url.toString();
        }
        
        function registrarVisualizacao(tutorialId) {
            fetch('tutoriais.php?visualizar=' + tutorialId, { method: 'GET' });
        }
        
        function abrirTutorial(id, titulo, descricao, embedUrl, plataforma, urlVideo) {
            document.getElementById('modalTutorialTitulo').innerHTML = '<i class="fas fa-video"></i> ' + titulo;
            document.getElementById('modalTutorialDescricao').innerHTML = descricao;
            document.getElementById('linkVideo').href = urlVideo;
            
            var videoHtml = '';
            if (plataforma === 'youtube') {
                videoHtml = '<iframe src="' + embedUrl + '?autoplay=1&rel=0" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
            } else if (plataforma === 'vimeo') {
                videoHtml = '<iframe src="' + embedUrl + '?autoplay=1" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
            } else {
                videoHtml = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Vídeo disponível no link abaixo.</div>';
            }
            
            document.getElementById('videoContainer').innerHTML = videoHtml;
            document.getElementById('modalInfo').innerHTML = '<i class="fas fa-eye"></i> Registrando visualização...';
            
            new bootstrap.Modal(document.getElementById('modalVerTutorial')).show();
            registrarVisualizacao(id);
            
            // Atualizar texto após registrar
            setTimeout(function() {
                document.getElementById('modalInfo').innerHTML = '<i class="fas fa-check-circle"></i> Visualização registrada';
            }, 1000);
        }
        
        <?php if ($is_admin || $is_suporte): ?>
        function editarTutorial(id, titulo, descricao, categoria, nivel, urlVideo, duracao) {
            document.getElementById('edit_tutorial_id').value = id;
            document.getElementById('edit_titulo').value = titulo;
            document.getElementById('edit_descricao').value = descricao;
            document.getElementById('edit_categoria').value = categoria;
            document.getElementById('edit_nivel').value = nivel;
            document.getElementById('edit_url_video').value = urlVideo;
            document.getElementById('edit_duracao').value = duracao;
            new bootstrap.Modal(document.getElementById('modalEditarTutorial')).show();
        }
        <?php endif; ?>
    </script>
</body>
</html>