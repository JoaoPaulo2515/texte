<?php
// escola/fiscal/clientes/index.php - Gestão de Clientes (Fiscal)

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões (apenas admin, diretor ou fiscal)
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_fiscal = ($papel == 'fiscal' || $is_admin);

if (!$is_fiscal && !$is_admin) {
    header('Location: ../../dashboard.php?msg=acesso_negado');
    exit;
}

// Filtros
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$success = '';
$error = '';

// ============================================
// PROCESSAR CADASTRO DE CLIENTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_cliente'])) {
    $nome = trim($_POST['nome'] ?? '');
    $tipo = $_POST['tipo'] ?? 'pf';
    $documento = trim($_POST['documento'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if (empty($nome)) {
        $error = "O nome do cliente é obrigatório.";
    } elseif (empty($documento)) {
        $error = "O documento (BI/NIF) é obrigatório.";
    } else {
        try {
            // Verificar se documento já existe
            $sql_check = "SELECT COUNT(*) as total FROM clientes WHERE documento = :documento AND escola_id = :escola_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':documento' => $documento, ':escola_id' => $escola_id]);
            if ($stmt_check->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                $error = "Já existe um cliente cadastrado com este documento.";
            } else {
                $sql = "INSERT INTO clientes (escola_id, nome, tipo, documento, email, telefone, endereco, observacoes, status, created_by, created_at) 
                        VALUES (:escola_id, :nome, :tipo, :documento, :email, :telefone, :endereco, :observacoes, 'ativo', :created_by, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':nome' => $nome,
                    ':tipo' => $tipo,
                    ':documento' => $documento,
                    ':email' => $email,
                    ':telefone' => $telefone,
                    ':endereco' => $endereco,
                    ':observacoes' => $observacoes,
                    ':created_by' => $usuario_id
                ]);
                $success = "Cliente cadastrado com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao cadastrar cliente: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR EDIÇÃO DE CLIENTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cliente'])) {
    $cliente_id = (int)$_POST['cliente_id'];
    $nome = trim($_POST['nome'] ?? '');
    $tipo = $_POST['tipo'] ?? 'pf';
    $documento = trim($_POST['documento'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if (empty($nome)) {
        $error = "O nome do cliente é obrigatório.";
    } elseif (empty($documento)) {
        $error = "O documento (BI/NIF) é obrigatório.";
    } else {
        try {
            // Verificar se documento já existe para outro cliente
            $sql_check = "SELECT COUNT(*) as total FROM clientes WHERE documento = :documento AND escola_id = :escola_id AND id != :id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':documento' => $documento, ':escola_id' => $escola_id, ':id' => $cliente_id]);
            if ($stmt_check->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                $error = "Já existe outro cliente cadastrado com este documento.";
            } else {
                $sql = "UPDATE clientes SET 
                            nome = :nome, 
                            tipo = :tipo, 
                            documento = :documento, 
                            email = :email, 
                            telefone = :telefone, 
                            endereco = :endereco, 
                            observacoes = :observacoes,
                            updated_at = NOW()
                        WHERE id = :id AND escola_id = :escola_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':nome' => $nome,
                    ':tipo' => $tipo,
                    ':documento' => $documento,
                    ':email' => $email,
                    ':telefone' => $telefone,
                    ':endereco' => $endereco,
                    ':observacoes' => $observacoes,
                    ':id' => $cliente_id,
                    ':escola_id' => $escola_id
                ]);
                $success = "Cliente atualizado com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao atualizar cliente: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR EXCLUSÃO DE CLIENTE
// ============================================
if (($is_admin || $is_fiscal) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $cliente_id = (int)$_GET['excluir'];
    try {
        $sql = "DELETE FROM clientes WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $cliente_id, ':escola_id' => $escola_id]);
        $success = "Cliente excluído com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao excluir cliente: " . $e->getMessage();
    }
}

// ============================================
// PROCESSAR ALTERAÇÃO DE STATUS
// ============================================
if (($is_admin || $is_fiscal) && isset($_GET['status']) && isset($_GET['id'])) {
    $cliente_id = (int)$_GET['id'];
    $novo_status = $_GET['status'] == 'ativo' ? 'inativo' : 'ativo';
    try {
        $sql = "UPDATE clientes SET status = :status WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':status' => $novo_status, ':id' => $cliente_id, ':escola_id' => $escola_id]);
        $success = "Status do cliente alterado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao alterar status: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR CLIENTES
// ============================================
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if ($tipo_filtro != 'todos') {
    $where_conditions[] = "c.tipo = :tipo";
    $params[':tipo'] = $tipo_filtro;
}

if ($status_filtro != 'todos') {
    $where_conditions[] = "c.status = :status";
    $params[':status'] = $status_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(c.nome LIKE :busca OR c.documento LIKE :busca OR c.email LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "c.escola_id = :escola_id";
$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE c.escola_id = :escola_id";

$sql_clientes = "
    SELECT c.*, u.nome as criador_nome
    FROM clientes c
    LEFT JOIN usuarios u ON u.id = c.created_by
    $where_sql
    ORDER BY c.nome ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_clientes);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM clientes c $where_sql";
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_clientes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_clientes / $limit);

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM clientes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM clientes WHERE escola_id = :escola_id AND tipo = 'pf'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['pf'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM clientes WHERE escola_id = :escola_id AND tipo = 'pj'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['pj'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM clientes WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['ativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Funções auxiliares
function getTipoCliente($tipo) {
    if ($tipo == 'pf') {
        return '<span class="badge bg-primary"><i class="fas fa-user"></i> Pessoa Física</span>';
    } else {
        return '<span class="badge bg-info"><i class="fas fa-building"></i> Pessoa Jurídica</span>';
    }
}

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Inativo</span>';
    }
}

function formatarDocumento($tipo, $documento) {
    if ($tipo == 'pf') {
        // Formatar BI/CPF
        return $documento;
    } else {
        // Formatar NIF
        return $documento;
    }
}

function formatarDataCliente($data) {
    if (!$data) return '-';
    return date('d/m/Y H:i', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | Fiscal | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.5em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.75rem; color: #6c757d; }
        
        .cliente-card { transition: all 0.3s ease; height: 100%; }
        .cliente-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .admin-actions { position: absolute; top: 10px; right: 10px; z-index: 10; }
        .cliente-card { position: relative; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-users"></i> Gestão de Clientes</h2>
                <p>Cadastro e gerenciamento de clientes (Pessoa Física/Jurídica)</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoCliente">
                    <i class="fas fa-plus"></i> Novo Cliente
                </button>
                <a href="../dashboard.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
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
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Clientes</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['pf']; ?></div><div class="stat-label">Pessoas Físicas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['pj']; ?></div><div class="stat-label">Pessoas Jurídicas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativos']; ?></div><div class="stat-label">Clientes Ativos</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3"><label class="filter-label">Tipo</label><select name="tipo" class="form-select"><option value="todos" <?php echo $tipo_filtro=='todos'?'selected':''; ?>>Todos</option><option value="pf" <?php echo $tipo_filtro=='pf'?'selected':''; ?>>Pessoa Física</option><option value="pj" <?php echo $tipo_filtro=='pj'?'selected':''; ?>>Pessoa Jurídica</option></select></div>
                    <div class="col-md-3"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option><option value="ativo" <?php echo $status_filtro=='ativo'?'selected':''; ?>>Ativo</option><option value="inativo" <?php echo $status_filtro=='inativo'?'selected':''; ?>>Inativo</option></select></div>
                    <div class="col-md-4"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Nome, documento ou email..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-2"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Clientes -->
        <div class="row">
            <?php if (empty($clientes)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-users fa-3x text-muted mb-3"></i><h4>Nenhum cliente encontrado</h4><p>Clique em "Novo Cliente" para começar.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($clientes as $cliente): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card cliente-card">
                            <?php if ($is_admin || $is_fiscal): ?>
                                <div class="admin-actions">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editarCliente(
                                        <?php echo $cliente['id']; ?>,
                                        '<?php echo addslashes($cliente['nome']); ?>',
                                        '<?php echo $cliente['tipo']; ?>',
                                        '<?php echo addslashes($cliente['documento']); ?>',
                                        '<?php echo addslashes($cliente['email']); ?>',
                                        '<?php echo addslashes($cliente['telefone']); ?>',
                                        '<?php echo addslashes($cliente['endereco']); ?>',
                                        '<?php echo addslashes($cliente['observacoes']); ?>'
                                    )">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?excluir=<?php echo $cliente['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza que deseja excluir este cliente?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="?id=<?php echo $cliente['id']; ?>&status=<?php echo $cliente['status']; ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-<?php echo $cliente['status']=='ativo'?'eye-slash':'eye'; ?>"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title"><?php echo htmlspecialchars($cliente['nome']); ?></h6>
                                    <?php echo getTipoCliente($cliente['tipo']); ?>
                                </div>
                                <p class="small text-muted">
                                    <i class="fas fa-id-card"></i> Documento: <?php echo formatarDocumento($cliente['tipo'], $cliente['documento']); ?>
                                </p>
                                <?php if ($cliente['email']): ?>
                                <p class="small text-muted">
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($cliente['email']); ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($cliente['telefone']): ?>
                                <p class="small text-muted">
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($cliente['telefone']); ?>
                                </p>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <?php echo getStatusBadge($cliente['status']); ?>
                                    <small class="text-muted"><i class="fas fa-calendar-alt"></i> <?php echo formatarDataCliente($cliente['created_at']); ?></small>
                                </div>
                                <?php if ($cliente['observacoes']): ?>
                                    <div class="small text-warning mt-2"><i class="fas fa-comment"></i> <?php echo htmlspecialchars(substr($cliente['observacoes'], 0, 50)) . (strlen($cliente['observacoes']) > 50 ? '...' : ''); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&status=<?php echo urlencode($status_filtro); ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Novo Cliente -->
    <div class="modal fade" id="modalNovoCliente" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Cliente</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Nome/Razão Social <span class="text-danger">*</span></label>
                                <input type="text" name="nome" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tipo <span class="text-danger">*</span></label>
                                <select name="tipo" class="form-select" required>
                                    <option value="pf">Pessoa Física</option>
                                    <option value="pj">Pessoa Jurídica</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Documento (BI/NIF) <span class="text-danger">*</span></label>
                                <input type="text" name="documento" class="form-control" required placeholder="Ex: 123456789">
                                <small class="text-muted">BI para Pessoa Física, NIF para Pessoa Jurídica</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" placeholder="cliente@email.com">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="telefone" class="form-control" placeholder="Ex: 923456789">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Endereço</label>
                                <input type="text" name="endereco" class="form-control" placeholder="Endereço completo">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="cadastrar_cliente" class="btn btn-primary">Cadastrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Cliente -->
    <div class="modal fade" id="modalEditarCliente" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Cliente</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="cliente_id" id="edit_cliente_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Nome/Razão Social</label>
                                <input type="text" name="nome" id="edit_nome" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="edit_tipo" class="form-select" required>
                                    <option value="pf">Pessoa Física</option>
                                    <option value="pj">Pessoa Jurídica</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Documento (BI/NIF)</label>
                                <input type="text" name="documento" id="edit_documento" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="telefone" id="edit_telefone" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Endereço</label>
                                <input type="text" name="endereco" id="edit_endereco" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" id="edit_observacoes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_cliente" class="btn btn-primary">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Clientes</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-users"></i> Sobre Clientes</h5><p>Sistema para cadastro e gestão de clientes.</p></div><div class="ajuda-section"><h5><i class="fas fa-info-circle"></i> Tipos de Cliente</h5><ul><li><strong>Pessoa Física:</strong> Cliente individual, com BI</li><li><strong>Pessoa Jurídica:</strong> Empresa, com NIF</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-search"></i> Como usar</h5><ul><li>Use os filtros para buscar clientes</li><li>Clique em "Novo Cliente" para cadastrar</li><li>Edite ou inative clientes quando necessário</li></ul></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="../../suporte/faq.php" class="btn btn-primary">Ver FAQ</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        
        function editarCliente(id, nome, tipo, documento, email, telefone, endereco, observacoes) {
            document.getElementById('edit_cliente_id').value = id;
            document.getElementById('edit_nome').value = nome;
            document.getElementById('edit_tipo').value = tipo;
            document.getElementById('edit_documento').value = documento;
            document.getElementById('edit_email').value = email || '';
            document.getElementById('edit_telefone').value = telefone || '';
            document.getElementById('edit_endereco').value = endereco || '';
            document.getElementById('edit_observacoes').value = observacoes || '';
            new bootstrap.Modal(document.getElementById('modalEditarCliente')).show();
        }
    </script>
</body>
</html>