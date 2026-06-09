<?php
// escola/financeiro/orcamento/executado.php - Execução Orçamentária
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
$tipo = $_GET['tipo'] ?? 'todos'; // todos, receitas, despesas

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar orçamentos planejados
$orcamentos = $conn->prepare("
    SELECT * FROM escola_orcamento 
    WHERE escola_id = :escola_id AND ano = :ano
");
$orcamentos->execute([':escola_id' => $escola_id, ':ano' => $ano]);
$orcamentos = $orcamentos->fetchAll(PDO::FETCH_ASSOC);

// Mapear valores realizados (fluxo de caixa)
$stmt = $conn->prepare("
    SELECT 
        categoria,
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as realizado_receita,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as realizado_despesa
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado' AND YEAR(data_movimento) = :ano
    GROUP BY categoria
");
$stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
$realizados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$realizados_map = [];
foreach ($realizados as $r) {
    $realizados_map[$r['categoria']] = [
        'receita' => $r['realizado_receita'],
        'despesa' => $r['realizado_despesa']
    ];
}

// Buscar categorias
$categorias_receita = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'receita' AND status = 'ativo'
    ORDER BY ordem, nome
");
$categorias_receita->execute([':escola_id' => $escola_id]);
$categorias_receita = $categorias_receita->fetchAll(PDO::FETCH_ASSOC);

$categorias_despesa = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'despesa' AND status = 'ativo'
    ORDER BY ordem, nome
");
$categorias_despesa->execute([':escola_id' => $escola_id]);
$categorias_despesa = $categorias_despesa->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$total_previsto_receitas = 0;
$total_realizado_receitas = 0;
$total_previsto_despesas = 0;
$total_realizado_despesas = 0;

$dados_execucao = [];

foreach ($categorias_receita as $cat) {
    $previsto = 0;
    $realizado = 0;
    foreach ($orcamentos as $o) {
        if ($o['categoria'] == $cat['nome'] && $o['tipo'] == 'receita') {
            $previsto = $o['valor_previsto'];
            break;
        }
    }
    $realizado = $realizados_map[$cat['nome']]['receita'] ?? 0;
    
    $total_previsto_receitas += $previsto;
    $total_realizado_receitas += $realizado;
    
    $percentual = $previsto > 0 ? round(($realizado / $previsto) * 100, 1) : 0;
    $status = $percentual >= 100 ? 'atingida' : ($percentual >= 70 ? 'parcial' : 'baixa');
    
    $dados_execucao['receitas'][] = [
        'categoria' => $cat['nome'],
        'cor' => $cat['cor'],
        'previsto' => $previsto,
        'realizado' => $realizado,
        'percentual' => $percentual,
        'status' => $status
    ];
}

foreach ($categorias_despesa as $cat) {
    $previsto = 0;
    $realizado = 0;
    foreach ($orcamentos as $o) {
        if ($o['categoria'] == $cat['nome'] && $o['tipo'] == 'despesa') {
            $previsto = $o['valor_previsto'];
            break;
        }
    }
    $realizado = $realizados_map[$cat['nome']]['despesa'] ?? 0;
    
    $total_previsto_despesas += $previsto;
    $total_realizado_despesas += $realizado;
    
    $percentual = $previsto > 0 ? round(($realizado / $previsto) * 100, 1) : 0;
    $status = $percentual >= 100 ? 'atingida' : ($percentual >= 70 ? 'parcial' : 'baixa');
    
    $dados_execucao['despesas'][] = [
        'categoria' => $cat['nome'],
        'cor' => $cat['cor'],
        'previsto' => $previsto,
        'realizado' => $realizado,
        'percentual' => $percentual,
        'status' => $status
    ];
}

$saldo_previsto = $total_previsto_receitas - $total_previsto_despesas;
$saldo_realizado = $total_realizado_receitas - $total_realizado_despesas;
$saldo_variacao = $saldo_realizado - $saldo_previsto;

$anos_disponiveis = $conn->prepare("
    SELECT DISTINCT ano FROM escola_orcamento 
    WHERE escola_id = :escola_id 
    UNION SELECT YEAR(CURDATE()) as ano
    ORDER BY ano DESC
");
$anos_disponiveis->execute([':escola_id' => $escola_id]);
$anos_disponiveis = $anos_disponiveis->fetchAll(PDO::FETCH_COLUMN);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Execução Orçamentária | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .progress-bar-custom { height: 20px; border-radius: 10px; }
        .status-atingida { color: #28a745; }
        .status-parcial { color: #ffc107; }
        .status-baixa { color: #dc3545; }
        
        .receita-row { background-color: #e8f5e9; }
        .despesa-row { background-color: #ffebee; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .chart-container { position: relative; height: 400px; width: 100%; }
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
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                    <li class="nav-item"><a href="categorias.php" class="nav-link"><i class="fas fa-tags"></i> Categorias</a></li>
                    <li class="nav-item"><a href="executado.php" class="nav-link active"><i class="fas fa-chart-line"></i> Executado</a></li>
                    <li class="nav-item"><a href="desvios.php" class="nav-link"><i class="fas fa-exclamation-triangle"></i> Desvios</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-chart-line"></i> Execução Orçamentária
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-chart-pie"></i> Planejamento
                </a>
                <a href="desvios.php" class="btn btn-warning btn-sm">
                    <i class="fas fa-exclamation-triangle"></i> Desvios
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtro de Ano -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label>Ano de Referência</label>
                    <select name="ano" class="form-control" onchange="this.form.submit()">
                        <?php for ($i = date('Y') - 2; $i <= date('Y') + 2; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($total_realizado_receitas, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Receitas Realizadas</div>
                <small>Previsto: <?php echo number_format($total_previsto_receitas, 2, ',', '.'); ?> Kz</small>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($total_realizado_despesas, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Despesas Realizadas</div>
                <small>Previsto: <?php echo number_format($total_previsto_despesas, 2, ',', '.'); ?> Kz</small>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo $saldo_realizado >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($saldo_realizado, 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo Realizado</div>
                <small>Previsto: <?php echo number_format($saldo_previsto, 2, ',', '.'); ?> Kz</small>
            </div>
        </div>
        
        <!-- Gráfico de Execução -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-chart-bar"></i> Comparativo Previsto vs Realizado</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="execucaoChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Detalhamento das Receitas -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <i class="fas fa-arrow-up"></i> Execução de Receitas - <?php echo $ano; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Categoria</th>
                                <th>Previsto (Kz)</th>
                                <th>Realizado (Kz)</th>
                                <th>% Executado</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="receita-row">
                            <?php foreach ($dados_execucao['receitas'] as $item): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-circle" style="color: <?php echo $item['cor']; ?>"></i>
                                    <?php echo $item['categoria']; ?>
                                 </div>
                                </div>
                                <td><?php echo number_format($item['previsto'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($item['realizado'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo min($item['percentual'], 100); ?>%"></div>
                                        </div>
                                        <span><?php echo $item['percentual']; ?>%</span>
                                    </div>
                                 </div>
                                </div>
                                <td>
                                    <span class="status-<?php echo $item['status']; ?>">
                                        <?php echo $item['status'] == 'atingida' ? '✓ Atingida' : ($item['status'] == 'parcial' ? '⚠ Parcial' : '⚠ Baixa'); ?>
                                    </span>
                                 </div>
                                </div>
                             </div>
                            <?php endforeach; ?>
                            <tr class="table-secondary">
                                <td><strong>TOTAL</strong></div>
                                <td><strong><?php echo number_format($total_previsto_receitas, 2, ',', '.'); ?> Kz</strong></div>
                                <td><strong><?php echo number_format($total_realizado_receitas, 2, ',', '.'); ?> Kz</strong></div>
                                <td><strong><?php echo $total_previsto_receitas > 0 ? round(($total_realizado_receitas / $total_previsto_receitas) * 100, 1) : 0; ?>%</strong></div>
                                <td>-</div>
                             </div>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Detalhamento das Despesas -->
        <div class="card">
            <div class="card-header bg-danger text-white">
                <i class="fas fa-arrow-down"></i> Execução de Despesas - <?php echo $ano; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Categoria</th>
                                <th>Previsto (Kz)</th>
                                <th>Realizado (Kz)</th>
                                <th>% Executado</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="despesa-row">
                            <?php foreach ($dados_execucao['despesas'] as $item): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-circle" style="color: <?php echo $item['cor']; ?>"></i>
                                    <?php echo $item['categoria']; ?>
                                 </div>
                                </div>
                                <td><?php echo number_format($item['previsto'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($item['realizado'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                            <div class="progress-bar bg-danger" style="width: <?php echo min($item['percentual'], 100); ?>%"></div>
                                        </div>
                                        <span><?php echo $item['percentual']; ?>%</span>
                                    </div>
                                 </div>
                                </div>
                                <td>
                                    <span class="status-<?php echo $item['status']; ?>">
                                        <?php echo $item['status'] == 'atingida' ? '✓ Atingida' : ($item['status'] == 'parcial' ? '⚠ Parcial' : '⚠ Baixa'); ?>
                                    </span>
                                 </div>
                                </div>
                             </div>
                            <?php endforeach; ?>
                            <tr class="table-secondary">
                                <td><strong>TOTAL</strong></div>
                                <td><strong><?php echo number_format($total_previsto_despesas, 2, ',', '.'); ?> Kz</strong></div>
                                <td><strong><?php echo number_format($total_realizado_despesas, 2, ',', '.'); ?> Kz</strong></div>
                                <td><strong><?php echo $total_previsto_despesas > 0 ? round(($total_realizado_despesas / $total_previsto_despesas) * 100, 1) : 0; ?>%</strong></div>
                                <td>-</div>
                             </div>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre a Execução Orçamentária</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é a Execução Orçamentária?</h6>
                    <p>A execução orçamentária compara o valor planejado (previsto) com o valor realizado (efetivamente arrecadado/gasto).</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Como interpretar:</h6>
                    <ul>
                        <li><strong class="text-success">Verde (Atingida):</strong> Meta de receita atingida ou excedida.</li>
                        <li><strong class="text-warning">Amarelo (Parcial):</strong> Entre 70% e 99% da meta.</li>
                        <li><strong class="text-danger">Vermelho (Baixa):</strong> Abaixo de 70% da meta.</li>
                        <li><strong>Para despesas:</strong> Percentuais altos indicam que o orçamento está sendo utilizado conforme planejado.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Acompanhe mensalmente a execução orçamentária.</li>
                        <li>Identifique categorias com baixo desempenho para ações corretivas.</li>
                        <li>Despesas muito acima do previsto merecem atenção especial.</li>
                        <li>Use os dados históricos para planejar o próximo ano.</li>
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
        
        // Gráfico de Execução
        const ctx = document.getElementById('execucaoChart').getContext('2d');
        const categorias = <?php echo json_encode(array_merge(
            array_column($dados_execucao['receitas'], 'categoria'),
            array_column($dados_execucao['despesas'], 'categoria')
        )); ?>;
        const previsto = <?php echo json_encode(array_merge(
            array_column($dados_execucao['receitas'], 'previsto'),
            array_column($dados_execucao['despesas'], 'previsto')
        )); ?>;
        const realizado = <?php echo json_encode(array_merge(
            array_column($dados_execucao['receitas'], 'realizado'),
            array_column($dados_execucao['despesas'], 'realizado')
        )); ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: categorias,
                datasets: [
                    {
                        label: 'Previsto',
                        data: previsto,
                        backgroundColor: '#17a2b8',
                        borderRadius: 5
                    },
                    {
                        label: 'Realizado',
                        data: realizado,
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