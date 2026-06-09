<?php
// escola/tesouraria/ver_pagamentos.php - Visualizar Pagamentos do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

// Buscar aluno
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;

if ($aluno_id <= 0) {
    header('Location: mensalidades.php?error=Aluno não encontrado');
    exit;
}

// Buscar dados do aluno
$sql_aluno = "SELECT e.id, e.nome, e.matricula, e.email, e.telefone, t.nome as turma_nome, t.ano as turma_ano
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas t ON t.id = m.turma_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: mensalidades.php?error=Aluno não encontrado');
    exit;
}

// ============================================
// FILTROS
// ============================================
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ============================================
// BUSCAR MENSALIDADES DO ALUNO
// ============================================
$sql_mensalidades = "
    SELECT m.*, al.ano as ano_letivo_valor
    FROM mensalidades m
    LEFT JOIN ano_letivo al ON al.id = m.ano_letivo_id
    WHERE m.aluno_id = :aluno_id AND m.escola_id = :escola_id
    ORDER BY m.ano_referencia DESC, m.mes_referencia DESC
";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PAGAMENTOS DO ALUNO
// ============================================
$where_conditions = [];
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
];

if ($ano_filtro > 0) {
    $where_conditions[] = "YEAR(p.data_pagamento) = :ano";
    $params[':ano'] = $ano_filtro;
}

if ($tipo_filtro != 'todos') {
    $where_conditions[] = "p.tipo_pagamento = :tipo";
    $params[':tipo'] = $tipo_filtro;
}

$where_sql = !empty($where_conditions) ? "AND " . implode(" AND ", $where_conditions) : "";

$sql_pagamentos = "
    SELECT p.*, u.nome as usuario_nome, tp.nome as tipo_nome, tp.icone, tp.cor,p.metodo_pagamento as forma_pagamento,p.referente as referencia
    FROM pagamentos p
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN tipos_pagamento tp ON tp.id = p.tipo_pagamento_id
    WHERE p.assinatura_id = :aluno_id AND p.escola_id = :escola_id
    $where_sql
    ORDER BY p.data_pagamento DESC
    LIMIT :limit OFFSET :offset
";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt_pagamentos->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    if ($key != ':limit' && $key != ':offset') {
        $stmt_pagamentos->bindValue($key, $value);
    }
}
$stmt_pagamentos->execute();
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM pagamentos WHERE assinatura_id = :aluno_id AND escola_id = :escola_id $where_sql";
$stmt_count = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    if ($key != ':limit' && $key != ':offset') {
        $stmt_count->bindValue($key, $value);
    }
}
$stmt_count->execute();
$total_pagamentos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_pagamentos / $limit);

// ============================================
// ESTATÍSTICAS DO ALUNO
// ============================================
$stats = [];

// Total pago
$sql = "SELECT SUM(valor) as total FROM pagamentos WHERE assinatura_id = :aluno_id AND escola_id = :escola_id AND status = 'confirmado'";
$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$stats['total_pago'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total de mensalidades
$sql = "SELECT COUNT(*) as total, SUM(valor_total) as valor_total FROM mensalidades WHERE aluno_id = :aluno_id AND escola_id = :escola_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_mensalidades'] = $row['total'] ?? 0;
$stats['valor_mensalidades'] = $row['valor_total'] ?? 0;

// Saldo devedor
$stats['saldo_devedor'] = $stats['valor_mensalidades'] - $stats['total_pago'];

// Último pagamento
$sql = "SELECT data_pagamento, valor FROM pagamentos WHERE assinatura_id = :aluno_id AND escola_id = :escola_id AND status = 'confirmado' ORDER BY data_pagamento DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$ultimo_pagamento = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANOS PARA FILTRO
// ============================================
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano FROM pagamentos WHERE assinatura_id = :aluno_id AND escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_pagamentos = $stmt_anos->fetchAll(PDO::FETCH_COLUMN);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusPagamentoBadge($status) {
    switch ($status) {
        case 'confirmado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Confirmado</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoPagamentoBadge($tipo_nome, $icone, $cor) {
    if (!$tipo_nome) {
        return '<span class="badge bg-secondary">Outro</span>';
    }
    return '<span class="badge" style="background-color: ' . $cor . ';"><i class="' . $icone . '"></i> ' . htmlspecialchars($tipo_nome) . '</span>';
}

function getFormaPagamentoIcone($forma) {
    switch ($forma) {
        case 'dinheiro': return '<i class="fas fa-money-bill-wave text-success"></i> Dinheiro';
        case 'transferencia': return '<i class="fas fa-university text-primary"></i> Transferência';
        case 'deposito': return '<i class="fas fa-money-bill text-info"></i> Depósito';
        case 'cheque': return '<i class="fas fa-check-circle text-warning"></i> Cheque';
        case 'multicaixa': return '<i class="fas fa-credit-card text-secondary"></i> Multicaixa';
        default: return '<i class="fas fa-question-circle"></i> ' . ucfirst($forma);
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

function getStatusMensalidadeBadge($status) {
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success">Pago</span>';
        case 'parcial':
            return '<span class="badge bg-warning text-dark">Parcial</span>';
        case 'pendente':
            return '<span class="badge bg-secondary">Pendente</span>';
        case 'atrasado':
            return '<span class="badge bg-danger">Atrasado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos do Aluno | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content-tesouraria {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content-tesouraria { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.3em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.75rem; color: #6c757d; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .mensalidade-row.atrasado { background-color: #fff3cd; }
        .mensalidade-row.pago { background-color: #d4edda; }
        .pagamento-row { transition: all 0.3s ease; }
        .pagamento-row:hover { background-color: #f8f9fa; }
        
        .aluno-info { background: linear-gradient(135deg, #e8f5e9, #e3f2fd); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content-tesouraria">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-user-graduate"></i> Pagamentos do Aluno</h2>
                <p class="text-muted">Histórico de pagamentos e mensalidades</p>
            </div>
            <div>
                <a href="pagamentos.php?aluno_id=<?php echo $aluno_id; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Pagamento
                </a>
                <a href="mensalidades.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="aluno-info">
            <div class="row">
                <div class="col-md-6">
                    <h4><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($aluno['nome']); ?></h4>
                    <p class="mb-1"><i class="fas fa-id-card"></i> Matrícula: <strong><?php echo htmlspecialchars($aluno['matricula']); ?></strong></p>
                    <p class="mb-1"><i class="fas fa-envelope"></i> Email: <?php echo htmlspecialchars($aluno['email'] ?: 'Não informado'); ?></p>
                    <p class="mb-1"><i class="fas fa-phone"></i> Telefone: <?php echo htmlspecialchars($aluno['telefone'] ?: 'Não informado'); ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><i class="fas fa-chalkboard"></i> Turma: <strong><?php echo $aluno['turma_ano'] . 'ª - ' . htmlspecialchars($aluno['turma_nome']); ?></strong></p>
                    <p class="mb-1"><i class="fas fa-calendar-alt"></i> Total de Mensalidades: <?php echo $stats['total_mensalidades']; ?></p>
                    <p class="mb-1"><i class="fas fa-money-bill-wave"></i> Valor Total das Mensalidades: <?php echo formatarMoeda($stats['valor_mensalidades']); ?></p>
                    <p class="mb-1"><i class="fas fa-check-circle text-success"></i> Total Pago: <?php echo formatarMoeda($stats['total_pago']); ?></p>
                    <p class="mb-0"><i class="fas fa-exclamation-triangle text-danger"></i> Saldo Devedor: <?php echo formatarMoeda($stats['saldo_devedor']); ?></p>
                </div>
            </div>
            <?php if ($ultimo_pagamento): ?>
            <div class="mt-3 pt-2 border-top">
                <small><i class="fas fa-history"></i> Último pagamento: <?php echo date('d/m/Y', strtotime($ultimo_pagamento['data_pagamento'])); ?> - <?php echo formatarMoeda($ultimo_pagamento['valor']); ?></small>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Mensalidades -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-dollar"></i> Mensalidades</h5>
            </div>
            <div class="card-body">
                <?php if (empty($mensalidades)): ?>
                    <div class="alert alert-info text-center">Nenhuma mensalidade encontrada para este aluno.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Mês/Ano</th>
                                    <th>Valor Total</th>
                                    <th>Valor Pago</th>
                                    <th>Saldo</th>
                                    <th>Vencimento</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_mensalidades_valor = 0;
                                $total_mensalidades_pago = 0;
                                foreach ($mensalidades as $row):
                                    $total_mensalidades_valor += $row['valor_total'];
                                    $total_mensalidades_pago += $row['valor_pago'];
                                ?>
                                <tr class="mensalidade-row <?php echo $row['status']; ?>">
                                    <td><?php echo getMesNome($row['mes_referencia']) . '/' . ($row['ano_letivo_valor'] ?? $row['ano_referencia']); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($row['valor_total']); ?></td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($row['valor_pago']); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($row['valor_total'] - $row['valor_pago']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['data_vencimento'])); ?></td>
                                    <td><?php echo getStatusMensalidadeBadge($row['status']); ?></td>
                                    <td>
                                        <a href="pagamentos.php?aluno_id=<?php echo $aluno_id; ?>&mensalidade_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success" title="Registrar Pagamento">
                                            <i class="fas fa-money-bill"></i>
                                        </a>
                                     </row>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td class="text-end">TOTAIS:</td>
                                    <td class="text-end"><?php echo formatarMoeda($total_mensalidades_valor); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($total_mensalidades_pago); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($total_mensalidades_valor - $total_mensalidades_pago); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Histórico de Pagamentos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Histórico de Pagamentos</h5>
            </div>
            <div class="card-body">
                <!-- Filtros -->
                <form method="GET" class="row g-3 mb-3">
                    <input type="hidden" name="aluno_id" value="<?php echo $aluno_id; ?>">
                    <div class="col-md-3">
                        <label class="filter-label">Ano</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos</option>
                            <?php foreach ($anos_pagamentos as $ano): ?>
                            <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                            <option value="matricula" <?php echo $tipo_filtro == 'matricula' ? 'selected' : ''; ?>>Matrícula</option>
                            <option value="certificado" <?php echo $tipo_filtro == 'certificado' ? 'selected' : ''; ?>>Certificado</option>
                            <option value="material" <?php echo $tipo_filtro == 'material' ? 'selected' : ''; ?>>Material</option>
                            <option value="outro" <?php echo $tipo_filtro == 'outro' ? 'selected' : ''; ?>>Outro</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">&nbsp;</label>
                        <a href="ver_pagamentos.php?aluno_id=<?php echo $aluno_id; ?>" class="btn btn-secondary w-100">Limpar</a>
                    </div>
                </form>
                
                <?php if (empty($pagamentos)): ?>
                    <div class="alert alert-info text-center">Nenhum pagamento encontrado para este aluno.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover pagamento-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                    <th>Registrado por</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagamentos as $pg): ?>
                                <tr class="pagamento-row">
                                    <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                    <td><?php echo getTipoPagamentoBadge($pg['tipo_nome'], $pg['icone'], $pg['cor']); ?></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($pg['forma_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($pg['referencia'] ?: '-'); ?></td>
                                    <td><small><?php echo htmlspecialchars($pg['usuario_nome'] ?? 'Sistema'); ?></small></td>
                                    <td><?php echo getStatusPagamentoBadge($pg['status']); ?></td>
                                    <td>
                                        <a href="recibos.php?pagamento_id=<?php echo $pg['id']; ?>" class="btn btn-sm btn-info" title="Imprimir Recibo" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                     </row>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <?php if ($total_pages > 1): ?>
                    <nav class="mt-3"><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?aluno_id=<?php echo $aluno_id; ?>&page=<?php echo $i; ?>&ano=<?php echo $ano_filtro; ?>&tipo=<?php echo urlencode($tipo_filtro); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>