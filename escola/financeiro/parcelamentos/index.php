<?php
// escola/financeiro/parcelamentos/index.php - Acordos de Parcelamento
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
// VERIFICAR E CRIAR TABELAS
// ============================================

// Tabela de acordos de parcelamento
$check = $conn->query("SHOW TABLES LIKE 'escola_acordos_parcelamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_acordos_parcelamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            aluno_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            valor_total DECIMAL(15,2) NOT NULL,
            numero_parcelas INT NOT NULL,
            valor_parcela DECIMAL(15,2) NOT NULL,
            entrada DECIMAL(15,2) DEFAULT 0,
            data_acordo DATE NOT NULL,
            status ENUM('ativo', 'concluido', 'cancelado') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            INDEX idx_escola (escola_id),
            INDEX idx_aluno (aluno_id),
            INDEX idx_status (status)
        )
    ");
}

// Tabela de parcelas do acordo
$check = $conn->query("SHOW TABLES LIKE 'escola_parcelas_acordo'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_parcelas_acordo (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            acordo_id INT NOT NULL,
            numero_parcela INT NOT NULL,
            valor DECIMAL(15,2) NOT NULL,
            valor_pago DECIMAL(15,2) DEFAULT 0,
            data_vencimento DATE NOT NULL,
            data_pagamento DATE,
            status ENUM('pendente', 'pago', 'vencido', 'parcial') DEFAULT 'pendente',
            forma_pagamento_id INT,
            observacoes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (acordo_id) REFERENCES escola_acordos_parcelamento(id) ON DELETE CASCADE,
            FOREIGN KEY (forma_pagamento_id) REFERENCES escola_formas_pagamento(id) ON DELETE SET NULL,
            INDEX idx_acordo (acordo_id),
            INDEX idx_status (status),
            INDEX idx_vencimento (data_vencimento)
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Cancelar acordo
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    $stmt = $conn->prepare("UPDATE escola_acordos_parcelamento SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Acordo cancelado!";
    header("Location: index.php");
    exit;
}

// Registrar pagamento de parcela
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'pagar_parcela') {
    $parcela_id = $_POST['parcela_id'];
    $valor_pago = str_replace(',', '', $_POST['valor_pago']);
    $data_pagamento = $_POST['data_pagamento'];
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    
    // Buscar parcela
    $stmt = $conn->prepare("
        SELECT p.*, a.aluno_id, a.titulo 
        FROM escola_parcelas_acordo p
        JOIN escola_acordos_parcelamento a ON a.id = p.acordo_id
        WHERE p.id = :id AND p.escola_id = :escola_id
    ");
    $stmt->execute([':id' => $parcela_id, ':escola_id' => $escola_id]);
    $parcela = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parcela) {
        $novo_valor_pago = $parcela['valor_pago'] + $valor_pago;
        $novo_status = ($novo_valor_pago >= $parcela['valor']) ? 'pago' : 'parcial';
        
        $stmt = $conn->prepare("
            UPDATE escola_parcelas_acordo 
            SET valor_pago = :valor_pago, data_pagamento = :data_pagamento, 
                forma_pagamento_id = :forma_pagamento_id, status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':valor_pago' => $novo_valor_pago,
            ':data_pagamento' => $data_pagamento,
            ':forma_pagamento_id' => $forma_pagamento_id,
            ':status' => $novo_status,
            ':id' => $parcela_id
        ]);
        
        // Verificar se todas as parcelas estão pagas
        $stmt = $conn->prepare("
            SELECT COUNT(*) as pendentes FROM escola_parcelas_acordo 
            WHERE acordo_id = :acordo_id AND status != 'pago'
        ");
        $stmt->execute([':acordo_id' => $parcela['acordo_id']]);
        $pendentes = $stmt->fetch(PDO::FETCH_ASSOC)['pendentes'];
        
        if ($pendentes == 0) {
            $stmt = $conn->prepare("UPDATE escola_acordos_parcelamento SET status = 'concluido' WHERE id = :id");
            $stmt->execute([':id' => $parcela['acordo_id']]);
        }
        
        $_SESSION['mensagem'] = "Pagamento registrado com sucesso!";
    }
    
    header("Location: index.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? '';
$aluno_filter = $_GET['aluno'] ?? '';

$sql = "
    SELECT a.*, u.nome as aluno_nome, e.matricula,
           (SELECT COUNT(*) FROM escola_parcelas_acordo WHERE acordo_id = a.id AND status = 'pago') as parcelas_pagas,
           (SELECT COUNT(*) FROM escola_parcelas_acordo WHERE acordo_id = a.id) as total_parcelas
    FROM escola_acordos_parcelamento a
    JOIN estudantes e ON e.id = a.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE a.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($status_filter) {
    $sql .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}
if ($aluno_filter) {
    $sql .= " AND a.aluno_id = :aluno_id";
    $params[':aluno_id'] = $aluno_filter;
}

$sql .= " ORDER BY a.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$acordos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para filtro
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
    'total' => count($acordos),
    'ativos' => 0,
    'concluidos' => 0,
    'cancelados' => 0,
    'valor_total' => 0
];

foreach ($acordos as $a) {
    if ($a['status'] == 'ativo') $stats['ativos']++;
    if ($a['status'] == 'concluido') $stats['concluidos']++;
    if ($a['status'] == 'cancelado') $stats['cancelados']++;
    $stats['valor_total'] += $a['valor_total'];
}

$status_nomes = [
    'ativo' => 'Ativo',
    'concluido' => 'Concluído',
    'cancelado' => 'Cancelado'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcelamentos | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        
        .badge-ativo { background: #28a745; color: white; }
        .badge-concluido { background: #17a2b8; color: white; }
        .badge-cancelado { background: #dc3545; color: white; }
        
        .progress-bar-custom { height: 6px; border-radius: 3px; }
        
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
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
                    <li class="nav-item"><a href="novo.php" class="nav-link"><i class="fas fa-plus"></i> Novo Acordo</a></li>
                    <li class="nav-item"><a href="simular.php" class="nav-link"><i class="fas fa-calculator"></i> Simular</a></li>
                    <li class="nav-item"><a href="acompanhar.php" class="nav-link"><i class="fas fa-chart-line"></i> Acompanhar</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-hand-holding-usd"></i> Acordos de Parcelamento
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="novo.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-plus"></i> Novo Acordo
                </a>
                <a href="simular.php" class="btn btn-info btn-sm">
                    <i class="fas fa-calculator"></i> Simular
                </a>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total de Acordos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $stats['ativos']; ?></div>
                <div class="stat-label">Acordos Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $stats['concluidos']; ?></div>
                <div class="stat-label">Concluídos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['valor_total'], 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Valor Total</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <select name="aluno" class="form-control">
                        <option value="">Todos os alunos</option>
                        <?php foreach ($alunos as $a): ?>
                        <option value="<?php echo $a['id']; ?>" <?php echo $aluno_filter == $a['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="concluido" <?php echo $status_filter == 'concluido' ? 'selected' : ''; ?>>Concluído</option>
                        <option value="cancelado" <?php echo $status_filter == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Acordos -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Acordos de Parcelamento</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaAcordos">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Aluno</th>
                                <th>Título</th>
                                <th>Valor Total</th>
                                <th>Parcelas</th>
                                <th>Progresso</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($acordos as $acordo): 
                                $progresso = $acordo['total_parcelas'] > 0 ? round(($acordo['parcelas_pagas'] / $acordo['total_parcelas']) * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo $acordo['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($acordo['data_acordo'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($acordo['aluno_nome']); ?></strong><br><small><?php echo $acordo['matricula']; ?></small></div>
                                <td><?php echo htmlspecialchars($acordo['titulo']); ?></div>
                                <td><?php echo number_format($acordo['valor_total'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo $acordo['parcelas_pagas']; ?>/<?php echo $acordo['total_parcelas']; ?></div>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $progresso; ?>%"></div>
                                        </div>
                                        <span><?php echo $progresso; ?>%</span>
                                    </div>
                                 </div>
                                <td><span class="badge badge-<?php echo $acordo['status']; ?>"><?php echo $status_nomes[$acordo['status']]; ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="acompanhar.php?id=<?php echo $acordo['id']; ?>" class="btn btn-info" title="Acompanhar">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($acordo['status'] == 'ativo'): ?>
                                        <a href="?cancelar=1&id=<?php echo $acordo['id']; ?>" class="btn btn-danger" title="Cancelar" onclick="return confirm('Cancelar este acordo?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                        <?php endif; ?>
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
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Acordos de Parcelamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Acordos de Parcelamento?</h6>
                    <p>Acordos de parcelamento são negociações formais com alunos para dividir débitos pendentes em parcelas fixas.</p>
                    
                    <h6><i class="fas fa-hand-holding-usd text-success"></i> Funcionalidades:</h6>
                    <ul>
                        <li><strong>Novo Acordo:</strong> Crie um acordo personalizado para o aluno.</li>
                        <li><strong>Simular:</strong> Calcule valores de parcelas antes de formalizar.</li>
                        <li><strong>Acompanhar:</strong> Visualize o andamento de cada acordo.</li>
                        <li><strong>Registrar Pagamentos:</strong> Atualize o status das parcelas.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Defina prazos realistas para evitar novas inadimplências.</li>
                        <li>Documente o acordo formalmente.</li>
                        <li>Acompanhe regularmente o cumprimento das parcelas.</li>
                        <li>Ofereça descontos para quitação antecipada quando possível.</li>
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
        
        $('#tabelaAcordos').DataTable({
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