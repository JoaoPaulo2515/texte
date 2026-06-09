<?php
// escola/financeiro/balancete/comparativo.php - Comparativo de Períodos
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

$periodo1_inicio = $_GET['periodo1_inicio'] ?? date('Y-m-01');
$periodo1_fim = $_GET['periodo1_fim'] ?? date('Y-m-t');
$periodo2_inicio = $_GET['periodo2_inicio'] ?? date('Y-m-d', strtotime('-1 month', strtotime(date('Y-m-01'))));
$periodo2_fim = $_GET['periodo2_fim'] ?? date('Y-m-d', strtotime('-1 month', strtotime(date('Y-m-t'))));
$tipo_comparativo = $_GET['tipo'] ?? 'categorias'; // categorias, contas, resumo

// ============================================
// BUSCAR DADOS DO PERÍODO 1
// ============================================

$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento BETWEEN :data_inicio AND :data_fim
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $periodo1_inicio, ':data_fim' => $periodo1_fim]);
$periodo1_totais = $stmt->fetch(PDO::FETCH_ASSOC);

// Dados por categoria - Período 1
$stmt = $conn->prepare("
    SELECT 
        categoria,
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento BETWEEN :data_inicio AND :data_fim
    GROUP BY categoria
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $periodo1_inicio, ':data_fim' => $periodo1_fim]);
$periodo1_categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DO PERÍODO 2
// ============================================

$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento BETWEEN :data_inicio AND :data_fim
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $periodo2_inicio, ':data_fim' => $periodo2_fim]);
$periodo2_totais = $stmt->fetch(PDO::FETCH_ASSOC);

// Dados por categoria - Período 2
$stmt = $conn->prepare("
    SELECT 
        categoria,
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento BETWEEN :data_inicio AND :data_fim
    GROUP BY categoria
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $periodo2_inicio, ':data_fim' => $periodo2_fim]);
$periodo2_categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Combinar categorias
$todas_categorias = [];
foreach ($periodo1_categorias as $c) {
    $todas_categorias[$c['categoria']] = true;
}
foreach ($periodo2_categorias as $c) {
    $todas_categorias[$c['categoria']] = true;
}

$categorias_nomes = [
    'mensalidade' => 'Mensalidades',
    'matricula' => 'Matrículas',
    'taxa' => 'Taxas',
    'doacao' => 'Doações',
    'salario' => 'Salários',
    'material' => 'Material Escolar',
    'utilidade' => 'Utilidades',
    'manutencao' => 'Manutenção',
    'imposto' => 'Impostos',
    'outro_entrada' => 'Outras Entradas',
    'outro_saida' => 'Outras Saídas'
];

// Calcular variações
$variacao_entradas = 0;
$variacao_saidas = 0;
$variacao_saldo = 0;

if (($periodo1_totais['entradas'] ?? 0) > 0) {
    $variacao_entradas = round((($periodo2_totais['entradas'] - $periodo1_totais['entradas']) / $periodo1_totais['entradas']) * 100, 1);
}
if (($periodo1_totais['saidas'] ?? 0) > 0) {
    $variacao_saidas = round((($periodo2_totais['saidas'] - $periodo1_totais['saidas']) / $periodo1_totais['saidas']) * 100, 1);
}

$saldo1 = ($periodo1_totais['entradas'] ?? 0) - ($periodo1_totais['saidas'] ?? 0);
$saldo2 = ($periodo2_totais['entradas'] ?? 0) - ($periodo2_totais['saidas'] ?? 0);
if ($saldo1 != 0) {
    $variacao_saldo = round((($saldo2 - $saldo1) / abs($saldo1)) * 100, 1);
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparativo de Períodos | Financeiro | SIGE Angola</title>
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
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .compare-up { color: #28a745; font-weight: bold; }
        .compare-down { color: #dc3545; font-weight: bold; }
        .compare-neutral { color: #6c757d; }
        
        .card-periodo { background: white; border-radius: 15px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .periodo-titulo { font-size: 1.1em; font-weight: bold; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #006B3E; }
        
        .chart-container { position: relative; height: 400px; width: 100%; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> <span>Financeiro</span>
                </a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="../contas_receber/index.php" class="nav-link"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
                    <li class="nav-item"><a href="../contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
                    <li class="nav-item"><a href="../fluxo_caixa/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                    <li class="nav-item"><a href="../mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                    <li class="nav-item"><a href="../extratos.php" class="nav-link"><i class="fas fa-file-invoice"></i> Extratos</a></li>
                    <li class="nav-item"><a href="../recibos.php" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-chart-line"></i> Comparativo de Períodos
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-chart-simple"></i> Balancete Geral
                </a>
                <a href="contas.php" class="btn btn-info btn-sm">
                    <i class="fas fa-book"></i> Por Conta
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Período 1 - Início</label>
                    <input type="date" name="periodo1_inicio" class="form-control" value="<?php echo $periodo1_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label>Período 1 - Fim</label>
                    <input type="date" name="periodo1_fim" class="form-control" value="<?php echo $periodo1_fim; ?>">
                </div>
                <div class="col-md-3">
                    <label>Período 2 - Início</label>
                    <input type="date" name="periodo2_inicio" class="form-control" value="<?php echo $periodo2_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label>Período 2 - Fim</label>
                    <input type="date" name="periodo2_fim" class="form-control" value="<?php echo $periodo2_fim; ?>">
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary w-100">Comparar</button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo Comparativo -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Total de Entradas</h6>
                        <h4 class="text-success"><?php echo number_format($periodo1_totais['entradas'] ?? 0, 2, ',', '.'); ?> Kz</h4>
                        <h4 class="text-success"><?php echo number_format($periodo2_totais['entradas'] ?? 0, 2, ',', '.'); ?> Kz</h4>
                        <div class="<?php echo $variacao_entradas >= 0 ? 'compare-up' : 'compare-down'; ?>">
                            <i class="fas fa-arrow-<?php echo $variacao_entradas >= 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo abs($variacao_entradas); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Total de Saídas</h6>
                        <h4 class="text-danger"><?php echo number_format($periodo1_totais['saidas'] ?? 0, 2, ',', '.'); ?> Kz</h4>
                        <h4 class="text-danger"><?php echo number_format($periodo2_totais['saidas'] ?? 0, 2, ',', '.'); ?> Kz</h4>
                        <div class="<?php echo $variacao_saidas <= 0 ? 'compare-up' : 'compare-down'; ?>">
                            <i class="fas fa-arrow-<?php echo $variacao_saidas <= 0 ? 'down' : 'up'; ?>"></i>
                            <?php echo abs($variacao_saidas); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Saldo do Período</h6>
                        <h4 class="<?php echo $saldo1 >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($saldo1, 2, ',', '.'); ?> Kz</h4>
                        <h4 class="<?php echo $saldo2 >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($saldo2, 2, ',', '.'); ?> Kz</h4>
                        <div class="<?php echo $variacao_saldo >= 0 ? 'compare-up' : 'compare-down'; ?>">
                            <i class="fas fa-arrow-<?php echo $variacao_saldo >= 0 ? 'up' : 'down'; ?>"></i>
                            <?php echo abs($variacao_saldo); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráfico Comparativo -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-bar"></i> Comparativo Entradas vs Saídas</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="comparativoChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Tabela Comparativa por Categoria -->
        <div class="card">
            <div class="card-header"><i class="fas fa-table"></i> Comparativo por Categoria</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Categoria</th>
                                <th colspan="2" class="text-center">Período 1</th>
                                <th colspan="2" class="text-center">Período 2</th>
                                <th colspan="2" class="text-center">Variação</th>
                            </tr>
                            <tr class="table-secondary">
                                <th></th>
                                <th>Entradas</th>
                                <th>Saídas</th>
                                <th>Entradas</th>
                                <th>Saídas</th>
                                <th>Entradas</th>
                                <th>Saídas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todas_categorias as $cat => $value): 
                                $p1_entradas = 0;
                                $p1_saidas = 0;
                                $p2_entradas = 0;
                                $p2_saidas = 0;
                                
                                foreach ($periodo1_categorias as $c) {
                                    if ($c['categoria'] == $cat) {
                                        $p1_entradas = $c['entradas'];
                                        $p1_saidas = $c['saidas'];
                                        break;
                                    }
                                }
                                foreach ($periodo2_categorias as $c) {
                                    if ($c['categoria'] == $cat) {
                                        $p2_entradas = $c['entradas'];
                                        $p2_saidas = $c['saidas'];
                                        break;
                                    }
                                }
                                
                                $var_entradas = 0;
                                $var_saidas = 0;
                                if ($p1_entradas > 0) $var_entradas = round((($p2_entradas - $p1_entradas) / $p1_entradas) * 100, 1);
                                if ($p1_saidas > 0) $var_saidas = round((($p2_saidas - $p1_saidas) / $p1_saidas) * 100, 1);
                            ?>
                            <tr>
                                <td><strong><?php echo $categorias_nomes[$cat] ?? $cat; ?></strong></td>
                                <td class="text-end text-success"><?php echo number_format($p1_entradas, 2, ',', '.'); ?> Kz</div>
                                <td class="text-end text-danger"><?php echo number_format($p1_saidas, 2, ',', '.'); ?> Kz</div>
                                <td class="text-end text-success"><?php echo number_format($p2_entradas, 2, ',', '.'); ?> Kz</div>
                                <td class="text-end text-danger"><?php echo number_format($p2_saidas, 2, ',', '.'); ?> Kz</div>
                                <td class="text-end <?php echo $var_entradas >= 0 ? 'compare-up' : 'compare-down'; ?>">
                                    <?php echo ($var_entradas >= 0 ? '+' : '') . $var_entradas; ?>%
                                </div>
                                <td class="text-end <?php echo $var_saidas <= 0 ? 'compare-up' : 'compare-down'; ?>">
                                    <?php echo ($var_saidas <= 0 ? '-' : '+') . abs($var_saidas); ?>%
                                </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre o Comparativo de Períodos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é o Comparativo de Períodos?</h6>
                    <p>O comparativo permite analisar a evolução financeira entre dois períodos diferentes, identificando tendências de crescimento ou redução.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Como interpretar:</h6>
                    <ul>
                        <li><strong class="text-success">↑ Verde (positivo):</strong> Aumento de entradas ou redução de saídas.</li>
                        <li><strong class="text-danger">↓ Vermelho (negativo):</strong> Redução de entradas ou aumento de saídas.</li>
                        <li><strong>Variação percentual:</strong> Mostra a mudança relativa entre os períodos.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Compare meses iguais de anos diferentes para análise sazonal.</li>
                        <li>Compare períodos antes e depois de mudanças estratégicas.</li>
                        <li>Identifique categorias com maior variação para focar ações corretivas.</li>
                        <li>Use o gráfico de barras para visualizar rapidamente as diferenças.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
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
        
        // Gráfico Comparativo
        const ctx = document.getElementById('comparativoChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Entradas', 'Saídas', 'Saldo'],
                datasets: [
                    {
                        label: 'Período 1',
                        data: [
                            <?php echo $periodo1_totais['entradas'] ?? 0; ?>,
                            <?php echo $periodo1_totais['saidas'] ?? 0; ?>,
                            <?php echo $saldo1; ?>
                        ],
                        backgroundColor: '#17a2b8',
                        borderRadius: 5
                    },
                    {
                        label: 'Período 2',
                        data: [
                            <?php echo $periodo2_totais['entradas'] ?? 0; ?>,
                            <?php echo $periodo2_totais['saidas'] ?? 0; ?>,
                            <?php echo $saldo2; ?>
                        ],
                        backgroundColor: '#006B3E',
                        borderRadius: 5
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
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>