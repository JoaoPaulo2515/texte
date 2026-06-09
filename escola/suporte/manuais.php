<?php
// escola/suporte/manuais.php - Central de Manuais e Documentação

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
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Admin: Adicionar manual
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_manual'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $tipo = $_POST['tipo'] ?? 'pdf';
    $url = trim($_POST['url'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título do manual é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição do manual é obrigatória.";
    } elseif (empty($url) && !isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] != 0) {
        $error = "Forneça um link ou faça upload de um arquivo.";
    } else {
        try {
            $arquivo_nome = null;
            if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0) {
                $extensoes_permitidas = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'jpg', 'png'];
                $extensao = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                if (in_array($extensao, $extensoes_permitidas)) {
                    $diretorio = __DIR__ . '/../../uploads/manuais/';
                    if (!file_exists($diretorio)) mkdir($diretorio, 0777, true);
                    $arquivo_nome = 'manual_' . time() . '_' . uniqid() . '.' . $extensao;
                    move_uploaded_file($_FILES['arquivo']['tmp_name'], $diretorio . $arquivo_nome);
                    $url = '../../uploads/manuais/' . $arquivo_nome;
                } else {
                    $error = "Formato de arquivo não permitido.";
                }
            }
            
            if (empty($error)) {
                $sql = "INSERT INTO manuais (titulo, descricao, categoria, tipo, url, arquivo, escola_id, ativo, created_at) 
                        VALUES (:titulo, :descricao, :categoria, :tipo, :url, :arquivo, :escola_id, 1, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':categoria' => $categoria,
                    ':tipo' => $tipo,
                    ':url' => $url,
                    ':arquivo' => $arquivo_nome,
                    ':escola_id' => $escola_id
                ]);
                $success = "Manual adicionado com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao adicionar manual: " . $e->getMessage();
        }
    }
}

// Admin: Editar manual
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_manual'])) {
    $manual_id = (int)$_POST['manual_id'];
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'sistema';
    $tipo = $_POST['tipo'] ?? 'pdf';
    $url = trim($_POST['url'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição é obrigatória.";
    } else {
        try {
            $sql = "UPDATE manuais SET titulo = :titulo, descricao = :descricao, categoria = :categoria, tipo = :tipo, url = :url, updated_at = NOW() 
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':tipo' => $tipo,
                ':url' => $url,
                ':id' => $manual_id,
                ':escola_id' => $escola_id
            ]);
            $success = "Manual atualizado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar manual: " . $e->getMessage();
        }
    }
}

// Admin: Excluir manual
if (($is_admin || $is_suporte) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $manual_id = (int)$_GET['excluir'];
    try {
        // Buscar arquivo para deletar
        $sql = "SELECT arquivo FROM manuais WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $manual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manual && $manual['arquivo']) {
            $arquivo_path = __DIR__ . '/../../uploads/manuais/' . $manual['arquivo'];
            if (file_exists($arquivo_path)) unlink($arquivo_path);
        }
        
        $sql = "DELETE FROM manuais WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $success = "Manual excluído com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao excluir manual: " . $e->getMessage();
    }
}

// Admin: Alternar status
if (($is_admin || $is_suporte) && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $manual_id = (int)$_GET['toggle'];
    try {
        $sql = "UPDATE manuais SET ativo = NOT ativo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $manual_id, ':escola_id' => $escola_id]);
        $success = "Status alterado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao alterar status: " . $e->getMessage();
    }
}

// Registrar download
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $manual_id = (int)$_GET['download'];
    $sql = "UPDATE manuais SET downloads = downloads + 1 WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $manual_id]);
}

// Buscar manuais
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "ativo = 1";
}

if ($categoria_filtro != 'todos') {
    $where_conditions[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(titulo LIKE :busca OR descricao LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_manuais = "SELECT * FROM manuais $where_sql ORDER BY ordem ASC, downloads DESC, id ASC";
$stmt_manuais = $conn->prepare($sql_manuais);
$stmt_manuais->execute($params);
$manuais = $stmt_manuais->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$manuais_por_categoria = [];
$categorias = [
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop', 'cor' => 'primary', 'descricao' => 'Manuais sobre o funcionamento geral do sistema'],
    'notas' => ['nome' => 'Lançamento de Notas', 'icone' => 'fas fa-edit', 'cor' => 'success', 'descricao' => 'Guia para lançamento e gestão de notas'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate', 'cor' => 'info', 'descricao' => 'Processos de matrícula e rematrícula'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill', 'cor' => 'danger', 'descricao' => 'Gestão financeira e pagamentos'],
    'relatorios' => ['nome' => 'Relatórios', 'icone' => 'fas fa-chart-bar', 'cor' => 'warning', 'descricao' => 'Emissão de relatórios e boletins'],
    'admin' => ['nome' => 'Administração', 'icone' => 'fas fa-user-shield', 'cor' => 'dark', 'descricao' => 'Manuais para administradores do sistema']
];

foreach ($manuais as $manual) {
    $cat = $manual['categoria'];
    if (!isset($manuais_por_categoria[$cat])) {
        $manuais_por_categoria[$cat] = [];
    }
    $manuais_por_categoria[$cat][] = $manual;
}

// Estatísticas
$total_manuais = count($manuais);
$total_downloads = array_sum(array_column($manuais, 'downloads'));

// Funções auxiliares
function getCategoriaInfo($categoria) {
    global $categorias;
    return $categorias[$categoria] ?? ['nome' => ucfirst($categoria), 'icone' => 'fas fa-folder', 'cor' => 'secondary', 'descricao' => ''];
}

function getTipoIcone($tipo) {
    switch ($tipo) {
        case 'pdf': return '<i class="fas fa-file-pdf text-danger"></i>';
        case 'doc': case 'docx': return '<i class="fas fa-file-word text-primary"></i>';
        case 'xls': case 'xlsx': return '<i class="fas fa-file-excel text-success"></i>';
        case 'ppt': case 'pptx': return '<i class="fas fa-file-powerpoint text-warning"></i>';
        case 'video': return '<i class="fas fa-file-video text-info"></i>';
        case 'link': return '<i class="fas fa-link text-primary"></i>';
        default: return '<i class="fas fa-file-alt text-secondary"></i>';
    }
}

function getStatusBadge($ativo) {
    return $ativo == 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
}

function formatarData($data) {
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}

function formatarTamanhoArquivo($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manuais e Documentação | SIGE Angola</title>
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
        .manual-card { transition: all 0.3s ease; height: 100%; }
        .manual-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .manual-icon { font-size: 2.5rem; margin-bottom: 10px; }
        .download-count { font-size: 0.8rem; color: #6c757d; }
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
        
        .manual-stats { background: linear-gradient(135deg, #e8f5e9 0%, #e3f2fd 100%); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .admin-actions { position: absolute; top: 15px; right: 15px; }
        .manual-card { position: relative; }
        .search-box { max-width: 500px; margin: 0 auto; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2><i class="fas fa-book-open"></i> Manuais e Documentação</h2>
                    <p>Central de manuais, guias e documentação do sistema</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="../dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-light ms-2" data-bs-toggle="modal" data-bs-target="#modalAdicionarManual">
                            <i class="fas fa-plus"></i> Adicionar Manual
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
        <div class="manual-stats">
            <div class="row text-center">
                <div class="col-md-6">
                    <h3 class="text-primary"><?php echo $total_manuais; ?></h3>
                    <small>Manuais Disponíveis</small>
                </div>
                <div class="col-md-6">
                    <h3 class="text-success"><?php echo number_format($total_downloads); ?></h3>
                    <small>Total de Downloads</small>
                </div>
            </div>
        </div>
        
        <!-- Busca -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="search-box">
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Buscar manuais..." value="<?php echo htmlspecialchars($busca); ?>">
                        <input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>">
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i> Buscar</button>
                        <?php if (!empty($busca) || $categoria_filtro != 'todos'): ?>
                            <a href="manuais.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Limpar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Categorias -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="categoria-card <?php echo $categoria_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarCategoria('todos')">
                    <div class="categoria-icon"><i class="fas fa-list-ul fa-2x"></i></div>
                    <div><strong>Todos</strong></div>
                    <small>(<?php echo $total_manuais; ?> manuais)</small>
                </div>
            </div>
            <?php foreach ($categorias as $key => $cat): ?>
                <?php $count = isset($manuais_por_categoria[$key]) ? count($manuais_por_categoria[$key]) : 0; ?>
                <div class="col-md-3 col-6">
                    <div class="categoria-card <?php echo $categoria_filtro == $key ? 'active' : ''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')">
                        <div class="categoria-icon"><i class="<?php echo $cat['icone']; ?> fa-2x"></i></div>
                        <div><strong><?php echo $cat['nome']; ?></strong></div>
                        <small>(<?php echo $count; ?> manuais)</small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Lista de Manuais -->
        <?php if (empty($manuais)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                    <h4>Nenhum manual encontrado</h4>
                    <p>Não há manuais disponíveis nesta categoria.</p>
                    <?php if ($is_admin || $is_suporte): ?>
                        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAdicionarManual">
                            <i class="fas fa-plus"></i> Adicionar Primeiro Manual
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($manuais as $manual): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card manual-card">
                            <?php if ($is_admin || $is_suporte): ?>
                                <div class="admin-actions">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editarManual(<?php echo $manual['id']; ?>, '<?php echo addslashes($manual['titulo']); ?>', '<?php echo addslashes($manual['descricao']); ?>', '<?php echo $manual['categoria']; ?>', '<?php echo $manual['tipo']; ?>', '<?php echo addslashes($manual['url']); ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?excluir=<?php echo $manual['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza que deseja excluir este manual?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="?toggle=<?php echo $manual['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-<?php echo $manual['ativo'] == 1 ? 'eye-slash' : 'eye'; ?>"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="card-body text-center">
                                <div class="manual-icon"><?php echo getTipoIcone($manual['tipo']); ?></div>
                                <h5 class="card-title"><?php echo htmlspecialchars($manual['titulo']); ?></h5>
                                <p class="card-text small text-muted"><?php echo htmlspecialchars(substr($manual['descricao'], 0, 100)) . '...'; ?></p>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <span class="badge bg-<?php echo getCategoriaInfo($manual['categoria'])['cor']; ?>">
                                        <?php echo getCategoriaInfo($manual['categoria'])['nome']; ?>
                                    </span>
                                    <span class="download-count"><i class="fas fa-download"></i> <?php echo number_format($manual['downloads']); ?> downloads</span>
                                </div>
                                <div class="mt-3">
                                    <?php if ($manual['ativo'] == 1): ?>
                                        <a href="<?php echo $manual['url']; ?>" class="btn btn-primary-custom btn-sm" target="_blank" onclick="registrarDownload(<?php echo $manual['id']; ?>)">
                                            <i class="fas fa-eye"></i> Visualizar
                                        </a>
                                        <a href="<?php echo $manual['url']; ?>" class="btn btn-success btn-sm" download onclick="registrarDownload(<?php echo $manual['id']; ?>)">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled>
                                            <i class="fas fa-lock"></i> Indisponível
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer text-muted text-center small">
                                <i class="fas fa-calendar-alt"></i> Adicionado em: <?php echo formatarData($manual['created_at']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Adicionar Manual -->
    <?php if ($is_admin || $is_suporte): ?>
    <div class="modal fade" id="modalAdicionarManual" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Adicionar Manual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Título do Manual <span class="text-danger">*</span></label>
                            <input type="text" name="titulo" class="form-control" required placeholder="Ex: Guia de Lançamento de Notas">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <textarea name="descricao" class="form-control" rows="3" required placeholder="Descreva o conteúdo do manual..."></textarea>
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
                                    <option value="admin">🛡️ Administração</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Arquivo</label>
                                <select name="tipo" class="form-select" id="tipo_arquivo" onchange="toggleUrlUpload()">
                                    <option value="pdf">📄 PDF</option>
                                    <option value="doc">📝 Word (DOC)</option>
                                    <option value="xls">📊 Excel (XLS)</option>
                                    <option value="ppt">📽️ PowerPoint (PPT)</option>
                                    <option value="video">🎥 Vídeo</option>
                                    <option value="link">🔗 Link Externo</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3" id="upload_div">
                            <label class="form-label">Arquivo</label>
                            <input type="file" name="arquivo" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.mov">
                            <small class="text-muted">Formatos permitidos: PDF, DOC, XLS, PPT, MP4. Máx: 50MB</small>
                        </div>
                        <div class="mb-3" id="url_div" style="display: none;">
                            <label class="form-label">URL do Link</label>
                            <input type="url" name="url" class="form-control" placeholder="https://...">
                            <small class="text-muted">Para links externos (YouTube, Vimeo, etc.)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="adicionar_manual" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Manual -->
    <div class="modal fade" id="modalEditarManual" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Manual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="manual_id" id="edit_manual_id">
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
                                    <option value="admin">Administração</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="edit_tipo" class="form-select">
                                    <option value="pdf">PDF</option>
                                    <option value="doc">Word</option>
                                    <option value="xls">Excel</option>
                                    <option value="ppt">PowerPoint</option>
                                    <option value="video">Vídeo</option>
                                    <option value="link">Link</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL do Arquivo/Link</label>
                            <input type="text" name="url" id="edit_url" class="form-control" placeholder="Caminho do arquivo ou link">
                            <small class="text-muted">Para trocar o arquivo, adicione um novo manual e remova este</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_manual" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Atualizar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Central de Manuais</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-book-open"></i> Sobre a Central de Manuais</h5>
                        <p>Esta é a central de documentação do sistema, onde você encontra todos os manuais, guias e tutoriais disponíveis.</p>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-search"></i> Como encontrar manuais</h5>
                        <ul>
                            <li><strong>Busca:</strong> Digite palavras-chave para encontrar manuais específicos</li>
                            <li><strong>Categorias:</strong> Filtre por tema (Sistema, Notas, Matrículas, etc.)</li>
                            <li><strong>Clique no manual:</strong> Visualize ou faça download do documento</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-download"></i> Tipos de arquivo disponíveis</h5>
                        <ul>
                            <li><i class="fas fa-file-pdf text-danger"></i> <strong>PDF</strong> - Documentos para leitura</li>
                            <li><i class="fas fa-file-word text-primary"></i> <strong>Word</strong> - Documentos editáveis</li>
                            <li><i class="fas fa-file-excel text-success"></i> <strong>Excel</strong> - Planilhas e formulários</li>
                            <li><i class="fas fa-file-powerpoint text-warning"></i> <strong>PowerPoint</strong> - Apresentações</li>
                            <li><i class="fas fa-link text-primary"></i> <strong>Links</strong> - Vídeos tutoriais online</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-shield-alt"></i> Para Administradores</h5>
                        <ul>
                            <li>Adicionar, editar e excluir manuais</li>
                            <li>Ativar/desativar manuais conforme necessidade</li>
                            <li>Acompanhar estatísticas de downloads</li>
                        </ul>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dica:</strong> Os manuais mais baixados aparecem primeiro na listagem.
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
        
        function toggleUrlUpload() {
            var tipo = document.getElementById('tipo_arquivo')?.value;
            var uploadDiv = document.getElementById('upload_div');
            var urlDiv = document.getElementById('url_div');
            
            if (tipo === 'link') {
                if (uploadDiv) uploadDiv.style.display = 'none';
                if (urlDiv) urlDiv.style.display = 'block';
            } else {
                if (uploadDiv) uploadDiv.style.display = 'block';
                if (urlDiv) urlDiv.style.display = 'none';
            }
        }
        
        function filtrarCategoria(categoria) {
            const url = new URL(window.location.href);
            if (categoria === 'todos') {
                url.searchParams.delete('categoria');
            } else {
                url.searchParams.set('categoria', categoria);
            }
            window.location.href = url.toString();
        }
        
        function registrarDownload(manualId) {
            fetch('manuais.php?download=' + manualId, { method: 'GET' });
        }
        
        <?php if ($is_admin || $is_suporte): ?>
        function editarManual(id, titulo, descricao, categoria, tipo, url) {
            document.getElementById('edit_manual_id').value = id;
            document.getElementById('edit_titulo').value = titulo;
            document.getElementById('edit_descricao').value = descricao;
            document.getElementById('edit_categoria').value = categoria;
            document.getElementById('edit_tipo').value = tipo;
            document.getElementById('edit_url').value = url;
            new bootstrap.Modal(document.getElementById('modalEditarManual')).show();
        }
        <?php endif; ?>
        
        // Inicializar visibilidade dos campos
        toggleUrlUpload();
    </script>
</body>
</html>