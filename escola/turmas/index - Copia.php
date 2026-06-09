<?php
// escola/turmas/index.php - Listagem de Turmas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$ano_letivo = $_GET['ano'] ?? date('Y');
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT t.*, 
           (SELECT COUNT(*) FROM matriculas WHERE turma_id = t.id AND status = 'ativa') as total_alunos,
           (SELECT COUNT(DISTINCT professor_id) FROM alocacoes WHERE turma_id = t.id) as total_professores,
           (SELECT COUNT(DISTINCT disciplina_id) FROM alocacoes WHERE turma_id = t.id) as total_disciplinas
    FROM turmas t
    WHERE t.escola_id = :escola_id AND t.ano_letivo = :ano
";

$params = [':escola_id' => $escola_id, ':ano' => $ano_letivo];

if ($status_filter) {
    $query .= " AND t.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY t.nome ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Turnos para exibição
$turnos = [
    'manha' => 'Manhã',
    'tarde' => 'Tarde',
    'noite' => 'Noite'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turmas | SIGE Angola</title>
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
        .status-encerrada { background: #ffebee; color: #d32f2f; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .turma-card { transition: transform 0.3s; cursor: pointer; height: 100%; }
        .turma-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../alunos/" class="nav-link"><i class="fas fa-users"></i> Alunos</a></li>
            <li class="nav-item"><a href="../professores/" class="nav-link"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-users-group"></i> Turmas</a></li>
            <li class="nav-item"><a href="../disciplinas/" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a></li>
            <li class="nav-item"><a href="../notas/" class="nav-link"><i class="fas fa-graduation-cap"></i> Notas</a></li>
            <li class="nav-item"><a href="../chamada/" class="nav-link"><i class="fas fa-calendar-check"></i> Chamada</a></li>
            <li class="nav-item"><a href="../biblioteca/" class="nav-link"><i class="fas fa-book-open"></i> Biblioteca</a></li>
            <li class="nav-item"><a href="../relatorios/" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users-group"></i> Turmas</h2>
            <div>
                <select name="ano" class="form-control form-control-sm d-inline-block w-auto" onchange="location.href='?ano='+this.value">
                    <?php for ($i = 2024; $i <= 2030; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $ano_letivo == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <select name="status" class="form-control form-control-sm d-inline-block w-auto ms-2" onchange="location.href='?ano=<?php echo $ano_letivo; ?>&status='+this.value">
                    <option value="">Todos</option>
                    <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativas</option>
                    <option value="encerrada" <?php echo $status_filter == 'encerrada' ? 'selected' : ''; ?>>Encerradas</option>
                </select>
                <a href="cadastrar.php" class="btn btn-primary btn-sm ms-2"><i class="fas fa-plus"></i> Nova Turma</a>
            </div>
        </div>
        
        <div class="row">
            <?php foreach ($turmas as $turma): ?>
            <div class="col-md-4 mb-4">
                <div class="card turma-card" onclick="location.href='visualizar.php?id=<?php echo $turma['id']; ?>'">
                    <div class="card-body text-center">
                        <i class="fas fa-users-group fa-3x text-success mb-3"></i>
                        <h4><?php echo htmlspecialchars($turma['nome']); ?></h4>
                        <p class="text-muted">
                            <?php echo $turma['ano']; ?> - <?php echo $turnos[$turma['turno']] ?? ucfirst($turma['turno']); ?>
                        </p>
                        <?php if ($turma['sala']): ?>
                        <p><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($turma['sala']); ?></p>
                        <?php endif; ?>
                        <hr>
                        <div class="row">
                            <div class="col-4">
                                <h5><?php echo $turma['total_alunos']; ?></h5>
                                <small>Alunos</small>
                            </div>
                            <div class="col-4">
                                <h5><?php echo $turma['total_professores']; ?></h5>
                                <small>Professores</small>
                            </div>
                            <div class="col-4">
                                <h5><?php echo $turma['total_disciplinas']; ?></h5>
                                <small>Disciplinas</small>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="status-badge status-<?php echo $turma['status']; ?>">
                                <?php echo ucfirst($turma['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent text-center">
                        <a href="editar.php?id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-warning" onclick="event.stopPropagation()"><i class="fas fa-edit"></i></a>
                        <a href="excluir.php?id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-danger" onclick="event.stopPropagation(); return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                        <a href="../alunos/index.php?turma=<?php echo $turma['id']; ?>" class="btn btn-sm btn-info" onclick="event.stopPropagation()"><i class="fas fa-users"></i> Alunos</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($turmas)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                    Nenhuma turma encontrada para o ano letivo <?php echo $ano_letivo; ?>.<br>
                    <a href="cadastrar.php" class="btn btn-primary mt-2">Cadastrar Primeira Turma</a>
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