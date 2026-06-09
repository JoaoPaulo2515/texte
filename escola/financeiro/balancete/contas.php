<?php
// escola/financeiro/balancete/contas.php - Balancete por Conta
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

$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$tipo_conta = $_GET['tipo'] ?? '';
$conta_id = $_GET['conta_id'] ?? '';

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar plano de contas
$sql_contas = "SELECT * FROM escola_plano_contas WHERE escola_id = :escola_id AND status = 'ativo'";
$params = [':escola_id' => $escola_id];

if ($tipo_conta) {
    $sql_contas .= " AND tipo = :tipo";
    $params[':tipo'] = $tipo_conta;
}

$sql_contas .= " ORDER BY codigo";
$stmt = $conn->prepare($sql_contas);
$stmt->execute($params);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar movimentações por conta
$movimentacoes = [];
foreach ($contas as $conta) {
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
        FROM escola_fluxo_caixa
        WHERE escola_id = :escola_id AND categoria = :categoria AND status = 'confirmado'
            AND data_movimento BETWEEN :data_inicio AND :data_fim
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':categoria' => $conta['categoria'],
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim
    ]);
    $mov = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $movimentacoes[$conta['id']] = [
        'entradas' => $mov['entradas'] ?? 0,
        'saidas' => $mov['saidas'] ?? 0,
        'saldo' => ($mov['entradas'] ?? 0) - ($mov['saidas'] ?? 0)
    ];
}

// Se uma conta específica foi selecionada, buscar detalhes
$detalhes_conta = null;
$lancamentos = [];
if ($conta_id) {
    foreach ($contas as $c) {
        if ($c['id'] == $conta_id) {
            $detalhes_conta = $c;
            break;
        }
    }
    
    if ($detalhes_conta) {
        $stmt = $conn->prepare("
            SELECT f.*, fp.nome as forma_pagamento_nome
            FROM escola_fluxo_caixa f
            LEFT JOIN escola_formas_pagamento fp ON fp.id = f.forma_pagamento_id
            WHERE f.escola_id = :escola_id AND f.categoria = :categoria AND f.status = 'confirmado'
                AND f.data_movimento BETWEEN :data_inicio AND :data_fim
            ORDER BY f.data_movimento DESC
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':categoria' => $detalhes_conta['categoria'],
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$tipos_nomes = [
    'ativo' => 'Ativo',
    'passivo' => 'Passivo',
    'receita' => 'Receita',
    'despesa' => 'Despesa',
    'patrimonio' => 'Patrimônio Líquido'
];

$categorias_nomes = [
    'mensalidade' => 'Mensalidades',
    'matricula' => 'Matrículas',
    'taxa' => 'Taxas',
    'doacao' => 'Doações',
    'salario' => 'Salários',
    'material' => 'Material Escolar',
    'utilidade' => 'Utilidades',
    'manutencao' => 'Manutenção',
    'imposto' => 'Impostos',
    'outro_entrada' => 'Outras Entradas',
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
    <title>Balancete por Conta | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .badge-ativo { background: #28a745; color: white; }
        .badge-passivo { background: #dc3545; color: white; }
        .badge-receita { background: #17a2b8; color: white; }
        .badge-despesa { background: #fd7e14; color: white; }
        
        .saldo-positivo { color: #28a745; font-weight: bold; }
        .saldo-negativo { color: #dc3545; font-weight: bold; }
        
        .conta-selecionada { background: #e8f5e9; border-left: 4px solid #28a745; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
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
                    <li class="nav-item"><a href="../fluxo_caixa/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
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
            <h2>
                <i class="fas fa-book"></i> Balancete por Conta
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-chart-simple"></i> Balancete Geral
                </a>
                <a href="comparativo.php" class="btn btn-info btn-sm">
                    <i class="fas fa-chart-line"></i> Comparativo
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-4">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-4">
                    <label>Tipo de Conta</label>
                    <select name="tipo" class="form-control">
                        <option value="">Todos</option>
                        <option value="ativo" <?php echo $tipo_conta == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="passivo" <?php echo $tipo_conta == 'passivo' ? 'selected' : ''; ?>>Passivo</option>
                        <option value="receita" <?php echo $tipo_conta == 'receita' ? 'selected' : ''; ?>>Receita</option>
                        <option value="despesa" <?php echo $tipo_conta == 'despesa' ? 'selected' : ''; ?>>Despesa</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-list"></i> Plano de Contas</div>
                    <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Código</th>
                                    <th>Conta</th>
                                    <th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contas as $conta): 
                                    $saldo = $movimentacoes[$conta['id']]['saldo'];
                                ?>
                                <tr class="<?php echo $conta_id == $conta['id'] ? 'conta-selecionada' : ''; ?>">
                                    <td><a href="?conta_id=<?php echo $conta['id']; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>"><?php echo $conta['codigo']; ?></a></td>
                                    <td><a href="?conta_id=<?php echo $conta['id']; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>"><?php echo htmlspecialchars($conta['nome']); ?></a></td>
                                    <td class="text-end <?php echo $saldo >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>">
                                        <?php echo number_format($saldo, 2, ',', '.'); ?> Kz
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($detalhes_conta): ?>
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-chart-line"></i> Detalhes da Conta: <?php echo htmlspecialchars($detalhes_conta['nome']); ?>
                        <span class="badge bg-light text-dark ms-2"><?php echo $tipos_nomes[$detalhes_conta['tipo']]; ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="alert alert-success text-center">
                                    <strong>Entradas</strong><br>
                                    <?php echo number_format($movimentacoes[$detalhes_conta['id']]['entradas'], 2, ',', '.'); ?> Kz
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-danger text-center">
                                    <strong>Saídas</strong><br>
                                    <?php echo number_format($movimentacoes[$detalhes_conta['id']]['saidas'], 2, ',', '.'); ?> Kz
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert <?php echo $movimentacoes[$detalhes_conta['id']]['saldo'] >= 0 ? 'alert-success' : 'alert-danger'; ?> text-center">
                                    <strong>Saldo</strong><br>
                                    <?php echo number_format($movimentacoes[$detalhes_conta['id']]['saldo'], 2, ',', '.'); ?> Kz
                                </div>
                            </div>
                        </div>
                        
                        <h6><i class="fas fa-list"></i> Lançamentos do Período</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered" id="tabelaLancamentos">
                                <thead class="table-light">
                                    <tr>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th>Descrição</th>
                                        <th>Valor</th>
                                        <th>Forma</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lancamentos as $lanc): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($lanc['data_movimento'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $lanc['tipo'] == 'entrada' ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo ucfirst($lanc['tipo']); ?>
                                            </span>
                                         </div>
                                        <td><?php echo htmlspecialchars($lanc['descricao']); ?></div>
                                        <td>
                                            <span class="<?php echo $lanc['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo number_format($lanc['valor'], 2, ',', '.'); ?> Kz
                                            </span>
                                         </div>
                                        <td><?php echo $lanc['forma_pagamento_nome'] ?? '-'; ?></div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-hand-point-left fa-3x text-muted mb-3"></i>
                        <h5>Selecione uma conta no menu ao lado</h5>
                        <p class="text-muted">Clique em qualquer conta para visualizar os detalhes e lançamentos</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre o Balancete por Conta</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é o Balancete por Conta?</h6>
                    <p>O balancete por conta permite visualizar o saldo e as movimentações de cada conta do plano de contas individualmente.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Como usar:</h6>
                    <ul>
                        <li><strong>Plano de Contas:</strong> Lista todas as contas cadastradas com seus respectivos saldos.</li>
                        <li><strong>Clique em uma conta:</strong> Para ver os detalhes e todos os lançamentos daquela conta.</li>
                        <li><strong>Filtros:</strong> Utilize os filtros de data e tipo de conta para refinar a análise.</li>
                    </ul>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Tipos de Conta:</h6>
                    <ul>
                        <li><strong class="text-success">Ativo:</strong> Bens e direitos da escola (Caixa, Bancos, Contas a Receber).</li>
                        <li><strong class="text-danger">Passivo:</strong> Obrigações e dívidas (Contas a Pagar, Salários).</li>
                        <li><strong class="text-info">Receita:</strong> Ganhos e entradas (Mensalidades, Matrículas).</li>
                        <li><strong class="text-warning">Despesa:</strong> Gastos e saídas (Salários, Material).</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Contas com saldo negativo indicam déficit ou pendências.</li>
                        <li>Compare o saldo de contas similares para identificar desvios.</li>
                        <li>Utilize os lançamentos detalhados para auditoria.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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
        
        $('#tabelaLancamentos').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>