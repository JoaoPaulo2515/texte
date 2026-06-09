<?php
// escola/tesouraria/balancete.php - Balancete Financeiro

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
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'mensal';

// Calcular período com base na seleção
switch ($periodo) {
    case 'trimestral':
        $mes_inicio = (ceil($mes_filtro / 3) - 1) * 3 + 1;
        $mes_fim = $mes_inicio + 2;
        $label_periodo = "Trimestre " . ceil($mes_filtro / 3) . "/$ano_filtro";
        break;
    case 'semestral':
        $mes_inicio = $mes_filtro <= 6 ? 1 : 7;
        $mes_fim = $mes_filtro <= 6 ? 6 : 12;
        $label_periodo = (($mes_filtro <= 6) ? '1º Semestre' : '2º Semestre') . "/$ano_filtro";
        break;
    case 'anual':
        $mes_inicio = 1;
        $mes_fim = 12;
        $label_periodo = "Ano $ano_filtro";
        break;
    default:
        $mes_inicio = $mes_filtro;
        $mes_fim = $mes_filtro;
        $label_periodo = getMesNome($mes_filtro) . "/$ano_filtro";
}

// ============================================
// BUSCAR DADOS DO BALANCETE - CORRIGIDO
// ============================================

// 1. Saldo Anterior (acumulado antes do período)
$sql_saldo_anterior = "SELECT 
    COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as total_entradas,
    COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as total_saidas
FROM caixa 
WHERE escola_id = ? 
AND status = 'ativo'
AND (YEAR(data_movimento) < ? 
     OR (YEAR(data_movimento) = ? AND MONTH(data_movimento) < ?))";
$stmt_saldo_anterior = $conn->prepare($sql_saldo_anterior);
$stmt_saldo_anterior->execute([$escola_id, $ano_filtro, $ano_filtro, $mes_inicio]);
$saldo_anterior = $stmt_saldo_anterior->fetch(PDO::FETCH_ASSOC);
if (!$saldo_anterior) {
    $saldo_anterior = ['total_entradas' => 0, 'total_saidas' => 0];
}
$saldo_anterior_valor = ($saldo_anterior['total_entradas'] - $saldo_anterior['total_saidas']);

// 2. Receitas do período
$sql_receitas = "SELECT 
    COALESCE(SUM(valor), 0) as total,
    COUNT(*) as quantidade
FROM caixa 
WHERE escola_id = ? 
AND tipo = 'entrada' 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
AND MONTH(data_movimento) BETWEEN ? AND ?";
$stmt_receitas = $conn->prepare($sql_receitas);
$stmt_receitas->execute([$escola_id, $ano_filtro, $mes_inicio, $mes_fim]);
$receitas = $stmt_receitas->fetch(PDO::FETCH_ASSOC);
if (!$receitas) {
    $receitas = ['total' => 0, 'quantidade' => 0];
}

// 3. Despesas do período
$sql_despesas = "SELECT 
    COALESCE(SUM(valor), 0) as total,
    COUNT(*) as quantidade
FROM caixa 
WHERE escola_id = ? 
AND tipo = 'saida' 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
AND MONTH(data_movimento) BETWEEN ? AND ?";
$stmt_despesas = $conn->prepare($sql_despesas);
$stmt_despesas->execute([$escola_id, $ano_filtro, $mes_inicio, $mes_fim]);
$despesas = $stmt_despesas->fetch(PDO::FETCH_ASSOC);
if (!$despesas) {
    $despesas = ['total' => 0, 'quantidade' => 0];
}

// 4. Receitas por categoria
$sql_receitas_categoria = "SELECT 
    categoria,
    COALESCE(SUM(valor), 0) as total,
    COUNT(*) as quantidade
FROM caixa 
WHERE escola_id = ? 
AND tipo = 'entrada' 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
AND MONTH(data_movimento) BETWEEN ? AND ?
GROUP BY categoria
ORDER BY total DESC";
$stmt_receitas_categoria = $conn->prepare($sql_receitas_categoria);
$stmt_receitas_categoria->execute([$escola_id, $ano_filtro, $mes_inicio, $mes_fim]);
$receitas_por_categoria = $stmt_receitas_categoria->fetchAll(PDO::FETCH_ASSOC);

// 5. Despesas por categoria
$sql_despesas_categoria = "SELECT 
    categoria,
    COALESCE(SUM(valor), 0) as total,
    COUNT(*) as quantidade
FROM caixa 
WHERE escola_id = ? 
AND tipo = 'saida' 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
AND MONTH(data_movimento) BETWEEN ? AND ?
GROUP BY categoria
ORDER BY total DESC";
$stmt_despesas_categoria = $conn->prepare($sql_despesas_categoria);
$stmt_despesas_categoria->execute([$escola_id, $ano_filtro, $mes_inicio, $mes_fim]);
$despesas_por_categoria = $stmt_despesas_categoria->fetchAll(PDO::FETCH_ASSOC);

// 6. Resultado do período
$resultado = ($receitas['total'] ?? 0) - ($despesas['total'] ?? 0);
$saldo_final = $saldo_anterior_valor + $resultado;

// 7. Evolução mensal (para gráfico)
$sql_evolucao = "SELECT 
    MONTH(data_movimento) as mes,
    COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as receitas,
    COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as despesas
FROM caixa 
WHERE escola_id = ? 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
GROUP BY MONTH(data_movimento)
ORDER BY mes ASC";
$stmt_evolucao = $conn->prepare($sql_evolucao);
$stmt_evolucao->execute([$escola_id, $ano_filtro]);
$evolucao_mensal = $stmt_evolucao->fetchAll(PDO::FETCH_ASSOC);

// Preencher meses faltantes
$dados_evolucao = [];
for ($i = 1; $i <= 12; $i++) {
    $encontrado = false;
    foreach ($evolucao_mensal as $ev) {
        if ($ev['mes'] == $i) {
            $dados_evolucao[] = $ev;
            $encontrado = true;
            break;
        }
    }
    if (!$encontrado) {
        $dados_evolucao[] = ['mes' => $i, 'receitas' => 0, 'despesas' => 0];
    }
}

// 8. Resumo por forma de pagamento (receitas)
$sql_formas = "SELECT 
    metodo_pagamento,
    COALESCE(SUM(valor), 0) as total,
    COUNT(*) as quantidade
FROM caixa 
WHERE escola_id = ? 
AND tipo = 'entrada' 
AND status = 'ativo'
AND YEAR(data_movimento) = ? 
AND MONTH(data_movimento) BETWEEN ? AND ?
GROUP BY metodo_pagamento
ORDER BY total DESC";
$stmt_formas = $conn->prepare($sql_formas);
$stmt_formas->execute([$escola_id, $ano_filtro, $mes_inicio, $mes_fim]);
$receitas_por_forma = $stmt_formas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}

function getPercentual($valor, $total) {
    if ($total == 0) return 0;
    return round(($valor / $total) * 100, 1);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balancete | Tesouraria | SIGE Angola</title>
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
        
        .receita-row { border-left: 4px solid #28a745; }
        .despesa-row { border-left: 4px solid #dc3545; }
        .resultado-positivo { color: #28a745; }
        .resultado-negativo { color: #dc3545; }
        
        .table-balancete th { background: #f8f9fa; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-balance-scale"></i> Balancete Financeiro</h2>
                <p class="text-muted">Demonstração de receitas, despesas e resultado do período</p>
            </div>
            <div>
                <a href="index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Período</label>
                        <select name="periodo" class="form-select" onchange="this.form.submit()">
                            <option value="mensal" <?php echo $periodo == 'mensal' ? 'selected' : ''; ?>>Mensal</option>
                            <option value="trimestral" <?php echo $periodo == 'trimestral' ? 'selected' : ''; ?>>Trimestral</option>
                            <option value="semestral" <?php echo $periodo == 'semestral' ? 'selected' : ''; ?>>Semestral</option>
                            <option value="anual" <?php echo $periodo == 'anual' ? 'selected' : ''; ?>>Anual</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Mês</label>
                        <select name="mes" class="form-select" onchange="this.form.submit()">
                            <?php for($i=1; $i<=12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $mes_filtro == $i ? 'selected' : ''; ?>><?php echo getMesNome($i); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ano</label>
                        <select name="ano" class="form-select" onchange="this.form.submit()">
                            <?php for($i = date('Y')-2; $i <= date('Y')+1; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano_filtro == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                            <a href="balancete.php" class="btn btn-secondary w-100"><i class="fas fa-sync-alt"></i> Limpar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($receitas['total']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up"></i> Total de Receitas</div>
                    <small><?php echo $label_periodo; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($despesas['total']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down"></i> Total de Despesas</div>
                    <small><?php echo $label_periodo; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $resultado >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($resultado); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-chart-line"></i> Resultado do Período</div>
                    <small><?php echo $resultado >= 0 ? 'Superavit' : 'Deficit'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $saldo_final >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($saldo_final); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo Acumulado</div>
                    <small>Inclui saldo anterior</small>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Evolução -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Evolução Mensal - <?php echo $ano_filtro; ?></h5>
            </div>
            <div class="card-body">
                <canvas id="evolucaoChart" height="100"></canvas>
            </div>
        </div>
        
        <!-- Balancete Detalhado -->
        <div class="row">
            <!-- Receitas Detalhadas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-arrow-up"></i> Receitas - <?php echo $label_periodo; ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-balancete">
                                <thead>
                                    <tr class="table-light">
                                        <th>Categoria</th>
                                        <th class="text-end">Valor</th>
                                        <th class="text-end">%</th>
                                        <th class="text-center">Qtd</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($receitas_por_categoria)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">Nenhuma receita registrada</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($receitas_por_categoria as $cat): ?>
                                        <tr class="receita-row">
                                            <td>
                                                <i class="fas fa-tag me-1"></i>
                                                <?php echo htmlspecialchars($cat['categoria']); ?>
                                             </td>
                                            <td class="text-end text-success fw-bold">
                                                <?php echo formatarMoeda($cat['total']); ?>
                                             </td>
                                            <td class="text-end">
                                                <?php echo getPercentual($cat['total'], $receitas['total']); ?>%
                                             </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo $cat['quantidade']; ?></span>
                                             </td>
                                         </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>TOTAL DE RECEITAS</td>
                                        <td class="text-end text-success"><?php echo formatarMoeda($receitas['total']); ?></td>
                                        <td class="text-end">100%</td>
                                        <td class="text-center"><?php echo $receitas['quantidade']; ?></td>
                                     </tr>
                                </tfoot>
                             </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Despesas Detalhadas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-arrow-down"></i> Despesas - <?php echo $label_periodo; ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-balancete">
                                <thead>
                                    <tr class="table-light">
                                        <th>Categoria</th>
                                        <th class="text-end">Valor</th>
                                        <th class="text-end">%</th>
                                        <th class="text-center">Qtd</th>
                                     </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($despesas_por_categoria)): ?>
                                     <tr>
                                        <td colspan="4" class="text-center text-muted">Nenhuma despesa registrada</td>
                                     </tr>
                                    <?php else: ?>
                                        <?php foreach ($despesas_por_categoria as $cat): ?>
                                        <tr class="despesa-row">
                                             <td>
                                                <i class="fas fa-tag me-1"></i>
                                                <?php echo htmlspecialchars($cat['categoria']); ?>
                                             </td>
                                            <td class="text-end text-danger fw-bold">
                                                <?php echo formatarMoeda($cat['total']); ?>
                                             </td>
                                            <td class="text-end">
                                                <?php echo getPercentual($cat['total'], $despesas['total']); ?>%
                                             </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo $cat['quantidade']; ?></span>
                                             </td>
                                         </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>TOTAL DE DESPESAS</td>
                                        <td class="text-end text-danger"><?php echo formatarMoeda($despesas['total']); ?></td>
                                        <td class="text-end">100%</td>
                                        <td class="text-center"><?php echo $despesas['quantidade']; ?></td>
                                     </tr>
                                </tfoot>
                             </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo por Forma de Pagamento -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-credit-card"></i> Receitas por Forma de Pagamento</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if (empty($receitas_por_forma)): ?>
                        <div class="alert alert-info text-center">Nenhuma receita registrada no período</div>
                    <?php else: ?>
                        <?php foreach ($receitas_por_forma as $forma): ?>
                        <div class="col-md-3 mb-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <?php
                                    $icone_forma = '';
                                    switch($forma['metodo_pagamento']) {
                                        case 'dinheiro': $icone_forma = 'fas fa-money-bill-wave text-success'; break;
                                        case 'transferencia': $icone_forma = 'fas fa-university text-primary'; break;
                                        case 'deposito': $icone_forma = 'fas fa-money-bill text-info'; break;
                                        case 'cheque': $icone_forma = 'fas fa-check-circle text-warning'; break;
                                        case 'multicaixa': $icone_forma = 'fas fa-credit-card text-secondary'; break;
                                        default: $icone_forma = 'fas fa-question-circle';
                                    }
                                    ?>
                                    <i class="<?php echo $icone_forma; ?> fa-2x mb-2"></i>
                                    <h6><?php echo ucfirst($forma['metodo_pagamento']); ?></h6>
                                    <div class="fw-bold text-success"><?php echo formatarMoeda($forma['total']); ?></div>
                                    <small class="text-muted"><?php echo $forma['quantidade']; ?> transações</small>
                                    <div class="progress mt-2" style="height: 5px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo getPercentual($forma['total'], $receitas['total']); ?>%"></div>
                                    </div>
                                    <small><?php echo getPercentual($forma['total'], $receitas['total']); ?>% do total</small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Resumo do Balancete -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Resumo do Balancete</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="fw-bold">Saldo Anterior</div>
                            <div class="h4 <?php echo $saldo_anterior_valor >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatarMoeda($saldo_anterior_valor); ?>
                            </div>
                            <small>Acumulado até período anterior</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="fw-bold">Resultado do Período</div>
                            <div class="h4 <?php echo $resultado >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatarMoeda($resultado); ?>
                            </div>
                            <small><?php echo $resultado >= 0 ? 'Receitas > Despesas' : 'Despesas > Receitas'; ?></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="fw-bold">Saldo Final</div>
                            <div class="h4 <?php echo $saldo_final >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatarMoeda($saldo_final); ?>
                            </div>
                            <small>Saldo acumulado até o período</small>
                        </div>
                    </div>
                </div>
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
        
        // Gráfico de Evolução
        const meses = <?php 
            $labels = [];
            foreach ($dados_evolucao as $d) {
                $labels[] = getMesNome($d['mes']);
            }
            echo json_encode($labels);
        ?>;
        
        const receitasData = <?php echo json_encode(array_column($dados_evolucao, 'receitas')); ?>;
        const despesasData = <?php echo json_encode(array_column($dados_evolucao, 'despesas')); ?>;
        
        new Chart(document.getElementById('evolucaoChart'), {
            type: 'bar',
            data: {
                labels: meses,
                datasets: [
                    {
                        label: 'Receitas',
                        data: receitasData,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 1
                    },
                    {
                        label: 'Despesas',
                        data: despesasData,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' },
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
    </script>
</body>
</html>