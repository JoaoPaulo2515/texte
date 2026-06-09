<?php
// escola/professor/dashboard.php - Dashboard do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// VERIFICAR TABELAS EXISTENTES
// ============================================

// Verificar se a tabela professor_disciplina_turma existe
try {
    $check = $conn->query("SHOW TABLES LIKE 'professor_disciplina_turma'");
    $tem_tabela_pdt = $check->rowCount() > 0;
} catch (PDOException $e) {
    $tem_tabela_pdt = false;
}

// ============================================
// ESTATÍSTICAS CORRIGIDAS
// ============================================

if ($tem_tabela_pdt) {
    // Usando professor_disciplina_turma
    $sql_stats = "
        SELECT 
            (SELECT COUNT(DISTINCT pdt.turma_id) 
             FROM professor_disciplina_turma pdt 
             WHERE pdt.professor_id = :professor_id) AS total_turmas,
            
            (SELECT COUNT(DISTINCT m.estudante_id) 
             FROM professor_disciplina_turma pdt
             INNER JOIN matriculas m ON m.turma_id = pdt.turma_id AND m.status = 'ativa'
             WHERE pdt.professor_id = :professor_id) AS total_alunos,
            
            (SELECT COUNT(DISTINCT pdt.disciplina_id) 
             FROM professor_disciplina_turma pdt 
             WHERE pdt.professor_id = :professor_id) AS total_disciplinas,
            
            (SELECT COUNT(*) FROM chamada c 
             WHERE c.professor_id = :professor_id AND c.data_aula = CURDATE()) AS chamada_hoje
    ";
} else {
    // Fallback usando horarios
    $sql_stats = "
        SELECT 
            (SELECT COUNT(DISTINCT turma_id) 
             FROM horarios 
             WHERE professor_id = :professor_id) AS total_turmas,
            
            (SELECT COUNT(DISTINCT m.estudante_id) 
             FROM horarios h
             INNER JOIN matriculas m ON m.turma_id = h.turma_id AND m.status = 'ativa'
             WHERE h.professor_id = :professor_id) AS total_alunos,
            
            (SELECT COUNT(DISTINCT disciplina_id) 
             FROM horarios 
             WHERE professor_id = :professor_id) AS total_disciplinas,
            
            (SELECT COUNT(*) FROM chamada c 
             WHERE c.professor_id = :professor_id AND c.data_aula = CURDATE()) AS chamada_hoje
    ";
}

$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':professor_id' => $professor_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

if (!$stats) {
    $stats = [
        'total_turmas' => 0,
        'total_alunos' => 0,
        'total_disciplinas' => 0,
        'chamada_hoje' => 0
    ];
}

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================

$turmas_professor = [];
if ($tem_tabela_pdt) {
    $sql_turmas = "
        SELECT DISTINCT 
            t.id, t.nome, t.ano, t.turno, t.sala
        FROM professor_disciplina_turma pdt
        INNER JOIN turmas t ON t.id = pdt.turma_id
        WHERE pdt.professor_id = :professor_id
        ORDER BY t.ano, t.nome
    ";
} else {
    $sql_turmas = "
        SELECT DISTINCT 
            t.id, t.nome, t.ano, t.turno, t.sala
        FROM horarios h
        INNER JOIN turmas t ON t.id = h.turma_id
        WHERE h.professor_id = :professor_id
        ORDER BY t.ano, t.nome
    ";
}

$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas_professor = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PRÓXIMAS ATIVIDADES
// ============================================
$atividades = [];
try {
    $sql_atividades = "
        SELECT a.*, d.nome as disciplina_nome, t.nome as turma_nome
        FROM atividades a
        INNER JOIN disciplinas d ON d.id = a.disciplina_id
        INNER JOIN turmas t ON t.id = a.turma_id
        WHERE a.professor_id = :professor_id AND a.data_entrega >= CURDATE()
        ORDER BY a.data_entrega ASC
        LIMIT 5
    ";
    $stmt_atividades = $conn->prepare($sql_atividades);
    $stmt_atividades->execute([':professor_id' => $professor_id]);
    $atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $atividades = [];
}

// ============================================
// HORÁRIOS DO DIA
// ============================================
$horarios_hoje = [];
try {
    $dias_semana = [
        0 => 'domingo', 1 => 'segunda', 2 => 'terca', 
        3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sabado'
    ];
    $dia_semana = $dias_semana[date('w')];
    
    $sql_horarios = "
        SELECT h.*, d.nome as disciplina_nome, t.nome as turma_nome
        FROM horarios h
        INNER JOIN disciplinas d ON d.id = h.disciplina_id
        INNER JOIN turmas t ON t.id = h.turma_id
        WHERE h.professor_id = :professor_id AND h.dia_semana = :dia_semana
        ORDER BY h.horario_inicio
    ";
    $stmt_horarios = $conn->prepare($sql_horarios);
    $stmt_horarios->execute([':professor_id' => $professor_id, ':dia_semana' => $dia_semana]);
    $horarios_hoje = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $horarios_hoje = [];
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard do Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .stats-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.2s;
            cursor: pointer;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .bg-primary-gradient {
            background: linear-gradient(135deg, #006B3E 0%, #008B5E 100%);
        }
        .bg-info-gradient {
            background: linear-gradient(135deg, #1A2A6C 0%, #2A3A7C 100%);
        }
        .bg-success-gradient {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .welcome-banner {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        .horario-item {
            border-left: 4px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .turma-card {
            transition: transform 0.2s;
        }
        .turma-card:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-chalkboard-user"></i> Bem-vindo, <?php echo htmlspecialchars($professor['professor_nome']); ?>!</h2>
            <p class="mb-0">Acompanhe suas turmas, lançamentos de notas e atividades diárias.</p>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-primary-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Minhas Turmas</h6>
                                <h2 class="mb-0"><?php echo $stats['total_turmas']; ?></h2>
                            </div>
                            <i class="fas fa-users fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-info-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Meus Alunos</h6>
                                <h2 class="mb-0"><?php echo $stats['total_alunos']; ?></h2>
                            </div>
                            <i class="fas fa-user-graduate fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-success-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Disciplinas</h6>
                                <h2 class="mb-0"><?php echo $stats['total_disciplinas']; ?></h2>
                            </div>
                            <i class="fas fa-book fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-warning-gradient text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Chamada Hoje</h6>
                                <h2 class="mb-0"><?php echo $stats['chamada_hoje']; ?></h2>
                            </div>
                            <i class="fas fa-clipboard-list fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Horários de Hoje -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-clock text-primary"></i> Horários de Hoje</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($horarios_hoje)): ?>
                            <p class="text-muted text-center">Nenhuma aula programada para hoje.</p>
                        <?php else: ?>
                            <?php foreach ($horarios_hoje as $horario): ?>
                            <div class="horario-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo date('H:i', strtotime($horario['horario_inicio'])); ?> - <?php echo date('H:i', strtotime($horario['horario_fim'])); ?></strong>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($horario['disciplina_nome']); ?></h6>
                                        <small class="text-muted">Turma: <?php echo htmlspecialchars($horario['turma_nome']); ?></small>
                                    </div>
                                    <a href="registrar_chamada.php?turma_id=<?php echo $horario['turma_id']; ?>&disciplina_id=<?php echo $horario['disciplina_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-clipboard-list"></i> Chamada
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Próximas Atividades -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt text-primary"></i> Próximas Atividades</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($atividades)): ?>
                            <p class="text-muted text-center">Nenhuma atividade programada.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($atividades as $atividade): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($atividade['titulo']); ?></h6>
                                            <small class="text-muted">
                                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($atividade['disciplina_nome']); ?> |
                                                <i class="fas fa-users"></i> <?php echo htmlspecialchars($atividade['turma_nome']); ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-warning">
                                            <?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Minhas Turmas -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-users text-primary"></i> Minhas Turmas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($turmas_professor)): ?>
                            <p class="text-muted text-center">Nenhuma turma associada.</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($turmas_professor as $turma): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card turma-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title"><?php echo htmlspecialchars($turma['nome']); ?></h6>
                                            <p class="card-text">
                                                <small><i class="fas fa-graduation-cap"></i> <?php echo $turma['ano']; ?>ª Classe</small><br>
                                                <small><i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno']); ?></small><br>
                                                <small><i class="fas fa-door-open"></i> Sala: <?php echo $turma['sala'] ?: 'Não definida'; ?></small>
                                            </p>
                                        </div>
                                        <div class="card-footer bg-white">
                                            <div class="btn-group w-100">
                                                <a href="lancar_notas.php?turma_id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-pen-alt"></i> Notas
                                                </a>
                                                <a href="registrar_chamada.php?turma_id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-clipboard-list"></i> Chamada
                                                </a>
                                                <a href="alunos.php?turma_id=<?php echo $turma['id']; ?>" class="btn btn-sm btn-success">
                                                    <i class="fas fa-users"></i> Alunos
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Links Rápidos -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-bolt text-primary"></i> Ações Rápidas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="lancar_notas.php" class="btn btn-outline-primary w-100 py-3">
                                    <i class="fas fa-pen-alt fa-2x d-block mb-2"></i>
                                    Lançar Notas
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="registrar_chamada.php" class="btn btn-outline-info w-100 py-3">
                                    <i class="fas fa-clipboard-list fa-2x d-block mb-2"></i>
                                    Registrar Chamada
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="horarios.php" class="btn btn-outline-warning w-100 py-3">
                                    <i class="fas fa-clock fa-2x d-block mb-2"></i>
                                    Meus Horários
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="perfil.php" class="btn btn-outline-secondary w-100 py-3">
                                    <i class="fas fa-user-circle fa-2x d-block mb-2"></i>
                                    Meu Perfil
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>