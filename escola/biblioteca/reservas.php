<?php
// escola/biblioteca/reservas.php - Sistema de Reservas da Biblioteca

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
$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_secretaria = ($usuario_tipo == 'secretaria' || $papel == 'secretaria');
$is_bibliotecario = ($papel == 'bibliotecario' || $is_admin || $is_secretaria);

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'ativas';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$success = '';
$error = '';

// ============================================
// PROCESSAR RESERVA DE LIVRO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fazer_reserva'])) {
    $livro_id = (int)$_POST['livro_id'];
    $data_reserva = date('Y-m-d');
    $data_limite = date('Y-m-d', strtotime('+7 days'));
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    if ($livro_id <= 0) {
        $error = "Selecione um livro válido.";
    } else {
        try {
            // Verificar se o livro está disponível - com escola_id
            $sql_check = "SELECT id, titulo, quantidade, quantidade_disponivel FROM livros WHERE id = :id AND status = 'disponivel' AND escola_id = :escola_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':id' => $livro_id, ':escola_id' => $escola_id]);
            $livro = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$livro) {
                $error = "Livro não encontrado ou indisponível.";
            } elseif ($livro['quantidade_disponivel'] <= 0) {
                $error = "Este livro não está disponível para reserva no momento.";
            } else {
                // Verificar se o usuário já tem reserva ativa para este livro - com escola_id
                $sql_user_reserva = "SELECT COUNT(*) as total FROM reservas WHERE livro_id = :livro_id AND usuario_id = :usuario_id AND status IN ('reservado', 'pendente') AND escola_id = :escola_id";
                $stmt_user_reserva = $conn->prepare($sql_user_reserva);
                $stmt_user_reserva->execute([':livro_id' => $livro_id, ':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
                if ($stmt_user_reserva->fetch(PDO::FETCH_ASSOC)['total'] > 0) {
                    $error = "Você já possui uma reserva ativa para este livro.";
                } else {
                    // Verificar limite de reservas do usuário (máximo 3) - com escola_id
                    $sql_limite = "SELECT COUNT(*) as total FROM reservas WHERE usuario_id = :usuario_id AND status IN ('reservado', 'pendente') AND escola_id = :escola_id";
                    $stmt_limite = $conn->prepare($sql_limite);
                    $stmt_limite->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
                    $total_reservas = $stmt_limite->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    if ($total_reservas >= 3) {
                        $error = "Você atingiu o limite máximo de 3 reservas simultâneas.";
                    } else {
                        // Criar reserva - com escola_id
                        $sql = "INSERT INTO reservas (livro_id, usuario_id, escola_id, data_reserva, data_limite, observacoes, status, created_at) 
                                VALUES (:livro_id, :usuario_id, :escola_id, :data_reserva, :data_limite, :observacoes, 'pendente', NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            ':livro_id' => $livro_id,
                            ':usuario_id' => $usuario_id,
                            ':escola_id' => $escola_id,
                            ':data_reserva' => $data_reserva,
                            ':data_limite' => $data_limite,
                            ':observacoes' => $observacoes
                        ]);
                        
                        // Atualizar quantidade disponível do livro - com escola_id
                        $sql_update = "UPDATE livros SET quantidade_disponivel = quantidade_disponivel - 1 WHERE id = :id AND escola_id = :escola_id";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->execute([':id' => $livro_id, ':escola_id' => $escola_id]);
                        
                        $success = "Reserva realizada com sucesso! Você tem até " . date('d/m/Y', strtotime($data_limite)) . " para retirar o livro.";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "Erro ao fazer reserva: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR CANCELAMENTO DE RESERVA (Admin/Bibliotecário)
// ============================================
if (($is_bibliotecario || $is_admin) && isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $reserva_id = (int)$_GET['cancelar'];
    try {
        // Buscar livro_id para atualizar quantidade - com escola_id
        $sql_livro = "SELECT livro_id FROM reservas WHERE id = :id AND escola_id = :escola_id";
        $stmt_livro = $conn->prepare($sql_livro);
        $stmt_livro->execute([':id' => $reserva_id, ':escola_id' => $escola_id]);
        $livro = $stmt_livro->fetch(PDO::FETCH_ASSOC);
        
        if ($livro) {
            // Atualizar quantidade disponível do livro - com escola_id
            $sql_update = "UPDATE livros SET quantidade_disponivel = quantidade_disponivel + 1 WHERE id = :id AND escola_id = :escola_id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $livro['livro_id'], ':escola_id' => $escola_id]);
        }
        
        $sql = "UPDATE reservas SET status = 'cancelada', updated_at = NOW() WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $reserva_id, ':escola_id' => $escola_id]);
        $success = "Reserva cancelada com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao cancelar reserva: " . $e->getMessage();
    }
}

// ============================================
// PROCESSAR CONFIRMAÇÃO DE RETIRADA (Admin/Bibliotecário)
// ============================================
if (($is_bibliotecario || $is_admin) && isset($_GET['confirmar']) && is_numeric($_GET['confirmar'])) {
    $reserva_id = (int)$_GET['confirmar'];
    try {
        $sql = "UPDATE reservas SET status = 'retirado', data_retirada = NOW(), updated_at = NOW() WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $reserva_id, ':escola_id' => $escola_id]);
        
        // Registrar empréstimo - com escola_id
        $sql_reserva = "SELECT livro_id, usuario_id FROM reservas WHERE id = :id AND escola_id = :escola_id";
        $stmt_reserva = $conn->prepare($sql_reserva);
        $stmt_reserva->execute([':id' => $reserva_id, ':escola_id' => $escola_id]);
        $reserva = $stmt_reserva->fetch(PDO::FETCH_ASSOC);
        
        if ($reserva) {
            $data_emprestimo = date('Y-m-d');
            $data_devolucao = date('Y-m-d', strtotime('+15 days'));
            
            $sql_emprestimo = "INSERT INTO emprestimos (livro_id, usuario_id, escola_id, data_emprestimo, data_devolucao_prevista, status, created_at) 
                               VALUES (:livro_id, :usuario_id, :escola_id, :data_emprestimo, :data_devolucao, 'ativo', NOW())";
            $stmt_emprestimo = $conn->prepare($sql_emprestimo);
            $stmt_emprestimo->execute([
                ':livro_id' => $reserva['livro_id'],
                ':usuario_id' => $reserva['usuario_id'],
                ':escola_id' => $escola_id,
                ':data_emprestimo' => $data_emprestimo,
                ':data_devolucao' => $data_devolucao
            ]);
        }
        
        $success = "Retirada confirmada! Empréstimo registrado com sucesso.";
    } catch (Exception $e) {
        $error = "Erro ao confirmar retirada: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR RESERVAS
// ============================================
$where_conditions = [];
$params = [':escola_id' => $escola_id];

// Se não for admin/bibliotecário, mostrar apenas reservas do usuário
if (!$is_bibliotecario && !$is_admin) {
    $where_conditions[] = "r.usuario_id = :usuario_id";
    $params[':usuario_id'] = $usuario_id;
}

if ($status_filtro != 'todas') {
    $where_conditions[] = "r.status = :status";
    $params[':status'] = $status_filtro;
}

if ($tipo_filtro != 'todos') {
    $where_conditions[] = "l.categoria = :categoria";
    $params[':categoria'] = $tipo_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(l.titulo LIKE :busca OR l.autor LIKE :busca OR u.nome LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_conditions[] = "r.escola_id = :escola_id";
$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE r.escola_id = :escola_id";

$sql_reservas = "
    SELECT r.*, l.titulo as livro_titulo, l.autor as livro_autor, l.capa, 
           u.nome as usuario_nome, u.email as usuario_email
    FROM reservas r
    JOIN livros l ON l.id = r.livro_id
    JOIN usuarios u ON u.id = r.usuario_id
    $where_sql
    ORDER BY 
        CASE r.status 
            WHEN 'pendente' THEN 1 
            WHEN 'reservado' THEN 2 
            WHEN 'retirado' THEN 3 
            WHEN 'cancelado' THEN 4 
        END,
        r.data_reserva DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_reservas);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM reservas r JOIN livros l ON l.id = r.livro_id $where_sql";
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_reservas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_reservas / $limit);

// Estatísticas - com escola_id
$stats = [];
$stats['total'] = $total_reservas;

$sql_pendentes = "SELECT COUNT(*) as total FROM reservas WHERE status = 'pendente' AND escola_id = :escola_id";
$stmt = $conn->prepare($sql_pendentes);
$stmt->execute([':escola_id' => $escola_id]);
$stats['pendentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$sql_ativas = "SELECT COUNT(*) as total FROM reservas WHERE status IN ('pendente', 'reservado') AND escola_id = :escola_id";
$stmt = $conn->prepare($sql_ativas);
$stmt->execute([':escola_id' => $escola_id]);
$stats['ativas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$sql_finalizadas = "SELECT COUNT(*) as total FROM reservas WHERE status IN ('retirado', 'cancelado') AND escola_id = :escola_id";
$stmt = $conn->prepare($sql_finalizadas);
$stmt->execute([':escola_id' => $escola_id]);
$stats['finalizadas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Buscar livros disponíveis para reserva - com escola_id
$sql_livros = "
    SELECT id, titulo, autor, categoria, capa, quantidade_disponivel 
    FROM livros 
    WHERE status = 'disponivel' AND quantidade_disponivel > 0 AND escola_id = :escola_id
    ORDER BY titulo ASC
";
$stmt_livros = $conn->prepare($sql_livros);
$stmt_livros->execute([':escola_id' => $escola_id]);
$livros_disponiveis = $stmt_livros->fetchAll(PDO::FETCH_ASSOC);

// Funções auxiliares
function getStatusReservaBadge($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'reservado':
            return '<span class="badge bg-info"><i class="fas fa-bookmark"></i> Reservado</span>';
        case 'retirado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Retirado</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function formatarDataReserva($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHoraReserva($data) {
    if (!$data) return '-';
    return date('d/m/Y H:i', strtotime($data));
}

function isReservaVencida($data_limite) {
    return strtotime($data_limite) < strtotime(date('Y-m-d'));
}

function getPrazoRestante($data_limite) {
    $hoje = new DateTime();
    $limite = new DateTime($data_limite);
    $diferenca = $hoje->diff($limite);
    return $diferenca->days;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservas da Biblioteca | SIGE Angola</title>
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
        
        .reserva-card { transition: all 0.3s ease; height: 100%; }
        .reserva-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .reserva-vencida { border-left: 4px solid #dc3545; }
        .reserva-pendente { border-left: 4px solid #ffc107; }
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
        .livro-item { cursor: pointer; transition: all 0.2s; }
        .livro-item:hover { background: #f8f9fa; }
        .livro-selecionado { background: #e8f5e9 !important; border-left: 3px solid #006B3E; }
        
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-bookmark"></i> Reservas da Biblioteca</h2>
                <p>Gerencie suas reservas de livros</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalReservar">
                    <i class="fas fa-plus"></i> Nova Reserva
                </button>
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
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Reservas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativas']; ?></div><div class="stat-label">Reservas Ativas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['pendentes']; ?></div><div class="stat-label">Aguardando Retirada</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['finalizadas']; ?></div><div class="stat-label">Finalizadas</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="ativas" <?php echo $status_filtro=='ativas'?'selected':''; ?>>Ativas</option><option value="pendente" <?php echo $status_filtro=='pendente'?'selected':''; ?>>Pendentes</option><option value="retirado" <?php echo $status_filtro=='retirado'?'selected':''; ?>>Retirados</option><option value="cancelado" <?php echo $status_filtro=='cancelado'?'selected':''; ?>>Cancelados</option><option value="todas" <?php echo $status_filtro=='todas'?'selected':''; ?>>Todas</option></select></div>
                    <div class="col-md-3"><label class="filter-label">Categoria</label><select name="tipo" class="form-select"><option value="todos" <?php echo $tipo_filtro=='todos'?'selected':''; ?>>Todas</option><option value="didatico" <?php echo $tipo_filtro=='didatico'?'selected':''; ?>>Didático</option><option value="literatura" <?php echo $tipo_filtro=='literatura'?'selected':''; ?>>Literatura</option><option value="cientifico" <?php echo $tipo_filtro=='cientifico'?'selected':''; ?>>Científico</option><option value="infantil" <?php echo $tipo_filtro=='infantil'?'selected':''; ?>>Infantil</option></select></div>
                    <div class="col-md-4"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Título, autor ou usuário..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-2"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Reservas -->
        <div class="row">
            <?php if (empty($reservas)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-bookmark fa-3x text-muted mb-3"></i><h4>Nenhuma reserva encontrada</h4><p>Clique em "Nova Reserva" para reservar um livro.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($reservas as $reserva): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card reserva-card <?php echo isReservaVencida($reserva['data_limite']) && $reserva['status']=='pendente' ? 'reserva-vencida' : ($reserva['status']=='pendente' ? 'reserva-pendente' : ''); ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title"><?php echo htmlspecialchars($reserva['livro_titulo']); ?></h6>
                                    <?php echo getStatusReservaBadge($reserva['status']); ?>
                                </div>
                                <p class="small text-muted"><i class="fas fa-user"></i> Autor: <?php echo htmlspecialchars($reserva['livro_autor']); ?></p>
                                <p class="small text-muted"><i class="fas fa-user-circle"></i> Reservado por: <?php echo htmlspecialchars($reserva['usuario_nome']); ?></p>
                                <div class="small text-muted mt-2">
                                    <i class="fas fa-calendar-alt"></i> Reserva: <?php echo formatarDataReserva($reserva['data_reserva']); ?><br>
                                    <i class="fas fa-hourglass-half"></i> Limite retirada: <?php echo formatarDataReserva($reserva['data_limite']); ?>
                                    <?php if ($reserva['status'] == 'pendente'): ?>
                                        <?php if (isReservaVencida($reserva['data_limite'])): ?>
                                            <span class="badge bg-danger ms-2">Vencida</span>
                                        <?php else: ?>
                                            <span class="badge bg-info ms-2"><?php echo getPrazoRestante($reserva['data_limite']); ?> dias restantes</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($reserva['status'] == 'retirado' && $reserva['data_retirada']): ?>
                                    <div class="small text-muted mt-1"><i class="fas fa-check-circle text-success"></i> Retirado em: <?php echo formatarDataReserva($reserva['data_retirada']); ?></div>
                                <?php endif; ?>
                                <?php if ($reserva['observacoes']): ?>
                                    <div class="small text-warning mt-2"><i class="fas fa-comment"></i> Obs: <?php echo htmlspecialchars($reserva['observacoes']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_bibliotecario || $is_admin): ?>
                                <div class="card-footer bg-transparent text-center">
                                    <?php if ($reserva['status'] == 'pendente'): ?>
                                        <a href="?confirmar=<?php echo $reserva['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Confirmar retirada deste livro?')">
                                            <i class="fas fa-check-circle"></i> Confirmar Retirada
                                        </a>
                                        <a href="?cancelar=<?php echo $reserva['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancelar esta reserva?')">
                                            <i class="fas fa-times-circle"></i> Cancelar
                                        </a>
                                    <?php elseif ($reserva['status'] == 'reservado'): ?>
                                        <a href="?cancelar=<?php echo $reserva['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancelar esta reserva?')">
                                            <i class="fas fa-times-circle"></i> Cancelar
                                        </a>
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
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Nova Reserva -->
    <div class="modal fade" id="modalReservar" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Reserva</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Selecione o Livro <span class="text-danger">*</span></label>
                            <div class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($livros_disponiveis as $livro): ?>
                                <div class="list-group-item list-group-item-action livro-item" data-id="<?php echo $livro['id']; ?>" data-titulo="<?php echo htmlspecialchars($livro['titulo']); ?>" onclick="selecionarLivro(this)">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($livro['titulo']); ?></strong>
                                            <br><small class="text-muted">Autor: <?php echo htmlspecialchars($livro['autor']); ?></small>
                                            <br><small class="text-muted">Categoria: <?php echo ucfirst($livro['categoria']); ?></small>
                                        </div>
                                        <span class="badge bg-success"><?php echo $livro['quantidade_disponivel']; ?> disponível(eis)</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="livro_id" id="livro_id" required>
                            <small class="text-muted">Clique no livro desejado para selecionar</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações (opcional)</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais sobre a reserva..."></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Informações importantes:</strong><br>
                            - Você tem até <strong>7 dias</strong> para retirar o livro após a reserva<br>
                            - Máximo de <strong>3 reservas ativas</strong> por usuário<br>
                            - Ao retirar o livro, você terá <strong>15 dias</strong> para devolução
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="fazer_reserva" class="btn btn-primary">Confirmar Reserva</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Reservas</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-bookmark"></i> Sobre Reservas</h5><p>Sistema para reservar livros da biblioteca.</p></div><div class="ajuda-section"><h5><i class="fas fa-info-circle"></i> Regras</h5><ul><li>Prazo de retirada: 7 dias após a reserva</li><li>Limite: 3 reservas ativas por usuário</li><li>Prazo de devolução: 15 dias após retirada</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-search"></i> Como reservar</h5><ul><li>Clique em "Nova Reserva"</li><li>Selecione o livro desejado</li><li>Confirme a reserva</li><li>Dirija-se à biblioteca para retirar o livro</li></ul></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="../suporte/faq.php" class="btn btn-primary">Ver FAQ</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        
        let livroSelecionado = null;
        
        function selecionarLivro(element) {
            document.querySelectorAll('.livro-item').forEach(item => item.classList.remove('livro-selecionado'));
            element.classList.add('livro-selecionado');
            const livroId = element.dataset.id;
            const livroTitulo = element.dataset.titulo;
            document.getElementById('livro_id').value = livroId;
            livroSelecionado = livroTitulo;
        }
        
        document.querySelector('#modalReservar form')?.addEventListener('submit', function(e) {
            if (!document.getElementById('livro_id').value) {
                e.preventDefault();
                alert('Selecione um livro para reservar');
            }
        });
    </script>
</body>
</html>