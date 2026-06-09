<?php
// escola/tesouraria/relatorios_mensais.php - Relatórios Mensais Detalhados

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
$ano_selecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$mes_selecionado = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$comparar_ano = isset($_GET['comparar_ano']) ? (int)$_GET['comparar_ano'] : date('Y') - 1;

// ============================================
// DADOS DO MÊS SELECIONADO
// ============================================

// Resumo do mês
$sql_resumo = "SELECT 
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                    COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                    COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND YEAR(data_movimento) = :ano 
                AND MONTH(data_movimento) = :mes
                AND status = 'ativo'";
$stmt_resumo = $conn->prepare($sql_resumo);
$stmt_resumo->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado, ':mes' => $mes_selecionado]);
$resumo_mes = $stmt_resumo->fetch(PDO::FETCH_ASSOC);
$resumo_mes['saldo'] = $resumo_mes['total_entradas'] - $resumo_mes['total_saidas'];

// Pagamentos do mês (agrupados por tipo)
$sql_pagamentos = "SELECT 
                        tipo_pagamento,
                        COUNT(*) as quantidade,
                        COALESCE(SUM(valor), 0) as total
                    FROM pagamentos 
                    WHERE escola_id = :escola_id 
                    AND status = 'confirmado'
                    AND YEAR(data_pagamento) = :ano 
                    AND MONTH(data_pagamento) = :mes
                    GROUP BY tipo_pagamento
                    ORDER BY total DESC";
$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado, ':mes' => $mes_selecionado]);
$pagamentos_mes = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Despesas do mês por categoria
$sql_despesas = "SELECT 
                    categoria,
                    COUNT(*) as quantidade,
                    COALESCE(SUM(valor), 0) as total
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND tipo = 'saida'
                AND status = 'ativo'
                AND YEAR(data_movimento) = :ano 
                AND MONTH(data_movimento) = :mes
                GROUP BY categoria
                ORDER BY total DESC
                LIMIT 10";
$stmt_despesas = $conn->prepare($sql_despesas);
$stmt_despesas->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado, ':mes' => $mes_selecionado]);
$despesas_mes = $stmt_despesas->fetchAll(PDO::FETCH_ASSOC);

// Mensalidades do mês
$sql_mensalidades = "SELECT 
                        COUNT(*) as total_mensalidades,
                        SUM(valor_total) as valor_total,
                        SUM(valor_pago) as valor_pago,
                        COUNT(CASE WHEN status = 'pago' THEN 1 END) as pagas,
                        COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
                        COUNT(CASE WHEN status = 'parcial' THEN 1 END) as parciais,
                        COUNT(CASE WHEN status = 'atrasado' THEN 1 END) as atrasadas
                    FROM mensalidades 
                    WHERE escola_id = :escola_id 
                    AND mes_referencia = :mes 
                    AND ano_referencia = :ano";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':escola_id' => $escola_id, ':mes' => $mes_selecionado, ':ano' => $ano_selecionado]);
$mensalidades_mes = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);

// Evolução diária no mês
$sql_diario = "SELECT 
                    DAY(data_movimento) as dia,
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND YEAR(data_movimento) = :ano 
                AND MONTH(data_movimento) = :mes
                AND status = 'ativo'
                GROUP BY DAY(data_movimento)
                ORDER BY dia ASC";
$stmt_diario = $conn->prepare($sql_diario);
$stmt_diario->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado, ':mes' => $mes_selecionado]);
$movimentos_diarios = $stmt_diario->fetchAll(PDO::FETCH_ASSOC);

// Top alunos do mês
$sql_top_alunos = "SELECT 
                        a.nome as aluno_nome,
                        a.matricula,
                        COUNT(p.id) as quantidade,
                        COALESCE(SUM(p.valor), 0) as total_pago
                    FROM pagamentos p
                    JOIN estudantes a ON a.id = p.assinatura_id
                    WHERE p.escola_id = :escola_id 
                    AND p.status = 'confirmado'
                    AND YEAR(p.data_pagamento) = :ano 
                    AND MONTH(p.data_pagamento) = :mes
                    GROUP BY a.id
                    ORDER BY total_pago DESC
                    LIMIT 10";
$stmt_top_alunos = $conn->prepare($sql_top_alunos);
$stmt_top_alunos->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado, ':mes' => $mes_selecionado]);
$top_alunos = $stmt_top_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DADOS PARA COMPARAÇÃO ANUAL
// ============================================

// Comparação com ano anterior
$sql_comparacao = "SELECT 
                        MONTH(data_movimento) as mes,
                        COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                        COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
                    FROM caixa 
                    WHERE escola_id = :escola_id 
                    AND YEAR(data_movimento) IN (:ano_atual, :ano_anterior)
                    AND status = 'ativo'
                    GROUP BY YEAR(data_movimento), MONTH(data_movimento)
                    ORDER BY mes ASC";
$stmt_comparacao = $conn->prepare($sql_comparacao);
$stmt_comparacao->execute([
    ':escola_id' => $escola_id,
    ':ano_atual' => $ano_selecionado,
    ':ano_anterior' => $comparar_ano
]);
$comparacao_mensal = $stmt_comparacao->fetchAll(PDO::FETCH_ASSOC);

// Organizar dados de comparação
$comparacao = [];
foreach ($comparacao_mensal as $c) {
    $comparacao[$c['mes']][$ano_selecionado] = $c['entradas'];
    $comparacao[$c['mes']][$comparar_ano] = $c['entradas'];
}

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

function getStatusBadge($status) {
    switch ($status) {
        case 'pago': return '<span class="badge bg-success">Pago</span>';
        case 'pendente': return '<span class="badge bg-secondary">Pendente</span>';
        case 'parcial': return '<span class="badge bg-warning text-dark">Parcial</span>';
        case 'atrasado': return '<span class="badge bg-danger">Atrasado</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Mensais | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; font-weight: 600; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .chart-container { position: relative; height: 300px; margin-bottom: 20px; }
        
        .print-only { display: none; }
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .chart-container { height: 200px; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-line"></i> Relatórios Mensais</h2>
                <p class="text-muted">Análise detalhada por período</p>
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
        <div class="card no-print mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Selecionar Período</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Ano</label>
                        <select name="ano" class="form-select">
                            <?php for($a = date('Y')-2; $a <= date('Y'); $a++): ?>
                            <option value="<?php echo $a; ?>" <?php echo $ano_selecionado == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mês</label>
                        <select name="mes" class="form-select">
                            <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $mes_selecionado == $m ? 'selected' : ''; ?>><?php echo getMesNome($m); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Comparar com Ano</label>
                        <select name="comparar_ano" class="form-select">
                            <?php for($a = date('Y')-2; $a <= date('Y'); $a++): ?>
                            <option value="<?php echo $a; ?>" <?php echo $comparar_ano == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Título do Relatório -->
        <div class="text-center mb-4">
            <h3>RELATÓRIO FINANCEIRO</h3>
            <h4><?php echo getMesNome($mes_selecionado); ?> de <?php echo $ano_selecionado; ?></h4>
        </div>
        
        <!-- Cards de Resumo do Mês -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($resumo_mes['total_entradas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small><?php echo $resumo_mes['qtd_entradas']; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($resumo_mes['total_saidas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small><?php echo $resumo_mes['qtd_saidas']; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $resumo_mes['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($resumo_mes['saldo']); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Mês</div>
                    <small><?php echo $resumo_mes['saldo'] >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($mensalidades_mes['total_mensalidades'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Mensalidades Lançadas</div>
                    <small>Valor: <?php echo formatarMoeda($mensalidades_mes['valor_total'] ?? 0); ?></small>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $mensalidades_mes['pagas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Mensalidades Pagas</div>
                    <small>Valor: <?php echo formatarMoeda($mensalidades_mes['valor_pago'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo ($mensalidades_mes['pendentes'] ?? 0) + ($mensalidades_mes['parciais'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-clock text-warning"></i> Mensalidades Pendentes</div>
                    <small>Valor: <?php echo formatarMoeda(($mensalidades_mes['valor_total'] ?? 0) - ($mensalidades_mes['valor_pago'] ?? 0)); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $mensalidades_mes['atrasadas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle text-danger"></i> Mensalidades Atrasadas</div>
                    <small>Precisa de atenção</small>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Evolução Diária -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Evolução Diária - <?php echo getMesNome($mes_selecionado); ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoDiario" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Pagamentos por Tipo -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Pagamentos por Tipo</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="graficoTipos"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach ($pagamentos_mes as $pg): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo ucfirst($pg['tipo_pagamento']); ?></span>
                                <span class="fw-bold text-success"><?php echo formatarMoeda($pg['total']); ?></span>
                                <span class="text-muted">(<?php echo $pg['quantidade']; ?>x)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Despesas por Categoria -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Principais Despesas</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="graficoDespesas"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach ($despesas_mes as $desp): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo htmlspecialchars($desp['categoria']); ?></span>
                                <span class="fw-bold text-danger"><?php echo formatarMoeda($desp['total']); ?></span>
                                <span class="text-muted">(<?php echo $desp['quantidade']; ?>x)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Comparação com Ano Anterior -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Comparação com <?php echo $comparar_ano; ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoComparacao" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Top Alunos do Mês -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-trophy"></i> Top Alunos que Mais Pagaram</h5>
            </div>
            <div class="card-body">
                <?php if (empty($top_alunos)): ?>
                    <div class="alert alert-info text-center">Nenhum pagamento registrado neste mês.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>#</th><th>Aluno</th><th>Matrícula</th><th>Quantidade</th><th class="text-end">Total Pago</th><th>%</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_pagos = array_sum(array_column($top_alunos, 'total_pago'));
                                foreach ($top_alunos as $index => $aluno):
                                    $percentual = $total_pagos > 0 ? ($aluno['total_pago'] / $total_pagos) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo $index + 1; ?> <?php echo $index == 0 ? '<i class="fas fa-crown text-warning"></i>' : ''; ?></td>
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
                                    <td colspan="4" class="text-end">TOTAL:</td>
                                    <td class="text-end"><?php echo formatarMoeda($total_pagos); ?></td>
                                    <td>100%</small></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Rodapé do Relatório -->
        <div class="text-center text-muted mt-4">
            <small>Relatório gerado em <?php echo date('d/m/Y H:i:s'); ?> por <?php echo $usuario_nome; ?></small>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico Diário
        const diasLabels = <?php echo json_encode(array_column($movimentos_diarios, 'dia')); ?>;
        const entradasDiarias = <?php echo json_encode(array_column($movimentos_diarios, 'entradas')); ?>;
        const saidasDiarias = <?php echo json_encode(array_column($movimentos_diarios, 'saidas')); ?>;
        
        if (diasLabels.length > 0) {
            new Chart(document.getElementById('graficoDiario'), {
                type: 'line',
                data: {
                    labels: diasLabels,
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
        }
        
        // Gráfico de Tipos de Pagamento
        const tiposLabels = <?php echo json_encode(array_column($pagamentos_mes, 'tipo_pagamento')); ?>;
        const tiposValores = <?php echo json_encode(array_column($pagamentos_mes, 'total')); ?>;
        
        if (tiposLabels.length > 0) {
            new Chart(document.getElementById('graficoTipos'), {
                type: 'pie',
                data: { labels: tiposLabels, datasets: [{ data: tiposValores, backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#fd7e14'] }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } } }
            });
        }
        
        // Gráfico de Despesas
        const despesasLabels = <?php echo json_encode(array_column($despesas_mes, 'categoria')); ?>;
        const despesasValores = <?php echo json_encode(array_column($despesas_mes, 'total')); ?>;
        
        if (despesasLabels.length > 0) {
            new Chart(document.getElementById('graficoDespesas'), {
                type: 'bar',
                data: { labels: despesasLabels, datasets: [{ label: 'Valor (Kz)', data: despesasValores, backgroundColor: '#dc3545', borderRadius: 5 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { tooltip: { callbacks: { label: function(context) { return 'Total: ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                    scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
                }
            });
        }
        
        // Gráfico de Comparação Anual
        const mesesLabels = <?php echo json_encode(array_map(function($m) { return getMesNome($m); }, range(1, 12))); ?>;
        const dadosAnoAtual = [];
        const dadosAnoAnterior = [];
        
        for (let i = 1; i <= 12; i++) {
            dadosAnoAtual.push(<?php 
                $val = 0;
                foreach ($comparacao_mensal as $c) {
                    if ($c['mes'] == $i && isset($c[$ano_selecionado])) $val = $c['entradas'];
                }
                echo $val;
            ?>);
            dadosAnoAnterior.push(<?php 
                $val = 0;
                foreach ($comparacao_mensal as $c) {
                    if ($c['mes'] == $i && isset($c[$comparar_ano])) $val = $c['entradas'];
                }
                echo $val;
            ?>);
        }
        
        new Chart(document.getElementById('graficoComparacao'), {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: [
                    { label: '<?php echo $ano_selecionado; ?>', data: dadosAnoAtual, backgroundColor: 'rgba(40, 167, 69, 0.7)', borderColor: '#28a745', borderWidth: 1 },
                    { label: '<?php echo $comparar_ano; ?>', data: dadosAnoAnterior, backgroundColor: 'rgba(108, 117, 125, 0.7)', borderColor: '#6c757d', borderWidth: 1 }
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
</body>
</html>