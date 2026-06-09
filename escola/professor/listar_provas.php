<?php
// escola/professor/provas/listar_provas.php - Listar Provas do Professor

require_once __DIR__ . '/../../config/database.php';
session_start();

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Minhas Provas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .page-header h4 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 0.9rem;
        }

        .btn-criar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-criar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        /* ============================================
           CARDS DE ESTATÍSTICAS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .stat-icon i {
            font-size: 24px;
            color: #006B3E;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           CARD DE FILTROS
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .filter-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .filter-header i {
            margin-right: 10px;
        }

        .filter-body {
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        .btn-outline-secondary {
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            border: 2px solid #6c757d;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
            background: #6c757d;
            color: white;
        }

        /* ============================================
           CARDS DE PROVAS
        ============================================ */
        .prova-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .prova-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .prova-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .badge-status {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-ativa {
            background: #28a745;
            color: white;
        }

        .badge-agendada {
            background: #ffc107;
            color: #333;
        }

        .badge-encerrada {
            background: #6c757d;
            color: white;
        }

        .badge-rascunho {
            background: #17a2b8;
            color: white;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        .info-text {
            font-size: 0.75rem;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 12px;
        }

        .info-text i {
            font-size: 0.7rem;
        }

        .prova-body {
            padding: 25px;
        }

        .prova-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #333;
        }

        .prova-description {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: #555;
        }

        .info-item i {
            width: 22px;
            color: #006B3E;
        }

        .prova-footer {
            background: #f8f9fa;
            padding: 15px 25px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .status-message {
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-message i {
            font-size: 0.9rem;
        }

        .btn-group-custom {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-sm-custom {
            padding: 6px 15px;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-sm-custom:hover {
            transform: translateY(-2px);
        }

        .btn-primary-custom {
            background: #006B3E;
            color: white;
            border: none;
        }

        .btn-primary-custom:hover {
            background: #004d2e;
            color: white;
        }

        .btn-success-custom {
            background: #28a745;
            color: white;
            border: none;
        }

        .btn-success-custom:hover {
            background: #1e7e34;
            color: white;
        }

        .btn-info-custom {
            background: #17a2b8;
            color: white;
            border: none;
        }

        .btn-info-custom:hover {
            background: #117a8b;
            color: white;
        }

        .btn-secondary-custom {
            background: #6c757d;
            color: white;
            border: none;
        }

        .btn-secondary-custom:hover {
            background: #5a6268;
            color: white;
        }

        .btn-danger-custom {
            background: #dc3545;
            color: white;
            border: none;
        }

        .btn-danger-custom:hover {
            background: #bd2130;
            color: white;
        }

        .progress-custom {
            height: 5px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-bar-custom {
            height: 100%;
            background: #28a745;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* ============================================
           ALERTA VAZIO
        ============================================ */
        .empty-alert {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            border-radius: 20px;
            padding: 50px 20px;
            text-align: center;
        }

        .empty-alert i {
            font-size: 4rem;
            color: #006B3E;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-alert h5 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }

        .empty-alert p {
            color: #6c757d;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .prova-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .prova-footer {
                flex-direction: column;
            }
            
            .btn-group-custom {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-body .row {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-file-alt me-2"></i> Minhas Provas</h4>
                        <p>Gerencie todas as suas provas online de forma fácil e rápida</p>
                    </div>
                    <div>
                        <a href="criar_prova.php" class="btn-criar">
                            <i class="fas fa-plus-circle"></i> Criar Nova Prova
                        </a>
                    </div>
                </div>
            </div>

            <!-- Cards de Estatísticas -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                    <div class="stat-label">Provas Ativas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value text-warning"><?php echo $total_agendadas; ?></div>
                    <div class="stat-label">Provas Agendadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="stat-value text-secondary"><?php echo $total_encerradas; ?></div>
                    <div class="stat-label">Provas Encerradas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-pencil-alt"></i>
                    </div>
                    <div class="stat-value text-info"><?php echo $total_rascunhos; ?></div>
                    <div class="stat-label">Rascunhos</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filter-card fade-in">
                <div class="filter-header">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </div>
                <div class="filter-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                                <option value="ativa" <?php echo $status_filtro == 'ativa' ? 'selected' : ''; ?>>Ativas</option>
                                <option value="agendada" <?php echo $status_filtro == 'agendada' ? 'selected' : ''; ?>>Agendadas</option>
                                <option value="encerrada" <?php echo $status_filtro == 'encerrada' ? 'selected' : ''; ?>>Encerradas</option>
                                <option value="rascunho" <?php echo $status_filtro == 'rascunho' ? 'selected' : ''; ?>>Rascunhos</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Disciplina</label>
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
                            <label class="form-label">Buscar por título</label>
                            <input type="text" name="busca" class="form-control" placeholder="Digite o título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtrar
                            </button>
                            <a href="listar_provas.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-eraser"></i> Limpar
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Provas -->
            <?php if (empty($provas)): ?>
                <div class="empty-alert fade-in">
                    <i class="fas fa-info-circle"></i>
                    <h5>Nenhuma prova encontrada</h5>
                    <p>Você ainda não criou nenhuma prova. Clique no botão "Criar Nova Prova" para começar.</p>
                    <a href="criar_prova.php" class="btn-criar mt-3">
                        <i class="fas fa-plus-circle"></i> Criar Primeira Prova
                    </a>
                </div>
            <?php else: ?>
                <div class="provas-list">
                    <?php foreach ($provas as $prova): 
                        $status_class = '';
                        $status_texto = '';
                        $status_icon = '';
                        
                        if ($prova['status'] == 'agendada' && $prova['status_atual'] == 'agendada') {
                            $status_class = 'badge-rascunho';
                            $status_texto = 'Rascunho';
                            $status_icon = 'fa-pencil-alt';
                        } elseif ($prova['status_atual'] == 'ativa') {
                            $status_class = 'badge-ativa';
                            $status_texto = 'Ativa';
                            $status_icon = 'fa-play-circle';
                        } elseif ($prova['status_atual'] == 'agendada') {
                            $status_class = 'badge-agendada';
                            $status_texto = 'Agendada';
                            $status_icon = 'fa-clock';
                        } elseif ($prova['status_atual'] == 'encerrada') {
                            $status_class = 'badge-encerrada';
                            $status_texto = 'Encerrada';
                            $status_icon = 'fa-lock';
                        } else {
                            $status_class = 'badge-secondary';
                            $status_texto = ucfirst($prova['status']);
                            $status_icon = 'fa-question-circle';
                        }
                        
                        $pode_editar = ($prova['status'] == 'agendada' && $prova['status_atual'] == 'agendada') || $prova['status'] == 'agendada';
                        $pode_publicar = $pode_editar && $prova['total_questoes'] > 0;
                        $percentual = $prova['total_tentativas'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_tentativas']) * 100) : 0;
                    ?>
                    <div class="prova-card fade-in">
                        <div class="prova-header">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="badge-status <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_texto; ?>
                                </span>
                                <span class="info-text">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                                </span>
                                <span class="info-text">
                                    <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                                </span>
                            </div>
                            <div class="info-text">
                                <i class="fas fa-calendar-alt"></i> Criada em: <?php echo date('d/m/Y', strtotime($prova['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="prova-body">
                            <h5 class="prova-title"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="prova-description">
                                <?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 100))) . (strlen($prova['descricao'] ?? '') > 100 ? '...' : ''); ?>
                            </p>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> min</strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-question-circle"></i>
                                    <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-users"></i>
                                    <span>Alunos: <strong><?php echo $prova['total_finalizadas']; ?> tentativas</strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-alt text-success"></i>
                                    <span>Início: <strong><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-times text-danger"></i>
                                    <span>Término: <strong><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></strong></span>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Progresso de conclusão</small>
                                    <small class="fw-bold text-success"><?php echo $percentual; ?>%</small>
                                </div>
                                <div class="progress-custom">
                                    <div class="progress-bar-custom" style="width: <?php echo $percentual; ?>%;"></div>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-chart-line"></i> <?php echo $prova['total_tentativas']; ?> tentativas no total
                                </small>
                            </div>
                        </div>
                        
                        <div class="prova-footer">
                            <div class="status-message">
                                <?php if ($prova['status_atual'] == 'ativa'): ?>
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span class="text-success">Disponível para alunos</span>
                                <?php elseif ($prova['status_atual'] == 'agendada'): ?>
                                    <i class="fas fa-clock text-warning"></i>
                                    <span class="text-warning">Disponível em breve</span>
                                <?php elseif ($prova['status_atual'] == 'encerrada'): ?>
                                    <i class="fas fa-lock text-secondary"></i>
                                    <span class="text-secondary">Prova encerrada</span>
                                <?php elseif ($pode_editar): ?>
                                    <i class="fas fa-edit text-info"></i>
                                    <span class="text-info">Rascunho - não publicado</span>
                                <?php endif; ?>
                            </div>
                            <div class="btn-group-custom">
                                <?php if ($pode_editar): ?>
                                    <a href="adicionar_questoes.php?id=<?php echo $prova['id']; ?>" class="btn-sm-custom btn-primary-custom">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <?php if ($pode_publicar): ?>
                                    <a href="publicar_prova.php?id=<?php echo $prova['id']; ?>" class="btn-sm-custom btn-success-custom" onclick="return confirm('Publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                                        <i class="fas fa-check-circle"></i> Publicar
                                    </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn-sm-custom btn-info-custom">
                                        <i class="fas fa-chart-line"></i> Ver Resultados
                                    </a>
                                <?php endif; ?>
                                
                                <a href="previsualizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn-sm-custom btn-secondary-custom" target="_blank">
                                    <i class="fas fa-eye"></i> Pré-visualizar
                                </a>
                                
                                <?php if ($pode_editar): ?>
                                <a href="excluir_prova.php?id=<?php echo $prova['id']; ?>" class="btn-sm-custom btn-danger-custom" onclick="return confirm('Tem certeza que deseja excluir esta prova? Esta ação não pode ser desfeita.')">
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
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Mensagem de sucesso ao voltar de outras páginas
        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg === 'prova_criada') {
            alert('✅ Prova criada com sucesso!');
        } else if (msg === 'prova_publicada') {
            alert('✅ Prova publicada com sucesso!');
        } else if (msg === 'prova_ja_publicada') {
            alert('ℹ️ Esta prova já está publicada.');
        }
        
        // Adicionar animações aos elementos ao rolar
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.prova-card, .stat-card, .filter-card').forEach(card => {
            card.classList.remove('fade-in');
            observer.observe(card);
        });
    </script>
</body>
</html>