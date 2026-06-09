<?php
// escola/aluno/provas/resultado_prova.php - Resultado da Prova

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$tentativa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar resultado da tentativa
$sql = "SELECT t.*, p.titulo, p.nota_maxima, p.nota_minima_aprovacao, p.mostrar_gabarito,
               d.nome as disciplina_nome
        FROM online_provas_tentativas t
        JOIN online_provas p ON p.id = t.prova_id
        JOIN disciplinas d ON d.id = p.disciplina_id
        WHERE t.id = :tentativa_id AND t.aluno_id = :aluno_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':tentativa_id' => $tentativa_id, ':aluno_id' => $aluno_id]);
$resultado = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resultado) {
    die("Resultado não encontrado.");
}

// Buscar respostas detalhadas
$sql = "SELECT r.*, q.enunciado, q.pontuacao, q.tipo
        FROM online_provas_respostas r
        JOIN online_provas_questoes q ON q.id = r.questao_id
        WHERE r.tentativa_id = :tentativa_id AND r.aluno_id = :aluno_id
        ORDER BY q.ordem ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([':tentativa_id' => $tentativa_id, ':aluno_id' => $aluno_id]);
$respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultado da Prova - <?php echo htmlspecialchars($resultado['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .result-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .nota {
            font-size: 4em;
            font-weight: bold;
        }
        .aprovado { color: #28a745; }
        .reprovado { color: #dc3545; }
        .questao-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .questao-card:hover {
            transform: translateY(-2px);
        }
        .correta { border-left: 5px solid #28a745; }
        .incorreta { border-left: 5px solid #dc3545; }
        .badge-pontos {
            background: #006B3E;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8em;
        }
        .btn-voltar {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
        }
        .btn-voltar:hover {
            opacity: 0.9;
            color: white;
        }
        .tempo-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            display: inline-block;
        }
    </style>
</head>
<body>
  <?php include 'includes/menu_aluno.php'; ?> 
</br> </br> </br>
<div class="container mt-4 mb-5">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="result-card">
                <i class="fas fa-file-alt fa-3x text-primary mb-3"></i>
                <h2><?php echo htmlspecialchars($resultado['titulo']); ?></h2>
                <p class="text-muted"><?php echo htmlspecialchars($resultado['disciplina_nome']); ?></p>
                
                <div class="nota <?php echo $resultado['aprovado'] ? 'aprovado' : 'reprovado'; ?>">
                    <?php echo number_format($resultado['pontuacao_total'], 1); ?> <small>/ <?php echo $resultado['nota_maxima']; ?></small>
                </div>
                
                <div class="progress mt-3 mb-3" style="height: 10px; border-radius: 5px;">
                    <div class="progress-bar <?php echo $resultado['aprovado'] ? 'bg-success' : 'bg-danger'; ?>" 
                         role="progressbar" 
                         style="width: <?php echo $resultado['porcentagem']; ?>%;" 
                         aria-valuenow="<?php echo $resultado['porcentagem']; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100"></div>
                </div>
                
                <p><strong>Porcentagem:</strong> <?php echo number_format($resultado['porcentagem'], 1); ?>%</p>
                
                <div class="alert <?php echo $resultado['aprovado'] ? 'alert-success' : 'alert-danger'; ?>">
                    <?php if ($resultado['aprovado']): ?>
                        <i class="fas fa-check-circle"></i> Parabéns! Você foi aprovado!
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle"></i> Você não atingiu a nota mínima de <?php echo $resultado['nota_minima_aprovacao']; ?> pontos.
                    <?php endif; ?>
                </div>
                
                <div class="tempo-info">
                    <i class="fas fa-clock"></i> Tempo gasto: 
                    <?php echo floor($resultado['tempo_gasto_segundos'] / 60); ?> minutos e 
                    <?php echo $resultado['tempo_gasto_segundos'] % 60; ?> segundos
                </div>
            </div>
            
            <h4 class="mt-4 mb-3"><i class="fas fa-list-check"></i> Detalhamento das Questões</h4>
            
            <?php foreach ($respostas as $index => $resposta): ?>
            <div class="questao-card <?php echo $resposta['correta'] ? 'correta' : 'incorreta'; ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <h5 class="mb-2">Questão <?php echo $index + 1; ?></h5>
                    <span class="badge-pontos">
                        <?php echo $resposta['pontuacao_obtida']; ?> / <?php echo $resposta['pontuacao']; ?> pts
                    </span>
                </div>
                <p class="mb-3"><?php echo nl2br(htmlspecialchars($resposta['enunciado'])); ?></p>
                
                <div class="mt-2">
                    <?php if ($resposta['correta']): ?>
                        <span class="text-success"><i class="fas fa-check-circle"></i> Resposta correta</span>
                    <?php else: ?>
                        <span class="text-danger"><i class="fas fa-times-circle"></i> Resposta incorreta</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($resultado['mostrar_gabarito'] && !$resposta['correta'] && $resposta['tipo'] != 'dissertativa'): ?>
                <div class="mt-2 p-2 bg-light rounded">
                    <small class="text-muted">Gabarito: 
                        <?php
                        if ($resposta['tipo'] == 'multipla_escolha') {
                            $sql = "SELECT texto FROM online_provas_alternativas WHERE questao_id = :questao_id AND correta = 1 LIMIT 1";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([':questao_id' => $resposta['questao_id']]);
                            $gabarito = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($gabarito['texto'] ?? 'Não disponível');
                        } elseif ($resposta['tipo'] == 'verdadeiro_falso') {
                            echo 'Verdadeiro';
                        }
                        ?>
                    </small>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <div class="text-center mt-4">
                <a href="listar_provas.php" class="btn btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar para Provas
                </a>
                <a href="historico_provas.php" class="btn btn-outline-secondary ms-2">
                    <i class="fas fa-history"></i> Ver Histórico
                </a>
                <button onclick="window.print();" class="btn btn-outline-primary ms-2">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>