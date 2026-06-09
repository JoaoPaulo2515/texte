<?php
// escola/tesouraria/caixa_diario.php - Caixa Diário Detalhado

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
// FILTROS
// ============================================
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $tipo = $_POST['tipo'];
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $data_movimento = $_POST['data_movimento'];
        $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        if ($valor <= 0) {
            $error = "Valor inválido.";
        } elseif (empty($categoria)) {
            $error = "Informe a categoria.";
        } else {
            try {
                $sql = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, observacoes, usuario_id, data_movimento, status, created_at) 
                        VALUES (:escola_id, :tipo, :categoria, :descricao, :valor, :forma_pagamento, :referencia, :observacoes, :usuario_id, :data_movimento, 'ativo', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':tipo' => $tipo,
                    ':categoria' => $categoria,
                    ':descricao' => $descricao,
                    ':valor' => $valor,
                    ':forma_pagamento' => $forma_pagamento,
                    ':referencia' => $referencia,
                    ':observacoes' => $observacoes,
                    ':usuario_id' => $usuario_id,
                    ':data_movimento' => $data_movimento
                ]);
                $success = "Movimentação registrada com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao registrar: " . $e->getMessage();
            }
        }
    }
    
    elseif ($_POST['action'] == 'cancelar') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE caixa SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Movimentação cancelada!";
    }
}

// ============================================
// CONSULTAS
// ============================================

// Totais do período
$sql_totais = "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim 
              AND status = 'ativo'";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
$saldo = ($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0);

// Buscar movimentações
$sql_movimentacoes = "SELECT * FROM caixa 
                      WHERE escola_id = :escola_id 
                      AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim 
                      AND status = 'ativo'";
if ($tipo_filtro != 'todos') {
    $sql_movimentacoes .= " AND tipo = :tipo";
}
if (!empty($categoria_filtro)) {
    $sql_movimentacoes .= " AND categoria = :categoria";
}
$sql_movimentacoes .= " ORDER BY data_movimento DESC, id DESC";

$stmt_movimentacoes = $conn->prepare($sql_movimentacoes);
$params = [
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
];
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($categoria_filtro)) {
    $params[':categoria'] = $categoria_filtro;
}
$stmt_movimentacoes->execute($params);
$movimentacoes = $stmt_movimentacoes->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas por categoria
$sql_categorias = "SELECT 
                    categoria, 
                    tipo,
                    COUNT(*) as quantidade,
                    COALESCE(SUM(valor), 0) as total
                  FROM caixa 
                  WHERE escola_id = :escola_id 
                  AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim 
                  AND status = 'ativo'
                  GROUP BY categoria, tipo
                  ORDER BY total DESC
                  LIMIT 10";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$stats_categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Movimentações por dia (gráfico)
$sql_diario = "SELECT 
                DATE(data_movimento) as data,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim 
              AND status = 'ativo'
              GROUP BY DATE(data_movimento)
              ORDER BY data ASC";
$stmt_diario = $conn->prepare($sql_diario);
$stmt_diario->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$movimentos_diarios = $stmt_diario->fetchAll(PDO::FETCH_ASSOC);

// Saldo acumulado diário
$saldo_acumulado = 0;
foreach ($movimentos_diarios as &$dia) {
    $saldo_acumulado += ($dia['entradas'] - $dia['saidas']);
    $dia['saldo'] = $saldo_acumulado;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoBadge($tipo) {
    if ($tipo == 'entrada') {
        return '<span class="badge bg-success"><i class="fas fa-arrow-up"></i> Entrada</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-arrow-down"></i> Saída</span>';
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

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success">Ativo</span>';
    } else {
        return '<span class="badge bg-danger">Cancelado</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa Diário | Tesouraria | SIGE Angola</title>
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
        
        .entrada-row { border-left: 4px solid #28a745; }
        .saida-row { border-left: 4px solid #dc3545; }
        
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .btn, .btn-voltar, .menu-toggle { display: none !important; }
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
                <h2><i class="fas fa-cash-register"></i> Caixa Diário</h2>
                <p class="text-muted">Movimentações financeiras detalhadas</p>
            </div>
            <div class="no-print">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaMovimentacao">
                    <i class="fas fa-plus"></i> Nova Movimentação
                </button>
                <button class="btn btn-info ms-2" onclick="window.print();">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show no-print"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
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
                    <div class="col-md-2">
                        <label class="form-label">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="entrada" <?php echo $tipo_filtro == 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                            <option value="saida" <?php echo $tipo_filtro == 'saida' ? 'selected' : ''; ?>>Saídas</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <option value="">Todas</option>
                            <optgroup label="Entradas">
                                <option value="Mensalidade" <?php echo $categoria_filtro == 'Mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                                <option value="Matrícula" <?php echo $categoria_filtro == 'Matrícula' ? 'selected' : ''; ?>>Matrícula</option>
                                <option value="Certificado" <?php echo $categoria_filtro == 'Certificado' ? 'selected' : ''; ?>>Certificado</option>
                                <option value="Material Escolar" <?php echo $categoria_filtro == 'Material Escolar' ? 'selected' : ''; ?>>Material Escolar</option>
                                <option value="Doação" <?php echo $categoria_filtro == 'Doação' ? 'selected' : ''; ?>>Doação</option>
                            </optgroup>
                            <optgroup label="Saídas">
                                <option value="Salários" <?php echo $categoria_filtro == 'Salários' ? 'selected' : ''; ?>>Salários</option>
                                <option value="Água" <?php echo $categoria_filtro == 'Água' ? 'selected' : ''; ?>>Água</option>
                                <option value="Luz" <?php echo $categoria_filtro == 'Luz' ? 'selected' : ''; ?>>Luz</option>
                                <option value="Internet" <?php echo $categoria_filtro == 'Internet' ? 'selected' : ''; ?>>Internet</option>
                                <option value="Telefone" <?php echo $categoria_filtro == 'Telefone' ? 'selected' : ''; ?>>Telefone</option>
                                <option value="Material Limpeza" <?php echo $categoria_filtro == 'Material Limpeza' ? 'selected' : ''; ?>>Material Limpeza</option>
                                <option value="Manutenção" <?php echo $categoria_filtro == 'Manutenção' ? 'selected' : ''; ?>>Manutenção</option>
                                <option value="Impostos" <?php echo $categoria_filtro == 'Impostos' ? 'selected' : ''; ?>>Impostos</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($totais['total_entradas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small>Período selecionado</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small>Período selecionado</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($saldo); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Período</div>
                    <small><?php echo $saldo >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Movimento Diário -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Movimento Diário</h5>
            </div>
            <div class="card-body">
                <canvas id="graficoDiario" height="100"></canvas>
            </div>
        </div>
        
        <!-- Top Categorias -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Top Entradas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoEntradas" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Top Saídas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoSaidas" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Movimentações -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Movimentações Financeiras</h5>
                <small><?php echo date('d/m/Y', strtotime($data_inicio)); ?> até <?php echo date('d/m/Y', strtotime($data_fim)); ?></small>
            </div>
            <div class="card-body">
                <?php if (empty($movimentacoes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma movimentação encontrada no período selecionado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                    <th>Status</th>
                                    <th class="no-print">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movimentacoes as $mov): ?>
                                <tr class="<?php echo $mov['tipo'] == 'entrada' ? 'entrada-row' : 'saida-row'; ?>">
                                    <td><?php echo $mov['id']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?></td>
                                    <td><?php echo getTipoBadge($mov['tipo']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['categoria']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['descricao']); ?></td>
                                    <td class="<?php echo $mov['tipo'] == 'entrada' ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                        <?php echo formatarMoeda($mov['valor']); ?>
                                    </td>
                                    <td><?php echo getFormaPagamentoIcone($mov['metodo_pagamento']); ?> <?php echo ucfirst($mov['metodo_pagamento'] ?? 'Dinheiro'); ?></td>
                                    <td><?php echo htmlspecialchars($mov['referencia'] ?: '-'); ?></td>
                                    <td><?php echo getStatusBadge($mov['status']); ?></td>
                                    <td class="no-print">
                                        <?php if ($mov['status'] == 'ativo'): ?>
                                        <button class="btn btn-sm btn-danger" onclick="cancelarMovimentacao(<?php echo $mov['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancelar
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAIS:</td>
                                    <td class="text-success"><?php echo formatarMoeda($totais['total_entradas'] ?? 0); ?></td>
                                    <td colspan="5"><?php echo formatarMoeda($totais['total_saidas'] ?? 0); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Movimentação -->
    <div class="modal fade no-print" id="modalNovaMovimentacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Movimentação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select name="tipo" id="tipoMov" class="form-select" required onchange="toggleTipo()">
                                <option value="entrada">📥 Entrada (Recebimento)</option>
                                <option value="saida">📤 Saída (Pagamento/Despesa)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Categoria <span class="text-danger">*</span></label>
                            <select name="categoria" id="categoriaMov" class="form-select" required>
                                <option value="">Selecione...</option>
                                <optgroup label="📥 Entradas">
                                    <option value="Mensalidade">📅 Mensalidade</option>
                                    <option value="Matrícula">📝 Matrícula</option>
                                    <option value="Certificado">🎓 Certificado</option>
                                    <option value="Material Escolar">📚 Material Escolar</option>
                                    <option value="Doação">🎁 Doação</option>
                                    <option value="Outra Entrada">💰 Outra Entrada</option>
                                </optgroup>
                                <optgroup label="📤 Saídas">
                                    <option value="Salários">👨‍🏫 Salários</option>
                                    <option value="Água">💧 Água</option>
                                    <option value="Luz">⚡ Luz</option>
                                    <option value="Internet">🌐 Internet</option>
                                    <option value="Telefone">📞 Telefone</option>
                                    <option value="Material Limpeza">🧹 Material Limpeza</option>
                                    <option value="Material Escritório">✏️ Material Escritório</option>
                                    <option value="Manutenção">🔧 Manutenção</option>
                                    <option value="Impostos">📄 Impostos</option>
                                    <option value="Outra Saída">📤 Outra Saída</option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" class="form-control" required placeholder="Ex: Pagamento de mensalidade, Compra de material...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Valor <span class="text-danger">*</span></label>
                            <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
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
                            <label class="form-label">Data da Movimentação</label>
                            <input type="date" name="data_movimento" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nº Referência (Opcional)</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Nº do documento, cheque, transferência...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Movimentação</button>
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
        
        $('#valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        function toggleTipo() {
            let tipo = $('#tipoMov').val();
            if (tipo === 'entrada') {
                $('.modal-header-custom').css('background', 'linear-gradient(135deg, #28a745 0%, #20c997 100%)');
            } else {
                $('.modal-header-custom').css('background', 'linear-gradient(135deg, #dc3545 0%, #e83e8c 100%)');
            }
        }
        
        function cancelarMovimentacao(id) {
            if(confirm('Tem certeza que deseja cancelar esta movimentação?')) {
                $('<form method="POST"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        toggleTipo();
        $('#tipoMov').on('change', toggleTipo);
        
        // Gráfico Diário
        const labels = <?php echo json_encode(array_column($movimentos_diarios, 'data')); ?>;
        const entradasData = <?php echo json_encode(array_column($movimentos_diarios, 'entradas')); ?>;
        const saidasData = <?php echo json_encode(array_column($movimentos_diarios, 'saidas')); ?>;
        
        new Chart(document.getElementById('graficoDiario'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Entradas',
                        data: entradasData,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Saídas',
                        data: saidasData,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Entradas por Categoria
        const entradasCategorias = <?php 
            $entradas_cat = array_filter($stats_categorias, function($item) { return $item['tipo'] == 'entrada'; });
            echo json_encode(array_column($entradas_cat, 'categoria'));
        ?>;
        const entradasValores = <?php 
            echo json_encode(array_column($entradas_cat, 'total'));
        ?>;
        
        if (entradasCategorias.length > 0) {
            new Chart(document.getElementById('graficoEntradas'), {
                type: 'pie',
                data: {
                    labels: entradasCategorias,
                    datasets: [{
                        data: entradasValores,
                        backgroundColor: ['#28a745', '#20c997', '#34ce57', '#48d96b', '#5ce37f']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
        
        // Gráfico de Saídas por Categoria
        const saidasCategorias = <?php 
            $saidas_cat = array_filter($stats_categorias, function($item) { return $item['tipo'] == 'saida'; });
            echo json_encode(array_column($saidas_cat, 'categoria'));
        ?>;
        const saidasValores = <?php 
            echo json_encode(array_column($saidas_cat, 'total'));
        ?>;
        
        if (saidasCategorias.length > 0) {
            new Chart(document.getElementById('graficoSaidas'), {
                type: 'pie',
                data: {
                    labels: saidasCategorias,
                    datasets: [{
                        data: saidasValores,
                        backgroundColor: ['#dc3545', '#e83e8c', '#f06292', '#f48fb1', '#f8bbd0']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    </script>
</body>
</html>