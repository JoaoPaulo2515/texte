<?php
// escola/config/banco/index.php - Dashboard Bancário
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
// VERIFICAR E CRIAR TABELAS
// ============================================

// Tabela de contas bancárias
$check = $conn->query("SHOW TABLES LIKE 'escola_contas_bancarias'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_contas_bancarias (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            banco VARCHAR(100) NOT NULL,
            tipo_conta ENUM('corrente', 'poupanca', 'salario', 'empresarial') DEFAULT 'corrente',
            agencia VARCHAR(20),
            numero_conta VARCHAR(50) NOT NULL,
            digito VARCHAR(5),
            titular VARCHAR(200),
            nif VARCHAR(20),
            saldo_inicial DECIMAL(15,2) DEFAULT 0,
            saldo_atual DECIMAL(15,2) DEFAULT 0,
            iban VARCHAR(50),
            swift VARCHAR(20),
            observacoes TEXT,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Tabela de transações bancárias
$check = $conn->query("SHOW TABLES LIKE 'escola_transacoes_bancarias'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_transacoes_bancarias (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            conta_id INT NOT NULL,
            tipo ENUM('credito', 'debito', 'transferencia', 'pagamento', 'taxa') NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            descricao TEXT,
            categoria VARCHAR(50),
            data_transacao DATE NOT NULL,
            comprovativo VARCHAR(255),
            referencia VARCHAR(100),
            status ENUM('pendente', 'confirmado', 'cancelado') DEFAULT 'confirmado',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (conta_id) REFERENCES escola_contas_bancarias(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar transação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_transacao') {
    $conta_id = $_POST['conta_id'];
    $tipo = $_POST['tipo'];
    $valor = str_replace(',', '', $_POST['valor']);
    $descricao = $_POST['descricao'];
    $categoria = $_POST['categoria'];
    $data_transacao = $_POST['data_transacao'];
    
    // Upload do comprovativo
    $comprovativo = null;
    if (isset($_FILES['comprovativo']) && $_FILES['comprovativo']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['comprovativo']['name'], PATHINFO_EXTENSION));
        $comprovativo = 'comprovativo_' . time() . '_' . uniqid() . '.' . $ext;
        $upload_dir = __DIR__ . '/../../../uploads/comprovativos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        move_uploaded_file($_FILES['comprovativo']['tmp_name'], $upload_dir . $comprovativo);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO escola_transacoes_bancarias 
        (escola_id, conta_id, tipo, valor, descricao, categoria, data_transacao, comprovativo, status, usuario_id)
        VALUES 
        (:escola_id, :conta_id, :tipo, :valor, :descricao, :categoria, :data_transacao, :comprovativo, 'confirmado', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':conta_id' => $conta_id,
        ':tipo' => $tipo,
        ':valor' => $valor,
        ':descricao' => $descricao,
        ':categoria' => $categoria,
        ':data_transacao' => $data_transacao,
        ':comprovativo' => $comprovativo,
        ':usuario_id' => $usuario_id
    ]);
    
    // Atualizar saldo da conta
    $sinal = ($tipo == 'credito') ? '+' : '-';
    $stmt = $conn->prepare("
        UPDATE escola_contas_bancarias 
        SET saldo_atual = saldo_atual $sinal :valor 
        WHERE id = :conta_id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':valor' => $valor,
        ':conta_id' => $conta_id,
        ':escola_id' => $escola_id
    ]);
    
    $_SESSION['mensagem'] = "Transação registada com sucesso!";
    header("Location: index.php");
    exit;
}

// Transferência entre contas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'transferencia') {
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
        header("Location: index.php");
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Registrar débito na conta de origem
        $stmt1 = $conn->prepare("
            INSERT INTO escola_transacoes_bancarias 
            (escola_id, conta_id, tipo, valor, descricao, categoria, data_transacao, status, usuario_id)
            VALUES 
            (:escola_id, :conta_id, 'transferencia', :valor, :descricao, 'transferencia_envio', :data_transacao, 'confirmado', :usuario_id)
        ");
        $stmt1->execute([
            ':escola_id' => $escola_id,
            ':conta_id' => $conta_origem_id,
            ':valor' => $valor,
            ':descricao' => "Transferência enviada: " . $descricao,
            ':data_transacao' => $data_transacao,
            ':usuario_id' => $usuario_id
        ]);
        
        // Registrar crédito na conta de destino
        $stmt2 = $conn->prepare("
            INSERT INTO escola_transacoes_bancarias 
            (escola_id, conta_id, tipo, valor, descricao, categoria, data_transacao, status, usuario_id)
            VALUES 
            (:escola_id, :conta_id, 'transferencia', :valor, :descricao, 'transferencia_recebida', :data_transacao, 'confirmado', :usuario_id)
        ");
        $stmt2->execute([
            ':escola_id' => $escola_id,
            ':conta_id' => $conta_destino_id,
            ':valor' => $valor,
            ':descricao' => "Transferência recebida: " . $descricao,
            ':data_transacao' => $data_transacao,
            ':usuario_id' => $usuario_id
        ]);
        
        // Atualizar saldos
        $stmt3 = $conn->prepare("UPDATE escola_contas_bancarias SET saldo_atual = saldo_atual - :valor WHERE id = :id");
        $stmt3->execute([':valor' => $valor, ':id' => $conta_origem_id]);
        
        $stmt4 = $conn->prepare("UPDATE escola_contas_bancarias SET saldo_atual = saldo_atual + :valor WHERE id = :id");
        $stmt4->execute([':valor' => $valor, ':id' => $conta_destino_id]);
        
        $conn->commit();
        $_SESSION['mensagem'] = "Transferência realizada com sucesso!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['erro'] = "Erro ao realizar transferência: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar contas bancárias
$contas = $conn->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM escola_transacoes_bancarias WHERE conta_id = c.id) as total_transacoes
    FROM escola_contas_bancarias c
    WHERE c.escola_id = :escola_id
    ORDER BY c.status DESC, c.banco ASC
");
$contas->execute([':escola_id' => $escola_id]);
$contas = $contas->fetchAll(PDO::FETCH_ASSOC);

// Calcular saldo total
$saldo_total = 0;
foreach ($contas as $conta) {
    if ($conta['status'] == 'ativo') {
        $saldo_total += $conta['saldo_atual'];
    }
}

// Buscar últimas transações
$transacoes = $conn->prepare("
    SELECT t.*, c.banco, c.numero_conta 
    FROM escola_transacoes_bancarias t
    JOIN escola_contas_bancarias c ON c.id = t.conta_id
    WHERE t.escola_id = :escola_id 
    ORDER BY t.created_at DESC 
    LIMIT 20
");
$transacoes->execute([':escola_id' => $escola_id]);
$transacoes = $transacoes->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Bancário | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .saldo-positivo { color: #28a745; font-weight: bold; }
        .saldo-negativo { color: #dc3545; font-weight: bold; }
        .table-responsive { overflow-x: auto; }
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-university"></i> Banco</a></li>
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
            <h2><i class="fas fa-university"></i> Dashboard Bancário</h2>
            <div>
                <a href="contas.php" class="btn btn-primary btn-sm me-2"><i class="fas fa-plus"></i> Gerir Contas</a>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalTransacao"><i class="fas fa-exchange-alt"></i> Nova Transação</button>
                <button class="btn btn-info btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalTransferencia"><i class="fas fa-arrow-right-arrow-left"></i> Transferência</button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($contas); ?></div>
                <div>Total de Contas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($saldo_total, 2, ',', '.'); ?> Kz</div>
                <div>Saldo Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($transacoes); ?></div>
                <div>Últimas Transações</div>
            </div>
        </div>
        
        <!-- Lista de Contas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Contas Bancárias</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Banco</th>
                                <th>Agência</th>
                                <th>Nº Conta</th>
                                <th>Titular</th>
                                <th>Saldo Atual</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contas as $conta): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($conta['banco']); ?></strong><br><small><?php echo ucfirst($conta['tipo_conta']); ?></small></div>
                                <td><?php echo $conta['agencia']; ?></div>
                                <td><?php echo $conta['numero_conta']; ?>-<?php echo $conta['digito']; ?></div>
                                <td><?php echo htmlspecialchars($conta['titular']); ?></div>
                                <td><span class="saldo-positivo"><?php echo number_format($conta['saldo_atual'], 2, ',', '.'); ?> Kz</span></div>
                                <td><span class="badge <?php echo $conta['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $conta['status']; ?></span></div>
                                <td>
                                    <a href="contas.php?edit=<?php echo $conta['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                    <button class="btn btn-sm btn-primary" onclick="abrirModalTransacao(<?php echo $conta['id']; ?>, '<?php echo addslashes($conta['banco']); ?>')">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Últimas Transações -->
        <div class="card">
            <div class="card-header"><i class="fas fa-history"></i> Últimas Transações</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaTransacoes">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Conta</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Comprovativo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transacoes as $t): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($t['data_transacao'])); ?></td>
                                <td><?php echo $t['banco']; ?><br><small><?php echo $t['numero_conta']; ?></small></div>
                                <td>
                                    <?php if ($t['tipo'] == 'credito'): ?>
                                        <span class="badge bg-success">Crédito</span>
                                    <?php elseif ($t['tipo'] == 'debito'): ?>
                                        <span class="badge bg-danger">Débito</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Transferência</span>
                                    <?php endif; ?>
                                 </div>
                                <td>
                                    <span class="<?php echo $t['tipo'] == 'credito' ? 'saldo-positivo' : 'saldo-negativo'; ?>">
                                        <?php echo $t['tipo'] == 'credito' ? '+' : '-'; ?> <?php echo number_format($t['valor'], 2, ',', '.'); ?> Kz
                                    </span>
                                 </div>
                                <td><?php echo htmlspecialchars($t['descricao']); ?></div>
                                <td><?php echo ucfirst($t['categoria']); ?></div>
                                <td>
                                    <?php if ($t['comprovativo']): ?>
                                        <a href="../../../uploads/comprovativos/<?php echo $t['comprovativo']; ?>" target="_blank" class="btn btn-sm btn-secondary"><i class="fas fa-file-pdf"></i></a>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Transação -->
    <div class="modal fade" id="modalTransacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Nova Transação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="add_transacao">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Conta</label>
                            <select name="conta_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($contas as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo $c['banco']; ?> - <?php echo $c['numero_conta']; ?>-<?php echo $c['digito']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Tipo</label>
                            <select name="tipo" class="form-control" required>
                                <option value="credito">Crédito (Entrada)</option>
                                <option value="debito">Débito (Saída)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Valor (Kz)</label>
                            <input type="number" step="0.01" name="valor" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Data</label>
                            <input type="date" name="data_transacao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Categoria</label>
                            <select name="categoria" class="form-control">
                                <option value="outros">Outros</option>
                                <option value="mensalidade">Mensalidade</option>
                                <option value="matricula">Matrícula</option>
                                <option value="fornecedor">Fornecedor</option>
                                <option value="salario">Salário</option>
                                <option value="manutencao">Manutenção</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Comprovativo</label>
                            <input type="file" name="comprovativo" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Transferência -->
    <div class="modal fade" id="modalTransferencia" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-arrow-right-arrow-left"></i> Transferência entre Contas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="transferencia">
                    <div class="modal-body">
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
                            <label>Data</label>
                            <input type="date" name="data_transacao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-info">Transferir</button>
                    </div>
                </form>
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
        
        $('#tabelaTransacoes').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        function abrirModalTransacao(contaId, contaNome) {
            $('select[name="conta_id"]').val(contaId);
            $('#modalTransacao').modal('show');
        }
    </script>
</body>
</html>