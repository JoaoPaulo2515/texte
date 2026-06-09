<?php
// escola/financeiro/mensalidades.php - Gestão de Mensalidades
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
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Tabela de mensalidades
$check = $conn->query("SHOW TABLES LIKE 'escola_mensalidades'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_mensalidades (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            aluno_id INT NOT NULL,
            ano_letivo VARCHAR(9) NOT NULL,
            mes INT NOT NULL,
            ano INT NOT NULL,
            valor_original DECIMAL(15,2) NOT NULL,
            valor_pago DECIMAL(15,2) DEFAULT 0,
            desconto DECIMAL(15,2) DEFAULT 0,
            multa DECIMAL(15,2) DEFAULT 0,
            juros DECIMAL(15,2) DEFAULT 0,
            data_vencimento DATE NOT NULL,
            data_pagamento DATE,
            status ENUM('pendente', 'parcial', 'pago', 'vencido', 'cancelado') DEFAULT 'pendente',
            forma_pagamento_id INT,
            observacoes TEXT,
            comprovativo VARCHAR(255),
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// Tabela de histórico de pagamentos
$check = $conn->query("SHOW TABLES LIKE 'escola_historicos_pagamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_historicos_pagamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            mensalidade_id INT NOT NULL,
            valor_pago DECIMAL(15,2) NOT NULL,
            data_pagamento DATE NOT NULL,
            forma_pagamento_id INT,
            observacoes TEXT,
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (mensalidade_id) REFERENCES escola_mensalidades(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function calcularMultaJuros($valor, $data_vencimento, $data_pagamento, $taxa_multa = 2, $taxa_juros = 1) {
    $multa = 0;
    $juros = 0;
    
    if ($data_pagamento > $data_vencimento) {
        $dias_atraso = (strtotime($data_pagamento) - strtotime($data_vencimento)) / 86400;
        
        if ($dias_atraso > 0) {
            $multa = $valor * ($taxa_multa / 100);
            $juros = $valor * ($taxa_juros / 100) * ($dias_atraso / 30);
        }
    }
    
    return ['multa' => round($multa, 2), 'juros' => round($juros, 2)];
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Gerar mensalidades em massa
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'gerar_mensalidades') {
    $turma_id = $_POST['turma_id'] ?? 0;
    $ano_letivo = $_POST['ano_letivo'];
    $mes_inicio = $_POST['mes_inicio'];
    $mes_fim = $_POST['mes_fim'];
    $valor_mensalidade = str_replace(',', '', $_POST['valor_mensalidade']);
    $data_vencimento_base = $_POST['data_vencimento'];
    
    // Buscar alunos da turma
    $stmt = $conn->prepare("
        SELECT e.id 
        FROM estudantes e
        JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND e.escola_id = :escola_id
    ");
    $stmt->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    $ano = date('Y');
    
    foreach ($alunos as $aluno) {
        for ($mes = $mes_inicio; $mes <= $mes_fim; $mes++) {
            // Verificar se já existe
            $check = $conn->prepare("
                SELECT id FROM escola_mensalidades 
                WHERE aluno_id = :aluno_id AND mes = :mes AND ano = :ano AND ano_letivo = :ano_letivo
            ");
            $check->execute([
                ':aluno_id' => $aluno['id'],
                ':mes' => $mes,
                ':ano' => $ano,
                ':ano_letivo' => $ano_letivo
            ]);
            
            if ($check->rowCount() == 0) {
                $data_vencimento = date('Y-m-d', strtotime("$ano-$mes-" . date('d', strtotime($data_vencimento_base))));
                
                $stmt_insert = $conn->prepare("
                    INSERT INTO escola_mensalidades 
                    (escola_id, aluno_id, ano_letivo, mes, ano, valor_original, valor_pago, data_vencimento, status)
                    VALUES (:escola_id, :aluno_id, :ano_letivo, :mes, :ano, :valor, 0, :data_vencimento, 'pendente')
                ");
                $stmt_insert->execute([
                    ':escola_id' => $escola_id,
                    ':aluno_id' => $aluno['id'],
                    ':ano_letivo' => $ano_letivo,
                    ':mes' => $mes,
                    ':ano' => $ano,
                    ':valor' => $valor_mensalidade,
                    ':data_vencimento' => $data_vencimento
                ]);
                $count++;
            }
        }
    }
    
    $_SESSION['mensagem'] = "$count mensalidade(s) gerada(s) com sucesso!";
    header("Location: mensalidades.php");
    exit;
}

// Registrar pagamento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'registrar_pagamento') {
    $mensalidade_id = $_POST['mensalidade_id'];
    $valor_pago = str_replace(',', '', $_POST['valor_pago']);
    $data_pagamento = $_POST['data_pagamento'];
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    $observacoes = $_POST['observacoes'];
    
    // Buscar dados da mensalidade
    $stmt = $conn->prepare("
        SELECT m.*, e.nome as aluno_nome 
        FROM escola_mensalidades m
        JOIN estudantes e ON e.id = m.aluno_id
        WHERE m.id = :id AND m.escola_id = :escola_id
    ");
    $stmt->execute([':id' => $mensalidade_id, ':escola_id' => $escola_id]);
    $mensalidade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mensalidade) {
        $_SESSION['erro'] = "Mensalidade não encontrada!";
        header("Location: mensalidades.php");
        exit;
    }
    
    // Calcular multa e juros se for pagamento após vencimento
    $multa_juros = calcularMultaJuros($mensalidade['valor_original'], $mensalidade['data_vencimento'], $data_pagamento);
    $valor_total = $valor_pago;
    
    // Registrar no histórico
    $stmt_hist = $conn->prepare("
        INSERT INTO escola_historicos_pagamento 
        (escola_id, mensalidade_id, valor_pago, data_pagamento, forma_pagamento_id, observacoes, usuario_id)
        VALUES (:escola_id, :mensalidade_id, :valor_pago, :data_pagamento, :forma_pagamento_id, :observacoes, :usuario_id)
    ");
    $stmt_hist->execute([
        ':escola_id' => $escola_id,
        ':mensalidade_id' => $mensalidade_id,
        ':valor_pago' => $valor_pago,
        ':data_pagamento' => $data_pagamento,
        ':forma_pagamento_id' => $forma_pagamento_id,
        ':observacoes' => $observacoes,
        ':usuario_id' => $usuario_id
    ]);
    
    // Atualizar mensalidade
    $novo_valor_pago = $mensalidade['valor_pago'] + $valor_pago;
    $novo_status = ($novo_valor_pago >= $mensalidade['valor_original']) ? 'pago' : 'parcial';
    
    $stmt_upd = $conn->prepare("
        UPDATE escola_mensalidades 
        SET valor_pago = :valor_pago, 
            data_pagamento = :data_pagamento,
            multa = :multa,
            juros = :juros,
            status = :status,
            forma_pagamento_id = :forma_pagamento_id,
            observacoes = :observacoes
        WHERE id = :id
    ");
    $stmt_upd->execute([
        ':valor_pago' => $novo_valor_pago,
        ':data_pagamento' => $data_pagamento,
        ':multa' => $multa_juros['multa'],
        ':juros' => $multa_juros['juros'],
        ':status' => $novo_status,
        ':forma_pagamento_id' => $forma_pagamento_id,
        ':observacoes' => $observacoes,
        ':id' => $mensalidade_id
    ]);
    
    $_SESSION['mensagem'] = "Pagamento registrado com sucesso!";
    header("Location: mensalidades.php");
    exit;
}

// Atualizar status de mensalidades vencidas
if (isset($_GET['atualizar_vencidos'])) {
    $stmt = $conn->prepare("
        UPDATE escola_mensalidades 
        SET status = 'vencido' 
        WHERE status = 'pendente' AND data_vencimento < CURDATE() AND escola_id = :escola_id
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status das mensalidades atualizado!";
    header("Location: mensalidades.php");
    exit;
}

// Cancelar mensalidade
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE escola_mensalidades SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Mensalidade cancelada!";
    header("Location: mensalidades.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Filtros
$aluno_filter = $_GET['aluno'] ?? '';
$turma_filter = $_GET['turma'] ?? '';
$status_filter = $_GET['status'] ?? '';
$mes_filter = $_GET['mes'] ?? '';
$ano_filter = $_GET['ano'] ?? date('Y');

// Buscar turmas
$turmas = $conn->prepare("SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// Query principal
$sql = "
    SELECT m.*, 
           u.nome as aluno_nome, 
           e.matricula,
           t.nome as turma_nome,
           t.ano as turma_ano,
           f.nome as forma_pagamento_nome
    FROM escola_mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    LEFT JOIN escola_formas_pagamento f ON f.id = m.forma_pagamento_id
    WHERE m.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($aluno_filter) {
    $sql .= " AND m.aluno_id = :aluno_id";
    $params[':aluno_id'] = $aluno_filter;
}
if ($turma_filter) {
    $sql .= " AND t.id = :turma_id";
    $params[':turma_id'] = $turma_filter;
}
if ($status_filter) {
    $sql .= " AND m.status = :status";
    $params[':status'] = $status_filter;
}
if ($mes_filter) {
    $sql .= " AND m.mes = :mes";
    $params[':mes'] = $mes_filter;
}
if ($ano_filter) {
    $sql .= " AND m.ano = :ano";
    $params[':ano'] = $ano_filter;
}

$sql .= " ORDER BY m.data_vencimento ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total_pendente' => 0,
    'total_pago' => 0,
    'total_vencido' => 0,
    'valor_total' => 0,
    'valor_recebido' => 0,
    'valor_pendente' => 0
];

foreach ($mensalidades as $m) {
    $stats['valor_total'] += $m['valor_original'];
    $stats['valor_recebido'] += $m['valor_pago'];
    
    if ($m['status'] == 'pendente') {
        $stats['total_pendente']++;
        $stats['valor_pendente'] += ($m['valor_original'] - $m['valor_pago']);
    } elseif ($m['status'] == 'pago') {
        $stats['total_pago']++;
    } elseif ($m['status'] == 'vencido') {
        $stats['total_vencido']++;
        $stats['valor_pendente'] += ($m['valor_original'] - $m['valor_pago']);
    }
}

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensalidades | Financeiro | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.css"></script>
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
        
        .badge-pendente { background: #ffc107; color: #000; }
        .badge-pago { background: #28a745; color: white; }
        .badge-vencido { background: #dc3545; color: white; }
        .badge-parcial { background: #17a2b8; color: white; }
        .badge-cancelado { background: #6c757d; color: white; }
        
        .table-responsive { overflow-x: auto; }
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        
        .valor-pendente { color: #dc3545; font-weight: bold; }
        .valor-pago { color: #28a745; font-weight: bold; }
        
        .btn-gerar { background: #28a745; color: white; }
        .btn-gerar:hover { background: #1e7e34; color: white; }
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
            <h2><i class="fas fa-calendar-dollar"></i> Gestão de Mensalidades</h2>
            <div>
                <a href="?atualizar_vencidos=1" class="btn btn-warning btn-sm me-2" onclick="return confirm('Atualizar status das mensalidades vencidas?')">
                    <i class="fas fa-sync-alt"></i> Atualizar Vencidos
                </a>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGerarMensalidades">
                    <i class="fas fa-plus"></i> Gerar Mensalidades
                </button>
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
                <div class="stat-value"><?php echo number_format($stats['valor_total'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Geral</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($stats['valor_recebido'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Recebido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($stats['valor_pendente'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_pendente'] + $stats['total_vencido']; ?></div>
                <div class="stat-label">Mensalidades Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_pago']; ?></div>
                <div class="stat-label">Mensalidades Pagas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_vencido']; ?></div>
                <div class="stat-label">Mensalidades Vencidas</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <select name="aluno" class="form-control">
                        <option value="">Todos os alunos</option>
                        <?php
                        $alunos = $conn->prepare("
                            SELECT e.id, u.nome, e.matricula 
                            FROM estudantes e
                            JOIN usuarios u ON u.id = e.usuario_id
                            WHERE e.escola_id = :escola_id
                            ORDER BY u.nome
                        ");
                        $alunos->execute([':escola_id' => $escola_id]);
                        $alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($alunos as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo $aluno_filter == $a['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="turma" class="form-control">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $turma_filter == $t['id'] ? 'selected' : ''; ?>>
                            <?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="pendente" <?php echo $status_filter == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="pago" <?php echo $status_filter == 'pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="vencido" <?php echo $status_filter == 'vencido' ? 'selected' : ''; ?>>Vencido</option>
                        <option value="parcial" <?php echo $status_filter == 'parcial' ? 'selected' : ''; ?>>Parcial</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="mes" class="form-control">
                        <option value="">Todos os meses</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $mes_filter == $i ? 'selected' : ''; ?>><?php echo $meses[$i]; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="ano" class="form-control">
                        <option value="">Todos os anos</option>
                        <?php for ($i = 2023; $i <= date('Y') + 1; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano_filter == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Mensalidades -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Mensalidades</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaMensalidades">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Turma</th>
                                <th>Mês/Ano</th>
                                <th>Valor</th>
                                <th>Pago</th>
                                <th>Saldo</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mensalidades as $m): 
                                $saldo = $m['valor_original'] - $m['valor_pago'];
                            ?>
                            <tr>
                                <td><?php echo $m['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($m['aluno_nome']); ?></strong></td>
                                <td><?php echo $m['matricula']; ?></td>
                                <td><?php echo $m['turma_ano']; ?> - <?php echo htmlspecialchars($m['turma_nome']); ?></td>
                                <td><?php echo $meses[$m['mes']]; ?>/<?php echo $m['ano']; ?></td>
                                <td><?php echo number_format($m['valor_original'], 2, ',', '.'); ?> Kz</div>
                                <td><span class="valor-pago"><?php echo number_format($m['valor_pago'], 2, ',', '.'); ?> Kz</span></div>
                                <td><span class="<?php echo $saldo > 0 ? 'valor-pendente' : 'valor-pago'; ?>"><?php echo number_format($saldo, 2, ',', '.'); ?> Kz</span></div>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($m['data_vencimento'])); ?>
                                    <?php if ($m['data_vencimento'] < date('Y-m-d') && $m['status'] == 'pendente'): ?>
                                        <br><small class="text-danger">(Vencida)</small>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><span class="badge badge-<?php echo $m['status']; ?>"><?php echo ucfirst($m['status']); ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-success" onclick="registrarPagamento(<?php echo $m['id']; ?>, '<?php echo addslashes($m['aluno_nome']); ?>', <?php echo $saldo; ?>)"
                                                <?php echo $m['status'] == 'pago' ? 'disabled' : ''; ?>>
                                            <i class="fas fa-money-bill"></i>
                                        </button>
                                        <button class="btn btn-info" onclick="verDetalhes(<?php echo $m['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="?cancelar=1&id=<?php echo $m['id']; ?>" class="btn btn-danger" onclick="return confirm('Cancelar esta mensalidade?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </div>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Gerar Mensalidades -->
    <div class="modal fade" id="modalGerarMensalidades" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Gerar Mensalidades em Massa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="gerar_mensalidades">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Turma</label>
                            <select name="turma_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Ano Letivo</label>
                            <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Mês Início</label>
                                <select name="mes_inicio" class="form-control" required>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $meses[$i]; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Mês Fim</label>
                                <select name="mes_fim" class="form-control" required>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $meses[$i]; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Valor da Mensalidade (Kz)</label>
                            <input type="number" step="0.01" name="valor_mensalidade" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Dia de Vencimento</label>
                            <select name="data_vencimento" class="form-control" required>
                                <?php for ($d = 1; $d <= 28; $d++): ?>
                                <option value="<?php echo $d; ?>">Dia <?php echo $d; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Serão geradas mensalidades para todos os alunos da turma selecionada.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Gerar Mensalidades</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Pagamento -->
    <div class="modal fade" id="modalRegistrarPagamento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill"></i> Registrar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="registrar_pagamento">
                    <input type="hidden" name="mensalidade_id" id="pagamento_mensalidade_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Aluno:</strong> <span id="pagamento_aluno_nome"></span><br>
                            <strong>Saldo Devedor:</strong> <span id="pagamento_saldo" class="text-danger fw-bold"></span>
                        </div>
                        <div class="mb-3">
                            <label>Valor a Pagar (Kz)</label>
                            <input type="number" step="0.01" name="valor_pago" id="pagamento_valor" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Data do Pagamento</label>
                            <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Forma de Pagamento</label>
                            <select name="forma_pagamento_id" class="form-control">
                                <option value="">Selecione...</option>
                                <?php foreach ($formas_pagamento as $fp): ?>
                                <option value="<?php echo $fp['id']; ?>"><?php echo htmlspecialchars($fp['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Pagamentos após o vencimento estão sujeitos a multa e juros.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar Pagamento</button>
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
        
        $('#tabelaMensalidades').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        function registrarPagamento(id, alunoNome, saldo) {
            $('#pagamento_mensalidade_id').val(id);
            $('#pagamento_aluno_nome').text(alunoNome);
            $('#pagamento_saldo').text(parseFloat(saldo).toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz');
            $('#pagamento_valor').val(saldo);
            $('#modalRegistrarPagamento').modal('show');
        }
        
        function verDetalhes(id) {
            alert('Detalhes da mensalidade ID: ' + id + ' - Funcionalidade em desenvolvimento');
        }
        
        // Manter submenu aberto
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>