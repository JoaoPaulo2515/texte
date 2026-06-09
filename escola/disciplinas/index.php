<?php
// escola/disciplinas/index.php - Listagem de Disciplinas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT d.*, 
           (SELECT COUNT(DISTINCT professor_id) FROM alocacoes WHERE disciplina_id = d.id) as total_professores,
           (SELECT COUNT(DISTINCT turma_id) FROM alocacoes WHERE disciplina_id = d.id AND turma_id IS NOT NULL) as total_turmas
    FROM disciplinas d
    WHERE d.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($search) {
    $query .= " AND (d.nome LIKE :search OR d.codigo LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($status_filter) {
    $query .= " AND d.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY d.nome ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$disciplinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disciplinas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .disciplina-card { transition: transform 0.3s; cursor: pointer; }
        .disciplina-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-book"></i> Disciplinas</h2>
            <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova Disciplina</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filtros
                <form method="GET" class="d-inline-flex gap-2 ms-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Nome ou Código" value="<?php echo htmlspecialchars($search); ?>" style="width: 250px;">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">Todos os status</option>
                        <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                        <option value="inativa" <?php echo $status_filter == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
            </div>
        </div>
        
        <div class="row">
            <?php foreach ($disciplinas as $disc): ?>
            <div class="col-md-4 mb-4">
                <div class="card disciplina-card" onclick="location.href='editar.php?id=<?php echo $disc['id']; ?>'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h5 class="card-title"><?php echo htmlspecialchars($disc['nome']); ?></h5>
                            <span class="status-badge status-<?php echo $disc['status']; ?>"><?php echo ucfirst($disc['status']); ?></span>
                        </div>
                        <?php if ($disc['codigo']): ?>
                        <p class="text-muted small">Código: <?php echo htmlspecialchars($disc['codigo']); ?></p>
                        <?php endif; ?>
                        <p class="card-text small"><?php echo htmlspecialchars(substr($disc['descricao'] ?? '', 0, 100)); ?></p>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <h6><?php echo $disc['carga_horaria'] ?? '-'; ?></h6>
                                <small>h/semana</small>
                            </div>
                            <div class="col-3">
                                <h6><?php echo $disc['total_professores']; ?></h6>
                                <small>Professores</small>
                            </div>
                            <div class="col-3">
                                <h6><?php echo $disc['total_turmas']; ?></h6>
                                <small>Turmas</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent text-center">
                        <a href="editar.php?id=<?php echo $disc['id']; ?>" class="btn btn-sm btn-warning" onclick="event.stopPropagation()"><i class="fas fa-edit"></i> Editar</a>
                        <a href="excluir.php?id=<?php echo $disc['id']; ?>" class="btn btn-sm btn-danger" onclick="event.stopPropagation(); return confirm('Tem certeza?')"><i class="fas fa-trash"></i> Excluir</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($disciplinas)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                    Nenhuma disciplina cadastrada.<br>
                    <a href="cadastrar.php" class="btn btn-primary mt-2">Cadastrar Primeira Disciplina</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>