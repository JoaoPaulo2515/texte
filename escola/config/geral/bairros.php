<?php
// escola/config/geral/bairros.php - Gestão de Bairros
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
$check = $conn->query("SHOW TABLES LIKE 'angola_bairros'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE angola_bairros (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            comuna_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (comuna_id) REFERENCES angola_comunas(id) ON DELETE CASCADE,
            UNIQUE KEY unique_bairro_comuna (nome, comuna_id)
        )
    ");
}

// Buscar dados para selects
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_bairro') {
        $nome = $_POST['nome'];
        $comuna_id = $_POST['comuna_id'];
        
        $stmt = $conn->prepare("INSERT INTO angola_bairros (nome, comuna_id) VALUES (:nome, :comuna_id)");
        $stmt->execute([':nome' => $nome, ':comuna_id' => $comuna_id]);
        $_SESSION['mensagem'] = "Bairro adicionado com sucesso!";
        header("Location: bairros.php");
        exit;
    }
    
    if ($acao == 'edit_bairro') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $comuna_id = $_POST['comuna_id'];
        
        $stmt = $conn->prepare("UPDATE angola_bairros SET nome = :nome, comuna_id = :comuna_id WHERE id = :id");
        $stmt->execute([':id' => $id, ':nome' => $nome, ':comuna_id' => $comuna_id]);
        $_SESSION['mensagem'] = "Bairro atualizado!";
        header("Location: bairros.php");
        exit;
    }
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM angola_bairros WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $_SESSION['mensagem'] = "Bairro excluído!";
    header("Location: bairros.php");
    exit;
}

// Buscar dados
$bairros = $conn->query("
    SELECT b.*, c.nome as comuna_nome, m.nome as municipio_nome, p.nome as provincia_nome,
           p.id as provincia_id, m.id as municipio_id
    FROM angola_bairros b
    JOIN angola_comunas c ON c.id = b.comuna_id
    JOIN angola_municipios m ON m.id = c.municipio_id
    JOIN angola_provincias p ON p.id = m.provincia_id
    ORDER BY p.nome, m.nome, c.nome, b.nome
")->fetchAll(PDO::FETCH_ASSOC);

// API para buscar municípios
if (isset($_GET['get_municipios']) && isset($_GET['provincia_id'])) {
    header('Content-Type: application/json');
    $provincia_id = $_GET['provincia_id'];
    $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
    $stmt->execute([':provincia_id' => $provincia_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// API para buscar comunas
if (isset($_GET['get_comunas']) && isset($_GET['municipio_id'])) {
    header('Content-Type: application/json');
    $municipio_id = $_GET['municipio_id'];
    $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
    $stmt->execute([':municipio_id' => $municipio_id]);
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
    <title>Bairros | Configurações | SIGE Angola</title>
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
        <div class="top-bar"><h2><i class="fas fa-home"></i> Bairros</h2><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoBairro"><i class="fas fa-plus"></i> Novo Bairro</button></div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Bairros</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaBairros">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Bairro</th><th>Comuna</th><th>Município</th><th>Província</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bairros as $bairro): ?>
                            <tr>
                                <td><?php echo $bairro['id']; ?></td>
                                <td><strong><?php echo $bairro['nome']; ?></strong></td>
                                <td><?php echo $bairro['comuna_nome']; ?></td>
                                <td><?php echo $bairro['municipio_nome']; ?></td>
                                <td><?php echo $bairro['provincia_nome']; ?></td>
                                <td><button class="btn btn-sm btn-info" onclick="editarBairro(<?php echo $bairro['id']; ?>, '<?php echo $bairro['nome']; ?>', <?php echo $bairro['comuna_id']; ?>, <?php echo $bairro['municipio_id']; ?>, <?php echo $bairro['provincia_id']; ?>)"><i class="fas fa-edit"></i></button><a href="?delete=1&id=<?php echo $bairro['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Bairro -->
    <div class="modal fade" id="modalNovoBairro" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Novo Bairro</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_bairro"><div class="modal-body"><div class="mb-3"><label>Província</label><select id="provincia_select" class="form-control" required><option value="">Selecione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['nome']; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Município</label><select id="municipio_select" class="form-control" required disabled><option value="">Primeiro selecione a província</option></select></div><div class="mb-3"><label>Comuna</label><select name="comuna_id" id="comuna_select" class="form-control" required disabled><option value="">Primeiro selecione o município</option></select></div><div class="mb-3"><label>Nome do Bairro</label><input type="text" name="nome" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Editar Bairro -->
    <div class="modal fade" id="modalEditarBairro" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Bairro</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_bairro"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Província</label><select id="edit_provincia_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['nome']; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Município</label><select id="edit_municipio_id" class="form-control" required></select></div><div class="mb-3"><label>Comuna</label><select name="comuna_id" id="edit_comuna_id" class="form-control" required></select></div><div class="mb-3"><label>Nome do Bairro</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaBairros').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        
        function carregarMunicipios(provinciaId, target) { if (provinciaId) { $.ajax({ url: 'bairros.php', method: 'GET', data: { get_municipios: 1, provincia_id: provinciaId }, success: function(data) { var options = '<option value="">Selecione...</option>'; data.forEach(function(m) { options += '<option value="' + m.id + '">' + m.nome + '</option>'; }); $(target).html(options).prop('disabled', false); } }); } else { $(target).html('<option value="">Primeiro selecione a província</option>').prop('disabled', true); } }
        function carregarComunas(municipioId, target) { if (municipioId) { $.ajax({ url: 'bairros.php', method: 'GET', data: { get_comunas: 1, municipio_id: municipioId }, success: function(data) { var options = '<option value="">Selecione...</option>'; data.forEach(function(c) { options += '<option value="' + c.id + '">' + c.nome + '</option>'; }); $(target).html(options).prop('disabled', false); } }); } else { $(target).html('<option value="">Primeiro selecione o município</option>').prop('disabled', true); } }
        
        $('#provincia_select').change(function() { carregarMunicipios($(this).val(), '#municipio_select'); });
        $('#municipio_select').change(function() { carregarComunas($(this).val(), '#comuna_select'); });
        $('#edit_provincia_id').change(function() { carregarMunicipios($(this).val(), '#edit_municipio_id'); });
        $('#edit_municipio_id').change(function() { carregarComunas($(this).val(), '#edit_comuna_id'); });
        
        function editarBairro(id, nome, comunaId, municipioId, provinciaId) {
            $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_provincia_id').val(provinciaId).trigger('change');
            setTimeout(function() { $('#edit_municipio_id').val(municipioId).trigger('change'); }, 500);
            setTimeout(function() { $('#edit_comuna_id').val(comunaId); }, 1000);
            $('#modalEditarBairro').modal('show');
        }
    </script>
</body>
</html>