<?php
// escola/financeiro/balancete/index.php - Balancete Geral (CORRIGIDO - VERSÃO FINAL)
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
// VERIFICAR E CRIAR TABELA DE PLANO DE CONTAS
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'escola_plano_contas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_plano_contas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            codigo VARCHAR(20) NOT NULL,
            nome VARCHAR(100) NOT NULL,
            tipo ENUM('ativo', 'passivo', 'receita', 'despesa', 'patrimonio') NOT NULL,
            categoria VARCHAR(50),
            saldo_inicial DECIMAL(15,2) DEFAULT 0,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_codigo (codigo, escola_id)
        )
    ");
    
    // Inserir contas padrão
    $contas_padrao = [
        ['1.1.1', 'Caixa', 'ativo', 'Disponivel', 0],
        ['1.1.2', 'Bancos', 'ativo', 'Disponivel', 0],
        ['1.2.1', 'Contas a Receber', 'ativo', 'Receber', 0],
        ['2.1.1', 'Contas a Pagar', 'passivo', 'Pagar', 0],
        ['2.1.2', 'Salários a Pagar', 'passivo', 'Pagar', 0],
        ['3.1.1', 'Mensalidades', 'receita', 'Receitas', 0],
        ['3.1.2', 'Matrículas', 'receita', 'Receitas', 0],
        ['3.1.3', 'Taxas', 'receita', 'Receitas', 0],
        ['4.1.1', 'Salários', 'despesa', 'Pessoal', 0],
        ['4.1.2', 'Material Escolar', 'despesa', 'Operacional', 0],
        ['4.1.3', 'Utilidades', 'despesa', 'Operacional', 0],
        ['4.1.4', 'Manutenção', 'despesa', 'Operacional', 0],
        ['5.1.1', 'Capital Social', 'patrimonio', 'Patrimonio', 0]
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO escola_plano_contas (escola_id, codigo, nome, tipo, categoria, saldo_inicial)
        VALUES (:escola_id, :codigo, :nome, :tipo, :categoria, :saldo_inicial)
    ");
    foreach ($contas_padrao as $conta) {
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':codigo' => $conta[0],
            ':nome' => $conta[1],
            ':tipo' => $conta[2],
            ':categoria' => $conta[3],
            ':saldo_inicial' => $conta[4]
        ]);
    }
}

// ============================================
// FILTROS
// ============================================

$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$tipo_balancete = $_GET['tipo'] ?? 'completo';

// ============================================
// BUSCAR DADOS DO BALANCETE
// ============================================

// Totais de entradas e saídas do período
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento BETWEEN :data_inicio AND :data_fim
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
$totais_periodo = $stmt->fetch(PDO::FETCH_ASSOC);

// Saldo inicial (antes do período)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND status = 'confirmado'
        AND data_movimento < :data_inicio
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio]);
$saldos_anteriores = $stmt->fetch(PDO::FETCH_ASSOC);
$saldo_inicial = ($saldos_anteriores['entradas'] ?? 0) - ($saldos_anteriores['saidas'] ?? 0);

// Contas a Receber (valores pendentes)
$stmt = $conn->prepare("
    SELECT SUM(valor - valor_recebido) as total
    FROM escola_contas_receber
    WHERE escola_id = :escola_id AND status IN ('pendente', 'parcial', 'vencido')
");
$stmt->execute([':escola_id' => $escola_id]);
$contas_receber = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Contas a Pagar (valores pendentes)
$stmt = $conn->prepare("
    SELECT SUM(valor - valor_pago) as total
    FROM escola_contas_pagar
    WHERE escola_id = :escola_id AND status IN ('pendente', 'parcial', 'vencido')
");
$stmt->execute([':escola_id' => $escola_id]);
$contas_pagar = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Saldo atual
$saldo_atual = $saldo_inicial + ($totais_periodo['total_entradas'] ?? 0) - ($totais_periodo['total_saidas'] ?? 0);

// Balancete por categoria
$stmt = $conn->prepare("
    SELECT 
        f.categoria,
        SUM(CASE WHEN f.tipo = 'entrada' THEN f.valor ELSE 0 END) as entradas,
        SUM(CASE WHEN f.tipo = 'saida' THEN f.valor ELSE 0 END) as saidas
    FROM escola_fluxo_caixa f
    WHERE f.escola_id = :escola_id AND f.status = 'confirmado'
        AND f.data_movimento BETWEEN :data_inicio AND :data_fim
    GROUP BY f.categoria
    ORDER BY f.categoria
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
$balancete_categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Balancete detalhado por conta (plano de contas)
$stmt = $conn->prepare("
    SELECT 
        pc.codigo,
        pc.nome as conta_nome,
        pc.tipo,
        SUM(CASE WHEN f.tipo = 'entrada' THEN f.valor ELSE 0 END) as debito,
        SUM(CASE WHEN f.tipo = 'saida' THEN f.valor ELSE 0 END) as credito
    FROM escola_plano_contas pc
    LEFT JOIN escola_fluxo_caixa f ON f.categoria = pc.categoria AND f.escola_id = pc.escola_id
        AND f.data_movimento BETWEEN :data_inicio AND :data_fim
    WHERE pc.escola_id = :escola_id AND pc.status = 'ativo'
    GROUP BY pc.id
    ORDER BY pc.codigo
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
$balancete_contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumo por tipo
$resumo_tipos = [
    'ativo' => ['total' => 0, 'contas' => []],
    'passivo' => ['total' => 0, 'contas' => []],
    'receita' => ['total' => 0, 'contas' => []],
    'despesa' => ['total' => 0, 'contas' => []],
    'patrimonio' => ['total' => 0, 'contas' => []]
];

foreach ($balancete_contas as $conta) {
    $saldo = $conta['debito'] - $conta['credito'];
    $resumo_tipos[$conta['tipo']]['total'] += $saldo;
    $resumo_tipos[$conta['tipo']]['contas'][] = $conta;
}

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

$tipos_nomes = [
    'ativo' => 'Ativo',
    'passivo' => 'Passivo',
    'receita' => 'Receita',
    'despesa' => 'Despesa',
    'patrimonio' => 'Patrimônio Líquido'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balancete Geral | Financeiro | SIGE Angola</title>
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
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .badge-ativo { background: #28a745; color: white; }
        .badge-passivo { background: #dc3545; color: white; }
        .badge-receita { background: #17a2b8; color: white; }
        .badge-despesa { background: #fd7e14; color: white; }
        
        .total-geral { background: #006B3E; color: white; font-weight: bold; }
        .saldo-positivo { color: #28a745; font-weight: bold; }
        .saldo-negativo { color: #dc3545; font-weight: bold; }
        
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-balance-scale"></i> Balancete</a></li>
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
                <i class="fas fa-balance-scale"></i> Balancete Geral
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="contas.php" class="btn btn-info btn-sm me-2">
                    <i class="fas fa-book"></i> Por Conta
                </a>
                <a href="comparativo.php" class="btn btn-secondary btn-sm">
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
                    <label>Tipo de Balancete</label>
                    <select name="tipo" class="form-control">
                        <option value="completo" <?php echo $tipo_balancete == 'completo' ? 'selected' : ''; ?>>Completo</option>
                        <option value="sintetico" <?php echo $tipo_balancete == 'sintetico' ? 'selected' : ''; ?>>Sintético (por categoria)</option>
                        <option value="analitico" <?php echo $tipo_balancete == 'analitico' ? 'selected' : ''; ?>>Analítico (por conta)</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($saldo_inicial, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Saldo Inicial</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($totais_periodo['total_entradas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Entradas no Período</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($totais_periodo['total_saidas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Saídas no Período</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo $saldo_atual >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($saldo_atual, 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo Final</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-arrow-up text-success"></i> Contas a Receber</div>
                    <div class="card-body text-center">
                        <h3 class="text-danger"><?php echo number_format($contas_receber, 2, ',', '.'); ?> Kz</h3>
                        <p class="text-muted">Valores pendentes de recebimento</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-arrow-down text-danger"></i> Contas a Pagar</div>
                    <div class="card-body text-center">
                        <h3 class="text-danger"><?php echo number_format($contas_pagar, 2, ',', '.'); ?> Kz</h3>
                        <p class="text-muted">Valores pendentes de pagamento</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Balancete - TABELA SEM DATATABLES -->
        <div class="card">
            <div class="card-header"><i class="fas fa-table"></i> Balancete Financeiro</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="tabelaBalancete">
                        <thead class="table-dark">
                            <tr>
                                <th>Código</th>
                                <th>Conta / Categoria</th>
                                <th>Tipo</th>
                                <th class="text-end">Débito (Kz)</th>
                                <th class="text-end">Crédito (Kz)</th>
                                <th class="text-end">Saldo (Kz)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tipo_balancete == 'sintetico'): ?>
                                <?php foreach ($balancete_categorias as $cat): 
                                    $saldo = $cat['entradas'] - $cat['saidas'];
                                ?>
                                <tr>
                                    <td>-</div>
                                    <td><strong><?php echo $categorias_nomes[$cat['categoria']] ?? $cat['categoria']; ?></strong></div>
                                    <td>-</div>
                                    <td class="text-end text-success"><?php echo number_format($cat['entradas'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end text-danger"><?php echo number_format($cat['saidas'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($saldo, 2, ',', '.'); ?> Kz
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php elseif ($tipo_balancete == 'analitico'): ?>
                                <?php foreach ($balancete_contas as $conta): 
                                    $saldo = $conta['debito'] - $conta['credito'];
                                ?>
                                <tr>
                                    <td><?php echo $conta['codigo']; ?></div>
                                    <td><?php echo htmlspecialchars($conta['conta_nome']); ?></div>
                                    <td><span class="badge badge-<?php echo $conta['tipo']; ?>"><?php echo $tipos_nomes[$conta['tipo']]; ?></span></div>
                                    <td class="text-end text-success"><?php echo number_format($conta['debito'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end text-danger"><?php echo number_format($conta['credito'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($saldo, 2, ',', '.'); ?> Kz
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($resumo_tipos as $tipo => $dados): if ($dados['total'] != 0 || !empty($dados['contas'])): ?>
                                <tr class="table-secondary">
                                    <td colspan="3"><strong><?php echo $tipos_nomes[$tipo]; ?></strong></td>
                                    <td class="text-end"><strong><?php echo number_format(array_sum(array_column($dados['contas'], 'debito')), 2, ',', '.'); ?> Kz</strong></td>
                                    <td class="text-end"><strong><?php echo number_format(array_sum(array_column($dados['contas'], 'credito')), 2, ',', '.'); ?> Kz</strong></td>
                                    <td class="text-end"><strong><?php echo number_format($dados['total'], 2, ',', '.'); ?> Kz</strong></td>
                                 </div>
                                <?php foreach ($dados['contas'] as $conta): 
                                    $saldo = $conta['debito'] - $conta['credito'];
                                ?>
                                <tr>
                                    <td>&nbsp;&nbsp;&nbsp;<?php echo $conta['codigo']; ?></div>
                                    <td>&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($conta['conta_nome']); ?></div>
                                    <td>-</div>
                                    <td class="text-end"><?php echo number_format($conta['debito'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end"><?php echo number_format($conta['credito'], 2, ',', '.'); ?> Kz</div>
                                    <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($saldo, 2, ',', '.'); ?> Kz
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="total-geral">
                            <tr>
                                <th colspan="3">TOTAL GERAL</th>
                                <th class="text-end"><?php echo number_format(array_sum(array_column($balancete_contas, 'debito')), 2, ',', '.'); ?> Kz</th>
                                <th class="text-end"><?php echo number_format(array_sum(array_column($balancete_contas, 'credito')), 2, ',', '.'); ?> Kz</th>
                                <th class="text-end"><?php echo number_format($saldo_atual, 2, ',', '.'); ?> Kz</th>
                             </div>
                        </tfoot>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre o Balancete Geral</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é o Balancete Geral?</h6>
                    <p>O balancete geral é um demonstrativo financeiro que apresenta o resumo de todas as movimentações (entradas e saídas) em um determinado período.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Tipos de Visualização:</h6>
                    <ul>
                        <li><strong>Completo:</strong> Mostra todos os lançamentos agrupados por tipo de conta.</li>
                        <li><strong>Sintético:</strong> Agrupa os valores por categoria.</li>
                        <li><strong>Analítico:</strong> Detalha cada conta individualmente.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Utilize os filtros de data para analisar períodos específicos.</li>
                        <li>Compare balancetes de diferentes períodos.</li>
                        <li>O balancete sintético é ideal para relatórios gerenciais.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
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
        
        // REMOVIDO O DATATABLES - Usando apenas CSS para tabela
        // Aplicar apenas estilos básicos à tabela
        $('#tabelaBalancete').addClass('table-bordered');
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>