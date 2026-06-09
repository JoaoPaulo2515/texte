<?php
// escola/config/geral/municipio.php - Gestão de Municípios
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
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Verificar se a tabela de províncias existe
$check = $conn->query("SHOW TABLES LIKE 'angola_provincias'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE angola_provincias (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL UNIQUE,
            sigla VARCHAR(5),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Inserir províncias padrão
    $provincias_padrao = [
        ['Bengo', 'BGO'], ['Benguela', 'BGU'], ['Bié', 'BIE'], ['Cabinda', 'CAB'],
        ['Cuando Cubango', 'CCU'], ['Cuanza Norte', 'CNO'], ['Cuanza Sul', 'CSU'], ['Cunene', 'CNN'],
        ['Huambo', 'HUA'], ['Huíla', 'HUI'], ['Luanda', 'LAD'], ['Lunda Norte', 'LNO'],
        ['Lunda Sul', 'LSU'], ['Malanje', 'MAL'], ['Moxico', 'MOX'], ['Namibe', 'NAM'],
        ['Uíge', 'UIG'], ['Zaire', 'ZAI']
    ];
    
    $stmt = $conn->prepare("INSERT INTO angola_provincias (nome, sigla) VALUES (:nome, :sigla)");
    foreach ($provincias_padrao as $prov) {
        $stmt->execute([':nome' => $prov[0], ':sigla' => $prov[1]]);
    }
}

// Verificar se a tabela de municípios existe
$check = $conn->query("SHOW TABLES LIKE 'angola_municipios'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE angola_municipios (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            provincia_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (provincia_id) REFERENCES angola_provincias(id) ON DELETE CASCADE,
            UNIQUE KEY unique_municipio_provincia (nome, provincia_id)
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar município
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_municipio') {
    $nome = trim($_POST['nome']);
    $provincia_id = $_POST['provincia_id'];
    
    // Verificar se já existe
    $check = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = :nome AND provincia_id = :provincia_id");
    $check->execute([':nome' => $nome, ':provincia_id' => $provincia_id]);
    
    if ($check->rowCount() > 0) {
        $_SESSION['erro'] = "Este município já existe nesta província!";
    } else {
        $stmt = $conn->prepare("INSERT INTO angola_municipios (nome, provincia_id) VALUES (:nome, :provincia_id)");
        $stmt->execute([':nome' => $nome, ':provincia_id' => $provincia_id]);
        $_SESSION['mensagem'] = "Município adicionado com sucesso!";
    }
    
    header("Location: municipio.php");
    exit;
}

// Editar município
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_municipio') {
    $id = $_POST['id'];
    $nome = trim($_POST['nome']);
    $provincia_id = $_POST['provincia_id'];
    
    // Verificar se já existe (excluindo o atual)
    $check = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = :nome AND provincia_id = :provincia_id AND id != :id");
    $check->execute([':nome' => $nome, ':provincia_id' => $provincia_id, ':id' => $id]);
    
    if ($check->rowCount() > 0) {
        $_SESSION['erro'] = "Este município já existe nesta província!";
    } else {
        $stmt = $conn->prepare("UPDATE angola_municipios SET nome = :nome, provincia_id = :provincia_id WHERE id = :id");
        $stmt->execute([':id' => $id, ':nome' => $nome, ':provincia_id' => $provincia_id]);
        $_SESSION['mensagem'] = "Município atualizado com sucesso!";
    }
    
    header("Location: municipio.php");
    exit;
}

// Excluir município
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar se existem comunas associadas
    $check = $conn->prepare("SELECT COUNT(*) as total FROM angola_comunas WHERE municipio_id = :municipio_id");
    $check->execute([':municipio_id' => $id]);
    $total_comunas = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_comunas > 0) {
        $_SESSION['erro'] = "Não é possível excluir este município pois existem $total_comunas comunas associadas!";
    } else {
        $stmt = $conn->prepare("DELETE FROM angola_municipios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $_SESSION['mensagem'] = "Município excluído com sucesso!";
    }
    
    header("Location: municipio.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar províncias para o filtro e selects
$provincias = $conn->query("SELECT id, nome, sigla FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Aplicar filtros
$provincia_filter = $_GET['provincia'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "
    SELECT m.*, p.nome as provincia_nome, p.sigla as provincia_sigla,
           (SELECT COUNT(*) FROM angola_comunas WHERE municipio_id = m.id) as total_comunas
    FROM angola_municipios m
    JOIN angola_provincias p ON p.id = m.provincia_id
    WHERE 1=1
";

$params = [];

if ($provincia_filter) {
    $sql .= " AND m.provincia_id = :provincia_id";
    $params[':provincia_id'] = $provincia_filter;
}

if ($search) {
    $sql .= " AND (m.nome LIKE :search OR p.nome LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$sql .= " ORDER BY p.nome, m.nome";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$municipios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_municipios = count($municipios);
$total_provincias = count($provincias);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Municípios | Configurações | SIGE Angola</title>
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
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .table-responsive { overflow-x: auto; }
        .badge-info { background: #17a2b8; color: white; }
        
        .filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
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
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
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
            <h2><i class="fas fa-city"></i> Municípios de Angola</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio">
                <i class="fas fa-plus"></i> Novo Município
            </button>
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
                <div class="stat-value"><?php echo $total_municipios; ?></div>
                <div class="stat-label">Total de Municípios</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_provincias; ?></div>
                <div class="stat-label">Províncias</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($total_municipios / max($total_provincias, 1), 1); ?></div>
                <div class="stat-label">Média por Província</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control" placeholder="Buscar município..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select name="provincia" class="form-control">
                        <option value="">Todas as províncias</option>
                        <?php foreach ($provincias as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $provincia_filter == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Municípios -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Municípios</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaMunicipios">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Município</th>
                                <th>Província</th>
                                <th>Sigla</th>
                                <th>Comunas</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($municipios)): ?>
                                <?php foreach ($municipios as $municipio): ?>
                                <tr>
                                    <td><?php echo $municipio['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($municipio['nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($municipio['provincia_nome']); ?></td>
                                    <td><?php echo $municipio['provincia_sigla']; ?></td>
                                    <td><span class="badge badge-info"><?php echo $municipio['total_comunas']; ?> comunas</span></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editarMunicipio(<?php echo $municipio['id']; ?>, '<?php echo addslashes($municipio['nome']); ?>', <?php echo $municipio['provincia_id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete=1&id=<?php echo $municipio['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza que deseja excluir este município?\n\nAtenção: Todas as comunas associadas também serão afetadas!')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                     </div>
                                 </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                        Nenhum município encontrado
                                     </div>
                                 </div>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Informação sobre níveis administrativos -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <i class="fas fa-info-circle"></i> Níveis Administrativos de Angola
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <i class="fas fa-map-marker-alt fa-2x text-primary mb-2"></i>
                            <h5>Províncias</h5>
                            <p class="mb-0">18 Províncias</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <i class="fas fa-city fa-2x text-success mb-2"></i>
                            <h5>Municípios</h5>
                            <p class="mb-0">164 Municípios</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <i class="fas fa-building fa-2x text-warning mb-2"></i>
                            <h5>Comunas</h5>
                            <p class="mb-0">518 Comunas</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3">
                            <i class="fas fa-home fa-2x text-danger mb-2"></i>
                            <h5>Bairros</h5>
                            <p class="mb-0">Cadastrados no sistema</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Município -->
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Município</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_municipio">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Província</label>
                            <select name="provincia_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($provincias as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?> (<?php echo $p['sigla']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Nome do Município</label>
                            <input type="text" name="nome" class="form-control" required placeholder="Ex: Luanda, Benguela, Huambo">
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Após criar o município, você poderá adicionar as comunas correspondentes.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Município -->
    <div class="modal fade" id="modalEditarMunicipio" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Município</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_municipio">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Província</label>
                            <select name="provincia_id" id="edit_provincia_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($provincias as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?> (<?php echo $p['sigla']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Nome do Município</label>
                            <input type="text" name="nome" id="edit_nome" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
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
        
        // Inicializar DataTable apenas se houver dados
        <?php if (!empty($municipios)): ?>
        $('#tabelaMunicipios').DataTable({
            language: { 
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' 
            },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        <?php endif; ?>
        
        function editarMunicipio(id, nome, provinciaId) {
            $('#edit_id').val(id);
            $('#edit_nome').val(nome);
            $('#edit_provincia_id').val(provinciaId);
            $('#modalEditarMunicipio').modal('show');
        }
        
        // Tooltips para informações
        $(document).ready(function() {
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
</body>
</html>