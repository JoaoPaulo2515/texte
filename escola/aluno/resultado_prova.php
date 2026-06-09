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

// Calcular a média da nota máxima (50% da nota máxima)
$nota_maxima = $resultado['nota_maxima'];
$media_aprovacao = $nota_maxima / 2;
$nota_obtida = $resultado['pontuacao_total'];

// Determinar se foi aprovado
$aprovado = ($nota_obtida >= $media_aprovacao);

// Buscar respostas detalhadas
$sql = "SELECT r.*, q.enunciado, q.pontuacao, q.tipo, q.gabarito, q.dica, q.id as questao_id
        FROM online_provas_respostas r
        JOIN online_provas_questoes q ON q.id = r.questao_id
        WHERE r.tentativa_id = :tentativa_id AND r.aluno_id = :aluno_id
        ORDER BY q.ordem ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([':tentativa_id' => $tentativa_id, ':aluno_id' => $aluno_id]);
$respostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão
foreach ($respostas as &$resposta) {
    // Buscar todas as alternativas da questão
    $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
    $stmt_alt = $conn->prepare($sql_alt);
    $stmt_alt->execute([':questao_id' => $resposta['questao_id']]);
    $resposta['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar o texto da alternativa marcada
    if ($resposta['tipo'] == 'multipla_escolha' && $resposta['alternativa_id']) {
        $sql_marcada = "SELECT texto FROM online_provas_alternativas WHERE id = :alt_id";
        $stmt_marcada = $conn->prepare($sql_marcada);
        $stmt_marcada->execute([':alt_id' => $resposta['alternativa_id']]);
        $marcada = $stmt_marcada->fetch(PDO::FETCH_ASSOC);
        $resposta['alternativa_marcada'] = $marcada['texto'] ?? 'Nenhuma';
    } elseif ($resposta['tipo'] == 'verdadeiro_falso') {
        $resposta['alternativa_marcada'] = $resposta['resposta_boolean'] == 1 ? 'Verdadeiro' : 'Falso';
    } else {
        $resposta['alternativa_marcada'] = null;
    }
}

// Calcular total de pontos possíveis
$total_pontos_possiveis = array_sum(array_column($respostas, 'pontuacao'));

// Função para formatar tempo
function formatarTempo($segundos) {
    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);
    $seg = $segundos % 60;
    
    if ($horas > 0) {
        return sprintf("%02d:%02d:%02d", $horas, $minutos, $seg);
    }
    return sprintf("%02d:%02d", $minutos, $seg);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Resultado da Prova - <?php echo htmlspecialchars($resultado['titulo']); ?> | Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; }
        
        .main-content { margin-left: 280px; margin-top: 60px; padding: 30px; min-height: calc(100vh - 60px); }
        @media (max-width: 768px) { .main-content { margin-left: 0; margin-top: 70px; padding: 20px; } }
        
        .result-container { max-width: 900px; margin: 0 auto; }
        
        .result-card { background: white; border-radius: 24px; padding: 35px; text-align: center; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); transition: all 0.3s ease; }
        .result-card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,0,0,0.15); }
        .result-icon { width: 80px; height: 80px; background: rgba(0,107,62,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .result-icon i { font-size: 40px; color: #006B3E; }
        .nota { font-size: 4rem; font-weight: 800; margin: 20px 0; }
        .nota small { font-size: 1.2rem; font-weight: 400; }
        .aprovado { color: #28a745; }
        .reprovado { color: #dc3545; }
        .progress-custom { height: 12px; background: #e9ecef; border-radius: 10px; overflow: hidden; margin: 20px 0; }
        .progress-bar-custom { height: 100%; border-radius: 10px; transition: width 0.5s ease; }
        .alert-custom { border-radius: 16px; padding: 15px 20px; margin: 20px 0; border: none; }
        .alert-success-custom { background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%); border-left: 4px solid #28a745; color: #155724; }
        .alert-danger-custom { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); border-left: 4px solid #dc3545; color: #721c24; }
        .tempo-info { background: #f8f9fa; border-radius: 12px; padding: 12px 20px; display: inline-flex; align-items: center; gap: 10px; margin-top: 15px; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .metric-card { background: white; border-radius: 20px; padding: 20px; text-align: center; transition: all 0.3s ease; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .metric-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .metric-icon { width: 50px; height: 50px; background: rgba(0,107,62,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .metric-icon i { font-size: 24px; color: #006B3E; }
        .metric-value { font-size: 1.5rem; font-weight: 800; margin-bottom: 5px; }
        .metric-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        
        .questoes-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; color: #1A2A6C; display: flex; align-items: center; gap: 10px; }
        .questao-card { background: white; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); transition: all 0.3s ease; border: 1px solid rgba(0,0,0,0.05); overflow: hidden; }
        .questao-card:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .correta { border-left: 4px solid #28a745; background: linear-gradient(135deg, #ffffff 0%, #f8fff8 100%); }
        .incorreta { border-left: 4px solid #dc3545; background: linear-gradient(135deg, #ffffff 0%, #fff8f8 100%); }
        .questao-header { padding: 20px 25px 0 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .questao-numero { font-weight: 700; color: #006B3E; font-size: 1rem; }
        .badge-pontos { background: #006B3E; color: white; padding: 5px 12px; border-radius: 30px; font-size: 0.75rem; font-weight: 600; }
        .resposta-status { display: inline-flex; align-items: center; gap: 8px; padding: 6px 15px; border-radius: 30px; font-size: 0.75rem; font-weight: 600; }
        .status-correta { background: #d4edda; color: #155724; }
        .status-incorreta { background: #f8d7da; color: #721c24; }
        .questao-body { padding: 20px 25px; }
        .questao-enunciado { font-size: 1rem; line-height: 1.7; color: #2c3e50; margin-bottom: 20px; background: #fafbfc; padding: 20px; border-radius: 16px; border-left: 3px solid #006B3E; }
        
        /* Estilos para alternativas */
        .alternativas-container { margin-top: 15px; }
        .alternativa-item { padding: 12px 15px; margin: 8px 0; border: 2px solid #e9ecef; border-radius: 12px; display: flex; align-items: center; gap: 12px; transition: all 0.3s ease; }
        .alternativa-correta { background: #d4edda; border-color: #28a745; }
        .alternativa-marcada { background: #fff3cd; border-color: #ffc107; border-left: 4px solid #ffc107; }
        .alternativa-marcada-errada { background: #f8d7da; border-color: #dc3545; border-left: 4px solid #dc3545; }
        .alternativa-normal { background: white; }
        .alternativa-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-correta { background: #28a745; color: white; }
        .badge-marcada { background: #ffc107; color: #333; }
        .badge-errada { background: #dc3545; color: white; }
        
        .resposta-aluno-box { background: #e8f5e9; border-radius: 12px; padding: 15px; margin-top: 15px; border-left: 3px solid #28a745; }
        .gabarito-box { background: #f8f9fa; border-radius: 12px; padding: 15px; margin-top: 15px; border-left: 3px solid #17a2b8; }
        
        .btn-voltar { background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%); color: white; border: none; padding: 12px 30px; border-radius: 40px; font-weight: 600; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-voltar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); color: white; }
        .btn-outline-custom { background: transparent; border: 2px solid #6c757d; color: #6c757d; padding: 12px 30px; border-radius: 40px; font-weight: 600; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-outline-custom:hover { background: #6c757d; color: white; transform: translateY(-2px); }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        
        @media (max-width: 768px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; } .result-card { padding: 25px; } .nota { font-size: 3rem; } }
        @media (max-width: 576px) { .metrics-grid { grid-template-columns: 1fr; } .questao-header { flex-direction: column; align-items: flex-start; } }
        @media print { .main-content { margin-left: 0 !important; padding: 0 !important; } .btn-voltar, .btn-outline-custom, .menu-aluno { display: none !important; } .result-card, .questao-card { break-inside: avoid; page-break-inside: avoid; } }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <div class="result-container">
            <!-- Card Principal de Resultado -->
            <div class="result-card fade-in">
                <div class="result-icon"><i class="fas fa-file-alt"></i></div>
                <h2><?php echo htmlspecialchars($resultado['titulo']); ?></h2>
                <p class="text-muted"><?php echo htmlspecialchars($resultado['disciplina_nome']); ?></p>
                
                <div class="nota <?php echo $aprovado ? 'aprovado' : 'reprovado'; ?>">
                    <?php echo number_format($nota_obtida, 1); ?> <small>/ <?php echo $nota_maxima; ?></small>
                </div>
                
                <div class="progress-custom">
                    <div class="progress-bar-custom <?php echo $aprovado ? 'bg-success' : 'bg-danger'; ?>" style="width: <?php echo ($nota_obtida / $nota_maxima) * 100; ?>%;"></div>
                </div>
                
                <p><strong>Porcentagem de acerto:</strong> <?php echo number_format(($nota_obtida / $nota_maxima) * 100, 1); ?>%</p>
                <p><strong>Nota mínima para aprovação (50%):</strong> <?php echo number_format($media_aprovacao, 1); ?> pontos</p>
                
                <div class="alert-custom <?php echo $aprovado ? 'alert-success-custom' : 'alert-danger-custom'; ?>">
                    <?php if ($aprovado): ?>
                        <i class="fas fa-check-circle"></i> Parabéns! Você foi aprovado! 
                        Sua nota (<?php echo number_format($nota_obtida, 1); ?>) é maior ou igual à média de <?php echo number_format($media_aprovacao, 1); ?> pontos.
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle"></i> Você não foi aprovado. 
                        Sua nota (<?php echo number_format($nota_obtida, 1); ?>) é inferior à média de <?php echo number_format($media_aprovacao, 1); ?> pontos.
                    <?php endif; ?>
                </div>
                
                <div class="tempo-info">
                    <i class="fas fa-clock"></i> Tempo gasto: <?php echo formatarTempo($resultado['tempo_gasto_segundos']); ?>
                </div>
            </div>
            
            <!-- Métricas -->
            <div class="metrics-grid fade-in">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-question-circle"></i></div>
                    <div class="metric-value text-primary"><?php echo count($respostas); ?></div>
                    <div class="metric-label">Total de Questões</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="metric-value text-success">
                        <?php 
                            $acertos = count(array_filter($respostas, function($r) { return $r['correta'] == 1; }));
                            echo $acertos;
                        ?>
                    </div>
                    <div class="metric-label">Acertos</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-times-circle"></i></div>
                    <div class="metric-value text-danger"><?php echo count($respostas) - $acertos; ?></div>
                    <div class="metric-label">Erros</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="metric-value text-warning"><?php echo round(($acertos / max(1, count($respostas))) * 100, 1); ?>%</div>
                    <div class="metric-label">Taxa de Acerto</div>
                </div>
            </div>
            
            <!-- Detalhamento das Questões -->
            <div class="questoes-title fade-in">
                <i class="fas fa-list-check"></i>
                <span>Detalhamento das Questões</span>
            </div>
            
            <?php foreach ($respostas as $index => $resposta): ?>
            <div class="questao-card <?php echo $resposta['correta'] ? 'correta' : 'incorreta'; ?> fade-in" style="animation-delay: <?php echo $index * 0.05; ?>s">
                <div class="questao-header">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="questao-numero"><i class="fas fa-hashtag"></i> Questão <?php echo $index + 1; ?></span>
                        <span class="badge-pontos"><i class="fas fa-star"></i> <?php echo $resposta['pontuacao_obtida']; ?> / <?php echo $resposta['pontuacao']; ?> pts</span>
                    </div>
                    <div class="resposta-status <?php echo $resposta['correta'] ? 'status-correta' : 'status-incorreta'; ?>">
                        <?php if ($resposta['correta']): ?>
                            <i class="fas fa-check-circle"></i> Resposta Correta
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i> Resposta Incorreta
                        <?php endif; ?>
                    </div>
                </div>
                <div class="questao-body">
                    <!-- Enunciado em HTML puro -->
                    <div class="questao-enunciado">
                        <?php echo $resposta['enunciado']; ?>
                    </div>
                    
                    <!-- Mostrar todas as alternativas para múltipla escolha -->
                    <?php if ($resposta['tipo'] == 'multipla_escolha' && !empty($resposta['alternativas'])): ?>
                        <div class="alternativas-container">
                            <strong><i class="fas fa-list-ul"></i> Alternativas:</strong>
                            <?php foreach ($resposta['alternativas'] as $alt): 
                                $is_marcada = ($alt['id'] == $resposta['alternativa_id']);
                                $is_correta = $alt['correta'];
                                
                                if ($is_marcada && $is_correta) {
                                    $classe = 'alternativa-marcada';
                                    $badge = '<span class="alternativa-badge badge-marcada ms-auto"><i class="fas fa-check-double"></i> Sua resposta (Correta!)</span>';
                                } elseif ($is_marcada && !$is_correta) {
                                    $classe = 'alternativa-marcada-errada';
                                    $badge = '<span class="alternativa-badge badge-errada ms-auto"><i class="fas fa-times"></i> Sua resposta</span>';
                                } elseif ($is_correta) {
                                    $classe = 'alternativa-correta';
                                    $badge = '<span class="alternativa-badge badge-correta ms-auto"><i class="fas fa-check"></i> Resposta Correta</span>';
                                } else {
                                    $classe = 'alternativa-normal';
                                    $badge = '';
                                }
                            ?>
                                <div class="alternativa-item <?php echo $classe; ?>">
                                    <span class="badge bg-secondary"><?php echo chr(65 + $alt['ordem']); ?></span>
                                    <span class="flex-grow-1"><?php echo htmlspecialchars($alt['texto']); ?></span>
                                    <?php echo $badge; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Mostrar todas as alternativas para verdadeiro/falso -->
                    <?php if ($resposta['tipo'] == 'verdadeiro_falso' && !empty($resposta['alternativas'])): ?>
                        <div class="alternativas-container">
                            <strong><i class="fas fa-check-double"></i> Opções:</strong>
                            <?php foreach ($resposta['alternativas'] as $alt): 
                                $is_marcada = ($alt['id'] == $resposta['alternativa_id']);
                                $is_correta = $alt['correta'];
                                
                                if ($is_marcada && $is_correta) {
                                    $classe = 'alternativa-marcada';
                                    $badge = '<span class="alternativa-badge badge-marcada ms-auto"><i class="fas fa-check-double"></i> Sua resposta (Correta!)</span>';
                                } elseif ($is_marcada && !$is_correta) {
                                    $classe = 'alternativa-marcada-errada';
                                    $badge = '<span class="alternativa-badge badge-errada ms-auto"><i class="fas fa-times"></i> Sua resposta</span>';
                                } elseif ($is_correta) {
                                    $classe = 'alternativa-correta';
                                    $badge = '<span class="alternativa-badge badge-correta ms-auto"><i class="fas fa-check"></i> Resposta Correta</span>';
                                } else {
                                    $classe = 'alternativa-normal';
                                    $badge = '';
                                }
                            ?>
                                <div class="alternativa-item <?php echo $classe; ?>">
                                    <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                    <?php echo $badge; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Mostrar resposta dissertativa -->
                    <?php if ($resposta['tipo'] == 'dissertativa' && $resposta['resposta_texto']): ?>
                        <div class="resposta-aluno-box">
                            <strong><i class="fas fa-user-edit"></i> Sua resposta:</strong>
                            <div class="mt-2 p-2 bg-white rounded">
                                <?php echo nl2br(htmlspecialchars($resposta['resposta_texto'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Gabarito (se habilitado e resposta incorreta) -->
                    <?php if ($resultado['mostrar_gabarito'] && !$resposta['correta'] && $resposta['gabarito']): ?>
                        <div class="gabarito-box">
                            <strong><i class="fas fa-lightbulb"></i> Gabarito:</strong>
                            <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($resposta['gabarito'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Dica -->
                    <?php if ($resposta['dica']): ?>
                        <div class="mt-2 p-2 bg-light rounded">
                            <small><i class="fas fa-lightbulb text-warning"></i> <strong>Dica:</strong> <?php echo htmlspecialchars($resposta['dica']); ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Botões de Ação -->
            <div class="text-center mt-4 fade-in">
                <a href="listar_provas.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar para Provas
                </a>
                <a href="historico_provas.php" class="btn-outline-custom ms-2">
                    <i class="fas fa-history"></i> Ver Histórico
                </a>
                <button onclick="window.print();" class="btn-outline-custom ms-2">
                    <i class="fas fa-print"></i> Imprimir Resultado
                </button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>