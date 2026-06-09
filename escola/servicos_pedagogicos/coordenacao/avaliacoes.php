<?php
// escola/servicos_pedagogicos/coordenacao/avaliacoes.php - Avaliações Institucionais
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
// VERIFICAR E CRIAR TABELA
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'avaliacoes_institucionais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE avaliacoes_institucionais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            tipo ENUM('autoavaliacao', 'externa', 'pedagogica', 'administrativa', 'desempenho', 'satisfacao') DEFAULT 'pedagogica',
            data_inicio DATE,
            data_fim DATE,
            status ENUM('pendente', 'em_andamento', 'concluida', 'cancelada') DEFAULT 'pendente',
            resultados TEXT,
            recomendacoes TEXT,
            pontos_fortes TEXT,
            pontos_melhorar TEXT,
            responsavel VARCHAR(100),
            participantes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar avaliação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_avaliacao') {
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $tipo = $_POST['tipo'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $responsavel = $_POST['responsavel'];
    $participantes = $_POST['participantes'];
    
    $stmt = $conn->prepare("
        INSERT INTO avaliacoes_institucionais 
        (escola_id, titulo, descricao, tipo, data_inicio, data_fim, status, responsavel, participantes)
        VALUES (:escola_id, :titulo, :descricao, :tipo, :data_inicio, :data_fim, 'pendente', :responsavel, :participantes)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':tipo' => $tipo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':responsavel' => $responsavel,
        ':participantes' => $participantes
    ]);
    
    $_SESSION['mensagem'] = "Avaliação registada com sucesso!";
    header("Location: avaliacoes.php");
    exit;
}

// Editar avaliação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_avaliacao') {
    $id = $_POST['id'];
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $tipo = $_POST['tipo'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $responsavel = $_POST['responsavel'];
    $participantes = $_POST['participantes'];
    
    $stmt = $conn->prepare("
        UPDATE avaliacoes_institucionais 
        SET titulo = :titulo, descricao = :descricao, tipo = :tipo, 
            data_inicio = :data_inicio, data_fim = :data_fim, 
            responsavel = :responsavel, participantes = :participantes
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':tipo' => $tipo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':responsavel' => $responsavel,
        ':participantes' => $participantes
    ]);
    
    $_SESSION['mensagem'] = "Avaliação atualizada!";
    header("Location: avaliacoes.php");
    exit;
}

// Alterar status
if (isset($_GET['change_status']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'];
    
    $stmt = $conn->prepare("UPDATE avaliacoes_institucionais SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status da avaliação alterado para " . ucfirst(str_replace('_', ' ', $status));
    header("Location: avaliacoes.php");
    exit;
}

// Registrar resultados
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_resultados') {
    $id = $_POST['id'];
    $resultados = $_POST['resultados'];
    $recomendacoes = $_POST['recomendacoes'];
    $pontos_fortes = $_POST['pontos_fortes'];
    $pontos_melhorar = $_POST['pontos_melhorar'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("
        UPDATE avaliacoes_institucionais 
        SET resultados = :resultados, recomendacoes = :recomendacoes, 
            pontos_fortes = :pontos_fortes, pontos_melhorar = :pontos_melhorar,
            status = :status
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':resultados' => $resultados,
        ':recomendacoes' => $recomendacoes,
        ':pontos_fortes' => $pontos_fortes,
        ':pontos_melhorar' => $pontos_melhorar,
        ':status' => $status
    ]);
    
    $_SESSION['mensagem'] = "Resultados registados com sucesso!";
    header("Location: avaliacoes.php");
    exit;
}

// Excluir avaliação
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM avaliacoes_institucionais WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Avaliação excluída!";
    header("Location: avaliacoes.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? 'todas';
$tipo_filter = $_GET['tipo'] ?? '';

$sql = "SELECT * FROM avaliacoes_institucionais WHERE escola_id = :escola_id";
$params = [':escola_id' => $escola_id];

if ($status_filter != 'todas') {
    $sql .= " AND status = :status";
    $params[':status'] = $status_filter;
}
if ($tipo_filter) {
    $sql .= " AND tipo = :tipo";
    $params[':tipo'] = $tipo_filter;
}

$sql .= " ORDER BY data_inicio DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM avaliacoes_institucionais WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM avaliacoes_institucionais WHERE escola_id = :escola_id AND status = 'em_andamento'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['em_andamento'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM avaliacoes_institucionais WHERE escola_id = :escola_id AND status = 'concluida'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['concluidas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avaliações Institucionais | Coordenação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .badge-pendente { background: #ffc107; color: #000; }
        .badge-em_andamento { background: #17a2b8; color: white; }
        .badge-concluida { background: #28a745; color: white; }
        .badge-cancelada { background: #dc3545; color: white; }
        
        .table-responsive { overflow-x: auto; }
        .info-text { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; }
        
        .status-selector {
            display: inline-flex;
            gap: 5px;
        }
        .status-btn { padding: 4px 8px; font-size: 0.75em; border-radius: 15px; text-decoration: none; }
        .status-btn-pendente { background: #ffc107; color: #000; }
        .status-btn-andamento { background: #17a2b8; color: white; }
        .status-btn-concluida { background: #28a745; color: white; }
        .status-btn-cancelada { background: #dc3545; color: white; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Avaliações Institucionais</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaAvaliacao">
                <i class="fas fa-plus"></i> Nova Avaliação
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Avaliações</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['em_andamento']; ?></div><div class="stat-label">Em Andamento</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['concluidas']; ?></div><div class="stat-label">Concluídas</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <select name="status" class="form-control">
                            <option value="todas" <?php echo $status_filter == 'todas' ? 'selected' : ''; ?>>Todos os status</option>
                            <option value="pendente" <?php echo $status_filter == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="em_andamento" <?php echo $status_filter == 'em_andamento' ? 'selected' : ''; ?>>Em Andamento</option>
                            <option value="concluida" <?php echo $status_filter == 'concluida' ? 'selected' : ''; ?>>Concluída</option>
                            <option value="cancelada" <?php echo $status_filter == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="tipo" class="form-control">
                            <option value="">Todos os tipos</option>
                            <option value="autoavaliacao" <?php echo $tipo_filter == 'autoavaliacao' ? 'selected' : ''; ?>>Autoavaliação</option>
                            <option value="externa" <?php echo $tipo_filter == 'externa' ? 'selected' : ''; ?>>Avaliação Externa</option>
                            <option value="pedagogica" <?php echo $tipo_filter == 'pedagogica' ? 'selected' : ''; ?>>Avaliação Pedagógica</option>
                            <option value="administrativa" <?php echo $tipo_filter == 'administrativa' ? 'selected' : ''; ?>>Avaliação Administrativa</option>
                            <option value="desempenho" <?php echo $tipo_filter == 'desempenho' ? 'selected' : ''; ?>>Avaliação de Desempenho</option>
                            <option value="satisfacao" <?php echo $tipo_filter == 'satisfacao' ? 'selected' : ''; ?>>Pesquisa de Satisfação</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Avaliações -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Avaliações</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaAvaliacoes">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Título</th>
                                <th>Tipo</th>
                                <th>Período</th>
                                <th>Responsável</th>
                                <th>Participantes</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($avaliacoes as $av): ?>
                            <tr>
                                <td><?php echo $av['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($av['titulo']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($av['descricao'], 0, 50)); ?>...</small>
                                </td>
                                <td>
                                    <?php
                                    $tipos = [
                                        'autoavaliacao' => 'Autoavaliação',
                                        'externa' => 'Avaliação Externa',
                                        'pedagogica' => 'Avaliação Pedagógica',
                                        'administrativa' => 'Avaliação Administrativa',
                                        'desempenho' => 'Avaliação de Desempenho',
                                        'satisfacao' => 'Pesquisa de Satisfação'
                                    ];
                                    echo $tipos[$av['tipo']] ?? $av['tipo'];
                                    ?>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($av['data_inicio'])); ?><br>
                                    <small>até <?php echo date('d/m/Y', strtotime($av['data_fim'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($av['responsavel']); ?></td>
                                <td><?php echo htmlspecialchars($av['participantes']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo str_replace('_', '', $av['status']); ?>">
                                        <?php echo str_replace('_', ' ', ucfirst($av['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verAvaliacao(<?php echo $av['id']; ?>, '<?php echo addslashes($av['titulo']); ?>', '<?php echo addslashes($av['descricao']); ?>', '<?php echo $av['tipo']; ?>', '<?php echo $av['data_inicio']; ?>', '<?php echo $av['data_fim']; ?>', '<?php echo addslashes($av['responsavel']); ?>', '<?php echo addslashes($av['participantes']); ?>', '<?php echo addslashes($av['resultados']); ?>', '<?php echo addslashes($av['recomendacoes']); ?>', '<?php echo addslashes($av['pontos_fortes']); ?>', '<?php echo addslashes($av['pontos_melhorar']); ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-warning" onclick="editarAvaliacao(<?php echo $av['id']; ?>, '<?php echo addslashes($av['titulo']); ?>', '<?php echo addslashes($av['descricao']); ?>', '<?php echo $av['tipo']; ?>', '<?php echo $av['data_inicio']; ?>', '<?php echo $av['data_fim']; ?>', '<?php echo addslashes($av['responsavel']); ?>', '<?php echo addslashes($av['participantes']); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($av['status'] != 'concluida' && $av['status'] != 'cancelada'): ?>
                                        <button class="btn btn-success" onclick="registrarResultados(<?php echo $av['id']; ?>, '<?php echo addslashes($av['titulo']); ?>')">
                                            <i class="fas fa-file-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                <i class="fas fa-tasks"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="?change_status=1&id=<?php echo $av['id']; ?>&status=pendente">📋 Pendente</a></li>
                                                <li><a class="dropdown-item" href="?change_status=1&id=<?php echo $av['id']; ?>&status=em_andamento">🔄 Em Andamento</a></li>
                                                <li><a class="dropdown-item" href="?change_status=1&id=<?php echo $av['id']; ?>&status=concluida">✅ Concluída</a></li>
                                                <li><a class="dropdown-item" href="?change_status=1&id=<?php echo $av['id']; ?>&status=cancelada">❌ Cancelada</a></li>
                                            </ul>
                                        </div>
                                        <a href="?delete=1&id=<?php echo $av['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja excluir esta avaliação?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($avaliacoes)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                    Nenhuma avaliação encontrada
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Avaliação -->
    <div class="modal fade" id="modalNovaAvaliacao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Avaliação Institucional</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_avaliacao">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título da Avaliação</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva os objetivos e escopo da avaliação..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Tipo de Avaliação</label>
                                <select name="tipo" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <option value="autoavaliacao">Autoavaliação Institucional</option>
                                    <option value="externa">Avaliação Externa</option>
                                    <option value="pedagogica">Avaliação Pedagógica</option>
                                    <option value="administrativa">Avaliação Administrativa</option>
                                    <option value="desempenho">Avaliação de Desempenho</option>
                                    <option value="satisfacao">Pesquisa de Satisfação</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Responsável</label>
                                <input type="text" name="responsavel" class="form-control" placeholder="Nome do responsável">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Data de Início</label>
                                <input type="date" name="data_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Data de Término</label>
                                <input type="date" name="data_fim" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Participantes / Público-alvo</label>
                            <input type="text" name="participantes" class="form-control" placeholder="Ex: Todos os professores, Coordenadores, Alunos do Ensino Médio">
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Após criar a avaliação, você poderá registrar os resultados e recomendações.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Avaliação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Avaliação -->
    <div class="modal fade" id="modalEditarAvaliacao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Avaliação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_avaliacao">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título da Avaliação</label>
                            <input type="text" name="titulo" id="edit_titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" id="edit_descricao" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Tipo de Avaliação</label>
                                <select name="tipo" id="edit_tipo" class="form-control">
                                    <option value="autoavaliacao">Autoavaliação Institucional</option>
                                    <option value="externa">Avaliação Externa</option>
                                    <option value="pedagogica">Avaliação Pedagógica</option>
                                    <option value="administrativa">Avaliação Administrativa</option>
                                    <option value="desempenho">Avaliação de Desempenho</option>
                                    <option value="satisfacao">Pesquisa de Satisfação</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Responsável</label>
                                <input type="text" name="responsavel" id="edit_responsavel" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Data de Início</label>
                                <input type="date" name="data_inicio" id="edit_data_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Data de Término</label>
                                <input type="date" name="data_fim" id="edit_data_fim" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Participantes</label>
                            <input type="text" name="participantes" id="edit_participantes" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Avaliação -->
    <div class="modal fade" id="modalVerAvaliacao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-chart-line"></i> Detalhes da Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="verAvaliacaoContent"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Resultados -->
    <div class="modal fade" id="modalRegistrarResultados" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Registrar Resultados da Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_resultados">
                    <input type="hidden" name="id" id="resultados_id">
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <strong>📋 Avaliação:</strong> <span id="resultados_titulo"></span>
                        </div>
                        <div class="mb-3">
                            <label>Resultados / Conclusões</label>
                            <textarea name="resultados" class="form-control" rows="4" placeholder="Descreva os principais resultados obtidos na avaliação..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Pontos Fortes</label>
                                <textarea name="pontos_fortes" class="form-control" rows="3" placeholder="O que funcionou bem?"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Pontos a Melhorar</label>
                                <textarea name="pontos_melhorar" class="form-control" rows="3" placeholder="O que precisa ser melhorado?"></textarea>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Recomendações</label>
                            <textarea name="recomendacoes" class="form-control" rows="3" placeholder="Recomendações para ações futuras..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Status após conclusão</label>
                            <select name="status" class="form-control">
                                <option value="concluida">Concluída</option>
                                <option value="cancelada">Cancelada</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar Resultados</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
        
        $('#tabelaAvaliacoes').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        function editarAvaliacao(id, titulo, descricao, tipo, data_inicio, data_fim, responsavel, participantes) {
            $('#edit_id').val(id);
            $('#edit_titulo').val(titulo);
            $('#edit_descricao').val(descricao);
            $('#edit_tipo').val(tipo);
            $('#edit_data_inicio').val(data_inicio);
            $('#edit_data_fim').val(data_fim);
            $('#edit_responsavel').val(responsavel);
            $('#edit_participantes').val(participantes);
            $('#modalEditarAvaliacao').modal('show');
        }
        
        function verAvaliacao(id, titulo, descricao, tipo, data_inicio, data_fim, responsavel, participantes, resultados, recomendacoes, pontos_fortes, pontos_melhorar) {
            const tipos = {
                'autoavaliacao': 'Autoavaliação Institucional',
                'externa': 'Avaliação Externa',
                'pedagogica': 'Avaliação Pedagógica',
                'administrativa': 'Avaliação Administrativa',
                'desempenho': 'Avaliação de Desempenho',
                'satisfacao': 'Pesquisa de Satisfação'
            };
            
            let html = `
                <div class="row">
                    <div class="col-md-12">
                        <div class="info-text">
                            <h5>${titulo}</h5>
                            <p><strong>Descrição:</strong> ${descricao || 'Não informada'}</p>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>📋 Tipo:</strong> ${tipos[tipo] || tipo}</p>
                                    <p><strong>👤 Responsável:</strong> ${responsavel || 'Não informado'}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>📅 Período:</strong> ${new Date(data_inicio).toLocaleDateString()} até ${new Date(data_fim).toLocaleDateString()}</p>
                                    <p><strong>👥 Participantes:</strong> ${participantes || 'Não informado'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            if (resultados) {
                html += `
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-success text-white">📊 Resultados</div>
                                <div class="card-body">
                                    <p>${resultados.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            if (pontos_fortes || pontos_melhorar) {
                html += `
                    <div class="row mt-3">
                        ${pontos_fortes ? `
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-info text-white">✅ Pontos Fortes</div>
                                <div class="card-body">
                                    <p>${pontos_fortes.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                        ${pontos_melhorar ? `
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-warning">⚠️ Pontos a Melhorar</div>
                                <div class="card-body">
                                    <p>${pontos_melhorar.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;
            }
            
            if (recomendacoes) {
                html += `
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-primary text-white">📌 Recomendações</div>
                                <div class="card-body">
                                    <p>${recomendacoes.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            $('#verAvaliacaoContent').html(html);
            $('#modalVerAvaliacao').modal('show');
        }
        
        function registrarResultados(id, titulo) {
            $('#resultados_id').val(id);
            $('#resultados_titulo').text(titulo);
            $('#modalRegistrarResultados').modal('show');
        }
    </script>
</body>
</html>