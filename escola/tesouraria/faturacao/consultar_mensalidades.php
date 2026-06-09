<?php
// escola/tesouraria/faturacao/consultar_mensalidades.php - Consulta de Mensalidades

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_filtro = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ============================================
// BUSCAR DADOS PARA FILTROS
// ============================================

// Buscar anos letivos
$sql_anos = "SELECT id, ano,ativo FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CONSULTAR MENSALIDADES
// ============================================

$where = "m.escola_id = :escola_id";

if ($status_filtro != 'todos') {
    $where .= " AND m.status = :status";
}
if ($turma_filtro > 0) {
    $where .= " AND mat.turma_id = :turma_id";
}
if ($ano_letivo_filtro > 0) {
    $where .= " AND m.ano_letivo_id = :ano_letivo_id";
}
if ($mes_filtro > 0) {
    $where .= " AND m.mes_referencia = :mes";
}
if (!empty($busca)) {
    $where .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca)";
}

// Query principal
$sql_mensalidades = "
    SELECT m.*, 
           e.nome as aluno_nome, 
           e.matricula,
           t.nome as turma_nome, 
           t.ano as turma_ano,
           al.ano as ano_letivo,
           al.ano as ano_letivo_nome
    FROM mensalidades m
    INNER JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    LEFT JOIN ano_letivo al ON al.id = m.ano_letivo_id
    WHERE $where
    GROUP BY m.id
    ORDER BY m.data_vencimento ASC, e.nome ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_mensalidades);
$stmt->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

if ($status_filtro != 'todos') {
    $stmt->bindParam(':status', $status_filtro, PDO::PARAM_STR);
}
if ($turma_filtro > 0) {
    $stmt->bindParam(':turma_id', $turma_filtro, PDO::PARAM_INT);
}
if ($ano_letivo_filtro > 0) {
    $stmt->bindParam(':ano_letivo_id', $ano_letivo_filtro, PDO::PARAM_INT);
}
if ($mes_filtro > 0) {
    $stmt->bindParam(':mes', $mes_filtro, PDO::PARAM_INT);
}
if (!empty($busca)) {
    $busca_param = "%$busca%";
    $stmt->bindParam(':busca', $busca_param, PDO::PARAM_STR);
}

$stmt->execute();
$mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query para total de registros
$sql_total = "
    SELECT COUNT(DISTINCT m.id) as total 
    FROM mensalidades m
    INNER JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    LEFT JOIN ano_letivo al ON al.id = m.ano_letivo_id
    WHERE $where
";

$stmt_total = $conn->prepare($sql_total);
$stmt_total->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);

if ($status_filtro != 'todos') {
    $stmt_total->bindParam(':status', $status_filtro, PDO::PARAM_STR);
}
if ($turma_filtro > 0) {
    $stmt_total->bindParam(':turma_id', $turma_filtro, PDO::PARAM_INT);
}
if ($ano_letivo_filtro > 0) {
    $stmt_total->bindParam(':ano_letivo_id', $ano_letivo_filtro, PDO::PARAM_INT);
}
if ($mes_filtro > 0) {
    $stmt_total->bindParam(':mes', $mes_filtro, PDO::PARAM_INT);
}
if (!empty($busca)) {
    $stmt_total->bindParam(':busca', $busca_param, PDO::PARAM_STR);
}

$stmt_total->execute();
$total_mensalidades = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_mensalidades / $limit);

// ============================================
// ESTATÍSTICAS
// ============================================

// Estatísticas gerais
$sql_stats = "SELECT 
                COUNT(*) as total,
                SUM(valor_total) as valor_total,
                SUM(valor_pago) as valor_pago,
                SUM(valor_total - valor_pago) as valor_devedor,
                COUNT(CASE WHEN status = 'pago' THEN 1 END) as pagos,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                COUNT(CASE WHEN status = 'parcial' THEN 1 END) as parciais,
                COUNT(CASE WHEN status = 'atrasado' THEN 1 END) as atrasados
              FROM mensalidades 
              WHERE escola_id = :escola_id";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':escola_id' => $escola_id]);
$stats_gerais = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Estatísticas por turma
$sql_stats_turma = "SELECT 
                      t.nome as turma_nome,
                      t.ano as turma_ano,
                      COUNT(m.id) as total,
                      SUM(m.valor_total - m.valor_pago) as devedor
                    FROM mensalidades m
                    INNER JOIN matriculas mat ON mat.estudante_id = m.aluno_id
                    INNER JOIN turmas t ON t.id = mat.turma_id
                    WHERE m.escola_id = :escola_id
                    GROUP BY mat.turma_id
                    ORDER BY devedor DESC
                    LIMIT 5";
$stmt_stats_turma = $conn->prepare($sql_stats_turma);
$stmt_stats_turma->execute([':escola_id' => $escola_id]);
$stats_turmas = $stmt_stats_turma->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'parcial':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Parcial</span>';
        case 'pendente':
            return '<span class="badge bg-secondary"><i class="fas fa-hourglass-half"></i> Pendente</span>';
        case 'atrasado':
            return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Mensalidades | Faturação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; position: relative; overflow: hidden; }
        .stat-card h3 { font-size: 1.8rem; margin: 0; font-weight: bold; }
        .stat-card p { margin: 0; opacity: 0.9; }
        .stat-card .icon { font-size: 2.5rem; opacity: 0.3; position: absolute; right: 20px; top: 20px; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .mensalidade-row.atrasado { background-color: #fff3cd; }
        .mensalidade-row.pendente { background-color: #f8f9fa; }
        .mensalidade-row.pago { background-color: #d4edda; }
        
        .badge { font-size: 0.75rem; padding: 5px 10px; }
        
        .progress-bar-custom { height: 8px; border-radius: 4px; }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-search"></i> Consultar Mensalidades</h2>
                <p class="text-muted">Consulta avançada de mensalidades com filtros e estatísticas</p>
            </div>
            <div>
                <a href="lancar_mensalidades.php" class="btn btn-primary me-2">
                    <i class="fas fa-plus"></i> Lançar Mensalidades
                </a>
                <a href="../index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Total de Mensalidades</p>
                    <h3><?php echo number_format($stats_gerais['total'] ?? 0); ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <div class="icon"><i class="fas fa-money-bill"></i></div>
                    <p>Valor Total</p>
                    <h3><?php echo formatarMoeda($stats_gerais['valor_total'] ?? 0); ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Valor Pago</p>
                    <h3><?php echo formatarMoeda($stats_gerais['valor_pago'] ?? 0); ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <p>Valor em Débito</p>
                    <h3><?php echo formatarMoeda($stats_gerais['valor_devedor'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        
        <!-- Gráficos Rápidos -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Distribuição por Status
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart" height="200"></canvas>
                        <div class="row mt-3 text-center">
                            <div class="col-3">
                                <div class="text-success"><i class="fas fa-check-circle"></i> Pago</div>
                                <strong><?php echo number_format($stats_gerais['pagos'] ?? 0); ?></strong>
                            </div>
                            <div class="col-3">
                                <div class="text-warning"><i class="fas fa-clock"></i> Pendente</div>
                                <strong><?php echo number_format($stats_gerais['pendentes'] ?? 0); ?></strong>
                            </div>
                            <div class="col-3">
                                <div class="text-info"><i class="fas fa-hourglass-half"></i> Parcial</div>
                                <strong><?php echo number_format($stats_gerais['parciais'] ?? 0); ?></strong>
                            </div>
                            <div class="col-3">
                                <div class="text-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</div>
                                <strong><?php echo number_format($stats_gerais['atrasados'] ?? 0); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-bar"></i> Top 5 Turmas com Maior Débito
                    </div>
                    <div class="card-body">
                        <?php if (!empty($stats_turmas)): ?>
                            <?php foreach ($stats_turmas as $turma): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span><?php echo $turma['turma_ano'] . 'ª - ' . htmlspecialchars($turma['turma_nome']); ?></span>
                                    <span class="text-danger fw-bold"><?php echo formatarMoeda($turma['devedor']); ?></span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo ($stats_gerais['valor_devedor'] > 0) ? ($turma['devedor'] / $stats_gerais['valor_devedor']) * 100 : 0; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo $turma['total']; ?> mensalidades</small>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">Nenhum dado disponível</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros de Busca</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="pago" <?php echo $status_filtro == 'pago' ? 'selected' : ''; ?>>Pago</option>
                            <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="parcial" <?php echo $status_filtro == 'parcial' ? 'selected' : ''; ?>>Parcial</option>
                            <option value="atrasado" <?php echo $status_filtro == 'atrasado' ? 'selected' : ''; ?>>Atrasado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">Turma</label>
                        <select name="turma_id" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_filtro == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">Ano Letivo</label>
                        <select name="ano_letivo_id" class="form-select">
                            <option value="0">Todos</option>
                            <?php foreach ($anos_letivos as $al): ?>
                            <option value="<?php echo $al['id']; ?>" <?php echo $ano_letivo_filtro == $al['id'] ? 'selected' : ''; ?>>
                                <?php echo $al['nome']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">Mês</label>
                        <select name="mes" class="form-select">
                            <option value="0">Todos</option>
                            <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $mes_filtro == $m ? 'selected' : ''; ?>>
                                <?php echo getMesNome($m); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome ou matrícula..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Resultados -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Resultados</h5>
                <small>Total de <?php echo number_format($total_mensalidades); ?> mensalidade(s) encontrada(s)</small>
            </div>
            <div class="card-body">
                <?php if (empty($mensalidades)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma mensalidade encontrada com os filtros selecionados.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th>
                                    <th>Turma</th>
                                    <th>Mês/Ano</th>
                                    <th>Ano Letivo</th>
                                    <th>Valor Total</th>
                                    <th>Valor Pago</th>
                                    <th>Saldo</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mensalidades as $row): ?>
                                <tr class="mensalidade-row <?php echo $row['status']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['aluno_nome']); ?></strong><br>
                                        <small class="text-muted">Mat: <?php echo $row['matricula']; ?></small>
                                    </td>
                                    <td><?php echo $row['turma_ano'] . 'ª - ' . htmlspecialchars($row['turma_nome']); ?></small></td>
                                    <td><?php echo getMesNome($row['mes_referencia']) . '/' . $row['ano_referencia']; ?></td>
                                    <td><?php echo $row['ano_letivo']; ?> - <?php echo htmlspecialchars($row['ano_letivo_nome']); ?></small></td>
                                    <td class="text-end"><?php echo formatarMoeda($row['valor_total']); ?></td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($row['valor_pago']); ?></td>
                                    <td class="text-end text-danger fw-bold"><?php echo formatarMoeda($row['valor_total'] - $row['valor_pago']); ?></td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($row['data_vencimento'])); ?>
                                        <?php if (strtotime($row['data_vencimento']) < time() && $row['status'] != 'pago'): ?>
                                            <br><span class="badge bg-danger">Vencida</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatusBadge($row['status']); ?></td>
                                    <td>
                                        <a href="detalhes_mensalidade.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($row['status'] != 'pago'): ?>
                                        <a href="../pagamentos.php?aluno_id=<?php echo $row['aluno_id']; ?>&mensalidade_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success" title="Registrar pagamento">
                                            <i class="fas fa-money-bill"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAIS:</td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total')) - array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&turma_id=<?php echo $turma_filtro; ?>&ano_letivo_id=<?php echo $ano_letivo_filtro; ?>&mes=<?php echo $mes_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Gráfico de Status
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pago', 'Pendente', 'Parcial', 'Atrasado'],
                datasets: [{
                    data: [
                        <?php echo $stats_gerais['pagos'] ?? 0; ?>,
                        <?php echo $stats_gerais['pendentes'] ?? 0; ?>,
                        <?php echo $stats_gerais['parciais'] ?? 0; ?>,
                        <?php echo $stats_gerais['atrasados'] ?? 0; ?>
                    ],
                    backgroundColor: ['#28a745', '#6c757d', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Inicializar Select2
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    </script>
</body>
</html>