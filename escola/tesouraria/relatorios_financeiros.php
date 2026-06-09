<?php
// escola/tesouraria/relatorios_financeiros.php - Relatórios Financeiros

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
$relatorio_tipo = isset($_GET['relatorio']) ? $_GET['relatorio'] : 'mensal';
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';

// ============================================
// CONSULTAS POR TIPO DE RELATÓRIO
// ============================================

// 1. RELATÓRIO MENSAL
$relatorio_mensal = [];
if ($relatorio_tipo == 'mensal') {
    for ($m = 1; $m <= 12; $m++) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND YEAR(data_movimento) = :ano 
                AND MONTH(data_movimento) = :mes";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano_filtro, ':mes' => $m]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $relatorio_mensal[$m] = [
            'mes' => date('F', mktime(0,0,0,$m,1)),
            'entradas' => $result['entradas'] ?? 0,
            'saidas' => $result['saidas'] ?? 0,
            'saldo' => ($result['entradas'] ?? 0) - ($result['saidas'] ?? 0)
        ];
    }
}

// 2. RELATÓRIO POR CATEGORIA
$relatorio_categorias = [];
if ($relatorio_tipo == 'categorias') {
    $sql = "SELECT 
                tipo,
                categoria,
                COUNT(*) as quantidade,
                COALESCE(SUM(valor), 0) as total
            FROM caixa 
            WHERE escola_id = :escola_id 
            AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
            AND status = 'ativo'
            GROUP BY tipo, categoria
            ORDER BY total DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
    $relatorio_categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. RELATÓRIO POR ALUNO
$relatorio_alunos = [];
if ($relatorio_tipo == 'alunos') {
    $sql = "SELECT 
                a.id,
                a.nome as aluno_nome,
                a.matricula,
                COUNT(p.id) as quantidade,
                COALESCE(SUM(p.valor), 0) as total_pago
            FROM pagamentos p
            JOIN estudantes a ON a.id = p.assinatura_id
            WHERE p.escola_id = :escola_id 
            AND p.status = 'confirmado'
            AND DATE(p.data_pagamento) BETWEEN :data_inicio AND :data_fim
            GROUP BY a.id
            ORDER BY total_pago DESC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
    $relatorio_alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. RELATÓRIO DIÁRIO
$relatorio_diario = [];
if ($relatorio_tipo == 'diario') {
    $sql = "SELECT 
                DATE(data_movimento) as data,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas,
                COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
            FROM caixa 
            WHERE escola_id = :escola_id 
            AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
            AND status = 'ativo'
            GROUP BY DATE(data_movimento)
            ORDER BY data ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
    $relatorio_diario = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 5. RESUMO DO PERÍODO
$sql_resumo = "SELECT 
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                    COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                    COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
                AND status = 'ativo'";
$stmt_resumo = $conn->prepare($sql_resumo);
$stmt_resumo->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
$resumo = $stmt_resumo->fetch(PDO::FETCH_ASSOC);
$resumo['saldo'] = ($resumo['total_entradas'] ?? 0) - ($resumo['total_saidas'] ?? 0);

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
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Financeiros | Tesouraria | SIGE Angola</title>
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
        
        .relatorio-nav { margin-bottom: 20px; }
        .relatorio-nav .btn { margin-right: 10px; margin-bottom: 10px; }
        
        .print-only { display: none; }
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
        
        .chart-container { position: relative; height: 300px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-line"></i> Relatórios Financeiros</h2>
                <p class="text-muted">Análise completa das finanças da escola</p>
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
        
        <!-- Navegação por Tipo de Relatório -->
        <div class="relatorio-nav no-print">
            <a href="?relatorio=mensal" class="btn <?php echo $relatorio_tipo == 'mensal' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fas fa-calendar-alt"></i> Relatório Mensal
            </a>
            <a href="?relatorio=diario" class="btn <?php echo $relatorio_tipo == 'diario' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fas fa-calendar-day"></i> Relatório Diário
            </a>
            <a href="?relatorio=categorias" class="btn <?php echo $relatorio_tipo == 'categorias' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fas fa-tags"></i> Por Categoria
            </a>
            <a href="?relatorio=alunos" class="btn <?php echo $relatorio_tipo == 'alunos' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fas fa-users"></i> Por Aluno
            </a>
        </div>
        
        <!-- Filtros (para relatórios que usam período) -->
        <?php if (in_array($relatorio_tipo, ['diario', 'categorias', 'alunos'])): ?>
        <div class="card no-print mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Período de Análise</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="relatorio" value="<?php echo $relatorio_tipo; ?>">
                    <div class="col-md-4">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtro para relatório mensal -->
        <?php if ($relatorio_tipo == 'mensal'): ?>
        <div class="card no-print mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Ano de Análise</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="relatorio" value="mensal">
                    <div class="col-md-4">
                        <label class="form-label">Ano</label>
                        <select name="ano" class="form-select">
                            <?php for($a = date('Y')-2; $a <= date('Y'); $a++): ?>
                            <option value="<?php echo $a; ?>" <?php echo $ano_filtro == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resumo do Período -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($resumo['total_entradas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small><?php echo $resumo['qtd_entradas'] ?? 0; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($resumo['total_saidas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small><?php echo $resumo['qtd_saidas'] ?? 0; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $resumo['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($resumo['saldo']); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Período</div>
                    <small><?php echo $resumo['saldo'] >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format(($resumo['total_entradas'] + $resumo['total_saidas']), 2, ',', '.'); ?> Kz</div>
                    <div class="stat-label"><i class="fas fa-chart-line"></i> Movimento Total</div>
                    <small>Entradas + Saídas</small>
                </div>
            </div>
        </div>
        
        <!-- ============================================ -->
        <!-- RELATÓRIO MENSAL -->
        <!-- ============================================ -->
        <?php if ($relatorio_tipo == 'mensal'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Relatório Mensal - <?php echo $ano_filtro; ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoMensal" height="300"></canvas>
                </div>
                
                <div class="table-responsive mt-4">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Mês</th><th class="text-end">Entradas</th><th class="text-end">Saídas</th><th class="text-end">Saldo</th><th class="text-end">% do Total</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $max_entradas = max(array_column($relatorio_mensal, 'entradas')) ?: 1;
                            foreach ($relatorio_mensal as $mes): 
                                $percentual = ($mes['entradas'] / $max_entradas) * 100;
                            ?>
                            <tr>
                                <td><strong><?php echo $mes['mes']; ?></strong></td>
                                <td class="text-end text-success"><?php echo formatarMoeda($mes['entradas']); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($mes['saidas']); ?></td>
                                <td class="text-end <?php echo $mes['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo formatarMoeda($mes['saldo']); ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($percentual, 1); ?>%</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td>TOTAL:</td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_mensal, 'entradas'))); ?></td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_mensal, 'saidas'))); ?></td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_mensal, 'entradas')) - array_sum(array_column($relatorio_mensal, 'saidas'))); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        // Gráfico Mensal
        const mesesLabels = <?php echo json_encode(array_column($relatorio_mensal, 'mes')); ?>;
        const entradasData = <?php echo json_encode(array_column($relatorio_mensal, 'entradas')); ?>;
        const saidasData = <?php echo json_encode(array_column($relatorio_mensal, 'saidas')); ?>;
        
        new Chart(document.getElementById('graficoMensal'), {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: [
                    { label: 'Entradas', data: entradasData, backgroundColor: 'rgba(40, 167, 69, 0.7)', borderColor: '#28a745', borderWidth: 1 },
                    { label: 'Saídas', data: saidasData, backgroundColor: 'rgba(220, 53, 69, 0.7)', borderColor: '#dc3545', borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
            }
        });
        </script>
        
        <!-- ============================================ -->
        <!-- RELATÓRIO DIÁRIO -->
        <!-- ============================================ -->
        <?php elseif ($relatorio_tipo == 'diario'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-day"></i> Relatório Diário</h5>
                <small><?php echo date('d/m/Y', strtotime($data_inicio)); ?> até <?php echo date('d/m/Y', strtotime($data_fim)); ?></small>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoDiario" height="300"></canvas>
                </div>
                
                <div class="table-responsive mt-4">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Data</th><th class="text-end">Entradas</th><th class="text-end">Saídas</th><th class="text-end">Saldo</th><th>Qtd Entradas</th><th>Qtd Saídas</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio_diario as $dia): 
                                $saldo = $dia['entradas'] - $dia['saidas'];
                            ?>
                            <tr>
                                <td><strong><?php echo date('d/m/Y', strtotime($dia['data'])); ?></strong></td>
                                <td class="text-end text-success"><?php echo formatarMoeda($dia['entradas']); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($dia['saidas']); ?></td>
                                <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo formatarMoeda($saldo); ?>
                                </td>
                                <td class="text-center"><?php echo $dia['qtd_entradas']; ?></td>
                                <td class="text-center"><?php echo $dia['qtd_saidas']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($relatorio_diario)): ?>
                            <tr><td colspan="6" class="text-center">Nenhum dado encontrado</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td>TOTAL:</td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_diario, 'entradas'))); ?></td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_diario, 'saidas'))); ?></td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($relatorio_diario, 'entradas')) - array_sum(array_column($relatorio_diario, 'saidas'))); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        // Gráfico Diário
        const datasLabels = <?php echo json_encode(array_column($relatorio_diario, 'data')); ?>;
        const entradasDiarias = <?php echo json_encode(array_column($relatorio_diario, 'entradas')); ?>;
        const saidasDiarias = <?php echo json_encode(array_column($relatorio_diario, 'saidas')); ?>;
        
        new Chart(document.getElementById('graficoDiario'), {
            type: 'line',
            data: {
                labels: datasLabels,
                datasets: [
                    { label: 'Entradas', data: entradasDiarias, borderColor: '#28a745', backgroundColor: 'rgba(40, 167, 69, 0.1)', fill: true, tension: 0.4 },
                    { label: 'Saídas', data: saidasDiarias, borderColor: '#dc3545', backgroundColor: 'rgba(220, 53, 69, 0.1)', fill: true, tension: 0.4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
            }
        });
        </script>
        
        <!-- ============================================ -->
        <!-- RELATÓRIO POR CATEGORIA -->
        <!-- ============================================ -->
        <?php elseif ($relatorio_tipo == 'categorias'): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Entradas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="graficoEntradasCategoria"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Saídas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="graficoSaidasCategoria"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Detalhamento por Categoria</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Tipo</th><th>Categoria</th><th>Quantidade</th><th class="text-end">Total</th><th>% do Total</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_geral = $resumo['total_entradas'] + $resumo['total_saidas'];
                            foreach ($relatorio_categorias as $cat): 
                                $percentual = $total_geral > 0 ? ($cat['total'] / $total_geral) * 100 : 0;
                            ?>
                            <tr>
                                <td><?php echo getTipoBadge($cat['tipo']); ?></td>
                                <td><strong><?php echo htmlspecialchars($cat['categoria']); ?></strong></td>
                                <td><?php echo $cat['quantidade']; ?>x</small></td>
                                <td class="text-end <?php echo $cat['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo formatarMoeda($cat['total']); ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar <?php echo $cat['tipo'] == 'entrada' ? 'bg-success' : 'bg-danger'; ?>" style="width: <?php echo $percentual; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($percentual, 1); ?>%</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        // Gráfico Entradas por Categoria
        const entradasCategorias = <?php 
            $entradas = array_filter($relatorio_categorias, function($item) { return $item['tipo'] == 'entrada'; });
            echo json_encode(array_column($entradas, 'categoria')); 
        ?>;
        const entradasValores = <?php echo json_encode(array_column($entradas, 'total')); ?>;
        
        new Chart(document.getElementById('graficoEntradasCategoria'), {
            type: 'pie',
            data: { labels: entradasCategorias, datasets: [{ data: entradasValores, backgroundColor: ['#28a745', '#20c997', '#34ce57', '#48d96b', '#5ce37f'] }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } } }
        });
        
        // Gráfico Saídas por Categoria
        const saidasCategorias = <?php 
            $saidas = array_filter($relatorio_categorias, function($item) { return $item['tipo'] == 'saida'; });
            echo json_encode(array_column($saidas, 'categoria')); 
        ?>;
        const saidasValores = <?php echo json_encode(array_column($saidas, 'total')); ?>;
        
        new Chart(document.getElementById('graficoSaidasCategoria'), {
            type: 'pie',
            data: { labels: saidasCategorias, datasets: [{ data: saidasValores, backgroundColor: ['#dc3545', '#e83e8c', '#f06292', '#f48fb1', '#f8bbd0'] }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } } }
        });
        </script>
        
        <!-- ============================================ -->
        <!-- RELATÓRIO POR ALUNO -->
        <!-- ============================================ -->
        <?php elseif ($relatorio_tipo == 'alunos'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-users"></i> Pagamentos por Aluno</h5>
                <small><?php echo date('d/m/Y', strtotime($data_inicio)); ?> até <?php echo date('d/m/Y', strtotime($data_fim)); ?></small>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoAlunos" height="300"></canvas>
                </div>
                
                <div class="table-responsive mt-4">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Aluno</th><th>Matrícula</th><th>Quantidade</th><th class="text-end">Total Pago</th><th>% do Total</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_pagos = array_sum(array_column($relatorio_alunos, 'total_pago'));
                            foreach ($relatorio_alunos as $aluno): 
                                $percentual = $total_pagos > 0 ? ($aluno['total_pago'] / $total_pagos) * 100 : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($aluno['aluno_nome']); ?></strong></td>
                                <td><?php echo $aluno['matricula']; ?></td>
                                <td><?php echo $aluno['quantidade']; ?>x</small></td>
                                <td class="text-end text-success fw-bold"><?php echo formatarMoeda($aluno['total_pago']); ?></td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format($percentual, 1); ?>%</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="3" class="text-end">TOTAL:</td>
                                <td class="text-end"><?php echo formatarMoeda($total_pagos); ?></td>
                                <td>100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        // Gráfico Alunos
        const alunosNomes = <?php echo json_encode(array_column($relatorio_alunos, 'aluno_nome')); ?>;
        const alunosValores = <?php echo json_encode(array_column($relatorio_alunos, 'total_pago')); ?>;
        
        new Chart(document.getElementById('graficoAlunos'), {
            type: 'bar',
            data: { labels: alunosNomes, datasets: [{ label: 'Total Pago (Kz)', data: alunosValores, backgroundColor: '#006B3E', borderRadius: 5 }] },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: function(context) { return 'Total: ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
            }
        });
        </script>
        <?php endif; ?>
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