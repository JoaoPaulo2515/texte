<?php
// escola/servicos_pedagogicos/gerais/classes.php - Gestão de Classes
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar e criar tabela classes
$check = $conn->query("SHOW TABLES LIKE 'classes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE classes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(50) NOT NULL,
            descricao TEXT,
            ordem INT DEFAULT 0,
            status ENUM('ativa', 'inativa') DEFAULT 'ativa',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_classe') {
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $ordem = $_POST['ordem'];
        
        $stmt = $conn->prepare("INSERT INTO classes (escola_id, nome, descricao, ordem, status) VALUES (:escola_id, :nome, :descricao, :ordem, 'ativa')");
        $stmt->execute([':escola_id' => $escola_id, ':nome' => $nome, ':descricao' => $descricao, ':ordem' => $ordem]);
        $_SESSION['mensagem'] = "Classe adicionada com sucesso!";
        header("Location: classes.php");
        exit;
    }
    
    if ($acao == 'edit_classe') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $ordem = $_POST['ordem'];
        
        $stmt = $conn->prepare("UPDATE classes SET nome = :nome, descricao = :descricao, ordem = :ordem WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id, ':nome' => $nome, ':descricao' => $descricao, ':ordem' => $ordem]);
        $_SESSION['mensagem'] = "Classe atualizada com sucesso!";
        header("Location: classes.php");
        exit;
    }
}

// Ativar/Desativar
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativa';
    $novo_status = ($status == 'ativa') ? 'inativa' : 'ativa';
    $stmt = $conn->prepare("UPDATE classes SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: classes.php");
    exit;
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM classes WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Classe excluída!";
    header("Location: classes.php");
    exit;
}

// Buscar dados
$classes = $conn->prepare("SELECT * FROM classes WHERE escola_id = :escola_id ORDER BY ordem, nome");
$classes->execute([':escola_id' => $escola_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes | SIGE Angola</title>
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
        .badge-ativa { background: #d4edda; color: #155724; }
        .badge-inativa { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-layer-group"></i> Gestão de Classes</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaClasse"><i class="fas fa-plus"></i> Nova Classe</button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Classes</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaClasses">
                        <thead class="table-light"><tr><th>ID</th><th>Nome</th><th>Descrição</th><th>Ordem</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                            <?php foreach ($classes as $classe): ?>
                            <tr>
                                <td><?php echo $classe['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($classe['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($classe['descricao']); ?></td>
                                <td><?php echo $classe['ordem']; ?></td>
                                <td><span class="badge <?php echo $classe['status'] == 'ativa' ? 'badge-ativa' : 'badge-inativa'; ?>"><?php echo $classe['status']; ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarClasse(<?php echo $classe['id']; ?>, '<?php echo addslashes($classe['nome']); ?>', '<?php echo addslashes($classe['descricao']); ?>', <?php echo $classe['ordem']; ?>)"><i class="fas fa-edit"></i></button>
                                    <a href="?toggle=1&id=<?php echo $classe['id']; ?>&status=<?php echo $classe['status']; ?>" class="btn btn-sm btn-success"><i class="fas fa-toggle-<?php echo $classe['status'] == 'ativa' ? 'off' : 'on'; ?>"></i></a>
                                    <a href="?delete=1&id=<?php echo $classe['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Classe -->
    <div class="modal fade" id="modalNovaClasse" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Nova Classe</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_classe"><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required placeholder="Ex: 1ª Classe, 2ª Classe"></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div><div class="mb-3"><label>Ordem</label><input type="number" name="ordem" class="form-control" value="0"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Editar Classe -->
    <div class="modal fade" id="modalEditarClasse" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Classe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_classe"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea></div><div class="mb-3"><label>Ordem</label><input type="number" name="ordem" id="edit_ordem" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaClasses').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        function editarClasse(id, nome, desc, ordem) { $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_descricao').val(desc); $('#edit_ordem').val(ordem); $('#modalEditarClasse').modal('show'); }
    </script>
</body>
</html>