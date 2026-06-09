<?php
// escola/config/geral/comunas.php - Gestão de Comunas
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar e criar tabelas se não existirem
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

$check = $conn->query("SHOW TABLES LIKE 'angola_comunas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE angola_comunas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            municipio_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (municipio_id) REFERENCES angola_municipios(id) ON DELETE CASCADE,
            UNIQUE KEY unique_comuna_municipio (nome, municipio_id)
        )
    ");
}

// Buscar províncias para selects
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_comuna') {
        $nome = $_POST['nome'];
        $municipio_id = $_POST['municipio_id'];
        
        $stmt = $conn->prepare("INSERT INTO angola_comunas (nome, municipio_id) VALUES (:nome, :municipio_id)");
        $stmt->execute([':nome' => $nome, ':municipio_id' => $municipio_id]);
        $_SESSION['mensagem'] = "Comuna adicionada com sucesso!";
        header("Location: comunas.php");
        exit;
    }
    
    if ($acao == 'edit_comuna') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $municipio_id = $_POST['municipio_id'];
        
        $stmt = $conn->prepare("UPDATE angola_comunas SET nome = :nome, municipio_id = :municipio_id WHERE id = :id");
        $stmt->execute([':id' => $id, ':nome' => $nome, ':municipio_id' => $municipio_id]);
        $_SESSION['mensagem'] = "Comuna atualizada!";
        header("Location: comunas.php");
        exit;
    }
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM angola_comunas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $_SESSION['mensagem'] = "Comuna excluída!";
    header("Location: comunas.php");
    exit;
}

// Buscar dados
$comunas = $conn->query("
    SELECT c.*, m.nome as municipio_nome, p.nome as provincia_nome, p.id as provincia_id
    FROM angola_comunas c
    JOIN angola_municipios m ON m.id = c.municipio_id
    JOIN angola_provincias p ON p.id = m.provincia_id
    ORDER BY p.nome, m.nome, c.nome
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar municípios via AJAX
if (isset($_GET['get_municipios']) && isset($_GET['provincia_id'])) {
    header('Content-Type: application/json');
    $provincia_id = $_GET['provincia_id'];
    $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
    $stmt->execute([':provincia_id' => $provincia_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunas | Configurações | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        .table-responsive { overflow-x: auto; }
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
        <div class="top-bar"><h2><i class="fas fa-city"></i> Comunas de Angola</h2><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaComuna"><i class="fas fa-plus"></i> Nova Comuna</button></div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Comunas</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaComunas">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Comuna</th><th>Município</th><th>Província</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comunas as $comuna): ?>
                            <tr>
                                <td><?php echo $comuna['id']; ?></td>
                                <td><strong><?php echo $comuna['nome']; ?></strong></td>
                                <td><?php echo $comuna['municipio_nome']; ?></td>
                                <td><?php echo $comuna['provincia_nome']; ?></td>
                                <td><button class="btn btn-sm btn-info" onclick="editarComuna(<?php echo $comuna['id']; ?>, '<?php echo $comuna['nome']; ?>', <?php echo $comuna['municipio_id']; ?>, <?php echo $comuna['provincia_id']; ?>)"><i class="fas fa-edit"></i></button><a href="?delete=1&id=<?php echo $comuna['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Comuna -->
    <div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Nova Comuna</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_comuna"><div class="modal-body"><div class="mb-3"><label>Província</label><select name="provincia_id" id="provincia_select" class="form-control" required><option value="">Selecione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['nome']; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Município</label><select name="municipio_id" id="municipio_select" class="form-control" required disabled><option value="">Primeiro selecione a província</option></select></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" name="nome" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Editar Comuna -->
    <div class="modal fade" id="modalEditarComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_comuna"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Província</label><select name="provincia_id" id="edit_provincia_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['nome']; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Município</label><select name="municipio_id" id="edit_municipio_id" class="form-control" required></select></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaComunas').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        
        // Carregar municípios por província
        $('#provincia_select, #edit_provincia_id').change(function() {
            var provinciaId = $(this).val();
            var target = $(this).attr('id') == 'provincia_select' ? '#municipio_select' : '#edit_municipio_id';
            if (provinciaId) {
                $.ajax({ url: 'comunas.php', method: 'GET', data: { get_municipios: 1, provincia_id: provinciaId }, success: function(data) { var options = '<option value="">Selecione...</option>'; data.forEach(function(m) { options += '<option value="' + m.id + '">' + m.nome + '</option>'; }); $(target).html(options).prop('disabled', false); } });
            } else { $(target).html('<option value="">Primeiro selecione a província</option>').prop('disabled', true); }
        });
        
        function editarComuna(id, nome, municipioId, provinciaId) {
            $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_provincia_id').val(provinciaId).trigger('change');
            setTimeout(function() { $('#edit_municipio_id').val(municipioId); }, 500);
            $('#modalEditarComuna').modal('show');
        }
    </script>
</body>
</html>