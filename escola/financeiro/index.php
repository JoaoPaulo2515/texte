<?php
// escola/tesouraria/financeiro/index.php - Dashboard Financeiro

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// ESTATÍSTICAS FINANCEIRAS
// ============================================

// Receitas do mês atual
$sql_receitas_mes = "SELECT 
                        COUNT(*) as total_pagamentos,
                        COALESCE(SUM(valor), 0) as total_receitas,
                        COUNT(DISTINCT assinatura_id) as total_alunos
                    FROM pagamentos 
                    WHERE escola_id = :escola_id 
                    AND MONTH(data_pagamento) = MONTH(CURDATE()) 
                    AND YEAR(data_pagamento) = YEAR(CURDATE())
                    AND status = 'confirmado'";
$stmt_receitas = $conn->prepare($sql_receitas_mes);
$stmt_receitas->execute([':escola_id' => $escola_id]);
$receitas_mes = $stmt_receitas->fetch(PDO::FETCH_ASSOC);

// Despesas do mês atual
$sql_despesas_mes = "SELECT 
                        COUNT(*) as total_despesas,
                        COALESCE(SUM(valor), 0) as total_despesas_valor
                    FROM caixa 
                    WHERE escola_id = :escola_id 
                    AND tipo = 'saida'
                    AND MONTH(data_movimento) = MONTH(CURDATE()) 
                    AND YEAR(data_movimento) = YEAR(CURDATE())
                    AND status = 'ativo'";
$stmt_despesas = $conn->prepare($sql_despesas_mes);
$stmt_despesas->execute([':escola_id' => $escola_id]);
$despesas_mes = $stmt_despesas->fetch(PDO::FETCH_ASSOC);

// Saldo atual
$sql_saldo = "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as total_saidas
            FROM caixa 
            WHERE escola_id = :escola_id 
            AND status = 'ativo'";
$stmt_saldo = $conn->prepare($sql_saldo);
$stmt_saldo->execute([':escola_id' => $escola_id]);
$saldo = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
$saldo_atual = ($saldo['total_entradas'] ?? 0) - ($saldo['total_saidas'] ?? 0);

// Dívidas em aberto
$sql_dividas = "SELECT 
                    COUNT(*) as total_dividas,
                    COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as valor_total_dividas
                FROM mensalidades 
                WHERE escola_id = :escola_id 
                AND status IN ('pendente', 'parcial')";
$stmt_dividas = $conn->prepare($sql_dividas);
$stmt_dividas->execute([':escola_id' => $escola_id]);
$dividas = $stmt_dividas->fetch(PDO::FETCH_ASSOC);

// Pagamentos por tipo (últimos 30 dias)
$sql_pagamentos_tipo = "SELECT 
                            tipo_pagamento,
                            COUNT(*) as quantidade,
                            COALESCE(SUM(valor), 0) as total
                        FROM pagamentos 
                        WHERE escola_id = :escola_id 
                        AND data_pagamento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY tipo_pagamento
                        ORDER BY total DESC";
$stmt_tipo = $conn->prepare($sql_pagamentos_tipo);
$stmt_tipo->execute([':escola_id' => $escola_id]);
$pagamentos_por_tipo = $stmt_tipo->fetchAll(PDO::FETCH_ASSOC);

// Formas de pagamento mais usadas
$sql_formas = "SELECT 
                    metodo_pagamento,
                    COUNT(*) as quantidade,
                    COALESCE(SUM(valor), 0) as total
                FROM pagamentos 
                WHERE escola_id = :escola_id 
                AND data_pagamento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY metodo_pagamento
                ORDER BY quantidade DESC
                LIMIT 5";
$stmt_formas = $conn->prepare($sql_formas);
$stmt_formas->execute([':escola_id' => $escola_id]);
$formas_pagamento = $stmt_formas->fetchAll(PDO::FETCH_ASSOC);

// Últimos 10 pagamentos
$sql_ultimos = "SELECT p.*, e.nome as aluno_nome 
                FROM pagamentos p
                JOIN estudantes e ON e.id = p.assinatura_id
                WHERE p.escola_id = :escola_id 
                ORDER BY p.id DESC 
                LIMIT 10";
$stmt_ultimos = $conn->prepare($sql_ultimos);
$stmt_ultimos->execute([':escola_id' => $escola_id]);
$ultimos_pagamentos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);

// Previsão de receitas (próximos 30 dias)
$sql_previsao = "SELECT 
                    COUNT(*) as mensalidades_vencendo,
                    COALESCE(SUM(valor_total - COALESCE(valor_pago, 0)), 0) as valor_previsao
                FROM mensalidades 
                WHERE escola_id = :escola_id 
                AND status IN ('pendente', 'parcial')
                AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
$stmt_previsao = $conn->prepare($sql_previsao);
$stmt_previsao->execute([':escola_id' => $escola_id]);
$previsao = $stmt_previsao->fetch(PDO::FETCH_ASSOC);

// Ticket médio
$ticket_medio = ($receitas_mes['total_pagamentos'] > 0) ? $receitas_mes['total_receitas'] / $receitas_mes['total_pagamentos'] : 0;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .card:hover { transform: translateY(-2px); }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .stat-card {
            border-radius: 15px;
            padding: 20px;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .stat-card .icon {
            font-size: 3rem;
            opacity: 0.3;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        .stat-card h3 { font-size: 2rem; margin: 0; font-weight: bold; }
        .stat-card p { margin: 0; opacity: 0.9; }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
            display: inline-block;
        }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .table-pagamentos td { vertical-align: middle; }
        
        .saldo-positivo { color: #28a745; }
        .saldo-negativo { color: #dc3545; }
        
        .quick-action {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .quick-action:hover {
            background: #e9ecef;
            transform: translateY(-3px);
        }
        .quick-action i {
            font-size: 2rem;
            color: #006B3E;
            margin-bottom: 10px;
        }
        .quick-action span {
            display: block;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-line"></i> Dashboard Financeiro</h2>
                <p class="text-muted">Visão geral das finanças da escola</p>
            </div>
            <div>
                <a href="../index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Receitas do Mês</p>
                    <h3><?php echo number_format($receitas_mes['total_receitas'] ?? 0, 2, ',', '.'); ?> Kz</h3>
                    <small><?php echo number_format($receitas_mes['total_pagamentos'] ?? 0); ?> pagamentos | <?php echo number_format($receitas_mes['total_alunos'] ?? 0); ?> alunos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%);">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <p>Despesas do Mês</p>
                    <h3><?php echo number_format($despesas_mes['total_despesas_valor'] ?? 0, 2, ',', '.'); ?> Kz</h3>
                    <small><?php echo number_format($despesas_mes['total_despesas'] ?? 0); ?> despesas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);">
                    <div class="icon"><i class="fas fa-wallet"></i></div>
                    <p>Saldo Atual</p>
                    <h3 class="<?php echo $saldo_atual >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>" style="color: white;">
                        <?php echo number_format($saldo_atual, 2, ',', '.'); ?> Kz
                    </h3>
                    <small>Entradas: <?php echo number_format($saldo['total_entradas'] ?? 0, 2, ',', '.'); ?> | Saídas: <?php echo number_format($saldo['total_saidas'] ?? 0, 2, ',', '.'); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #fd7e14 0%, #ffc107 100%);">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <p>Dívidas em Aberto</p>
                    <h3><?php echo number_format($dividas['valor_total_dividas'] ?? 0, 2, ',', '.'); ?> Kz</h3>
                    <small><?php echo number_format($dividas['total_dividas'] ?? 0); ?> mensalidades pendentes</small>
                </div>
            </div>
        </div>
        
        <!-- Segunda linha de cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">Ticket Médio</div>
                    <div class="card-body text-center">
                        <h2 class="text-success"><?php echo number_format($ticket_medio, 2, ',', '.'); ?> Kz</h2>
                        <p class="text-muted">Valor médio por pagamento</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">Previsão Próximos 30 Dias</div>
                    <div class="card-body text-center">
                        <h2 class="text-primary"><?php echo number_format($previsao['valor_previsao'] ?? 0, 2, ',', '.'); ?> Kz</h2>
                        <p class="text-muted"><?php echo number_format($previsao['mensalidades_vencendo'] ?? 0); ?> mensalidades a vencer</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">Total de Pagamentos</div>
                    <div class="card-body text-center">
                        <h2 class="text-info"><?php echo number_format($receitas_mes['total_pagamentos'] ?? 0); ?></h2>
                        <p class="text-muted">Pagamentos realizados este mês</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Pagamentos por Tipo
                    </div>
                    <div class="card-body">
                        <canvas id="tipoChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($pagamentos_por_tipo as $tipo): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo ucfirst($tipo['tipo_pagamento']); ?></span>
                                <span class="fw-bold"><?php echo number_format($tipo['total'], 2, ',', '.'); ?> Kz</span>
                                <span class="text-muted">(<?php echo $tipo['quantidade']; ?>x)</span>
                            </div>
                            <div class="progress mb-2" style="height: 5px;">
                                <div class="progress-bar bg-success" style="width: <?php echo ($tipo['total'] / max($receitas_mes['total_receitas'], 1)) * 100; ?>%"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Formas de Pagamento
                    </div>
                    <div class="card-body">
                        <canvas id="formaChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php foreach ($formas_pagamento as $forma): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>
                                    <?php
                                    $icone = '';
                                    switch($forma['metodo_pagamento']) {
                                        case 'dinheiro': $icone = '💵'; break;
                                        case 'transferencia': $icone = '🏦'; break;
                                        case 'multicaixa': $icone = '💳'; break;
                                        case 'deposito': $icone = '💰'; break;
                                        case 'cheque': $icone = '📄'; break;
                                        default: $icone = '💵';
                                    }
                                    echo $icone . ' ' . ucfirst($forma['metodo_pagamento']);
                                    ?>
                                </span>
                                <span class="fw-bold"><?php echo number_format($forma['total'], 2, ',', '.'); ?> Kz</span>
                                <span class="text-muted">(<?php echo $forma['quantidade']; ?>x)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-bolt"></i> Ações Rápidas
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="quick-action" onclick="window.location.href='../pagamentos.php'">
                                    <i class="fas fa-credit-card"></i>
                                    <span>Novo Pagamento</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="quick-action" onclick="window.location.href='../mensalidades.php'">
                                    <i class="fas fa-calendar-dollar"></i>
                                    <span>Gerar Mensalidades</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="quick-action" onclick="window.location.href='../dividas.php'">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Ver Dívidas</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="quick-action" onclick="window.location.href='../relatorios_financeiros.php'">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Relatórios</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Últimos Pagamentos -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history"></i> Últimos Pagamentos
                    </div>
                    <div class="card-body">
                        <?php if (empty($ultimos_pagamentos)): ?>
                            <div class="alert alert-info text-center">Nenhum pagamento registrado ainda.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr><th>Data</th><th>Aluno</th><th>Tipo</th><th>Valor</th><th>Forma</th><th>Fatura</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ultimos_pagamentos as $pg): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($pg['aluno_nome']); ?></strong></td>
                                            <td><?php echo ucfirst($pg['tipo_pagamento']); ?></td>
                                            <td class="text-success fw-bold"><?php echo number_format($pg['valor'], 2, ',', '.'); ?> Kz</td>
                                            <td><?php echo ucfirst($pg['metodo_pagamento']); ?></td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($pg['numero_fatura'] ?? '-'); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de Tipos
        const tipoCtx = document.getElementById('tipoChart').getContext('2d');
        const tipoLabels = <?php echo json_encode(array_column($pagamentos_por_tipo, 'tipo_pagamento')); ?>;
        const tipoData = <?php echo json_encode(array_column($pagamentos_por_tipo, 'total')); ?>;
        
        new Chart(tipoCtx, {
            type: 'doughnut',
            data: {
                labels: tipoLabels.map(t => t === 'mensalidade' ? 'Mensalidade' : t === 'matricula' ? 'Matrícula' : t === 'certificado' ? 'Certificado' : t === 'material' ? 'Material' : 'Outro'),
                datasets: [{
                    data: tipoData,
                    backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de Formas de Pagamento
        const formaCtx = document.getElementById('formaChart').getContext('2d');
        const formaLabels = <?php echo json_encode(array_column($formas_pagamento, 'metodo_pagamento')); ?>;
        const formaData = <?php echo json_encode(array_column($formas_pagamento, 'total')); ?>;
        
        new Chart(formaCtx, {
            type: 'pie',
            data: {
                labels: formaLabels,
                datasets: [{
                    data: formaData,
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    </script>
</body>
</html>