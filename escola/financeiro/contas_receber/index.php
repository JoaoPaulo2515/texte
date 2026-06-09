<?php
// escola/financeiro/contas_receber/index.php - Lista de Contas a Receber
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// VERIFICAR E CRIAR TABELA
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'escola_contas_receber'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_contas_receber (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            aluno_id INT NOT NULL,
            documento VARCHAR(50),
            descricao TEXT NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            valor_recebido DECIMAL(15,2) DEFAULT 0,
            desconto DECIMAL(15,2) DEFAULT 0,
            multa DECIMAL(15,2) DEFAULT 0,
            juros DECIMAL(15,2) DEFAULT 0,
            data_emissao DATE NOT NULL,
            data_vencimento DATE NOT NULL,
            data_recebimento DATE,
            forma_pagamento_id INT,
            parcela INT DEFAULT 1,
            total_parcelas INT DEFAULT 1,
            status ENUM('pendente', 'parcial', 'recebido', 'cancelado', 'vencido') DEFAULT 'pendente',
            observacoes TEXT,
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_vencimento (data_vencimento),
            INDEX idx_aluno (aluno_id)
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Excluir conta a receber
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar se já foi recebido
    $check = $conn->prepare("SELECT status FROM escola_contas_receber WHERE id = :id AND escola_id = :escola_id");
    $check->execute([':id' => $id, ':escola_id' => $escola_id]);
    $conta = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($conta && $conta['status'] == 'recebido') {
        $_SESSION['erro'] = "Não é possível excluir uma conta já recebida!";
    } else {
        $stmt = $conn->prepare("DELETE FROM escola_contas_receber WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['mensagem'] = "Conta excluída com sucesso!";
    }
    header("Location: index.php");
    exit;
}

// Cancelar conta
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE escola_contas_receber SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Conta cancelada!";
    header("Location: index.php");
    exit;
}

// Atualizar status de contas vencidas
if (isset($_GET['atualizar_vencidos'])) {
    $stmt = $conn->prepare("
        UPDATE escola_contas_receber 
        SET status = 'vencido' 
        WHERE status = 'pendente' AND data_vencimento < CURDATE() AND escola_id = :escola_id
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Status das contas atualizado!";
    header("Location: index.php");
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
    SELECT c.*, 
           u.nome as aluno_nome, 
           e.matricula,
           f.nome as forma_pagamento_nome
    FROM escola_contas_receber c
    JOIN estudantes e ON e.id = c.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN escola_formas_pagamento f ON f.id = c.forma_pagamento_id
    WHERE c.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($aluno_filter) {
    $sql .= " AND c.aluno_id = :aluno_id";
    $params[':aluno_id'] = $aluno_filter;
}
if ($status_filter) {
    $sql .= " AND c.status = :status";
    $params[':status'] = $status_filter;
}
if ($data_inicio && $data_fim) {
    $sql .= " AND DATE(c.data_vencimento) BETWEEN :data_inicio AND :data_fim";
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
}

$sql .= " ORDER BY c.data_vencimento ASC, c.status ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para select
$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total_geral' => 0,
    'total_recebido' => 0,
    'total_pendente' => 0,
    'total_vencido' => 0,
    'quantidade_pendente' => 0,
    'quantidade_vencido' => 0,
    'quantidade_recebido' => 0
];

foreach ($contas as $c) {
    $stats['total_geral'] += $c['valor'];
    $stats['total_recebido'] += $c['valor_recebido'];
    
    if ($c['status'] == 'pendente') {
        $stats['total_pendente'] += ($c['valor'] - $c['valor_recebido']);
        $stats['quantidade_pendente']++;
    } elseif ($c['status'] == 'vencido') {
        $stats['total_vencido'] += ($c['valor'] - $c['valor_recebido']);
        $stats['quantidade_vencido']++;
    } elseif ($c['status'] == 'recebido') {
        $stats['quantidade_recebido']++;
    }
}

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas a Receber | Financeiro | SIGE Angola</title>
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
        
        .badge-pendente { background: #ffc107; color: #000; }
        .badge-recebido { background: #28a745; color: white; }
        .badge-vencido { background: #dc3545; color: white; }
        .badge-parcial { background: #17a2b8; color: white; }
        .badge-cancelado { background: #6c757d; color: white; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .valor-pendente { color: #dc3545; font-weight: bold; }
        .valor-recebido { color: #28a745; font-weight: bold; }
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-arrow-up"></i> Contas a Receber</a></li>
                    <li class="nav-item"><a href="../contas_pagar/index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
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
            <h2><i class="fas fa-arrow-up"></i> Contas a Receber</h2>
            <div>
                <a href="?atualizar_vencidos=1" class="btn btn-warning btn-sm me-2" onclick="return confirm('Atualizar status das contas vencidas?')">
                    <i class="fas fa-sync-alt"></i> Atualizar Vencidos
                </a>
                <a href="adicionar.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Nova Conta
                </a>
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
                <div class="stat-value"><?php echo number_format($stats['total_geral'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Geral</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($stats['total_recebido'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Recebido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($stats['total_pendente'] + $stats['total_vencido'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['quantidade_pendente'] + $stats['quantidade_vencido']; ?></div>
                <div class="stat-label">Contas Pendentes</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
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
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="pendente" <?php echo $status_filter == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="recebido" <?php echo $status_filter == 'recebido' ? 'selected' : ''; ?>>Recebido</option>
                        <option value="vencido" <?php echo $status_filter == 'vencido' ? 'selected' : ''; ?>>Vencido</option>
                        <option value="parcial" <?php echo $status_filter == 'parcial' ? 'selected' : ''; ?>>Parcial</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Contas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Contas a Receber</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaContas">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Vencimento</th>
                                <th>Aluno</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Recebido</th>
                                <th>Saldo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contas as $conta): 
                                $saldo = $conta['valor'] - $conta['valor_recebido'];
                            ?>
                            <tr>
                                <td><?php echo $conta['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($conta['data_emissao'])); ?></td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($conta['data_vencimento'])); ?>
                                    <?php if ($conta['data_vencimento'] < date('Y-m-d') && $conta['status'] == 'pendente'): ?>
                                        <br><span class="badge bg-danger">Vencida</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><strong><?php echo htmlspecialchars($conta['aluno_nome']); ?></strong><br><small><?php echo $conta['matricula']; ?></small></div>
                                <td><?php echo htmlspecialchars($conta['descricao']); ?></div>
                                <td><?php echo number_format($conta['valor'], 2, ',', '.'); ?> Kz</div>
                                <td><span class="valor-recebido"><?php echo number_format($conta['valor_recebido'], 2, ',', '.'); ?> Kz</span></div>
                                <td><span class="<?php echo $saldo > 0 ? 'valor-pendente' : 'valor-recebido'; ?>"><?php echo number_format($saldo, 2, ',', '.'); ?> Kz</span></div>
                                <td><span class="badge badge-<?php echo $conta['status']; ?>"><?php echo ucfirst($conta['status']); ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="editar.php?id=<?php echo $conta['id']; ?>" class="btn btn-info" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($conta['status'] != 'recebido'): ?>
                                        <a href="receber.php?id=<?php echo $conta['id']; ?>" class="btn btn-success" title="Registrar Recebimento">
                                            <i class="fas fa-money-bill"></i>
                                        </a>
                                        <a href="?cancelar=1&id=<?php echo $conta['id']; ?>" class="btn btn-warning" title="Cancelar" onclick="return confirm('Cancelar esta conta?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?delete=1&id=<?php echo $conta['id']; ?>" class="btn btn-danger" title="Excluir" onclick="return confirm('Excluir esta conta?')">
                                            <i class="fas fa-trash"></i>
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
        
        $('#tabelaContas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[2, 'asc']]
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>