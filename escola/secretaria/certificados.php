<?php
// escola/secretaria/certificados.php - Emissão e Gestão de Certificados

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
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$success = '';
$error = '';

// Função para gerar número automático de certificado
function gerarNumeroCertificado($conn, $escola_id, $tipo) {
    // Prefixos para cada tipo de certificado
    $prefixos = [
        'conclusao' => 'CERT-CON',
        'frequencia' => 'CERT-FRE',
        'aproveitamento' => 'CERT-APR',
        'participacao' => 'CERT-PAR',
        'estagio' => 'CERT-EST'
    ];
    
    $prefixo = $prefixos[$tipo] ?? 'CERT';
    $ano = date('Y');
    
    // Buscar o último número sequencial do ano
    $sql = "SELECT numero_certificado FROM certificados 
            WHERE escola_id = :escola_id 
            AND numero_certificado LIKE :prefixo 
            ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':prefixo' => $prefixo . '-' . $ano . '-%'
    ]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        // Extrair o número sequencial
        $partes = explode('-', $ultimo['numero_certificado']);
        $numero = (int)end($partes);
        $novo_numero = str_pad($numero + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $novo_numero = '0001';
    }
    
    return $prefixo . '-' . $ano . '-' . $novo_numero;
}

// Processar emissão de certificado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emitir_certificado'])) {
    $aluno_id = (int)($_POST['aluno_id'] ?? 0);
    $tipo = $_POST['tipo'] ?? 'conclusao';
    $data_emissao = $_POST['data_emissao'] ?? date('Y-m-d');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $assinado_por = trim($_POST['assinado_por'] ?? $_SESSION['usuario_nome']);
    
    if ($aluno_id <= 0) {
        $error = "Selecione um aluno.";
    } else {
        try {
            // Gerar número automático
            $numero_certificado = gerarNumeroCertificado($conn, $escola_id, $tipo);
            
            $sql = "INSERT INTO certificados (escola_id, aluno_id, tipo, numero_certificado, data_emissao, observacoes, assinado_por, status, created_by, created_at) 
                    VALUES (:escola_id, :aluno_id, :tipo, :numero, :data_emissao, :observacoes, :assinado_por, 'ativo', :created_by, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':tipo' => $tipo,
                ':numero' => $numero_certificado,
                ':data_emissao' => $data_emissao,
                ':observacoes' => $observacoes,
                ':assinado_por' => $assinado_por,
                ':created_by' => $usuario_id
            ]);
            $certificado_id = $conn->lastInsertId();
            $success = "Certificado #$numero_certificado emitido com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao emitir certificado: " . $e->getMessage();
        }
    }
}

// Processar cancelamento de certificado
if (($is_secretaria || $is_admin) && isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $cert_id = (int)$_GET['cancelar'];
    try {
        $sql = "UPDATE certificados SET status = 'cancelado', updated_at = NOW() WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $cert_id, ':escola_id' => $escola_id]);
        $success = "Certificado cancelado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao cancelar certificado: " . $e->getMessage();
    }
}

// Processar reativação de certificado
if (($is_secretaria || $is_admin) && isset($_GET['reativar']) && is_numeric($_GET['reativar'])) {
    $cert_id = (int)$_GET['reativar'];
    try {
        $sql = "UPDATE certificados SET status = 'ativo', updated_at = NOW() WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $cert_id, ':escola_id' => $escola_id]);
        $success = "Certificado reativado com sucesso!";
    } catch (Exception $e) {
        $error = "Erro ao reativar certificado: " . $e->getMessage();
    }
}

// Buscar alunos para o select
$sql_alunos = "SELECT e.id, e.nome, e.matricula, t.nome as turma_nome 
               FROM estudantes e 
               LEFT JOIN matriculas m ON m.estudante_id = e.id 
               LEFT JOIN turmas t ON t.id = m.turma_id 
               WHERE m.escola_id = :escola_id AND m.status = 'ativa' 
               GROUP BY e.id ORDER BY e.nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar certificados
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
if ($ano_filtro) {
    $where_conditions[] = "YEAR(c.data_emissao) = :ano";
    $params[':ano'] = $ano_filtro;
}
if (!empty($busca)) {
    $where_conditions[] = "(c.numero_certificado LIKE :busca OR e.nome LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) . " AND c.escola_id = :escola_id" : "WHERE c.escola_id = :escola_id";

$sql_certificados = "
    SELECT c.*, e.nome as aluno_nome, e.matricula, t.nome as turma_nome, u.nome as emissor_nome
    FROM certificados c
    LEFT JOIN estudantes e ON e.id = c.aluno_id
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    LEFT JOIN usuarios u ON u.id = c.created_by
    $where_sql
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_certificados);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(DISTINCT c.id) as total FROM certificados c LEFT JOIN estudantes e ON e.id = c.aluno_id " . str_replace("GROUP BY c.id ORDER BY c.created_at DESC", "", $where_sql);
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_certificados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_certificados / $limit);

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id AND tipo = 'conclusao'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['conclusao'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id AND tipo = 'frequencia'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['frequencia'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id AND tipo = 'aproveitamento'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['aproveitamento'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id AND tipo = 'participacao'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['participacao'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM certificados WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['ativos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Tipos de certificados
$tipos_certificados = [
    'conclusao' => ['nome' => 'Conclusão de Curso', 'icone' => 'fas fa-graduation-cap', 'cor' => 'primary'],
    'frequencia' => ['nome' => 'Frequência', 'icone' => 'fas fa-calendar-check', 'cor' => 'success'],
    'aproveitamento' => ['nome' => 'Aproveitamento', 'icone' => 'fas fa-chart-line', 'cor' => 'info'],
    'participacao' => ['nome' => 'Participação', 'icone' => 'fas fa-users', 'cor' => 'warning'],
    'estagio' => ['nome' => 'Estágio', 'icone' => 'fas fa-briefcase', 'cor' => 'secondary']
];

function getTipoInfo($tipo) {
    global $tipos_certificados;
    return $tipos_certificados[$tipo] ?? $tipos_certificados['conclusao'];
}

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
    }
}

function formatarData($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarNumeroCertificado($numero) {
    return strtoupper($numero);
}

// Função para buscar o próximo número via AJAX
function getProximoNumeroAJAX($conn, $escola_id, $tipo) {
    return gerarNumeroCertificado($conn, $escola_id, $tipo);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificados | Secretaria | SIGE Angola</title>
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
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-value { font-size: 1.5em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.75rem; color: #6c757d; }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .certificado-card { transition: all 0.3s ease; height: 100%; }
        .certificado-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .certificado-icon { font-size: 2rem; margin-bottom: 10px; }
        .numero-certificado { font-family: monospace; font-size: 0.9rem; letter-spacing: 1px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .admin-actions { position: absolute; top: 10px; right: 10px; z-index: 10; }
        .certificado-card { position: relative; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-close-white { filter: invert(1); }
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        .numero-auto { background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 5px; padding: 8px 12px; font-family: monospace; font-size: 1rem; }
        .info-numero { font-size: 0.75rem; color: #2e7d32; margin-top: 5px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-certificate"></i> Emissão de Certificados</h2>
                <p>Gestão de certificados escolares - Conclusão, Frequência, Aproveitamento</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalEmitirCertificado">
                    <i class="fas fa-plus"></i> Emitir Certificado
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
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Certificados</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['conclusao']; ?></div><div class="stat-label">Conclusão</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['frequencia']; ?></div><div class="stat-label">Frequência</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['aproveitamento']; ?></div><div class="stat-label">Aproveitamento</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['participacao']; ?></div><div class="stat-label">Participação</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativos']; ?></div><div class="stat-label">Ativos</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2"><label class="filter-label">Tipo</label><select name="tipo" class="form-select"><option value="todos" <?php echo $tipo_filtro=='todos'?'selected':''; ?>>Todos</option><option value="conclusao" <?php echo $tipo_filtro=='conclusao'?'selected':''; ?>>Conclusão</option><option value="frequencia" <?php echo $tipo_filtro=='frequencia'?'selected':''; ?>>Frequência</option><option value="aproveitamento" <?php echo $tipo_filtro=='aproveitamento'?'selected':''; ?>>Aproveitamento</option><option value="participacao" <?php echo $tipo_filtro=='participacao'?'selected':''; ?>>Participação</option><option value="estagio" <?php echo $tipo_filtro=='estagio'?'selected':''; ?>>Estágio</option></select></div>
                    <div class="col-md-2"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option><option value="ativo" <?php echo $status_filtro=='ativo'?'selected':''; ?>>Ativo</option><option value="cancelado" <?php echo $status_filtro=='cancelado'?'selected':''; ?>>Cancelado</option></select></div>
                    <div class="col-md-2"><label class="filter-label">Ano</label><select name="ano" class="form-select"><?php for($i=date('Y'); $i>=date('Y')-5; $i--): ?><option value="<?php echo $i; ?>" <?php echo $ano_filtro==$i?'selected':''; ?>><?php echo $i; ?></option><?php endfor; ?></select></div>
                    <div class="col-md-4"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Nº Certificado ou Nome do Aluno..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-2"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Certificados -->
        <div class="row">
            <?php if (empty($certificados)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-certificate fa-3x text-muted mb-3"></i><h4>Nenhum certificado encontrado</h4><p>Clique em "Emitir Certificado" para começar.</p></div></div></div>
            <?php else: ?>
                <?php foreach ($certificados as $cert): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card certificado-card">
                        <?php if ($is_secretaria || $is_admin): ?>
                        <div class="admin-actions">
                            <?php if ($cert['status'] == 'ativo'): ?>
                                <a href="?cancelar=<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancelar este certificado?')"><i class="fas fa-ban"></i></a>
                            <?php else: ?>
                                <a href="?reativar=<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('Reativar este certificado?')"><i class="fas fa-check-circle"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="card-body text-center">
                            <div class="certificado-icon"><?php echo getTipoInfo($cert['tipo'])['icone']; ?></div>
                            <h6 class="card-title mt-2"><?php echo htmlspecialchars($cert['aluno_nome']); ?></h6>
                            <p class="small text-muted">Matrícula: <?php echo $cert['matricula'] ?? 'N/A'; ?></p>
                            <p class="small text-muted">Turma: <?php echo $cert['turma_nome'] ?? 'N/A'; ?></p>
                            <div class="numero-certificado mb-2"><strong>Nº:</strong> <?php echo formatarNumeroCertificado($cert['numero_certificado']); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap">
                                <span class="badge bg-<?php echo getTipoInfo($cert['tipo'])['cor']; ?>"><?php echo getTipoInfo($cert['tipo'])['nome']; ?></span>
                                <?php echo getStatusBadge($cert['status']); ?>
                            </div>
                            <div class="mt-2 small text-muted"><i class="fas fa-calendar-alt"></i> Emitido em: <?php echo formatarData($cert['data_emissao']); ?></div>
                            <div class="small text-muted"><i class="fas fa-user"></i> Emitido por: <?php echo htmlspecialchars($cert['emissor_nome'] ?? 'N/A'); ?></div>
                            <div class="small text-muted"><i class="fas fa-signature"></i> Assinado por: <?php echo htmlspecialchars($cert['assinado_por'] ?? 'Direção'); ?></div>
                            <?php if ($cert['observacoes']): ?><div class="small text-warning mt-1"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($cert['observacoes']); ?></div><?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent text-center">
                            <button class="btn btn-sm btn-primary" onclick="imprimirCertificado(<?php echo $cert['id']; ?>, '<?php echo $cert['tipo']; ?>')">
                                <i class="fas fa-print"></i> Imprimir
                            </button>
                            <button class="btn btn-sm btn-info" onclick="visualizarCertificado(<?php echo $cert['id']; ?>, '<?php echo $cert['tipo']; ?>')">
                                <i class="fas fa-eye"></i> Visualizar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&status=<?php echo urlencode($status_filtro); ?>&ano=<?php echo $ano_filtro; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Emitir Certificado (COM NÚMERO AUTOMÁTICO) -->
    <div class="modal fade" id="modalEmitirCertificado" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Emitir Novo Certificado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formEmitirCertificado">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Aluno <span class="text-danger">*</span></label>
                            <select name="aluno_id" class="form-select" required>
                                <option value="">Selecione um aluno</option>
                                <?php foreach ($alunos as $aluno): ?>
                                <option value="<?php echo $aluno['id']; ?>"><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?> - <?php echo $aluno['turma_nome']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Certificado <span class="text-danger">*</span></label>
                                <select name="tipo" id="tipo_certificado" class="form-select" required>
                                    <option value="conclusao">🎓 Conclusão de Curso</option>
                                    <option value="frequencia">📅 Certificado de Frequência</option>
                                    <option value="aproveitamento">📊 Certificado de Aproveitamento</option>
                                    <option value="participacao">👥 Certificado de Participação</option>
                                    <option value="estagio">💼 Certificado de Estágio</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Número do Certificado <span class="text-danger">*</span></label>
                                <div class="numero-auto" id="numero_certificado_display" style="background: #e8f5e9; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 1rem;">
                                    <i class="fas fa-spinner fa-spin"></i> Gerando...
                                </div>
                                <input type="hidden" name="numero_certificado" id="numero_certificado_input">
                                <small class="text-muted info-numero"><i class="fas fa-info-circle"></i> Número gerado automaticamente seguindo a sequência</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Emissão</label>
                                <input type="date" name="data_emissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assinado por</label>
                                <input type="text" name="assinado_por" class="form-control" value="<?php echo $usuario_nome; ?>" placeholder="Nome do assinante">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="emitir_certificado" class="btn btn-primary">
                            <i class="fas fa-save"></i> Emitir Certificado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Certificado -->
    <div class="modal fade" id="modalVisualizarCertificado" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-certificate"></i> Certificado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="certificadoConteudo">
                    <div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Carregando certificado...</p></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Certificados</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-certificate"></i> Sobre a Emissão de Certificados</h5>
                        <p>Sistema para emissão e gestão de certificados escolares.</p>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-file-alt"></i> Tipos de Certificados</h5>
                        <ul>
                            <li><strong>Conclusão:</strong> Para alunos que concluíram o curso</li>
                            <li><strong>Frequência:</strong> Comprovação de frequência escolar</li>
                            <li><strong>Aproveitamento:</strong> Certificado de desempenho acadêmico</li>
                            <li><strong>Participação:</strong> Para eventos e atividades</li>
                            <li><strong>Estágio:</strong> Comprovação de estágio curricular</li>
                        </ul>
                    </div>
                    <div class="ajuda-section">
                        <h5><i class="fas fa-print"></i> Como emitir</h5>
                        <ul>
                            <li>Clique em "Emitir Certificado"</li>
                            <li>Selecione o aluno e o tipo</li>
                            <li>O número do certificado é gerado automaticamente</li>
                            <li>Após emitir, é possível imprimir ou visualizar</li>
                        </ul>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Formato do número:</strong> CERT-TIPO-ANO-SEQUENCIAL<br>
                        Exemplo: CERT-CON-2024-0001, CERT-FRE-2024-0002
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="../suporte/faq.php" class="btn btn-primary">Ver FAQ</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('.sidebar').toggleClass('active'); $('.main-content').toggleClass('active'); });
        $('#btnAjuda').click(function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        
        // Função para buscar o próximo número de certificado via AJAX
        function buscarProximoNumero() {
            var tipo = $('#tipo_certificado').val();
            
            $.ajax({
                url: 'ajax_gerar_numero.php',
                method: 'POST',
                data: { tipo: tipo },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#numero_certificado_display').html('<i class="fas fa-certificate"></i> ' + response.numero);
                        $('#numero_certificado_input').val(response.numero);
                    } else {
                        $('#numero_certificado_display').html('<i class="fas fa-exclamation-triangle text-danger"></i> Erro ao gerar número');
                    }
                },
                error: function() {
                    $('#numero_certificado_display').html('<i class="fas fa-exclamation-triangle text-danger"></i> Erro ao conectar');
                }
            });
        }
        
        // Gerar número automaticamente ao abrir o modal e ao mudar o tipo
        $('#modalEmitirCertificado').on('show.bs.modal', function() {
            buscarProximoNumero();
        });
        
        $('#tipo_certificado').change(function() {
            buscarProximoNumero();
        });
        
        function visualizarCertificado(id, tipo) {
            $('#certificadoConteudo').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>Carregando...</p></div>');
            $('#modalVisualizarCertificado').modal('show');
            
            $.ajax({
                url: 'visualizar_certificado.php',
                method: 'POST',
                data: { id: id, tipo: tipo },
                dataType: 'html',
                success: function(data) {
                    $('#certificadoConteudo').html(data);
                },
                error: function() {
                    $('#certificadoConteudo').html('<div class="alert alert-danger">Erro ao carregar certificado.</div>');
                }
            });
        }
        
        function imprimirCertificado(id, tipo) {
            window.open('imprimir_certificado.php?id=' + id + '&tipo=' + tipo, '_blank', 'width=800,height=600');
        }
    </script>
</body>
</html>