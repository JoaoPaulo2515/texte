<?php
// escola/financeiro/extratos.php - Extratos Financeiros (CORRIGIDO)
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

// ============================================
// PROCESSAR FILTROS
// ============================================

$aluno_id = $_GET['aluno_id'] ?? 0;
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$tipo_extrato = $_GET['tipo'] ?? 'todas';

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================

$aluno_selecionado = null;
if ($aluno_id) {
    $stmt = $conn->prepare("
        SELECT e.*, u.nome, u.email, u.telefone, t.nome as turma_nome
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
        LEFT JOIN turmas t ON t.id = m.turma_id
        WHERE e.id = :aluno_id AND e.escola_id = :escola_id
    ");
    $stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
    $aluno_selecionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR EXTRATO
// ============================================

$extrato = [];

// Buscar mensalidades
if ($tipo_extrato == 'todas' || $tipo_extrato == 'mensalidades') {
    $sql_mensalidades = "
        SELECT 
            m.id,
            'mensalidade' as tipo,
            m.mes,
            m.ano,
            m.valor_original as valor,
            m.valor_pago as valor_pago,
            m.data_vencimento as data,
            m.data_pagamento,
            m.status,
            u.nome as aluno_nome,
            e.matricula,
            f.nome as forma_pagamento
        FROM escola_mensalidades m
        JOIN estudantes e ON e.id = m.aluno_id
        JOIN usuarios u ON u.id = e.usuario_id
        LEFT JOIN escola_formas_pagamento f ON f.id = m.forma_pagamento_id
        WHERE m.escola_id = :escola_id
    ";
    
    $params = [':escola_id' => $escola_id];
    
    if ($aluno_id) {
        $sql_mensalidades .= " AND m.aluno_id = :aluno_id";
        $params[':aluno_id'] = $aluno_id;
    }
    
    $sql_mensalidades .= " AND DATE(m.data_vencimento) BETWEEN :data_inicio AND :data_fim
                          ORDER BY m.data_vencimento DESC";
    
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
    
    $stmt = $conn->prepare($sql_mensalidades);
    $stmt->execute($params);
    $mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mensalidades as $m) {
        $extrato[] = $m;
    }
}

// Buscar pagamentos
if ($tipo_extrato == 'todas' || $tipo_extrato == 'pagamentos') {
    $sql_pagamentos = "
        SELECT 
            t.id,
            'transacao' as tipo,
            t.tipo as tipo_transacao,
            t.valor,
            t.descricao,
            t.data_transacao as data,
            t.categoria,
            c.banco,
            c.numero_conta
        FROM escola_transacoes_bancarias t
        JOIN escola_contas_bancarias c ON c.id = t.conta_id
        WHERE t.escola_id = :escola_id AND t.status = 'confirmado'
    ";
    
    $params = [':escola_id' => $escola_id];
    
    if ($aluno_id) {
        $sql_pagamentos .= " AND t.descricao LIKE :aluno_busca";
        $params[':aluno_busca'] = "%Aluno ID: {$aluno_id}%";
    }
    
    $sql_pagamentos .= " AND DATE(t.data_transacao) BETWEEN :data_inicio AND :data_fim
                        ORDER BY t.data_transacao DESC";
    
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
    
    $stmt = $conn->prepare($sql_pagamentos);
    $stmt->execute($params);
    $pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pagamentos as $p) {
        $extrato[] = $p;
    }
}

// Ordenar extrato por data
usort($extrato, function($a, $b) {
    return strtotime($b['data']) - strtotime($a['data']);
});

// ============================================
// CALCULAR RESUMO
// ============================================

$resumo = [
    'total_entradas' => 0,
    'total_saidas' => 0,
    'total_pendente' => 0,
    'total_pago' => 0,
    'quantidade_mensalidades' => 0,
    'quantidade_pagas' => 0,
    'quantidade_pendentes' => 0,
    'quantidade_vencidas' => 0
];

foreach ($extrato as $item) {
    if ($item['tipo'] == 'mensalidade') {
        $resumo['quantidade_mensalidades']++;
        $resumo['total_entradas'] += $item['valor'];
        $resumo['total_pago'] += $item['valor_pago'];
        
        if ($item['status'] == 'pago') {
            $resumo['quantidade_pagas']++;
        } elseif ($item['status'] == 'pendente') {
            $resumo['quantidade_pendentes']++;
            $resumo['total_pendente'] += ($item['valor'] - $item['valor_pago']);
        } elseif ($item['status'] == 'vencido') {
            $resumo['quantidade_vencidas']++;
            $resumo['total_pendente'] += ($item['valor'] - $item['valor_pago']);
        }
    } elseif ($item['tipo'] == 'transacao') {
        if ($item['tipo_transacao'] == 'credito' || $item['tipo_transacao'] == 'transferencia_recebida') {
            $resumo['total_entradas'] += $item['valor'];
        } else {
            $resumo['total_saidas'] += $item['valor'];
        }
    }
}

$resumo['saldo'] = $resumo['total_entradas'] - $resumo['total_saidas'];
$resumo['total_recebido'] = $resumo['total_pago'];

// ============================================
// BUSCAR ALUNOS PARA SELECT
// ============================================

$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extratos Financeiros | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .badge-entrada { background: #28a745; color: white; }
        .badge-saida { background: #dc3545; color: white; }
        .badge-pago { background: #28a745; color: white; }
        .badge-pendente { background: #ffc107; color: #000; }
        .badge-vencido { background: #dc3545; color: white; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .resumo-aluno {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .btn-export { background: #17a2b8; color: white; }
        .btn-export:hover { background: #138496; color: white; }
        
        .chart-container { position: relative; height: 300px; width: 100%; }
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
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
           <!-- FINANCEIRO -->
<li class="nav-item has-submenu" id="menuFinanceiro">
    <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
        <i class="fas fa-coins"></i>
        <span>Financeiro</span>
    </a>
    <ul class="nav-submenu" id="submenuFinanceiro">
        <!-- Dashboard Financeiro -->
        <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
        
        <!-- Contas a Receber/Pagar -->
        <li class="nav-item"><a href="contas_receber/index.php" class="nav-link"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
        <li class="nav-item"><a href="contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
        
        <!-- Gestão de Pagamentos -->
        <li class="nav-item"><a href="mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
        <li class="nav-item"><a href="parcelamentos/index.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
        <li class="nav-item"><a href="inadimplencia/index.php" class="nav-link"><i class="fas fa-exclamation-triangle"></i> Inadimplência</a></li>
        
        <!-- Fluxo de Caixa -->
        <li class="nav-item"><a href="fluxo_caixa/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
        <li class="nav-item"><a href="balancete/index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
        
        <!-- Boletos e Conciliação -->
        <li class="nav-item"><a href="boletos/index.php" class="nav-link"><i class="fas fa-barcode"></i> Boletos Bancários</a></li>
        <li class="nav-item"><a href="conciliacao/index.php" class="nav-link"><i class="fas fa-handshake"></i> Conciliação Bancária</a></li>
        
        <!-- Relatórios -->
        <li class="nav-item"><a href="relatorios_financeiros/index.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatórios Financeiros</a></li>
        <li class="nav-item"><a href="relatorios_personalizados/index.php" class="nav-link"><i class="fas fa-chart-bar"></i> Relatórios Personalizados</a></li>
        
        <!-- Extratos e Recibos -->
        <li class="nav-item"><a href="extratos.php" class="nav-link"><i class="fas fa-file-invoice"></i> Extratos</a></li>
        <li class="nav-item"><a href="recibos.php" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
        
        <!-- Configurações -->
        <li class="nav-item"><a href="configuracoes/index.php" class="nav-link"><i class="fas fa-cog"></i> Configurações Financeiras</a></li>
    </ul>
</li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-invoice"></i> Extratos Financeiros</h2>
            <div>
                <a href="?exportar=pdf&aluno_id=<?php echo $aluno_id; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>&tipo=<?php echo $tipo_extrato; ?>" class="btn btn-export btn-sm" target="_blank">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </a>
                <button class="btn btn-secondary btn-sm ms-2" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Aluno</label>
                    <select name="aluno_id" class="form-control">
                        <option value="">Todos os alunos</option>
                        <?php foreach ($alunos as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo $aluno_id == $a['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-2">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-3">
                    <label>Tipo de Extrato</label>
                    <select name="tipo" class="form-control">
                        <option value="todas" <?php echo $tipo_extrato == 'todas' ? 'selected' : ''; ?>>Todas as movimentações</option>
                        <option value="mensalidades" <?php echo $tipo_extrato == 'mensalidades' ? 'selected' : ''; ?>>Apenas Mensalidades</option>
                        <option value="pagamentos" <?php echo $tipo_extrato == 'pagamentos' ? 'selected' : ''; ?>>Apenas Pagamentos</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Resumo do Aluno (se selecionado) -->
        <?php if ($aluno_selecionado): ?>
        <div class="resumo-aluno">
            <div class="row">
                <div class="col-md-4">
                    <h4><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($aluno_selecionado['nome']); ?></h4>
                    <p class="mb-0">Matrícula: <?php echo $aluno_selecionado['matricula']; ?></p>
                    <p class="mb-0">Turma: <?php echo $aluno_selecionado['turma_nome'] ?? 'Não atribuída'; ?></p>
                </div>
                <div class="col-md-4">
                    <p class="mb-0"><strong>📧 E-mail:</strong> <?php echo $aluno_selecionado['email']; ?></p>
                    <p class="mb-0"><strong>📞 Telefone:</strong> <?php echo $aluno_selecionado['telefone'] ?? 'Não informado'; ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <h3><?php echo number_format($resumo['total_pago'], 2, ',', '.'); ?> Kz</h3>
                    <p class="mb-0">Total Pago</p>
                    <p class="mb-0 text-warning">Pendente: <?php echo number_format($resumo['total_pendente'], 2, ',', '.'); ?> Kz</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($resumo['total_entradas'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Entradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($resumo['total_saidas'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Saídas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo $resumo['saldo'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($resumo['saldo'], 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo do Período</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $resumo['quantidade_mensalidades']; ?></div>
                <div class="stat-label">Total de Mensalidades</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $resumo['quantidade_pagas']; ?></div>
                <div class="stat-label">Mensalidades Pagas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $resumo['quantidade_pendentes'] + $resumo['quantidade_vencidas']; ?></div>
                <div class="stat-label">Mensalidades Pendentes</div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição de Pagamentos</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Valores por Mês</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="mensalChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Extrato - CORRIGIDA -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Movimentações Financeiras</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaExtrato">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Valor Pago</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($extrato)): ?>
                                <?php 
                                $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                                foreach ($extrato as $item): 
                                ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($item['data'])); ?></td>
                                    <td>
                                        <?php if ($item['tipo'] == 'mensalidade'): ?>
                                            <span class="badge bg-primary">Mensalidade</span>
                                        <?php else: ?>
                                            <span class="badge <?php echo $item['tipo_transacao'] == 'credito' ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo ucfirst($item['tipo_transacao']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['tipo'] == 'mensalidade'): ?>
                                            <?php echo $meses[$item['mes']]; ?>/<?php echo $item['ano']; ?>
                                            <?php if ($item['aluno_nome']): ?>
                                                <br><small><?php echo htmlspecialchars($item['aluno_nome']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($item['descricao']); ?>
                                            <br><small><?php echo $item['banco']; ?> - <?php echo $item['numero_conta']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo number_format($item['valor'], 2, ',', '.'); ?> Kz</strong></td>
                                    <td>
                                        <?php if ($item['tipo'] == 'mensalidade'): ?>
                                            <?php echo number_format($item['valor_pago'], 2, ',', '.'); ?> Kz
                                        <?php else: ?>
                                            ---
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['tipo'] == 'mensalidade'): ?>
                                            <span class="badge badge-<?php echo $item['status']; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Registrado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['tipo'] == 'mensalidade' && $item['status'] != 'pago'): ?>
                                            <button class="btn btn-sm btn-success" onclick="registrarPagamento(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-money-bill"></i> Pagar
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $item['id']; ?>, '<?php echo $item['tipo']; ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                        Nenhuma movimentação encontrada no período
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
        
        // Inicialização condicional do DataTables - CORRIGIDA
        $(document).ready(function() {
            var $table = $('#tabelaExtrato');
            var hasDataRows = $table.find('tbody tr:not(:has(td[colspan]))').length > 0;
            
            if (hasDataRows) {
                try {
                    $table.DataTable({
                        language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
                        pageLength: 25,
                        order: [[0, 'desc']],
                        responsive: true
                    });
                } catch (e) {
                    console.error('Erro ao inicializar DataTables:', e);
                    $table.addClass('table-bordered');
                }
            } else {
                $table.addClass('table-bordered');
            }
        });
        
        // Gráfico de Status
        const ctx1 = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: ['Pagas', 'Pendentes', 'Vencidas'],
                datasets: [{
                    data: [<?php echo $resumo['quantidade_pagas']; ?>, <?php echo $resumo['quantidade_pendentes']; ?>, <?php echo $resumo['quantidade_vencidas']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Gráfico de Valores por Mês
        const ctx2 = document.getElementById('mensalChart').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                datasets: [
                    {
                        label: 'Valores Pagos (Kz)',
                        data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                        backgroundColor: '#28a745'
                    },
                    {
                        label: 'Valores Pendentes (Kz)',
                        data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                        backgroundColor: '#dc3545'
                    }
                ]
            },
            options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true } } }
        });
        
        function registrarPagamento(id) {
            window.location.href = 'mensalidades.php?pagar=' + id;
        }
        
        function verDetalhes(id, tipo) {
            alert('Detalhes da ' + (tipo == 'mensalidade' ? 'mensalidade' : 'transação') + ' ID: ' + id);
        }
        
        // Manter submenu aberto
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>