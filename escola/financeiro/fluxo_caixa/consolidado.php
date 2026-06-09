<?php
// escola/financeiro/fluxo_caixa/consolidado.php - Consolidado por Período
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// FILTROS
// ============================================

$ano = $_GET['ano'] ?? date('Y');
$mes = $_GET['mes'] ?? '';
$tipo_consolidado = $_GET['tipo'] ?? 'mensal'; // mensal, trimestral, semestral, anual

// ============================================
// BUSCAR DADOS CONSOLIDADOS
// ============================================

if ($tipo_consolidado == 'mensal' && $mes) {
    $stmt = $conn->prepare("
        SELECT 
            data_movimento,
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) = :mes
        GROUP BY data_movimento
        ORDER BY data_movimento ASC
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes' => $mes]);
    $detalhes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_totais = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) = :mes
    ");
    $stmt_totais->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes' => $mes]);
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
    
} elseif ($tipo_consolidado == 'trimestral') {
    $trimestre = floor(($mes - 1) / 3) + 1;
    $mes_inicio = ($trimestre - 1) * 3 + 1;
    $mes_fim = $trimestre * 3;
    
    $stmt = $conn->prepare("
        SELECT 
            MONTH(data_movimento) as mes,
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) BETWEEN :mes_inicio AND :mes_fim
        GROUP BY MONTH(data_movimento)
        ORDER BY mes ASC
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes_inicio' => $mes_inicio, ':mes_fim' => $mes_fim]);
    $detalhes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_totais = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) BETWEEN :mes_inicio AND :mes_fim
    ");
    $stmt_totais->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes_inicio' => $mes_inicio, ':mes_fim' => $mes_fim]);
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
    
} elseif ($tipo_consolidado == 'semestral') {
    $semestre = ($mes <= 6) ? 1 : 2;
    $mes_inicio = ($semestre == 1) ? 1 : 7;
    $mes_fim = ($semestre == 1) ? 6 : 12;
    
    $stmt = $conn->prepare("
        SELECT 
            MONTH(data_movimento) as mes,
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) BETWEEN :mes_inicio AND :mes_fim
        GROUP BY MONTH(data_movimento)
        ORDER BY mes ASC
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes_inicio' => $mes_inicio, ':mes_fim' => $mes_fim]);
    $detalhes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_totais = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado'
            AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) BETWEEN :mes_inicio AND :mes_fim
    ");
    $stmt_totais->execute([':escola_id' => $escola_id, ':ano' => $ano, ':mes_inicio' => $mes_inicio, ':mes_fim' => $mes_fim]);
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
    
} else { // anual
    $stmt = $conn->prepare("
        SELECT 
            MONTH(data_movimento) as mes,
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado' AND YEAR(data_movimento) = :ano
        GROUP BY MONTH(data_movimento)
        ORDER BY mes ASC
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $detalhes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_totais = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND status = 'confirmado' AND YEAR(data_movimento) = :ano
    ");
    $stmt_totais->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
}

$categorias_entrada = $conn->prepare("
    SELECT categoria, SUM(valor) as total
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND tipo = 'entrada' AND status = 'confirmado'
        AND YEAR(data_movimento) = :ano
    GROUP BY categoria
    ORDER BY total DESC
");
$categorias_entrada->execute([':escola_id' => $escola_id, ':ano' => $ano]);
$categorias_entrada = $categorias_entrada->fetchAll(PDO::FETCH_ASSOC);

$categorias_saida = $conn->prepare("
    SELECT categoria, SUM(valor) as total
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND tipo = 'saida' AND status = 'confirmado'
        AND YEAR(data_movimento) = :ano
    GROUP BY categoria
    ORDER BY total DESC
");
$categorias_saida->execute([':escola_id' => $escola_id, ':ano' => $ano]);
$categorias_saida = $categorias_saida->fetchAll(PDO::FETCH_ASSOC);

$meses_nomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$categorias_entrada_nomes = [
    'mensalidade' => 'Mensalidades',
    'matricula' => 'Matrículas',
    'taxa' => 'Taxas',
    'doacao' => 'Doações',
    'outro_entrada' => 'Outras Entradas'
];

$categorias_saida_nomes = [
    'salario' => 'Salários',
    'material' => 'Material Escolar',
    'utilidade' => 'Utilidades',
    'manutencao' => 'Manutenção',
    'imposto' => 'Impostos',
    'outro_saida' => 'Outras Saídas'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado | Fluxo de Caixa | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        .chart-container { position: relative; height: 400px; width: 100%; }
        .badge-entrada { background: #28a745; color: white; }
        .badge-saida { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-coins"></i> Financeiro</a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                    <li class="nav-item"><a href="lancamentos.php" class="nav-link"><i class="fas fa-list"></i> Lançamentos</a></li>
                    <li class="nav-item"><a href="consolidado.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Consolidado</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-bar"></i> Consolidado por Período</h2>
            <a href="graficos.php" class="btn btn-info btn-sm">
                <i class="fas fa-chart-line"></i> Ver Gráficos
            </a>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="ano" class="form-control">
                        <?php for ($i = date('Y') - 2; $i <= date('Y') + 1; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="tipo" class="form-control">
                        <option value="mensal" <?php echo $tipo_consolidado == 'mensal' ? 'selected' : ''; ?>>Mensal</option>
                        <option value="trimestral" <?php echo $tipo_consolidado == 'trimestral' ? 'selected' : ''; ?>>Trimestral</option>
                        <option value="semestral" <?php echo $tipo_consolidado == 'semestral' ? 'selected' : ''; ?>>Semestral</option>
                        <option value="anual" <?php echo $tipo_consolidado == 'anual' ? 'selected' : ''; ?>>Anual</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="mes" class="form-control" <?php echo $tipo_consolidado == 'anual' ? 'disabled' : ''; ?>>
                        <option value="">Selecione...</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $mes == $i ? 'selected' : ''; ?>><?php echo $meses_nomes[$i]; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Totais -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($totais['total_entradas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Entradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($totais['total_saidas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Saídas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo (($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0)) >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format(($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0), 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo do Período</div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Evolução por Período</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="evolucaoChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Composição das Entradas</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="entradasChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-table"></i> Detalhamento do Período</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Período</th>
                                        <th class="text-end">Entradas (Kz)</th>
                                        <th class="text-end">Saídas (Kz)</th>
                                        <th class="text-end">Saldo (Kz)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $saldo_acumulado = 0;
                                    foreach ($detalhes as $d): 
                                        $saldo = $d['entradas'] - $d['saidas'];
                                        $saldo_acumulado += $saldo;
                                        
                                        if ($tipo_consolidado == 'mensal') {
                                            $periodo = date('d/m/Y', strtotime($d['data_movimento']));
                                        } else {
                                            $periodo = $meses_nomes[$d['mes']];
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo $periodo; ?></td>
                                        <td class="text-end text-success"><?php echo number_format($d['entradas'], 2, ',', '.'); ?> Kz</div>
                                        <td class="text-end text-danger"><?php echo number_format($d['saidas'], 2, ',', '.'); ?> Kz</div>
                                        <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($saldo, 2, ',', '.'); ?> Kz
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <th>TOTAL</th>
                                        <th class="text-end"><?php echo number_format($totais['total_entradas'] ?? 0, 2, ',', '.'); ?> Kz</th>
                                        <th class="text-end"><?php echo number_format($totais['total_saidas'] ?? 0, 2, ',', '.'); ?> Kz</th>
                                        <th class="text-end"><?php echo number_format(($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0), 2, ',', '.'); ?> Kz</th>
                                    </tr>
                                </tfoot>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Gráfico de Evolução
        const ctx1 = document.getElementById('evolucaoChart').getContext('2d');
        const periodos = <?php echo json_encode(array_map(function($d) use ($tipo_consolidado, $meses_nomes) {
            if ($tipo_consolidado == 'mensal') {
                return date('d/m', strtotime($d['data_movimento']));
            }
            return $meses_nomes[$d['mes']];
        }, $detalhes)); ?>;
        const entradas = <?php echo json_encode(array_column($detalhes, 'entradas')); ?>;
        const saidas = <?php echo json_encode(array_column($detalhes, 'saidas')); ?>;
        
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: periodos,
                datasets: [
                    {
                        label: 'Entradas',
                        data: entradas,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Saídas',
                        data: saidas,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4
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
                                return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz';
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Entradas por Categoria
        const ctx2 = document.getElementById('entradasChart').getContext('2d');
        const categoriasEntradaLabels = <?php echo json_encode(array_map(function($c) use ($categorias_entrada_nomes) {
            return $categorias_entrada_nomes[$c['categoria']] ?? $c['categoria'];
        }, $categorias_entrada)); ?>;
        const categoriasEntradaValues = <?php echo json_encode(array_column($categorias_entrada, 'total')); ?>;
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: categoriasEntradaLabels,
                datasets: [{
                    data: categoriasEntradaValues,
                    backgroundColor: ['#28a745', '#20c997', '#17a2b8', '#ffc107', '#fd7e14', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = categoriasEntradaValues.reduce((a, b) => a + b, 0);
                                const percentagem = ((context.raw / total) * 100).toFixed(1);
                                return context.label + ': ' + context.raw.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz (' + percentagem + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>