<?php
// escola/aluno/provas/em_andamento.php - Provas em Andamento do Aluno

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Provas em Andamento';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR PROVAS EM ANDAMENTO
// ==============================================
$sql_provas = "SELECT 
                    t.id as tentativa_id,
                    t.prova_id,
                    t.tentativa_numero,
                    t.data_inicio,
                    t.tempo_gasto_segundos,
                    t.status as tentativa_status,
                    p.id as prova_id,
                    p.titulo,
                    p.descricao,
                    p.duracao_minutos,
                    p.data_fim,
                    p.nota_maxima,
                    d.nome as disciplina_nome,
                    prof.nome as professor_nome,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND aluno_id = :aluno_id1) as respostas_respondidas,
                    TIMESTAMPDIFF(SECOND, t.data_inicio, NOW()) as tempo_decorrido
                FROM online_provas_tentativas t
                JOIN online_provas p ON p.id = t.prova_id
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN funcionarios prof ON prof.id = p.professor_id
                WHERE t.aluno_id = :aluno_id2
                AND t.status = 'em_andamento'
                AND p.escola_id = :escola_id
                ORDER BY t.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$stmt_provas->execute([
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':escola_id' => $escola_id
]);
$provas_andamento = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// CALCULAR TEMPO RESTANTE PARA CADA PROVA
// ==============================================
foreach ($provas_andamento as &$prova) {
    $tempo_total_segundos = $prova['duracao_minutos'] * 60;
    $tempo_decorrido = $prova['tempo_decorrido'];
    $tempo_restante = $tempo_total_segundos - $tempo_decorrido;
    
    if ($tempo_restante <= 0) {
        $tempo_restante = 0;
        $prova['tempo_expirado'] = true;
    } else {
        $prova['tempo_expirado'] = false;
    }
    
    $prova['tempo_restante_segundos'] = $tempo_restante;
    $prova['tempo_restante_formatado'] = sprintf("%02d:%02d:%02d", 
        floor($tempo_restante / 3600), 
        floor(($tempo_restante % 3600) / 60), 
        $tempo_restante % 60);
    
    $prova['percentual_concluido'] = $prova['total_questoes'] > 0 
        ? round(($prova['respostas_respondidas'] / $prova['total_questoes']) * 100) 
        : 0;
}

// Estatísticas
$total_em_andamento = count($provas_andamento);
$total_questoes_respondidas = 0;
$total_questoes_possiveis = 0;

foreach ($provas_andamento as $prova) {
    $total_questoes_respondidas += $prova['respostas_respondidas'];
    $total_questoes_possiveis += $prova['total_questoes'];
}

$percentual_geral = $total_questoes_possiveis > 0 
    ? round(($total_questoes_respondidas / $total_questoes_possiveis) * 100) 
    : 0;

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
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
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
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
        
        .disciplina-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .timer-container {
            background: #dc3545;
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-weight: bold;
            font-family: monospace;
            font-size: 1.2em;
        }
        
        .timer-container.warning {
            background: #ffc107;
            color: #333;
        }
        
        .progress-bar-custom {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
        }
        
        .btn-continuar {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        .btn-continuar:hover {
            opacity: 0.9;
            color: white;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .timer-pulse {
            animation: pulse 1s infinite;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .btn-continuar, .menu-aluno { display: none; }
        }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Provas em Andamento</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todas as provas que você já iniciou mas ainda não finalizou.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Timer</div>
                <div class="ajuda-texto">O timer mostra o tempo restante para finalizar a prova. Quando chegar a zero, a prova será automaticamente finalizada.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Continuar</div>
                <div class="ajuda-texto">Clique em "Continuar" para retomar a prova de onde parou.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Progresso</div>
                <div class="ajuda-texto">A barra de progresso mostra quantas questões você já respondeu.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-hourglass-half"></i> Provas em Andamento</h4>
            <p class="text-muted mb-0">Continue suas provas não finalizadas</p>
        </div>
        <div>
            <a href="disponiveis.php" class="btn btn-primary">
                <i class="fas fa-list"></i> Ver Provas Disponíveis
            </a>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-hourglass-half"></i> Em Andamento</small>
                    <h6 class="mb-0"><?php echo $total_em_andamento; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_em_andamento; ?></div>
                <div class="stat-label"><i class="fas fa-hourglass-half text-warning"></i> Provas em Andamento</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_questoes_respondidas; ?> / <?php echo $total_questoes_possiveis; ?></div>
                <div class="stat-label"><i class="fas fa-question-circle text-primary"></i> Questões Respondidas</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $percentual_geral; ?>%</div>
                <div class="stat-label"><i class="fas fa-chart-line text-success"></i> Progresso Geral</div>
            </div>
        </div>
    </div>
    
    <!-- Lista de Provas em Andamento -->
    <?php if (empty($provas_andamento)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma prova em andamento</h5>
            <p>Você não tem provas iniciadas e não finalizadas no momento.</p>
            <a href="disponiveis.php" class="btn btn-primary mt-2">
                <i class="fas fa-play-circle"></i> Iniciar uma Prova
            </a>
        </div>
    <?php else: ?>
        <div class="provas-list">
            <?php foreach ($provas_andamento as $prova): 
                $timer_class = '';
                if ($prova['tempo_restante_segundos'] < 300 && $prova['tempo_restante_segundos'] > 0) {
                    $timer_class = 'warning timer-pulse';
                } elseif ($prova['tempo_restante_segundos'] <= 0) {
                    $timer_class = 'bg-secondary';
                } else {
                    $timer_class = '';
                }
                
                $tempo_restante_minutos = floor($prova['tempo_restante_segundos'] / 60);
                $tempo_restante_segundos = $prova['tempo_restante_segundos'] % 60;
            ?>
            <div class="prova-card fade-in">
                <div class="prova-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2 text-white-50">
                            <i class="fas fa-hashtag"></i> Tentativa <?php echo $prova['tentativa_numero']; ?>
                        </span>
                    </div>
                    <div class="timer-container <?php echo $timer_class; ?>">
                        <i class="fas fa-hourglass-half"></i>
                        <span id="timer-<?php echo $prova['tentativa_id']; ?>">
                            <?php echo sprintf("%02d:%02d:%02d", 
                                floor($prova['tempo_restante_segundos'] / 3600),
                                floor(($prova['tempo_restante_segundos'] % 3600) / 60),
                                $prova['tempo_restante_segundos'] % 60); ?>
                        </span>
                    </div>
                </div>
                
                <div class="prova-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))); ?></p>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-clock text-primary"></i>
                                        <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-question-circle text-primary"></i>
                                        <span>Progresso: <strong><?php echo $prova['respostas_respondidas']; ?> / <?php echo $prova['total_questoes']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-play-circle text-success"></i>
                                        <span>Iniciada em: <strong><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-chalkboard-user text-info"></i>
                                        <span>Professor: <strong><?php echo htmlspecialchars($prova['professor_nome']); ?></strong></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Progresso de respostas</small>
                                    <small><?php echo $prova['percentual_concluido']; ?>%</small>
                                </div>
                                <div class="progress-bar-custom">
                                    <div class="progress-fill" style="width: <?php echo $prova['percentual_concluido']; ?>%; background: linear-gradient(90deg, #1A2A6C, #006B3E);"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="mb-3">
                                <div class="display-4 mb-2">
                                    <?php if ($prova['tempo_restante_segundos'] > 0): ?>
                                        <i class="fas fa-play-circle text-success" style="font-size: 3rem;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-hourglass-end text-danger" style="font-size: 3rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <?php if ($prova['tempo_restante_segundos'] > 0): ?>
                                    <div class="mt-2">
                                        <button class="btn-continuar" onclick="continuarProva(<?php echo $prova['prova_id']; ?>, <?php echo $prova['tentativa_id']; ?>)">
                                            <i class="fas fa-play"></i> Continuar Prova
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2">
                                        <button class="btn btn-secondary" disabled>
                                            <i class="fas fa-hourglass-end"></i> Tempo Expirado
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="prova-footer">
                    <div>
                        <?php if ($prova['tempo_restante_segundos'] > 0): ?>
                            <?php if ($prova['tempo_restante_segundos'] < 300): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Atenção! Pouco tempo restante.</span>
                            <?php else: ?>
                                <span class="text-success"><i class="fas fa-check-circle"></i> Prova em andamento</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-danger"><i class="fas fa-times-circle"></i> Tempo esgotado. A prova será finalizada automaticamente.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-danger" onclick="abandonarProva(<?php echo $prova['tentativa_id']; ?>)">
                            <i class="fas fa-trash-alt"></i> Abandonar Prova
                        </button>
                    </div>
                </div>
            </div>
            
            <script>
                // Timer para esta prova específica
                let tempoRestante_<?php echo $prova['tentativa_id']; ?> = <?php echo $prova['tempo_restante_segundos']; ?>;
                const timerElement_<?php echo $prova['tentativa_id']; ?> = document.getElementById('timer-<?php echo $prova['tentativa_id']; ?>');
                
                if (timerElement_<?php echo $prova['tentativa_id']; ?> && tempoRestante_<?php echo $prova['tentativa_id']; ?> > 0) {
                    const interval_<?php echo $prova['tentativa_id']; ?> = setInterval(function() {
                        if (tempoRestante_<?php echo $prova['tentativa_id']; ?> <= 0) {
                            clearInterval(interval_<?php echo $prova['tentativa_id']; ?>);
                            location.reload();
                        } else {
                            tempoRestante_<?php echo $prova['tentativa_id']; ?>--;
                            const horas = Math.floor(tempoRestante_<?php echo $prova['tentativa_id']; ?> / 3600);
                            const minutos = Math.floor((tempoRestante_<?php echo $prova['tentativa_id']; ?> % 3600) / 60);
                            const segundos = tempoRestante_<?php echo $prova['tentativa_id']; ?> % 60;
                            timerElement_<?php echo $prova['tentativa_id']; ?>.textContent = 
                                String(horas).padStart(2, '0') + ':' + 
                                String(minutos).padStart(2, '0') + ':' + 
                                String(segundos).padStart(2, '0');
                            
                            // Mudar cor quando faltar menos de 5 minutos
                            if (tempoRestante_<?php echo $prova['tentativa_id']; ?> < 300 && tempoRestante_<?php echo $prova['tentativa_id']; ?> > 0) {
                                timerElement_<?php echo $prova['tentativa_id']; ?>.parentElement.classList.add('warning');
                                timerElement_<?php echo $prova['tentativa_id']; ?>.parentElement.classList.add('timer-pulse');
                            }
                        }
                    }, 1000);
                }
            </script>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Dica -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-lightbulb"></i> Dicas</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-hourglass-half text-warning fa-2x"></i>
                        <div>
                            <strong>Gerencie seu tempo</strong>
                            <p class="mb-0 small">Fique atento ao cronômetro para não perder o prazo.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-save text-info fa-2x"></i>
                        <div>
                            <strong>Respostas salvas</strong>
                            <p class="mb-0 small">Suas respostas são salvas automaticamente.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-ban text-danger fa-2x"></i>
                        <div>
                            <strong>Abandonar prova</strong>
                            <p class="mb-0 small">Se abandonar, o progresso será perdido.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Função para continuar prova
    function continuarProva(provaId, tentativaId) {
        window.location.href = 'realizar_prova.php?id=' + provaId + '&tentativa=' + tentativaId;
    }
    
    // Função para abandonar prova
    function abandonarProva(tentativaId) {
        if (confirm('Tem certeza que deseja abandonar esta prova? Todo o seu progresso será perdido e você não poderá recuperá-lo.')) {
            $.ajax({
                url: 'abandonar_prova.php',
                method: 'POST',
                data: { tentativa_id: tentativaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Prova abandonada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao abandonar prova. Tente novamente.');
                }
            });
        }
    }
</script>
</body>
</html>