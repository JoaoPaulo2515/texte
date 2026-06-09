<?php
// escola/servicos_pedagogicos/gerais/salas.php - Gestão de Salas

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
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? '';

// Verificar se tem permissão (apenas admin, diretor ou secretaria)
if (!in_array($usuario_tipo, ['admin_escola', 'diretor', 'secretaria'])) {
    header('Location: ../dashboard.php?erro=Sem permissão');
    exit;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarCodigoSala($conn, $escola_id) {
    // Buscar o último código gerado
    $stmt = $conn->prepare("SELECT codigo FROM salas WHERE escola_id = :escola_id AND codigo LIKE 'SALA%' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':escola_id' => $escola_id]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo && preg_match('/(\d+)/', $ultimo['codigo'], $matches)) {
        $numero = (int)$matches[1] + 1;
    } else {
        $numero = 1;
    }
    
    return 'SALA' . str_pad($numero, 3, '0', STR_PAD_LEFT);
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Adicionar nova sala
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $nome = $_POST['nome'] ?? '';
    $tipo = $_POST['tipo'] ?? 'comum';
    $capacidade = $_POST['capacidade'] ?? 0;
    $localizacao = $_POST['localizacao'] ?? '';
    $bloco = $_POST['bloco'] ?? '';
    $andar = $_POST['andar'] ?? null;
    $recursos = $_POST['recursos'] ?? '';
    $responsavel = $_POST['responsavel'] ?? '';
    $telefone_ramal = $_POST['telefone_ramal'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Gerar código automaticamente
    $codigo = gerarCodigoSala($conn, $escola_id);
    
    if ($nome) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO salas (nome, codigo, tipo, capacidade, localizacao, bloco, andar, 
                                   recursos, responsavel, telefone_ramal, escola_id, status, created_at)
                VALUES (:nome, :codigo, :tipo, :capacidade, :localizacao, :bloco, :andar,
                        :recursos, :responsavel, :telefone_ramal, :escola_id, :status, NOW())
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':codigo' => $codigo,
                ':tipo' => $tipo,
                ':capacidade' => $capacidade,
                ':localizacao' => $localizacao,
                ':bloco' => $bloco,
                ':andar' => $andar ?: null,
                ':recursos' => $recursos,
                ':responsavel' => $responsavel,
                ':telefone_ramal' => $telefone_ramal,
                ':escola_id' => $escola_id,
                ':status' => $status
            ]);
            $_SESSION['success'] = "Sala adicionada com sucesso! Código: " . $codigo;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar sala: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha o campo nome da sala.";
    }
    header('Location: salas.php');
    exit;
}

// Editar sala
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = $_POST['id'] ?? 0;
    $nome = $_POST['nome'] ?? '';
    $tipo = $_POST['tipo'] ?? 'comum';
    $capacidade = $_POST['capacidade'] ?? 0;
    $localizacao = $_POST['localizacao'] ?? '';
    $bloco = $_POST['bloco'] ?? '';
    $andar = $_POST['andar'] ?? null;
    $recursos = $_POST['recursos'] ?? '';
    $responsavel = $_POST['responsavel'] ?? '';
    $telefone_ramal = $_POST['telefone_ramal'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    if ($id > 0 && $nome) {
        try {
            $stmt = $conn->prepare("
                UPDATE salas SET 
                    nome = :nome,
                    tipo = :tipo,
                    capacidade = :capacidade,
                    localizacao = :localizacao,
                    bloco = :bloco,
                    andar = :andar,
                    recursos = :recursos,
                    responsavel = :responsavel,
                    telefone_ramal = :telefone_ramal,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':tipo' => $tipo,
                ':capacidade' => $capacidade,
                ':localizacao' => $localizacao,
                ':bloco' => $bloco,
                ':andar' => $andar ?: null,
                ':recursos' => $recursos,
                ':responsavel' => $responsavel,
                ':telefone_ramal' => $telefone_ramal,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $_SESSION['success'] = "Sala atualizada com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar sala: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: salas.php');
    exit;
}

// Excluir sala
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM salas WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['success'] = "Sala excluída com sucesso!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao excluir sala: " . $e->getMessage();
    }
    header('Location: salas.php');
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar todas as salas da escola
$sql_salas = "SELECT * FROM salas WHERE escola_id = :escola_id ORDER BY bloco, andar, nome";
$stmt_salas = $conn->prepare($sql_salas);
$stmt_salas->execute([':escola_id' => $escola_id]);
$salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

// Buscar sala específica para edição
$sala_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("SELECT * FROM salas WHERE id = :id AND escola_id = :escola_id");
    $stmt_editar->execute([':id' => $id_editar, ':escola_id' => $escola_id]);
    $sala_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Tipos de sala
$tipos_sala = [
    'comum' => 'Sala Comum',
    'laboratorio' => 'Laboratório',
    'auditorio' => 'Auditório',
    'biblioteca' => 'Biblioteca',
    'quadra' => 'Quadra Desportiva',
    'oficina' => 'Oficina',
    'informatica' => 'Sala de Informática',
    'multimedia' => 'Sala Multimédia',
    'outro' => 'Outro'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Salas | Serviços Pedagógicos | SIGE Angola</title>
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
        .codigo-badge { background: #e9ecef; padding: 8px 12px; border-radius: 8px; font-family: monospace; font-size: 14px; }
        .tipo-badge { padding: 4px 8px; border-radius: 15px; font-size: 11px; }
        .tipo-comum { background: #17a2b8; color: white; }
        .tipo-laboratorio { background: #28a745; color: white; }
        .tipo-auditorio { background: #fd7e14; color: white; }
        .tipo-biblioteca { background: #6f42c1; color: white; }
        .tipo-quadra { background: #20c997; color: white; }
        .tipo-oficina { background: #e83e8c; color: white; }
        .tipo-informatica { background: #007bff; color: white; }
        .tipo-outro { background: #6c757d; color: white; }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-door-open"></i> Gestão de Salas</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalSala" onclick="resetForm()">
                <i class="fas fa-plus"></i> Nova Sala
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
                <h5 class="mb-0"><i class="fas fa-list"></i> Salas Cadastradas</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Capacidade</th>
                                <th>Localização</th>
                                <th>Responsável</th>
                                <th>Recursos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($salas)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-door-open fa-2x mb-2 d-block"></i>
                                        Nenhuma sala cadastrada. Clique em "Nova Sala" para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($salas as $sala): ?>
                                <tr>
                                    <td><span class="codigo-badge"><?php echo htmlspecialchars($sala['codigo']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($sala['nome']); ?></strong></td>
                                    <td>
                                        <span class="tipo-badge tipo-<?php echo $sala['tipo']; ?>">
                                            <?php echo $tipos_sala[$sala['tipo']] ?? $sala['tipo']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $sala['capacidade']; ?> alunos</td>
                                    <td>
                                        <?php 
                                        $local = [];
                                        if ($sala['bloco']) $local[] = "Bloco " . $sala['bloco'];
                                        if ($sala['andar']) $local[] = $sala['andar'] . "º Andar";
                                        if ($sala['localizacao']) $local[] = $sala['localizacao'];
                                        echo implode(' / ', $local) ?: '<span class="text-muted">Não definido</span>';
                                        ?>
                                     </td>
                                    <td>
                                        <?php echo htmlspecialchars($sala['responsavel'] ?? '<span class="text-muted">Não definido</span>'); ?>
                                        <?php if ($sala['telefone_ramal']): ?>
                                            <br><small class="text-muted">Ramal: <?php echo $sala['telefone_ramal']; ?></small>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <?php 
                                        $recursos_lista = explode(',', $sala['recursos']);
                                        foreach ($recursos_lista as $recurso):
                                            if (trim($recurso)):
                                        ?>
                                            <span class="badge bg-secondary"><?php echo trim($recurso); ?></span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        if (empty($sala['recursos'])): 
                                        ?>
                                            <span class="text-muted">Nenhum</span>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <?php if ($sala['status']): ?>
                                            <span class="status-badge status-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inativo">Inativo</span>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <a href="?editar=1&id=<?php echo $sala['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?acao=excluir&id=<?php echo $sala['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir esta sala?')">
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
    
    <!-- Modal Adicionar/Editar Sala -->
    <div class="modal fade" id="modalSala" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-door-open"></i> Adicionar Sala</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formSala">
                    <input type="hidden" name="id" id="sala_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label required">Nome da Sala</label>
                                <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: Sala 01, Laboratório de Química, Auditório Central">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Capacidade</label>
                                <input type="number" name="capacidade" id="capacidade" class="form-control" value="30" min="1" max="500">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Sala</label>
                                <select name="tipo" id="tipo" class="form-control">
                                    <?php foreach ($tipos_sala as $valor => $label): ?>
                                    <option value="<?php echo $valor; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Código</label>
                                <input type="text" class="form-control" id="codigo_preview" readonly disabled style="background: #e9ecef; font-family: monospace;">
                                <small class="text-muted">Código gerado automaticamente</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Bloco</label>
                                <input type="text" name="bloco" id="bloco" class="form-control" placeholder="Ex: A, B, C, Central">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Andar</label>
                                <select name="andar" id="andar" class="form-control">
                                    <option value="">Selecione...</option>
                                    <option value="1">1º Andar</option>
                                    <option value="2">2º Andar</option>
                                    <option value="3">3º Andar</option>
                                    <option value="4">4º Andar</option>
                                    <option value="T">Térreo</option>
                                    <option value="S">Subsolo</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Localização</label>
                                <input type="text" name="localizacao" id="localizacao" class="form-control" placeholder="Ex: Ala Norte, Perto da secretaria">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Responsável</label>
                                <input type="text" name="responsavel" id="responsavel" class="form-control" placeholder="Nome do responsável pela sala">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefone / Ramal</label>
                                <input type="text" name="telefone_ramal" id="telefone_ramal" class="form-control" placeholder="Ex: 101, 943911384">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Recursos Disponíveis</label>
                            <input type="text" name="recursos" id="recursos" class="form-control" placeholder="Ex: Projetor, Ar condicionado, Computadores, Quadro interativo (separar por vírgula)">
                            <small class="text-muted">Separe os recursos por vírgula</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                <label class="form-check-label" for="status">
                                    Sala Ativa
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Sala</button>
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
            $('#formSala')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-door-open"></i> Adicionar Sala');
            $('#sala_id').val('');
            $('#codigo_preview').val('Será gerado automaticamente');
            $('#status').prop('checked', true);
            $('#tipo').val('comum');
            $('#capacidade').val('30');
        }
        
        <?php if ($sala_editar): ?>
        // Carregar dados para edição
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Sala');
            $('#acao').val('editar');
            $('#sala_id').val('<?php echo $sala_editar['id']; ?>');
            $('#nome').val('<?php echo addslashes($sala_editar['nome']); ?>');
            $('#tipo').val('<?php echo $sala_editar['tipo']; ?>');
            $('#capacidade').val('<?php echo $sala_editar['capacidade']; ?>');
            $('#localizacao').val('<?php echo addslashes($sala_editar['localizacao']); ?>');
            $('#bloco').val('<?php echo addslashes($sala_editar['bloco']); ?>');
            $('#andar').val('<?php echo $sala_editar['andar']; ?>');
            $('#recursos').val('<?php echo addslashes($sala_editar['recursos']); ?>');
            $('#responsavel').val('<?php echo addslashes($sala_editar['responsavel']); ?>');
            $('#telefone_ramal').val('<?php echo $sala_editar['telefone_ramal']; ?>');
            $('#status').prop('checked', <?php echo $sala_editar['status'] ? 'true' : 'false'; ?>);
            $('#codigo_preview').val('<?php echo $sala_editar['codigo']; ?>');
            
            $('#modalSala').modal('show');
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