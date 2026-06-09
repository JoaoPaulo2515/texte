<?php
// escola/professor/detalhes_aluno_prova.php - Detalhes da Prova do Aluno

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];
$professor_nome = $professor['professor_nome'] ?? 'Professor';

$tentativa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$tentativa_id) {
    header('Location: erro_prova.php?codigo=404&msg=tentativa_nao_encontrada');
    exit;
}

// ============================================
// BUSCAR DADOS DA TENTATIVA
// ============================================
$sql_tentativa = "SELECT 
                    t.*,
                    p.titulo as prova_titulo,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.duracao_minutos,
                    p.tipo as prova_tipo,
                    p.mostrar_gabarito,
                    d.nome as disciplina_nome,
                    d.cor as disciplina_cor,
                    e.id as aluno_id,
                    e.nome as aluno_nome,
                    e.matricula as aluno_matricula,
                    e.foto as aluno_foto,
                    e.bi as aluno_bi,
                    e.email as aluno_email,
                    e.telefone as aluno_telefone,
                    tur.nome as turma_nome,
                    tur.ano as turma_ano
                  FROM online_provas_tentativas t
                  JOIN online_provas p ON p.id = t.prova_id
                  JOIN disciplinas d ON d.id = p.disciplina_id
                  JOIN estudantes e ON e.id = t.aluno_id
                  JOIN turmas tur ON tur.id = p.turma_id
                  WHERE t.id = :tentativa_id";

$stmt_tentativa = $conn->prepare($sql_tentativa);
$stmt_tentativa->execute([':tentativa_id' => $tentativa_id]);
$tentativa = $stmt_tentativa->fetch(PDO::FETCH_ASSOC);

if (!$tentativa) {
    header('Location: erro_prova.php?codigo=404&msg=tentativa_nao_encontrada&id=' . $tentativa_id);
    exit;
}

// ============================================
// BUSCAR QUESTÕES E RESPOSTAS DO ALUNO
// ============================================
$sql_questoes = "SELECT 
                    q.id as questao_id,
                    q.enunciado,
                    q.tipo,
                    q.pontuacao as pontuacao_maxima,
                    q.gabarito,
                    q.dica,
                    q.imagem,
                    q.video_url,
                    r.id as resposta_id,
                    r.resposta_texto,
                    r.alternativa_id,
                    r.pontuacao_obtida,
                    r.correta,
                    r.comentario_professor
                  FROM online_provas_questoes q
                  LEFT JOIN online_provas_respostas r ON r.questao_id = q.id AND r.tentativa_id = :tentativa_id
                  WHERE q.prova_id = :prova_id
                  ORDER BY q.ordem ASC";

$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([
    ':tentativa_id' => $tentativa_id,
    ':prova_id' => $tentativa['prova_id']
]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALTERNATIVAS PARA CADA QUESTÃO
// ============================================
foreach ($questoes as &$questao) {
    if ($questao['tipo'] == 'multipla_escolha') {
        $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
        $stmt_alt = $conn->prepare($sql_alt);
        $stmt_alt->execute([':questao_id' => $questao['questao_id']]);
        $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar alternativa marcada pelo aluno
        if ($questao['alternativa_id']) {
            $sql_marcada = "SELECT texto FROM online_provas_alternativas WHERE id = :alt_id";
            $stmt_marcada = $conn->prepare($sql_marcada);
            $stmt_marcada->execute([':alt_id' => $questao['alternativa_id']]);
            $marcada = $stmt_marcada->fetch(PDO::FETCH_ASSOC);
            $questao['alternativa_marcada_texto'] = $marcada['texto'] ?? 'Nenhuma';
        } else {
            $questao['alternativa_marcada_texto'] = 'Não respondida';
        }
    } elseif ($questao['tipo'] == 'verdadeiro_falso') {
        $questao['alternativa_marcada_texto'] = $questao['alternativa_id'] ? 
            ($questao['alternativa_id'] == 1 ? 'Verdadeiro' : 'Falso') : 'Não respondida';
    } else {
        $questao['alternativas'] = [];
    }
}

// ============================================
// CALCULAR ESTATÍSTICAS DA TENTATIVA
// ============================================
$total_questoes = count($questoes);
$total_acertos = 0;
$total_pontos_obtidos = 0;
$total_pontos_maximos = 0;

foreach ($questoes as $questao) {
    $total_pontos_maximos += $questao['pontuacao_maxima'];
    $total_pontos_obtidos += $questao['pontuacao_obtida'] ?? 0;
    if ($questao['correta']) {
        $total_acertos++;
    }
}

$porcentagem_acerto = $total_questoes > 0 ? round(($total_acertos / $total_questoes) * 100, 1) : 0;
$porcentagem_nota = $total_pontos_maximos > 0 ? round(($total_pontos_obtidos / $total_pontos_maximos) * 100, 1) : 0;
$media_aprovacao = $tentativa['nota_maxima'] / 2;

// Formatar tempo
$tempo_formatado = '';
if ($tentativa['tempo_gasto_segundos'] > 0) {
    $horas = floor($tentativa['tempo_gasto_segundos'] / 3600);
    $minutos = floor(($tentativa['tempo_gasto_segundos'] % 3600) / 60);
    $segundos = $tentativa['tempo_gasto_segundos'] % 60;
    $tempo_formatado = sprintf("%02d:%02d:%02d", $horas, $minutos, $segundos);
}

// Funções auxiliares
function getStatusBadge($aprovado, $corrigida) {
    if (!$corrigida) {
        return '<span class="badge-status badge-pendente"><i class="fas fa-hourglass-half"></i> Pendente</span>';
    }
    if ($aprovado) {
        return '<span class="badge-status badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
    }
    return '<span class="badge-status badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
}

function getRespostaStatus($correta, $respondida = true) {
    if (!$respondida) {
        return '<span class="badge-status badge-nao-respondida"><i class="fas fa-question-circle"></i> Não respondida</span>';
    }
    if ($correta) {
        return '<span class="badge-status badge-correta"><i class="fas fa-check-circle"></i> Correta</span>';
    }
    return '<span class="badge-status badge-incorreta"><i class="fas fa-times-circle"></i> Incorreta</span>';
}

function getPontosClass($obtida, $maxima) {
    $percentual = $maxima > 0 ? ($obtida / $maxima) * 100 : 0;
    if ($percentual >= 80) return 'text-success fw-bold';
    if ($percentual >= 50) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Detalhes da Prova - <?php echo htmlspecialchars($tentativa['aluno_nome']); ?> | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; }
        
        .main-content { margin-left: 280px; margin-top: 60px; padding: 30px; min-height: calc(100vh - 60px); }
        @media (max-width: 768px) { .main-content { margin-left: 0; margin-top: 70px; padding: 20px; } }
        
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 24px; padding: 25px 30px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 300px; height: 300px; background: rgba(255,255,255,0.05); border-radius: 50%; }
        .page-header h4 { margin: 0; font-size: 1.5rem; font-weight: 700; position: relative; z-index: 1; }
        .page-header p { margin: 8px 0 0; opacity: 0.85; position: relative; z-index: 1; }
        
        .btn-voltar { background: rgba(255,255,255,0.2); color: white; border: none; padding: 8px 20px; border-radius: 30px; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .card-custom { background: white; border-radius: 24px; margin-bottom: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); overflow: hidden; transition: all 0.3s ease; }
        .card-custom:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
        .card-header-custom { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px 25px; font-weight: 700; border-bottom: 1px solid #e9ecef; color: #333; }
        .card-header-custom i { margin-right: 10px; color: #006B3E; }
        .card-body-custom { padding: 25px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 20px; padding: 25px; text-align: center; transition: all 0.3s ease; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .stat-icon { width: 60px; height: 60px; background: rgba(0,107,62,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; }
        .stat-icon i { font-size: 28px; color: #006B3E; }
        .stat-value { font-size: 2rem; font-weight: 800; margin-bottom: 5px; }
        .stat-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        
        .info-row { display: flex; flex-wrap: wrap; gap: 20px; padding: 12px 0; border-bottom: 1px solid #e9ecef; }
        .info-item { flex: 1; min-width: 200px; }
        .info-label { font-size: 0.7rem; text-transform: uppercase; color: #6c757d; font-weight: 600; margin-bottom: 5px; }
        .info-value { font-size: 1rem; font-weight: 600; color: #333; }
        
        .questao-card { background: white; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); overflow: hidden; transition: all 0.3s ease; border: 1px solid rgba(0,0,0,0.05); }
        .questao-card:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .questao-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .questao-badge { background: #006B3E; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
        .questao-tipo-badge { background: #17a2b8; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
        .questao-body { padding: 20px; }
        .questao-enunciado { font-size: 1rem; line-height: 1.7; color: #2c3e50; margin-bottom: 20px; background: #fafbfc; padding: 20px; border-radius: 16px; border-left: 3px solid #006B3E; }
        
        .alternativa-item { padding: 10px 15px; border: 2px solid #e9ecef; border-radius: 12px; display: flex; align-items: center; gap: 12px; margin-bottom: 10px; transition: all 0.3s ease; }
        .alternativa-marcada { background: #fff3cd; border-color: #ffc107; }
        .alternativa-correta-destaque { background: #d4edda; border-color: #28a745; }
        .alternativa-normal { background: white; }
        
        .badge-status { padding: 5px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .badge-aprovado { background: #28a745; color: white; }
        .badge-reprovado { background: #dc3545; color: white; }
        .badge-pendente { background: #ffc107; color: #333; }
        .badge-correta { background: #28a745; color: white; }
        .badge-incorreta { background: #dc3545; color: white; }
        .badge-nao-respondida { background: #6c757d; color: white; }
        
        .resposta-aluno-box { background: #e8f5e9; border-radius: 12px; padding: 15px; margin-top: 15px; border-left: 3px solid #28a745; }
        .gabarito-box { background: #f8f9fa; border-radius: 12px; padding: 15px; margin-top: 15px; border-left: 3px solid #17a2b8; }
        .comentario-box { background: #e3f2fd; border-radius: 12px; padding: 15px; margin-top: 15px; border-left: 3px solid #2196f3; }
        
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; } .questao-header { flex-direction: column; align-items: flex-start; } .info-item { min-width: 100%; } }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
        
        @media print { .main-content { margin-left: 0 !important; padding: 0 !important; } .btn-voltar, .menu-professor { display: none !important; } .questao-card { break-inside: avoid; page-break-inside: avoid; } }
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
                        <h4><i class="fas fa-user-graduate me-2"></i> Detalhes da Prova do Aluno</h4>
                        <p><?php echo htmlspecialchars($tentativa['prova_titulo']); ?> - <?php echo htmlspecialchars($tentativa['disciplina_nome']); ?></p>
                        <p class="small mb-0">Turma: <?php echo $tentativa['turma_ano'] . 'ª - ' . htmlspecialchars($tentativa['turma_nome']); ?></p>
                    </div>
                    <div>
                        <a href="resultados_prova.php?id=<?php echo $tentativa['prova_id']; ?>" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Estatísticas -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-value text-primary"><?php echo htmlspecialchars($tentativa['aluno_nome']); ?></div>
                    <div class="stat-label">Aluno</div>
                    <small class="text-muted"><?php echo $tentativa['aluno_matricula']; ?></small>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-value text-warning"><?php echo number_format($tentativa['pontuacao_total'], 1); ?></div>
                    <div class="stat-label">Nota Obtida</div>
                    <small>de <?php echo $tentativa['nota_maxima']; ?> pontos</small>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-value text-info"><?php echo $porcentagem_nota; ?>%</div>
                    <div class="stat-label">Aproveitamento</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-flag-checkered"></i></div>
                    <div class="stat-value"><?php echo getStatusBadge($tentativa['aprovado'], $tentativa['corrigida']); ?></div>
                    <div class="stat-label">Situação</div>
                </div>
            </div>
            
            <!-- Informações do Aluno -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-user-circle"></i> Informações do Aluno
                </div>
                <div class="card-body-custom">
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">Nome Completo</div>
                            <div class="info-value"><?php echo htmlspecialchars($tentativa['aluno_nome']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Matrícula</div>
                            <div class="info-value"><?php echo $tentativa['aluno_matricula']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">BI</div>
                            <div class="info-value"><?php echo $tentativa['aluno_bi'] ?? 'Não informado'; ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo $tentativa['aluno_email'] ?? 'Não informado'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Telefone</div>
                            <div class="info-value"><?php echo $tentativa['aluno_telefone'] ?? 'Não informado'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Turma</div>
                            <div class="info-value"><?php echo $tentativa['turma_ano'] . 'ª ' . htmlspecialchars($tentativa['turma_nome']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Informações da Prova -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-info-circle"></i> Informações da Prova
                </div>
                <div class="card-body-custom">
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">Data de Início</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($tentativa['data_inicio'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Data de Entrega</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($tentativa['data_entrega'])); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Tempo Gasto</div>
                            <div class="info-value"><?php echo $tempo_formatado; ?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">Tentativa</div>
                            <div class="info-value"><?php echo $tentativa['tentativa_numero']; ?>ª tentativa</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Nota Mínima</div>
                            <div class="info-value"><?php echo $tentativa['nota_minima_aprovacao']; ?> pontos</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value"><?php echo $tentativa['corrigida'] ? 'Corrigida' : 'Pendente'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Questões e Respostas -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-question-circle"></i> Questões e Respostas
                    <span class="badge bg-primary ms-2"><?php echo $total_questoes; ?> questões</span>
                </div>
                <div class="card-body-custom">
                    <?php foreach ($questoes as $index => $questao): ?>
                    <div class="questao-card">
                        <div class="questao-header">
                            <div class="d-flex gap-2 align-items-center">
                                <span class="questao-badge">Questão <?php echo $index + 1; ?></span>
                                <span class="questao-tipo-badge"><?php echo ucfirst(str_replace('_', ' ', $questao['tipo'])); ?></span>
                                <span class="badge bg-secondary"><?php echo $questao['pontuacao_maxima']; ?> pts</span>
                            </div>
                            <div>
                                <?php echo getRespostaStatus($questao['correta'], $questao['resposta_id']); ?>
                                <span class="ms-2 <?php echo getPontosClass($questao['pontuacao_obtida'], $questao['pontuacao_maxima']); ?>">
                                    <?php echo number_format($questao['pontuacao_obtida'], 1); ?> / <?php echo $questao['pontuacao_maxima']; ?> pts
                                </span>
                            </div>
                        </div>
                        <div class="questao-body">
                            <!-- Enunciado -->
                            <div class="questao-enunciado">
                                <?php echo $questao['enunciado']; ?>
                            </div>
                            
                            <!-- Para questões de múltipla escolha -->
                            <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                                <div class="mt-3">
                                    <strong><i class="fas fa-list-ul"></i> Alternativas:</strong>
                                    <?php foreach ($questao['alternativas'] as $alt): ?>
                                        <?php 
                                            $is_marcada = ($alt['id'] == $questao['alternativa_id']);
                                            $is_correta = $alt['correta'];
                                            $classe = '';
                                            if ($is_marcada && $is_correta) $classe = 'alternativa-correta-destaque';
                                            elseif ($is_marcada && !$is_correta) $classe = 'alternativa-marcada';
                                            else $classe = 'alternativa-normal';
                                        ?>
                                        <div class="alternativa-item <?php echo $classe; ?>">
                                            <span class="badge <?php echo $is_correta ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $is_correta ? '✓ Correta' : '○'; ?>
                                            </span>
                                            <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                            <?php if ($is_marcada): ?>
                                                <span class="badge bg-info ms-auto">Resposta do aluno</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Para questões dissertativas -->
                            <?php if ($questao['tipo'] == 'dissertativa' && $questao['resposta_texto']): ?>
                                <div class="resposta-aluno-box">
                                    <strong><i class="fas fa-user-edit"></i> Resposta do Aluno:</strong>
                                    <div class="mt-2 p-2 bg-white rounded">
                                        <?php echo nl2br(htmlspecialchars($questao['resposta_texto'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Gabarito (se incorreto) -->
                            <?php if (!$questao['correta'] && $questao['gabarito'] && $tentativa['mostrar_gabarito']): ?>
                                <div class="gabarito-box">
                                    <strong><i class="fas fa-lightbulb"></i> Gabarito:</strong>
                                    <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($questao['gabarito'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Comentário do Professor -->
                            <?php if ($questao['comentario_professor']): ?>
                                <div class="comentario-box">
                                    <strong><i class="fas fa-comment-dots"></i> Comentário do Professor:</strong>
                                    <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($questao['comentario_professor'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Dica -->
                            <?php if ($questao['dica']): ?>
                                <div class="mt-2 p-2 bg-light rounded">
                                    <small><i class="fas fa-lightbulb text-warning"></i> <strong>Dica:</strong> <?php echo htmlspecialchars($questao['dica']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Resumo Final -->
            <div class="card-custom fade-in">
                <div class="card-header-custom">
                    <i class="fas fa-chart-bar"></i> Resumo da Correção
                </div>
                <div class="card-body-custom">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="stat-value text-primary"><?php echo $total_questoes; ?></div>
                                <div class="stat-label">Total de Questões</div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="stat-value text-success"><?php echo $total_acertos; ?></div>
                                <div class="stat-label">Acertos</div>
                                <small><?php echo $porcentagem_acerto; ?>% de acerto</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="stat-value text-warning"><?php echo number_format($tentativa['pontuacao_total'], 1); ?></div>
                                <div class="stat-label">Nota Final</div>
                                <small>de <?php echo $tentativa['nota_maxima']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>