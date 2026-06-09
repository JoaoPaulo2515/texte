<?php
// escola/aluno/provas/realizar_prova.php - Realizar Prova Online

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tentativa_id = isset($_GET['tentativa']) ? (int)$_GET['tentativa'] : 0;

// ==============================================
// BUSCAR DADOS DA PROVA
// ==============================================
$sql_prova = "SELECT p.*, d.nome as disciplina_nome 
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              WHERE p.id = :prova_id 
              AND p.escola_id = :escola_id 
              AND p.status = 'publicada'
              AND NOW() BETWEEN p.data_inicio AND p.data_fim";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    header('Location: erro_prova.php?codigo=404&msg=prova_nao_encontrada&id=' . $prova_id);
    exit;
}

// ==============================================
// VERIFICAR TENTATIVAS
// ==============================================
$sql_tentativas = "SELECT COUNT(*) as total FROM online_provas_tentativas 
                   WHERE prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'finalizada'";
$stmt_tentativas = $conn->prepare($sql_tentativas);
$stmt_tentativas->execute([':prova_id' => $prova_id, ':aluno_id' => $aluno_id]);
$tentativas_realizadas = $stmt_tentativas->fetch(PDO::FETCH_ASSOC);

if ($tentativas_realizadas['total'] >= $prova['tentativas_permitidas']) {
    die('Você já utilizou todas as tentativas permitidas para esta prova.');
}

// ==============================================
// CRIAR OU BUSCAR TENTATIVA EM ANDAMENTO
// ==============================================
if ($tentativa_id > 0) {
    $sql = "SELECT * FROM online_provas_tentativas 
            WHERE id = :id AND prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'em_andamento'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tentativa_id, ':prova_id' => $prova_id, ':aluno_id' => $aluno_id]);
    $tentativa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tentativa) {
        $tentativa_numero = $tentativas_realizadas['total'] + 1;
        $sql = "INSERT INTO online_provas_tentativas (prova_id, aluno_id, tentativa_numero, data_inicio, status) 
                VALUES (:prova_id, :aluno_id, :tentativa_numero, NOW(), 'em_andamento')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':prova_id' => $prova_id,
            ':aluno_id' => $aluno_id,
            ':tentativa_numero' => $tentativa_numero
        ]);
        $tentativa_id = $conn->lastInsertId();
    }
} else {
    $tentativa_numero = $tentativas_realizadas['total'] + 1;
    $sql = "INSERT INTO online_provas_tentativas (prova_id, aluno_id, tentativa_numero, data_inicio, status) 
            VALUES (:prova_id, :aluno_id, :tentativa_numero, NOW(), 'em_andamento')";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':prova_id' => $prova_id,
        ':aluno_id' => $aluno_id,
        ':tentativa_numero' => $tentativa_numero
    ]);
    $tentativa_id = $conn->lastInsertId();
}

// ==============================================
// BUSCAR QUESTÕES DA PROVA
// ==============================================
$sql_questoes = "SELECT q.* FROM online_provas_questoes q 
                 WHERE q.prova_id = :prova_id 
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

if (empty($questoes)) {
    die('Esta prova não possui questões cadastradas. Entre em contato com o professor.');
}

// ==============================================
// BUSCAR RESPOSTAS JÁ SALVAS
// ==============================================
$respostas_salvas = [];
$sql_respostas = "SELECT questao_id, alternativa_id, resposta_texto, resposta_boolean 
                  FROM online_provas_respostas 
                  WHERE tentativa_id = :tentativa_id";
$stmt_respostas = $conn->prepare($sql_respostas);
$stmt_respostas->execute([':tentativa_id' => $tentativa_id]);
$respostas_temp = $stmt_respostas->fetchAll(PDO::FETCH_ASSOC);

foreach ($respostas_temp as $res) {
    $respostas_salvas[$res['questao_id']] = $res;
}

// ==============================================
// BUSCAR ALTERNATIVAS PARA CADA QUESTÃO
// ==============================================
foreach ($questoes as &$questao) {
    $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem ASC";
    $stmt_alt = $conn->prepare($sql_alt);
    $stmt_alt->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
    
    if (isset($respostas_salvas[$questao['id']])) {
        $questao['resposta_salva'] = $respostas_salvas[$questao['id']];
    }
}

// Calcular tempo decorrido
$tempo_decorrido = time() - strtotime($tentativa['data_inicio'] ?? date('Y-m-d H:i:s'));
$tempo_restante = max(0, ($prova['duracao_minutos'] * 60) - $tempo_decorrido);

// Calcular progresso inicial das respostas
$total_questoes = count($questoes);
$respondidas_inicial = 0;
foreach ($questoes as $q) {
    if (isset($q['resposta_salva'])) {
        $respondidas_inicial++;
    }
}
$percentual_inicial = $total_questoes > 0 ? round(($respondidas_inicial / $total_questoes) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($prova['titulo']); ?> | Prova Online</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow: hidden; height: 100vh; }
        
        /* Layout com webcam fixa à direita */
        .prova-container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
        .prova-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            margin-top: 60px;
        }
        
        .webcam-container {
            width: 320px;
            background: #1a1a2e;
            border-left: 2px solid #006B3E;
            display: flex;
            flex-direction: column;
            position: fixed;
            right: 0;
            top: 0;
            height: 100vh;
            z-index: 999;
        }
        
        .webcam-header {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: bold;
            margin-top: 60px;
        }
        
        .webcam-video {
            flex: 1;
            background: #000;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        #videoElement {
            width: 100%;
            height: auto;
            background: #000;
        }
        
        .webcam-overlay {
            position: absolute;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px;
            font-size: 11px;
        }
        
        .webcam-status {
            padding: 8px;
            text-align: center;
            font-size: 12px;
            background: #2c2c3e;
            color: #ffc107;
        }
        
        .webcam-status.active {
            color: #28a745;
        }
        
        .webcam-status.inactive {
            color: #dc3545;
        }
        
        .timer-container {
            position: fixed;
            top: 70px;
            left: 20px;
            background: #dc3545;
            color: white;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 1.2em;
            font-weight: bold;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .timer-container.warning { background: #ffc107; color: #333; animation: pulse 1s infinite; }
        .timer-container.danger { background: #dc3545; animation: pulse 0.5s infinite; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
        
        .progress-container {
            background: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .progress-bar-custom {
            height: 10px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 5px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #006B3E, #1A2A6C);
            border-radius: 10px;
            transition: width 0.3s;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .questao-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .questao-card:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); }
        .questao-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .questao-body { padding: 20px; }
        
        .questao-enunciado {
            font-size: 1rem;
            line-height: 1.7;
            color: #2c3e50;
            margin-bottom: 20px;
            background: #fafbfc;
            padding: 20px;
            border-radius: 16px;
            border-left: 3px solid #006B3E;
        }
        
        .alternativa {
            margin: 10px 0;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alternativa:hover { background: #f0f2f5; border-color: #006B3E; }
        .alternativa.selecionada { background: #e8f5e9; border-color: #006B3E; border-left: 4px solid #006B3E; }
        
        .btn-finalizar {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-finalizar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        
        .main-content-aluno { margin-left: 0; margin-top: 0; padding: 20px; }
        @media (max-width: 768px) { .webcam-container { width: 250px; } .prova-content { margin-right: 250px; } }
        
        @media (max-width: 576px) { .webcam-container { display: none; } .prova-content { margin-right: 0; } }
        
        /* Modal de Alerta */
        .modal-alerta {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            animation: fadeInModal 0.3s ease;
        }
        @keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
        
        .modal-alerta-content {
            background: white;
            border-radius: 24px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            overflow: hidden;
            animation: slideInModal 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        @keyframes slideInModal { from { opacity: 0; transform: translateY(-50px); } to { opacity: 1; transform: translateY(0); } }
        
        .modal-alerta-header { padding: 25px 20px 15px; text-align: center; }
        .modal-alerta-icon { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; }
        .modal-alerta-icon i { font-size: 45px; }
        .modal-alerta-icon.danger { background: #f8d7da; color: #dc3545; }
        .modal-alerta-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 10px; color: #333; }
        .modal-alerta-message { color: #666; font-size: 1rem; line-height: 1.5; padding: 0 20px; margin-bottom: 20px; }
        .modal-alerta-footer { padding: 15px 20px 25px; display: flex; gap: 10px; justify-content: center; }
        .modal-alerta-btn { padding: 10px 25px; border: none; border-radius: 40px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; }
        .modal-alerta-btn-primary { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .countdown-circle { width: 60px; height: 60px; border-radius: 50%; background: #dc3545; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; animation: pulseCountdown 1s infinite; }
        .countdown-circle span { font-size: 28px; font-weight: 800; color: white; }
        @keyframes pulseCountdown { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.8; } 100% { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>
<div class="prova-container">
    <!-- Conteúdo da Prova -->
    <div class="prova-content">
        <div class="timer-container" id="timer">
            <i class="fas fa-hourglass-half"></i> 
            Tempo restante: <span id="tempo">--:--</span>
        </div>

        <div class="container">
            <div class="row">
                <div class="col-md-10 mx-auto">
                    <!-- Progress Bar com Porcentagem -->
                    <div class="progress-container">
                        <div class="progress-bar-custom">
                            <div class="progress-fill" id="progressFill" style="width: <?php echo $percentual_inicial; ?>%;"></div>
                        </div>
                        <div class="progress-text">
                            <span><i class="fas fa-check-circle"></i> Questões respondidas</span>
                            <span><strong id="respondidas_count"><?php echo $respondidas_inicial; ?></strong> de <strong id="total_questoes"><?php echo $total_questoes; ?></strong></span>
                            <span><strong id="percentual_texto"><?php echo $percentual_inicial; ?>%</strong> concluído</span>
                        </div>
                    </div>

                    <!-- Cabeçalho da Prova -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h3 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h3>
                            <p class="text-muted"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></p>
                            <?php if ($prova['instrucoes']): ?>
                            <div class="alert alert-info mt-2">
                                <i class="fas fa-info-circle"></i> <strong>Instruções:</strong><br>
                                <?php echo nl2br(htmlspecialchars($prova['instrucoes'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Formulário da Prova -->
                    <form id="provaForm" method="POST" action="salvar_prova.php">
                        <input type="hidden" name="tentativa_id" value="<?php echo $tentativa_id; ?>">
                        <input type="hidden" name="prova_id" value="<?php echo $prova_id; ?>">
                        <input type="hidden" name="finalizada_automaticamente" id="finalizada_automaticamente" value="0">
                        <input type="hidden" name="finalizada_por_seguranca" id="finalizada_por_seguranca" value="0">
                        
                        <?php foreach ($questoes as $index => $questao): ?>
                        <div class="questao-card" data-questao-id="<?php echo $questao['id']; ?>">
                            <div class="questao-header">
                                <span><strong><i class="fas fa-hashtag"></i> Questão <?php echo $index + 1; ?></strong></span>
                                <span class="badge bg-success"><?php echo $questao['pontuacao']; ?> pontos</span>
                            </div>
                            <div class="questao-body">
                                <div class="questao-enunciado">
                                    <?php echo $questao['enunciado']; ?>
                                </div>
                                
                                <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                                    <?php foreach ($questao['alternativas'] as $alt_idx => $alt): 
                                        $is_checked = (isset($questao['resposta_salva']['alternativa_id']) && $questao['resposta_salva']['alternativa_id'] == $alt['id']);
                                    ?>
                                    <div class="alternativa <?php echo $is_checked ? 'selecionada' : ''; ?>" onclick="selecionarAlternativa(<?php echo $questao['id']; ?>, <?php echo $alt['id']; ?>, this)">
                                        <input type="radio" name="questao_<?php echo $questao['id']; ?>" value="<?php echo $alt['id']; ?>" id="alt_<?php echo $alt['id']; ?>" <?php echo $is_checked ? 'checked' : ''; ?> hidden>
                                        <span class="badge bg-secondary"><?php echo chr(65 + $alt_idx); ?></span>
                                        <label for="alt_<?php echo $alt['id']; ?>" style="cursor: pointer; width: 100%; margin: 0;"><?php echo htmlspecialchars($alt['texto']); ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                <?php elseif ($questao['tipo'] == 'verdadeiro_falso'): ?>
                                    <?php 
                                    $vf_correta = isset($questao['resposta_salva']['resposta_boolean']) ? $questao['resposta_salva']['resposta_boolean'] : null;
                                    ?>
                                    <div class="alternativa <?php echo ($vf_correta === 1) ? 'selecionada' : ''; ?>" onclick="selecionarVF(<?php echo $questao['id']; ?>, 1, this)">
                                        <input type="radio" name="questao_<?php echo $questao['id']; ?>" value="1" id="vf_true_<?php echo $questao['id']; ?>" <?php echo ($vf_correta === 1) ? 'checked' : ''; ?> hidden>
                                        <label for="vf_true_<?php echo $questao['id']; ?>">✅ Verdadeiro</label>
                                    </div>
                                    <div class="alternativa <?php echo ($vf_correta === 0) ? 'selecionada' : ''; ?>" onclick="selecionarVF(<?php echo $questao['id']; ?>, 0, this)">
                                        <input type="radio" name="questao_<?php echo $questao['id']; ?>" value="0" id="vf_false_<?php echo $questao['id']; ?>" <?php echo ($vf_correta === 0) ? 'checked' : ''; ?> hidden>
                                        <label for="vf_false_<?php echo $questao['id']; ?>">❌ Falso</label>
                                    </div>
                                    
                                <?php elseif ($questao['tipo'] == 'dissertativa'): ?>
                                    <textarea name="questao_<?php echo $questao['id']; ?>" class="form-control resposta-texto" rows="5" placeholder="Digite sua resposta aqui..." data-questao="<?php echo $questao['id']; ?>"><?php echo htmlspecialchars($questao['resposta_salva']['resposta_texto'] ?? ''); ?></textarea>
                                    
                                <?php elseif ($questao['tipo'] == 'completar'): ?>
                                    <input type="text" name="questao_<?php echo $questao['id']; ?>" class="form-control resposta-texto" placeholder="Digite a resposta..." data-questao="<?php echo $questao['id']; ?>" value="<?php echo htmlspecialchars($questao['resposta_salva']['resposta_texto'] ?? ''); ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="text-center mt-4 mb-5">
                            <button type="button" class="btn-finalizar" onclick="confirmarEntrega()">
                                <i class="fas fa-paper-plane"></i> Finalizar Prova
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Webcam de Monitoramento -->
    <div class="webcam-container">
        <div class="webcam-header">
            <i class="fas fa-video"></i> Monitoramento em Tempo Real
        </div>
        <div class="webcam-video">
            <video id="videoElement" autoplay playsinline></video>
            <div class="webcam-overlay">
                <i class="fas fa-camera"></i> Câmera ativa
            </div>
        </div>
        <div class="webcam-status" id="webcamStatus">
            <i class="fas fa-sync-alt fa-spin"></i> Inicializando câmera...
        </div>
    </div>
</div>

<!-- Modal de Alerta -->
<div id="modalAlerta" class="modal-alerta">
    <div class="modal-alerta-content">
        <div class="modal-alerta-header">
            <div class="modal-alerta-icon danger" id="modalIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="modal-alerta-title" id="modalTitle">Atenção!</h3>
        </div>
        <div class="modal-alerta-message" id="modalMessage">
            Mensagem de alerta
        </div>
        <div class="modal-alerta-footer" id="modalFooter">
            <button class="modal-alerta-btn modal-alerta-btn-primary" id="modalBtnOk">OK</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // ============================================
    // VARIÁVEIS GLOBAIS
    // ============================================
    let tempoRestante = <?php echo $tempo_restante; ?>;
    const totalTempo = <?php echo $prova['duracao_minutos'] * 60; ?>;
    let timerInterval;
    let tempoEsgotado = false;
    let webcamAtiva = true;
    let ultimaCaptura = Date.now();
    let faceDetectada = true;
    let finalizando = false;
    
    const totalQuestoes = <?php echo $total_questoes; ?>;
    let respondidas = <?php echo $respondidas_inicial; ?>;
    
    // ============================================
    // WEBCAM E DETECÇÃO DE FACE/PRESENÇA
    // ============================================
    const videoElement = document.getElementById('videoElement');
    const webcamStatus = document.getElementById('webcamStatus');
    
    async function iniciarWebcam() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 320 },
                    height: { ideal: 240 },
                    facingMode: "user"
                } 
            });
            videoElement.srcObject = stream;
            webcamStatus.innerHTML = '<i class="fas fa-check-circle"></i> Câmera ativa - monitorando';
            webcamStatus.classList.add('active');
            webcamStatus.classList.remove('inactive');
            webcamAtiva = true;
            
            // Iniciar verificação periódica da câmera
            iniciarVerificacaoCamera();
            // Iniciar detecção de movimento/face
            iniciarDeteccao();
            
        } catch (err) {
            console.error('Erro ao acessar câmera:', err);
            webcamStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Câmera não disponível! A prova será finalizada.';
            webcamStatus.classList.add('inactive');
            webcamAtiva = false;
            
            // Finalizar prova por falta de câmera
            setTimeout(() => {
                if (!finalizando) {
                    finalizarProvaPorSeguranca('Câmera não disponível');
                }
            }, 5000);
        }
    }
    
    let verificacaoInterval;
    function iniciarVerificacaoCamera() {
        verificacaoInterval = setInterval(() => {
            if (!webcamAtiva && !finalizando) {
                finalizarProvaPorSeguranca('Câmera desativada durante a prova');
            }
        }, 5000);
    }
    
    function iniciarDeteccao() {
        // Detectar quando a página perde foco (alt+tab, mudar de aba)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden && !finalizando && !tempoEsgotado) {
                finalizarProvaPorSeguranca('Página minimizada ou troca de aba detectada');
            }
        });
        
        // Detectar quando o usuário tenta sair da página
        window.addEventListener('beforeunload', function(e) {
            if (!tempoEsgotado && !finalizando) {
                e.preventDefault();
                e.returnValue = 'Você está realizando uma prova. Se sair agora, será considerado fraude.';
                finalizarProvaPorSeguranca('Tentativa de sair da página');
                return e.returnValue;
            }
        });
        
        // Detectar perda de foco da janela
        window.addEventListener('blur', function() {
            if (!finalizando && !tempoEsgotado) {
                finalizarProvaPorSeguranca('Janela perdeu foco (possível troca de aplicativo)');
            }
        });
        
        // Detectar teclas de atalho
        document.addEventListener('keydown', function(e) {
            const teclasProibidas = ['F12', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11'];
            if (teclasProibidas.includes(e.key) || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) {
                e.preventDefault();
                finalizarProvaPorSeguranca('Tecla de atalho proibida pressionada');
            }
        });
    }
    
    function finalizarProvaPorSeguranca(motivo) {
        if (finalizando) return;
        finalizando = true;
        
        console.log('Prova finalizada por segurança:', motivo);
        
        if (verificacaoInterval) clearInterval(verificacaoInterval);
        if (timerInterval) clearInterval(timerInterval);
        
        // Parar webcam
        if (videoElement.srcObject) {
            const tracks = videoElement.srcObject.getTracks();
            tracks.forEach(track => track.stop());
        }
        
        mostrarAlertaContagem('🔒 PROVA FINALIZADA!', 
            `A prova foi finalizada automaticamente por motivos de segurança.<br><br>
            <strong>Motivo:</strong> ${motivo}<br><br>
            Esta ação foi registrada para análise do professor.`, 
            5, function() {
                document.getElementById('finalizada_por_seguranca').value = motivo;
                document.getElementById('finalizada_automaticamente').value = '1';
                document.getElementById('provaForm').submit();
            });
    }
    
    // ============================================
    // FUNÇÕES DO MODAL
    // ============================================
    function mostrarAlerta(tipo, titulo, mensagem, onConfirm = null) {
        const modal = document.getElementById('modalAlerta');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const message = document.getElementById('modalMessage');
        const footer = document.getElementById('modalFooter');
        
        icon.className = 'modal-alerta-icon danger';
        icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
        
        title.textContent = titulo;
        message.innerHTML = mensagem;
        
        footer.innerHTML = `<button class="modal-alerta-btn modal-alerta-btn-primary" id="modalBtnOk">OK</button>`;
        const btnOk = document.getElementById('modalBtnOk');
        
        btnOk.addEventListener('click', function() {
            modal.style.display = 'none';
            if (onConfirm && typeof onConfirm === 'function') {
                onConfirm();
            }
        }, { once: true });
        
        modal.style.display = 'flex';
    }
    
    function mostrarAlertaContagem(titulo, mensagem, segundos, onConfirm = null) {
        const modal = document.getElementById('modalAlerta');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const message = document.getElementById('modalMessage');
        const footer = document.getElementById('modalFooter');
        
        icon.className = 'modal-alerta-icon danger';
        icon.innerHTML = '<div class="countdown-circle"><span id="countdownNumber">' + segundos + '</span></div>';
        
        title.textContent = titulo;
        message.innerHTML = mensagem;
        
        let countdown = segundos;
        let finalizado = false;
        
        footer.innerHTML = `<button class="modal-alerta-btn modal-alerta-btn-primary" id="modalBtnOk" disabled>Aguardando (${countdown}s)</button>`;
        const btnOk = document.getElementById('modalBtnOk');
        
        const countdownInterval = setInterval(() => {
            countdown--;
            const countdownSpan = document.getElementById('countdownNumber');
            if (countdownSpan) countdownSpan.textContent = countdown;
            if (btnOk) btnOk.textContent = `Aguardando (${countdown}s)`;
            
            if (countdown <= 0 && !finalizado) {
                clearInterval(countdownInterval);
                finalizado = true;
                modal.style.display = 'none';
                
                if (onConfirm && typeof onConfirm === 'function') {
                    onConfirm();
                }
            }
        }, 1000);
        
        modal.style.display = 'flex';
    }
    
    function mostrarAlertaConfirmacao(titulo, mensagem, onConfirm, onCancel = null) {
        const modal = document.getElementById('modalAlerta');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const message = document.getElementById('modalMessage');
        const footer = document.getElementById('modalFooter');
        
        icon.className = 'modal-alerta-icon danger';
        icon.innerHTML = '<i class="fas fa-question-circle"></i>';
        
        title.textContent = titulo;
        message.innerHTML = mensagem;
        
        footer.innerHTML = `
            <button class="modal-alerta-btn modal-alerta-btn-primary" id="modalBtnCancelar">Cancelar</button>
            <button class="modal-alerta-btn modal-alerta-btn-primary" id="modalBtnConfirmar" style="background: #dc3545;">Confirmar</button>
        `;
        
        const btnConfirmar = document.getElementById('modalBtnConfirmar');
        const btnCancelar = document.getElementById('modalBtnCancelar');
        
        btnConfirmar.addEventListener('click', function() {
            modal.style.display = 'none';
            if (onConfirm && typeof onConfirm === 'function') {
                onConfirm();
            }
        }, { once: true });
        
        btnCancelar.addEventListener('click', function() {
            modal.style.display = 'none';
            if (onCancel && typeof onCancel === 'function') {
                onCancel();
            }
        }, { once: true });
        
        modal.style.display = 'flex';
    }
    
    function finalizarProva() {
        document.getElementById('finalizada_automaticamente').value = '1';
        document.getElementById('provaForm').submit();
    }
    
    // ============================================
    // TIMER
    // ============================================
    function atualizarProgresso() {
        const percentual = totalQuestoes > 0 ? Math.round((respondidas / totalQuestoes) * 100) : 0;
        document.getElementById('progressFill').style.width = percentual + '%';
        document.getElementById('respondidas_count').textContent = respondidas;
        document.getElementById('percentual_texto').textContent = percentual + '%';
    }
    
    function atualizarTimer() {
        if (tempoRestante <= 0 && !tempoEsgotado) {
            tempoEsgotado = true;
            clearInterval(timerInterval);
            document.getElementById('timer').innerHTML = '<i class="fas fa-hourglass-end"></i> Tempo esgotado!';
            document.getElementById('timer').classList.add('danger');
            
            mostrarAlertaContagem('⏰ TEMPO ESGOTADO!', 
                'O tempo limite da prova foi atingido.<br><br>A prova será finalizada automaticamente.', 
                5, finalizarProva);
            return;
        }
        
        let minutos = Math.floor(tempoRestante / 60);
        let segundos = tempoRestante % 60;
        document.getElementById('tempo').innerHTML = String(minutos).padStart(2, '0') + ':' + String(segundos).padStart(2, '0');
        
        let progressoTempo = ((totalTempo - tempoRestante) / totalTempo) * 100;
        document.getElementById('progressFill').style.width = progressoTempo + '%';
        
        if (tempoRestante === 300) {
            mostrarAlerta('warning', '⚠️ Atenção!', 'Faltam <strong>5 minutos</strong> para finalizar a prova.<br><br>Certifique-se de salvar suas respostas.');
            document.getElementById('timer').classList.add('warning');
        }
        
        if (tempoRestante === 60) {
            mostrarAlertaContagem('⏰ TEMPO ESCASSANDO!', 
                'Faltam apenas <strong>1 minuto</strong> para o término da prova.<br><br>Certifique-se de salvar todas as suas respostas.', 
                10);
            document.getElementById('timer').classList.add('danger');
        }
        
        tempoRestante--;
    }
    
    timerInterval = setInterval(atualizarTimer, 1000);
    atualizarTimer();
    
    // ============================================
    // FUNÇÕES DAS RESPOSTAS
    // ============================================
    function atualizarContagemRespostas() {
        let novaContagem = 0;
        
        // Contar radios marcados
        document.querySelectorAll('input[type="radio"]:checked').forEach(() => novaContagem++);
        
        // Contar textos preenchidos
        document.querySelectorAll('.resposta-texto').forEach(texto => {
            if (texto.value.trim() !== '') novaContagem++;
        });
        
        respondidas = novaContagem;
        atualizarProgresso();
    }
    
    function selecionarAlternativa(questaoId, alternativaId, elemento) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => alt.classList.remove('selecionada'));
        elemento.classList.add('selecionada');
        document.getElementById(`alt_${alternativaId}`).checked = true;
        salvarResposta(questaoId, alternativaId, null, null);
        atualizarContagemRespostas();
    }
    
    function selecionarVF(questaoId, valor, elemento) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => alt.classList.remove('selecionada'));
        elemento.classList.add('selecionada');
        document.querySelector(`input[name="questao_${questaoId}"]`).checked = true;
        salvarResposta(questaoId, null, valor, null);
        atualizarContagemRespostas();
    }
    
    let saveTimeout;
    function salvarResposta(questaoId, alternativaId, respostaBoolean, respostaTexto) {
        if (saveTimeout) clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => {
            $.ajax({
                url: 'salvar_resposta_ajax.php',
                method: 'POST',
                data: {
                    tentativa_id: <?php echo $tentativa_id; ?>,
                    questao_id: questaoId,
                    alternativa_id: alternativaId,
                    resposta_boolean: respostaBoolean,
                    resposta_texto: respostaTexto
                },
                dataType: 'json',
                success: function(response) {
                    if (!response.success) console.log('Erro ao salvar resposta');
                },
                error: function() { console.log('Erro de conexão'); }
            });
        }, 500);
    }
    
    $('.resposta-texto').on('input', function() {
        let questaoId = $(this).data('questao');
        let valor = $(this).val();
        salvarResposta(questaoId, null, null, valor);
        atualizarContagemRespostas();
    });
    
    function confirmarEntrega() {
        if (respondidas < totalQuestoes) {
            mostrarAlertaConfirmacao('⚠️ ATENÇÃO!', 
                `Você respondeu <strong>${respondidas}</strong> de <strong>${totalQuestoes}</strong> questões.<br><br>Deseja finalizar a prova mesmo assim?`, 
                function() { 
                    if (videoElement.srcObject) {
                        const tracks = videoElement.srcObject.getTracks();
                        tracks.forEach(track => track.stop());
                    }
                    document.getElementById('provaForm').submit(); 
                });
            return;
        }
        
        mostrarAlertaConfirmacao('📝 FINALIZAR PROVA', 
            'Tem certeza que deseja finalizar a prova?<br><br>Após a entrega, não será possível alterar as respostas.', 
            function() { 
                if (videoElement.srcObject) {
                    const tracks = videoElement.srcObject.getTracks();
                    tracks.forEach(track => track.stop());
                }
                document.getElementById('provaForm').submit(); 
            });
    }
    
    // Iniciar webcam
    iniciarWebcam();
    
    // Inicializar contagem de respostas
    atualizarContagemRespostas();
</script>
</body>
</html>