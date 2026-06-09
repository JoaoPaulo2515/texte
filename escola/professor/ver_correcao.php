<?php
// escola/professor/ver_correcao.php - Visualizar Correção da Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];

// Receber parâmetros
$tentativa_id = isset($_GET['tentativa_id']) ? (int)$_GET['tentativa_id'] : 0;

if (!$tentativa_id) {
    die('Tentativa não informada.');
}

// Verificar se o professor tem permissão para visualizar esta correção
$sql_verifica = "SELECT p.id 
                 FROM online_provas_tentativas t
                 JOIN online_provas p ON p.id = t.prova_id
                 WHERE t.id = :tentativa_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([
    ':tentativa_id' => $tentativa_id,
    ':funcionario_id' => $funcionario_id,
    ':escola_id' => $escola_id
]);

if (!$stmt_verifica->fetch()) {
    die('Você não tem permissão para visualizar esta correção.');
}

// Buscar dados da tentativa
$sql_tentativa = "SELECT 
                    t.*, 
                    e.nome as aluno_nome, 
                    e.matricula as aluno_matricula,
                    e.foto as aluno_foto,
                    e.bi as aluno_bi,
                    p.titulo as prova_titulo,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.duracao_minutos,
                    d.nome as disciplina_nome
                  FROM online_provas_tentativas t
                  JOIN estudantes e ON e.id = t.aluno_id
                  JOIN online_provas p ON p.id = t.prova_id
                  JOIN disciplinas d ON d.id = p.disciplina_id
                  WHERE t.id = :tentativa_id";
$stmt_tentativa = $conn->prepare($sql_tentativa);
$stmt_tentativa->execute([':tentativa_id' => $tentativa_id]);
$tentativa = $stmt_tentativa->fetch(PDO::FETCH_ASSOC);

if (!$tentativa) {
    die('Correção não encontrada.');
}

// Buscar questões da prova
$sql_questoes = "SELECT q.* 
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $tentativa['prova_id']]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar respostas e alternativas
foreach ($questoes as &$questao) {
    // Buscar resposta do aluno
    $sql_resposta = "SELECT * FROM online_provas_respostas 
                     WHERE tentativa_id = :tentativa_id AND questao_id = :questao_id";
    $stmt_resposta = $conn->prepare($sql_resposta);
    $stmt_resposta->execute([
        ':tentativa_id' => $tentativa_id,
        ':questao_id' => $questao['id']
    ]);
    $questao['resposta'] = $stmt_resposta->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alternativas
    if ($questao['tipo'] == 'multipla_escolha' || $questao['tipo'] == 'verdadeiro_falso') {
        $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
        $stmt_alt = $conn->prepare($sql_alt);
        $stmt_alt->execute([':questao_id' => $questao['id']]);
        $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $questao['alternativas'] = [];
    }
}

// Calcular tempo formatado
$tempo_formatado = '';
if ($tentativa['tempo_gasto_segundos'] > 0) {
    $horas = floor($tentativa['tempo_gasto_segundos'] / 3600);
    $minutos = floor(($tentativa['tempo_gasto_segundos'] % 3600) / 60);
    $segundos = $tentativa['tempo_gasto_segundos'] % 60;
    $tempo_formatado = sprintf("%02d:%02d:%02d", $horas, $minutos, $segundos);
} else {
    $tempo_formatado = '-';
}

// Status da correção
$status_corrigida = $tentativa['corrigida'] == 1;
$status_aprovado = $tentativa['aprovado'] == 1;
$porcentagem = $tentativa['porcentagem'] ?? 0;

// Definir classe e texto do status
if ($status_corrigida) {
    if ($status_aprovado) {
        $status_classe = 'success';
        $status_texto = 'Aprovado';
        $status_icone = 'check-circle';
    } else {
        $status_classe = 'danger';
        $status_texto = 'Reprovado';
        $status_icone = 'times-circle';
    }
} else {
    $status_classe = 'warning';
    $status_texto = 'Pendente';
    $status_icone = 'hourglass-half';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Ver Correção - <?php echo htmlspecialchars($tentativa['aluno_nome']); ?> | Professor</title>
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
           PAGE HEADER
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

        .info-card h6 {
            font-weight: 700;
            margin-bottom: 15px;
            color: #333;
        }

        /* ============================================
           STATS GRID
        ============================================ */
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
           QUESTÕES
        ============================================ */
        .questao-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .questao-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .questao-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .questao-badge {
            background: #006B3E;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-tipo-badge {
            background: #17a2b8;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-pontos-badge {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-body {
            padding: 20px;
        }

        .questao-enunciado {
            font-size: 1rem;
            line-height: 1.6;
            color: #333;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            border-left: 3px solid #006B3E;
        }

        /* ============================================
           RESPOSTAS E ALTERNATIVAS
        ============================================ */
        .resposta-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .resposta-correta {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }

        .resposta-errada {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }

        .alternativa-item {
            padding: 10px 15px;
            margin: 5px 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alternativa-correta-aluno {
            background: #d4edda;
            border: 1px solid #28a745;
        }

        .alternativa-correta-nao {
            background: #fff3cd;
            border: 1px solid #ffc107;
        }

        .alternativa-errada-aluno {
            background: #f8d7da;
            border: 1px solid #dc3545;
        }

        .alternativa-normal {
            background: white;
            border: 1px solid #e9ecef;
        }

        .badge-correta {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-errada {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .badge-nao-respondida {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        /* ============================================
           COMENTÁRIO DO PROFESSOR
        ============================================ */
        .comentario-box {
            background: #e8f5e9;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #006B3E;
        }

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

        .btn-imprimir {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-imprimir:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .questao-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .sidebar, .menu-professor {
                display: none !important;
            }
            .page-header {
                background: #006B3E;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .questao-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4><i class="fas fa-check-circle me-2"></i> Correção da Prova</h4>
                    <p><strong><?php echo htmlspecialchars($tentativa['prova_titulo']); ?></strong> - <?php echo htmlspecialchars($tentativa['disciplina_nome']); ?></p>
                </div>
                <div class="no-print">
                    <a href="corrigir_provas.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button onclick="window.print();" class="btn-imprimir ms-2">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
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
                <div class="stat-value text-info"><?php echo $porcentagem; ?>%</div>
                <div class="stat-label">Aproveitamento</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-flag-checkered"></i></div>
                <div class="stat-value text-<?php echo $status_classe; ?>">
                    <i class="fas fa-<?php echo $status_icone; ?>"></i>
                </div>
                <div class="stat-label">Situação</div>
                <small><?php echo $status_texto; ?></small>
            </div>
        </div>

        <!-- Informações da Prova -->
        <div class="info-card">
            <h6><i class="fas fa-info-circle text-primary me-2"></i> Informações da Prova</h6>
            <div class="row">
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Data de Entrega</small>
                    <div class="fw-bold"><?php echo date('d/m/Y H:i', strtotime($tentativa['data_entrega'])); ?></div>
                </div>
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Tempo Gasto</small>
                    <div class="fw-bold"><?php echo $tempo_formatado; ?></div>
                </div>
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Tentativa</small>
                    <div class="fw-bold"><?php echo $tentativa['tentativa_numero']; ?>ª tentativa</div>
                </div>
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Nota Mínima para Aprovação</small>
                    <div class="fw-bold"><?php echo $tentativa['nota_minima_aprovacao']; ?> pontos</div>
                </div>
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Duração da Prova</small>
                    <div class="fw-bold"><?php echo $tentativa['duracao_minutos']; ?> minutos</div>
                </div>
                <div class="col-md-4 mb-2">
                    <small class="text-muted">Status da Correção</small>
                    <div class="fw-bold">
                        <?php if ($tentativa['corrigida']): ?>
                            <span class="text-success"><i class="fas fa-check-circle"></i> Corrigida em <?php echo date('d/m/Y H:i', strtotime($tentativa['created_at'])); ?></span>
                        <?php else: ?>
                            <span class="text-warning"><i class="fas fa-hourglass-half"></i> Pendente</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Questões e Respostas -->
        <h5 class="mb-3"><i class="fas fa-question-circle text-primary me-2"></i> Questões da Prova</h5>
        
        <?php foreach ($questoes as $index => $questao): ?>
            <div class="questao-card">
                <div class="questao-header">
                    <div class="d-flex flex-wrap gap-2">
                        <span class="questao-badge">Questão <?php echo $index + 1; ?></span>
                        <span class="questao-tipo-badge">
                            <?php 
                                $tipos = [
                                    'multipla_escolha' => 'Múltipla Escolha',
                                    'verdadeiro_falso' => 'Verdadeiro ou Falso',
                                    'dissertativa' => 'Dissertativa'
                                ];
                                echo $tipos[$questao['tipo']] ?? $questao['tipo'];
                            ?>
                        </span>
                        <span class="questao-pontos-badge"><i class="fas fa-star"></i> <?php echo $questao['pontuacao']; ?> pontos</span>
                    </div>
                </div>
                <div class="questao-body">
                    <div class="questao-enunciado">
                        <?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?>
                    </div>
                    
                    <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                        <div class="mt-3">
                            <strong><i class="fas fa-list-ul me-1"></i> Alternativas:</strong>
                            <?php 
                                $resposta_aluno = $questao['resposta']['alternativa_id'] ?? null;
                                $alternativa_correta_id = null;
                                foreach ($questao['alternativas'] as $alt) {
                                    if ($alt['correta']) {
                                        $alternativa_correta_id = $alt['id'];
                                        break;
                                    }
                                }
                            ?>
                            <?php foreach ($questao['alternativas'] as $alt): ?>
                                <?php 
                                    $classe_alternativa = 'alternativa-normal';
                                    $icone = '';
                                    
                                    if ($resposta_aluno == $alt['id']) {
                                        if ($alt['correta']) {
                                            $classe_alternativa = 'alternativa-correta-aluno';
                                            $icone = '<i class="fas fa-check-circle text-success me-2"></i>';
                                        } else {
                                            $classe_alternativa = 'alternativa-errada-aluno';
                                            $icone = '<i class="fas fa-times-circle text-danger me-2"></i>';
                                        }
                                    } elseif ($alt['correta'] && $resposta_aluno != $alt['id']) {
                                        $classe_alternativa = 'alternativa-correta-nao';
                                        $icone = '<i class="fas fa-star text-warning me-2"></i>';
                                    }
                                ?>
                                <div class="alternativa-item <?php echo $classe_alternativa; ?>">
                                    <?php echo $icone; ?>
                                    <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                    <?php if ($alt['correta']): ?>
                                        <span class="badge-correta ms-auto"><i class="fas fa-check"></i> Correta</span>
                                    <?php endif; ?>
                                    <?php if ($resposta_aluno == $alt['id'] && !$alt['correta']): ?>
                                        <span class="badge-errada ms-auto"><i class="fas fa-times"></i> Sua resposta</span>
                                    <?php endif; ?>
                                    <?php if ($resposta_aluno == $alt['id'] && $alt['correta']): ?>
                                        <span class="badge-correta ms-auto"><i class="fas fa-check-circle"></i> Acertou!</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$resposta_aluno): ?>
                                <div class="alternativa-item alternativa-normal">
                                    <i class="fas fa-question-circle text-secondary me-2"></i>
                                    <span>Não respondida</span>
                                    <span class="badge-nao-respondida ms-auto">Não respondeu</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    <?php elseif ($questao['tipo'] == 'verdadeiro_falso' && !empty($questao['alternativas'])): ?>
                        <div class="mt-3">
                            <strong><i class="fas fa-check-double me-1"></i> Resposta do Aluno:</strong>
                            <?php 
                                $resposta_aluno = $questao['resposta']['alternativa_id'] ?? null;
                                $resposta_texto = '';
                                $correta = false;
                                
                                foreach ($questao['alternativas'] as $alt) {
                                    if ($resposta_aluno == $alt['id']) {
                                        $resposta_texto = $alt['texto'];
                                        $correta = $alt['correta'];
                                        break;
                                    }
                                    if ($alt['correta']) {
                                        $resposta_correta_texto = $alt['texto'];
                                    }
                                }
                            ?>
                            <div class="resposta-box <?php echo $correta ? 'resposta-correta' : ($resposta_aluno ? 'resposta-errada' : ''); ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($resposta_aluno): ?>
                                        <?php if ($correta): ?>
                                            <i class="fas fa-check-circle text-success fa-2x"></i>
                                            <div>
                                                <span class="fw-bold text-success">Resposta Correta!</span><br>
                                                <span>O aluno respondeu <strong><?php echo $resposta_texto; ?></strong></span>
                                            </div>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-danger fa-2x"></i>
                                            <div>
                                                <span class="fw-bold text-danger">Resposta Incorreta</span><br>
                                                <span>O aluno respondeu <strong><?php echo $resposta_texto; ?></strong></span><br>
                                                <small class="text-muted">Resposta correta: <strong><?php echo $resposta_correta_texto; ?></strong></small>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="fas fa-question-circle text-secondary fa-2x"></i>
                                        <div>
                                            <span class="fw-bold text-secondary">Não respondida</span><br>
                                            <span class="text-muted">O aluno não respondeu esta questão</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($questao['tipo'] == 'dissertativa'): ?>
                        <div class="mt-3">
                            <strong><i class="fas fa-pen me-1"></i> Resposta do Aluno:</strong>
                            <div class="resposta-box">
                                <?php 
                                    $resposta_texto = $questao['resposta']['resposta_texto'] ?? '';
                                    $pontuacao_obtida = $questao['resposta']['pontuacao_obtida'] ?? 0;
                                ?>
                                <?php if ($resposta_texto): ?>
                                    <div class="mb-2"><?php echo nl2br(htmlspecialchars($resposta_texto)); ?></div>
                                    <?php if ($pontuacao_obtida > 0): ?>
                                        <div class="mt-2">
                                            <span class="badge bg-success">Pontuação: <?php echo $pontuacao_obtida; ?> / <?php echo $questao['pontuacao']; ?> pontos</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2">
                                            <span class="badge bg-secondary">Aguardando correção</span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-muted">
                                        <i class="fas fa-comment-slash"></i> Nenhuma resposta fornecida
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Comentário do Professor -->
        <?php if ($tentativa['comentario']): ?>
            <div class="info-card">
                <h6><i class="fas fa-comment-dots text-primary me-2"></i> Comentário do Professor</h6>
                <div class="comentario-box">
                    <i class="fas fa-quote-left text-muted me-1"></i>
                    <?php echo nl2br(htmlspecialchars($tentativa['comentario'])); ?>
                    <i class="fas fa-quote-right text-muted ms-1"></i>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Resumo Final -->
        <div class="info-card">
            <h6><i class="fas fa-chart-bar text-primary me-2"></i> Resumo da Correção</h6>
            <div class="row">
                <div class="col-md-4 mb-2">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="stat-value text-primary"><?php echo count($questoes); ?></div>
                        <div class="stat-label">Total de Questões</div>
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="stat-value text-success"><?php echo number_format($tentativa['pontuacao_total'], 1); ?></div>
                        <div class="stat-label">Nota Final</div>
                        <small>de <?php echo $tentativa['nota_maxima']; ?></small>
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="stat-value text-<?php echo $status_classe; ?>"><?php echo $porcentagem; ?>%</div>
                        <div class="stat-label">Aproveitamento</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>