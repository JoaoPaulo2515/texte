<?php
// escola/financeiro/taxas/index.php - Lista de Taxas e Multas
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

// Tabela de taxas
$check = $conn->query("SHOW TABLES LIKE 'escola_taxas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_taxas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            tipo ENUM('matricula', 'mensalidade', 'taxa_escolar', 'multa', 'juros', 'outros') DEFAULT 'outros',
            valor DECIMAL(15,2) DEFAULT 0,
            percentual DECIMAL(5,2) DEFAULT 0,
            aplicacao ENUM('fixo', 'percentual') DEFAULT 'fixo',
            periodo VARCHAR(50),
            data_inicio DATE,
            data_fim DATE,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            INDEX idx_escola (escola_id),
            INDEX idx_status (status),
            INDEX idx_tipo (tipo)
        )
    ");
}

// Tabela de isenções
$check = $conn->query("SHOW TABLES LIKE 'escola_isencoes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_isencoes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            aluno_id INT NOT NULL,
            tipo ENUM('bolsa', 'desconto', 'isencao_total', 'isencao_parcial') NOT NULL,
            percentual_desconto DECIMAL(5,2) DEFAULT 0,
            motivo TEXT,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            status ENUM('ativo', 'expirado', 'cancelado') DEFAULT 'ativo',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            INDEX idx_escola (escola_id),
            INDEX idx_aluno (aluno_id),
            INDEX idx_status (status)
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Ativar/Desativar taxa
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE escola_taxas SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: index.php");
    exit;
}

// Excluir taxa
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM escola_taxas WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Taxa excluída!";
    header("Location: index.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$tipo_filter = $_GET['tipo'] ?? '';
$status_filter = $_GET['status'] ?? '';

$sql = "SELECT * FROM escola_taxas WHERE escola_id = :escola_id";
$params = [':escola_id' => $escola_id];

if ($tipo_filter) {
    $sql .= " AND tipo = :tipo";
    $params[':tipo'] = $tipo_filter;
}
if ($status_filter) {
    $sql .= " AND status = :status";
    $params[':status'] = $status_filter;
}

$sql .= " ORDER BY tipo, nome";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$taxas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => count($taxas),
    'ativas' => 0,
    'multas' => 0,
    'juros' => 0
];

foreach ($taxas as $t) {
    if ($t['status'] == 'ativo') $stats['ativas']++;
    if ($t['tipo'] == 'multa') $stats['multas']++;
    if ($t['tipo'] == 'juros') $stats['juros']++;
}

$tipos = [
    'matricula' => 'Matrícula',
    'mensalidade' => 'Mensalidade',
    'taxa_escolar' => 'Taxa Escolar',
    'multa' => 'Multa',
    'juros' => 'Juros',
    'outros' => 'Outros'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taxas e Multas | SIGE Angola</title>
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
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
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
                    <li class="nav-item"><a href="../balancete/index.php" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                    <li class="nav-item"><a href="../orcamento/index.php" class="nav-link"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-percent"></i> Taxas</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-percent"></i> Taxas e Multas
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <a href="configurar.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-plus"></i> Nova Taxa
                </a>
                <a href="aplicar.php" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-play"></i> Aplicar Taxas
                </a>
                <a href="isencoes.php" class="btn btn-info btn-sm">
                    <i class="fas fa-gift"></i> Isenções
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
                <div class="stat-label">Total de Taxas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $stats['ativas']; ?></div>
                <div class="stat-label">Taxas Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['multas']; ?></div>
                <div class="stat-label">Multas Cadastradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['juros']; ?></div>
                <div class="stat-label">Juros Cadastrados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <select name="tipo" class="form-control">
                        <option value="">Todos os tipos</option>
                        <?php foreach ($tipos as $key => $tipo): ?>
                        <option value="<?php echo $key; ?>" <?php echo $tipo_filter == $key ? 'selected' : ''; ?>><?php echo $tipo; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filter == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Taxas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Taxas e Multas</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaTaxas">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Aplicação</th>
                                <th>Período</th>
                                <th>Vigência</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taxas as $taxa): ?>
                            <tr>
                                <td><?php echo $taxa['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($taxa['nome']); ?></strong><br><small><?php echo htmlspecialchars($taxa['descricao']); ?></small></div>
                                <td><?php echo $tipos[$taxa['tipo']]; ?></div>
                                <td>
                                    <?php if ($taxa['aplicacao'] == 'fixo'): ?>
                                        <?php echo number_format($taxa['valor'], 2, ',', '.'); ?> Kz
                                    <?php else: ?>
                                        <?php echo $taxa['percentual']; ?>%
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><?php echo $taxa['aplicacao'] == 'fixo' ? 'Valor Fixo' : 'Percentual'; ?></div>
                                <td><?php echo $taxa['periodo'] ?? '-'; ?></div>
                                <td>
                                    <?php if ($taxa['data_inicio']): ?>
                                        <?php echo date('d/m/Y', strtotime($taxa['data_inicio'])); ?>
                                        até <?php echo date('d/m/Y', strtotime($taxa['data_fim'])); ?>
                                    <?php else: ?>
                                        Sempre
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><span class="badge <?php echo $taxa['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $taxa['status']; ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="configurar.php?id=<?php echo $taxa['id']; ?>" class="btn btn-info"><i class="fas fa-edit"></i></a>
                                        <a href="?toggle=1&id=<?php echo $taxa['id']; ?>&status=<?php echo $taxa['status']; ?>" class="btn btn-success"><i class="fas fa-toggle-<?php echo $taxa['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                        <a href="?delete=1&id=<?php echo $taxa['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir esta taxa?')"><i class="fas fa-trash"></i></a>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre Taxas e Multas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Taxas e Multas?</h6>
                    <p>Taxas são valores adicionais cobrados em situações específicas (matrícula, material, etc.). Multas e juros são aplicados por atraso no pagamento.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Tipos de Taxas:</h6>
                    <ul>
                        <li><strong>Matrícula:</strong> Taxa cobrada no ato da matrícula.</li>
                        <li><strong>Mensalidade:</strong> Taxa aplicada sobre mensalidades.</li>
                        <li><strong>Taxa Escolar:</strong> Taxas adicionais (laboratório, atividades).</li>
                        <li><strong>Multa:</strong> Penalidade por atraso no pagamento.</li>
                        <li><strong>Juros:</strong> Juros moratórios sobre valores em atraso.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Configure taxas com antecedência para o próximo ano letivo.</li>
                        <li>Use a opção "Aplicar Taxas" para calcular multas automaticamente.</li>
                        <li>Gerencie isenções para alunos com bolsas ou descontos especiais.</li>
                        <li>Mantenha as taxas atualizadas conforme regulamento escolar.</li>
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
        
        $('#tabelaTaxas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>