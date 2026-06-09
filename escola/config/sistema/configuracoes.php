<?php
// escola/config/sistema/configuracoes.php - Configurações Gerais
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar configurações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $configs = $_POST['config'] ?? [];
    
    foreach ($configs as $chave => $valor) {
        $stmt = $conn->prepare("
            INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
            VALUES (:escola_id, :parametro, :valor)
            ON DUPLICATE KEY UPDATE valor = :valor
        ");
        $stmt->execute([':escola_id' => $escola_id, ':parametro' => $chave, ':valor' => $valor]);
    }
    
    $_SESSION['mensagem'] = "Configurações salvas com sucesso!";
    header("Location: configuracoes.php");
    exit;
}

// Buscar configurações
$configs_padrao = [
    'nome_sistema' => 'SIGE Angola',
    'email_contato' => '',
    'telefone_contato' => '',
    'endereco_escola' => '',
    'horario_funcionamento' => '08:00 - 17:00',
    'dias_letivos' => 'segunda,terca,quarta,quinta,sexta',
    'nota_minima_aprovacao' => '10',
    'recuperacao_nota_minima' => '7',
    'max_faltas_percentual' => '25',
    'emitir_notificacoes' => 'sim',
    'tema' => 'claro'
];

$configs = [];
foreach ($configs_padrao as $chave => $valor_padrao) {
    $stmt = $conn->prepare("SELECT valor FROM escola_parametros_sistema WHERE escola_id = :escola_id AND parametro = :parametro");
    $stmt->execute([':escola_id' => $escola_id, ':parametro' => $chave]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $configs[$chave] = $row['valor'] ?? $valor_padrao;
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações Gerais | SIGE Angola</title>
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
        .config-section { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
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
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link active"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-cogs"></i> Configurações Gerais do Sistema</h2>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <form method="POST">
            <div class="card">
                <div class="card-header bg-primary text-white"><i class="fas fa-building"></i> Informações da Instituição</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12 mb-3"><label>Nome do Sistema</label><input type="text" name="config[nome_sistema]" class="form-control" value="<?php echo htmlspecialchars($configs['nome_sistema']); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Email de Contato</label><input type="email" name="config[email_contato]" class="form-control" value="<?php echo htmlspecialchars($configs['email_contato']); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Telefone de Contato</label><input type="text" name="config[telefone_contato]" class="form-control" value="<?php echo htmlspecialchars($configs['telefone_contato']); ?>"></div>
                        <div class="col-md-12 mb-3"><label>Endereço da Escola</label><textarea name="config[endereco_escola]" class="form-control" rows="2"><?php echo htmlspecialchars($configs['endereco_escola']); ?></textarea></div>
                        <div class="col-md-6 mb-3"><label>Horário de Funcionamento</label><input type="text" name="config[horario_funcionamento]" class="form-control" value="<?php echo htmlspecialchars($configs['horario_funcionamento']); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Dias Letivos</label><input type="text" name="config[dias_letivos]" class="form-control" value="<?php echo htmlspecialchars($configs['dias_letivos']); ?>" placeholder="segunda,terca,quarta,quinta,sexta"></div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-success text-white"><i class="fas fa-graduation-cap"></i> Parâmetros Acadêmicos</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3"><label>Nota Mínima para Aprovação</label><input type="number" step="0.1" name="config[nota_minima_aprovacao]" class="form-control" value="<?php echo $configs['nota_minima_aprovacao']; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Nota Mínima para Recuperação</label><input type="number" step="0.1" name="config[recuperacao_nota_minima]" class="form-control" value="<?php echo $configs['recuperacao_nota_minima']; ?>"></div>
                        <div class="col-md-4 mb-3"><label>Percentual Máximo de Faltas (%)</label><input type="number" step="1" name="config[max_faltas_percentual]" class="form-control" value="<?php echo $configs['max_faltas_percentual']; ?>"></div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-info text-white"><i class="fas fa-bell"></i> Notificações e Preferências</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Emitir Notificações</label><select name="config[emitir_notificacoes]" class="form-control"><option value="sim" <?php echo $configs['emitir_notificacoes'] == 'sim' ? 'selected' : ''; ?>>Sim</option><option value="nao" <?php echo $configs['emitir_notificacoes'] == 'nao' ? 'selected' : ''; ?>>Não</option></select></div>
                        <div class="col-md-6 mb-3"><label>Tema do Sistema</label><select name="config[tema]" class="form-control"><option value="claro" <?php echo $configs['tema'] == 'claro' ? 'selected' : ''; ?>>Claro</option><option value="escuro" <?php echo $configs['tema'] == 'escuro' ? 'selected' : ''; ?>>Escuro</option></select></div>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Salvar Configurações</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
    </script>
</body>
</html>