<?php
// escola/biblioteca/emprestimos.php - Sistema de Empréstimos da Biblioteca

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

// Detectar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_secretaria = ($usuario_tipo == 'secretaria' || $papel == 'secretaria');
$is_bibliotecario = ($papel == 'bibliotecario' || $is_admin || $is_secretaria);

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'ativos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$success = '';
$error = '';

// ============================================
// PROCESSAR DEVOLUÇÃO DE LIVRO
// ============================================
if (($is_bibliotecario || $is_admin) && isset($_GET['devolver']) && is_numeric($_GET['devolver'])) {
    $emprestimo_id = (int)$_GET['devolver'];
    try {
        // Buscar dados do empréstimo - com escola_id
        $sql_emprestimo = "SELECT livro_id, usuario_id FROM emprestimos WHERE id = :id AND status = 'ativo' AND escola_id = :escola_id";
        $stmt_emprestimo = $conn->prepare($sql_emprestimo);
        $stmt_emprestimo->execute([':id' => $emprestimo_id, ':escola_id' => $escola_id]);
        $emprestimo = $stmt_emprestimo->fetch(PDO::FETCH_ASSOC);
        
        if ($emprestimo) {
            // Atualizar status do empréstimo - com escola_id
            $sql = "UPDATE emprestimos SET status = 'devolvido', data_devolucao_real = NOW(), updated_at = NOW() WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $emprestimo_id, ':escola_id' => $escola_id]);
            
            // Atualizar quantidade disponível do livro - com escola_id
            $sql_update = "UPDATE livros SET quantidade_disponivel = quantidade_disponivel + 1 WHERE id = :id AND escola_id = :escola_id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $emprestimo['livro_id'], ':escola_id' => $escola_id]);
            
            $success = "Devolução registrada com sucesso!";
        } else {
            $error = "Empréstimo não encontrado ou já devolvido.";
        }
    } catch (Exception $e) {
        $error = "Erro ao registrar devolução: " . $e->getMessage();
    }
}

// ============================================
// PROCESSAR RENOVAÇÃO DE EMPRÉSTIMO
// ============================================
if (($is_bibliotecario || $is_admin) && isset($_GET['renovar']) && is_numeric($_GET['renovar'])) {
    $emprestimo_id = (int)$_GET['renovar'];
    try {
        $sql = "UPDATE emprestimos SET data_devolucao_prevista = DATE_ADD(data_devolucao_prevista, INTERVAL 15 DAY), updated_at = NOW(), renovacoes = renovacoes + 1 WHERE id = :id AND status = 'ativo' AND renovacoes < 2 AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $emprestimo_id, ':escola_id' => $escola_id]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Empréstimo renovado por mais 15 dias!";
        } else {
            $error = "Não foi possível renovar. Verifique se o empréstimo está ativo ou se já atingiu o limite de renovações.";
        }
    } catch (Exception $e) {
        $error = "Erro ao renovar empréstimo: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR EMPRÉSTIMOS
// ============================================
$where_conditions = [];
$params = [];

// Se não for admin/bibliotecário, mostrar apenas empréstimos do usuário
if (!$is_bibliotecario && !$is_admin) {
    $where_conditions[] = "e.usuario_id = :usuario_id";
    $params[':usuario_id'] = $usuario_id;
}

if ($status_filtro != 'todos') {
    if ($status_filtro == 'ativos') {
        $where_conditions[] = "e.status = 'ativo'";
    } elseif ($status_filtro == 'atrasados') {
        $where_conditions[] = "e.status = 'ativo' AND e.data_devolucao_prevista < CURDATE()";
    } elseif ($status_filtro == 'devolvidos') {
        $where_conditions[] = "e.status = 'devolvido'";
    } else {
        $where_conditions[] = "e.status = :status";
        $params[':status'] = $status_filtro;
    }
}

if (!empty($busca)) {
    $where_conditions[] = "(l.titulo LIKE :busca OR l.autor LIKE :busca OR u.nome LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

// Adicionar filtro de escola_id
$where_conditions[] = "e.escola_id = :escola_id";
$params[':escola_id'] = $escola_id;

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE e.escola_id = :escola_id";

$sql_emprestimos = "
    SELECT e.*, l.titulo as livro_titulo, l.autor as livro_autor, l.categoria,
           u.nome as usuario_nome, u.email as usuario_email
    FROM emprestimos e
    JOIN livros l ON l.id = e.livro_id
    JOIN usuarios u ON u.id = e.usuario_id
    $where_sql
    ORDER BY 
        CASE 
            WHEN e.status = 'ativo' AND e.data_devolucao_prevista < CURDATE() THEN 1
            WHEN e.status = 'ativo' THEN 2
            ELSE 3
        END,
        e.data_devolucao_prevista ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_emprestimos);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$emprestimos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM emprestimos e JOIN livros l ON l.id = e.livro_id $where_sql";
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_emprestimos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_emprestimos / $limit);

// Estatísticas - com escola_id
$stats = [];
$stats['total'] = $total_emprestimos;

$sql_ativos = "SELECT COUNT(*) as total FROM emprestimos WHERE status = 'ativo' AND escola_id = :escola_id";
if (!$is_bibliotecario && !$is_admin) {
    $sql_ativos .= " AND usuario_id = :usuario_id";
    $stmt = $conn->prepare($sql_ativos);
    $stmt->execute([':escola_id' => $escola_id, ':usuario_id' => $usuario_id]);
} else {
    $stmt = $conn->prepare($sql_ativos);
    $stmt->execute([':escola_id' => $escola_id]);
}
$stats['ativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$sql_atrasados = "SELECT COUNT(*) as total FROM emprestimos WHERE status = 'ativo' AND data_devolucao_prevista < CURDATE() AND escola_id = :escola_id";
if (!$is_bibliotecario && !$is_admin) {
    $sql_atrasados .= " AND usuario_id = :usuario_id";
    $stmt = $conn->prepare($sql_atrasados);
    $stmt->execute([':escola_id' => $escola_id, ':usuario_id' => $usuario_id]);
} else {
    $stmt = $conn->prepare($sql_atrasados);
    $stmt->execute([':escola_id' => $escola_id]);
}
$stats['atrasados'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$sql_devolvidos = "SELECT COUNT(*) as total FROM emprestimos WHERE status = 'devolvido' AND escola_id = :escola_id";
if (!$is_bibliotecario && !$is_admin) {
    $sql_devolvidos .= " AND usuario_id = :usuario_id";
    $stmt = $conn->prepare($sql_devolvidos);
    $stmt->execute([':escola_id' => $escola_id, ':usuario_id' => $usuario_id]);
} else {
    $stmt = $conn->prepare($sql_devolvidos);
    $stmt->execute([':escola_id' => $escola_id]);
}
$stats['devolvidos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Buscar livros disponíveis para empréstimo - com escola_id
$sql_livros = "
    SELECT id, titulo, autor, categoria, quantidade_disponivel 
    FROM livros 
    WHERE status = 'disponivel' AND quantidade_disponivel > 0 AND escola_id = :escola_id
    ORDER BY titulo ASC
";
$stmt_livros = $conn->prepare($sql_livros);
$stmt_livros->execute([':escola_id' => $escola_id]);
$livros_disponiveis = $stmt_livros->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuários para empréstimo (admin/bibliotecário) - com escola_id
$usuarios = [];
if ($is_bibliotecario || $is_admin) {
    $sql_usuarios = "SELECT id, nome, email FROM usuarios WHERE status = 'ativo' AND escola_id = :escola_id ORDER BY nome ASC";
    $stmt_usuarios = $conn->prepare($sql_usuarios);
    $stmt_usuarios->execute([':escola_id' => $escola_id]);
    $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
}

// Funções auxiliares
function getStatusEmprestimoBadge($status, $data_devolucao) {
    if ($status == 'devolvido') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Devolvido</span>';
    } elseif ($status == 'ativo' && strtotime($data_devolucao) < strtotime(date('Y-m-d'))) {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
    } else {
        return '<span class="badge bg-primary"><i class="fas fa-book"></i> Emprestado</span>';
    }
}

function getPrazoRestanteEmprestimo($data_devolucao) {
    $hoje = new DateTime();
    $devolucao = new DateTime($data_devolucao);
    $diferenca = $hoje->diff($devolucao);
    
    if ($hoje > $devolucao) {
        return '<span class="text-danger">Atrasado ' . $diferenca->days . ' dias</span>';
    } else {
        return '<span class="text-success">' . $diferenca->days . ' dias restantes</span>';
    }
}

function formatarDataEmprestimo($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHoraEmprestimo($data) {
    if (!$data) return '-';
    return date('d/m/Y H:i', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empréstimos da Biblioteca | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.5em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.75rem; color: #6c757d; }
        
        .emprestimo-card { transition: all 0.3s ease; height: 100%; }
        .emprestimo-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .emprestimo-atrasado { border-left: 4px solid #dc3545; }
        .emprestimo-ativo { border-left: 4px solid #28a745; }
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
        .livro-item, .usuario-item { cursor: pointer; transition: all 0.2s; }
        .livro-item:hover, .usuario-item:hover { background: #f8f9fa; }
        .selecionado { background: #e8f5e9 !important; border-left: 3px solid #006B3E; }
        
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .renovacoes-badge { background: #17a2b8; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-hand-holding-heart"></i> Empréstimos da Biblioteca</h2>
                <p>Gerencie os empréstimos de livros</p>
            </div>
            <div>
                <?php if ($is_bibliotecario || $is_admin): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoEmprestimo">
                    <i class="fas fa-plus"></i> Novo Empréstimo
                </button>
                <?php endif; ?>
                <a href="index.php" class="btn-voltar ms-2">
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
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Empréstimos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativos']; ?></div><div class="stat-label">Empréstimos Ativos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['atrasados']; ?></div><div class="stat-label">Atrasados</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['devolvidos']; ?></div><div class="stat-label">Devolvidos</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="ativos" <?php echo $status_filtro=='ativos'?'selected':''; ?>>Ativos</option><option value="atrasados" <?php echo $status_filtro=='atrasados'?'selected':''; ?>>Atrasados</option><option value="devolvidos" <?php echo $status_filtro=='devolvidos'?'selected':''; ?>>Devolvidos</option><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option></select></div>
                    <div class="col-md-6"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Título, autor ou usuário..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-3"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Empréstimos -->
        <div class="row">
            <?php if (empty($emprestimos)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-hand-holding-heart fa-3x text-muted mb-3"></i><h4>Nenhum empréstimo encontrado</h4><p>Não há empréstimos registrados.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($emprestimos as $emp): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card emprestimo-card <?php echo $emp['status']=='ativo' && strtotime($emp['data_devolucao_prevista']) < strtotime(date('Y-m-d')) ? 'emprestimo-atrasado' : ($emp['status']=='ativo' ? 'emprestimo-ativo' : ''); ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title"><?php echo htmlspecialchars($emp['livro_titulo']); ?></h6>
                                    <?php echo getStatusEmprestimoBadge($emp['status'], $emp['data_devolucao_prevista']); ?>
                                </div>
                                <p class="small text-muted"><i class="fas fa-user"></i> Autor: <?php echo htmlspecialchars($emp['livro_autor']); ?></p>
                                <p class="small text-muted"><i class="fas fa-user-circle"></i> Usuário: <?php echo htmlspecialchars($emp['usuario_nome']); ?></p>
                                <div class="small text-muted mt-2">
                                    <i class="fas fa-calendar-alt"></i> Empréstimo: <?php echo formatarDataEmprestimo($emp['data_emprestimo']); ?><br>
                                    <i class="fas fa-calendar-times"></i> Devolução prevista: <?php echo formatarDataEmprestimo($emp['data_devolucao_prevista']); ?>
                                    <?php if ($emp['status'] == 'ativo'): ?>
                                        <br><i class="fas fa-hourglass-half"></i> Prazo: <?php echo getPrazoRestanteEmprestimo($emp['data_devolucao_prevista']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (isset($emp['renovacoes']) && $emp['renovacoes'] > 0): ?>
                                    <div class="mt-1"><span class="renovacoes-badge"><i class="fas fa-sync-alt"></i> Renovado <?php echo $emp['renovacoes']; ?>x</span></div>
                                <?php endif; ?>
                                <?php if ($emp['status'] == 'devolvido' && $emp['data_devolucao_real']): ?>
                                    <div class="small text-muted mt-1"><i class="fas fa-check-circle text-success"></i> Devolvido em: <?php echo formatarDataEmprestimo($emp['data_devolucao_real']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_bibliotecario || $is_admin): ?>
                                <div class="card-footer bg-transparent text-center">
                                    <?php if ($emp['status'] == 'ativo'): ?>
                                        <a href="?devolver=<?php echo $emp['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Registrar devolução deste livro?')">
                                            <i class="fas fa-check-circle"></i> Devolver
                                        </a>
                                        <?php if (($emp['renovacoes'] ?? 0) < 2): ?>
                                            <a href="?renovar=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info" onclick="return confirm('Renovar empréstimo por mais 15 dias?')">
                                                <i class="fas fa-sync-alt"></i> Renovar
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Novo Empréstimo -->
    <?php if ($is_bibliotecario || $is_admin): ?>
    <div class="modal fade" id="modalNovoEmprestimo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Empréstimo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="novo_emprestimo.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Usuário <span class="text-danger">*</span></label>
                            <select name="usuario_id" class="form-select" required>
                                <option value="">Selecione um usuário</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?php echo $usuario['id']; ?>"><?php echo htmlspecialchars($usuario['nome']); ?> (<?php echo htmlspecialchars($usuario['email']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Livro <span class="text-danger">*</span></label>
                            <select name="livro_id" class="form-select" required>
                                <option value="">Selecione um livro</option>
                                <?php foreach ($livros_disponiveis as $livro): ?>
                                <option value="<?php echo $livro['id']; ?>"><?php echo htmlspecialchars($livro['titulo']); ?> (<?php echo $livro['quantidade_disponivel']; ?> disponível(eis))</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Empréstimo</label>
                                <input type="date" name="data_emprestimo" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Devolução Prevista</label>
                                <input type="date" name="data_devolucao" class="form-control" value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>" required>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Regras de Empréstimo:</strong><br>
                            - Prazo padrão: <strong>15 dias</strong><br>
                            - Renovações: <strong>máximo 2 vezes</strong> (total de 45 dias)<br>
                            - Multa por atraso: <strong>100 Kz/dia</strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="confirmar_emprestimo" class="btn btn-primary">Confirmar Empréstimo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Empréstimos</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-hand-holding-heart"></i> Sobre Empréstimos</h5><p>Sistema para gerenciar empréstimos de livros da biblioteca.</p></div><div class="ajuda-section"><h5><i class="fas fa-info-circle"></i> Regras</h5><ul><li>Prazo de empréstimo: 15 dias</li><li>Renovações: até 2 vezes (total 45 dias)</li><li>Multa por atraso: 100 Kz/dia</li><li>Limite: 3 livros por usuário</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-search"></i> Como gerenciar</h5><ul><li>Visualize empréstimos ativos e atrasados</li><li>Registre devoluções</li><li>Renove empréstimos quando necessário</li></ul></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="../suporte/faq.php" class="btn btn-primary">Ver FAQ</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
    </script>
</body>
</html>