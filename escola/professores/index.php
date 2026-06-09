<?php
// escola/professores/index.php - Listagem de Professores
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
    SELECT p.*, u.nome, u.email, u.telefone, u.status as usuario_status,
           GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN alocacoes a ON a.professor_id = p.id
    LEFT JOIN disciplinas d ON d.id = a.disciplina_id
    WHERE p.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($search) {
    $query .= " AND (u.nome LIKE :search OR p.bi LIKE :search OR p.especialidade LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($status_filter) {
    $query .= " AND u.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " GROUP BY p.id ORDER BY u.nome ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$professores = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professores | SIGE Angola</title>
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
        .status-ativo { background: #e8f5e9; color: #388e3c; }
        .status-inativo { background: #ffebee; color: #d32f2f; }
        .foto-perfil { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chalkboard-user"></i> Professores</h2>
            <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Novo Professor</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filtros
                <form method="GET" class="d-inline-flex gap-2 ms-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Nome, BI ou Especialidade" value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filter == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Foto</th><th>Nome</th><th>BI</th><th>Especialidade</th><th>Disciplinas</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professores as $prof): 
                                $foto_path = '../../uploads/professores/fotos/' . $prof['foto'];
                                $tem_foto = $prof['foto'] && file_exists($foto_path);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($tem_foto): ?>
                                        <img src="<?php echo $foto_path; ?>" class="foto-perfil">
                                    <?php else: ?>
                                        <div class="foto-perfil bg-secondary d-flex align-items-center justify-content-center text-white">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                 </div>
                                </td>
                                <td><strong><?php echo htmlspecialchars($prof['nome']); ?></strong><br><small><?php echo htmlspecialchars($prof['email']); ?></small></td>
                                <td><?php echo $prof['bi'] ?? '-'; ?></td>
                                <td><?php echo htmlspecialchars($prof['especialidade'] ?? '-'); ?></td>
                                <td><small><?php echo htmlspecialchars($prof['disciplinas'] ?? '-'); ?></small></td>
                                <td><span class="status-badge status-<?php echo $prof['usuario_status']; ?>"><?php echo ucfirst($prof['usuario_status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="visualizar.php?id=<?php echo $prof['id']; ?>" class="btn btn-info"><i class="fas fa-eye"></i></a>
                                        <a href="editar.php?id=<?php echo $prof['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i></a>
                                        <a href="excluir.php?id=<?php echo $prof['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                 </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>