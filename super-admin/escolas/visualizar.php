<?php
// super-admin/escolas/visualizar.php - Visualizar detalhes da escola
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar dados da escola
$stmt = $conn->prepare("
    SELECT e.*, p.nome as plano_nome, p.preco_mensal, p.preco_anual,
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id AND status = 'ativa') as tem_assinatura
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE e.id = :id
");
$stmt->execute([':id' => $id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    header('Location: index.php?error=Escola não encontrada');
    exit;
}

// Buscar assinaturas da escola
$stmt = $conn->prepare("
    SELECT a.*, p.nome as plano_nome
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    WHERE a.escola_id = :id
    ORDER BY a.created_at DESC
");
$stmt->execute([':id' => $id]);
$assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamentos da escola
$stmt = $conn->prepare("
    SELECT p.*, a.tipo_cobranca
    FROM pagamentos p
    JOIN assinaturas a ON a.id = p.assinatura_id
    WHERE p.escola_id = :id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute([':id' => $id]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuários da escola
$stmt = $conn->prepare("
    SELECT * FROM usuarios
    WHERE escola_id = :id
    ORDER BY created_at DESC
");
$stmt->execute([':id' => $id]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Função para formatar telefone
function formatarTelefoneExibir($telefone) {
    if (empty($telefone)) return '-';
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// Função para formatar NUIT
function formatarNUITExibir($nuit) {
    if (empty($nuit)) return '-';
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    if (strlen($nuit) == 14) {
        return substr($nuit, 0, 3) . '.' . substr($nuit, 3, 3) . '.' . substr($nuit, 6, 3) . '.' . substr($nuit, 9, 5);
    }
    return $nuit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($escola['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        
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
        
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            width: 200px;
            font-weight: 600;
            color: #555;
        }
        
        .info-value {
            flex: 1;
            color: #333;
        }
        
        .logo-grande {
            width: 120px;
            height: 120px;
            border-radius: 15px;
            object-fit: cover;
            border: 2px solid #006B3E;
        }
        
        .section-title {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #006B3E;
        }
        
        .print-only {
            display: none;
        }
        
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            .print-only {
                display: block;
            }
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
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
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
                <h2><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola['nome']); ?></h2>
                <small><?php echo $escola['subdominio']; ?>.sige.ao</small>
            </div>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Imprimir</button>
                <a href="editar.php?id=<?php echo $escola['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="print-only text-center mb-4">
            <?php if ($escola['logo']): ?>
                <img src="../../uploads/escolas/<?php echo $escola['logo']; ?>" style="max-height: 80px;">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($escola['nome']); ?></h2>
            <p><?php echo htmlspecialchars($escola['endereco']); ?> | Tel: <?php echo formatarTelefoneExibir($escola['telefone']); ?></p>
            <hr>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center p-4">
                    <?php if ($escola['logo']): ?>
                        <img src="../../uploads/escolas/<?php echo $escola['logo']; ?>" class="logo-grande mx-auto mb-3">
                    <?php else: ?>
                        <div class="logo-grande mx-auto mb-3 bg-light d-flex align-items-center justify-content-center">
                            <i class="fas fa-school fa-4x text-muted"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($escola['nome']); ?></h4>
                    <p class="text-muted"><?php echo $escola['subdominio']; ?>.sige.ao</p>
                    <span class="status-badge status-<?php echo $escola['status']; ?>"><?php echo ucfirst($escola['status']); ?></span>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Resumo Financeiro</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Plano Atual:</div><div class="info-value"><?php echo $escola['plano_nome'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Valor Mensal:</div><div class="info-value">KZ <?php echo number_format($escola['preco_mensal'] ?? 0, 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Total Pago:</div><div class="info-value">KZ <?php echo number_format(array_sum(array_column($pagamentos, 'valor')), 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Último Pagamento:</div><div class="info-value"><?php echo $pagamentos ? date('d/m/Y', strtotime($pagamentos[0]['created_at'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Trial até:</div><div class="info-value"><?php echo $escola['trial_ate'] ? date('d/m/Y', strtotime($escola['trial_ate'])) : '-'; ?></div></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <!-- Dados da Instituição -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-building"></i> Dados da Instituição</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">Subdomínio:</div><div class="info-value"><?php echo $escola['subdominio']; ?>.sige.ao</div></div>
                        <div class="info-row"><div class="info-label">Domínio Personalizado:</div><div class="info-value"><?php echo $escola['dominio_personalizado'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">NUIT/NIF:</div><div class="info-value"><?php echo formatarNUITExibir($escola['nuit']); ?></div></div>
                        <div class="info-row"><div class="info-label">Ano de Fundação:</div><div class="info-value"><?php echo $escola['ano_fundacao'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['email']); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone Fixo:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['telefone']); ?></div></div>
                        <div class="info-row"><div class="info-label">Celular:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['celular']); ?></div></div>
                    </div>
                </div>
                
                <!-- Endereço -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-map-marker-alt"></i> Endereço</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Província:</div><div class="info-value"><?php echo $escola['provincia'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Município:</div><div class="info-value"><?php echo $escola['municipio'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Comuna:</div><div class="info-value"><?php echo $escola['comuna'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo nl2br(htmlspecialchars($escola['endereco'] ?? '-')); ?></div></div>
                    </div>
                </div>
                
                <!-- Direção -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-users"></i> Direção da Escola</div>
                    <div class="card-body">
                        <h6 class="section-title">Diretor</h6>
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['director'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Contato:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['director_contato']); ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['director_email'] ?? '-'); ?></div></div>
                        
                        <h6 class="section-title mt-3">Diretor Pedagógico</h6>
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['director_pedagogico'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Contato:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['director_pedagogico_contato']); ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['director_pedagogico_email'] ?? '-'); ?></div></div>
                        
                        <h6 class="section-title mt-3">Secretário</h6>
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['secretario'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Contato:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['secretario_contato']); ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['secretario_email'] ?? '-'); ?></div></div>
                    </div>
                </div>
                
                <!-- Responsável -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-tie"></i> Responsável Legal</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['responsavel_nome'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['responsavel_email'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo formatarTelefoneExibir($escola['responsavel_telefone']); ?></div></div>
                    </div>
                </div>
                
                <!-- Usuários -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-users"></i> Usuários da Escola</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Nome</th><th>E-mail</th><th>Tipo</th><th>Status</th><th>Último Acesso</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($usuario['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $usuario['tipo'])); ?></td>
                                        <td><span class="badge bg-<?php echo $usuario['status'] == 'ativo' ? 'success' : 'danger'; ?>"><?php echo ucfirst($usuario['status']); ?></span></td>
                                        <td><?php echo $usuario['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($usuario['ultimo_acesso'])) : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Assinaturas -->
                <div class="card">
                    <div class="card-header"><i class="fas fa-credit-card"></i> Histórico de Assinaturas</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Plano</th><th>Valor</th><th>Tipo</th><th>Início</th><th>Fim</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assinaturas as $a): ?>
                                    <tr>
                                        <td><?php echo $a['plano_nome']; ?></td>
                                        <td>KZ <?php echo number_format($a['valor'], 2, ',', '.'); ?></td>
                                        <td><?php echo ucfirst($a['tipo_cobranca']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($a['data_inicio'])); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($a['data_fim'])); ?></td>
                                        <td><span class="badge bg-<?php echo $a['status'] == 'ativa' ? 'success' : 'warning'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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