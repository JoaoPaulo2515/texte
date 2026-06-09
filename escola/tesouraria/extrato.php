<?php
// escola/tesouraria/extrato.php - Extrato Financeiro

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
$aluno_filtro = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$fornecedor_filtro = isset($_GET['fornecedor']) ? $_GET['fornecedor'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// CONSULTAS
// ============================================

// Buscar alunos para filtro
$sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Construir query de extrato
$sql_extrato = "SELECT 
                    c.*,
                    'caixa' as origem,
                    e.nome as aluno_nome,
                    e.matricula
                FROM caixa c
                LEFT JOIN estudantes e ON e.id = c.assinatura_id
                WHERE c.escola_id = :escola_id 
                AND c.status = 'ativo'
                AND DATE(c.data_movimento) BETWEEN :data_inicio AND :data_fim";

if ($tipo_filtro != 'todos') {
    $sql_extrato .= " AND c.tipo = :tipo";
}
if (!empty($categoria_filtro)) {
    $sql_extrato .= " AND c.categoria = :categoria";
}
if ($aluno_filtro > 0) {
    $sql_extrato .= " AND c.assinatura_id = :aluno_id";
}
if (!empty($fornecedor_filtro)) {
    $sql_extrato .= " AND c.fornecedor LIKE :fornecedor";
}

$sql_extrato .= " ORDER BY c.data_movimento DESC, c.id DESC LIMIT :offset, :limit";

$stmt_extrato = $conn->prepare($sql_extrato);
$stmt_extrato->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_extrato->bindParam(':data_inicio', $data_inicio);
$stmt_extrato->bindParam(':data_fim', $data_fim);
$stmt_extrato->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt_extrato->bindParam(':limit', $por_pagina, PDO::PARAM_INT);

if ($tipo_filtro != 'todos') {
    $stmt_extrato->bindParam(':tipo', $tipo_filtro);
}
if (!empty($categoria_filtro)) {
    $stmt_extrato->bindParam(':categoria', $categoria_filtro);
}
if ($aluno_filtro > 0) {
    $stmt_extrato->bindParam(':aluno_id', $aluno_filtro, PDO::PARAM_INT);
}
if (!empty($fornecedor_filtro)) {
    $fornecedor_like = "%$fornecedor_filtro%";
    $stmt_extrato->bindParam(':fornecedor', $fornecedor_like);
}

$stmt_extrato->execute();
$extratos = $stmt_extrato->fetchAll(PDO::FETCH_ASSOC);

// Contar total para paginação
$sql_total = "SELECT COUNT(*) as total FROM caixa c
              WHERE c.escola_id = :escola_id 
              AND c.status = 'ativo'
              AND DATE(c.data_movimento) BETWEEN :data_inicio AND :data_fim";
if ($tipo_filtro != 'todos') {
    $sql_total .= " AND c.tipo = :tipo";
}
if (!empty($categoria_filtro)) {
    $sql_total .= " AND c.categoria = :categoria";
}
if ($aluno_filtro > 0) {
    $sql_total .= " AND c.assinatura_id = :aluno_id";
}

$stmt_total = $conn->prepare($sql_total);
$stmt_total->bindParam(':escola_id', $escola_id, PDO::PARAM_INT);
$stmt_total->bindParam(':data_inicio', $data_inicio);
$stmt_total->bindParam(':data_fim', $data_fim);
if ($tipo_filtro != 'todos') {
    $stmt_total->bindParam(':tipo', $tipo_filtro);
}
if (!empty($categoria_filtro)) {
    $stmt_total->bindParam(':categoria', $categoria_filtro);
}
if ($aluno_filtro > 0) {
    $stmt_total->bindParam(':aluno_id', $aluno_filtro, PDO::PARAM_INT);
}
$stmt_total->execute();
$total_registros = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_paginas = ceil($total_registros / $por_pagina);

// Calcular totais
$total_entradas = array_sum(array_column(array_filter($extratos, function($item) {
    return $item['tipo'] == 'entrada';
}), 'valor'));
$total_saidas = array_sum(array_column(array_filter($extratos, function($item) {
    return $item['tipo'] == 'saida';
}), 'valor'));
$saldo = $total_entradas - $total_saidas;

// Saldo acumulado
$saldo_acumulado = 0;
foreach ($extratos as &$ext) {
    if ($ext['tipo'] == 'entrada') {
        $saldo_acumulado += $ext['valor'];
    } else {
        $saldo_acumulado -= $ext['valor'];
    }
    $ext['saldo'] = $saldo_acumulado;
}

// Buscar categorias para filtro
$sql_categorias = "SELECT DISTINCT categoria FROM caixa WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY categoria ASC";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_COLUMN, 0);

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
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extrato Financeiro | Tesouraria | SIGE Angola</title>
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
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .entrada-row { background-color: #d4edda; border-left: 4px solid #28a745; }
        .saida-row { background-color: #f8d7da; border-left: 4px solid #dc3545; }
        
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .entrada-row, .saida-row { background-color: transparent; }
        }
        
        .extrato-table td { vertical-align: middle; }
        .extrato-table .saldo-col { font-weight: bold; }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice"></i> Extrato Financeiro</h2>
                <p class="text-muted">Consulta detalhada de todas as movimentações</p>
            </div>
            <div class="no-print">
                <button class="btn btn-info" onclick="window.print();">
                    <i class="fas fa-print"></i> Imprimir Extrato
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros de Busca</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                    </div>
                    <div class="col-md-2">
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
                    <div class="col-md-3">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $categoria_filtro == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Aluno</label>
                        <select name="aluno_id" class="form-select">
                            <option value="0">Todos os alunos</option>
                            <?php foreach ($alunos as $aluno): ?>
                            <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_filtro == $aluno['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                        <a href="extrato.php" class="btn btn-secondary">Limpar Filtros</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($total_entradas); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small>Período selecionado</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($total_saidas); ?></div>
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
        
        <!-- Tabela de Extrato -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Movimentações</h5>
                <small>Total de <?php echo number_format($total_registros, 0, ',', '.'); ?> registro(s) encontrado(s)</small>
            </div>
            <div class="card-body">
                <?php if (empty($extratos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma movimentação encontrada no período selecionado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover extrato-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Aluno</th>
                                    <th>Forma</th>
                                    <th>Documento</th>
                                    <th class="text-end">Valor</th>
                                    <th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $saldo_mostrado = 0;
                                foreach ($extratos as $ext): 
                                    $saldo_mostrado = $ext['saldo'];
                                ?>
                                <tr class="<?php echo $ext['tipo'] == 'entrada' ? 'entrada-row' : 'saida-row'; ?>">
                                    <td><?php echo date('d/m/Y', strtotime($ext['data_movimento'])); ?></td>
                                    <td><?php echo getTipoBadge($ext['tipo']); ?></td>
                                    <td><?php echo htmlspecialchars($ext['categoria']); ?></td>
                                    <td><?php echo htmlspecialchars($ext['descricao']); ?></td>
                                    <td><?php echo htmlspecialchars($ext['aluno_nome'] ?? '-'); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($ext['metodo_pagamento']); ?> <?php echo ucfirst($ext['metodo_pagamento'] ?? 'Dinheiro'); ?></td>
                                    <td><?php echo htmlspecialchars($ext['referencia'] ?: '-'); ?></td>
                                    <td class="text-end <?php echo $ext['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        <?php echo ($ext['tipo'] == 'entrada' ? '+' : '-') . ' ' . formatarMoeda($ext['valor']); ?>
                                    </td>
                                    <td class="text-end saldo-col <?php echo $saldo_mostrado >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatarMoeda($saldo_mostrado); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="7" class="text-end">TOTAIS:</td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($total_entradas); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($total_saidas); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="7" class="text-end">SALDO:</td>
                                    <td colspan="2" class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        <?php echo formatarMoeda($saldo); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <?php if ($total_paginas > 1): ?>
                    <nav class="mt-3 no-print">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&categoria=<?php echo urlencode($categoria_filtro); ?>&aluno_id=<?php echo $aluno_filtro; ?>">
                                    <i class="fas fa-chevron-left"></i> Anterior
                                </a>
                            </li>
                            <?php 
                            $max_paginas = 5;
                            $inicio = max(1, $pagina - floor($max_paginas / 2));
                            $fim = min($total_paginas, $inicio + $max_paginas - 1);
                            if ($inicio > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?pagina=1&data_inicio=' . urlencode($data_inicio) . '&data_fim=' . urlencode($data_fim) . '&tipo=' . urlencode($tipo_filtro) . '&categoria=' . urlencode($categoria_filtro) . '&aluno_id=' . $aluno_filtro . '">1</a></li>';
                                if ($inicio > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for($i = $inicio; $i <= $fim; $i++): 
                            ?>
                            <li class="page-item <?php echo $pagina == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $i; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&categoria=<?php echo urlencode($categoria_filtro); ?>&aluno_id=<?php echo $aluno_filtro; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; 
                            if ($fim < $total_paginas) {
                                if ($fim < $total_paginas - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="?pagina=' . $total_paginas . '&data_inicio=' . urlencode($data_inicio) . '&data_fim=' . urlencode($data_fim) . '&tipo=' . urlencode($tipo_filtro) . '&categoria=' . urlencode($categoria_filtro) . '&aluno_id=' . $aluno_filtro . '">' . $total_paginas . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&tipo=<?php echo urlencode($tipo_filtro); ?>&categoria=<?php echo urlencode($categoria_filtro); ?>&aluno_id=<?php echo $aluno_filtro; ?>">
                                    Próxima <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
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
    </script>
</body>
</html>