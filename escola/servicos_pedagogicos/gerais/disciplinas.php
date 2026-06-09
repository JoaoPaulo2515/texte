<?php
// escola/servicos_pedagogicos/gerais/disciplinas.php - Gestão de Disciplinas

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

function gerarCodigoDisciplina($conn, $escola_id, $nome) {
    // Remove acentos e caracteres especiais
    $nome_clean = preg_replace('/[^a-zA-Z0-9]/', '', $nome);
    $nome_clean = iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
    $nome_clean = preg_replace('/[^a-zA-Z0-9]/', '', $nome_clean);
    
    // Pega as 3 primeiras letras do nome
    $prefixo = strtoupper(substr($nome_clean, 0, 3));
    
    // Buscar o último código gerado para este prefixo
    $stmt = $conn->prepare("SELECT codigo FROM disciplinas WHERE escola_id = :escola_id AND codigo LIKE :prefixo ORDER BY id DESC LIMIT 1");
    $stmt->execute([':escola_id' => $escola_id, ':prefixo' => $prefixo . '%']);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo && preg_match('/(\d+)/', $ultimo['codigo'], $matches)) {
        $numero = (int)$matches[1] + 1;
    } else {
        $numero = 1;
    }
    
    return $prefixo . str_pad($numero, 3, '0', STR_PAD_LEFT);
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

// Adicionar nova disciplina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $nome = $_POST['nome'] ?? '';
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    $descricao = $_POST['descricao'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Gerar código automaticamente
    $codigo = gerarCodigoDisciplina($conn, $escola_id, $nome);
    
    if ($nome) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO disciplinas (escola_id, nome, codigo, carga_horaria, descricao, status, created_at)
                VALUES (:escola_id, :nome, :codigo, :carga_horaria, :descricao, :status, NOW())
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':codigo' => $codigo,
                ':carga_horaria' => $carga_horaria,
                ':descricao' => $descricao,
                ':status' => $status
            ]);
            $_SESSION['success'] = "Disciplina adicionada com sucesso! Código: " . $codigo;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao adicionar disciplina: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha o campo nome da disciplina.";
    }
    header('Location: disciplinas.php');
    exit;
}

// Editar disciplina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = $_POST['id'] ?? 0;
    $nome = $_POST['nome'] ?? '';
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    $descricao = $_POST['descricao'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    
    if ($id > 0 && $nome) {
        try {
            $stmt = $conn->prepare("
                UPDATE disciplinas SET 
                    nome = :nome,
                    carga_horaria = :carga_horaria,
                    descricao = :descricao,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':carga_horaria' => $carga_horaria,
                ':descricao' => $descricao,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $_SESSION['success'] = "Disciplina atualizada com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao atualizar disciplina: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Preencha os campos obrigatórios.";
    }
    header('Location: disciplinas.php');
    exit;
}

// Excluir disciplina
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM disciplinas WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['success'] = "Disciplina excluída com sucesso!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erro ao excluir disciplina: " . $e->getMessage();
    }
    header('Location: disciplinas.php');
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar todas as disciplinas da escola
$sql_disciplinas = "SELECT * FROM disciplinas WHERE escola_id = :escola_id ORDER BY nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplina específica para edição
$disciplina_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("SELECT * FROM disciplinas WHERE id = :id AND escola_id = :escola_id");
    $stmt_editar->execute([':id' => $id_editar, ':escola_id' => $escola_id]);
    $disciplina_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Disciplinas | Serviços Pedagógicos | SIGE Angola</title>
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
        .codigo-badge { background: #e9ecef; padding: 4px 10px; border-radius: 15px; font-family: monospace; font-size: 12px; font-weight: bold; }
        .disciplina-card { transition: transform 0.2s; }
        .disciplina-card:hover { transform: translateY(-3px); }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-book"></i> Gestão de Disciplinas</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDisciplina" onclick="resetForm()">
                <i class="fas fa-plus"></i> Nova Disciplina
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
                <h5 class="mb-0"><i class="fas fa-list"></i> Disciplinas Cadastradas</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Disciplina</th>
                                <th>Carga Horária</th>
                                <th>Descrição</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($disciplinas)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-book fa-2x mb-2 d-block"></i>
                                        Nenhuma disciplina cadastrada. Clique em "Nova Disciplina" para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($disciplinas as $disciplina): ?>
                                <tr>
                                    <td><span class="codigo-badge"><?php echo htmlspecialchars($disciplina['codigo']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong></td>
                                    <td><?php echo $disciplina['carga_horaria'] ? number_format($disciplina['carga_horaria'], 0) . ' h' : '-'; ?></td>
                                    <td>
                                        <?php 
                                        $descricao = htmlspecialchars($disciplina['descricao'] ?? '');
                                        echo strlen($descricao) > 60 ? substr($descricao, 0, 60) . '...' : ($descricao ?: '<span class="text-muted">Sem descrição</span>');
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($disciplina['status']): ?>
                                            <span class="status-badge status-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?editar=1&id=<?php echo $disciplina['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?acao=excluir&id=<?php echo $disciplina['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir esta disciplina?')">
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
    
    <!-- Modal Adicionar/Editar Disciplina -->
    <div class="modal fade" id="modalDisciplina" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-book"></i> Adicionar Disciplina</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formDisciplina">
                    <input type="hidden" name="id" id="disciplina_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label required">Nome da Disciplina</label>
                                <input type="text" name="nome" id="nome" class="form-control" required placeholder="Ex: Matemática, Português, Física, Química">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Código</label>
                                <input type="text" id="codigo_preview" class="form-control" readonly disabled style="background: #e9ecef; font-family: monospace;">
                                <small class="text-muted">Código gerado automaticamente</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Carga Horária (horas)</label>
                                <input type="number" name="carga_horaria" id="carga_horaria" class="form-control" min="0" step="1" value="60" placeholder="Ex: 60, 90, 120">
                                <small class="text-muted">Carga horária total da disciplina por ano letivo</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                    <label class="form-check-label" for="status">
                                        Disciplina Ativa
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição / Ementa</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="4" placeholder="Descreva a disciplina, conteúdos programáticos, objetivos, etc."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Disciplina</button>
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
        
        // Gerar código automaticamente enquanto digita o nome
        function gerarCodigoPreview() {
            var nome = $('#nome').val();
            if (nome) {
                // Remove acentos
                var nomeSemAcentos = nome.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                var codigo = nomeSemAcentos.substring(0, 3).toUpperCase();
                
                // Adicionar um número aleatório para garantir unicidade na pré-visualização
                var numeroAleatorio = Math.floor(Math.random() * 900) + 100;
                $('#codigo_preview').val(codigo + numeroAleatorio);
            } else {
                $('#codigo_preview').val('Será gerado automaticamente');
            }
        }
        
        $('#nome').on('keyup', gerarCodigoPreview);
        
        function resetForm() {
            $('#formDisciplina')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-book"></i> Adicionar Disciplina');
            $('#disciplina_id').val('');
            $('#status').prop('checked', true);
            $('#codigo_preview').val('Será gerado automaticamente');
            $('#carga_horaria').val('60');
        }
        
        <?php if ($disciplina_editar): ?>
        // Carregar dados para edição
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Disciplina');
            $('#acao').val('editar');
            $('#disciplina_id').val('<?php echo $disciplina_editar['id']; ?>');
            $('#nome').val('<?php echo addslashes($disciplina_editar['nome']); ?>');
            $('#codigo_preview').val('<?php echo $disciplina_editar['codigo']; ?>');
            $('#carga_horaria').val('<?php echo $disciplina_editar['carga_horaria']; ?>');
            $('#descricao').val('<?php echo addslashes($disciplina_editar['descricao']); ?>');
            $('#status').prop('checked', <?php echo $disciplina_editar['status'] ? 'true' : 'false'; ?>);
            
            $('#modalDisciplina').modal('show');
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