<?php
// escola/servicos_pedagogicos/coordenacao/comunicados.php - Gestão de Comunicados
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar comunicado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_comunicado') {
    $titulo = $_POST['titulo'];
    $conteudo = $_POST['conteudo'];
    $tipo = $_POST['tipo'];
    $prioridade = $_POST['prioridade'];
    $destinatarios = $_POST['destinatarios'];
    $data_publicacao = $_POST['data_publicacao'];
    $data_expiracao = $_POST['data_expiracao'] ?: null;
    
    $stmt = $conn->prepare("
        INSERT INTO comunicados_coordenacao 
        (escola_id, titulo, conteudo, tipo, prioridade, destinatarios, data_publicacao, data_expiracao, status, usuario_id)
        VALUES (:escola_id, :titulo, :conteudo, :tipo, :prioridade, :destinatarios, :data_publicacao, :data_expiracao, 'ativo', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':conteudo' => $conteudo,
        ':tipo' => $tipo,
        ':prioridade' => $prioridade,
        ':destinatarios' => $destinatarios,
        ':data_publicacao' => $data_publicacao,
        ':data_expiracao' => $data_expiracao,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Comunicado publicado com sucesso!";
    header("Location: comunicados.php");
    exit;
}

// Editar comunicado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_comunicado') {
    $id = $_POST['id'];
    $titulo = $_POST['titulo'];
    $conteudo = $_POST['conteudo'];
    $tipo = $_POST['tipo'];
    $prioridade = $_POST['prioridade'];
    $destinatarios = $_POST['destinatarios'];
    $data_publicacao = $_POST['data_publicacao'];
    $data_expiracao = $_POST['data_expiracao'] ?: null;
    
    $stmt = $conn->prepare("
        UPDATE comunicados_coordenacao 
        SET titulo = :titulo, conteudo = :conteudo, tipo = :tipo, prioridade = :prioridade,
            destinatarios = :destinatarios, data_publicacao = :data_publicacao, data_expiracao = :data_expiracao
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':conteudo' => $conteudo,
        ':tipo' => $tipo,
        ':prioridade' => $prioridade,
        ':destinatarios' => $destinatarios,
        ':data_publicacao' => $data_publicacao,
        ':data_expiracao' => $data_expiracao
    ]);
    
    $_SESSION['mensagem'] = "Comunicado atualizado!";
    header("Location: comunicados.php");
    exit;
}

// Arquivar/Desarquivar comunicado
if (isset($_GET['archive']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'arquivado' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE comunicados_coordenacao SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Comunicado " . ($novo_status == 'arquivado' ? 'arquivado' : 'restaurado') . "!";
    header("Location: comunicados.php");
    exit;
}

// Excluir comunicado
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM comunicados_coordenacao WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Comunicado excluído!";
    header("Location: comunicados.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? 'todos';
$sql = "SELECT c.*, u.nome as autor_nome FROM comunicados_coordenacao c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.escola_id = :escola_id";
if ($status_filter != 'todos') {
    $sql .= " AND c.status = :status";
}
$sql .= " ORDER BY c.created_at DESC";

$stmt = $conn->prepare($sql);
$params = [':escola_id' => $escola_id];
if ($status_filter != 'todos') {
    $params[':status'] = $status_filter;
}
$stmt->execute($params);
$comunicados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicados | Coordenação | SIGE Angola</title>
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
        
        .badge-urgente { background: #dc3545; color: white; }
        .badge-alta { background: #fd7e14; color: white; }
        .badge-media { background: #ffc107; color: #000; }
        .badge-baixa { background: #28a745; color: white; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-arquivado { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
        
        .comunicado-conteudo {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-bullhorn"></i> Gestão de Comunicados</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoComunicado"><i class="fas fa-plus"></i> Novo Comunicado</button>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list"></i> Lista de Comunicados</span>
                    <div>
                        <a href="?status=todos" class="btn btn-sm <?php echo $status_filter == 'todos' ? 'btn-primary' : 'btn-secondary'; ?>">Todos</a>
                        <a href="?status=ativo" class="btn btn-sm <?php echo $status_filter == 'ativo' ? 'btn-primary' : 'btn-secondary'; ?>">Ativos</a>
                        <a href="?status=arquivado" class="btn btn-sm <?php echo $status_filter == 'arquivado' ? 'btn-primary' : 'btn-secondary'; ?>">Arquivados</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaComunicados">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Título</th><th>Conteúdo</th><th>Tipo</th><th>Prioridade</th><th>Destinatários</th><th>Publicação</th><th>Expiração</th><th>Autor</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comunicados as $com): ?>
                            <tr>
                                <td><?php echo $com['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($com['titulo']); ?></strong></td>
                                <td><div class="comunicado-conteudo"><?php echo htmlspecialchars($com['conteudo']); ?></div></div>
                                <td><span class="badge bg-secondary"><?php echo ucfirst($com['tipo']); ?></span></div>
                                <td><span class="badge badge-<?php echo $com['prioridade']; ?>"><?php echo ucfirst($com['prioridade']); ?></span></div>
                                <td><?php echo htmlspecialchars($com['destinatarios']); ?></div>
                                <td><?php echo date('d/m/Y', strtotime($com['data_publicacao'])); ?></div>
                                <td><?php echo $com['data_expiracao'] ? date('d/m/Y', strtotime($com['data_expiracao'])) : '-'; ?></div>
                                <td><?php echo $com['autor_nome']; ?></div>
                                <td><span class="badge <?php echo $com['status'] == 'ativo' ? 'badge-ativo' : 'badge-arquivado'; ?>"><?php echo $com['status']; ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarComunicado(<?php echo $com['id']; ?>, '<?php echo addslashes($com['titulo']); ?>', '<?php echo addslashes($com['conteudo']); ?>', '<?php echo $com['tipo']; ?>', '<?php echo $com['prioridade']; ?>', '<?php echo addslashes($com['destinatarios']); ?>', '<?php echo $com['data_publicacao']; ?>', '<?php echo $com['data_expiracao']; ?>')"><i class="fas fa-edit"></i></button>
                                    <a href="?archive=1&id=<?php echo $com['id']; ?>&status=<?php echo $com['status']; ?>" class="btn btn-sm btn-warning"><i class="fas fa-archive"></i></a>
                                    <a href="?delete=1&id=<?php echo $com['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Comunicado -->
    <div class="modal fade" id="modalNovoComunicado" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Novo Comunicado</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_comunicado"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div><div class="mb-3"><label>Conteúdo</label><textarea name="conteudo" class="form-control" rows="4" required></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="informativo">Informativo</option><option value="aviso">Aviso</option><option value="urgente">Urgente</option><option value="circular">Circular</option></select></div><div class="col-md-6 mb-3"><label>Prioridade</label><select name="prioridade" class="form-control"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option></select></div></div><div class="mb-3"><label>Destinatários</label><input type="text" name="destinatarios" class="form-control" placeholder="Ex: Todos os professores, Coordenadores, etc."></div><div class="row"><div class="col-md-6 mb-3"><label>Data de Publicação</label><input type="date" name="data_publicacao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div><div class="col-md-6 mb-3"><label>Data de Expiração</label><input type="date" name="data_expiracao" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Publicar</button></div></form></div></div></div>
    
    <!-- Modal Editar Comunicado -->
    <div class="modal fade" id="modalEditarComunicado" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Comunicado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_comunicado"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" id="edit_titulo" class="form-control" required></div><div class="mb-3"><label>Conteúdo</label><textarea name="conteudo" id="edit_conteudo" class="form-control" rows="4" required></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" id="edit_tipo" class="form-control"><option value="informativo">Informativo</option><option value="aviso">Aviso</option><option value="urgente">Urgente</option><option value="circular">Circular</option></select></div><div class="col-md-6 mb-3"><label>Prioridade</label><select name="prioridade" id="edit_prioridade" class="form-control"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option></select></div></div><div class="mb-3"><label>Destinatários</label><input type="text" name="destinatarios" id="edit_destinatarios" class="form-control"></div><div class="row"><div class="col-md-6 mb-3"><label>Data de Publicação</label><input type="date" name="data_publicacao" id="edit_data_publicacao" class="form-control" required></div><div class="col-md-6 mb-3"><label>Data de Expiração</label><input type="date" name="data_expiracao" id="edit_data_expiracao" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaComunicados').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25, order: [[0, 'desc']] });
        function editarComunicado(id, titulo, conteudo, tipo, prioridade, destinatarios, data_publicacao, data_expiracao) {
            $('#edit_id').val(id); $('#edit_titulo').val(titulo); $('#edit_conteudo').val(conteudo); $('#edit_tipo').val(tipo); $('#edit_prioridade').val(prioridade);
            $('#edit_destinatarios').val(destinatarios); $('#edit_data_publicacao').val(data_publicacao); $('#edit_data_expiracao').val(data_expiracao);
            $('#modalEditarComunicado').modal('show');
        }
    </script>
</body>
</html>