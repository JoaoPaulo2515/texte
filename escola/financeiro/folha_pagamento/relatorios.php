<?php
// escola/financeiro/folha_pagamento/relatorios.php - Relatórios da Folha de Pagamento
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar meses para filtro
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$ano_atual = date('Y');
$mes_atual = date('m');
$ano_selecionado = $_GET['ano'] ?? $ano_atual;
$mes_selecionado = $_GET['mes'] ?? $mes_atual;

// Buscar resumo da folha
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT ff.funcionario_id) as total_funcionarios,
        SUM(ff.salario_base) as total_salarios_base,
        SUM(ff.subsidio_transporte) as total_subsidio_transporte,
        SUM(ff.subsidio_alimentacao) as total_subsidio_alimentacao,
        SUM(ff.outros_vencimentos) as total_outros_vencimentos,
        SUM(ff.desconto_inss) as total_desconto_inss,
        SUM(ff.desconto_irrf) as total_desconto_irrf,
        SUM(ff.outros_descontos) as total_outros_descontos,
        SUM(ff.salario_base + ff.subsidio_transporte + ff.subsidio_alimentacao + ff.outros_vencimentos) as total_vencimentos,
        SUM(ff.desconto_inss + ff.desconto_irrf + ff.outros_descontos) as total_descontos,
        SUM((ff.salario_base + ff.subsidio_transporte + ff.subsidio_alimentacao + ff.outros_vencimentos) - (ff.desconto_inss + ff.desconto_irrf + ff.outros_descontos)) as total_liquido
    FROM folha_funcionarios ff
    WHERE ff.escola_id = ?
");
$stmt->execute([$escola_id]);
$resumo = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar lista de funcionários na folha - CORRIGIDO
$stmt = $conn->prepare("
    SELECT 
        ff.*,
        f.numero_processo,
        f.nome,
        f.cargo,
        f.tipo_funcionario,
        f.bi,
        f.data_admissao,
        f.tipo_contrato,
        f.banco_nome,
        f.numero_conta,
        f.iban,
        f.status
    FROM folha_funcionarios ff
    JOIN funcionarios f ON ff.funcionario_id = f.id
    WHERE ff.escola_id = ?
    ORDER BY f.nome
");
$stmt->execute([$escola_id]);
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar histórico de processamento
$stmt = $conn->prepare("
    SELECT 
        fp.*,
        COUNT(fp.id) as total_processados,
        SUM(fp.total_liquido) as valor_total
    FROM folha_processamento fp
    WHERE fp.escola_id = ?
    GROUP BY fp.ano, fp.mes
    ORDER BY fp.ano DESC, fp.mes DESC
    LIMIT 12
");
$stmt->execute([$escola_id]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - Folha de Pagamento | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
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
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #006B3E;
        }
        .stat-label {
            font-size: 0.85em;
            color: #666;
        }
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
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
                    <li class="nav-item"><a href="relatorios.php" class="nav-link active"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Relatórios da Folha de Pagamento</h2>
            <div>
                <select id="anoFiltro" class="form-select d-inline-block w-auto">
                    <?php for ($i = $ano_atual - 2; $i <= $ano_atual + 1; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == $ano_selecionado ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <select id="mesFiltro" class="form-select d-inline-block w-auto ms-2">
                    <?php foreach ($meses as $num => $nome): ?>
                        <option value="<?php echo $num; ?>" <?php echo $num == $mes_selecionado ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary ms-2" onclick="filtrar()"><i class="fas fa-search"></i> Filtrar</button>
                <button class="btn btn-success ms-2" onclick="exportarExcel()"><i class="fas fa-file-excel"></i> Exportar</button>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($resumo['total_funcionarios'] ?? 0); ?></div>
                    <div class="stat-label">Total de Funcionários</div>
                    <i class="fas fa-users text-muted mt-2"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($resumo['total_vencimentos'] ?? 0, 2); ?> Kz</div>
                    <div class="stat-label">Total de Vencimentos</div>
                    <i class="fas fa-arrow-up text-success mt-2"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($resumo['total_descontos'] ?? 0, 2); ?> Kz</div>
                    <div class="stat-label">Total de Descontos</div>
                    <i class="fas fa-arrow-down text-danger mt-2"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($resumo['total_liquido'] ?? 0, 2); ?> Kz</div>
                    <div class="stat-label">Total Líquido a Pagar</div>
                    <i class="fas fa-money-bill text-primary mt-2"></i>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Composição dos Vencimentos
                    </div>
                    <div class="card-body">
                        <canvas id="vencimentosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Composição dos Descontos
                    </div>
                    <div class="card-body">
                        <canvas id="descontosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Histórico Mensal -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Histórico Mensal
            </div>
            <div class="card-body">
                <canvas id="historicoChart" height="100"></canvas>
            </div>
        </div>
        
        <!-- Lista de Funcionários -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Detalhamento por Funcionário
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaFuncionarios">
                        <thead>
                            <tr>
                                <th>Nº Processo</th>
                                <th>Nome</th>
                                <th>Cargo</th>
                                <th>Salário Base</th>
                                <th>Subsídios</th>
                                <th>Descontos</th>
                                <th>Líquido</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($funcionarios as $f): ?>
                            <?php
                            $total_subsidios = ($f['subsidio_transporte'] ?? 0) + ($f['subsidio_alimentacao'] ?? 0) + ($f['outros_vencimentos'] ?? 0);
                            $total_descontos = ($f['desconto_inss'] ?? 0) + ($f['desconto_irrf'] ?? 0) + ($f['outros_descontos'] ?? 0);
                            $total_liquido = ($f['salario_base'] ?? 0) + $total_subsidios - $total_descontos;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($f['numero_processo']); ?></td>
                                <td><?php echo htmlspecialchars($f['nome']); ?></td>
                                <td><?php echo htmlspecialchars($f['cargo']); ?></td>
                                <td><?php echo number_format($f['salario_base'] ?? 0, 2); ?> Kz</td>
                                <td><?php echo number_format($total_subsidios, 2); ?> Kz</td>
                                <td><?php echo number_format($total_descontos, 2); ?> Kz</td>
                                <td><strong><?php echo number_format($total_liquido, 2); ?> Kz</strong></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="verHolerite(<?php echo $f['funcionario_id']; ?>)">
                                        <i class="fas fa-receipt"></i>
                                    </button>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Gráfico de Vencimentos
        const vencimentosCtx = document.getElementById('vencimentosChart').getContext('2d');
        new Chart(vencimentosCtx, {
            type: 'doughnut',
            data: {
                labels: ['Salário Base', 'Subsídio Transporte', 'Subsídio Alimentação', 'Outros Vencimentos'],
                datasets: [{
                    data: [
                        <?php echo $resumo['total_salarios_base'] ?? 0; ?>,
                        <?php echo $resumo['total_subsidio_transporte'] ?? 0; ?>,
                        <?php echo $resumo['total_subsidio_alimentacao'] ?? 0; ?>,
                        <?php echo $resumo['total_outros_vencimentos'] ?? 0; ?>
                    ],
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        // Gráfico de Descontos
        const descontosCtx = document.getElementById('descontosChart').getContext('2d');
        new Chart(descontosCtx, {
            type: 'doughnut',
            data: {
                labels: ['INSS', 'IRRF', 'Outros Descontos'],
                datasets: [{
                    data: [
                        <?php echo $resumo['total_desconto_inss'] ?? 0; ?>,
                        <?php echo $resumo['total_desconto_irrf'] ?? 0; ?>,
                        <?php echo $resumo['total_outros_descontos'] ?? 0; ?>
                    ],
                    backgroundColor: ['#dc3545', '#ffc107', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        // Gráfico Histórico
        const historicoCtx = document.getElementById('historicoChart').getContext('2d');
        const mesesLabels = <?php 
            $labels = [];
            $valores = [];
            foreach ($historico as $h) {
                $labels[] = $meses[$h['mes']] . '/' . $h['ano'];
                $valores[] = $h['valor_total'];
            }
            echo json_encode(array_reverse($labels));
        ?>;
        const historicoValores = <?php echo json_encode(array_reverse($valores)); ?>;
        
        new Chart(historicoCtx, {
            type: 'line',
            data: {
                labels: mesesLabels,
                datasets: [{
                    label: 'Total Pago (Kz)',
                    data: historicoValores,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return v.toLocaleString() + ' Kz'; } } } }
            }
        });
        
        function filtrar() {
            var ano = $('#anoFiltro').val();
            var mes = $('#mesFiltro').val();
            window.location.href = 'relatorios.php?ano=' + ano + '&mes=' + mes;
        }
        
        function exportarExcel() {
            window.location.href = 'exportar_relatorio.php?ano=' + $('#anoFiltro').val() + '&mes=' + $('#mesFiltro').val();
        }
        
        function verHolerite(funcionarioId) {
            window.open('holerite.php?id=' + funcionarioId, '_blank');
        }
    </script>
</body>
</html>