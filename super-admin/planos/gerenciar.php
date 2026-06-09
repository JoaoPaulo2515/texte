<?php
// super-admin/planos/gerenciar.php - Gerenciar plano (escolas associadas)
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$id = $_GET['id'] ?? 0;

$stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id");
$stmt->execute([':id' => $id]);
$plano = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plano) {
    header('Location: index.php?error=Plano não encontrado');
    exit;
}

// Escolas que usam este plano
$stmt = $conn->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id AND status = 'ativa') as tem_assinatura
    FROM escolas e
    WHERE e.plano_id = :plano_id
    ORDER BY e.created_at DESC
");
$stmt->execute([':plano_id' => $id]);
$escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_escolas = count($escolas);
$total_usuarios = array_sum(array_column($escolas, 'total_usuarios'));

$modulos = json_decode($plano['modulos_disponiveis'], true) ?: [];
$recursos = json_decode($plano['recursos'], true) ?: [];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Plano - <?php echo htmlspecialchars($plano['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Navegação */
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #006B3E;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-ativa {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .status-trial {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .status-suspensa {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
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
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <!-- Configurações com Submenu -->
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
            <div>
                <h2><i class="fas fa-cog"></i> Gerenciar Plano: <?php echo htmlspecialchars($plano['nome']); ?></h2>
                <small>Gerencie as escolas associadas a este plano</small>
            </div>
            <div>
                <a href="editar.php?id=<?php echo $plano['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar Plano</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_escolas; ?></div>
                <div>Escolas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_usuarios; ?></div>
                <div>Usuários</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?></div>
                <div>Preço Mensal</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><i class="fas fa-info-circle"></i> Detalhes do Plano</div>
                    <div class="card-body">
                        <p><strong>Nome:</strong> <?php echo htmlspecialchars($plano['nome']); ?></p>
                        <p><strong>Descrição:</strong> <?php echo htmlspecialchars($plano['descricao']); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge bg-<?php echo $plano['status'] == 'ativo' ? 'success' : 'danger'; ?>">
                                <?php echo ucfirst($plano['status']); ?>
                            </span>
                        </p>
                        <hr>
                        <p><strong>Preços:</strong></p>
                        <ul>
                            <li>Mensal: KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?></li>
                            <li>Anual: KZ <?php echo number_format($plano['preco_anual'], 2, ',', '.'); ?></li>
                        </ul>
                        <hr>
                        <p><strong>Limites:</strong></p>
                        <ul>
                            <li>Alunos: <?php echo $plano['limite_alunos'] ?: 'Ilimitado'; ?></li>
                            <li>Professores: <?php echo $plano['limite_professores'] ?: 'Ilimitado'; ?></li>
                            <li>Turmas: <?php echo $plano['limite_turmas'] ?: 'Ilimitado'; ?></li>
                        </ul>
                        <hr>
                        <p><strong>Recursos:</strong></p>
                        <ul>
                            <li>Suporte: <?php echo ucfirst($recursos['suporte'] ?? 'Email'); ?></li>
                            <li>Armazenamento: <?php echo $recursos['armazenamento'] ?? 10; ?> GB</li>
                            <?php if (!empty($recursos['api'])): ?><li>✓ API de Integração</li><?php endif; ?>
                            <?php if (!empty($recursos['certificado_digital'])): ?><li>✓ Certificado Digital</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-school"></i> Escolas com este Plano</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Escola</th>
                                        <th>Subdomínio</th>
                                        <th>Status</th>
                                        <th>Usuários</th>
                                        <th>Cadastro</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($escolas as $escola): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($escola['nome']); ?></strong></td>
                                        <td><?php echo $escola['subdominio']; ?>.sige.ao</small></td>
                                        <td><span class="status-badge status-<?php echo $escola['status']; ?>"><?php echo ucfirst($escola['status']); ?></span></td>
                                                                                <td><?php echo $escola['total_usuarios']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($escola['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="../escolas/visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-info" title="Visualizar Escola">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="../assinaturas/renovar.php?escola=<?php echo $escola['id']; ?>" class="btn btn-success" title="Renovar Assinatura">
                                                    <i class="fas fa-sync"></i>
                                                </a>
                                            </div>
                                         </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($escolas)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                            Nenhuma escola utilizando este plano
                                        </td>
                                    </td>
                                    <?php endif; ?>
                                </tbody>
                             </div>
                            </div>
                        </div>
                    </div>
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
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatorios').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>