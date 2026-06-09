<?php
// escola/servicos_pedagogicos/gerais/turmas.php - Gestão de Turmas

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? '';

// Verificar se tem permissão (apenas admin, diretor ou secretaria)
if (!in_array($usuario_tipo, ['admin_escola', 'diretor', 'secretaria', 'professor'])) {
    header('Location: ../dashboard.php?erro=Sem permissão');
    exit;
}

// ============================================
// PAGINAÇÃO
// ============================================
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 10;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Adicionar nova turma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $nome = $_POST['nome'] ?? '';
    $ano = $_POST['ano'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $sala = $_POST['sala'] ?? '';
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    $status = isset($_POST['status']) ? 'ativa' : 'inativa';
    
    if ($nome && $ano && $turno) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO turmas (escola_id, nome, ano, turno, sala, ano_letivo, status, created_at)
                VALUES (:escola_id, :nome, :ano, :turno, :sala, :ano_letivo, :status, NOW())
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':ano' => $ano,
                ':turno' => $turno,
                ':sala' => $sala,
                ':ano_letivo' => $ano_letivo,
                ':status' => $status
            ]);
            $_SESSION['success'] = "Turma adicionada com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar turma: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: turmas.php');
    exit;
}

// Editar turma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = $_POST['id'] ?? 0;
    $nome = $_POST['nome'] ?? '';
    $ano = $_POST['ano'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $sala = $_POST['sala'] ?? '';
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    $status = isset($_POST['status']) ? 'ativa' : 'inativa';
    
    if ($id > 0 && $nome && $ano && $turno) {
        try {
            $stmt = $conn->prepare("
                UPDATE turmas SET 
                    nome = :nome,
                    ano = :ano,
                    turno = :turno,
                    sala = :sala,
                    ano_letivo = :ano_letivo,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':ano' => $ano,
                ':turno' => $turno,
                ':sala' => $sala,
                ':ano_letivo' => $ano_letivo,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $_SESSION['success'] = "Turma atualizada com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar turma: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: turmas.php');
    exit;
}

// Excluir turma (via AJAX)
if (isset($_POST['acao']) && $_POST['acao'] == 'excluir' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM turmas WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// BUSCAR DADOS COM PAGINAÇÃO
// ============================================

// Contar total de turmas
$sql_count = "SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id";
$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute([':escola_id' => $escola_id]);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Buscar turmas
$sql_turmas = "SELECT * FROM turmas WHERE escola_id = :escola_id ORDER BY ano_letivo DESC, ano, nome LIMIT :offset, :por_pagina";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->bindParam(':escola_id', $escola_id);
$stmt_turmas->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt_turmas->bindParam(':por_pagina', $por_pagina, PDO::PARAM_INT);
$stmt_turmas->execute();
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar turma específica para edição
$turma_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("SELECT * FROM turmas WHERE id = :id AND escola_id = :escola_id");
    $stmt_editar->execute([':id' => $id_editar, ':escola_id' => $escola_id]);
    $turma_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Buscar turnos para o select
$turnos = $conn->query("SELECT id, nome, sigla FROM turnos WHERE status = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Turmas | Serviços Pedagógicos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
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
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-warning { background: #ffc107; border: none; }
        .btn-danger { background: #dc3545; border: none; }
        .btn-info { background: #17a2b8; border: none; color: white; }
        .btn-info:hover { background: #138496; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #1e7e34; }
        .table th { background: #f8f9fa; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-ativa { background: #d4edda; color: #155724; }
        .status-inativa { background: #f8d7da; color: #721c24; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .form-label { font-weight: 600; }
        .pagination .page-link { color: #006B3E; }
        .pagination .active .page-link { background-color: #006B3E; border-color: #006B3E; color: white; }
        
        /* Modal Ver Alunos */
        .modal-ver-alunos .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .lista-nominal-table {
            font-size: 12px;
        }
        .lista-nominal-table th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        .lista-nominal-table td {
            text-align: center;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users"></i> Gestão de Turmas</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTurma" onclick="resetForm()">
                <i class="fas fa-plus"></i> Nova Turma
            </button>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Turmas Cadastradas</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Turma</th>
                                <th>Ano</th>
                                <th>Turno</th>
                                <th>Sala</th>
                                <th>Ano Letivo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($turmas)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                        Nenhuma turma cadastrada. Clique em "Nova Turma" para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($turmas as $turma): ?>
                                <tr>
                                    <td><?php echo $turma['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($turma['nome']); ?></strong></td>
                                    <td><?php echo $turma['ano']; ?>ª Classe</td>
                                    <td><?php echo ucfirst(htmlspecialchars($turma['turno'])); ?></td>
                                    <td><?php echo htmlspecialchars($turma['sala'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($turma['ano_letivo']); ?></td>
                                    <td>
                                        <?php if ($turma['status'] == 'ativa'): ?>
                                            <span class="status-badge status-ativa">Ativa</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inativa">Inativa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" title="Ver Alunos" onclick="verAlunos(<?php echo $turma['id']; ?>, '<?php echo addslashes($turma['nome']); ?>', <?php echo $turma['ano']; ?>, '<?php echo $turma['turno']; ?>', '<?php echo $turma['sala']; ?>', '<?php echo $turma['ano_letivo']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="?editar=1&id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-success" title="Gerar PDF" onclick="gerarPDF(<?php echo $turma['id']; ?>)">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" title="Excluir" onclick="confirmarExclusao(<?php echo $turma['id']; ?>, '<?php echo addslashes($turma['nome']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                <nav aria-label="Paginação" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        </li>
                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                        <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar/Editar Turma -->
    <div class="modal fade" id="modalTurma" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-users"></i> Adicionar Turma</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formTurma">
                    <input type="hidden" name="id" id="turma_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Nome da Turma</label>
                            <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: A, B, C, Única">
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Ano / Classe</label>
                            <select name="ano" id="ano" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php for ($i = 1; $i <= 13; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?>ª Classe</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Turno</label>
                            <select name="turno" id="turno" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($turnos as $turno): ?>
                                <option value="<?php echo $turno['nome']; ?>"><?php echo $turno['nome']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sala</label>
                            <input type="text" name="sala" id="sala" class="form-control" placeholder="Ex: Sala 1, Laboratório A">
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Ano Letivo</label>
                            <select name="ano_letivo" id="ano_letivo" class="form-control" required>
                                <?php for ($i = date('Y'); $i >= 2020; $i--): ?>
                                <option value="<?php echo $i . '/' . ($i + 1); ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>>
                                    <?php echo $i . '/' . ($i + 1); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                <label class="form-check-label" for="status">
                                    Turma Ativa
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Turma</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Ver Alunos da Turma -->
    <div class="modal fade modal-ver-alunos" id="modalVerAlunos" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalAlunosTitle"><i class="fas fa-users"></i> Alunos da Turma</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalAlunosBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">Carregando alunos...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-success" id="btnGerarPDFLista">
                        <i class="fas fa-file-pdf"></i> Gerar PDF da Lista
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação de Exclusão -->
    <div class="modal fade" id="modalExcluir" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir a turma <strong id="turmaNome"></strong>?</p>
                    <p class="text-danger small">Esta ação não pode ser desfeita. Todos os dados relacionados a esta turma serão removidos.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarExcluir">Sim, excluir</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var turmaIdParaExcluir = null;
        var turmaAtual = null;
        
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function resetForm() {
            $('#formTurma')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-users"></i> Adicionar Turma');
            $('#turma_id').val('');
            $('#status').prop('checked', true);
        }
        
        function confirmarExclusao(id, nome) {
            turmaIdParaExcluir = id;
            $('#turmaNome').text(nome);
            $('#modalExcluir').modal('show');
        }
        
        $('#btnConfirmarExcluir').on('click', function() {
            if (turmaIdParaExcluir) {
                $.ajax({
                    url: 'turmas.php',
                    method: 'POST',
                    data: {
                        acao: 'excluir',
                        id: turmaIdParaExcluir
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erro ao excluir turma: ' + response.error);
                            $('#modalExcluir').modal('hide');
                        }
                    },
                    error: function() {
                        alert('Erro ao processar a requisição.');
                        $('#modalExcluir').modal('hide');
                    }
                });
            }
        });
        
        function verAlunos(id, nome, ano, turno, sala, anoLetivo) {
            turmaAtual = {id: id, nome: nome, ano: ano, turno: turno, sala: sala, anoLetivo: anoLetivo};
            $('#modalAlunosTitle').html('<i class="fas fa-users"></i> Alunos da Turma - ' + nome + ' (' + ano + 'ª Classe - ' + turno + ')');
            $('#modalAlunosBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Carregando alunos...</p></div>');
            $('#modalVerAlunos').modal('show');
            
            $.ajax({
                url: 'get_alunos_turma.php',
                method: 'GET',
                data: {turma_id: id},
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        var html = '';
                        if (data.alunos.length === 0) {
                            html = '<div class="alert alert-info text-center">Nenhum aluno matriculado nesta turma.</div>';
                        } else {
                            html = '<div class="table-responsive">';
                            html += '<table class="table table-hover lista-nominal-table">';
                            html += '<thead><tr>';
                            html += '<th>Nº</th><th>Nº Processo</th><th>Nome do estudante</th><th>Data</th><th>Género</th>';
                            html += '</tr></thead><tbody>';
                            for (var i = 0; i < data.alunos.length; i++) {
                                var aluno = data.alunos[i];
                                var num = i + 1;
                                var dataNasc = aluno.data_nascimento ? aluno.data_nascimento.split('-').reverse().join(' / ') : '__ / __ / ____';
                                html += '<tr>';
                                html += '<td>' + num + '</td>';
                                html += '<td>' + (aluno.numero_matricula || aluno.matricula) + '</td>';
                                html += '<td>' + aluno.nome + '</td>';
                                html += '<td>' + dataNasc + '</td>';
                                html += '<td>' + (aluno.genero == 'M' ? 'Masculino' : 'Feminino') + '</td>';
                                html += '</tr>';
                            }
                            html += '</tbody></table>';
                            html += '<div class="text-end mt-3"><strong>Total: ' + data.alunos.length + '</strong></div>';
                            html += '</div>';
                        }
                        $('#modalAlunosBody').html(html);
                    } else {
                        $('#modalAlunosBody').html('<div class="alert alert-danger">Erro ao carregar alunos: ' + data.message + '</div>');
                    }
                },
                error: function() {
                    $('#modalAlunosBody').html('<div class="alert alert-danger">Erro ao carregar alunos.</div>');
                }
            });
        }
        
        function gerarPDF(id) {
            window.open('gerar_lista_alunos_pdf.php?turma_id=' + id, '_blank');
        }
        
        $('#btnGerarPDFLista').on('click', function() {
            if (turmaAtual) {
                window.open('gerar_lista_alunos_pdf.php?turma_id=' + turmaAtual.id, '_blank');
            }
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('servicos_pedagogicos')) {
            $('#menuGerais').addClass('open');
            $('#submenuGerais').addClass('show');
        }
        
        <?php if ($turma_editar): ?>
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Turma');
            $('#acao').val('editar');
            $('#turma_id').val('<?php echo $turma_editar['id']; ?>');
            $('#nome').val('<?php echo addslashes($turma_editar['nome']); ?>');
            $('#ano').val('<?php echo $turma_editar['ano']; ?>');
            $('#turno').val('<?php echo $turma_editar['turno']; ?>');
            $('#sala').val('<?php echo addslashes($turma_editar['sala']); ?>');
            $('#ano_letivo').val('<?php echo $turma_editar['ano_letivo']; ?>');
            $('#status').prop('checked', <?php echo $turma_editar['status'] == 'ativa' ? 'true' : 'false'; ?>);
            
            $('#modalTurma').modal('show');
        });
        <?php endif; ?>
    </script>
</body>
</html>