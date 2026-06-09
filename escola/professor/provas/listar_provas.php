<?php
// escola/professor/provas/listar_provas.php - Listar Provas do Professor

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
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;

// ==============================================
// BUSCAR PROVAS DO PROFESSOR
// ==============================================
$sql_provas = "SELECT 
                    p.*,
                    d.nome as disciplina_nome,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id) as total_tentativas,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_finalizadas,
                    CASE 
                        WHEN (p.status = 'publicada' or p.status = 'agendada') AND p.data_fim < NOW() THEN 'encerrada'
                        WHEN (p.status = 'publicada' or p.status = 'agendada') AND p.data_inicio <= NOW() AND p.data_fim >= NOW() THEN 'ativa'
                        WHEN (p.status = 'publicada' or p.status = 'agendada') AND p.data_inicio > NOW() THEN 'agendada'
                        ELSE p.status
                    END as status_atual
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN turmas t ON t.id = p.turma_id
                WHERE p.professor_id = :funcionario_id
                AND p.escola_id = :escola_id";

if ($status_filtro != 'todas') {
    if ($status_filtro == 'ativa') {
        $sql_provas .= " AND (p.status = 'publicada' or p.status = 'agendada') AND p.data_inicio <= NOW() AND p.data_fim >= NOW()";
    } elseif ($status_filtro == 'agendada') {
        $sql_provas .= " AND (p.status = 'publicada' or p.status = 'agendada') AND p.data_inicio > NOW()";
    } elseif ($status_filtro == 'encerrada') {
        $sql_provas .= " AND (p.status = 'publicada' or p.status = 'agendada') AND p.data_fim < NOW()";
    } elseif ($status_filtro == 'rascunho') {
        $sql_provas .= " AND p.status = 'agendada'";
    } else {
        $sql_provas .= " AND p.status = :status";
    }
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}
if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}

$sql_provas .= " ORDER BY p.created_at DESC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id];
if ($status_filtro != 'todas' && $status_filtro != 'ativa' && $status_filtro != 'agendada' && $status_filtro != 'encerrada' && $status_filtro != 'rascunho') {
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.professor_id = :funcionario_id 
                    AND p.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$total_ativas = 0;
$total_agendadas = 0;
$total_encerradas = 0;
$total_rascunhos = 0;
$total_questoes = 0;
$total_alunos_atingidos = 0;

foreach ($provas as $prova) {
    if ($prova['status_atual'] == 'ativa') {
        $total_ativas++;
    } elseif ($prova['status_atual'] == 'agendada') {
        $total_agendadas++;
    } elseif ($prova['status_atual'] == 'encerrada') {
        $total_encerradas++;
    } elseif ($prova['status'] == 'agendada') {
        $total_rascunhos++;
    }
    $total_questoes += $prova['total_questoes'];
    $total_alunos_atingidos += $prova['total_finalizadas'];
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Provas | Professor</title>
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
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .prova-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
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
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        .badge-ativa { background: #28a745; color: white; }
        .badge-agendada { background: #ffc107; color: #333; }
        .badge-encerrada { background: #6c757d; color: white; }
        .badge-rascunho { background: #17a2b8; color: white; }
        
        .btn-sm-custom {
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-criar {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
        }
        .btn-criar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
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
                <h4 class="mb-1"><i class="fas fa-file-alt"></i> Minhas Provas</h4>
                <p class="text-muted mb-0">Gerencie todas as suas provas online</p>
            </div>
            <div>
                <a href="criar_prova.php" class="btn-criar">
                    <i class="fas fa-plus-circle"></i> Criar Nova Prova
                </a>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4 fade-in">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                    <div class="stat-label">Provas Ativas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_agendadas; ?></div>
                    <div class="stat-label">Provas Agendadas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-secondary"><?php echo $total_encerradas; ?></div>
                    <div class="stat-label">Provas Encerradas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_rascunhos; ?></div>
                    <div class="stat-label">Rascunhos</div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card border-0 shadow-sm mb-4 fade-in">
            <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="ativa" <?php echo $status_filtro == 'ativa' ? 'selected' : ''; ?>>Ativas</option>
                            <option value="agendada" <?php echo $status_filtro == 'agendada' ? 'selected' : ''; ?>>Agendadas</option>
                            <option value="encerrada" <?php echo $status_filtro == 'encerrada' ? 'selected' : ''; ?>>Encerradas</option>
                            <option value="rascunho" <?php echo $status_filtro == 'rascunho' ? 'selected' : ''; ?>>Rascunhos</option>
                        </select>
                    </div>
                    <div class="col-md-3">
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
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                        <a href="listar_provas.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Provas -->
        <?php if (empty($provas)): ?>
            <div class="alert alert-info text-center fade-in">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>Nenhuma prova encontrada</h5>
                <p>Você ainda não criou nenhuma prova. Clique no botão "Criar Nova Prova" para começar.</p>
                <a href="criar_prova.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus-circle"></i> Criar Primeira Prova
                </a>
            </div>
        <?php else: ?>
            <div class="provas-list">
                <?php foreach ($provas as $prova): 
                    $status_class = '';
                    $status_texto = '';
                    
                    if ($prova['status'] == 'agendada' && $prova['status_atual'] == 'agendada') {
                        $status_class = 'badge-rascunho';
                        $status_texto = 'Rascunho';
                    } elseif ($prova['status_atual'] == 'ativa') {
                        $status_class = 'badge-ativa';
                        $status_texto = 'Ativa';
                    } elseif ($prova['status_atual'] == 'agendada') {
                        $status_class = 'badge-agendada';
                        $status_texto = 'Agendada';
                    } elseif ($prova['status_atual'] == 'encerrada') {
                        $status_class = 'badge-encerrada';
                        $status_texto = 'Encerrada';
                    } else {
                        $status_class = 'badge-secondary';
                        $status_texto = ucfirst($prova['status']);
                    }
                    
                    $pode_editar = ($prova['status'] == 'agendada' && $prova['status_atual'] == 'agendada') || $prova['status'] == 'agendada';
                    $pode_publicar = $pode_editar && $prova['total_questoes'] > 0;
                ?>
                <div class="prova-card fade-in">
                    <div class="prova-header">
                        <div>
                            <span class="badge-status <?php echo $status_class; ?>"><?php echo $status_texto; ?></span>
                            <span class="ms-2 text-muted">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                            </span>
                            <span class="ms-2 text-muted">
                                <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                            </span>
                        </div>
                        <div>
                            <small class="text-muted">Criada em: <?php echo date('d/m/Y', strtotime($prova['created_at'])); ?></small>
                        </div>
                    </div>
                    
                    <div class="prova-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                                <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 100))) . (strlen($prova['descricao'] ?? '') > 100 ? '...' : ''); ?></p>
                                
                                <div class="row g-2 mt-2">
                                    <div class="col-md-4">
                                        <small><i class="fas fa-clock text-primary"></i> Duração: <?php echo $prova['duracao_minutos']; ?> min</small>
                                    </div>
                                    <div class="col-md-4">
                                        <small><i class="fas fa-question-circle text-primary"></i> Questões: <?php echo $prova['total_questoes']; ?></small>
                                    </div>
                                    <div class="col-md-4">
                                        <small><i class="fas fa-users text-primary"></i> Alunos: <?php echo $prova['total_finalizadas']; ?> tentativas</small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-calendar-alt text-success"></i> Início: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-calendar-times text-danger"></i> Término: <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 text-center">
                                <div class="mb-2">
                                    <span class="badge bg-info"><?php echo $prova['total_tentativas']; ?> tentativas</span>
                                    <span class="badge bg-success ms-1"><?php echo $prova['total_finalizadas']; ?> finalizadas</span>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <?php $percentual = $prova['total_tentativas'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_tentativas']) * 100) : 0; ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="prova-footer">
                        <div>
                            <?php if ($prova['status_atual'] == 'ativa'): ?>
                                <span class="text-success"><i class="fas fa-check-circle"></i> Disponível para alunos</span>
                            <?php elseif ($prova['status_atual'] == 'agendada'): ?>
                                <span class="text-warning"><i class="fas fa-clock"></i> Disponível em breve</span>
                            <?php elseif ($prova['status_atual'] == 'encerrada'): ?>
                                <span class="text-secondary"><i class="fas fa-lock"></i> Prova encerrada</span>
                            <?php elseif ($pode_editar): ?>
                                <span class="text-info"><i class="fas fa-edit"></i> Rascunho - não publicado</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($pode_editar): ?>
                                <a href="adicionar_questoes.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <?php if ($pode_publicar): ?>
                                <a href="publicar_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                                    <i class="fas fa-check-circle"></i> Publicar
                                </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-chart-line"></i> Ver Resultados
                                </a>
                            <?php endif; ?>
                            
                            <a href="previsualizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">
                                <i class="fas fa-eye"></i> Pré-visualizar
                            </a>
                            
                            <?php if ($pode_editar): ?>
                            <a href="excluir_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza que deseja excluir esta prova? Esta ação não pode ser desfeita.')">
                                <i class="fas fa-trash"></i> Excluir
                            </a>
                            <?php endif; ?>
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
    
    // Mensagem de sucesso ao voltar de outras páginas
    const urlParams = new URLSearchParams(window.location.search);
    const msg = urlParams.get('msg');
    if (msg === 'prova_criada') {
        alert('Prova criada com sucesso!');
    } else if (msg === 'prova_publicada') {
        alert('Prova publicada com sucesso!');
    } else if (msg === 'prova_ja_publicada') {
        alert('Esta prova já está publicada.');
    }
</script>
</body>
</html>