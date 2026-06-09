<?php
// escola/config/sistema/calendario_provas.php - Calendário de Provas
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

// Verificar e criar tabela
$check = $conn->query("SHOW TABLES LIKE 'escola_calendario_provas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_calendario_provas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            disciplina_id INT,
            turma_id INT,
            data_prova DATE NOT NULL,
            hora_inicio TIME,
            hora_fim TIME,
            sala VARCHAR(50),
            observacoes TEXT,
            status ENUM('agendada', 'realizada', 'cancelada') DEFAULT 'agendada',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE SET NULL,
            FOREIGN KEY (turma_id) REFERENCES turmas(id) ON DELETE SET NULL
        )
    ");
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_prova') {
        $titulo = $_POST['titulo'];
        $disciplina_id = $_POST['disciplina_id'] ?: null;
        $turma_id = $_POST['turma_id'] ?: null;
        $data_prova = $_POST['data_prova'];
        $hora_inicio = $_POST['hora_inicio'];
        $hora_fim = $_POST['hora_fim'];
        $sala = $_POST['sala'];
        $observacoes = $_POST['observacoes'];
        
        $stmt = $conn->prepare("
            INSERT INTO escola_calendario_provas (escola_id, titulo, disciplina_id, turma_id, data_prova, hora_inicio, hora_fim, sala, observacoes, status)
            VALUES (:escola_id, :titulo, :disciplina_id, :turma_id, :data_prova, :hora_inicio, :hora_fim, :sala, :observacoes, 'agendada')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':titulo' => $titulo,
            ':disciplina_id' => $disciplina_id,
            ':turma_id' => $turma_id,
            ':data_prova' => $data_prova,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim,
            ':sala' => $sala,
            ':observacoes' => $observacoes
        ]);
        
        $_SESSION['mensagem'] = "Prova agendada com sucesso!";
        header("Location: calendario_provas.php");
        exit;
    }
    
    if ($acao == 'edit_prova') {
        $id = $_POST['id'];
        $titulo = $_POST['titulo'];
        $disciplina_id = $_POST['disciplina_id'] ?: null;
        $turma_id = $_POST['turma_id'] ?: null;
        $data_prova = $_POST['data_prova'];
        $hora_inicio = $_POST['hora_inicio'];
        $hora_fim = $_POST['hora_fim'];
        $sala = $_POST['sala'];
        $observacoes = $_POST['observacoes'];
        
        $stmt = $conn->prepare("
            UPDATE escola_calendario_provas 
            SET titulo = :titulo, disciplina_id = :disciplina_id, turma_id = :turma_id,
                data_prova = :data_prova, hora_inicio = :hora_inicio, hora_fim = :hora_fim,
                sala = :sala, observacoes = :observacoes
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':titulo' => $titulo,
            ':disciplina_id' => $disciplina_id,
            ':turma_id' => $turma_id,
            ':data_prova' => $data_prova,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim,
            ':sala' => $sala,
            ':observacoes' => $observacoes
        ]);
        
        $_SESSION['mensagem'] = "Prova atualizada!";
        header("Location: calendario_provas.php");
        exit;
    }
}

// Atualizar status
if (isset($_GET['status']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'];
    
    $stmt = $conn->prepare("UPDATE escola_calendario_provas SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status atualizado!";
    header("Location: calendario_provas.php");
    exit;
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM escola_calendario_provas WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Prova excluída!";
    header("Location: calendario_provas.php");
    exit;
}

// Buscar dados
$provas = $conn->prepare("
    SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome
    FROM escola_calendario_provas p
    LEFT JOIN disciplinas d ON d.id = p.disciplina_id
    LEFT JOIN turmas t ON t.id = p.turma_id
    WHERE p.escola_id = :escola_id
    ORDER BY p.data_prova ASC, p.hora_inicio ASC
");
$provas->execute([':escola_id' => $escola_id]);
$provas = $provas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas e turmas para selects
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

$turmas = $conn->prepare("SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendário de Provas | SIGE Angola</title>
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
        .evento-prova { border-left: 3px solid #dc3545; margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 8px; }
        .evento-realizada { border-left-color: #28a745; }
        .evento-cancelada { border-left-color: #6c757d; opacity: 0.7; }
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
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/contas.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="../pagamento/formas.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="calendario_provas.php" class="nav-link active"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calendar-alt"></i> Calendário de Provas</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaProva"><i class="fas fa-plus"></i> Agendar Prova</button>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Provas Agendadas</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaProvas">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Data</th><th>Horário</th><th>Título</th><th>Disciplina</th><th>Turma</th><th>Sala</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($provas as $prova): ?>
                            <tr>
                                <td><?php echo $prova['id']; ?></td>
                                <td><strong><?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?></strong></div>
                                <td><?php echo substr($prova['hora_inicio'], 0, 5); ?> - <?php echo substr($prova['hora_fim'], 0, 5); ?></div>
                                <td><?php echo htmlspecialchars($prova['titulo']); ?></div>
                                <td><?php echo $prova['disciplina_nome'] ?? '-'; ?></div>
                                <td><?php echo $prova['turma_nome'] ?? '-'; ?></div>
                                <td><?php echo $prova['sala']; ?></div>
                                <td><span class="badge bg-<?php echo $prova['status'] == 'agendada' ? 'warning' : ($prova['status'] == 'realizada' ? 'success' : 'secondary'); ?>"><?php echo ucfirst($prova['status']); ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarProva(<?php echo $prova['id']; ?>, '<?php echo addslashes($prova['titulo']); ?>', <?php echo $prova['disciplina_id'] ?: 'null'; ?>, <?php echo $prova['turma_id'] ?: 'null'; ?>, '<?php echo $prova['data_prova']; ?>', '<?php echo $prova['hora_inicio']; ?>', '<?php echo $prova['hora_fim']; ?>', '<?php echo $prova['sala']; ?>', '<?php echo addslashes($prova['observacoes']); ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-tasks"></i></button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?status=agendada&id=<?php echo $prova['id']; ?>">📋 Agendada</a></li>
                                            <li><a class="dropdown-item" href="?status=realizada&id=<?php echo $prova['id']; ?>">✅ Realizada</a></li>
                                            <li><a class="dropdown-item" href="?status=cancelada&id=<?php echo $prova['id']; ?>">❌ Cancelada</a></li>
                                        </ul>
                                    </div>
                                    <a href="?delete=1&id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir esta prova?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Prova -->
    <div class="modal fade" id="modalNovaProva" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Agendar Prova</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_prova"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" required></div><div class="row"><div class="col-md-6 mb-3"><label>Disciplina</label><select name="disciplina_id" class="form-control"><option value="">Selecione...</option><?php foreach ($disciplinas as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option><?php endforeach; ?></select></div><div class="col-md-6 mb-3"><label>Turma</label><select name="turma_id" class="form-control"><option value="">Selecione...</option><?php foreach ($turmas as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option><?php endforeach; ?></select></div></div><div class="row"><div class="col-md-4 mb-3"><label>Data</label><input type="date" name="data_prova" class="form-control" required></div><div class="col-md-4 mb-3"><label>Hora Início</label><input type="time" name="hora_inicio" class="form-control" required></div><div class="col-md-4 mb-3"><label>Hora Fim</label><input type="time" name="hora_fim" class="form-control" required></div></div><div class="row"><div class="col-md-6 mb-3"><label>Sala</label><input type="text" name="sala" class="form-control" placeholder="Ex: Sala 101"></div></div><div class="mb-3"><label>Observações</label><textarea name="observacoes" class="form-control" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Agendar</button></div></form></div></div></div>
    
    <!-- Modal Editar Prova -->
    <div class="modal fade" id="modalEditarProva" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Prova</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_prova"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="mb-3"><label>Título</label><input type="text" name="titulo" id="edit_titulo" class="form-control" required></div><div class="row"><div class="col-md-6 mb-3"><label>Disciplina</label><select name="disciplina_id" id="edit_disciplina_id" class="form-control"><option value="">Selecione...</option><?php foreach ($disciplinas as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option><?php endforeach; ?></select></div><div class="col-md-6 mb-3"><label>Turma</label><select name="turma_id" id="edit_turma_id" class="form-control"><option value="">Selecione...</option><?php foreach ($turmas as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option><?php endforeach; ?></select></div></div><div class="row"><div class="col-md-4 mb-3"><label>Data</label><input type="date" name="data_prova" id="edit_data_prova" class="form-control" required></div><div class="col-md-4 mb-3"><label>Hora Início</label><input type="time" name="hora_inicio" id="edit_hora_inicio" class="form-control" required></div><div class="col-md-4 mb-3"><label>Hora Fim</label><input type="time" name="hora_fim" id="edit_hora_fim" class="form-control" required></div></div><div class="row"><div class="col-md-6 mb-3"><label>Sala</label><input type="text" name="sala" id="edit_sala" class="form-control"></div></div><div class="mb-3"><label>Observações</label><textarea name="observacoes" id="edit_observacoes" class="form-control" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaProvas').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25, order: [[1, 'asc']] });
        function editarProva(id, titulo, disciplina_id, turma_id, data, hora_inicio, hora_fim, sala, obs) {
            $('#edit_id').val(id); $('#edit_titulo').val(titulo); $('#edit_disciplina_id').val(disciplina_id); $('#edit_turma_id').val(turma_id);
            $('#edit_data_prova').val(data); $('#edit_hora_inicio').val(hora_inicio); $('#edit_hora_fim').val(hora_fim);
            $('#edit_sala').val(sala); $('#edit_observacoes').val(obs);
            $('#modalEditarProva').modal('show');
        }
    </script>
</body>
</html>