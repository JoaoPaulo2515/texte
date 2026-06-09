<?php
// escola/financeiro/fluxo_caixa/index.php - Dashboard de Fluxo de Caixa
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// VERIFICAR E CRIAR TABELA
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'escola_fluxo_caixa'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_fluxo_caixa (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            data_movimento DATE NOT NULL,
            tipo ENUM('entrada', 'saida') NOT NULL,
            categoria VARCHAR(50),
            descricao TEXT NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            documento VARCHAR(50),
            conta_id INT,
            forma_pagamento_id INT,
            status ENUM('confirmado', 'pendente', 'cancelado') DEFAULT 'confirmado',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (conta_id) REFERENCES escola_contas_bancarias(id) ON DELETE SET NULL,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            INDEX idx_data (data_movimento),
            INDEX idx_tipo (tipo),
            INDEX idx_categoria (categoria)
        )
    ");
}

// ============================================
// BUSCAR DADOS PARA O DASHBOARD
// ============================================

$data_atual = date('Y-m-d');
$mes_atual = date('m');
$ano_atual = date('Y');
$data_inicio_mes = date('Y-m-01');
$data_fim_mes = date('Y-m-t');

// Saldo atual (últimos 30 dias)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute([':escola_id' => $escola_id]);
$saldos = $stmt->fetch(PDO::FETCH_ASSOC);
$saldo_atual = ($saldos['total_entradas'] ?? 0) - ($saldos['total_saidas'] ?? 0);

// Saldo do mês
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas_mes,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas_mes
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) = :mes
");
$stmt->execute([':escola_id' => $escola_id, ':ano' => $ano_atual, ':mes' => $mes_atual]);
$mes_atual_saldos = $stmt->fetch(PDO::FETCH_ASSOC);

// Entradas por categoria
$entradas_categorias = $conn->prepare("
    SELECT categoria, SUM(valor) as total
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND tipo = 'entrada' AND status = 'confirmado'
        AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) = :mes
    GROUP BY categoria
    ORDER BY total DESC
    LIMIT 5
");
$entradas_categorias->execute([':escola_id' => $escola_id, ':ano' => $ano_atual, ':mes' => $mes_atual]);
$entradas_categorias = $entradas_categorias->fetchAll(PDO::FETCH_ASSOC);

// Saídas por categoria
$saidas_categorias = $conn->prepare("
    SELECT categoria, SUM(valor) as total
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND tipo = 'saida' AND status = 'confirmado'
        AND YEAR(data_movimento) = :ano AND MONTH(data_movimento) = :mes
    GROUP BY categoria
    ORDER BY total DESC
    LIMIT 5
");
$saidas_categorias->execute([':escola_id' => $escola_id, ':ano' => $ano_atual, ':mes' => $mes_atual]);
$saidas_categorias = $saidas_categorias->fetchAll(PDO::FETCH_ASSOC);

// Últimos lançamentos
$ultimos_lancamentos = $conn->prepare("
    SELECT f.*, c.banco, c.numero_conta, fp.nome as forma_pagamento_nome
    FROM escola_fluxo_caixa f
    LEFT JOIN escola_contas_bancarias c ON c.id = f.conta_id
    LEFT JOIN escola_formas_pagamento fp ON fp.id = f.forma_pagamento_id
    WHERE f.escola_id = :escola_id
    ORDER BY f.data_movimento DESC
    LIMIT 10
");
$ultimos_lancamentos->execute([':escola_id' => $escola_id]);
$ultimos_lancamentos = $ultimos_lancamentos->fetchAll(PDO::FETCH_ASSOC);

// Resumo por dia (últimos 7 dias)
$resumo_diario = [];
for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $dia = date('d/m', strtotime($data));
    
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND data_movimento = :data AND status = 'confirmado'
    ");
    $stmt->execute([':escola_id' => $escola_id, ':data' => $data]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $resumo_diario[] = [
        'dia' => $dia,
        'entradas' => $row['entradas'] ?? 0,
        'saidas' => $row['saidas'] ?? 0,
        'saldo' => ($row['entradas'] ?? 0) - ($row['saidas'] ?? 0)
    ];
}

$categorias_entrada = [
    'mensalidade' => 'Mensalidades',
    'matricula' => 'Matrículas',
    'taxa' => 'Taxas',
    'doacao' => 'Doações',
    'outro_entrada' => 'Outras Entradas'
];

$categorias_saida = [
    'salario' => 'Salários',
    'material' => 'Material Escolar',
    'utilidade' => 'Utilidades',
    'manutencao' => 'Manutenção',
    'imposto' => 'Impostos',
    'outro_saida' => 'Outras Saídas'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fluxo de Caixa | Financeiro | SIGE Angola</title>
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
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        .stat-icon { font-size: 2em; margin-bottom: 10px; }
        
        .badge-entrada { background: #28a745; color: white; }
        .badge-saida { background: #dc3545; color: white; }
        
        .chart-container { position: relative; height: 300px; width: 100%; }
        
        .saldo-positivo { color: #28a745; }
        .saldo-negativo { color: #dc3545; }
        
        .table-responsive { overflow-x: auto; }
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
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
            <h2><i class="fas fa-chart-line"></i> Fluxo de Caixa</h2>
            <div>
                <a href="lancamentos.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-plus"></i> Novo Lançamento
                </a>
                <a href="consolidado.php" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-bar"></i> Consolidado
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-dollar-sign text-success"></i></div>
                <div class="stat-value text-success"><?php echo number_format($saldos['total_entradas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Entradas (últimos 30 dias)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-arrow-down text-danger"></i></div>
                <div class="stat-value text-danger"><?php echo number_format($saldos['total_saidas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Saídas (últimos 30 dias)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-value <?php echo $saldo_atual >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>">
                    <?php echo number_format($saldo_atual, 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo Atual</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-value">
                    <?php echo number_format(($mes_atual_saldos['entradas_mes'] ?? 0) - ($mes_atual_saldos['saidas_mes'] ?? 0), 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo do Mês</div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Movimentação dos Últimos 7 Dias</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="movimentacaoChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição por Categoria</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="categoriaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Últimos Lançamentos -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clock"></i> Últimos Lançamentos</span>
                <a href="lancamentos.php" class="btn btn-sm btn-primary">Ver Todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Categoria</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Forma</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimos_lancamentos as $lanc): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($lanc['data_movimento'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $lanc['tipo']; ?>">
                                        <?php echo ucfirst($lanc['tipo']); ?>
                                    </span>
                                 </div>
                                <td><?php echo $categorias_entrada[$lanc['categoria']] ?? $categorias_saida[$lanc['categoria']] ?? $lanc['categoria']; ?></div>
                                <td><?php echo htmlspecialchars($lanc['descricao']); ?></div>
                                <td>
                                    <span class="<?php echo $lanc['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $lanc['tipo'] == 'entrada' ? '+' : '-'; ?> 
                                        <?php echo number_format($lanc['valor'], 2, ',', '.'); ?> Kz
                                    </span>
                                 </div>
                                <td><?php echo $lanc['forma_pagamento_nome'] ?? '-'; ?></div>
                                <td><span class="badge bg-secondary"><?php echo ucfirst($lanc['status']); ?></span></div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
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
        
        // Gráfico de Movimentação
        const ctx1 = document.getElementById('movimentacaoChart').getContext('2d');
        const dias = <?php echo json_encode(array_column($resumo_diario, 'dia')); ?>;
        const entradas = <?php echo json_encode(array_column($resumo_diario, 'entradas')); ?>;
        const saidas = <?php echo json_encode(array_column($resumo_diario, 'saidas')); ?>;
        
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: dias,
                datasets: [
                    {
                        label: 'Entradas',
                        data: entradas,
                        backgroundColor: '#28a745',
                        borderRadius: 5
                    },
                    {
                        label: 'Saídas',
                        data: saidas,
                        backgroundColor: '#dc3545',
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
        
        // Gráfico de Categorias
        const ctx2 = document.getElementById('categoriaChart').getContext('2d');
        
        <?php
        $categorias_labels = [];
        $categorias_valores = [];
        foreach ($entradas_categorias as $e) {
            $categorias_labels[] = $categorias_entrada[$e['categoria']] ?? $e['categoria'];
            $categorias_valores[] = $e['total'];
        }
        foreach ($saidas_categorias as $s) {
            $categorias_labels[] = $categorias_saida[$s['categoria']] ?? $s['categoria'];
            $categorias_valores[] = -$s['total'];
        }
        ?>
        
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($categorias_labels); ?>,
                datasets: [{
                    label: 'Valor (Kz)',
                    data: <?php echo json_encode($categorias_valores); ?>,
                    backgroundColor: function(context) {
                        return context.raw >= 0 ? '#28a745' : '#dc3545';
                    },
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Valor: ' + Math.abs(context.raw).toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            callback: function(value) {
                                return Math.abs(value).toLocaleString('pt-AO') + ' Kz';
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