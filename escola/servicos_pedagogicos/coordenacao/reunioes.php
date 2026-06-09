<?php
// escola/servicos_pedagogicos/coordenacao/reunioes.php - Gestão de Reuniões
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

// Adicionar reunião
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_reuniao') {
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $data_reuniao = $_POST['data_reuniao'];
    $duracao = $_POST['duracao'];
    $local = $_POST['local'];
    $participantes = $_POST['participantes'];
    $pauta = $_POST['pauta'];
    
    $stmt = $conn->prepare("
        INSERT INTO reunioes_coordenacao 
        (escola_id, titulo, descricao, data_reuniao, duracao, local, participantes, pauta, status, usuario_id)
        VALUES (:escola_id, :titulo, :descricao, :data_reuniao, :duracao, :local, :participantes, :pauta, 'agendada', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':data_reuniao' => $data_reuniao,
        ':duracao' => $duracao,
        ':local' => $local,
        ':participantes' => $participantes,
        ':pauta' => $pauta,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Reunião agendada com sucesso!";
    header("Location: reunioes.php");
    exit;
}

// Editar reunião
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_reuniao') {
    $id = $_POST['id'];
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $data_reuniao = $_POST['data_reuniao'];
    $duracao = $_POST['duracao'];
    $local = $_POST['local'];
    $participantes = $_POST['participantes'];
    $pauta = $_POST['pauta'];
    
    $stmt = $conn->prepare("
        UPDATE reunioes_coordenacao 
        SET titulo = :titulo, descricao = :descricao, data_reuniao = :data_reuniao, duracao = :duracao,
            local = :local, participantes = :participantes, pauta = :pauta
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':data_reuniao' => $data_reuniao,
        ':duracao' => $duracao,
        ':local' => $local,
        ':participantes' => $participantes,
        ':pauta' => $pauta
    ]);
    
    $_SESSION['mensagem'] = "Reunião atualizada!";
    header("Location: reunioes.php");
    exit;
}

// Adicionar ata
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_ata') {
    $id = $_POST['id'];
    $ata = $_POST['ata'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE reunioes_coordenacao SET ata = :ata, status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':ata' => $ata, ':status' => $status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Ata registada com sucesso!";
    header("Location: reunioes.php");
    exit;
}

// Cancelar reunião
if (isset($_GET['cancel']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE reunioes_coordenacao SET status = 'cancelada' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Reunião cancelada!";
    header("Location: reunioes.php");
    exit;
}

// Excluir reunião
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM reunioes_coordenacao WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Reunião excluída!";
    header("Location: reunioes.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? 'todas';
$sql = "SELECT * FROM reunioes_coordenacao WHERE escola_id = :escola_id";
if ($status_filter != 'todas') {
    $sql .= " AND status = :status";
}
$sql .= " ORDER BY data_reuniao DESC";

$stmt = $conn->prepare($sql);
$params = [':escola_id' => $escola_id];
if ($status_filter != 'todas') {
    $params[':status'] = $status_filter;
}
$stmt->execute($params);
$reunioes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reuniões | Coordenação | SIGE Angola</title>
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
        
        .badge-agendada { background: #17a2b8; color: white; }
        .badge-realizada { background: #28a745; color: white; }
        .badge-cancelada { background: #dc3545; color: white; }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
 
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calendar-alt"></i> Gestão de Reuniões</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaReuniao"><i class="fas fa-plus"></i> Agendar Reunião</button>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list"></i> Lista de Reuniões</span>
                    <div>
                        <a href="?status=todas" class="btn btn-sm <?php echo $status_filter == 'todas' ? 'btn-primary' : 'btn-secondary'; ?>">Todas</a>
                        <a href="?status=agendada" class="btn btn-sm <?php echo $status_filter == 'agendada' ? 'btn-primary' : 'btn-secondary'; ?>">Agendadas</a>
                        <a href="?status=realizada" class="btn btn-sm <?php echo $status_filter == 'realizada' ? 'btn-primary' : 'btn-secondary'; ?>">Realizadas</a>
                        <a href="?status=cancelada" class="btn btn-sm <?php echo $status_filter == 'cancelada' ? 'btn-primary' : 'btn-secondary'; ?>">Canceladas</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaReunioes">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Título</th><th>Data/Hora</th><th>Duração</th><th>Local</th><th>Participantes</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reunioes as $reu): ?>
                            <tr>
                                <td><?php echo $reu['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($reu['titulo']); ?></strong><br><small><?php echo htmlspecialchars(substr($reu['descricao'], 0, 50)); ?></small></div>
                                <td><?php echo date('d/m/Y H:i', strtotime($reu['data_reuniao'])); ?></div>
                                <td><?php echo $reu['duracao']; ?> min</div>
                                <td><?php echo $reu['local']; ?></div>
                                <td><?php echo htmlspecialchars($reu['participantes']); ?></div>
                                <td><span class="badge badge-<?php echo $reu['status']; ?>"><?php echo ucfirst($reu['status']); ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarReuniao(<?php echo $reu['id']; ?>, '<?php echo addslashes($reu['titulo']); ?>', '<?php echo addslashes($reu['descricao']); ?>', '<?php echo $reu['data_reuniao']; ?>', <?php echo $reu['duracao']; ?>, '<?php echo addslashes($reu['local']); ?>', '<?php echo addslashes($reu['participantes']); ?>', '<?php echo addslashes($reu['pauta']); ?>')"><i class="fas fa-edit"></i></button>
                                    <?php if ($reu['status'] == 'agendada'): ?>
                                    <button class="btn btn-sm btn-success" onclick="registrarAta(<?php echo $reu['id']; ?>, '<?php echo addslashes($reu['titulo']); ?>')"><i class="fas fa-file-alt"></i> Ata</button>
                                    <a href="?cancel=1&id=<?php echo $reu['id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Cancelar esta reunião?')"><i class="fas fa-times"></i></a>
                                    <?php endif; ?>
                                    <a href="?delete=1&id=<?php echo $reu['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir esta reunião?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Reunião -->
    <div class="modal fade" id="modalNovaReuniao" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Agendar Reunião</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_reuniao"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Data e Hora</label><input type="datetime-local" name="data_reuniao" class="form-control" required></div><div class="col-md-6 mb-3"><label>Duração (minutos)</label><input type="number" name="duracao" class="form-control" value="60" required></div></div><div class="row"><div class="col-md-6 mb-3"><label>Local</label><input type="text" name="local" class="form-control" placeholder="Sala de reuniões, Auditório"></div><div class="col-md-6 mb-3"><label>Participantes</label><input type="text" name="participantes" class="form-control" placeholder="Ex: Coordenadores, Professores"></div></div><div class="mb-3"><label>Pauta</label><textarea name="pauta" class="form-control" rows="3" placeholder="1. Abertura\n2. Assuntos em discussão\n3. Encaminhamentos"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Agendar</button></div></form></div></div></div>
    
    <!-- Modal Editar Reunião -->
    <div class="modal fade" id="modalEditarReuniao" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Reunião</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_reuniao"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" id="edit_titulo" class="form-control" required></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Data e Hora</label><input type="datetime-local" name="data_reuniao" id="edit_data_reuniao" class="form-control" required></div><div class="col-md-6 mb-3"><label>Duração (minutos)</label><input type="number" name="duracao" id="edit_duracao" class="form-control" required></div></div><div class="row"><div class="col-md-6 mb-3"><label>Local</label><input type="text" name="local" id="edit_local" class="form-control"></div><div class="col-md-6 mb-3"><label>Participantes</label><input type="text" name="participantes" id="edit_participantes" class="form-control"></div></div><div class="mb-3"><label>Pauta</label><textarea name="pauta" id="edit_pauta" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Registrar Ata -->
    <div class="modal fade" id="modalRegistrarAta" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-file-alt"></i> Registrar Ata da Reunião</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_ata"><input type="hidden" name="id" id="ata_id"><div class="modal-body"><div class="alert alert-info"><i class="fas fa-info-circle"></i> Reunião: <strong id="ata_titulo"></strong></div><div class="mb-3"><label>Ata da Reunião</label><textarea name="ata" class="form-control" rows="8" placeholder="Registre aqui os pontos discutidos, decisões tomadas e encaminhamentos..."></textarea></div><div class="mb-3"><label>Status</label><select name="status" class="form-control"><option value="realizada">Realizada</option><option value="cancelada">Cancelada</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Registrar Ata</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaReunioes').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25, order: [[0, 'desc']] });
        function editarReuniao(id, titulo, desc, data, duracao, local, participantes, pauta) {
            $('#edit_id').val(id); $('#edit_titulo').val(titulo); $('#edit_descricao').val(desc); $('#edit_data_reuniao').val(data);
            $('#edit_duracao').val(duracao); $('#edit_local').val(local); $('#edit_participantes').val(participantes); $('#edit_pauta').val(pauta);
            $('#modalEditarReuniao').modal('show');
        }
        function registrarAta(id, titulo) { $('#ata_id').val(id); $('#ata_titulo').text(titulo); $('#modalRegistrarAta').modal('show'); }
    </script>
</body>
</html>