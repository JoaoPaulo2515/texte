<?php
// escola/financeiro/orcamento/desvios.php - Análise de Desvios
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

// Buscar realizados
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
$categorias = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND status = 'ativo'
    ORDER BY tipo, ordem, nome
");
$categorias->execute([':escola_id' => $escola_id]);
$categorias = $categorias->fetchAll(PDO::FETCH_ASSOC);

// Calcular desvios
$desvios = [];
$total_previsto = 0;
$total_realizado = 0;
$total_desvio = 0;

foreach ($categorias as $cat) {
    $previsto = 0;
    foreach ($orcamentos as $o) {
        if ($o['categoria'] == $cat['nome'] && $o['tipo'] == $cat['tipo']) {
            $previsto = $o['valor_previsto'];
            break;
        }
    }
    
    $realizado = 0;
    if ($cat['tipo'] == 'receita') {
        $realizado = $realizados_map[$cat['nome']]['receita'] ?? 0;
        $total_previsto += $previsto;
        $total_realizado += $realizado;
    } else {
        $realizado = $realizados_map[$cat['nome']]['despesa'] ?? 0;
        $total_previsto += $previsto;
        $total_realizado += $realizado;
    }
    
    $desvio = $realizado - $previsto;
    $percentual_desvio = $previsto > 0 ? round(($desvio / $previsto) * 100, 1) : ($realizado > 0 ? 100 : 0);
    
    $total_desvio += $desvio;
    
    $desvios[] = [
        'categoria' => $cat['nome'],
        'tipo' => $cat['tipo'],
        'cor' => $cat['cor'],
        'previsto' => $previsto,
        'realizado' => $realizado,
        'desvio' => $desvio,
        'percentual' => $percentual_desvio,
        'status' => $desvio >= 0 ? ($cat['tipo'] == 'receita' ? 'positivo' : 'negativo') : ($cat['tipo'] == 'receita' ? 'negativo' : 'positivo')
    ];
}

// Ordenar por maior desvio absoluto
usort($desvios, function($a, $b) {
    return abs($b['desvio']) - abs($a['desvio']);
});

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
    <title>Análise de Desvios | SIGE Angola</title>
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
        
        .desvio-positivo { color: #28a745; font-weight: bold; }
        .desvio-negativo { color: #dc3545; font-weight: bold; }
        .desvio-neutral { color: #6c757d; }
        
        .receita-badge { background: #28a745; color: white; padding: 3px 8px; border-radius: 5px; font-size: 0.7em; }
        .despesa-badge { background: #dc3545; color: white; padding: 3px 8px; border-radius: 5px; font-size: 0.7em; }
        
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
                    <li class="nav-item"><a href="executado.php" class="nav-link"><i class="fas fa-chart-line"></i> Executado</a></li>
                    <li class="nav-item"><a href="desvios.php" class="nav-link active"><i class="fas fa-exclamation-triangle"></i> Desvios</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-exclamation-triangle"></i> Análise de Desvios Orçamentários
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-chart-pie"></i> Planejamento
                </a>
                <a href="executado.php" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-line"></i> Executado
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtro de Ano -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-12">
                    <label>Ano de Referência</label>
                    <select name="ano" class="form-control" onchange="this.form.submit()">
                        <?php for ($i = date('Y') - 2; $i <= date('Y') + 2; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_previsto, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Previsto</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_realizado, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Realizado</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo $total_desvio >= 0 ? 'desvio-positivo' : 'desvio-negativo'; ?>">
                    <?php echo ($total_desvio >= 0 ? '+' : '') . number_format($total_desvio, 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Desvio Total</div>
            </div>
        </div>
        
        <!-- Gráfico de Desvios -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-chart-bar"></i> Desvios por Categoria</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="desviosChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Desvios -->
        <div class="card">
            <div class="card-header"><i class="fas fa-table"></i> Detalhamento dos Desvios - <?php echo $ano; ?></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="tabelaDesvios">
                        <thead class="table-light">
                            <tr>
                                <th>Categoria</th>
                                <th>Tipo</th>
                                <th>Previsto (Kz)</th>
                                <th>Realizado (Kz)</th>
                                <th>Desvio (Kz)</th>
                                <th>% Desvio</th>
                                <th>Análise</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($desvios as $d): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-circle" style="color: <?php echo $d['cor']; ?>"></i>
                                    <?php echo $d['categoria']; ?>
                                 </div>
                                </div>
                                <td>
                                    <span class="<?php echo $d['tipo'] == 'receita' ? 'receita-badge' : 'despesa-badge'; ?>">
                                        <?php echo $d['tipo'] == 'receita' ? 'Receita' : 'Despesa'; ?>
                                    </span>
                                 </div>
                                </div>
                                <td><?php echo number_format($d['previsto'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($d['realizado'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <span class="<?php echo $d['desvio'] >= 0 ? 'desvio-positivo' : 'desvio-negativo'; ?>">
                                        <?php echo ($d['desvio'] >= 0 ? '+' : '') . number_format($d['desvio'], 2, ',', '.'); ?> Kz
                                    </span>
                                 </div>
                                </div>
                                <td>
                                    <span class="<?php echo $d['desvio'] >= 0 ? 'desvio-positivo' : 'desvio-negativo'; ?>">
                                        <?php echo ($d['percentual'] >= 0 ? '+' : '') . $d['percentual']; ?>%
                                    </span>
                                 </div>
                                </div>
                                <td>
                                    <?php if ($d['tipo'] == 'receita'): ?>
                                        <?php if ($d['desvio'] > 0): ?>
                                            <span class="text-success">✓ Receita acima do esperado</span>
                                        <?php elseif ($d['desvio'] < 0): ?>
                                            <span class="text-danger">⚠ Receita abaixo do esperado</span>
                                        <?php else: ?>
                                            <span class="text-muted">✓ Meta atingida</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($d['desvio'] < 0): ?>
                                            <span class="text-success">✓ Despesa abaixo do esperado</span>
                                        <?php elseif ($d['desvio'] > 0): ?>
                                            <span class="text-danger">⚠ Despesa acima do esperado</span>
                                        <?php else: ?>
                                            <span class="text-muted">✓ Meta atingida</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                 </div>
                                </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="2">TOTAL</th>
                                <th><?php echo number_format($total_previsto, 2, ',', '.'); ?> Kz</th>
                                <th><?php echo number_format($total_realizado, 2, ',', '.'); ?> Kz</th>
                                <th class="<?php echo $total_desvio >= 0 ? 'desvio-positivo' : 'desvio-negativo'; ?>">
                                    <?php echo ($total_desvio >= 0 ? '+' : '') . number_format($total_desvio, 2, ',', '.'); ?> Kz
                                </th>
                                <th><?php echo $total_previsto > 0 ? round(($total_desvio / $total_previsto) * 100, 1) : 0; ?>%</th>
                                <th>-</th>
                             </div>
                        </tfoot>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Recomendações -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <i class="fas fa-lightbulb"></i> Recomendações
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-up text-success"></i> Pontos Positivos</h6>
                        <ul>
                            <?php 
                            $positivos = array_filter($desvios, function($d) {
                                return ($d['tipo'] == 'receita' && $d['desvio'] > 0) || ($d['tipo'] == 'despesa' && $d['desvio'] < 0);
                            });
                            foreach (array_slice($positivos, 0, 3) as $p):
                            ?>
                            <li><strong><?php echo $p['categoria']; ?>:</strong> 
                                <?php echo $p['tipo'] == 'receita' ? 'Receita excedeu em' : 'Economia de'; ?> 
                                <?php echo number_format(abs($p['desvio']), 2, ',', '.'); ?> Kz 
                                (<?php echo abs($p['percentual']); ?>%)
                            </li>
                            <?php endforeach; ?>
                            <?php if (empty($positivos)): ?>
                            <li class="text-muted">Nenhum ponto positivo identificado.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-exclamation-triangle text-danger"></i> Pontos de Atenção</h6>
                        <ul>
                            <?php 
                            $negativos = array_filter($desvios, function($d) {
                                return ($d['tipo'] == 'receita' && $d['desvio'] < 0) || ($d['tipo'] == 'despesa' && $d['desvio'] > 0);
                            });
                            foreach (array_slice($negativos, 0, 3) as $n):
                            ?>
                            <li><strong><?php echo $n['categoria']; ?>:</strong> 
                                <?php echo $n['tipo'] == 'receita' ? 'Receita abaixo em' : 'Gasto acima em'; ?> 
                                <?php echo number_format(abs($n['desvio']), 2, ',', '.'); ?> Kz 
                                (<?php echo abs($n['percentual']); ?>%)
                            </li>
                            <?php endforeach; ?>
                            <?php if (empty($negativos)): ?>
                            <li class="text-muted">Nenhum ponto de atenção identificado.</li>
                            <?php endif; ?>
                        </ul>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre a Análise de Desvios</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é a Análise de Desvios?</h6>
                    <p>A análise de desvios compara o valor realizado com o valor previsto, identificando diferenças significativas que merecem atenção.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Como interpretar:</h6>
                    <ul>
                        <li><strong class="text-success">Desvio Positivo (Verde):</strong> 
                            <br>- Receitas: acima do esperado (bom)
                            <br>- Despesas: abaixo do esperado (bom)
                        </li>
                        <li><strong class="text-danger">Desvio Negativo (Vermelho):</strong>
                            <br>- Receitas: abaixo do esperado (atenção)
                            <br>- Despesas: acima do esperado (atenção)
                        </li>
                        <li><strong>Percentual de desvio:</strong> Mostra a magnitude relativa do desvio.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Ações Recomendadas:</h6>
                    <ul>
                        <li>Investigue causas de desvios significativos (>20%).</li>
                        <li>Para receitas abaixo do esperado, reforce ações de captação.</li>
                        <li>Para despesas acima do esperado, revise contratos e processos.</li>
                        <li>Documente justificativas para desvios relevantes.</li>
                        <li>Use os aprendizados para melhorar o próximo planejamento.</li>
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
        
        // Gráfico de Desvios
        const ctx = document.getElementById('desviosChart').getContext('2d');
        const categoriasDesvios = <?php echo json_encode(array_column($desvios, 'categoria')); ?>;
        const desviosValues = <?php echo json_encode(array_column($desvios, 'desvio')); ?>;
        const coresDesvios = desviosValues.map(v => v >= 0 ? '#28a745' : '#dc3545');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: categoriasDesvios,
                datasets: [{
                    label: 'Desvio (Kz)',
                    data: desviosValues,
                    backgroundColor: coresDesvios,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                return 'Desvio: ' + (value >= 0 ? '+' : '') + value.toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return (value >= 0 ? '+' : '') + value.toLocaleString('pt-AO') + ' Kz';
                            }
                        }
                    }
                }
            }
        });
        
        $('#tabelaDesvios').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[4, 'desc']]
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>