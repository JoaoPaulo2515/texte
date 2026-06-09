<?php
// escola/secretaria/documentos.php - Gestão de Documentos da Secretaria

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

// Verificar permissões (apenas secretaria, admin, diretor)
$is_secretaria = ($usuario_tipo == 'secretaria' || $papel == 'secretaria');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');

if (!$is_secretaria && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$success = '';
$error = '';

// Processar upload de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_documento'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'outro';
    $tipo_documento = $_POST['tipo_documento'] ?? 'pdf';
    $destinatario = trim($_POST['destinatario'] ?? '');
    $data_validade = $_POST['data_validade'] ?? null;
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título do documento é obrigatório.";
    } elseif (empty($_FILES['arquivo']['name']) && empty($_POST['url'])) {
        $error = "Selecione um arquivo ou informe uma URL.";
    } else {
        try {
            $arquivo_nome = null;
            $url = null;
            
            // Processar upload de arquivo
            if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0 && $_FILES['arquivo']['size'] > 0) {
                $extensoes_permitidas = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
                $extensao = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                $tamanho_maximo = 10 * 1024 * 1024; // 10MB
                
                if (!in_array($extensao, $extensoes_permitidas)) {
                    $error = "Formato de arquivo não permitido. Use: PDF, DOC, XLS, JPG, PNG";
                } elseif ($_FILES['arquivo']['size'] > $tamanho_maximo) {
                    $error = "Arquivo muito grande. Máximo 10MB.";
                } else {
                    $diretorio = __DIR__ . '/../../uploads/documentos/';
                    if (!file_exists($diretorio)) {
                        mkdir($diretorio, 0777, true);
                    }
                    $arquivo_nome = 'doc_' . time() . '_' . uniqid() . '.' . $extensao;
                    move_uploaded_file($_FILES['arquivo']['tmp_name'], $diretorio . $arquivo_nome);
                    $url = '../../uploads/documentos/' . $arquivo_nome;
                }
            } elseif (!empty($_POST['url'])) {
                $url = trim($_POST['url']);
            }
            
            if (empty($error)) {
                $sql = "INSERT INTO documentos_secretaria (escola_id, titulo, descricao, categoria, tipo_documento, arquivo, url, destinatario, data_validade, observacoes, status, created_by, created_at) 
                        VALUES (:escola_id, :titulo, :descricao, :categoria, :tipo_documento, :arquivo, :url, :destinatario, :data_validade, :observacoes, 'ativo', :created_by, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':categoria' => $categoria,
                    ':tipo_documento' => $tipo_documento,
                    ':arquivo' => $arquivo_nome,
                    ':url' => $url,
                    ':destinatario' => $destinatario,
                    ':data_validade' => $data_validade,
                    ':observacoes' => $observacoes,
                    ':created_by' => $usuario_id
                ]);
                $success = "Documento adicionado com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao adicionar documento: " . $e->getMessage();
        }
    }
}

// Processar edição de documento
if (($is_secretaria || $is_admin) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_documento'])) {
    $doc_id = (int)$_POST['documento_id'];
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'outro';
    $destinatario = trim($_POST['destinatario'] ?? '');
    $data_validade = $_POST['data_validade'] ?? null;
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if (empty($titulo)) {
        $error = "O título do documento é obrigatório.";
    } else {
        try {
            $sql = "UPDATE documentos_secretaria SET titulo = :titulo, descricao = :descricao, categoria = :categoria, destinatario = :destinatario, data_validade = :data_validade, observacoes = :observacoes, updated_at = NOW() 
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':destinatario' => $destinatario,
                ':data_validade' => $data_validade,
                ':observacoes' => $observacoes,
                ':id' => $doc_id,
                ':escola_id' => $escola_id
            ]);
            $success = "Documento atualizado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao atualizar documento: " . $e->getMessage();
        }
    }
}

// Processar exclusão de documento
if (($is_secretaria || $is_admin) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $doc_id = (int)$_GET['excluir'];
    try {
        // Buscar arquivo para deletar
        $sql = "SELECT arquivo FROM documentos_secretaria WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $doc_id, ':escola_id' => $escola_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc && $doc['arquivo']) {
            $arquivo_path = __DIR__ . '/../../uploads/documentos/' . $doc['arquivo'];
            if (file_exists($arquivo_path)) {
                unlink($arquivo_path);
            }
        }
        
        $sql = "DELETE FROM documentos_secretaria WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $doc_id, ':escola_id' => $escola_id]);
        $success = "Documento excluído com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao excluir documento: " . $e->getMessage();
    }
}

// Processar alteração de status
if (($is_secretaria || $is_admin) && isset($_GET['status']) && isset($_GET['id'])) {
    $doc_id = (int)$_GET['id'];
    $novo_status = $_GET['status'] == 'ativo' ? 'inativo' : 'ativo';
    try {
        $sql = "UPDATE documentos_secretaria SET status = :status WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':status' => $novo_status, ':id' => $doc_id, ':escola_id' => $escola_id]);
        $success = "Status alterado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao alterar status: " . $e->getMessage();
    }
}

// Buscar documentos
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if ($categoria_filtro != 'todos') {
    $where_conditions[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}
if ($ano_filtro) {
    $where_conditions[] = "YEAR(created_at) = :ano";
    $params[':ano'] = $ano_filtro;
}
if (!empty($busca)) {
    $where_conditions[] = "(titulo LIKE :busca OR descricao LIKE :busca OR destinatario LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) . " AND escola_id = :escola_id" : "WHERE escola_id = :escola_id";

$sql_documentos = "SELECT * FROM documentos_secretaria $where_sql ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $conn->prepare($sql_documentos);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM documentos_secretaria " . str_replace("ORDER BY created_at DESC", "", $where_sql);
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_documentos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_documentos / $limit);

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM documentos_secretaria WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM documentos_secretaria WHERE escola_id = :escola_id AND categoria = 'oficio'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['oficios'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM documentos_secretaria WHERE escola_id = :escola_id AND categoria = 'certidao'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['certidoes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM documentos_secretaria WHERE escola_id = :escola_id AND categoria = 'declaracao'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['declaracoes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM documentos_secretaria WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['ativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Categorias disponíveis
$categorias = [
    'oficio' => ['nome' => 'Ofício', 'icone' => 'fas fa-envelope', 'cor' => 'primary'],
    'certidao' => ['nome' => 'Certidão', 'icone' => 'fas fa-certificate', 'cor' => 'success'],
    'declaracao' => ['nome' => 'Declaração', 'icone' => 'fas fa-file-alt', 'cor' => 'info'],
    'atestado' => ['nome' => 'Atestado', 'icone' => 'fas fa-notes-medical', 'cor' => 'warning'],
    'requerimento' => ['nome' => 'Requerimento', 'icone' => 'fas fa-file-signature', 'cor' => 'danger'],
    'comunicado' => ['nome' => 'Comunicado', 'icone' => 'fas fa-bullhorn', 'cor' => 'secondary'],
    'outro' => ['nome' => 'Outros', 'icone' => 'fas fa-folder', 'cor' => 'dark']
];

$tipos_arquivo = [
    'pdf' => ['nome' => 'PDF', 'icone' => 'fas fa-file-pdf text-danger'],
    'word' => ['nome' => 'Word', 'icone' => 'fas fa-file-word text-primary'],
    'excel' => ['nome' => 'Excel', 'icone' => 'fas fa-file-excel text-success'],
    'imagem' => ['nome' => 'Imagem', 'icone' => 'fas fa-file-image text-info'],
    'link' => ['nome' => 'Link', 'icone' => 'fas fa-link text-secondary']
];

function getCategoriaInfo($categoria) {
    global $categorias;
    return $categorias[$categoria] ?? $categorias['outro'];
}

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>';
    } else {
        return '<span class="badge bg-secondary"><i class="fas fa-minus-circle"></i> Inativo</span>';
    }
}

function formatarData($data) {
    if (!$data) return '-';
    return date('d/m/Y H:i', strtotime($data));
}

function formatarDataSimples($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Documentos | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-value { font-size: 1.8em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.85rem; color: #6c757d; }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .documento-card { transition: all 0.3s ease; height: 100%; }
        .documento-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .documento-icon { font-size: 2rem; margin-bottom: 10px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .badge-categoria { padding: 5px 10px; border-radius: 20px; font-size: 11px; }
        .admin-actions { position: absolute; top: 10px; right: 10px; z-index: 10; }
        .documento-card { position: relative; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-close-white { filter: invert(1); }
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-folder-open"></i> Gestão de Documentos</h2>
                <p>Secretaria - Ofícios, Certidões, Declarações e Documentos Administrativos</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdicionarDocumento">
                    <i class="fas fa-plus"></i> Novo Documento
                </button>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Documentos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['oficios']; ?></div><div class="stat-label">Ofícios</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['certidoes']; ?></div><div class="stat-label">Certidões</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['declaracoes']; ?></div><div class="stat-label">Declarações</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativos']; ?></div><div class="stat-label">Documentos Ativos</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3"><label class="filter-label">Categoria</label><select name="categoria" class="form-select"><option value="todos" <?php echo $categoria_filtro=='todos'?'selected':''; ?>>Todas</option><option value="oficio" <?php echo $categoria_filtro=='oficio'?'selected':''; ?>>Ofício</option><option value="certidao" <?php echo $categoria_filtro=='certidao'?'selected':''; ?>>Certidão</option><option value="declaracao" <?php echo $categoria_filtro=='declaracao'?'selected':''; ?>>Declaração</option><option value="atestado" <?php echo $categoria_filtro=='atestado'?'selected':''; ?>>Atestado</option><option value="requerimento" <?php echo $categoria_filtro=='requerimento'?'selected':''; ?>>Requerimento</option><option value="comunicado" <?php echo $categoria_filtro=='comunicado'?'selected':''; ?>>Comunicado</option><option value="outro" <?php echo $categoria_filtro=='outro'?'selected':''; ?>>Outros</option></select></div>
                    <div class="col-md-2"><label class="filter-label">Ano</label><select name="ano" class="form-select"><?php for($i=date('Y'); $i>=date('Y')-5; $i--): ?><option value="<?php echo $i; ?>" <?php echo $ano_filtro==$i?'selected':''; ?>><?php echo $i; ?></option><?php endfor; ?></select></div>
                    <div class="col-md-4"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Título, descrição ou destinatário..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-3"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Documentos -->
        <div class="row">
            <?php if (empty($documentos)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-folder-open fa-3x text-muted mb-3"></i><h4>Nenhum documento encontrado</h4><p>Clique em "Novo Documento" para adicionar.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($documentos as $doc): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card documento-card">
                        <?php if ($is_secretaria || $is_admin): ?>
                        <div class="admin-actions">
                            <button class="btn btn-sm btn-outline-primary" onclick="editarDocumento(<?php echo $doc['id']; ?>, '<?php echo addslashes($doc['titulo']); ?>', '<?php echo addslashes($doc['descricao']); ?>', '<?php echo $doc['categoria']; ?>', '<?php echo addslashes($doc['destinatario']); ?>', '<?php echo $doc['data_validade']; ?>', '<?php echo addslashes($doc['observacoes']); ?>')"><i class="fas fa-edit"></i></button>
                            <a href="?excluir=<?php echo $doc['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza que deseja excluir este documento?')"><i class="fas fa-trash"></i></a>
                            <a href="?id=<?php echo $doc['id']; ?>&status=<?php echo $doc['status']; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-<?php echo $doc['status']=='ativo'?'eye-slash':'eye'; ?>"></i></a>
                        </div>
                        <?php endif; ?>
                        <div class="card-body text-center">
                            <div class="documento-icon"><?php echo getCategoriaInfo($doc['categoria'])['icone']; ?></div>
                            <h6 class="card-title"><?php echo htmlspecialchars($doc['titulo']); ?></h6>
                            <p class="card-text small text-muted"><?php echo htmlspecialchars(substr($doc['descricao'] ?? '', 0, 80)) . '...'; ?></p>
                            <?php if ($doc['destinatario']): ?><p class="small text-muted"><i class="fas fa-user"></i> <?php echo htmlspecialchars($doc['destinatario']); ?></p><?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge bg-<?php echo getCategoriaInfo($doc['categoria'])['cor']; ?>"><?php echo getCategoriaInfo($doc['categoria'])['nome']; ?></span>
                                <?php echo getStatusBadge($doc['status']); ?>
                            </div>
                            <div class="mt-2 small text-muted"><i class="fas fa-calendar-alt"></i> <?php echo formatarData($doc['created_at']); ?></div>
                            <?php if ($doc['data_validade']): ?><div class="small text-warning"><i class="fas fa-hourglass-half"></i> Válido até: <?php echo formatarDataSimples($doc['data_validade']); ?></div><?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent text-center">
                            <?php if ($doc['url']): ?>
                                <a href="<?php echo $doc['url']; ?>" class="btn btn-sm btn-primary" target="_blank"><i class="fas fa-eye"></i> Visualizar</a>
                                <a href="<?php echo $doc['url']; ?>" class="btn btn-sm btn-success" download><i class="fas fa-download"></i> Download</a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled><i class="fas fa-lock"></i> Arquivo Indisponível</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&categoria=<?php echo urlencode($categoria_filtro); ?>&ano=<?php echo $ano_filtro; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Adicionar Documento -->
    <div class="modal fade" id="modalAdicionarDocumento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> Adicionar Documento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Título <span class="text-danger">*</span></label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="3"></textarea></div>
                        <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Categoria</label><select name="categoria" class="form-select"><option value="oficio">Ofício</option><option value="certidao">Certidão</option><option value="declaracao">Declaração</option><option value="atestado">Atestado</option><option value="requerimento">Requerimento</option><option value="comunicado">Comunicado</option><option value="outro">Outros</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Tipo</label><select name="tipo_documento" class="form-select"><option value="pdf">PDF</option><option value="word">Word</option><option value="excel">Excel</option><option value="imagem">Imagem</option><option value="link">Link</option></select></div></div>
                        <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Destinatário</label><input type="text" name="destinatario" class="form-control" placeholder="Para quem é este documento?"></div><div class="col-md-6 mb-3"><label class="form-label">Data de Validade</label><input type="date" name="data_validade" class="form-control"></div></div>
                        <div class="mb-3" id="upload_div"><label class="form-label">Arquivo</label><input type="file" name="arquivo" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"><small class="text-muted">PDF, DOC, XLS, JPG, PNG. Máx: 10MB</small></div>
                        <div class="mb-3" id="url_div" style="display:none;"><label class="form-label">URL</label><input type="url" name="url" class="form-control" placeholder="https://..."><small class="text-muted">Para links externos</small></div>
                        <div class="mb-3"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="adicionar_documento" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Documento -->
    <div class="modal fade" id="modalEditarDocumento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Documento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST"><input type="hidden" name="documento_id" id="edit_documento_id">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Título</label><input type="text" name="titulo" id="edit_titulo" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="3"></textarea></div>
                        <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Categoria</label><select name="categoria" id="edit_categoria" class="form-select"><option value="oficio">Ofício</option><option value="certidao">Certidão</option><option value="declaracao">Declaração</option><option value="atestado">Atestado</option><option value="requerimento">Requerimento</option><option value="comunicado">Comunicado</option><option value="outro">Outros</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Destinatário</label><input type="text" name="destinatario" id="edit_destinatario" class="form-control"></div></div>
                        <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Data de Validade</label><input type="date" name="data_validade" id="edit_data_validade" class="form-control"></div></div>
                        <div class="mb-3"><label class="form-label">Observações</label><textarea name="observacoes" id="edit_observacoes" class="form-control" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="editar_documento" class="btn btn-primary"><i class="fas fa-save"></i> Atualizar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Gestão de Documentos</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-folder-open"></i> Sobre a Gestão de Documentos</h5><p>Sistema para gerenciar documentos administrativos da secretaria.</p></div><div class="ajuda-section"><h5><i class="fas fa-file-alt"></i> Tipos de Documentos</h5><ul><li><strong>Ofício:</strong> Comunicações oficiais</li><li><strong>Certidão:</strong> Documentos comprobatórios</li><li><strong>Declaração:</strong> Declarações diversas</li><li><strong>Atestado:</strong> Atestados médicos ou escolares</li><li><strong>Requerimento:</strong> Solicitações oficiais</li><li><strong>Comunicado:</strong> Avisos e comunicados</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-search"></i> Como usar</h5><ul><li>Use os filtros para encontrar documentos</li><li>Clique em "Novo Documento" para adicionar</li><li>Documentos podem ser visualizados ou baixados</li></ul></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="../suporte/faq.php" class="btn btn-primary">Ver FAQ</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('.sidebar').toggleClass('active'); $('.main-content').toggleClass('active'); });
        $('#btnAjuda').click(function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        
        function toggleUrlUpload() { var tipo = document.querySelector('select[name="tipo_documento"]')?.value; var uploadDiv = document.getElementById('upload_div'); var urlDiv = document.getElementById('url_div'); if(tipo === 'link'){ if(uploadDiv) uploadDiv.style.display = 'none'; if(urlDiv) urlDiv.style.display = 'block'; }else{ if(uploadDiv) uploadDiv.style.display = 'block'; if(urlDiv) urlDiv.style.display = 'none'; } }
        document.querySelector('select[name="tipo_documento"]')?.addEventListener('change', toggleUrlUpload);
        
        function editarDocumento(id, titulo, descricao, categoria, destinatario, data_validade, observacoes) {
            document.getElementById('edit_documento_id').value = id;
            document.getElementById('edit_titulo').value = titulo;
            document.getElementById('edit_descricao').value = descricao;
            document.getElementById('edit_categoria').value = categoria;
            document.getElementById('edit_destinatario').value = destinatario || '';
            document.getElementById('edit_data_validade').value = data_validade || '';
            document.getElementById('edit_observacoes').value = observacoes || '';
            new bootstrap.Modal(document.getElementById('modalEditarDocumento')).show();
        }
        toggleUrlUpload();
    </script>
</body>
</html>