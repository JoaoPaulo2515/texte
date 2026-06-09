<?php
// escola/alunos/index.php - Listagem de Alunos
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
$turma_id = $_GET['turma'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT e.*, u.nome, u.email, u.telefone, u.status as usuario_status,
           t.id as turma_id, t.nome as turma_nome, m.status as matricula_status,
           m.data_matricula
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE e.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($search) {
    $query .= " AND (u.nome LIKE :search OR e.matricula LIKE :search OR e.bi LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($turma_id) {
    $query .= " AND t.id = :turma_id";
    $params[':turma_id'] = $turma_id;
}
if ($status_filter) {
    $query .= " AND u.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY u.nome ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas para filtro
$turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$total_alunos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes e JOIN usuarios u ON u.id = e.usuario_id WHERE e.escola_id = :escola_id AND u.status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$alunos_ativos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes e JOIN usuarios u ON u.id = e.usuario_id WHERE e.escola_id = :escola_id AND u.status = 'inativo'");
$stmt->execute([':escola_id' => $escola_id]);
$alunos_inativos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Buscar total por género
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN genero = 'M' THEN 1 ELSE 0 END) as masculino,
        SUM(CASE WHEN genero = 'F' THEN 1 ELSE 0 END) as feminino
    FROM estudantes WHERE escola_id = :escola_id
");
$stmt->execute([':escola_id' => $escola_id]);
$generos = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alunos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        .nav-submenu.show { display: block; }
        .nav-submenu .nav-link { padding: 8px 25px; font-size: 0.9em; }
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
        
        /* Main Content */
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-ativo { background: #e8f5e9; color: #388e3c; }
        .status-inativo { background: #ffebee; color: #d32f2f; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 15px; padding: 15px; text-align: center; }
        .stat-value { font-size: 1.8em; font-weight: bold; color: #006B3E; }
        .foto-perfil { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .btn-sm { padding: 5px 10px; font-size: 0.8em; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users"></i> Alunos</h2>
            <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Novo Aluno</a>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_alunos; ?></div>
                <div>Total de Alunos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $alunos_ativos; ?></div>
                <div>Alunos Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $alunos_inativos; ?></div>
                <div>Alunos Inativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $generos['masculino']; ?> | <?php echo $generos['feminino']; ?></div>
                <div>M | F</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por nome, matrícula ou BI" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="turma" class="form-control">
                            <option value="">Todas as turmas</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo $status_filter == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Alunos -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Lista de Alunos
                <span class="badge bg-secondary ms-2"><?php echo count($alunos); ?> registros</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Foto</th>
                                <th>Matrícula</th>
                                <th>Nome</th>
                                <th>BI</th>
                                <th>Turma</th>
                                <th>Contacto</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $aluno): 
                                $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                                $tem_foto = $aluno['foto'] && file_exists($foto_path);
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
                                <td><strong><?php echo $aluno['matricula']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($aluno['email']); ?></small>
                                 </div>
                                </div>
                                <td><?php echo $aluno['bi'] ?? '-'; ?></td>
                                <td>
                                    <?php if ($aluno['turma_nome']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($aluno['turma_nome']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Não matriculado</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><?php echo $aluno['telefone'] ?? '-'; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $aluno['usuario_status']; ?>">
                                        <?php echo ucfirst($aluno['usuario_status']); ?>
                                    </span>
                                 </div>
                                </div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="visualizar.php?id=<?php echo $aluno['id']; ?>" class="btn btn-info" title="Visualizar">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar.php?id=<?php echo $aluno['id']; ?>" class="btn btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="excluir.php?id=<?php echo $aluno['id']; ?>" class="btn btn-danger" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este aluno?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                 </div>
                                 </div>
                                <td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($alunos)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                    Nenhum aluno encontrado
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('alunos')) {
            $('#menuAlunos').addClass('open');
            $('#submenuAlunos').addClass('show');
        }
        if (currentPage.includes('professores')) { $('#menuProfessores').addClass('open'); $('#submenuProfessores').addClass('show'); }
        if (currentPage.includes('turmas')) { $('#menuTurmas').addClass('open'); $('#submenuTurmas').addClass('show'); }
        if (currentPage.includes('disciplinas')) { $('#menuDisciplinas').addClass('open'); $('#submenuDisciplinas').addClass('show'); }
        if (currentPage.includes('notas')) { $('#menuNotas').addClass('open'); $('#submenuNotas').addClass('show'); }
        if (currentPage.includes('chamada')) { $('#menuChamada').addClass('open'); $('#submenuChamada').addClass('show'); }
        if (currentPage.includes('biblioteca')) { $('#menuBiblioteca').addClass('open'); $('#submenuBiblioteca').addClass('show'); }
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatoriosEscola').addClass('show'); }
    </script>
</body>
</html>