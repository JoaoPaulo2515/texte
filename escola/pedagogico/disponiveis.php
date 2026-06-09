<?php
// escola/professor/provas/provas_disponiveis.php - Provas Disponíveis (Visualização do Professor)

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Provas Disponíveis | Professor | SIGE Angola</title>
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

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateX(-3px);
        }

        /* ============================================
           CARDS DE ESTATÍSTICAS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px 20px;
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
            width: 60px;
            height: 60px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .stat-icon i {
            font-size: 28px;
            color: #006B3E;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.8rem;
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
            font-size: 0.75rem;
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

        .disciplina-badge {
            background: rgba(0, 107, 62, 0.1);
            color: #006B3E;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .badge-status {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
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

        .turma-info {
            color: #6c757d;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .periodo-info {
            font-size: 0.7rem;
            color: #6c757d;
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
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.8rem;
            color: #555;
        }

        .info-item i {
            width: 25px;
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

        .progress-stats {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .progress {
            width: 200px;
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
        }

        .progress-bar {
            background: #28a745;
            border-radius: 10px;
        }

        .btn-resultados {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-resultados:hover {
            background: #138496;
            transform: translateY(-2px);
            color: white;
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
            .prova-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .prova-footer {
                flex-direction: column;
                text-align: center;
            }
            
            .progress-stats {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
   <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
</br>
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4><i class="fas fa-eye me-2"></i> Provas Disponíveis para os Alunos</h4>
                        <p>Visualize todas as provas que estão disponíveis para os alunos</p>
                    </div>
                    <div>
                        <a href="listar_provas.php" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Cards de Estatísticas -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-value text-primary"><?php echo $total_provas; ?></div>
                    <div class="stat-label">Total de Provas Publicadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                    <div class="stat-label">Provas Ativas no Momento</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value text-info"><?php echo $total_finalizadas_total; ?></div>
                    <div class="stat-label">Tentativas Realizadas</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filter-card fade-in">
                <div class="filter-header">
                    <i class="fas fa-filter"></i> Filtros de Busca
                </div>
                <div class="filter-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
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
                        <div class="col-md-5">
                            <label class="form-label">Buscar por título</label>
                            <input type="text" name="busca" class="form-control" placeholder="Digite o título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                        </div>
                        <div class="col-md-3 d-flex gap-2 align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtrar
                            </button>
                            <a href="provas_disponiveis.php" class="btn btn-outline-secondary w-100">
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
                    <h5>Nenhuma prova publicada encontrada</h5>
                    <p>Você ainda não publicou nenhuma prova ou não há provas com os filtros selecionados.</p>
                    <a href="criar_prova.php" class="btn btn-primary mt-3">
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
                            $status_texto = '📅 Agendada';
                        } elseif ($agora >= $data_inicio && $agora <= $data_fim) {
                            $status_class = 'badge-ativa';
                            $status_texto = '✅ Ativa';
                        } else {
                            $status_class = 'badge-encerrada';
                            $status_texto = '🔒 Encerrada';
                        }
                        
                        $percentual_conclusao = $prova['total_tentativas'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_tentativas']) * 100) : 0;
                    ?>
                    <div class="prova-card fade-in">
                        <div class="prova-header">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="disciplina-badge">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                                </span>
                                <span class="badge-status <?php echo $status_class; ?>"><?php echo $status_texto; ?></span>
                                <span class="turma-info">
                                    <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                                </span>
                            </div>
                            <div class="periodo-info">
                                <i class="fas fa-calendar-alt"></i> 
                                <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?> até 
                                <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?>
                            </div>
                        </div>
                        
                        <div class="prova-body">
                            <h5 class="prova-title"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="prova-description">
                                <?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))) . (strlen($prova['descricao'] ?? '') > 150 ? '...' : ''); ?>
                            </p>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-question-circle"></i>
                                    <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-star"></i>
                                    <span>Nota Máxima: <strong><?php echo $prova['nota_maxima']; ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-repeat"></i>
                                    <span>Tentativas: <strong><?php echo $prova['tentativas_permitidas']; ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-users"></i>
                                    <span>Tentativas: <strong><?php echo $prova['total_tentativas']; ?></strong></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Finalizadas: <strong><?php echo $prova['total_finalizadas']; ?></strong></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="prova-footer">
                            <div class="progress-stats">
                                <span class="small text-muted">
                                    <i class="fas fa-chart-line"></i> Progresso de conclusão
                                </span>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $percentual_conclusao; ?>%"></div>
                                </div>
                                <span class="small fw-bold"><?php echo $percentual_conclusao; ?>%</span>
                            </div>
                            <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn-resultados">
                                <i class="fas fa-chart-line"></i> Ver Resultados
                            </a>
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
        
        // Adicionar classe fade-in aos elementos ao rolar
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
        
        document.querySelectorAll('.prova-card').forEach(card => {
            card.classList.remove('fade-in');
            observer.observe(card);
        });
    </script>
</body>
</html>