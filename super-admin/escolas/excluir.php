<?php
// super-admin/escolas/excluir.php - Excluir escola
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$confirm = $_GET['confirm'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar dados da escola
$stmt = $conn->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id) as total_assinaturas,
           (SELECT COUNT(*) FROM pagamentos WHERE escola_id = e.id) as total_pagamentos
    FROM escolas e
    WHERE e.id = :id
");
$stmt->execute([':id' => $id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    header('Location: index.php?error=Escola não encontrada');
    exit;
}

// Buscar escolas com mesmo plano para sugestão
$stmt = $conn->prepare("
    SELECT id, nome, subdominio, status 
    FROM escolas 
    WHERE plano_id = :plano_id AND id != :id AND status = 'ativa'
    LIMIT 5
");
$stmt->execute([':plano_id' => $escola['plano_id'], ':id' => $id]);
$escolas_similares = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';

// Processar exclusão
if ($confirm === 'yes') {
    try {
        $conn->beginTransaction();
        
        // Buscar logo para deletar
        if ($escola['logo']) {
            $upload_dir = __DIR__ . '/../../uploads/escolas/';
            if (file_exists($upload_dir . $escola['logo'])) {
                unlink($upload_dir . $escola['logo']);
            }
            if (file_exists($upload_dir . 'thumb_' . $escola['logo'])) {
                unlink($upload_dir . 'thumb_' . $escola['logo']);
            }
        }
        
        // Log antes de excluir
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, dados_antes, ip, created_at)
            VALUES (:usuario_id, 'excluir_escola', 'escolas', :registro_id, :dados_antes, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':dados_antes' => json_encode($escola),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Excluir escola (as relações em cascata vão excluir usuários, assinaturas, pagamentos, etc.)
        $stmt = $conn->prepare("DELETE FROM escolas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Escola '{$escola['nome']}' excluída com sucesso!";
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao excluir escola: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir Escola - <?php echo htmlspecialchars($escola['nome']); ?> | SIGE Angola</title>
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
            background: #dc3545;
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 20px;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
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
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            width: 150px;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
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
            <h2><i class="fas fa-trash-alt"></i> Excluir Escola</h2>
            <div>
                <a href="visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> Visualizar</a>
                <a href="editar.php?id=<?php echo $escola['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Atenção! Esta ação é irreversível</h3>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="warning-box">
                    <h5><i class="fas fa-skull-crosswalk"></i> Você está prestes a excluir a escola:</h5>
                    <div class="info-row">
                        <div class="info-label">Nome:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($escola['nome']); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Subdomínio:</div>
                        <div class="info-value"><?php echo $escola['subdominio']; ?>.sige.ao</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value"><span class="status-badge status-<?php echo $escola['status']; ?>"><?php echo ucfirst($escola['status']); ?></span></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data de Cadastro:</div>
                        <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($escola['created_at'])); ?></div>
                    </div>
                </div>
                
                <div class="alert alert-danger">
                    <i class="fas fa-skull-crosswalk"></i>
                    <strong>AVISO IMPORTANTE:</strong> Ao excluir esta escola, os seguintes dados serão permanentemente removidos:
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <i class="fas fa-users fa-3x text-danger mb-2"></i>
                                <h3><?php echo $escola['total_usuarios']; ?></h3>
                                <p>Usuários</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <i class="fas fa-credit-card fa-3x text-danger mb-2"></i>
                                <h3><?php echo $escola['total_assinaturas']; ?></h3>
                                <p>Assinaturas</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <i class="fas fa-money-bill-wave fa-3x text-danger mb-2"></i>
                                <h3><?php echo $escola['total_pagamentos']; ?></h3>
                                <p>Pagamentos</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Dados que serão perdidos permanentemente:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Todos os usuários da escola (professores, alunos, secretários, etc.)</li>
                        <li>Todas as assinaturas e histórico de pagamentos</li>
                        <li>Todas as turmas, disciplinas e notas lançadas</li>
                        <li>Registros de chamada e frequência</li>
                        <li>Arquivos da biblioteca e uploads da escola</li>
                        <li>Logs e histórico de atividades</li>
                        <li>Logo e imagens da escola</li>
                    </ul>
                </div>
                
                <?php if (!empty($escolas_similares)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Sugestão:</strong> Se deseja manter os dados, considere suspender a escola em vez de excluir.
                    <br><br>
                    <strong>Escolas com o mesmo plano:</strong>
                    <ul>
                        <?php foreach ($escolas_similares as $similar): ?>
                        <li><?php echo htmlspecialchars($similar['nome']); ?> (<?php echo $similar['subdominio']; ?>.sige.ao) - <?php echo ucfirst($similar['status']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="text-center mt-4">
                    <a href="?id=<?php echo $id; ?>&confirm=yes" class="btn btn-danger btn-lg px-5" onclick="return confirm('Esta ação é irreversível! Tem certeza absoluta que deseja excluir permanentemente esta escola e todos os seus dados?')">
                        <i class="fas fa-trash-alt"></i> Sim, excluir permanentemente
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                        <i class="fas fa-ban"></i> Cancelar
                    </a>
                </div>
                
                <div class="text-center mt-3">
                    <small class="text-muted">Para confirmar a exclusão, clique no botão vermelho acima.</small>
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