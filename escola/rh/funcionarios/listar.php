<?php
// escola/rh/funcionarios/listar.php - Listagem de Funcionários
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Parâmetros de filtro
$tipo = $_GET['tipo'] ?? '';
$status = $_GET['status'] ?? 'ativo';
$search = $_GET['search'] ?? '';

// Query base
$sql = "SELECT f.*, u.email as user_email 
        FROM funcionarios f 
        LEFT JOIN usuarios u ON f.usuario_id = u.id 
        WHERE f.escola_id = :escola_id";

$params = [':escola_id' => $escola_id];

if ($tipo) {
    $sql .= " AND f.tipo_funcionario = :tipo";
    $params[':tipo'] = $tipo;
}

if ($status) {
    $sql .= " AND f.status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $sql .= " AND (f.nome LIKE :search OR f.numero_processo LIKE :search OR f.bi LIKE :search OR f.telefone LIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY f.nome ASC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas para os cards
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND status = 'ativo'");
$stmt->execute([$escola_id]);
$total_ativos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND tipo_funcionario = 'professor' AND status = 'ativo'");
$stmt->execute([$escola_id]);
$total_professores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND tipo_funcionario = 'administrativo' AND status = 'ativo'");
$stmt->execute([$escola_id]);
$total_administrativos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$tipos_funcionario = ['professor', 'administrativo', 'auxiliar', 'seguranca', 'limpeza', 'manutencao', 'motorista', 'outro'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funcionários | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .sidebar-header { padding: 25px; text-align: center; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .stat-card { background: white; border-radius: 10px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .avatar-funcionario { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .badge-professor { background: #006B3E; }
        .badge-administrativo { background: #1A2A6C; }
        .badge-auxiliar { background: #17a2b8; }
        .badge-seguranca { background: #dc3545; }
    </style>
</head>
<body>
  
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users"></i> Gestão de Funcionários</h2>
            <a href="cadastrar.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Novo Funcionário</a>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_ativos; ?></div>
                    <div>Total Ativos</div>
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_professores; ?></div>
                    <div>Professores</div>
                    <i class="fas fa-chalkboard-user"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_administrativos; ?></div>
                    <div>Administrativos</div>
                    <i class="fas fa-building"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value">0</div>
                    <div>Férias Pendentes</div>
                    <i class="fas fa-umbrella-beach"></i>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label>Tipo de Funcionário</label>
                        <select name="tipo" class="form-control">
                            <option value="">Todos</option>
                            <?php foreach ($tipos_funcionario as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo $tipo == $t ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($t); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="ativo" <?php echo $status == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo $status == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                            <option value="ferias" <?php echo $status == 'ferias' ? 'selected' : ''; ?>>Férias</option>
                            <option value="licenca" <?php echo $status == 'licenca' ? 'selected' : ''; ?>>Licença</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>Pesquisar</label>
                        <input type="text" name="search" class="form-control" placeholder="Nome, Processo, BI, Telefone" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Funcionários -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Lista de Funcionários
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaFuncionarios">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Processo</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Cargo</th>
                                <th>Telefone</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($funcionarios as $f): ?>
                            <tr>
                                <td>
                                    <img src="../../../uploads/funcionarios/fotos/<?php echo $f['foto']; ?>" 
                                         class="avatar-funcionario" 
                                         onerror="this.src='../../../assets/images/avatar-padrao.png'">
                                </td>
                                <td><?php echo htmlspecialchars($f['numero_processo']); ?></td>
                                <td><?php echo htmlspecialchars($f['nome']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $f['tipo_funcionario']; ?>">
                                        <?php echo ucfirst($f['tipo_funcionario']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($f['cargo']); ?></td>
                                <td><?php echo htmlspecialchars($f['telefone']); ?></td>
                                <td>
                                    <?php
                                    $status_class = [
                                        'ativo' => 'success',
                                        'inativo' => 'danger',
                                        'ferias' => 'warning',
                                        'licenca' => 'info'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $status_class[$f['status']] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($f['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="visualizar.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-info" title="Visualizar">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="editar.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button class="btn btn-sm btn-danger" onclick="confirmarExclusao(<?php echo $f['id']; ?>)" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
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
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        $(document).ready(function() {
            $('#tabelaFuncionarios').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                },
                order: [[2, 'asc']],
                pageLength: 25
            });
        });
        
        function confirmarExclusao(id) {
            if (confirm('Tem certeza que deseja excluir este funcionário? Esta ação não pode ser desfeita.')) {
                window.location.href = 'excluir.php?id=' + id;
            }
        }
    </script>
</body>
</html>