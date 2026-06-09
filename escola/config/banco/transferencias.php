<?php
// escola/config/banco/transferencias.php - Transferências Bancárias
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR TRANSFERÊNCIA
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'transferir') {
    $conta_origem_id = $_POST['conta_origem_id'];
    $conta_destino_id = $_POST['conta_destino_id'];
    $valor = str_replace(',', '', $_POST['valor']);
    $descricao = $_POST['descricao'];
    $data_transacao = $_POST['data_transacao'];
    
    // Verificar saldo disponível
    $stmt = $conn->prepare("SELECT saldo_atual FROM escola_contas_bancarias WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $conta_origem_id, ':escola_id' => $escola_id]);
    $saldo_origem = $stmt->fetch(PDO::FETCH_ASSOC)['saldo_atual'];
    
    if ($saldo_origem < $valor) {
        $_SESSION['erro'] = "Saldo insuficiente na conta de origem!";
        header("Location: transferencias.php");
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Registrar débito na conta de origem
        $stmt = $conn->prepare("
            INSERT INTO escola_transacoes_bancarias 
            (escola_id, conta_id, tipo, valor, descricao, categoria, data_transacao, status, usuario_id)
            VALUES (:escola_id, :conta_id, 'transferencia', :valor, :descricao, 'transferencia_envio', :data_transacao, 'confirmado', :usuario_id)
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':conta_id' => $conta_origem_id,
            ':valor' => $valor,
            ':descricao' => "Transferência enviada: " . $descricao,
            ':data_transacao' => $data_transacao,
            ':usuario_id' => $usuario_id
        ]);
        
        // Registrar crédito na conta de destino
        $stmt = $conn->prepare("
            INSERT INTO escola_transacoes_bancarias 
            (escola_id, conta_id, tipo, valor, descricao, categoria, data_transacao, status, usuario_id)
            VALUES (:escola_id, :conta_id, 'transferencia', :valor, :descricao, 'transferencia_recebida', :data_transacao, 'confirmado', :usuario_id)
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':conta_id' => $conta_destino_id,
            ':valor' => $valor,
            ':descricao' => "Transferência recebida: " . $descricao,
            ':data_transacao' => $data_transacao,
            ':usuario_id' => $usuario_id
        ]);
        
        // Atualizar saldos
        $conn->prepare("UPDATE escola_contas_bancarias SET saldo_atual = saldo_atual - :valor WHERE id = :id")->execute([':valor' => $valor, ':id' => $conta_origem_id]);
        $conn->prepare("UPDATE escola_contas_bancarias SET saldo_atual = saldo_atual + :valor WHERE id = :id")->execute([':valor' => $valor, ':id' => $conta_destino_id]);
        
        $conn->commit();
        $_SESSION['mensagem'] = "Transferência realizada com sucesso!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['erro'] = "Erro ao realizar transferência: " . $e->getMessage();
    }
    
    header("Location: transferencias.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Contas ativas
$contas = $conn->prepare("SELECT id, banco, numero_conta, digito, saldo_atual FROM escola_contas_bancarias WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY banco");
$contas->execute([':escola_id' => $escola_id]);
$contas = $contas->fetchAll(PDO::FETCH_ASSOC);

// Últimas transferências
$transferencias = $conn->prepare("
    SELECT t.*, c.banco, c.numero_conta, c.digito
    FROM escola_transacoes_bancarias t
    JOIN escola_contas_bancarias c ON c.id = t.conta_id
    WHERE t.escola_id = :escola_id AND t.tipo = 'transferencia'
    ORDER BY t.created_at DESC
    LIMIT 50
");
$transferencias->execute([':escola_id' => $escola_id]);
$transferencias = $transferencias->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferências Bancárias | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .table-responsive { overflow-x: auto; }
        .transfer-card { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-cogs"></i> Configurações</a>
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
            <h2><i class="fas fa-exchange-alt"></i> Transferências Bancárias</h2>
            <a href="contas.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-primary text-white"><i class="fas fa-exchange-alt"></i> Nova Transferência</div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="acao" value="transferir">
                            <div class="mb-3">
                                <label>Conta de Origem</label>
                                <select name="conta_origem_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($contas as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['banco']; ?> - <?php echo $c['numero_conta']; ?>-<?php echo $c['digito']; ?> (Saldo: <?php echo number_format($c['saldo_atual'], 2, ',', '.'); ?> Kz)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Conta de Destino</label>
                                <select name="conta_destino_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($contas as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['banco']; ?> - <?php echo $c['numero_conta']; ?>-<?php echo $c['digito']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Valor (Kz)</label>
                                <input type="number" step="0.01" name="valor" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Data da Transferência</label>
                                <input type="date" name="data_transacao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label>Descrição / Motivo</label>
                                <textarea name="descricao" class="form-control" rows="3" required placeholder="Ex: Transferência entre contas, Pagamento de fornecedor..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" onclick="return confirm('Confirmar transferência?')"><i class="fas fa-exchange-alt"></i> Realizar Transferência</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><i class="fas fa-history"></i> Histórico de Transferências</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabelaTransferencias">
                                <thead class="table-light">
                                    <tr><th>Data</th><th>Conta</th><th>Tipo</th><th>Valor</th><th>Descrição</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transferencias as $t): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($t['data_transacao'])); ?></td>
                                        <td><?php echo $t['banco']; ?><br><small><?php echo $t['numero_conta']; ?>-<?php echo $t['digito']; ?></small></div>
                                        <td>
                                            <?php if (strpos($t['descricao'], 'enviada') !== false): ?>
                                                <span class="badge bg-danger">Enviada</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Recebida</span>
                                            <?php endif; ?>
                                         </div>
                                        <td><span class="<?php echo strpos($t['descricao'], 'enviada') !== false ? 'saldo-negativo' : 'saldo-positivo'; ?>"><?php echo number_format($t['valor'], 2, ',', '.'); ?> Kz</span></div>
                                        <td><?php echo htmlspecialchars($t['descricao']); ?></div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaTransferencias').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25, order: [[0, 'desc']] });
    </script>
</body>
</html>