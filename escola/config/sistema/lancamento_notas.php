<?php
// escola/config/sistema/lancamento_notas.php - Lançamento de Notas
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar e criar tabela se não existir
$check = $conn->query("SHOW TABLES LIKE 'escola_parametros_sistema'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_parametros_sistema (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            parametro VARCHAR(100) NOT NULL,
            valor TEXT,
            data_abertura DATETIME,
            data_fechamento DATETIME,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_parametro_escola (parametro, escola_id)
        )
    ");
}

// Processar ação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'toggle_lancamento') {
    $status = $_POST['status'];
    $data_abertura = $status == 'aberto' ? date('Y-m-d H:i:s') : null;
    $data_fechamento = $status == 'fechado' ? date('Y-m-d H:i:s') : null;
    
    $stmt = $conn->prepare("
        INSERT INTO escola_parametros_sistema (escola_id, parametro, valor, data_abertura, data_fechamento)
        VALUES (:escola_id, 'lancamento_notas', :status, :data_abertura, :data_fechamento)
        ON DUPLICATE KEY UPDATE 
        valor = :status, data_abertura = :data_abertura, data_fechamento = :data_fechamento, atualizado_em = NOW()
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':status' => $status,
        ':data_abertura' => $data_abertura,
        ':data_fechamento' => $data_fechamento
    ]);
    
    $_SESSION['mensagem'] = "Lançamento de notas " . ($status == 'aberto' ? 'aberto' : 'fechado') . " com sucesso!";
    header("Location: lancamento_notas.php");
    exit;
}

// Buscar status atual
$stmt = $conn->prepare("SELECT valor, data_abertura, data_fechamento FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = 'lancamento_notas'");
$stmt->execute([':escola_id' => $escola_id]);
$lancamento = $stmt->fetch(PDO::FETCH_ASSOC);
$status_atual = $lancamento['valor'] ?? 'fechado';
$data_abertura = $lancamento['data_abertura'] ?? null;
$data_fechamento = $lancamento['data_fechamento'] ?? null;

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançamento de Notas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .status-card { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border-radius: 15px; padding: 30px; text-align: center; }
        .status-card-fechado { background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); }
        .btn-toggle { padding: 15px 30px; font-size: 1.2em; border-radius: 50px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-cogs"></i> Configurações</a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/contas.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="../pagamento/formas.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="lancamento_notas.php" class="nav-link active"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Lançamento de Notas</h2>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="status-card <?php echo $status_atual == 'fechado' ? 'status-card-fechado' : ''; ?>">
            <i class="fas fa-<?php echo $status_atual == 'aberto' ? 'lock-open' : 'lock'; ?> fa-4x mb-3"></i>
            <h2>Lançamento de Notas</h2>
            <h1 class="display-1"><?php echo strtoupper($status_atual); ?></h1>
            <?php if ($data_abertura): ?>
                <p>Aberto em: <?php echo date('d/m/Y H:i', strtotime($data_abertura)); ?></p>
            <?php endif; ?>
            <?php if ($data_fechamento): ?>
                <p>Fechado em: <?php echo date('d/m/Y H:i', strtotime($data_fechamento)); ?></p>
            <?php endif; ?>
            <form method="POST" class="mt-4">
                <input type="hidden" name="acao" value="toggle_lancamento">
                <input type="hidden" name="status" value="<?php echo $status_atual == 'aberto' ? 'fechado' : 'aberto'; ?>">
                <button type="submit" class="btn btn-light btn-toggle">
                    <i class="fas fa-<?php echo $status_atual == 'aberto' ? 'lock' : 'lock-open'; ?>"></i>
                    <?php echo $status_atual == 'aberto' ? 'Fechar Lançamento' : 'Abrir Lançamento'; ?>
                </button>
            </form>
        </div>
        
        <div class="card mt-4">
            <div class="card-header bg-info text-white"><i class="fas fa-info-circle"></i> Informações</div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li><i class="fas fa-check-circle text-success"></i> Quando aberto, professores e coordenadores podem lançar notas.</li>
                    <li><i class="fas fa-exclamation-triangle text-warning"></i> Quando fechado, o lançamento de notas é bloqueado.</li>
                    <li><i class="fas fa-chart-line text-primary"></i> O histórico de aberturas e fechamentos é registrado.</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
    </script>
</body>
</html>