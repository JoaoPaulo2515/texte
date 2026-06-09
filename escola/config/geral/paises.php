<?php
// escola/config/geral/paises.php - Gestão de Países
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
$check = $conn->query("SHOW TABLES LIKE 'paises'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE paises (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL UNIQUE,
            sigla VARCHAR(3),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Inserir países padrão
    $paises_padrao = [
        ['Angola', 'AO'], ['Portugal', 'PT'], ['Brasil', 'BR'], ['Cabo Verde', 'CV'],
        ['São Tomé e Príncipe', 'ST'], ['Moçambique', 'MZ'], ['Guiné-Bissau', 'GW'], ['Timor-Leste', 'TL']
    ];
    
    $stmt = $conn->prepare("INSERT INTO paises (nome, sigla) VALUES (:nome, :sigla)");
    foreach ($paises_padrao as $pais) {
        $stmt->execute([':nome' => $pais[0], ':sigla' => $pais[1]]);
    }
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_pais') {
        $nome = $_POST['nome'];
        $sigla = $_POST['sigla'];
        
        $stmt = $conn->prepare("INSERT INTO paises (nome, sigla) VALUES (:nome, :sigla)");
        $stmt->execute([':nome' => $nome, ':sigla' => $sigla]);
        $_SESSION['mensagem'] = "País adicionado com sucesso!";
        header("Location: paises.php");
        exit;
    }
    
    if ($acao == 'edit_pais') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $sigla = $_POST['sigla'];
        
        $stmt = $conn->prepare("UPDATE paises SET nome = :nome, sigla = :sigla WHERE id = :id");
        $stmt->execute([':id' => $id, ':nome' => $nome, ':sigla' => $sigla]);
        $_SESSION['mensagem'] = "País atualizado!";
        header("Location: paises.php");
        exit;
    }
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM paises WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $_SESSION['mensagem'] = "País excluído!";
    header("Location: paises.php");
    exit;
}

// Buscar dados
$paises = $conn->query("SELECT * FROM paises ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Países | Configurações | SIGE Angola</title>
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
        <div class="top-bar"><h2><i class="fas fa-globe"></i> Países</h2><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoPais"><i class="fas fa-plus"></i> Novo País</button></div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Países</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaPaises">
                        <thead class="table-light"><tr><th>ID</th><th>País</th><th>Sigla</th><th>Ações</th></tr></thead>
                        <tbody>
                            <?php foreach ($paises as $pais): ?>
                            <tr><td><?php echo $pais['id']; ?></td><td><strong><?php echo $pais['nome']; ?></strong></td><td><?php echo $pais['sigla']; ?></td><td><button class="btn btn-sm btn-info" onclick="editarPais(<?php echo $pais['id']; ?>, '<?php echo $pais['nome']; ?>', '<?php echo $pais['sigla']; ?>')"><i class="fas fa-edit"></i></button><a href="?delete=1&id=<?php echo $pais['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalNovoPais" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Novo País</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_pais"><div class="modal-body"><div class="mb-3"><label>Nome do País</label><input type="text" name="nome" class="form-control" required></div><div class="mb-3"><label>Sigla</label><input type="text" name="sigla" class="form-control" maxlength="3"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <div class="modal fade" id="modalEditarPais" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_pais"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Nome do País</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="mb-3"><label>Sigla</label><input type="text" name="sigla" id="edit_sigla" class="form-control" maxlength="3"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaPaises').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        function editarPais(id, nome, sigla) { $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_sigla').val(sigla); $('#modalEditarPais').modal('show'); }
    </script>
</body>
</html>