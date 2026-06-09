<?php
// escola/professor/provas/publicar_prova.php - Publicar Prova Online

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

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada ou você não tem permissão para publicá-la.');
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
            // Atualizar status da prova para 'publicada'
            $sql = "UPDATE online_provas SET status = 'publicada', updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $prova_id]);
            
            // Registrar log de publicação
            $sql_log = "INSERT INTO provas_log (prova_id, usuario_id, acao, data_acao, ip_address) 
                        VALUES (:prova_id, :usuario_id, 'publicada', NOW(), :ip)";
            $stmt_log = $conn->prepare($sql_log);
            $stmt_log->execute([
                ':prova_id' => $prova_id,
                ':usuario_id' => $usuario_id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            $sucesso = 'Prova publicada com sucesso! Os alunos já podem visualizá-la.';
            
            // Redirecionar após 3 segundos
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar Prova - <?php echo htmlspecialchars($prova['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        .btn-publicar {
            background: linear-gradient(135deg, #28a745, #006B3E);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
        }
        .btn-publicar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
        }
        .info-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 10px;
        }
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 10px;
        }
        .danger-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            border-radius: 10px;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
        }
    </style>
</head>
<body>

     <?php include '../includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <!-- Cabeçalho -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-check-circle"></i> Publicar Prova</h4>
                        <p class="text-muted mb-0">Confirme os dados antes de publicar</p>
                    </div>
                    <div>
                        <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>

                <?php if ($sucesso): ?>
                    <div class="success-box mb-4">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h5 class="mb-2"><?php echo $sucesso; ?></h5>
                        <p class="mb-0">Redirecionando para a lista de provas...</p>
                    </div>
                <?php endif; ?>

                <?php if ($erro): ?>
                    <div class="danger-box mb-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h5 class="mb-2">Erro ao publicar</h5>
                        <p class="mb-0"><?php echo $erro; ?></p>
                    </div>
                <?php endif; ?>

                <!-- Informações da Prova -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i> Informações da Prova
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="text-muted">Título</label>
                                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($prova['titulo']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted">Disciplina</label>
                                    <p class="mb-0"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted">Turma</label>
                                    <p class="mb-0"><?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="text-muted">Tipo</label>
                                    <p class="mb-0"><?php echo ucfirst($prova['tipo']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted">Duração</label>
                                    <p class="mb-0"><?php echo $prova['duracao_minutos']; ?> minutos</p>
                                </div>
                                <div class="mb-3">
                                    <label class="text-muted">Tentativas Permitidas</label>
                                    <p class="mb-0"><?php echo $prova['tentativas_permitidas']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Datas -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-calendar-alt"></i> Período de Realização
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-box text-center">
                                    <i class="fas fa-play-circle fa-2x text-success mb-2"></i>
                                    <h6>Data de Início</h6>
                                    <p class="mb-0 fw-bold"><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-box text-center">
                                    <i class="fas fa-stop-circle fa-2x text-danger mb-2"></i>
                                    <h6>Data de Término</h6>
                                    <p class="mb-0 fw-bold"><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Configurações -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-sliders-h"></i> Configurações
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="fas fa-random fa-2x text-info mb-2"></i>
                                    <p class="mb-0">Embaralhar Questões</p>
                                    <span class="badge <?php echo $prova['embaralhar_questoes'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $prova['embaralhar_questoes'] ? 'Sim' : 'Não'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="fas fa-random fa-2x text-info mb-2"></i>
                                    <p class="mb-0">Embaralhar Alternativas</p>
                                    <span class="badge <?php echo $prova['embaralhar_alternativas'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $prova['embaralhar_alternativas'] ? 'Sim' : 'Não'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <i class="fas fa-eye fa-2x text-info mb-2"></i>
                                    <p class="mb-0">Mostrar Gabarito</p>
                                    <span class="badge <?php echo $prova['mostrar_gabarito'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $prova['mostrar_gabarito'] ? 'Sim' : 'Não'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Questões -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-question-circle"></i> Questões
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <h2 class="display-4 text-primary"><?php echo $total_questoes; ?></h2>
                            <p class="text-muted">Questões cadastradas</p>
                            <?php if ($total_questoes == 0): ?>
                                <div class="warning-box mt-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Atenção!</strong> Você precisa adicionar pelo menos uma questão antes de publicar.
                                </div>
                                <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn btn-primary mt-3">
                                    <i class="fas fa-plus"></i> Adicionar Questões
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pontuação -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-star"></i> Pontuação
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <h4>Nota Máxima</h4>
                                <p class="display-6 text-success"><?php echo $prova['nota_maxima']; ?></p>
                            </div>
                            <div class="col-md-4">
                                <h4>Nota Mínima</h4>
                                <p class="display-6 text-warning"><?php echo $prova['nota_minima_aprovacao']; ?></p>
                            </div>
                            <div class="col-md-4">
                                <h4>Total Pontos</h4>
                                <p class="display-6 text-primary">--</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Confirmação de Publicação -->
                <?php if ($total_questoes > 0 && !$sucesso): ?>
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <i class="fas fa-exclamation-triangle"></i> Atenção!
                    </div>
                    <div class="card-body">
                        <div class="warning-box mb-3">
                            <p class="mb-2"><strong>Antes de publicar, verifique:</strong></p>
                            <ul class="mb-0">
                                <li>Todas as questões estão corretas?</li>
                                <li>As alternativas estão configuradas corretamente?</li>
                                <li>As datas de início e término estão corretas?</li>
                                <li>A pontuação de cada questão está definida?</li>
                            </ul>
                        </div>
                        
                        <p class="text-danger">
                            <i class="fas fa-lock"></i> 
                            <strong>Após publicar, a prova não poderá mais ser editada!</strong>
                        </p>
                        
                        <form method="POST" class="text-center mt-4">
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="confirmar" name="confirmar" required>
                                <label class="form-check-label" for="confirmar">
                                    Confirmo que desejo publicar esta prova para os alunos
                                </label>
                            </div>
                            
                            <button type="submit" class="btn-publicar">
                                <i class="fas fa-check-circle"></i> Publicar Prova
                            </button>
                            <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn btn-secondary ms-2">
                                <i class="fas fa-arrow-left"></i> Voltar e Editar
                            </a>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Botão de pré-visualização -->
                <?php if ($total_questoes > 0): ?>
                <div class="text-center mt-4">
                    <a href="previsualizar_prova.php?id=<?php echo $prova_id; ?>" class="btn btn-info" target="_blank">
                        <i class="fas fa-eye"></i> Pré-visualizar Prova
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>