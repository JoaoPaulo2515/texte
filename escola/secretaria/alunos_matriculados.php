<?php
// escola/secretaria/alunos_matriculados.php - Alunos com Matrícula Ativa
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y') . '/' . (date('Y') + 1);

$search = $_GET['search'] ?? '';
$turma_id = $_GET['turma'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Query para alunos matriculados
$query = "
    SELECT e.*, u.nome, u.email, u.telefone,
           t.id as turma_id, t.nome as turma_nome,
           m.data_matricula, m.numero_processo
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    INNER JOIN matriculas m ON m.estudante_id = e.id
    INNER JOIN turmas t ON t.id = m.turma_id
    WHERE e.escola_id = :escola_id 
        AND m.ano_letivo = :ano_letivo 
        AND m.status = 'ativa'
";

$params = [':escola_id' => $escola_id, ':ano_letivo' => $ano_letivo];

if ($search) {
    $query .= " AND (u.nome LIKE :search OR e.matricula LIKE :search OR e.bi LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($turma_id) {
    $query .= " AND t.id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

$query .= " ORDER BY u.nome ASC LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);

foreach ($params as $key => $value) {
    if ($key == ':limit' || $key == ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total de registros
$count_query = "
    SELECT COUNT(*) as total 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    INNER JOIN matriculas m ON m.estudante_id = e.id
    WHERE e.escola_id = :escola_id AND m.ano_letivo = :ano_letivo AND m.status = 'ativa'
";

$count_params = [':escola_id' => $escola_id, ':ano_letivo' => $ano_letivo];

if ($search) {
    $count_query .= " AND (u.nome LIKE :search OR e.matricula LIKE :search OR e.bi LIKE :search)";
    $count_params[':search'] = "%{$search}%";
}
if ($turma_id) {
    $count_query .= " AND m.turma_id = :turma_id";
    $count_params[':turma_id'] = $turma_id;
}

$stmt_count = $conn->prepare($count_query);
foreach ($count_params as $key => $value) {
    $stmt_count->bindValue($key, $value);
}
$stmt_count->execute();
$total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total / $limit);

// Buscar turmas para filtro
$turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas por turma
$stats_turmas = [];
$stmt_stats = $conn->prepare("
    SELECT t.nome, COUNT(m.id) as total
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.ano_letivo = :ano_letivo AND m.status = 'ativa'
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    GROUP BY t.id
    ORDER BY t.nome
");
$stmt_stats->execute([':escola_id' => $escola_id, ':ano_letivo' => $ano_letivo]);
$stats_turmas = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Alunos Matriculados | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ==============================================
           DESIGN MODERNO - IGUAL AOS OUTROS
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
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
            border-radius: 20px;
            padding: 18px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .top-bar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
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
        
        .badge-ano-letivo {
            background: linear-gradient(135deg, #28a745, #20c997);
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
        }
        
        /* Stats Mini Cards */
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-mini-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .stat-mini-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 30px -12px rgba(0,0,0,0.15);
        }
        
        .stat-mini-value {
            font-size: 2em;
            font-weight: 800;
            color: #006B3E;
        }
        
        .stat-mini-label {
            font-size: 0.75em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: white;
            padding: 18px 25px;
            font-weight: 700;
            font-size: 1em;
            border-bottom: 1px solid #e9ecef;
            color: #2c3e50;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .card-footer {
            background: white;
            border-top: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        
        /* Formulário */
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        
        /* Botões */
        .btn-primary {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
        }
        
        .btn-info {
            background: #17a2b8;
            border: none;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-info:hover {
            background: #138496;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: #ffc107;
            border: none;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 0.75em;
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
        
        /* Badges */
        .badge-turma {
            background: #e8f4f8;
            color: #006B3E;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .badge-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 500;
        }
        
        /* Foto Perfil */
        .foto-perfil {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #006B3E;
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
        }
        
        /* Botões de Ação */
        .btn-group-sm .btn {
            margin: 0 2px;
        }
        
        /* Paginação */
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            border-radius: 10px;
            margin: 0 3px;
            color: #006B3E;
            border: 1px solid #e9ecef;
        }
        
        .page-link:hover {
            background-color: #006B3E;
            color: white;
            border-color: #006B3E;
        }
        
        .page-item.active .page-link {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            border-color: #006B3E;
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
        
        /* Responsivo */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
            .menu-toggle { display: block; }
            .top-bar { flex-direction: column; text-align: center; }
            .stats-mini { grid-template-columns: repeat(2, 1fr); gap: 15px; }
            .table thead th { font-size: 0.7em; padding: 10px; }
            .table tbody td { padding: 8px 10px; font-size: 0.85em; }
            .btn-group-sm .btn { padding: 4px 8px; }
        }
        
        @media (max-width: 480px) {
            .main-content { padding: 15px; }
            .stats-mini { grid-template-columns: 1fr 1fr; }
            .stat-mini-value { font-size: 1.5em; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

<?php include '../menu_escola.php'; ?>

<div class="main-content">
    <div class="top-bar">
        <h2><i class="fas fa-check-circle text-success"></i> Alunos Matriculados</h2>
        <div>
            <span class="badge-ano-letivo"><i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $ano_letivo; ?></span>
            <a href="matricula.php" class="btn btn-primary btn-sm ms-2"><i class="fas fa-plus"></i> Nova Matrícula</a>
        </div>
    </div>
    
    <!-- Estatísticas por Turma -->
    <div class="stats-mini">
        <div class="stat-mini-card">
            <div class="stat-mini-value"><?php echo $total; ?></div>
            <div class="stat-mini-label">Total Matriculados</div>
        </div>
        <?php foreach ($stats_turmas as $stat): ?>
        <div class="stat-mini-card">
            <div class="stat-mini-value"><?php echo $stat['total']; ?></div>
            <div class="stat-mini-label"><?php echo htmlspecialchars($stat['nome']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Filtros -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter"></i> Filtros de Pesquisa
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control" placeholder="Buscar por nome, matrícula ou BI..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select name="turma" class="form-select">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                        <?php endforeach; ?>
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
    
    <!-- Lista de Alunos Matriculados -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-users"></i> Alunos com Matrícula Ativa
            <span class="badge bg-secondary ms-2"><?php echo $total; ?> registros</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Foto</th>
                            <th>Matrícula</th>
                            <th>Nome</th>
                            <th>BI</th>
                            <th>Turma</th>
                            <th>Data Matrícula</th>
                            <th>Nº Processo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $count = $offset + 1; ?>
                        <?php foreach ($alunos as $aluno): 
                            $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                            $tem_foto = !empty($aluno['foto']) && file_exists($foto_path);
                        ?>
                            <tr>
                                <td><?php echo $count++; ?></td>
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
                                <td><span class="badge-turma"><?php echo htmlspecialchars($aluno['turma_nome']); ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($aluno['data_matricula'])); ?></td>
                                <td><?php echo htmlspecialchars($aluno['numero_processo']); ?></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="visualizar_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-info" title="Visualizar">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="rematricula.php?aluno_id=<?php echo $aluno['id']; ?>" class="btn btn-warning" title="Rematricular">
                                            <i class="fas fa-sync-alt"></i>
                                        </a>
                                        <a href="../alunos/emitir_comprovativo.php?id=<?php echo $aluno['id']; ?>" class="btn btn-secondary" title="Emitir Comprovativo">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </div>
                                 </div>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($alunos)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">Nenhum aluno matriculado no ano letivo <?php echo $ano_letivo; ?></p>
                                    <a href="matricula.php" class="btn btn-primary btn-sm mt-3">
                                        <i class="fas fa-plus"></i> Realizar Matrícula
                                    </a>
                                 </div>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                 </div>
            </div>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&turma=<?php echo urlencode($turma_id); ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&turma=<?php echo urlencode($turma_id); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&turma=<?php echo urlencode($turma_id); ?>">
                                Próxima <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>