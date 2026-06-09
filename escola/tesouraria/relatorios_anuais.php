<?php
// escola/tesouraria/relatorios_anuais.php - Relatórios Anuais

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
$comparar_anos = isset($_GET['comparar']) ? (int)$_GET['comparar'] : 3; // Comparar últimos N anos

// ============================================
// DADOS DO ANO SELECIONADO
// ============================================

// Resumo do ano
$sql_resumo = "SELECT 
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                    COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                    COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND YEAR(data_movimento) = :ano
                AND status = 'ativo'";
$stmt_resumo = $conn->prepare($sql_resumo);
$stmt_resumo->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$resumo_ano = $stmt_resumo->fetch(PDO::FETCH_ASSOC);
$resumo_ano['saldo'] = $resumo_ano['total_entradas'] - $resumo_ano['total_saidas'];

// Dados mensais do ano
$sql_mensal = "SELECT 
                    MONTH(data_movimento) as mes,
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saidas
                FROM caixa 
                WHERE escola_id = :escola_id 
                AND YEAR(data_movimento) = :ano
                AND status = 'ativo'
                GROUP BY MONTH(data_movimento)
                ORDER BY mes ASC";
$stmt_mensal = $conn->prepare($sql_mensal);
$stmt_mensal->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$dados_mensais = $stmt_mensal->fetchAll(PDO::FETCH_ASSOC);

// Organizar dados mensais
$entradas_mensais = array_fill(1, 12, 0);
$saidas_mensais = array_fill(1, 12, 0);
foreach ($dados_mensais as $d) {
    $entradas_mensais[$d['mes']] = $d['entradas'];
    $saidas_mensais[$d['mes']] = $d['saidas'];
}

// Totais por categoria no ano
$sql_categorias = "SELECT 
                        tipo,
                        categoria,
                        COUNT(*) as quantidade,
                        COALESCE(SUM(valor), 0) as total
                    FROM caixa 
                    WHERE escola_id = :escola_id 
                    AND YEAR(data_movimento) = :ano
                    AND status = 'ativo'
                    GROUP BY tipo, categoria
                    ORDER BY total DESC
                    LIMIT 15";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$categorias_ano = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Pagamentos totais no ano por tipo
$sql_pagamentos = "SELECT 
                        tipo_pagamento,
                        COUNT(*) as quantidade,
                        COALESCE(SUM(valor), 0) as total
                    FROM pagamentos 
                    WHERE escola_id = :escola_id 
                    AND status = 'confirmado'
                    AND YEAR(data_pagamento) = :ano
                    GROUP BY tipo_pagamento
                    ORDER BY total DESC";
$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$pagamentos_ano = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Mensalidades do ano
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
                    AND ano_referencia = :ano";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$mensalidades_ano = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);

// Top alunos do ano
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
                    GROUP BY a.id
                    ORDER BY total_pago DESC
                    LIMIT 10";
$stmt_top_alunos = $conn->prepare($sql_top_alunos);
$stmt_top_alunos->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$top_alunos_ano = $stmt_top_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// COMPARAÇÃO ENTRE ANOS
// ============================================

$anos_comparar = [];
for ($i = 0; $i < $comparar_anos; $i++) {
    $ano = $ano_selecionado - $i;
    $anos_comparar[] = $ano;
}

$sql_comparacao = "SELECT 
                        YEAR(data_movimento) as ano,
                        COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                        COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas
                    FROM caixa 
                    WHERE escola_id = :escola_id 
                    AND YEAR(data_movimento) IN (" . implode(',', $anos_comparar) . ")
                    AND status = 'ativo'
                    GROUP BY YEAR(data_movimento)
                    ORDER BY ano DESC";
$stmt_comparacao = $conn->prepare($sql_comparacao);
$stmt_comparacao->execute([':escola_id' => $escola_id]);
$comparacao_anos = $stmt_comparacao->fetchAll(PDO::FETCH_ASSOC);

// Organizar dados de comparação
$dados_comparacao = [];
foreach ($anos_comparar as $ano) {
    $dados_comparacao[$ano] = ['entradas' => 0, 'saidas' => 0, 'saldo' => 0];
}
foreach ($comparacao_anos as $c) {
    $dados_comparacao[$c['ano']]['entradas'] = $c['total_entradas'];
    $dados_comparacao[$c['ano']]['saidas'] = $c['total_saidas'];
    $dados_comparacao[$c['ano']]['saldo'] = $c['total_entradas'] - $c['total_saidas'];
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

function getCrescimento($atual, $anterior) {
    if ($anterior == 0) return $atual > 0 ? 100 : 0;
    return (($atual - $anterior) / $anterior) * 100;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Anuais | Tesouraria | SIGE Angola</title>
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
        .growth-positive { color: #28a745; }
        .growth-negative { color: #dc3545; }
        
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
                <h2><i class="fas fa-chart-line"></i> Relatórios Anuais</h2>
                <p class="text-muted">Análise completa do desempenho anual</p>
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
                            <?php for($a = date('Y')-2; $a <= date('Y')+1; $a++): ?>
                            <option value="<?php echo $a; ?>" <?php echo $ano_selecionado == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Comparar Últimos</label>
                        <select name="comparar" class="form-select">
                            <option value="2" <?php echo $comparar_anos == 2 ? 'selected' : ''; ?>>2 anos</option>
                            <option value="3" <?php echo $comparar_anos == 3 ? 'selected' : ''; ?>>3 anos</option>
                            <option value="4" <?php echo $comparar_anos == 4 ? 'selected' : ''; ?>>4 anos</option>
                            <option value="5" <?php echo $comparar_anos == 5 ? 'selected' : ''; ?>>5 anos</option>
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
            <h3>RELATÓRIO ANUAL</h3>
            <h4><?php echo $ano_selecionado; ?></h4>
        </div>
        
        <!-- Cards de Resumo do Ano -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($resumo_ano['total_entradas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small><?php echo $resumo_ano['qtd_entradas']; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($resumo_ano['total_saidas']); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small><?php echo $resumo_ano['qtd_saidas']; ?> transações</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo $resumo_ano['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($resumo_ano['saldo']); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Ano</div>
                    <small><?php echo $resumo_ano['saldo'] >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($mensalidades_ano['total_mensalidades'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Mensalidades Lançadas</div>
                    <small>Valor: <?php echo formatarMoeda($mensalidades_ano['valor_total'] ?? 0); ?></small>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $mensalidades_ano['pagas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Mensalidades Pagas</div>
                    <small>Valor: <?php echo formatarMoeda($mensalidades_ano['valor_pago'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo ($mensalidades_ano['pendentes'] ?? 0) + ($mensalidades_ano['parciais'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-clock text-warning"></i> Mensalidades Pendentes</div>
                    <small>Valor: <?php echo formatarMoeda(($mensalidades_ano['valor_total'] ?? 0) - ($mensalidades_ano['valor_pago'] ?? 0)); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $mensalidades_ano['atrasadas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle text-danger"></i> Mensalidades Atrasadas</div>
                    <small>Precisa de atenção</small>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Evolução Mensal -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Evolução Mensal - <?php echo $ano_selecionado; ?></h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoMensal" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Comparação entre Anos -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Comparação entre Anos</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="graficoComparacao" height="300"></canvas>
                </div>
                <div class="table-responsive mt-4">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th>Ano</th><th class="text-end">Entradas</th><th class="text-end">Saídas</th><th class="text-end">Saldo</th><th>Crescimento</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $anos_ordenados = array_keys($dados_comparacao);
                            rsort($anos_ordenados);
                            $anterior = null;
                            foreach ($anos_ordenados as $ano): 
                                $crescimento = '';
                                if ($anterior !== null && $dados_comparacao[$anterior]['entradas'] > 0) {
                                    $cresc = getCrescimento($dados_comparacao[$ano]['entradas'], $dados_comparacao[$anterior]['entradas']);
                                    $crescimento = $cresc > 0 ? "+" . number_format($cresc, 1) . "%" : number_format($cresc, 1) . "%";
                                    $cresc_class = $cresc >= 0 ? 'growth-positive' : 'growth-negative';
                                    $crescimento = "<span class='$cresc_class'>$crescimento</span>";
                                } else {
                                    $crescimento = "—";
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo $ano; ?></strong></td>
                                <td class="text-end text-success"><?php echo formatarMoeda($dados_comparacao[$ano]['entradas']); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($dados_comparacao[$ano]['saidas']); ?></td>
                                <td class="text-end <?php echo $dados_comparacao[$ano]['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                    <?php echo formatarMoeda($dados_comparacao[$ano]['saldo']); ?>
                                </td>
                                <td><?php echo $crescimento; ?></td>
                            </tr>
                            <?php 
                            $anterior = $ano;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
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
                            <?php foreach ($pagamentos_ano as $pg): ?>
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
            
            <!-- Top Categorias de Despesas -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Principais Despesas do Ano</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="graficoDespesas"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php 
                            $despesas_filtradas = array_filter($categorias_ano, function($item) { 
                                return $item['tipo'] == 'saida'; 
                            });
                            foreach ($despesas_filtradas as $desp): 
                            ?>
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
        
        <!-- Top Alunos do Ano -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-trophy"></i> Top 10 Alunos que Mais Pagaram em <?php echo $ano_selecionado; ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($top_alunos_ano)): ?>
                    <div class="alert alert-info text-center">Nenhum pagamento registrado neste ano.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>#</th><th>Aluno</th><th>Matrícula</th><th>Quantidade</th><th class="text-end">Total Pago</th><th>% do Total</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_pagos_ano = array_sum(array_column($top_alunos_ano, 'total_pago'));
                                foreach ($top_alunos_ano as $index => $aluno):
                                    $percentual = $total_pagos_ano > 0 ? ($aluno['total_pago'] / $total_pagos_ano) * 100 : 0;
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
                                    <td class="text-end"><?php echo formatarMoeda($total_pagos_ano); ?></td>
                                    <td>100%</small></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Resumo Geral do Ano -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Resumo Geral - <?php echo $ano_selecionado; ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Indicadores de Desempenho</h6>
                        <table class="table table-borderless">
                            <tr><th>Ticket Médio por Pagamento:</th><td class="text-end"><?php 
                                $ticket_medio = $resumo_ano['qtd_entradas'] > 0 ? $resumo_ano['total_entradas'] / $resumo_ano['qtd_entradas'] : 0;
                                echo formatarMoeda($ticket_medio);
                            ?></td></tr>
                            <tr><th>Média Mensal de Entradas:</th><td class="text-end"><?php echo formatarMoeda($resumo_ano['total_entradas'] / 12); ?></td></tr>
                            <tr><th>Média Mensal de Saídas:</th><td class="text-end"><?php echo formatarMoeda($resumo_ano['total_saidas'] / 12); ?></td></tr>
                            <tr><th>Taxa de Eficiência:</th><td class="text-end">
                                <?php 
                                $eficiencia = $resumo_ano['total_entradas'] > 0 ? ($resumo_ano['total_entradas'] - $resumo_ano['total_saidas']) / $resumo_ano['total_entradas'] * 100 : 0;
                                echo number_format($eficiencia, 1) . '%';
                                ?>
                            </td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Distribuição das Entradas</h6>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="graficoDistribuicao"></canvas>
                        </div>
                    </div>
                </div>
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
        
        // Gráfico Mensal
        const mesesLabels = <?php echo json_encode(array_map(function($m) { return getMesNome($m); }, range(1, 12))); ?>;
        const entradasMensais = <?php echo json_encode(array_values($entradas_mensais)); ?>;
        const saidasMensais = <?php echo json_encode(array_values($saidas_mensais)); ?>;
        
        new Chart(document.getElementById('graficoMensal'), {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: [
                    { label: 'Entradas', data: entradasMensais, backgroundColor: 'rgba(40, 167, 69, 0.7)', borderColor: '#28a745', borderWidth: 1 },
                    { label: 'Saídas', data: saidasMensais, backgroundColor: 'rgba(220, 53, 69, 0.7)', borderColor: '#dc3545', borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
            }
        });
        
        // Gráfico de Comparação entre Anos
        const anosLabels = <?php echo json_encode(array_keys($dados_comparacao)); ?>;
        const entradasAnos = <?php echo json_encode(array_column($dados_comparacao, 'entradas')); ?>;
        const saidasAnos = <?php echo json_encode(array_column($dados_comparacao, 'saidas')); ?>;
        
        new Chart(document.getElementById('graficoComparacao'), {
            type: 'bar',
            data: {
                labels: anosLabels,
                datasets: [
                    { label: 'Entradas', data: entradasAnos, backgroundColor: 'rgba(40, 167, 69, 0.7)', borderColor: '#28a745', borderWidth: 1 },
                    { label: 'Saídas', data: saidasAnos, backgroundColor: 'rgba(220, 53, 69, 0.7)', borderColor: '#dc3545', borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value.toLocaleString('pt-AO') + ' Kz'; } } } }
            }
        });
        
        // Gráfico de Tipos de Pagamento
        const tiposLabels = <?php echo json_encode(array_column($pagamentos_ano, 'tipo_pagamento')); ?>;
        const tiposValores = <?php echo json_encode(array_column($pagamentos_ano, 'total')); ?>;
        
        if (tiposLabels.length > 0) {
            new Chart(document.getElementById('graficoTipos'), {
                type: 'pie',
                data: { labels: tiposLabels, datasets: [{ data: tiposValores, backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d'] }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } } }
            });
        }
        
        // Gráfico de Despesas
        const despesasLabels = <?php 
            $despesas_filtradas = array_filter($categorias_ano, function($item) { return $item['tipo'] == 'saida'; });
            echo json_encode(array_column($despesas_filtradas, 'categoria')); 
        ?>;
        const despesasValores = <?php echo json_encode(array_column($despesas_filtradas, 'total')); ?>;
        
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
        
        // Gráfico de Distribuição de Entradas
        const entradasCategorias = <?php 
            $entradas_filtradas = array_filter($categorias_ano, function($item) { return $item['tipo'] == 'entrada'; });
            echo json_encode(array_column($entradas_filtradas, 'categoria')); 
        ?>;
        const entradasCategoriasValores = <?php echo json_encode(array_column($entradas_filtradas, 'total')); ?>;
        
        if (entradasCategorias.length > 0) {
            new Chart(document.getElementById('graficoDistribuicao'), {
                type: 'doughnut',
                data: { labels: entradasCategorias, datasets: [{ data: entradasCategoriasValores, backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#20c997', '#34ce57', '#48d96b'] }] },
                options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; } } } } }
            });
        }
    </script>
</body>
</html>