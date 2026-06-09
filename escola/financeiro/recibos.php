<?php
// escola/financeiro/recibos.php - Gestão de Recibos (CORRIGIDO - DataTables)
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
// VERIFICAR E CRIAR TABELA DE RECIBOS
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'escola_recibos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_recibos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            numero_recibo VARCHAR(20) NOT NULL,
            aluno_id INT NOT NULL,
            tipo ENUM('mensalidade', 'matricula', 'taxa', 'outro') DEFAULT 'mensalidade',
            valor DECIMAL(15,2) NOT NULL,
            desconto DECIMAL(15,2) DEFAULT 0,
            multa DECIMAL(15,2) DEFAULT 0,
            juros DECIMAL(15,2) DEFAULT 0,
            valor_total DECIMAL(15,2) NOT NULL,
            data_emissao DATE NOT NULL,
            data_pagamento DATE,
            forma_pagamento_id INT,
            descricao TEXT,
            observacoes TEXT,
            status ENUM('emitido', 'cancelado') DEFAULT 'emitido',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            UNIQUE KEY unique_numero_recibo (numero_recibo)
        )
    ");
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarNumeroRecibo($conn, $escola_id) {
    $ano = date('Y');
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total FROM escola_recibos 
        WHERE escola_id = :escola_id AND YEAR(created_at) = :ano
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] + 1;
    
    return 'REC-' . $ano . '-' . str_pad($total, 6, '0', STR_PAD_LEFT);
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Gerar recibo a partir de uma mensalidade
if (isset($_GET['gerar_from_mensalidade']) && isset($_GET['id'])) {
    $mensalidade_id = $_GET['id'];
    
    $stmt = $conn->prepare("
        SELECT m.*, u.nome as aluno_nome, e.matricula, u.email, u.telefone
        FROM escola_mensalidades m
        JOIN estudantes e ON e.id = m.aluno_id
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE m.id = :id AND m.escola_id = :escola_id
    ");
    $stmt->execute([':id' => $mensalidade_id, ':escola_id' => $escola_id]);
    $mensalidade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($mensalidade) {
        $numero_recibo = gerarNumeroRecibo($conn, $escola_id);
        
        $stmt = $conn->prepare("
            INSERT INTO escola_recibos 
            (escola_id, numero_recibo, aluno_id, tipo, valor, desconto, multa, juros, 
             valor_total, data_emissao, data_pagamento, forma_pagamento_id, descricao, status, usuario_id)
            VALUES 
            (:escola_id, :numero_recibo, :aluno_id, 'mensalidade', :valor, :desconto, :multa, :juros,
             :valor_total, CURDATE(), :data_pagamento, :forma_pagamento_id, :descricao, 'emitido', :usuario_id)
        ");
        
        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $descricao = "Mensalidade de " . $meses[$mensalidade['mes']] . "/" . $mensalidade['ano'];
        
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':numero_recibo' => $numero_recibo,
            ':aluno_id' => $mensalidade['aluno_id'],
            ':valor' => $mensalidade['valor_original'],
            ':desconto' => $mensalidade['desconto'],
            ':multa' => $mensalidade['multa'],
            ':juros' => $mensalidade['juros'],
            ':valor_total' => $mensalidade['valor_pago'],
            ':data_pagamento' => $mensalidade['data_pagamento'],
            ':forma_pagamento_id' => $mensalidade['forma_pagamento_id'],
            ':descricao' => $descricao,
            ':usuario_id' => $usuario_id
        ]);
        
        $_SESSION['mensagem'] = "Recibo gerado com sucesso! Nº: $numero_recibo";
        header("Location: recibos.php");
        exit;
    }
}

// Gerar recibo manual
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'gerar_recibo') {
    $aluno_id = $_POST['aluno_id'];
    $tipo = $_POST['tipo'];
    $valor = str_replace(',', '', $_POST['valor']);
    $desconto = str_replace(',', '', $_POST['desconto'] ?? 0);
    $multa = str_replace(',', '', $_POST['multa'] ?? 0);
    $juros = str_replace(',', '', $_POST['juros'] ?? 0);
    $data_pagamento = $_POST['data_pagamento'];
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    $descricao = $_POST['descricao'];
    $observacoes = $_POST['observacoes'];
    
    $valor_total = $valor - $desconto + $multa + $juros;
    $numero_recibo = gerarNumeroRecibo($conn, $escola_id);
    
    $stmt = $conn->prepare("
        INSERT INTO escola_recibos 
        (escola_id, numero_recibo, aluno_id, tipo, valor, desconto, multa, juros, 
         valor_total, data_emissao, data_pagamento, forma_pagamento_id, descricao, observacoes, status, usuario_id)
        VALUES 
        (:escola_id, :numero_recibo, :aluno_id, :tipo, :valor, :desconto, :multa, :juros,
         :valor_total, CURDATE(), :data_pagamento, :forma_pagamento_id, :descricao, :observacoes, 'emitido', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':numero_recibo' => $numero_recibo,
        ':aluno_id' => $aluno_id,
        ':tipo' => $tipo,
        ':valor' => $valor,
        ':desconto' => $desconto,
        ':multa' => $multa,
        ':juros' => $juros,
        ':valor_total' => $valor_total,
        ':data_pagamento' => $data_pagamento,
        ':forma_pagamento_id' => $forma_pagamento_id,
        ':descricao' => $descricao,
        ':observacoes' => $observacoes,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Recibo gerado com sucesso! Nº: $numero_recibo";
    header("Location: recibos.php");
    exit;
}

// Cancelar recibo
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE escola_recibos SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Recibo cancelado!";
    header("Location: recibos.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Filtros
$aluno_filter = $_GET['aluno'] ?? '';
$status_filter = $_GET['status'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');

$sql = "
    SELECT r.*, 
           u.nome as aluno_nome, 
           e.matricula,
           f.nome as forma_pagamento_nome,
           u2.nome as usuario_nome
    FROM escola_recibos r
    JOIN estudantes e ON e.id = r.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN escola_formas_pagamento f ON f.id = r.forma_pagamento_id
    LEFT JOIN usuarios u2 ON u2.id = r.usuario_id
    WHERE r.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($aluno_filter) {
    $sql .= " AND r.aluno_id = :aluno_id";
    $params[':aluno_id'] = $aluno_filter;
}
if ($status_filter) {
    $sql .= " AND r.status = :status";
    $params[':status'] = $status_filter;
}
if ($data_inicio && $data_fim) {
    $sql .= " AND DATE(r.created_at) BETWEEN :data_inicio AND :data_fim";
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
}

$sql .= " ORDER BY r.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$recibos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para selects
$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// Buscar mensalidades não faturadas
$mensalidades_nao_faturadas = $conn->prepare("
    SELECT m.id, m.mes, m.ano, m.valor_original, m.valor_pago, m.data_pagamento,
           u.nome as aluno_nome, e.matricula, u.email
    FROM escola_mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE m.escola_id = :escola_id 
        AND m.status = 'pago'
        AND NOT EXISTS (SELECT 1 FROM escola_recibos r WHERE r.descricao LIKE CONCAT('%', m.mes, '/', m.ano, '%') AND r.aluno_id = m.aluno_id)
    ORDER BY m.data_pagamento DESC
    LIMIT 50
");
$mensalidades_nao_faturadas->execute([':escola_id' => $escola_id]);
$mensalidades_nao_faturadas = $mensalidades_nao_faturadas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total_recibos' => count($recibos),
    'total_valor' => 0,
    'total_emitidos' => 0,
    'total_cancelados' => 0
];

foreach ($recibos as $r) {
    $stats['total_valor'] += $r['valor_total'];
    if ($r['status'] == 'emitido') {
        $stats['total_emitidos']++;
    } else {
        $stats['total_cancelados']++;
    }
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibos | Financeiro | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .badge-emitido { background: #28a745; color: white; }
        .badge-cancelado { background: #dc3545; color: white; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .recibo-preview { cursor: pointer; transition: transform 0.2s; }
        .recibo-preview:hover { transform: scale(1.02); }
        
        .numero-recibo { font-family: monospace; font-weight: bold; font-size: 1.1em; }
        
        .alert-warning-list { background: #fff3cd; border-left: 4px solid #ffc107; }
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
        <li class="nav-item"><a href="financeiro/index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
        
        <!-- Contas a Receber/Pagar -->
        <li class="nav-item"><a href="financeiro/contas_receber/index.php" class="nav-link"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
        <li class="nav-item"><a href="financeiro/contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
        
        <!-- Gestão de Pagamentos -->
        <li class="nav-item"><a href="financeiro/mensalidades.php" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
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
            <h2><i class="fas fa-receipt"></i> Gestão de Recibos</h2>
            <div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGerarRecibo">
                    <i class="fas fa-plus"></i> Gerar Recibo
                </button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Mensalidades não faturadas -->
        <?php if (!empty($mensalidades_nao_faturadas)): ?>
        <div class="alert alert-warning alert-warning-list">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Atenção!</strong> Existem <?php echo count($mensalidades_nao_faturadas); ?> mensalidades pagas sem recibo emitido.
            <div class="mt-2">
                <?php foreach ($mensalidades_nao_faturadas as $mf): ?>
                <a href="?gerar_from_mensalidade=1&id=<?php echo $mf['id']; ?>" class="btn btn-sm btn-warning me-1 mb-1">
                    <i class="fas fa-receipt"></i> Gerar recibo - <?php echo htmlspecialchars($mf['aluno_nome']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_recibos']; ?></div>
                <div class="stat-label">Total de Recibos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($stats['total_valor'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Valor Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $stats['total_emitidos']; ?></div>
                <div class="stat-label">Recibos Emitidos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $stats['total_cancelados']; ?></div>
                <div class="stat-label">Recibos Cancelados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label>Aluno</label>
                    <select name="aluno" class="form-control">
                        <option value="">Todos os alunos</option>
                        <?php foreach ($alunos as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo $aluno_filter == $a['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">Todos</option>
                        <option value="emitido" <?php echo $status_filter == 'emitido' ? 'selected' : ''; ?>>Emitido</option>
                        <option value="cancelado" <?php echo $status_filter == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
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
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Recibos - TABELA CORRIGIDA -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Recibos</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaRecibos">
                        <thead class="table-light">
                            <tr>
                                <th>Nº Recibo</th>
                                <th>Data</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Forma Pagamento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recibos)): ?>
                                <?php foreach ($recibos as $recibo): ?>
                                <tr class="recibo-preview">
                                    <td class="numero-recibo"><?php echo $recibo['numero_recibo']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($recibo['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($recibo['aluno_nome']); ?></strong></td>
                                    <td><?php echo $recibo['matricula']; ?></td>
                                    <td>
                                        <?php
                                        $tipos = ['mensalidade' => 'Mensalidade', 'matricula' => 'Matrícula', 'taxa' => 'Taxa', 'outro' => 'Outro'];
                                        echo $tipos[$recibo['tipo']] ?? $recibo['tipo'];
                                        ?>
                                     </div>
                                    <td><?php echo number_format($recibo['valor_total'], 2, ',', '.'); ?> Kz</div>
                                    <td><?php echo $recibo['forma_pagamento_nome'] ?? '-'; ?></div>
                                    <td><span class="badge badge-<?php echo $recibo['status']; ?>"><?php echo ucfirst($recibo['status']); ?></span></div>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="visualizar_recibo.php?id=<?php echo $recibo['id']; ?>" class="btn btn-info" target="_blank" title="Visualizar">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="imprimir_recibo.php?id=<?php echo $recibo['id']; ?>" class="btn btn-secondary" target="_blank" title="Imprimir">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <a href="?cancelar=1&id=<?php echo $recibo['id']; ?>" class="btn btn-danger" onclick="return confirm('Cancelar este recibo?')" title="Cancelar">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        </div>
                                     </div>
                                 </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                        Nenhum recibo encontrado
                                     </div>
                                 </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Gerar Recibo Manual -->
    <div class="modal fade" id="modalGerarRecibo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-receipt"></i> Gerar Recibo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="gerar_recibo">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Aluno</label>
                                <select name="aluno_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($alunos as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Tipo de Recibo</label>
                                <select name="tipo" class="form-control" required>
                                    <option value="mensalidade">Mensalidade</option>
                                    <option value="matricula">Matrícula</option>
                                    <option value="taxa">Taxa Escolar</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label>Valor (Kz)</label>
                                <input type="number" step="0.01" name="valor" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label>Desconto (Kz)</label>
                                <input type="number" step="0.01" name="desconto" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label>Multa (Kz)</label>
                                <input type="number" step="0.01" name="multa" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label>Juros (Kz)</label>
                                <input type="number" step="0.01" name="juros" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label>Data do Pagamento</label>
                                <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label>Forma de Pagamento</label>
                                <select name="forma_pagamento_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($formas_pagamento as $fp): ?>
                                    <option value="<?php echo $fp['id']; ?>"><?php echo htmlspecialchars($fp['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" placeholder="Ex: Pagamento de mensalidade referente a Outubro/2024"></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> O número do recibo será gerado automaticamente.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Gerar Recibo</button>
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
        
        // Inicialização condicional do DataTables para evitar erro de contagem de colunas
        $(document).ready(function() {
            var $table = $('#tabelaRecibos');
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
        
        // Manter submenu aberto
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>