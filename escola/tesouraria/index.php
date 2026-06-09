<?php
// escola/tesouraria/index.php - Dashboard da Tesouraria

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
// CORREÇÃO: ESTATÍSTICAS DO MÊS ATUAL
// ============================================
$ano_atual = date('Y');
$mes_atual = date('m');
$data_inicio_mes = date('Y-m-01');
$data_fim_mes = date('Y-m-t');

// Total de Receitas do Mês (Pagamentos confirmados)
$sql = "SELECT COALESCE(SUM(valor), 0) as total FROM pagamentos 
        WHERE escola_id = :escola_id 
        AND status = 'confirmado' 
        AND MONTH(data_pagamento) = :mes 
        AND YEAR(data_pagamento) = :ano";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_atual, ':ano' => $ano_atual]);
$total_receitas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Total de Despesas do Mês (Caixa - saídas)
$sql = "SELECT COALESCE(SUM(valor), 0) as total FROM caixa 
        WHERE escola_id = :escola_id 
        AND tipo = 'saida' 
        AND status = 'ativo' 
        AND MONTH(data_movimento) = :mes 
        AND YEAR(data_movimento) = :ano";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_atual, ':ano' => $ano_atual]);
$total_despesas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Saldo do Mês
$saldo_mes = $total_receitas - $total_despesas;

// ============================================
// CORREÇÃO: MENSALIDADES
// ============================================
// Total de Mensalidades do Mês (Valor total de mensalidades deste mês)
$sql = "SELECT COALESCE(SUM(valor_total), 0) as total FROM mensalidades 
        WHERE escola_id = :escola_id 
        AND mes_referencia = :mes 
        AND ano_referencia = :ano";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_atual, ':ano' => $ano_atual]);
$total_mensalidades_mes = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Mensalidades Pagas no Mês
$sql = "SELECT COUNT(*) as total, COALESCE(SUM(valor_pago), 0) as valor 
        FROM mensalidades 
        WHERE escola_id = :escola_id 
        AND status = 'pago' 
        AND MONTH(data_pagamento) = :mes 
        AND YEAR(data_pagamento) = :ano";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_atual, ':ano' => $ano_atual]);
$mensalidades_pagas = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mensalidades_pagas) {
    $mensalidades_pagas = ['total' => 0, 'valor' => 0];
}

// Mensalidades Pendentes (Atrasadas)
$sql = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as valor 
        FROM mensalidades 
        WHERE escola_id = :escola_id 
        AND status IN ('pendente', 'parcial') 
        AND data_vencimento < CURDATE()";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$mensalidades_pendentes = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mensalidades_pendentes) {
    $mensalidades_pendentes = ['total' => 0, 'valor' => 0];
}

// ============================================
// CORREÇÃO: DÍVIDAS
// ============================================
// Total em Dívidas (soma direta das mensalidades não pagas)
$sql = "SELECT COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as total 
        FROM mensalidades 
        WHERE escola_id = :escola_id 
        AND status IN ('pendente', 'parcial')";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$total_dividas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Número de Alunos Inadimplentes
$sql = "SELECT COUNT(DISTINCT aluno_id) as total 
        FROM mensalidades 
        WHERE escola_id = :escola_id 
        AND status IN ('pendente', 'parcial') 
        AND (valor_total - COALESCE(valor_pago, 0)) > 0";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$alunos_inadimplentes = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ============================================
// CORREÇÃO: FLUXO DE CAIXA (Últimos 6 meses)
// ============================================
$fluxo_mensal = [];
for ($i = 5; $i >= 0; $i--) {
    $mes_numero = date('m', strtotime("-$i months"));
    $ano_numero = date('Y', strtotime("-$i months"));
    $nome_mes = date('M/Y', strtotime("-$i months"));
    
    // Receitas do mês (pagamentos confirmados)
    $sql = "SELECT COALESCE(SUM(valor), 0) as total 
            FROM pagamentos 
            WHERE escola_id = :escola_id 
            AND status = 'confirmado' 
            AND MONTH(data_pagamento) = :mes 
            AND YEAR(data_pagamento) = :ano";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_numero, ':ano' => $ano_numero]);
    $receitas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Despesas do mês (caixa - saídas)
    $sql = "SELECT COALESCE(SUM(valor), 0) as total 
            FROM caixa 
            WHERE escola_id = :escola_id 
            AND tipo = 'saida' 
            AND status = 'ativo' 
            AND MONTH(data_movimento) = :mes 
            AND YEAR(data_movimento) = :ano";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id, ':mes' => $mes_numero, ':ano' => $ano_numero]);
    $despesas = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    $fluxo_mensal[] = [
        'mes' => $nome_mes,
        'receitas' => (float)$receitas,
        'despesas' => (float)$despesas,
        'saldo' => (float)$receitas - (float)$despesas
    ];
}

// ============================================
// ÚLTIMOS PAGAMENTOS
// ============================================
$sql = "SELECT p.*, a.nome as aluno_nome, 
        COALESCE(t.nome, '-') as turma_nome,
        p.metodo_pagamento as forma_pagamento
        FROM pagamentos p 
        JOIN estudantes a ON a.id = p.assinatura_id 
        LEFT JOIN matriculas m ON m.estudante_id = a.id AND m.status = 'ativa'
        LEFT JOIN turmas t ON t.id = m.turma_id 
        WHERE p.escola_id = :escola_id 
        ORDER BY p.data_pagamento DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$ultimos_pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// TOP CLIENTES (MAIS PAGAM)
// ============================================
$sql = "SELECT a.nome, COUNT(p.id) as total_pagamentos, COALESCE(SUM(p.valor), 0) as total_gasto 
        FROM pagamentos p 
        JOIN estudantes a ON a.id = p.assinatura_id 
        WHERE p.escola_id = :escola_id AND p.status = 'confirmado'
        GROUP BY a.id 
        ORDER BY total_gasto DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute([':escola_id' => $escola_id]);
$top_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor) || $valor == 0) return '0,00 Kz';
    return number_format((float)$valor, 2, ',', '.') . ' Kz';
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

// Para debug - verificar se os dados estão sendo carregados
$debug_info = [
    'total_receitas' => $total_receitas,
    'total_despesas' => $total_despesas,
    'total_mensalidades_mes' => $total_mensalidades_mes,
    'mensalidades_pagas' => $mensalidades_pagas,
    'mensalidades_pendentes' => $mensalidades_pendentes,
    'total_dividas' => $total_dividas,
    'fluxo_mensal' => $fluxo_mensal
];
// Descomentar para debug
// echo "<pre>"; print_r($debug_info); echo "</pre>";
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tesouraria | Dashboard | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; transition: transform 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.6em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        .stat-receita { color: #28a745; }
        .stat-despesa { color: #dc3545; }
        .stat-saldo { color: #006B3E; }
        .stat-warning { color: #ffc107; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .table-pagamentos td { vertical-align: middle; }
        .badge-pagamento { padding: 5px 10px; border-radius: 15px; font-size: 11px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-coins"></i> Tesouraria</h2>
                <p>Gestão financeira da escola</p>
            </div>
            <div>
                <a href="pagamentos.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Novo Pagamento
                </a>
                <a href="../dashboard.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value stat-receita"><?php echo formatarMoeda($total_receitas); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up"></i> Receitas do Mês</div>
                    <small class="text-muted"><?php echo date('M/Y'); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value stat-despesa"><?php echo formatarMoeda($total_despesas); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down"></i> Despesas do Mês</div>
                    <small class="text-muted"><?php echo date('M/Y'); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value stat-saldo"><?php echo formatarMoeda($saldo_mes); ?></div>
                    <div class="stat-label"><i class="fas fa-chart-line"></i> Saldo do Mês</div>
                    <small class="text-muted"><?php echo $saldo_mes >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value stat-warning"><?php echo $alunos_inadimplentes; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Alunos Inadimplentes</div>
                    <small class="text-muted"><?php echo formatarMoeda($total_dividas); ?> em débito</small>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($mensalidades_pagas['total'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Mensalidades Pagas</div>
                    <small class="text-muted">Valor: <?php echo formatarMoeda($mensalidades_pagas['valor'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($mensalidades_pendentes['total'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="stat-label"><i class="fas fa-clock text-warning"></i> Mensalidades Atrasadas</div>
                    <small class="text-muted">Valor: <?php echo formatarMoeda($mensalidades_pendentes['valor'] ?? 0); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatarMoeda($total_mensalidades_mes); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Meta do Mês</div>
                    <small class="text-muted">Total de mensalidades a receber</small>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Fluxo de Caixa -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Fluxo de Caixa (Últimos 6 meses)</h5>
            </div>
            <div class="card-body">
                <canvas id="graficoFluxoCaixa" height="100"></canvas>
            </div>
        </div>
        
        <div class="row">
            <!-- Últimos Pagamentos -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Últimos Pagamentos</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-pagamentos">
                                <thead class="table-light">
                                    <tr><th>Data</th><th>Aluno</th><th>Turma</th><th>Valor</th><th>Forma</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ultimos_pagamentos)): ?>
                                    <tr><td colspan="6" class="text-center">Nenhum pagamento registrado</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($ultimos_pagamentos as $pg): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                            <td><?php echo htmlspecialchars($pg['aluno_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($pg['turma_nome'] ?? '-'); ?></small></td>
                                            <td class="text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                            <td><?php echo getFormaPagamentoIcone($pg['forma_pagamento']); ?> <?php echo ucfirst($pg['forma_pagamento']); ?></td>
                                            <td><span class="badge bg-success">Confirmado</span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="ver_pagamentos.php" class="btn btn-sm btn-outline-primary">Ver todos os pagamentos</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Clientes -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-trophy"></i> Top Clientes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($top_clientes)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-chart-line fa-3x mb-2"></i>
                                <p>Nenhum cliente encontrado</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($top_clientes as $index => $cliente): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <?php if ($index == 0): ?>
                                            <i class="fas fa-crown text-warning"></i>
                                        <?php elseif ($index == 1): ?>
                                            <i class="fas fa-medal text-secondary"></i>
                                        <?php elseif ($index == 2): ?>
                                            <i class="fas fa-medal" style="color: #cd7f32;"></i>
                                        <?php else: ?>
                                            <i class="fas fa-user-circle"></i>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($cliente['nome']); ?></strong>
                                        <br><small><?php echo $cliente['total_pagamentos']; ?> pagamento(s)</small>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold text-success"><?php echo formatarMoeda($cliente['total_gasto']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="row g-3 mt-2">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-money-bill-wave fa-2x text-success mb-2"></i>
                        <h6>Registrar Pagamento</h6>
                        <a href="pagamentos.php" class="btn btn-sm btn-outline-primary">Acessar</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-chart-pie fa-2x text-info mb-2"></i>
                        <h6>Relatórios</h6>
                        <a href="relatorios_financeiros.php" class="btn btn-sm btn-outline-primary">Acessar</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h6>Dívidas</h6>
                        <a href="dividas.php" class="btn btn-sm btn-outline-primary">Acessar</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-receipt fa-2x text-secondary mb-2"></i>
                        <h6>Recibos</h6>
                        <a href="recibos.php" class="btn btn-sm btn-outline-primary">Acessar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Dados para o gráfico
        const labels = <?php echo json_encode(array_column($fluxo_mensal, 'mes')); ?>;
        const receitasData = <?php echo json_encode(array_column($fluxo_mensal, 'receitas')); ?>;
        const despesasData = <?php echo json_encode(array_column($fluxo_mensal, 'despesas')); ?>;
        
        // Gráfico de Fluxo de Caixa
        const ctx = document.getElementById('graficoFluxoCaixa').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Receitas',
                        data: receitasData,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 1
                    },
                    {
                        label: 'Despesas',
                        data: despesasData,
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
                    legend: { position: 'top' },
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
    </script>
</body>
</html>