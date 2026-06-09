<?php
// escola/professor/provas/publicar_prova.php - Publicar Prova Online

require_once __DIR__ . '/../../config/database.php';
session_start();

$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';


$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];
$professor_nome = $professor['professor_nome'] ?? 'Professor';


$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([
    ':prova_id' => $prova_id, 
    ':funcionario_id' => $funcionario_id, 
    ':escola_id' => $escola_id
]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    // Redirecionar para página de erro com o ID da prova
    header('Location: erro_prova.php?codigo=404&msg=prova_nao_encontrada&id=' . $prova_id);
    exit;
}

// Verificar se já está publicada
if ($prova['status'] == 'publicada') {
    header('Location: listar_provas.php?msg=prova_ja_publicada');
    exit;
}

// Buscar questões da prova
$sql_questoes = "SELECT COUNT(*) as total FROM online_provas_questoes WHERE prova_id = :prova_id";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$total_questoes = $stmt_questoes->fetch(PDO::FETCH_ASSOC)['total'];

// Calcular total de pontos
$sql_pontos = "SELECT SUM(pontuacao) as total FROM online_provas_questoes WHERE prova_id = :prova_id";
$stmt_pontos = $conn->prepare($sql_pontos);
$stmt_pontos->execute([':prova_id' => $prova_id]);
$total_pontos = $stmt_pontos->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Processar publicação
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmar = isset($_POST['confirmar']) ? true : false;
    
    if (!$confirmar) {
        $erro = 'Confirme que deseja publicar a prova.';
    } elseif ($total_questoes == 0) {
        $erro = 'Não é possível publicar uma prova sem questões. Adicione pelo menos uma questão.';
    } else {
        try {
            $sql = "UPDATE online_provas SET status = 'publicada', updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $prova_id]);
            
            // Registrar log de publicação
            $sql_log = "INSERT INTO provas_log (prova_id, usuario_id, acao, data_acao, ip_address) 
                        VALUES (:prova_id, :usuario_id, 'publicada', NOW(), :ip)";
            $stmt_log = $conn->prepare($sql_log);
            $stmt_log->execute([
                ':prova_id' => $prova_id,
                ':usuario_id' => $professor_id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            $sucesso = 'Prova publicada com sucesso! Os alunos já podem visualizá-la.';
            
            header('refresh:3;url=listar_provas.php');
            
        } catch (PDOException $e) {
            $erro = 'Erro ao publicar prova: ' . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Publicar Prova - <?php echo htmlspecialchars($prova['titulo']); ?> | Professor</title>
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
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
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
           CONTAINER
        ============================================ */
        .publish-container {
            max-width: 900px;
            margin: 0 auto;
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
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .page-header h4 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
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
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           CARDS
        ============================================ */
        .card-custom {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 25px;
            font-weight: 700;
            border-bottom: 1px solid #e9ecef;
            color: #333;
        }

        .card-header-custom i {
            margin-right: 10px;
            color: #006B3E;
        }

        .card-body-custom {
            padding: 25px;
        }

        /* ============================================
           INFO BOXES
        ============================================ */
        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
        }

        .date-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }

        .date-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .date-icon {
            width: 60px;
            height: 60px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }

        .date-icon i {
            font-size: 28px;
            color: #006B3E;
        }

        .date-title {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #6c757d;
        }

        .date-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #333;
        }

        /* ============================================
           CONFIG BOXES
        ============================================ */
        .config-item {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .config-item:hover {
            transform: translateY(-3px);
            background: #e9ecef;
        }

        .config-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .config-title {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .badge-custom-success {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-custom-secondary {
            background: #6c757d;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ============================================
           QUESTÕES CARD
        ============================================ */
        .questoes-count {
            text-align: center;
            padding: 20px;
        }

        .questoes-number {
            font-size: 3rem;
            font-weight: 800;
            color: #006B3E;
        }

        .warning-box {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            padding: 15px 20px;
            border-radius: 12px;
            margin-top: 15px;
        }

        /* ============================================
           PONTUAÇÃO CARD
        ============================================ */
        .pontos-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .pontos-item:hover {
            transform: translateY(-3px);
            background: #e9ecef;
        }

        .pontos-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .pontos-value {
            font-size: 1.5rem;
            font-weight: 800;
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            border: none;
        }

        .alert-success-custom {
            background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%);
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .alert-danger-custom {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border-left: 4px solid #dc3545;
            color: #721c24;
        }

        .alert-warning-custom {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);
            border-left: 4px solid #ffc107;
            color: #856404;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-publicar {
            background: linear-gradient(135deg, #28a745 0%, #006B3E 100%);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-publicar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
        }

        .btn-secondary-custom {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary-custom:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }

        .btn-info-custom {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-info-custom:hover {
            background: #138496;
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           FORM CHECK
        ============================================ */
        .form-check-input {
            width: 1.2rem;
            height: 1.2rem;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #006B3E;
            border-color: #006B3E;
        }

        .form-check-label {
            font-weight: 500;
            cursor: pointer;
            margin-left: 8px;
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
            .date-box {
                margin-bottom: 15px;
            }
            
            .config-item {
                margin-bottom: 15px;
            }
            
            .pontos-item {
                margin-bottom: 15px;
            }
            
            .btn-publicar, .btn-secondary-custom, .btn-info-custom {
                width: 100%;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    
   <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
</br>
    <div class="main-content">
        <div class="publish-container">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-check-circle me-2"></i> Publicar Prova</h4>
                        <p>Confirme os dados antes de publicar a prova para os alunos</p>
                    </div>
                    <div>
                        <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($sucesso): ?>
                <div class="alert-custom alert-success-custom fade-in">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-check-circle fa-3x"></i>
                        <div>
                            <h5 class="mb-1"><?php echo $sucesso; ?></h5>
                            <p class="mb-0">Redirecionando para a lista de provas...</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($erro): ?>
                <div class="alert-custom alert-danger-custom fade-in">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-exclamation-triangle fa-3x"></i>
                        <div>
                            <h5 class="mb-1">Erro ao publicar</h5>
                            <p class="mb-0"><?php echo $erro; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Informações da Prova -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-info-circle"></i> Informações da Prova
                </div>
                <div class="card-body-custom">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Título</div>
                            <div class="info-value"><?php echo htmlspecialchars($prova['titulo']); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Disciplina</div>
                            <div class="info-value"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Turma</div>
                            <div class="info-value"><?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Tipo</div>
                            <div class="info-value"><?php echo ucfirst($prova['tipo']); ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Duração</div>
                            <div class="info-value"><i class="fas fa-clock me-1"></i> <?php echo $prova['duracao_minutos']; ?> minutos</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="info-label">Tentativas Permitidas</div>
                            <div class="info-value"><i class="fas fa-redo-alt me-1"></i> <?php echo $prova['tentativas_permitidas']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Datas -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-calendar-alt"></i> Período de Realização
                </div>
                <div class="card-body-custom">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="date-box">
                                <div class="date-icon">
                                    <i class="fas fa-play-circle"></i>
                                </div>
                                <div class="date-title">Data de Início</div>
                                <div class="date-value"><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="date-box">
                                <div class="date-icon">
                                    <i class="fas fa-stop-circle"></i>
                                </div>
                                <div class="date-title">Data de Término</div>
                                <div class="date-value"><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configurações -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-sliders-h"></i> Configurações da Prova
                </div>
                <div class="card-body-custom">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="config-item">
                                <div class="config-icon">
                                    <i class="fas fa-random"></i>
                                </div>
                                <div class="config-title">Embaralhar Questões</div>
                                <span class="<?php echo $prova['embaralhar_questoes'] ? 'badge-custom-success' : 'badge-custom-secondary'; ?>">
                                    <?php echo $prova['embaralhar_questoes'] ? 'Sim' : 'Não'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="config-item">
                                <div class="config-icon">
                                    <i class="fas fa-random"></i>
                                </div>
                                <div class="config-title">Embaralhar Alternativas</div>
                                <span class="<?php echo $prova['embaralhar_alternativas'] ? 'badge-custom-success' : 'badge-custom-secondary'; ?>">
                                    <?php echo $prova['embaralhar_alternativas'] ? 'Sim' : 'Não'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="config-item">
                                <div class="config-icon">
                                    <i class="fas fa-eye"></i>
                                </div>
                                <div class="config-title">Mostrar Gabarito</div>
                                <span class="<?php echo $prova['mostrar_gabarito'] ? 'badge-custom-success' : 'badge-custom-secondary'; ?>">
                                    <?php echo $prova['mostrar_gabarito'] ? 'Sim' : 'Não'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Questões -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-question-circle"></i> Questões
                </div>
                <div class="card-body-custom">
                    <div class="questoes-count">
                        <div class="questoes-number"><?php echo $total_questoes; ?></div>
                        <p class="text-muted">Questões cadastradas</p>
                        <?php if ($total_questoes == 0): ?>
                            <div class="warning-box">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                <strong>Atenção!</strong> Você precisa adicionar pelo menos uma questão antes de publicar.
                            </div>
                            <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn-publicar mt-3">
                                <i class="fas fa-plus"></i> Adicionar Questões
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pontuação -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-star"></i> Pontuação
                </div>
                <div class="card-body-custom">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="pontos-item">
                                <div class="pontos-label">Nota Máxima</div>
                                <div class="pontos-value text-success"><?php echo $prova['nota_maxima']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="pontos-item">
                                <div class="pontos-label">Nota Mínima</div>
                                <div class="pontos-value text-warning"><?php echo $prova['nota_minima_aprovacao']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="pontos-item">
                                <div class="pontos-label">Total Pontos</div>
                                <div class="pontos-value text-primary"><?php echo number_format($total_pontos, 1); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Confirmação de Publicação -->
            <?php if ($total_questoes > 0 && !$sucesso): ?>
                <div class="card-custom fade-in">
                    <div class="card-header-custom" style="background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);">
                        <i class="fas fa-exclamation-triangle text-warning"></i> Atenção!
                    </div>
                    <div class="card-body-custom">
                        <div class="warning-box mb-3">
                            <p class="mb-2"><strong>Antes de publicar, verifique:</strong></p>
                            <ul class="mb-0">
                                <li>Todas as questões estão corretas?</li>
                                <li>As alternativas estão configuradas corretamente?</li>
                                <li>As datas de início e término estão corretas?</li>
                                <li>A pontuação de cada questão está definida?</li>
                            </ul>
                        </div>
                        
                        <p class="text-danger text-center mb-3">
                            <i class="fas fa-lock me-2"></i> 
                            <strong>Após publicar, a prova não poderá mais ser editada!</strong>
                        </p>
                        
                        <form method="POST" class="text-center">
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="confirmar" name="confirmar" required>
                                <label class="form-check-label" for="confirmar">
                                    Confirmo que desejo publicar esta prova para os alunos
                                </label>
                            </div>
                            
                            <div class="d-flex justify-content-center gap-3 flex-wrap">
                                <button type="submit" class="btn-publicar">
                                    <i class="fas fa-check-circle"></i> Publicar Prova
                                </button>
                                <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn-secondary-custom">
                                    <i class="fas fa-arrow-left"></i> Voltar e Editar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Botão de pré-visualização -->
            <?php if ($total_questoes > 0): ?>
                <div class="text-center mt-3 fade-in">
                    <a href="previsualizar_prova.php?id=<?php echo $prova_id; ?>" class="btn-info-custom" target="_blank">
                        <i class="fas fa-eye"></i> Pré-visualizar Prova
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Animações ao rolar
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
        
        document.querySelectorAll('.card-custom, .page-header, .alert-custom').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>