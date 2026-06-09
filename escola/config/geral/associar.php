<?php
// escola/config/geral/associar.php - Associação Classe-Curso
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
$check = $conn->query("SHOW TABLES LIKE 'classe_curso'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE classe_curso (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            classe_id INT NOT NULL,
            curso_id INT NOT NULL,
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (classe_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
            UNIQUE KEY unique_associacao (classe_id, curso_id)
        )
    ");
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_associacao') {
        $classe_id = $_POST['classe_id'];
        $curso_id = $_POST['curso_id'];
        
        $stmt = $conn->prepare("INSERT IGNORE INTO classe_curso (escola_id, classe_id, curso_id, status) VALUES (:escola_id, :classe_id, :curso_id, 'ativo')");
        $stmt->execute([':escola_id' => $escola_id, ':classe_id' => $classe_id, ':curso_id' => $curso_id]);
        $_SESSION['mensagem'] = "Associação criada com sucesso!";
        header("Location: associar.php");
        exit;
    }
    
    if ($acao == 'associar_massa') {
        $classe_id = $_POST['classe_id'];
        $cursos = $_POST['cursos'] ?? [];
        
        foreach ($cursos as $curso_id) {
            $stmt = $conn->prepare("INSERT IGNORE INTO classe_curso (escola_id, classe_id, curso_id, status) VALUES (:escola_id, :classe_id, :curso_id, 'ativo')");
            $stmt->execute([':escola_id' => $escola_id, ':classe_id' => $classe_id, ':curso_id' => $curso_id]);
        }
        $_SESSION['mensagem'] = count($cursos) . " curso(s) associado(s) com sucesso!";
        header("Location: associar.php");
        exit;
    }
}

// Remover associação
if (isset($_GET['remove']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM classe_curso WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Associação removida!";
    header("Location: associar.php");
    exit;
}

// Ativar/Desativar
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    $stmt = $conn->prepare("UPDATE classe_curso SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: associar.php");
    exit;
}

// Buscar dados
$classes = $conn->prepare("SELECT id, nome FROM classes WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ordem, nome");
$classes->execute([':escola_id' => $escola_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

$cursos = $conn->prepare("SELECT id, nome, codigo FROM cursos WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$cursos->execute([':escola_id' => $escola_id]);
$cursos = $cursos->fetchAll(PDO::FETCH_ASSOC);

$associacoes = $conn->prepare("
    SELECT ac.*, c.nome as classe_nome, cs.nome as curso_nome, cs.codigo as curso_codigo
    FROM classe_curso ac
    JOIN classes c ON c.id = ac.classe_id
    JOIN cursos cs ON cs.id = ac.curso_id
    WHERE ac.escola_id = :escola_id
    ORDER BY c.ordem, c.nome, cs.nome
");
$associacoes->execute([':escola_id' => $escola_id]);
$associacoes = $associacoes->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associar Classe-Curso | Configurações | SIGE Angola</title>
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
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .checkbox-group { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 15px; }
        .checkbox-item { margin-bottom: 8px; }
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
        <div class="top-bar"><h2><i class="fas fa-link"></i> Associar Classe - Curso</h2><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAssociar"><i class="fas fa-plus"></i> Nova Associação</button></div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Associações Classe - Curso</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaAssociacoes">
                        <thead class="table-light"><tr><th>ID</th><th>Classe</th><th>Curso</th><th>Código</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                            <?php if (!empty($associacoes)): ?>
                                <?php foreach ($associacoes as $assoc): ?>
                                <tr><td><?php echo $assoc['id']; ?></td><td><strong><?php echo htmlspecialchars($assoc['classe_nome']); ?></strong></td><td><?php echo htmlspecialchars($assoc['curso_nome']); ?></td><td><?php echo $assoc['curso_codigo']; ?></td><td><span class="badge <?php echo $assoc['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $assoc['status']; ?></span></td>
                                <td><a href="?toggle=1&id=<?php echo $assoc['id']; ?>&status=<?php echo $assoc['status']; ?>" class="btn btn-sm btn-success"><i class="fas fa-toggle-<?php echo $assoc['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a><a href="?remove=1&id=<?php echo $assoc['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remover esta associação?')"><i class="fas fa-trash"></i></a></div></tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-4"><i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>Nenhuma associação encontrada</td></tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3"><button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAssociarMassa"><i class="fas fa-layer-group"></i> Associar em Massa</button></div>
    </div>
    
    <!-- Modal Associar Individual -->
    <div class="modal fade" id="modalAssociar" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Associar Classe a Curso</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_associacao"><div class="modal-body"><div class="mb-3"><label>Classe</label><select name="classe_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($classes as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Curso</label><select name="curso_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($cursos as $cs): ?><option value="<?php echo $cs['id']; ?>"><?php echo htmlspecialchars($cs['nome']); ?> (<?php echo $cs['codigo']; ?>)</option><?php endforeach; ?></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Associar</button></div></form></div></div></div>
    
    <!-- Modal Associar em Massa -->
    <div class="modal fade" id="modalAssociarMassa" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-layer-group"></i> Associar em Massa</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="associar_massa"><div class="modal-body"><div class="mb-3"><label>Selecione a Classe</label><select name="classe_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($classes as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label>Selecione os Cursos (vários)</label><div class="checkbox-group"><?php foreach ($cursos as $cs): ?><div class="checkbox-item"><input type="checkbox" name="cursos[]" value="<?php echo $cs['id']; ?>" id="curso_<?php echo $cs['id']; ?>"><label for="curso_<?php echo $cs['id']; ?>"> <strong><?php echo $cs['codigo']; ?></strong> - <?php echo htmlspecialchars($cs['nome']); ?></label></div><?php endforeach; ?></div></div><div class="alert alert-info"><i class="fas fa-info-circle"></i> Selecione um ou vários cursos para associar à classe selecionada.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Associar em Massa</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        <?php if (!empty($associacoes)): ?>$('#tabelaAssociacoes').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });<?php endif; ?>
    </script>
</body>
</html>