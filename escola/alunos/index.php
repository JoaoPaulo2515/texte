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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Alunos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ==============================================
           DESIGN MODERNO - CARDS COM BORDAS ARREDONDADAS
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            margin-bottom: 45px;
            padding: 25px 30px;
            background: #f5f7fb;
            min-height: calc(100vh - 115px);
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 24px;
            padding: 18px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .top-bar:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
        }
        
        .top-bar h2 {
            font-size: 1.3em;
            font-weight: 700;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
        }
        
        /* Stats Grid - Cards Melhorados */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 22px 20px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .stat-card.alunos::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .stat-card.ativos::before { background: linear-gradient(90deg, #28a745, #20c997); }
        .stat-card.inativos::before { background: linear-gradient(90deg, #dc3545, #fd7e14); }
        .stat-card.genero::before { background: linear-gradient(90deg, #17a2b8, #6f42c1); }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 24px;
            transition: all 0.3s ease;
        }
        
        .stat-card.alunos .stat-icon { background: linear-gradient(135deg, #667eea20, #764ba220); color: #667eea; }
        .stat-card.ativos .stat-icon { background: linear-gradient(135deg, #28a74520, #20c99720); color: #28a745; }
        .stat-card.inativos .stat-icon { background: linear-gradient(135deg, #dc354520, #fd7e1420); color: #dc3545; }
        .stat-card.genero .stat-icon { background: linear-gradient(135deg, #17a2b820, #6f42c120); color: #17a2b8; }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.05) rotate(5deg);
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: 800;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 0.75em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        
        /* Cards de Filtro - Melhorados */
        .filter-card {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .filter-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.1);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px 22px;
            font-weight: 600;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            color: #2c3e50;
            font-size: 0.95em;
        }
        
        .card-body-custom {
            padding: 22px;
        }
        
        /* Tabela - Melhorada */
        .table-card {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 25px;
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.1);
        }
        
        .table-header-custom {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px 22px;
            font-weight: 600;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            color: #2c3e50;
        }
        
        /* Formulário */
        .form-control, .form-select {
            width: 100%;
            padding: 10px 16px;
            border: 2px solid #e9ecef;
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,107,62,0.1);
        }
        
        /* Botões */
        .btn-primary {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            border: none;
            border-radius: 14px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,107,62,0.3);
        }
        
        .btn-info {
            background: #17a2b8;
            border: none;
            border-radius: 12px;
            transition: all 0.3s;
            padding: 6px 12px;
        }
        
        .btn-info:hover {
            background: #138496;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23,162,184,0.3);
        }
        
        .btn-warning {
            background: #ffc107;
            border: none;
            border-radius: 12px;
            transition: all 0.3s;
            padding: 6px 12px;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255,193,7,0.3);
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
            border-radius: 12px;
            transition: all 0.3s;
            padding: 6px 12px;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220,53,69,0.3);
        }
        
        /* Tabela */
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background: #f8f9fa;
            padding: 15px;
            font-weight: 600;
            font-size: 0.8em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #006B3E;
            color: #2c3e50;
        }
        
        .table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Foto Perfil */
        .foto-perfil {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #006B3E;
            transition: all 0.3s;
        }
        
        .foto-perfil:hover {
            transform: scale(1.05);
            border-color: #FFD700;
        }
        
        .foto-placeholder {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2em;
            transition: all 0.3s;
        }
        
        .foto-placeholder:hover {
            transform: scale(1.05);
        }
        
        /* Badges */
        .badge-status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .status-ativo {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-turma {
            background: #e8f4f8;
            color: #006B3E;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .badge-turma:hover {
            background: #006B3E;
            color: white;
            transform: scale(1.05);
        }
        
        /* Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 18px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 1.2em;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s;
        }
        
        .menu-toggle:hover { transform: scale(1.05); }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #006B3E; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #004d2d; }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; border-radius: 0; }
            .main-content { margin-left: 0; margin-top: 70px; padding: 20px; }
            .menu-toggle { display: block; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
            .table thead th { font-size: 0.7em; padding: 10px; }
            .table tbody td { padding: 8px 10px; font-size: 0.85em; }
            .top-bar { flex-direction: column; gap: 15px; text-align: center; }
            .stat-icon { width: 45px; height: 45px; font-size: 20px; }
            .stat-value { font-size: 1.5em; }
        }
        
        @media (max-width: 480px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

<?php include '../menu_escola.php'; ?>

<div class="main-content">
    <div class="top-bar">
        <h2><i class="fas fa-users me-2" style="color: #006B3E;"></i> Alunos</h2>
        <a href="cadastrar.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Novo Aluno</a>
    </div>
    
    <!-- Cards de Estatísticas Melhorados -->
    <div class="stats-grid">
        <div class="stat-card alunos">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?php echo $total_alunos; ?></div>
            <div class="stat-label">Total de Alunos</div>
        </div>
        <div class="stat-card ativos">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-value"><?php echo $alunos_ativos; ?></div>
            <div class="stat-label">Alunos Ativos</div>
        </div>
        <div class="stat-card inativos">
            <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
            <div class="stat-value"><?php echo $alunos_inativos; ?></div>
            <div class="stat-label">Alunos Inativos</div>
        </div>
        <div class="stat-card genero">
            <div class="stat-icon"><i class="fas fa-venus-mars"></i></div>
            <div class="stat-value"><?php echo $generos['masculino']; ?> | <?php echo $generos['feminino']; ?></div>
            <div class="stat-label">Masculino | Feminino</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filter-card">
        <div class="card-header-custom">
            <i class="fas fa-filter me-2"></i> Filtros de Pesquisa
        </div>
        <div class="card-body-custom">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Buscar por nome, matrícula ou BI..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="turma" class="form-select">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filter == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Alunos -->
    <div class="table-card">
        <div class="table-header-custom">
            <i class="fas fa-list me-2"></i> Lista de Alunos
            <span class="badge bg-secondary ms-2"><?php echo count($alunos); ?> registros</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
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
                            <td class="text-center">
                                <?php if ($tem_foto): ?>
                                    <img src="<?php echo $foto_path; ?>" class="foto-perfil">
                                <?php else: ?>
                                    <div class="foto-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <td><strong><?php echo htmlspecialchars($aluno['matricula']); ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($aluno['email']); ?></small>
                             </div>
                            <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                            <td>
                                <?php if ($aluno['turma_nome']): ?>
                                    <span class="badge-turma"><?php echo htmlspecialchars($aluno['turma_nome']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Não matriculado</span>
                                <?php endif; ?>
                             </div>
                            <td><?php echo htmlspecialchars($aluno['telefone'] ?? '-'); ?></td>
                            <td>
                                <span class="badge-status status-<?php echo $aluno['usuario_status']; ?>">
                                    <?php echo ucfirst($aluno['usuario_status']); ?>
                                </span>
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
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($alunos)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">Nenhum aluno encontrado</p>
                                <a href="cadastrar.php" class="btn btn-primary btn-sm mt-3">
                                    <i class="fas fa-plus"></i> Cadastrar Aluno
                                </a>
                             </div>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Menu Toggle
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
</script>
</body>
</html>