<?php
// escola/config/banco/extrato.php - Extrato Bancário
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// PROCESSAR FILTROS E EXPORTAÇÃO
// ============================================

$conta_id = $_GET['conta_id'] ?? 0;
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$tipo_filter = $_GET['tipo'] ?? '';

// Exportar para Excel/CSV
if (isset($_GET['exportar']) && $conta_id) {
    $formato = $_GET['formato'] ?? 'excel';
    
    $stmt = $conn->prepare("
        SELECT t.*, c.banco, c.numero_conta, c.digito, c.titular
        FROM escola_transacoes_bancarias t
        JOIN escola_contas_bancarias c ON c.id = t.conta_id
        WHERE t.conta_id = :conta_id AND t.escola_id = :escola_id
            AND DATE(t.data_transacao) BETWEEN :data_inicio AND :data_fim
        ORDER BY t.data_transacao DESC
    ");
    $stmt->execute([
        ':conta_id' => $conta_id,
        ':escola_id' => $escola_id,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim
    ]);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar dados da conta
    $stmt = $conn->prepare("SELECT * FROM escola_contas_bancarias WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $conta_id, ':escola_id' => $escola_id]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($formato == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="extrato_' . $conta['banco'] . '_' . date('Y-m-d') . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"><title>Extrato Bancário</title></head><body>';
        echo '<h2>' . htmlspecialchars($conta['banco']) . '</h2>';
        echo '<h3>Extrato Bancário</h3>';
        echo '<p><strong>Conta:</strong> ' . $conta['numero_conta'] . '-' . $conta['digito'] . '</p>';
        echo '<p><strong>Titular:</strong> ' . htmlspecialchars($conta['titular']) . '</p>';
        echo '<p><strong>Período:</strong> ' . date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim)) . '</p>';
        echo '<table border="1" cellpadding="5">';
        echo '<tr><th>Data</th><th>Tipo</th><th>Descrição</th><th>Categoria</th><th>Valor</th><th>Saldo</th></tr>';
        
        $saldo = 0;
        $transacoes_reverse = array_reverse($transacoes);
        foreach ($transacoes_reverse as $t) {
            if ($t['tipo'] == 'credito' || $t['tipo'] == 'transferencia_recebida') {
                $saldo += $t['valor'];
                $valor_format = '+' . number_format($t['valor'], 2, ',', '.');
                $classe = 'style="color: green"';
            } else {
                $saldo -= $t['valor'];
                $valor_format = '-' . number_format($t['valor'], 2, ',', '.');
                $classe = 'style="color: red"';
            }
            echo '<tr>';
            echo '<td>' . date('d/m/Y', strtotime($t['data_transacao'])) . '</td>';
            echo '<td>' . ucfirst($t['tipo']) . '</td>';
            echo '<td>' . htmlspecialchars($t['descricao']) . '</td>';
            echo '<td>' . ucfirst($t['categoria']) . '</td>';
            echo '<td ' . $classe . '>' . $valor_format . ' Kz</td>';
            echo '<td>' . number_format($saldo, 2, ',', '.') . ' Kz</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '<p><small>Documento gerado por SIGE Angola em ' . date('d/m/Y H:i:s') . '</small></p>';
        echo '</body></html>';
        exit;
    } elseif ($formato == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="extrato_' . $conta['banco'] . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Extrato Bancário - ' . $conta['banco']]);
        fputcsv($output, ['Conta: ' . $conta['numero_conta'] . '-' . $conta['digito']]);
        fputcsv($output, ['Período: ' . date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim))]);
        fputcsv($output, []);
        fputcsv($output, ['Data', 'Tipo', 'Descrição', 'Categoria', 'Valor', 'Saldo']);
        
        $saldo = 0;
        $transacoes_reverse = array_reverse($transacoes);
        foreach ($transacoes_reverse as $t) {
            if ($t['tipo'] == 'credito' || $t['tipo'] == 'transferencia_recebida') {
                $saldo += $t['valor'];
                $valor_format = '+' . number_format($t['valor'], 2, ',', '.');
            } else {
                $saldo -= $t['valor'];
                $valor_format = '-' . number_format($t['valor'], 2, ',', '.');
            }
            fputcsv($output, [
                date('d/m/Y', strtotime($t['data_transacao'])),
                $t['tipo'],
                $t['descricao'],
                $t['categoria'],
                $valor_format . ' Kz',
                number_format($saldo, 2, ',', '.') . ' Kz'
            ]);
        }
        fclose($output);
        exit;
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Lista de contas para o select
$contas = $conn->prepare("SELECT id, banco, numero_conta, digito, titular, saldo_atual FROM escola_contas_bancarias WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY banco");
$contas->execute([':escola_id' => $escola_id]);
$contas = $contas->fetchAll(PDO::FETCH_ASSOC);

// Se nenhuma conta selecionada e houver contas, selecionar a primeira
if ($conta_id == 0 && !empty($contas)) {
    $conta_id = $contas[0]['id'];
}

// Buscar transações da conta selecionada
$transacoes = [];
$conta_atual = null;
$saldo_atual = 0;
$total_creditos = 0;
$total_debitos = 0;

if ($conta_id) {
    // Buscar dados da conta
    $stmt = $conn->prepare("SELECT * FROM escola_contas_bancarias WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $conta_id, ':escola_id' => $escola_id]);
    $conta_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar transações
    $sql = "
        SELECT t.*, c.banco, c.numero_conta
        FROM escola_transacoes_bancarias t
        JOIN escola_contas_bancarias c ON c.id = t.conta_id
        WHERE t.conta_id = :conta_id AND t.escola_id = :escola_id
            AND DATE(t.data_transacao) BETWEEN :data_inicio AND :data_fim
    ";
    if ($tipo_filter) {
        $sql .= " AND t.tipo = :tipo";
    }
    $sql .= " ORDER BY t.data_transacao DESC";
    
    $stmt = $conn->prepare($sql);
    $params = [
        ':conta_id' => $conta_id,
        ':escola_id' => $escola_id,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim
    ];
    if ($tipo_filter) {
        $params[':tipo'] = $tipo_filter;
    }
    $stmt->execute($params);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular totais
    foreach ($transacoes as $t) {
        if ($t['tipo'] == 'credito' || $t['tipo'] == 'transferencia_recebida') {
            $total_creditos += $t['valor'];
        } else {
            $total_debitos += $t['valor'];
        }
    }
    $saldo_atual = $conta_atual['saldo_atual'] ?? 0;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extrato Bancário | SIGE Angola</title>
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
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .info-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .saldo-positivo { color: #28a745; font-weight: bold; }
        .saldo-negativo { color: #dc3545; font-weight: bold; }
        .table-responsive { overflow-x: auto; }
        
        .filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .btn-export-group { display: flex; gap: 10px; }
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
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i> <span>Configurações</span>
                </a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="contas.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="../pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-invoice"></i> Extrato Bancário</h2>
            <a href="contas.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Conta Bancária</label>
                    <select name="conta_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Selecione uma conta...</option>
                        <?php foreach ($contas as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $conta_id == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo $c['banco']; ?> - <?php echo $c['numero_conta']; ?>-<?php echo $c['digito']; ?>
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
                <div class="col-md-2">
                    <label>Tipo</label>
                    <select name="tipo" class="form-control">
                        <option value="">Todos</option>
                        <option value="credito" <?php echo $tipo_filter == 'credito' ? 'selected' : ''; ?>>Crédito</option>
                        <option value="debito" <?php echo $tipo_filter == 'debito' ? 'selected' : ''; ?>>Débito</option>
                        <option value="transferencia" <?php echo $tipo_filter == 'transferencia' ? 'selected' : ''; ?>>Transferência</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>&nbsp;</label>
                    <div class="btn-export-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                        <?php if ($conta_id): ?>
                        <a href="?exportar=1&formato=excel&conta_id=<?php echo $conta_id; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>&tipo=<?php echo $tipo_filter; ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="?exportar=1&formato=csv&conta_id=<?php echo $conta_id; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>&tipo=<?php echo $tipo_filter; ?>" class="btn btn-info"><i class="fas fa-file-csv"></i> CSV</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if ($conta_atual): ?>
        <!-- Informações da Conta -->
        <div class="info-box">
            <div class="row">
                <div class="col-md-4">
                    <strong><i class="fas fa-university"></i> Banco:</strong> <?php echo htmlspecialchars($conta_atual['banco']); ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="fas fa-credit-card"></i> Conta:</strong> <?php echo $conta_atual['numero_conta']; ?>-<?php echo $conta_atual['digito']; ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="fas fa-user"></i> Titular:</strong> <?php echo htmlspecialchars($conta_atual['titular']); ?>
                </div>
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-chart-line"></i> Saldo Atual:</strong> 
                    <span class="saldo-positivo"><?php echo number_format($saldo_atual, 2, ',', '.'); ?> Kz</span>
                </div>
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-arrow-up text-success"></i> Total Créditos:</strong> 
                    <span class="saldo-positivo"><?php echo number_format($total_creditos, 2, ',', '.'); ?> Kz</span>
                </div>
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-arrow-down text-danger"></i> Total Débitos:</strong> 
                    <span class="saldo-negativo"><?php echo number_format($total_debitos, 2, ',', '.'); ?> Kz</span>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Transações -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Movimentações do Período</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaExtrato">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Valor</th>
                                <th>Referência</th>
                                <th>Comprovativo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transacoes)): ?>
                                <?php foreach ($transacoes as $t): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($t['data_transacao'])); ?></td>
                                    <td>
                                        <?php
                                        $tipos = [
                                            'credito' => '<span class="badge bg-success">Crédito</span>',
                                            'debito' => '<span class="badge bg-danger">Débito</span>',
                                            'transferencia' => '<span class="badge bg-info">Transferência</span>',
                                            'pagamento' => '<span class="badge bg-primary">Pagamento</span>',
                                            'taxa' => '<span class="badge bg-secondary">Taxa</span>'
                                        ];
                                        echo $tipos[$t['tipo']] ?? $t['tipo'];
                                        ?>
                                     </div>
                                    <td><?php echo htmlspecialchars($t['descricao']); ?></td>
                                    <td><?php echo ucfirst($t['categoria']); ?></div>
                                    <td>
                                        <?php if ($t['tipo'] == 'credito' || $t['tipo'] == 'transferencia_recebida'): ?>
                                            <span class="saldo-positivo">+ <?php echo number_format($t['valor'], 2, ',', '.'); ?> Kz</span>
                                        <?php else: ?>
                                            <span class="saldo-negativo">- <?php echo number_format($t['valor'], 2, ',', '.'); ?> Kz</span>
                                        <?php endif; ?>
                                     </div>
                                    <td><?php echo $t['referencia'] ?? '-'; ?></div>
                                    <td>
                                        <?php if ($t['comprovativo']): ?>
                                            <a href="../../../uploads/comprovativos/<?php echo $t['comprovativo']; ?>" target="_blank" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            --
                                        <?php endif; ?>
                                     </div>
                                 </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                        Nenhuma movimentação encontrada no período.
                                     </div>
                                 </div>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo do Período -->
        <div class="card">
            <div class="card-header bg-info text-white"><i class="fas fa-chart-pie"></i> Resumo do Período</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="border rounded p-3">
                            <h4>Total de Créditos</h4>
                            <h3 class="text-success"><?php echo number_format($total_creditos, 2, ',', '.'); ?> Kz</h3>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="border rounded p-3">
                            <h4>Total de Débitos</h4>
                            <h3 class="text-danger"><?php echo number_format($total_debitos, 2, ',', '.'); ?> Kz</h3>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="border rounded p-3">
                            <h4>Saldo do Período</h4>
                            <h3 class="<?php echo ($total_creditos - $total_debitos) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($total_creditos - $total_debitos, 2, ',', '.'); ?> Kz
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="alert alert-info text-center">
            <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
            <h5>Selecione uma conta bancária para visualizar o extrato</h5>
            <p>Você precisa ter pelo menos uma conta cadastrada para visualizar o extrato.</p>
            <a href="contas.php" class="btn btn-primary mt-2"><i class="fas fa-plus"></i> Cadastrar Conta</a>
        </div>
        <?php endif; ?>
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
        
        <?php if (!empty($transacoes)): ?>
        $('#tabelaExtrato').DataTable({
            language: { 
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' 
            },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        <?php endif; ?>
    </script>
</body>
</html>