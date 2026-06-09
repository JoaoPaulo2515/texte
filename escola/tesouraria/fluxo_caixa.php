<?php
// escola/tesouraria/fluxo_caixa.php - Fluxo de Caixa

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
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

// ============================================
// CONSULTAS
// ============================================

// Totais gerais
$sql_totais = "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND YEAR(data_movimento) = :ano";
if ($mes_filtro > 0) {
    $sql_totais .= " AND MONTH(data_movimento) = :mes";
}
$stmt_totais = $conn->prepare($sql_totais);
$params = [':escola_id' => $escola_id, ':ano' => $ano_filtro];
if ($mes_filtro > 0) {
    $params[':mes'] = $mes_filtro;
}
$stmt_totais->execute($params);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
$saldo = ($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0);

// Fluxo mensal (últimos 12 meses)
$sql_mensal = "SELECT 
                DATE_FORMAT(data_movimento, '%Y-%m') as mes,
                DATE_FORMAT(data_movimento, '%b/%Y') as mes_nome,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND YEAR(data_movimento) >= :ano_inicio
              AND status = 'ativo'
              GROUP BY DATE_FORMAT(data_movimento, '%Y-%m')
              ORDER BY mes ASC
              LIMIT 12";
$stmt_mensal = $conn->prepare($sql_mensal);
$stmt_mensal->execute([
    ':escola_id' => $escola_id,
    ':ano_inicio' => $ano_filtro - 1
]);
$fluxo_mensal = $stmt_mensal->fetchAll(PDO::FETCH_ASSOC);

// Fluxo por categoria
$sql_categorias = "SELECT 
                    tipo,
                    categoria,
                    COUNT(*) as quantidade,
                    COALESCE(SUM(valor), 0) as total
                  FROM caixa 
                  WHERE escola_id = :escola_id 
                  AND YEAR(data_movimento) = :ano
                  AND status = 'ativo'";
if ($mes_filtro > 0) {
    $sql_categorias .= " AND MONTH(data_movimento) = :mes";
}
$sql_categorias .= " GROUP BY tipo, categoria ORDER BY total DESC LIMIT 10";

$stmt_categorias = $conn->prepare($sql_categorias);
$params_cat = [':escola_id' => $escola_id, ':ano' => $ano_filtro];
if ($mes_filtro > 0) {
    $params_cat[':mes'] = $mes_filtro;
}
$stmt_categorias->execute($params_cat);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Saldo acumulado diário (últimos 30 dias)
$sql_diario = "SELECT 
                DATE(data_movimento) as data,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND data_movimento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND status = 'ativo'
              GROUP BY DATE(data_movimento)
              ORDER BY data ASC";
$stmt_diario = $conn->prepare($sql_diario);
$stmt_diario->execute([':escola_id' => $escola_id]);
$movimentos_diarios = $stmt_diario->fetchAll(PDO::FETCH_ASSOC);

// Calcular saldo acumulado
$saldo_acumulado = 0;
foreach ($movimentos_diarios as &$dia) {
    $saldo_acumulado += ($dia['entradas'] - $dia['saidas']);
    $dia['saldo'] = $saldo_acumulado;
}

// Últimas movimentações
$sql_ultimas = "SELECT * FROM caixa 
                WHERE escola_id = :escola_id 
                AND status = 'ativo'
                ORDER BY data_movimento DESC, id DESC 
                LIMIT 20";
$stmt_ultimas = $conn->prepare($sql_ultimas);
$stmt_ultimas->execute([':escola_id' => $escola_id]);
$ultimas_movimentacoes = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Fluxo de Caixa | Tesouraria | SIGE Angola</title>
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
        
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
        
        .entrada-row { border-left: 4px solid #28a745; }
        .saida-row { border-left: 4px solid #dc3545; }
        
        .chart-container { position: relative; height: 300px; }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-line"></i> Fluxo de Caixa</h2>
                <p class="text-muted">Análise de entradas e saídas financeiras</p>
            </div>
            <div class="no-print">
                <button class="btn btn-info" onclick="window.print();">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
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
                        <label class="form-label">Ano</label>
                        <select name="ano" class="form-select">
                            <?php for($a = date('Y')-2; $a <= date('Y'); $a++): ?>
                            <option value="<?php echo $a; ?>" <?php echo $ano_filtro == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mês</label>
                        <select name="mes" class="form-select">
                            <option value="0">Todos os meses</option>
                            <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $mes_filtro == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                    <div class="col-md-4 text-end">
                        <label class="form-label">&nbsp;</label>
                        <a href="fluxo_caixa.php" class="btn btn-secondary w-100">Limpar Filtros</a>
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
                    <small><?php echo $mes_filtro > 0 ? date('F', mktime(0,0,0,$mes_filtro,1)) . ' de ' : ''; ?><?php echo $ano_filtro; ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small><?php echo $mes_filtro > 0 ? date('F', mktime(0,0,0,$mes_filtro,1)) . ' de ' : ''; ?><?php echo $ano_filtro; ?></small>
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
        
        <!-- Gráfico de Fluxo Mensal -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Fluxo Mensal (Últimos 12 meses)</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoMensal" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Saldo Acumulado Diário -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Saldo Acumulado - Últimos 30 dias</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoSaldo" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <!-- Top Categorias -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Principais Categorias</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="graficoCategorias"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach ($categorias as $cat): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>
                                    <?php echo getTipoBadge($cat['tipo']); ?>
                                    <strong><?php echo htmlspecialchars($cat['categoria']); ?></strong>
                                </span>
                                <span class="fw-bold <?php echo $cat['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatarMoeda($cat['total']); ?>
                                </span>
                                <span class="text-muted">(<?php echo $cat['quantidade']; ?>x)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Últimas Movimentações -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Últimas Movimentações</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr><th>Data</th><th>Tipo</th><th>Categoria</th><th>Valor</th><th>Forma</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ultimas_movimentacoes)): ?>
                                    <tr><td colspan="5" class="text-center">Nenhuma movimentação</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($ultimas_movimentacoes as $mov): ?>
                                        <tr class="<?php echo $mov['tipo'] == 'entrada' ? 'entrada-row' : 'saida-row'; ?>">
                                            <td><small><?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?></small></td>
                                            <td><?php echo getTipoBadge($mov['tipo']); ?></td>
                                            <td><?php echo htmlspecialchars($mov['categoria']); ?></td>
                                            <td class="<?php echo $mov['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                                <?php echo formatarMoeda($mov['valor']); ?>
                                            </td>
                                            <td><?php echo getFormaPagamentoIcone($mov['metodo_pagamento']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Fluxo Mensal Detalhado -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Detalhamento Mensal</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Mês/Ano</th>
                                <th class="text-end">Entradas</th>
                                <th class="text-end">Saídas</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-end">% do Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $max_entradas = max(array_column($fluxo_mensal, 'entradas')) ?: 1;
                            foreach ($fluxo_mensal as $mes):
                                $saldo_mes = $mes['entradas'] - $mes['saidas'];
                                $percentual = ($mes['entradas'] / $max_entradas) * 100;
                            ?>
                            <tr>
                                <td><strong><?php echo $mes['mes_nome']; ?></strong></td>
                                <td class="text-end text-success"><?php echo formatarMoeda($mes['entradas']); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($mes['saidas']); ?></td>
                                <td class="text-end <?php echo $saldo_mes >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo formatarMoeda($saldo_mes); ?>
                                </td>
                                <td class="text-end">
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($percentual, 1); ?>%</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fluxo_mensal)): ?>
                            <tr><td colspan="5" class="text-center">Nenhum dado disponível</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
        
        // Gráfico de Fluxo Mensal
        const mesesLabels = <?php echo json_encode(array_column($fluxo_mensal, 'mes_nome')); ?>;
        const entradasData = <?php echo json_encode(array_column($fluxo_mensal, 'entradas')); ?>;
        const saidasData = <?php echo json_encode(array_column($fluxo_mensal, 'saidas')); ?>;
        
        if (mesesLabels.length > 0) {
            new Chart(document.getElementById('graficoMensal'), {
                type: 'bar',
                data: {
                    labels: mesesLabels,
                    datasets: [
                        {
                            label: 'Entradas',
                            data: entradasData,
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: '#28a745',
                            borderWidth: 1
                        },
                        {
                            label: 'Saídas',
                            data: saidasData,
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
        }
        
        // Gráfico de Saldo Acumulado
        const datasLabels = <?php echo json_encode(array_column($movimentos_diarios, 'data')); ?>;
        const saldoData = <?php echo json_encode(array_column($movimentos_diarios, 'saldo')); ?>;
        
        if (datasLabels.length > 0) {
            new Chart(document.getElementById('graficoSaldo'), {
                type: 'line',
                data: {
                    labels: datasLabels,
                    datasets: [{
                        label: 'Saldo Acumulado',
                        data: saldoData,
                        borderColor: '#006B3E',
                        backgroundColor: 'rgba(0, 107, 62, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Saldo: ' + context.raw.toLocaleString('pt-AO') + ' Kz';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('pt-AO') + ' Kz';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Gráfico de Categorias
        const catLabels = <?php echo json_encode(array_column($categorias, 'categoria')); ?>;
        const catValores = <?php echo json_encode(array_column($categorias, 'total')); ?>;
        const catCores = catLabels.map((_, i) => 
            <?php echo json_encode(array_column($categorias, 'tipo')); ?>[i] === 'entrada' ? '#28a745' : '#dc3545'
        );
        
        if (catLabels.length > 0) {
            new Chart(document.getElementById('graficoCategorias'), {
                type: 'pie',
                data: {
                    labels: catLabels,
                    datasets: [{
                        data: catValores,
                        backgroundColor: catCores
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
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