<?php
// escola/servicos_pedagogicos/gerais/periodos.php - Gestão de Turnos

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
if (!in_array($usuario_tipo, ['admin_escola', 'diretor', 'secretaria'])) {
    header('Location: ../dashboard.php?erro=Sem permissão');
    exit;
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Adicionar novo turno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $nome = $_POST['nome'] ?? '';
    $sigla = $_POST['sigla'] ?? '';
    $horario_inicio = $_POST['horario_inicio'] ?? '';
    $horario_fim = $_POST['horario_fim'] ?? '';
    $duracao_aula = $_POST['duracao_aula'] ?? 50;
    $intervalo_inicio = $_POST['intervalo_inicio'] ?? null;
    $intervalo_fim = $_POST['intervalo_fim'] ?? null;
    $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : 'SEG,TER,QUA,QUI,SEX';
    $status = isset($_POST['status']) ? 1 : 0;
    
    if ($nome && $sigla) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO turnos (nome, sigla, horario_inicio, horario_fim, duracao_aula, 
                                   intervalo_inicio, intervalo_fim, dias_semana, escola_id, status, created_at)
                VALUES (:nome, :sigla, :horario_inicio, :horario_fim, :duracao_aula,
                        :intervalo_inicio, :intervalo_fim, :dias_semana, :escola_id, :status, NOW())
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':sigla' => $sigla,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':duracao_aula' => $duracao_aula,
                ':intervalo_inicio' => $intervalo_inicio,
                ':intervalo_fim' => $intervalo_fim,
                ':dias_semana' => $dias_semana,
                ':escola_id' => $escola_id,
                ':status' => $status
            ]);
            $_SESSION['success'] = "Turno adicionado com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar turno: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: periodos.php');
    exit;
}

// Editar turno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = $_POST['id'] ?? 0;
    $nome = $_POST['nome'] ?? '';
    $sigla = $_POST['sigla'] ?? '';
    $horario_inicio = $_POST['horario_inicio'] ?? '';
    $horario_fim = $_POST['horario_fim'] ?? '';
    $duracao_aula = $_POST['duracao_aula'] ?? 50;
    $intervalo_inicio = $_POST['intervalo_inicio'] ?? null;
    $intervalo_fim = $_POST['intervalo_fim'] ?? null;
    $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : 'SEG,TER,QUA,QUI,SEX';
    $status = isset($_POST['status']) ? 1 : 0;
    
    if ($id > 0 && $nome && $sigla) {
        try {
            $stmt = $conn->prepare("
                UPDATE turnos SET 
                    nome = :nome,
                    sigla = :sigla,
                    horario_inicio = :horario_inicio,
                    horario_fim = :horario_fim,
                    duracao_aula = :duracao_aula,
                    intervalo_inicio = :intervalo_inicio,
                    intervalo_fim = :intervalo_fim,
                    dias_semana = :dias_semana,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':sigla' => $sigla,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':duracao_aula' => $duracao_aula,
                ':intervalo_inicio' => $intervalo_inicio,
                ':intervalo_fim' => $intervalo_fim,
                ':dias_semana' => $dias_semana,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $_SESSION['success'] = "Turno atualizado com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar turno: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: periodos.php');
    exit;
}

// Excluir turno
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM turnos WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['success'] = "Turno excluído com sucesso!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao excluir turno: " . $e->getMessage();
    }
    header('Location: periodos.php');
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar todos os turnos da escola
$sql_turnos = "SELECT * FROM turnos WHERE escola_id = :escola_id ORDER BY horario_inicio";
$stmt_turnos = $conn->prepare($sql_turnos);
$stmt_turnos->execute([':escola_id' => $escola_id]);
$turnos = $stmt_turnos->fetchAll(PDO::FETCH_ASSOC);

// Buscar turno específico para edição
$turno_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("SELECT * FROM turnos WHERE id = :id AND escola_id = :escola_id");
    $stmt_editar->execute([':id' => $id_editar, ':escola_id' => $escola_id]);
    $turno_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Dias da semana para checkbox
$dias_semana_lista = [
    'SEG' => 'Segunda-feira',
    'TER' => 'Terça-feira',
    'QUA' => 'Quarta-feira',
    'QUI' => 'Quinta-feira',
    'SEX' => 'Sexta-feira',
    'SAB' => 'Sábado',
    'DOM' => 'Domingo'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Turnos | Serviços Pedagógicos | SIGE Angola</title>
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
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-warning { background: #ffc107; border: none; }
        .btn-danger { background: #dc3545; border: none; }
        .table th { background: #f8f9fa; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-ativo { background: #d4edda; color: #155724; }
        .status-inativo { background: #f8d7da; color: #721c24; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .form-label { font-weight: 600; }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-clock"></i> Gestão de Turnos / Períodos</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTurno" onclick="resetForm()">
                <i class="fas fa-plus"></i> Novo Turno
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
                <h5 class="mb-0"><i class="fas fa-list"></i> Turnos Cadastrados</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Sigla</th>
                                <th>Horário Início</th>
                                <th>Horário Fim</th>
                                <th>Duração Aula</th>
                                <th>Intervalo</th>
                                <th>Dias Letivos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($turnos)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        <i class="fas fa-clock fa-2x mb-2 d-block"></i>
                                        Nenhum turno cadastrado. Clique em "Novo Turno" para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($turnos as $turno): ?>
                                <tr>
                                    <td><?php echo $turno['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($turno['nome']); ?></strong></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($turno['sigla']); ?></span></td>
                                    <td><?php echo substr($turno['horario_inicio'], 0, 5); ?>:00</td>
                                    <td><?php echo substr($turno['horario_fim'], 0, 5); ?>:00</td>
                                    <td><?php echo $turno['duracao_aula']; ?> min</td>
                                    <td>
                                        <?php if ($turno['intervalo_inicio'] && $turno['intervalo_fim']): ?>
                                            <?php echo substr($turno['intervalo_inicio'], 0, 5); ?> - <?php echo substr($turno['intervalo_fim'], 0, 5); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Não definido</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $dias = explode(',', $turno['dias_semana']);
                                        foreach ($dias as $dia):
                                            $nome_dia = $dias_semana_lista[$dia] ?? $dia;
                                        ?>
                                            <span class="badge bg-info"><?php echo $nome_dia; ?></span>
                                        <?php endforeach; ?>
                                     </td>
                                    <td>
                                        <?php if ($turno['status']): ?>
                                            <span class="status-badge status-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inativo">Inativo</span>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <a href="?editar=1&id=<?php echo $turno['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?acao=excluir&id=<?php echo $turno['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este turno?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                     </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar/Editar Turno -->
    <div class="modal fade" id="modalTurno" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-clock"></i> Adicionar Turno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formTurno">
                    <input type="hidden" name="id" id="turno_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Nome do Turno</label>
                                <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: Manhã, Tarde, Noite">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Sigla</label>
                                <input type="text" name="sigla" id="sigla" class="form-control" required placeholder="Ex: M, T, N">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Horário Início</label>
                                <input type="time" name="horario_inicio" id="horario_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Horário Fim</label>
                                <input type="time" name="horario_fim" id="horario_fim" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Duração da Aula (minutos)</label>
                                <input type="number" name="duracao_aula" id="duracao_aula" class="form-control" required value="50" min="30" max="120">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Intervalo Início</label>
                                <input type="time" name="intervalo_inicio" id="intervalo_inicio" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Intervalo Fim</label>
                                <input type="time" name="intervalo_fim" id="intervalo_fim" class="form-control">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Dias Letivos</label>
                            <div class="row">
                                <?php foreach ($dias_semana_lista as $sigla => $nome): ?>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="dias_semana[]" value="<?php echo $sigla; ?>" id="dia_<?php echo $sigla; ?>">
                                        <label class="form-check-label" for="dia_<?php echo $sigla; ?>">
                                            <?php echo $nome; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Selecione os dias em que há aula neste turno</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                <label class="form-check-label" for="status">
                                    Turno Ativo
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Turno</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            $('#formTurno')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-clock"></i> Adicionar Turno');
            $('#turno_id').val('');
            // Marcar dias padrão (segunda a sexta)
            $('#dia_SEG').prop('checked', true);
            $('#dia_TER').prop('checked', true);
            $('#dia_QUA').prop('checked', true);
            $('#dia_QUI').prop('checked', true);
            $('#dia_SEX').prop('checked', true);
            $('#dia_SAB').prop('checked', false);
            $('#dia_DOM').prop('checked', false);
            $('#status').prop('checked', true);
        }
        
        <?php if ($turno_editar): ?>
        // Carregar dados para edição
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Turno');
            $('#acao').val('editar');
            $('#turno_id').val('<?php echo $turno_editar['id']; ?>');
            $('#nome').val('<?php echo addslashes($turno_editar['nome']); ?>');
            $('#sigla').val('<?php echo $turno_editar['sigla']; ?>');
            $('#horario_inicio').val('<?php echo substr($turno_editar['horario_inicio'], 0, 5); ?>');
            $('#horario_fim').val('<?php echo substr($turno_editar['horario_fim'], 0, 5); ?>');
            $('#duracao_aula').val('<?php echo $turno_editar['duracao_aula']; ?>');
            $('#intervalo_inicio').val('<?php echo $turno_editar['intervalo_inicio'] ? substr($turno_editar['intervalo_inicio'], 0, 5) : ''; ?>');
            $('#intervalo_fim').val('<?php echo $turno_editar['intervalo_fim'] ? substr($turno_editar['intervalo_fim'], 0, 5) : ''; ?>');
            $('#status').prop('checked', <?php echo $turno_editar['status'] ? 'true' : 'false'; ?>);
            
            // Marcar dias da semana
            var dias = '<?php echo $turno_editar['dias_semana']; ?>'.split(',');
            $('input[name="dias_semana[]"]').prop('checked', false);
            for(var i = 0; i < dias.length; i++) {
                $('#dia_' + dias[i]).prop('checked', true);
            }
            
            $('#modalTurno').modal('show');
        });
        <?php endif; ?>
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('servicos_pedagogicos')) {
            $('#menuGerais').addClass('open');
            $('#submenuGerais').addClass('show');
        }
    </script>
</body>
</html>