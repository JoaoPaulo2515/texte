<?php
// escola/professor/provas/provas_andamento.php - Provas em Andamento

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
// Verificar se o professor está logado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}*/

$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';

// Buscar dados do professor
$sql_professor = "SELECT f.id as funcionario_id FROM funcionarios f WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $professor['funcionario_id'] ?? 0;

// Filtros
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR PROVAS EM ANDAMENTO (COM ALUNOS RESPONDENDO)
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    d.nome as disciplina_nome,
                    d.cor as disciplina_cor,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'em_andamento') as em_andamento,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as finalizadas,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id) as total_tentativas
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN turmas t ON t.id = p.turma_id
                WHERE p.escola_id = :escola_id
                AND p.professor_id = :funcionario_id
                AND p.status = 'publicada'
                AND (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'em_andamento') > 0";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}

$sql_provas .= " ORDER BY p.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':escola_id' => $escola_id,
    ':funcionario_id' => $funcionario_id
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DETALHES DOS ALUNOS EM CADA PROVA
// ==============================================
foreach ($provas as $key => $prova) {
    // Buscar alunos que estão realizando a prova
    $sql_alunos = "SELECT 
                        t.id as tentativa_id,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.tempo_gasto_segundos,
                        e.id as aluno_id,
                        e.nome as aluno_nome,
                        e.matricula as aluno_matricula,
                        TIMESTAMPDIFF(MINUTE, t.data_inicio, NOW()) as minutos_decorridos,
                        (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id) as respostas_respondidas,
                        (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = t.id) as total_questoes
                    FROM online_provas_tentativas t
                    JOIN estudantes e ON e.id = t.aluno_id
                    WHERE t.prova_id = :prova_id AND t.status = 'em_andamento'
                    ORDER BY t.data_inicio ASC";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':prova_id' => $prova['id']]);
    $provas[$key]['alunos'] = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular tempo restante para cada aluno
    foreach ($provas[$key]['alunos'] as &$aluno) {
        $tempo_restante = max(0, ($prova['duracao_minutos'] * 60) - ($aluno['minutos_decorridos'] * 60));
        $aluno['tempo_restante_formatado'] = sprintf("%02d:%02d", floor($tempo_restante / 60), $tempo_restante % 60);
        $aluno['percentual_respondido'] = $aluno['total_questoes'] > 0 
            ? round(($aluno['respostas_respondidas'] / $aluno['total_questoes']) * 100) 
            : 0;
    }
}

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.escola_id = :escola_id 
                    AND p.professor_id = :funcionario_id
                    AND  (p.status = 'publicada' or p.status = 'agendada')
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([
    ':escola_id' => $escola_id,
    ':funcionario_id' => $funcionario_id
]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$total_alunos_em_andamento = 0;
foreach ($provas as $prova) {
    $total_alunos_em_andamento += count($prova['alunos']);
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provas em Andamento | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .prova-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .prova-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
        }
        .prova-body {
            padding: 20px;
        }
        
        .aluno-card {
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            padding: 12px;
            border-left: 4px solid #ffc107;
            transition: all 0.3s;
        }
        .aluno-card:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .disciplina-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .progress-custom {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        .btn-monitorar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        .em-andamento {
            animation: pulse 2s infinite;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    
     <?php include '../includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="container-fluid">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-hourglass-half"></i> Provas em Andamento</h4>
                <p class="text-muted mb-0">Acompanhe as provas que os alunos estão realizando</p>
            </div>
            <div>
                <a href="listar_provas.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4 fade-in">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_provas; ?></div>
                    <div class="stat-label">Provas em Andamento</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value text-warning em-andamento"><?php echo $total_alunos_em_andamento; ?></div>
                    <div class="stat-label">Alunos Realizando Prova</div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card border-0 shadow-sm mb-4 fade-in">
            <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Disciplina</label>
                        <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">Todas as disciplinas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                            <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                        <a href="provas_andamento.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Provas -->
        <?php if (empty($provas)): ?>
            <div class="alert alert-info text-center fade-in">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>Nenhuma prova em andamento</h5>
                <p>No momento não há alunos realizando provas.</p>
            </div>
        <?php else: ?>
            <div class="provas-list">
                <?php foreach ($provas as $prova): ?>
                <div class="prova-card fade-in">
                    <div class="prova-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                                <span class="disciplina-badge">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                                </span>
                                <span class="disciplina-badge ms-2">
                                    <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                                </span>
                            </div>
                            <div>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-users"></i> <?php echo count($prova['alunos']); ?> aluno(s) realizando
                                </span>
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-check-circle"></i> <?php echo $prova['finalizadas']; ?> finalizadas
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="prova-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <small><i class="fas fa-clock"></i> Duração: <?php echo $prova['duracao_minutos']; ?> minutos</small>
                            </div>
                            <div class="col-md-6">
                                <small><i class="fas fa-calendar-alt"></i> Período: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?> até <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></small>
                            </div>
                        </div>
                        
                        <h6 class="mb-3"><i class="fas fa-user-graduate"></i> Alunos realizando esta prova:</h6>
                        
                        <?php foreach ($prova['alunos'] as $aluno): ?>
                        <div class="aluno-card">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <strong><?php echo htmlspecialchars($aluno['aluno_nome']); ?></strong>
                                    <br>
                                    <small class="text-muted">Mat: <?php echo $aluno['aluno_matricula']; ?></small>
                                </div>
                                <div class="col-md-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-hourglass-half text-warning"></i>
                                        <span>Tempo restante: <strong class="text-danger"><?php echo $aluno['tempo_restante_formatado']; ?></strong></span>
                                    </div>
                                    <small>Tentativa <?php echo $aluno['tentativa_numero']; ?>ª</small>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Progresso:</small>
                                        <small><?php echo $aluno['percentual_respondido']; ?>%</small>
                                    </div>
                                    <div class="progress-custom">
                                        <div class="progress-fill bg-success" style="width: <?php echo $aluno['percentual_respondido']; ?>%;"></div>
                                    </div>
                                    <small class="text-muted"><?php echo $aluno['respostas_respondidas']; ?> / <?php echo $aluno['total_questoes']; ?> questões respondidas</small>
                                </div>
                                <div class="col-md-2 text-end">
                                    <button class="btn-monitorar" onclick="monitorarAluno(<?php echo $aluno['tentativa_id']; ?>)">
                                        <i class="fas fa-eye"></i> Monitorar
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="text-end mt-3">
                            <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-chart-line"></i> Ver todos os resultados
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Dica -->
        <div class="card border-0 shadow-sm mt-4 fade-in">
            <div class="card-header bg-white fw-bold"><i class="fas fa-lightbulb"></i> Dicas</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-chart-line text-info fa-2x"></i>
                            <div>
                                <strong>Acompanhamento em tempo real</strong>
                                <p class="mb-0 small">Você pode ver o progresso dos alunos enquanto eles realizam a prova.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-clock text-warning fa-2x"></i>
                            <div>
                                <strong>Tempo restante</strong>
                                <p class="mb-0 small">O sistema mostra o tempo restante para cada aluno finalizar a prova.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
    
    function monitorarAluno(tentativaId) {
        window.open('monitorar_aluno.php?tentativa=' + tentativaId, '_blank', 'width=800,height=600');
    }
    
    // Atualizar página a cada 30 segundos para manter dados atualizados
    setTimeout(function() {
        location.reload();
    }, 30000);
</script>
</body>
</html>