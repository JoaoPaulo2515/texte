<?php
// escola/tesouraria/despesas.php - Gestão de Despesas

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

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// TABELA DE CATEGORIAS DE DESPESAS
// ============================================
$sql_criar_tabela = "CREATE TABLE IF NOT EXISTS categorias_despesas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NULL,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    icone VARCHAR(50) DEFAULT 'fas fa-tag',
    cor VARCHAR(20) DEFAULT '#dc3545',
    ativo TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->exec($sql_criar_tabela);

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';

// Inserir despesa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $data_vencimento = $_POST['data_vencimento'];
        $data_pagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : null;
        $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
        $fornecedor = trim($_POST['fornecedor'] ?? '');
        $documento_numero = trim($_POST['documento_numero'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $status = $_POST['status'] ?? 'pendente';
        
        if ($valor <= 0) {
            $error = "Valor da despesa inválido.";
        } elseif (empty($categoria)) {
            $error = "Selecione uma categoria.";
        } else {
            try {
                $sql = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, observacoes, usuario_id, data_movimento, status, created_at) 
                        VALUES (:escola_id, 'saida', :categoria, :descricao, :valor, :forma_pagamento, :referencia, :observacoes, :usuario_id, :data_movimento, :status, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':categoria' => $categoria,
                    ':descricao' => $descricao,
                    ':valor' => $valor,
                    ':forma_pagamento' => $forma_pagamento,
                    ':referencia' => $documento_numero,
                    ':observacoes' => $observacoes,
                    ':usuario_id' => $usuario_id,
                    ':data_movimento' => $data_vencimento,
                    ':status' => $status
                ]);
                $success = "Despesa registrada com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao registrar: " . $e->getMessage();
            }
        }
    }
    
    // Cancelar despesa
    elseif ($_POST['action'] == 'cancelar') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE caixa SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id AND tipo = 'saida'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Despesa cancelada!";
    }
    
    // Adicionar categoria
    elseif ($_POST['action'] == 'add_categoria') {
        $nome = trim($_POST['nome_categoria']);
        $icone = trim($_POST['icone_categoria']);
        $cor = trim($_POST['cor_categoria']);
        
        if (empty($nome)) {
            $error = "Nome da categoria é obrigatório.";
        } else {
            $sql = "INSERT INTO categorias_despesas (escola_id, nome, icone, cor) VALUES (:escola_id, :nome, :icone, :cor)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':icone' => $icone,
                ':cor' => $cor
            ]);
            $success = "Categoria adicionada com sucesso!";
        }
    }
}

// ============================================
// CONSULTAS
// ============================================

// Totais de despesas
$sql_totais = "SELECT 
                COALESCE(SUM(CASE WHEN status = 'ativo' THEN valor ELSE 0 END), 0) as total_pago,
                COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END), 0) as total_pendente,
                COUNT(CASE WHEN status = 'ativo' THEN 1 END) as qtd_pago,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as qtd_pendente,
                COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as qtd_cancelado
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND tipo = 'saida'
              AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);

// Buscar despesas
$sql_despesas = "SELECT * FROM caixa 
                  WHERE escola_id = :escola_id 
                  AND tipo = 'saida'
                  AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim";
if (!empty($categoria_filtro)) {
    $sql_despesas .= " AND categoria = :categoria";
}
if ($status_filtro != 'todos') {
    $sql_despesas .= " AND status = :status";
}
$sql_despesas .= " ORDER BY data_movimento DESC, id DESC";

$stmt_despesas = $conn->prepare($sql_despesas);
$params = [
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
];
if (!empty($categoria_filtro)) {
    $params[':categoria'] = $categoria_filtro;
}
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
$stmt_despesas->execute($params);
$despesas = $stmt_despesas->fetchAll(PDO::FETCH_ASSOC);

// Buscar categorias de despesas
$sql_categorias = "SELECT * FROM categorias_despesas WHERE escola_id = :escola_id OR escola_id IS NULL ORDER BY nome ASC";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas por categoria
$sql_stats_categoria = "SELECT 
                          categoria,
                          COUNT(*) as quantidade,
                          COALESCE(SUM(valor), 0) as total
                        FROM caixa 
                        WHERE escola_id = :escola_id 
                        AND tipo = 'saida'
                        AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
                        AND status = 'ativo'
                        GROUP BY categoria
                        ORDER BY total DESC";
$stmt_stats = $conn->prepare($sql_stats_categoria);
$stmt_stats->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$stats_categoria = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusDespesaBadge($status) {
    switch ($status) {
        case 'ativo':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getFormaPagamentoIcone($forma) {
    switch ($forma) {
        case 'dinheiro': return '<i class="fas fa-money-bill-wave text-success"></i>';
        case 'transferencia': return '<i class="fas fa-university text-primary"></i>';
        case 'deposito': return '<i class="fas fa-money-bill text-info"></i>';
        case 'cheque': return '<i class="fas fa-check-circle text-warning"></i>';
        case 'multicaixa': return '<i class="fas fa-credit-card text-secondary"></i>';
        default: return '<i class="fas fa-question-circle"></i>';
    }
}

$icones_disponiveis = [
    'fas fa-tag', 'fas fa-money-bill', 'fas fa-chart-line', 'fas fa-chart-bar',
    'fas fa-utensils', 'fas fa-tint', 'fas fa-bolt', 'fas fa-wifi',
    'fas fa-phone', 'fas fa-tools', 'fas fa-book', 'fas fa-pencil-alt',
    'fas fa-trash', 'fas fa-recycle', 'fas fa-car', 'fas fa-gas-pump'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despesas | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .despesa-row-pendente { border-left: 4px solid #ffc107; }
        .despesa-row-pago { border-left: 4px solid #28a745; }
        .despesa-row-cancelado { border-left: 4px solid #dc3545; }
        
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-arrow-down"></i> Gestão de Despesas</h2>
                <p class="text-muted">Controle de gastos e despesas da escola</p>
            </div>
            <div class="no-print">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaDespesa">
                    <i class="fas fa-plus"></i> Nova Despesa
                </button>
                <button class="btn btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#modalNovaCategoria">
                    <i class="fas fa-tags"></i> Categorias
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais['total_pago'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Despesas Pagas</div>
                                        <small><?php echo $totais['qtd_pago'] ?? 0; ?> despesa(s)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo formatarMoeda($totais['total_pendente'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-clock text-warning"></i> Despesas Pendentes</div>
                    <small><?php echo $totais['qtd_pendente'] ?? 0; ?> despesa(s)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($totais['qtd_cancelado'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-times-circle text-danger"></i> Despesas Canceladas</div>
                    <small>Total cancelado</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format(($stats_categoria ? count($stats_categoria) : 0), 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-tags"></i> Categorias Utilizadas</div>
                    <small>Diferentes tipos</small>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat['nome']; ?>" <?php echo $categoria_filtro == $cat['nome'] ? 'selected' : ''; ?>>
                                <?php echo $cat['nome']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Pago</option>
                            <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="cancelado" <?php echo $status_filtro == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Gráfico de Despesas por Categoria -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Despesas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoDespesas" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Top Categorias</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats_categoria)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-chart-line fa-3x mb-2"></i>
                                <p>Nenhum dado disponível</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($stats_categoria as $index => $cat): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $index + 1; ?>.</strong> <?php echo htmlspecialchars($cat['categoria']); ?>
                                        <br><small><?php echo $cat['quantidade']; ?> despesa(s)</small>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold text-danger"><?php echo formatarMoeda($cat['total']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Despesas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Despesas</h5>
                <small><?php echo date('d/m/Y', strtotime($data_inicio)); ?> até <?php echo date('d/m/Y', strtotime($data_fim)); ?></small>
            </div>
            <div class="card-body">
                <?php if (empty($despesas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma despesa encontrada no período selecionado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Fornecedor</th>
                                    <th>Documento</th>
                                    <th>Status</th>
                                    <th class="no-print">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($despesas as $despesa): ?>
                                <tr class="despesa-row-<?php echo $despesa['status']; ?>">
                                    <td><?php echo $despesa['id']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($despesa['data_movimento'])); ?></td>
                                    <td><?php echo htmlspecialchars($despesa['categoria']); ?></td>
                                    <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                                    <td class="text-danger fw-bold"><?php echo formatarMoeda($despesa['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($despesa['metodo_pagamento']); ?> <?php echo ucfirst($despesa['metodo_pagamento'] ?? 'Dinheiro'); ?></td>
                                    <td><?php echo htmlspecialchars($despesa['fornecedor'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($despesa['referencia'] ?: '-'); ?></td>
                                    <td><?php echo getStatusDespesaBadge($despesa['status']); ?></td>
                                    <td class="no-print">
                                        <?php if ($despesa['status'] == 'pendente'): ?>
                                        <button class="btn btn-sm btn-success" onclick="registrarPagamento(<?php echo $despesa['id']; ?>, '<?php echo formatarMoeda($despesa['valor']); ?>')">
                                            <i class="fas fa-money-bill"></i> Pagar
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="cancelarDespesa(<?php echo $despesa['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php elseif ($despesa['status'] == 'ativo'): ?>
                                        <button class="btn btn-sm btn-secondary" disabled>
                                            <i class="fas fa-check"></i> Pago
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAIS:</td>
                                    <td class="text-danger"><?php echo formatarMoeda(array_sum(array_column($despesas, 'valor'))); ?></td>
                                    <td colspan="5"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Despesa -->
    <div class="modal fade no-print" id="modalNovaDespesa" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Despesa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Categoria <span class="text-danger">*</span></label>
                            <select name="categoria" class="form-select" required>
                                <option value="">Selecione uma categoria</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['nome']; ?>"><?php echo $cat['nome']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" class="form-control" required placeholder="Ex: Compra de material de limpeza">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Valor <span class="text-danger">*</span></label>
                            <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Data da Despesa <span class="text-danger">*</span></label>
                            <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select">
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="cheque">📄 Cheque</option>
                                <option value="multicaixa">💳 Multicaixa</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fornecedor</label>
                            <input type="text" name="fornecedor" class="form-control" placeholder="Nome do fornecedor">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nº Documento/Fatura</label>
                            <input type="text" name="documento_numero" class="form-control" placeholder="Nº da fatura ou documento">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="pendente">⏳ Pendente (Aguardando pagamento)</option>
                                <option value="ativo">✅ Pago (Já foi pago)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Despesa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Categoria -->
    <div class="modal fade no-print" id="modalNovaCategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-tags"></i> Nova Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_categoria">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nome da Categoria <span class="text-danger">*</span></label>
                            <input type="text" name="nome_categoria" class="form-control" required placeholder="Ex: Água, Luz, Internet, Salários...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ícone</label>
                            <select name="icone_categoria" class="form-select">
                                <?php foreach ($icones_disponiveis as $icone): ?>
                                <option value="<?php echo $icone; ?>"><i class="<?php echo $icone; ?>"></i> <?php echo $icone; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Cor</label>
                            <input type="color" name="cor_categoria" class="form-control" value="#dc3545">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Adicionar Categoria</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Pagamento -->
    <div class="modal fade no-print" id="modalPagamento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-money-bill"></i> Registrar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="pagar">
                    <input type="hidden" name="id" id="despesa_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Valor a Pagar</label>
                            <input type="text" name="valor_pago" id="valor_pago" class="form-control valor" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Data de Pagamento</label>
                            <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select">
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="cheque">📄 Cheque</option>
                                <option value="multicaixa">💳 Multicaixa</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('.valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        function registrarPagamento(id, valor) {
            $('#despesa_id').val(id);
            $('#valor_pago').val(valor);
            new bootstrap.Modal(document.getElementById('modalPagamento')).show();
        }
        
        function cancelarDespesa(id) {
            if(confirm('Tem certeza que deseja cancelar esta despesa?')) {
                $('<form method="POST"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        // Gráfico de Despesas por Categoria
        const labels = <?php echo json_encode(array_column($stats_categoria, 'categoria')); ?>;
        const valores = <?php echo json_encode(array_column($stats_categoria, 'total')); ?>;
        
        if (labels.length > 0) {
            new Chart(document.getElementById('graficoDespesas'), {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: valores,
                        backgroundColor: ['#dc3545', '#e83e8c', '#f06292', '#f48fb1', '#f8bbd0', '#ff6b6b', '#c0392b', '#e74c3c']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz';
                                }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>