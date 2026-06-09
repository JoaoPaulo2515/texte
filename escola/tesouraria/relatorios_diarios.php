<?php
// escola/tesouraria/relatorios_diarios.php - Relatórios Diários da Tesouraria

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
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$tipo_relatorio = isset($_GET['tipo']) ? $_GET['tipo'] : 'resumo';

// ============================================
// BUSCAR DADOS DO PERÍODO
// ============================================

// 1. Resumo Geral do Período
$sql_resumo = "SELECT 
                COUNT(DISTINCT DATE(data_movimento)) as dias_trabalhados,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as total_saidas,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as saldo_periodo
              FROM caixa 
              WHERE escola_id = ? 
              AND status = 'ativo'
              AND DATE(data_movimento) BETWEEN ? AND ?";
$stmt_resumo = $conn->prepare($sql_resumo);
$stmt_resumo->execute([$escola_id, $data_inicio, $data_fim]);
$resumo = $stmt_resumo->fetch(PDO::FETCH_ASSOC);

// 2. Receitas por Dia
$sql_receitas_diarias = "SELECT 
                          DATE(data_movimento) as data,
                          COALESCE(SUM(valor), 0) as total,
                          COUNT(*) as quantidade
                        FROM caixa 
                        WHERE escola_id = ? 
                        AND tipo = 'entrada'
                        AND status = 'ativo'
                        AND DATE(data_movimento) BETWEEN ? AND ?
                        GROUP BY DATE(data_movimento)
                        ORDER BY data DESC";
$stmt_receitas_diarias = $conn->prepare($sql_receitas_diarias);
$stmt_receitas_diarias->execute([$escola_id, $data_inicio, $data_fim]);
$receitas_diarias = $stmt_receitas_diarias->fetchAll(PDO::FETCH_ASSOC);

// 3. Despesas por Dia
$sql_despesas_diarias = "SELECT 
                          DATE(data_movimento) as data,
                          COALESCE(SUM(valor), 0) as total,
                          COUNT(*) as quantidade
                        FROM caixa 
                        WHERE escola_id = ? 
                        AND tipo = 'saida'
                        AND status = 'ativo'
                        AND DATE(data_movimento) BETWEEN ? AND ?
                        GROUP BY DATE(data_movimento)
                        ORDER BY data DESC";
$stmt_despesas_diarias = $conn->prepare($sql_despesas_diarias);
$stmt_despesas_diarias->execute([$escola_id, $data_inicio, $data_fim]);
$despesas_diarias = $stmt_despesas_diarias->fetchAll(PDO::FETCH_ASSOC);

// 4. Pagamentos por Dia (da tabela pagamentos)
$sql_pagamentos_diarios = "SELECT 
                            DATE(data_pagamento) as data,
                            COALESCE(SUM(valor), 0) as total,
                            COUNT(*) as quantidade,
                            COUNT(DISTINCT assinatura_id) as alunos_atendidos
                          FROM pagamentos 
                          WHERE escola_id = ? 
                          AND status = 'confirmado'
                          AND DATE(data_pagamento) BETWEEN ? AND ?
                          GROUP BY DATE(data_pagamento)
                          ORDER BY data DESC";
$stmt_pagamentos_diarios = $conn->prepare($sql_pagamentos_diarios);
$stmt_pagamentos_diarios->execute([$escola_id, $data_inicio, $data_fim]);
$pagamentos_diarios = $stmt_pagamentos_diarios->fetchAll(PDO::FETCH_ASSOC);

// 5. Top Formas de Pagamento no Período
$sql_formas_pagamento = "SELECT 
                          metodo_pagamento,
                          COALESCE(SUM(valor), 0) as total,
                          COUNT(*) as quantidade,
                          ROUND(COALESCE(SUM(valor), 0) * 100 / NULLIF((SELECT COALESCE(SUM(valor), 0) FROM caixa WHERE escola_id = ? AND tipo = 'entrada' AND status = 'ativo' AND DATE(data_movimento) BETWEEN ? AND ?), 0), 1) as percentual
                        FROM caixa 
                        WHERE escola_id = ? 
                        AND tipo = 'entrada'
                        AND status = 'ativo'
                        AND DATE(data_movimento) BETWEEN ? AND ?
                        GROUP BY metodo_pagamento
                        ORDER BY total DESC";
$stmt_formas_pagamento = $conn->prepare($sql_formas_pagamento);
$stmt_formas_pagamento->execute([$escola_id, $data_inicio, $data_fim, $escola_id, $data_inicio, $data_fim]);
$formas_pagamento = $stmt_formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// 6. Melhor Dia (maior arrecadação)
$sql_melhor_dia = "SELECT 
                    DATE(data_pagamento) as data,
                    COALESCE(SUM(valor), 0) as total,
                    COUNT(*) as quantidade
                  FROM pagamentos 
                  WHERE escola_id = ? 
                  AND status = 'confirmado'
                  AND DATE(data_pagamento) BETWEEN ? AND ?
                  GROUP BY DATE(data_pagamento)
                  ORDER BY total DESC
                  LIMIT 1";
$stmt_melhor_dia = $conn->prepare($sql_melhor_dia);
$stmt_melhor_dia->execute([$escola_id, $data_inicio, $data_fim]);
$melhor_dia = $stmt_melhor_dia->fetch(PDO::FETCH_ASSOC);

// 7. Ticket Médio Diário
$sql_ticket_medio = "SELECT 
                      ROUND(AVG(diario.total), 2) as ticket_medio
                    FROM (
                      SELECT DATE(data_pagamento) as data, SUM(valor) as total
                      FROM pagamentos 
                      WHERE escola_id = ? 
                      AND status = 'confirmado'
                      AND DATE(data_pagamento) BETWEEN ? AND ?
                      GROUP BY DATE(data_pagamento)
                    ) as diario";
$stmt_ticket_medio = $conn->prepare($sql_ticket_medio);
$stmt_ticket_medio->execute([$escola_id, $data_inicio, $data_fim]);
$ticket_medio_diario = $stmt_ticket_medio->fetch(PDO::FETCH_ASSOC)['ticket_medio'] ?? 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getDiaSemana($data) {
    $dias = [
        'Sunday' => 'Domingo', 'Monday' => 'Segunda', 'Tuesday' => 'Terça',
        'Wednesday' => 'Quarta', 'Thursday' => 'Quinta', 'Friday' => 'Sexta', 'Saturday' => 'Sábado'
    ];
    $nome_ingles = date('l', strtotime($data));
    return $dias[$nome_ingles] ?? $nome_ingles;
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Diários | Tesouraria | SIGE Angola</title>
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
        
        .table-responsive { overflow-x: auto; }
        .valor-positivo { color: #28a745; font-weight: bold; }
        .valor-negativo { color: #dc3545; font-weight: bold; }
        
        .print-btn { position: fixed; bottom: 20px; right: 20px; z-index: 1000; }
        
        @media print {
            body { background: white; }
            .main-content { margin-left: 0; }
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .stat-card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-line"></i> Relatórios Diários</h2>
                <p class="text-muted">Análise detalhada das movimentações financeiras diárias</p>
            </div>
            <div>
                <button class="btn btn-primary no-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="index.php" class="btn-voltar ms-2 no-print">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
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
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($resumo['total_entradas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up"></i> Total de Receitas</div>
                    <small><?php echo date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim)); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($resumo['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down"></i> Total de Despesas</div>
                    <small><?php echo date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim)); ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?php echo ($resumo['saldo_periodo'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($resumo['saldo_periodo'] ?? 0); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Período</div>
                    <small><?php echo ($resumo['saldo_periodo'] ?? 0) >= 0 ? 'Superavit' : 'Deficit'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $resumo['dias_trabalhados'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-day"></i> Dias com Movimentação</div>
                    <small>No período selecionado</small>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($ticket_medio_diario, 2, ',', '.'); ?> Kz</div>
                    <div class="stat-label"><i class="fas fa-ticket-alt"></i> Ticket Médio Diário</div>
                    <small>Média de arrecadação por dia</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo !empty($melhor_dia) ? date('d/m/Y', strtotime($melhor_dia['data'])) : '-'; ?></div>
                    <div class="stat-label"><i class="fas fa-trophy"></i> Melhor Dia</div>
                    <small>Maior arrecadação: <?php echo !empty($melhor_dia) ? formatarMoeda($melhor_dia['total']) : '-'; ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format(array_sum(array_column($pagamentos_diarios, 'alunos_atendidos')), 0); ?></div>
                    <div class="stat-label"><i class="fas fa-users"></i> Total de Alunos Atendidos</div>
                    <small>No período selecionado</small>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Receitas Diárias -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Receitas e Despesas por Dia</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Dia da Semana</th>
                                <th class="text-end">Receitas (Kz)</th>
                                <th class="text-end">Despesas (Kz)</th>
                                <th class="text-end">Saldo (Kz)</th>
                                <th class="text-center">Qtd Receitas</th>
                                <th class="text-center">Qtd Despesas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Combinar receitas e despesas
                            $dados_diarios = [];
                            foreach ($receitas_diarias as $r) {
                                $dados_diarios[$r['data']]['receitas'] = $r['total'];
                                $dados_diarios[$r['data']]['qtd_receitas'] = $r['quantidade'];
                            }
                            foreach ($despesas_diarias as $d) {
                                $dados_diarios[$d['data']]['despesas'] = $d['total'];
                                $dados_diarios[$d['data']]['qtd_despesas'] = $d['quantidade'];
                            }
                            
                            // Ordenar por data
                            ksort($dados_diarios);
                            
                            if (empty($dados_diarios)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Nenhum dado encontrado no período</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dados_diarios as $data => $valores): 
                                    $receitas = $valores['receitas'] ?? 0;
                                    $despesas = $valores['despesas'] ?? 0;
                                    $saldo = $receitas - $despesas;
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($data)); ?></small></td>
                                    <td><?php echo getDiaSemana($data); ?></small></td>
                                    <td class="text-end text-success fw-bold"><?php echo formatarMoeda($receitas); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($despesas); ?></td>
                                    <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        <?php echo formatarMoeda($saldo); ?>
                                    </td>
                                    <td class="text-center"><?php echo $valores['qtd_receitas'] ?? 0; ?></small></td>
                                    <td class="text-center"><?php echo $valores['qtd_despesas'] ?? 0; ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="2" class="text-end">TOTAIS:</td>
                                <td class="text-end text-success"><?php echo formatarMoeda(array_sum(array_column($receitas_diarias, 'total'))); ?></td>
                                <td class="text-end text-danger"><?php echo formatarMoeda(array_sum(array_column($despesas_diarias, 'total'))); ?></td>
                                <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($receitas_diarias, 'total')) - array_sum(array_column($despesas_diarias, 'total'))); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Pagamentos Diários -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-credit-card"></i> Pagamentos Registrados por Dia</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Dia da Semana</th>
                                <th class="text-end">Valor Total (Kz)</th>
                                <th class="text-center">Qtd Pagamentos</th>
                                <th class="text-center">Alunos Atendidos</th>
                                <th class="text-end">Ticket Médio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pagamentos_diarios)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Nenhum pagamento encontrado no período</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pagamentos_diarios as $pg): 
                                    $ticket_medio = $pg['quantidade'] > 0 ? $pg['total'] / $pg['quantidade'] : 0;
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pg['data'])); ?></small></td>
                                    <td><?php echo getDiaSemana($pg['data']); ?></small></td>
                                    <td class="text-end text-success fw-bold"><?php echo formatarMoeda($pg['total']); ?></td>
                                    <td class="text-center"><?php echo $pg['quantidade']; ?></small></td>
                                    <td class="text-center"><?php echo $pg['alunos_atendidos']; ?></small></td>
                                    <td class="text-end"><?php echo formatarMoeda($ticket_medio); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="2" class="text-end">TOTAIS:</td>
                                <td class="text-end text-success"><?php echo formatarMoeda(array_sum(array_column($pagamentos_diarios, 'total'))); ?></td>
                                <td class="text-center"><?php echo array_sum(array_column($pagamentos_diarios, 'quantidade')); ?></small></td>
                                <td class="text-center"><?php echo array_sum(array_column($pagamentos_diarios, 'alunos_atendidos')); ?></small></td>
                                <td class="text-end">-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Formas de Pagamento -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Formas de Pagamento</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <canvas id="formasPagamentoChart" height="250"></canvas>
                    </div>
                    <div class="col-md-6">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Forma de Pagamento</th>
                                        <th class="text-end">Valor</th>
                                        <th class="text-center">Qtd</th>
                                        <th class="text-center">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_formas = array_sum(array_column($formas_pagamento, 'total'));
                                    foreach ($formas_pagamento as $forma): 
                                    ?>
                                    <tr>
                                        <td>
                                            <?php
                                            switch($forma['metodo_pagamento']) {
                                                case 'dinheiro': echo '<i class="fas fa-money-bill-wave text-success"></i> Dinheiro'; break;
                                                case 'transferencia': echo '<i class="fas fa-university text-primary"></i> Transferência'; break;
                                                case 'deposito': echo '<i class="fas fa-money-bill text-info"></i> Depósito'; break;
                                                case 'cheque': echo '<i class="fas fa-check-circle text-warning"></i> Cheque'; break;
                                                case 'multicaixa': echo '<i class="fas fa-credit-card text-secondary"></i> Multicaixa'; break;
                                                default: echo ucfirst($forma['metodo_pagamento']);
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end text-success"><?php echo formatarMoeda($forma['total']); ?></td>
                                        <td class="text-center"><?php echo $forma['quantidade']; ?></small></td>
                                        <td class="text-center">
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $forma['percentual']; ?>%"></div>
                                            </div>
                                            <small><?php echo $forma['percentual']; ?>%</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo Executivo -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Resumo Executivo</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Análise do Período:</strong><br>
                            <?php 
                            $total_receitas = $resumo['total_entradas'] ?? 0;
                            $total_despesas = $resumo['total_saidas'] ?? 0;
                            $total_pagamentos = array_sum(array_column($pagamentos_diarios, 'total'));
                            $total_alunos = array_sum(array_column($pagamentos_diarios, 'alunos_atendidos'));
                            $total_transacoes = array_sum(array_column($pagamentos_diarios, 'quantidade'));
                            ?>
                            <ul class="mt-2 mb-0">
                                <li>Foram realizadas <strong><?php echo number_format($total_transacoes); ?></strong> transações financeiras.</li>
                                <li><strong><?php echo number_format($total_alunos); ?></strong> alunos foram atendidos no período.</li>
                                <li>A média diária de arrecadação foi de <strong><?php echo formatarMoeda($ticket_medio_diario); ?></strong>.</li>
                                <li>O melhor dia de arrecadação foi <strong><?php echo !empty($melhor_dia) ? date('d/m/Y', strtotime($melhor_dia['data'])) . ' com ' . formatarMoeda($melhor_dia['total']) : '-'; ?></strong>.</li>
                                <li>O saldo acumulado no período é de <strong class="<?php echo ($resumo['saldo_periodo'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatarMoeda($resumo['saldo_periodo'] ?? 0); ?></strong>.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-warning">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Recomendações:</strong><br>
                            <ul class="mt-2 mb-0">
                                <?php if ($total_receitas > 0 && $total_despesas > 0): ?>
                                <li>A relação Receitas/Despesas é de <strong><?php echo round(($total_receitas / $total_despesas) * 100, 1); ?>%</strong>.</li>
                                <?php endif; ?>
                                <?php if ($ticket_medio_diario < 10000): ?>
                                <li>Considere estratégias para aumentar o ticket médio diário.</li>
                                <?php endif; ?>
                                <?php if (!empty($formas_pagamento) && $formas_pagamento[0]['metodo_pagamento'] == 'dinheiro'): ?>
                                <li>Incentive o uso de transferências bancárias para reduzir o manuseio de dinheiro.</li>
                                <?php endif; ?>
                                <li>Mantenha o controle de inadimplência em dia.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Gráfico de Formas de Pagamento
        const formasLabels = <?php 
            $labels = [];
            foreach ($formas_pagamento as $f) {
                $labels[] = ucfirst($f['metodo_pagamento']);
            }
            echo json_encode($labels);
        ?>;
        
        const formasData = <?php echo json_encode(array_column($formas_pagamento, 'total')); ?>;
        
        new Chart(document.getElementById('formasPagamentoChart'), {
            type: 'pie',
            data: {
                labels: formasLabels,
                datasets: [{
                    data: formasData,
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { 
                        callbacks: { 
                            label: function(context) { 
                                return context.label + ': ' + context.raw.toLocaleString('pt-AO') + ' Kz'; 
                            } 
                        } 
                    }
                }
            }
        });
    </script>
</body>
</html>