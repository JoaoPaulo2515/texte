<?php
// super-admin/escolas/index.php - Listagem de Escolas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Filtros
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$provincia_filter = $_GET['provincia'] ?? '';

$query = "
    SELECT e.*, p.nome as plano_nome, 
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (e.nome LIKE :search OR e.subdominio LIKE :search OR e.email LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($status_filter) {
    $query .= " AND e.status = :status";
    $params[':status'] = $status_filter;
}
if ($provincia_filter) {
    $query .= " AND e.provincia = :provincia";
    $params[':provincia'] = $provincia_filter;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Províncias para filtro
$provincias = $conn->query("SELECT DISTINCT provincia FROM escolas WHERE provincia IS NOT NULL ORDER BY provincia")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escolas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .nav-submenu .nav-link { padding: 8px 25px; font-size: 0.9em; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .logo-preview { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
            <div class="user-info-sidebar">
                <small><i class="fas fa-user-shield"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-cog"></i> Configurações</a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../config/sistema.php" class="nav-link"><i class="fas fa-globe"></i> Configurações do Sistema</a></li>
                    <li class="nav-item"><a href="../config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões e Papéis</a></li>
                </ul>
            </li>
            
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-school"></i> Escolas</h2>
            <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova Escola</a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Buscar escola..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                            <option value="trial" <?php echo $status_filter == 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="suspensa" <?php echo $status_filter == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                            <option value="inativa" <?php echo $status_filter == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="provincia" class="form-control">
                            <option value="">Todas as províncias</option>
                            <?php foreach ($provincias as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $provincia_filter == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Logo</th><th>Escola</th><th>Subdomínio</th><th>Província</th><th>Plano</th><th>Status</th><th>Usuários</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($escolas as $escola): ?>
                            <tr>
                                <td>
                                    <?php if ($escola['logo']): ?>
                                        <img src="../../uploads/escolas/thumb_<?php echo $escola['logo']; ?>" class="logo-preview">
                                    <?php else: ?>
                                        <i class="fas fa-school fa-2x text-muted"></i>
                                    <?php endif; ?>
                                   </div>
                                  </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($escola['nome']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($escola['email']); ?></small>
                                   </div>
                                  </td>
                                <td><?php echo $escola['subdominio']; ?>.sige.ao</small></td>
                                <td><?php echo $escola['provincia'] ?? '-'; ?></td>
                                <td><?php echo $escola['plano_nome'] ?? '-'; ?></td>
                                <td><span class="status-badge status-<?php echo $escola['status']; ?>"><?php echo ucfirst($escola['status']); ?></span></td>
                                <td><?php echo $escola['total_usuarios']; ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-info"><i class="fas fa-eye"></i></a>
                                        <a href="editar.php?id=<?php echo $escola['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i></a>
                                        <a href="excluir.php?id=<?php echo $escola['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                   </div>
                                  </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>