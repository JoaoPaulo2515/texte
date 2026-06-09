<?php
// escola/professor/provas/detalhes_prova.php - Detalhes da Prova

require_once __DIR__ . '/../../config/database.php';
session_start();

$db = Database::getInstance();
$conn = $db->getConnection();
$professor_id = $_SESSION['professor_id'] ?? 0;
$escola_id = $_SESSION['escola_id'] ?? 0;
$professor_nome = $_SESSION['professor_nome'] ?? 'Professor';

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, d.cor as disciplina_cor,
              t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :professor_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':professor_id' => $professor_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada ou você não tem permissão para visualizá-la.');
}

// Buscar questões da prova
$sql_questoes = "SELECT q.*, 
                 (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = q.id) as num_alternativas
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar estatísticas de desempenho
$sql_stats = "SELECT 
                COUNT(*) as total_tentativas,
                COUNT(DISTINCT aluno_id) as total_alunos,
                COUNT(CASE WHEN status = 'finalizada' THEN 1 END) as finalizadas,
                AVG(pontuacao_total) as media_nota,
                MAX(pontuacao_total) as maior_nota,
                MIN(pontuacao_total) as menor_nota
              FROM online_provas_tentativas 
              WHERE prova_id = :prova_id";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':prova_id' => $prova_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$total_pontos = array_sum(array_column($questoes, 'pontuacao'));

// Funções auxiliares
function getStatusBadge($status) {
    $badges = [
        'agendada' => '<span class="badge-status badge-agendada"><i class="fas fa-calendar-alt"></i> Agendada</span>',
        'em_andamento' => '<span class="badge-status badge-ativa"><i class="fas fa-play-circle"></i> Em andamento</span>',
        'finalizada' => '<span class="badge-status badge-finalizada"><i class="fas fa-check-circle"></i> Finalizada</span>',
        'cancelada' => '<span class="badge-status badge-cancelada"><i class="fas fa-times-circle"></i> Cancelada</span>'
    ];
    return $badges[$status] ?? '<span class="badge-status">' . $status . '</span>';
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    return $data ? date($formato, strtotime($data)) : '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Detalhes da Prova - <?php echo htmlspecialchars($prova['titulo']); ?> | Professor</title>
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
           CABEÇALHO
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        /* ============================================
           CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
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
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           INFO ITENS
        ============================================ */
        .info-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-item {
            flex: 1;
            min-width: 200px;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge-status {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-agendada { background: #6c757d; color: white; }
        .badge-ativa { background: #28a745; color: white; }
        .badge-finalizada { background: #17a2b8; color: white; }
        .badge-cancelada { background: #dc3545; color: white; }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }

        .btn-editar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-editar:hover {
            background: #138496;
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .info-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .info-item {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-info-circle me-2"></i> Detalhes da Prova</h4>
                        <p class="mb-0"><?php echo htmlspecialchars($prova['titulo']); ?></p>
                    </div>
                    <div>
                        <a href="historico_provas.php" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                        <?php if ($prova['status'] == 'agendada'): ?>
                        <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn-editar ms-2">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                    <div class="stat-value text-primary"><?php echo count($questoes); ?></div>
                    <div class="stat-label">Questões</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-value text-warning"><?php echo $total_pontos; ?></div>
                    <div class="stat-label">Pontos Totais</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-value text-success"><?php echo $prova['nota_maxima']; ?></div>
                    <div class="stat-label">Nota Máxima</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-value text-info"><?php echo $stats['total_alunos'] ?? 0; ?></div>
                    <div class="stat-label">Alunos</div>
                </div>
            </div>

            <!-- Informações da Prova -->
            <div class="info-card">
                <h6 class="mb-3"><i class="fas fa-info-circle text-primary"></i> Informações Gerais</h6>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Disciplina</div>
                        <div class="info-value"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Turma</div>
                        <div class="info-value"><?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value"><?php echo getStatusBadge($prova['status']); ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Data de Início</div>
                        <div class="info-value"><i class="fas fa-calendar-alt me-1"></i> <?php echo formatarData($prova['data_inicio']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Data de Término</div>
                        <div class="info-value"><i class="fas fa-calendar-times me-1"></i> <?php echo formatarData($prova['data_fim']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Duração</div>
                        <div class="info-value"><i class="fas fa-clock me-1"></i> <?php echo $prova['duracao_minutos']; ?> minutos</div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Tentativas Permitidas</div>
                        <div class="info-value"><i class="fas fa-repeat me-1"></i> <?php echo $prova['tentativas_permitidas']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Nota Mínima</div>
                        <div class="info-value"><i class="fas fa-flag-checkered me-1"></i> <?php echo $prova['nota_minima_aprovacao']; ?> pontos</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Criada em</div>
                        <div class="info-value"><i class="fas fa-calendar-plus me-1"></i> <?php echo formatarData($prova['created_at']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Descrição -->
            <?php if ($prova['descricao']): ?>
            <div class="info-card">
                <h6 class="mb-3"><i class="fas fa-align-left text-primary"></i> Descrição</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($prova['descricao'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Instruções -->
            <?php if ($prova['instrucoes']): ?>
            <div class="info-card">
                <h6 class="mb-3"><i class="fas fa-exclamation-triangle text-warning"></i> Instruções</h6>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($prova['instrucoes'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Configurações -->
            <div class="info-card">
                <h6 class="mb-3"><i class="fas fa-cog text-primary"></i> Configurações</h6>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" <?php echo $prova['embaralhar_questoes'] ? 'checked' : ''; ?> disabled>
                            <label class="form-check-label">Embaralhar questões</label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" <?php echo $prova['embaralhar_alternativas'] ? 'checked' : ''; ?> disabled>
                            <label class="form-check-label">Embaralhar alternativas</label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" <?php echo $prova['mostrar_gabarito'] ? 'checked' : ''; ?> disabled>
                            <label class="form-check-label">Mostrar gabarito após correção</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>