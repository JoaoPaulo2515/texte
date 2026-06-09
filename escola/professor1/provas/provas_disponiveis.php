<?php
// escola/professor/provas/provas_disponiveis.php - Provas Disponíveis (Visualização do Professor)

require_once __DIR__ . '/../../config/database.php';
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
// BUSCAR PROVAS PUBLICADAS (VISUALIZAÇÃO DO PROFESSOR)
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.instrucoes,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.status,
                    d.nome as disciplina_nome,
                    d.cor as disciplina_cor,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id) as total_tentativas,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_finalizadas
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN turmas t ON t.id = p.turma_id
                WHERE p.escola_id = :escola_id
                AND (p.status = 'publicada' or p.status = 'agendada')";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}

$sql_provas .= " ORDER BY p.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [':escola_id' => $escola_id];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.escola_id = :escola_id 
                    AND (p.status = 'publicada' or p.status = 'agendada')
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$total_ativas = 0;
$total_finalizadas_total = 0;
$total_alunos_atingidos = 0;

foreach ($provas as $prova) {
    $agora = new DateTime();
    $data_inicio = new DateTime($prova['data_inicio']);
    $data_fim = new DateTime($prova['data_fim']);
    
    if ($agora >= $data_inicio && $agora <= $data_fim) {
        $total_ativas++;
    }
    $total_finalizadas_total += $prova['total_finalizadas'];
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provas Disponíveis | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .prova-body {
            padding: 20px;
        }
        .prova-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .disciplina-badge {
            background: #e8f5e9;
            color: #006B3E;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        .badge-ativa { background: #28a745; color: white; }
        .badge-agendada { background: #ffc107; color: #333; }
        .badge-encerrada { background: #6c757d; color: white; }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        .btn-resultados {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
     <?php include 'includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="container-fluid">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-eye"></i> Provas Disponíveis para os Alunos</h4>
                <p class="text-muted mb-0">Visualize todas as provas que estão disponíveis para os alunos</p>
            </div>
            <div>
                <a href="listar_provas.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4 fade-in">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_provas; ?></div>
                    <div class="stat-label">Total de Provas Publicadas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                    <div class="stat-label">Provas Ativas no Momento</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_finalizadas_total; ?></div>
                    <div class="stat-label">Tentativas Realizadas</div>
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
                        <a href="provas_disponiveis.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Provas -->
        <?php if (empty($provas)): ?>
            <div class="alert alert-info text-center fade-in">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>Nenhuma prova publicada encontrada</h5>
                <p>Você ainda não publicou nenhuma prova ou não há provas com os filtros selecionados.</p>
                <a href="criar_prova.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus-circle"></i> Criar Nova Prova
                </a>
            </div>
        <?php else: ?>
            <div class="provas-list">
                <?php foreach ($provas as $prova): 
                    $agora = new DateTime();
                    $data_inicio = new DateTime($prova['data_inicio']);
                    $data_fim = new DateTime($prova['data_fim']);
                    
                    if ($agora < $data_inicio) {
                        $status_class = 'badge-agendada';
                        $status_texto = 'Agendada';
                    } elseif ($agora >= $data_inicio && $agora <= $data_fim) {
                        $status_class = 'badge-ativa';
                        $status_texto = 'Ativa';
                    } else {
                        $status_class = 'badge-encerrada';
                        $status_texto = 'Encerrada';
                    }
                ?>
                <div class="prova-card fade-in">
                    <div class="prova-header">
                        <div>
                            <span class="disciplina-badge">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                            </span>
                            <span class="ms-2 badge-status <?php echo $status_class; ?>"><?php echo $status_texto; ?></span>
                            <span class="ms-2 text-muted">
                                <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                            </span>
                        </div>
                        <div>
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt"></i> Período: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?> até <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="prova-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                                <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))) . (strlen($prova['descricao'] ?? '') > 150 ? '...' : ''); ?></p>
                                
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-clock text-primary"></i>
                                            <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-question-circle text-primary"></i>
                                            <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-star text-warning"></i>
                                            <span>Nota Máxima: <strong><?php echo $prova['nota_maxima']; ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-repeat text-info"></i>
                                            <span>Tentativas: <strong><?php echo $prova['tentativas_permitidas']; ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-users text-info"></i>
                                            <span>Tentativas realizadas: <strong><?php echo $prova['total_tentativas']; ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <i class="fas fa-check-circle text-success"></i>
                                            <span>Finalizadas: <strong><?php echo $prova['total_finalizadas']; ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 text-center">
                                <?php if ($prova['total_finalizadas'] > 0): ?>
                                <div class="mb-3">
                                    <span class="badge bg-success"><?php echo round(($prova['total_finalizadas'] / max(1, $prova['total_tentativas'])) * 100); ?>% concluíram</span>
                                </div>
                                <?php endif; ?>
                                <div class="progress mt-2" style="height: 8px;">
                                    <?php $percentual = $prova['total_tentativas'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_tentativas']) * 100) : 0; ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%;"></div>
                                </div>
                                <div class="mt-3">
                                    <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn-resultados">
                                        <i class="fas fa-chart-line"></i> Ver Resultados
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
</script>
</body>
</html>