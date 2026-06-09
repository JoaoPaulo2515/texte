<?php
// escola/aluno/provas/realizar_prova.php - Realizar Prova Online

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}*/

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
    die('Prova não encontrada ou não está disponível no momento.');
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
    // Buscar tentativa existente
    $sql = "SELECT * FROM online_provas_tentativas 
            WHERE id = :id AND prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'em_andamento'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $tentativa_id, ':prova_id' => $prova_id, ':aluno_id' => $aluno_id]);
    $tentativa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tentativa) {
        // Se não encontrou, criar nova
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
    // Criar nova tentativa
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

// Se não houver questões, mostrar mensagem
if (empty($questoes)) {
    die('Esta prova não possui questões cadastradas. Entre em contato com o professor.');
}

// ==============================================
// BUSCAR RESPOSTAS JÁ SALVAS (para continuar prova)
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
    
    // Verificar se já tem resposta salva
    if (isset($respostas_salvas[$questao['id']])) {
        $questao['resposta_salva'] = $respostas_salvas[$questao['id']];
    }
}

// Calcular tempo decorrido
$tempo_decorrido = time() - strtotime($tentativa['data_inicio'] ?? date('Y-m-d H:i:s'));
$tempo_restante = max(0, ($prova['duracao_minutos'] * 60) - $tempo_decorrido);

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
        body { background: #f0f2f5; }
        .timer-container {
            position: fixed;
            top: 70px;
            right: 20px;
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
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
        
        .questao-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .questao-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }
        .questao-body { padding: 20px; }
        
        .alternativa {
            margin: 10px 0;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
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
        }
        .progress-bar-custom { height: 8px; background: #e0e0e0; border-radius: 4px; margin-bottom: 15px; }
        .progress-fill { height: 100%; background: #006B3E; border-radius: 4px; transition: width 0.3s; }
        
        @media print { .timer-container, .btn-finalizar, .menu-aluno { display: none; } }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
<div class="timer-container" id="timer">
    <i class="fas fa-hourglass-half"></i> 
    Tempo restante: <span id="tempo">--:--</span>
</div>

<div class="main-content-aluno">
    <div class="container">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <!-- Cabeçalho da Prova -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h3 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h3>
                        <p class="text-muted"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></p>
                        <div class="progress-bar-custom">
                            <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
                        </div>
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
                    
                    <?php foreach ($questoes as $index => $questao): ?>
                    <div class="questao-card">
                        <div class="questao-header">
                            <span><strong>Questão <?php echo $index + 1; ?></strong></span>
                            <span class="badge bg-success"><?php echo $questao['pontuacao']; ?> pontos</span>
                        </div>
                        <div class="questao-body">
                            <div class="mb-3">
                                <?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?>
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
                                <textarea name="questao_<?php echo $questao['id']; ?>" class="form-control" rows="5" placeholder="Digite sua resposta aqui..."><?php echo htmlspecialchars($questao['resposta_salva']['resposta_texto'] ?? ''); ?></textarea>
                                
                            <?php elseif ($questao['tipo'] == 'completar'): ?>
                                <input type="text" name="questao_<?php echo $questao['id']; ?>" class="form-control" placeholder="Digite a resposta..." value="<?php echo htmlspecialchars($questao['resposta_salva']['resposta_texto'] ?? ''); ?>">
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Timer da prova
    let tempoRestante = <?php echo $tempo_restante; ?>;
    const totalTempo = <?php echo $prova['duracao_minutos'] * 60; ?>;
    
    function atualizarTimer() {
        if (tempoRestante <= 0) {
            document.getElementById('timer').innerHTML = '<i class="fas fa-hourglass-end"></i> Tempo esgotado!';
            document.getElementById('timer').classList.add('warning');
            document.getElementById('provaForm').submit();
            return;
        }
        
        let minutos = Math.floor(tempoRestante / 60);
        let segundos = tempoRestante % 60;
        document.getElementById('tempo').innerHTML = String(minutos).padStart(2, '0') + ':' + String(segundos).padStart(2, '0');
        
        // Atualizar barra de progresso
        let progresso = ((totalTempo - tempoRestante) / totalTempo) * 100;
        document.getElementById('progressFill').style.width = progresso + '%';
        
        // Alertar quando faltar 5 minutos
        if (tempoRestante === 300) {
            alert('⚠️ Atenção! Faltam 5 minutos para finalizar a prova.');
            document.getElementById('timer').classList.add('warning');
        }
        
        tempoRestante--;
    }
    
    setInterval(atualizarTimer, 1000);
    atualizarTimer();
    
    // Selecionar alternativa
    function selecionarAlternativa(questaoId, alternativaId, elemento) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => alt.classList.remove('selecionada'));
        elemento.classList.add('selecionada');
        document.getElementById(`alt_${alternativaId}`).checked = true;
        
        // Salvar resposta automaticamente
        salvarResposta(questaoId, alternativaId, null, null);
    }
    
    function selecionarVF(questaoId, valor, elemento) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => alt.classList.remove('selecionada'));
        elemento.classList.add('selecionada');
        document.querySelector(`input[name="questao_${questaoId}"]`).checked = true;
        
        // Salvar resposta automaticamente
        salvarResposta(questaoId, null, valor, null);
    }
    
    // Salvar resposta automaticamente via AJAX
    function salvarResposta(questaoId, alternativaId, respostaBoolean, respostaTexto) {
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
                if (!response.success) {
                    console.log('Erro ao salvar resposta');
                }
            }
        });
    }
    
    // Salvar respostas de texto e completar
    $('textarea, input[type="text"]').on('change', function() {
        let name = $(this).attr('name');
        let questaoId = name.split('_')[1];
        let valor = $(this).val();
        salvarResposta(questaoId, null, null, valor);
    });
    
    function confirmarEntrega() {
        let totalQuestoes = <?php echo count($questoes); ?>;
        let respondidas = $('input:checked, textarea:not(:empty), input[type="text"]:not(:empty)').length;
        
        if (respondidas < totalQuestoes) {
            if (!confirm(`Você respondeu ${respondidas} de ${totalQuestoes} questões. Deseja finalizar mesmo assim?`)) {
                return;
            }
        }
        
        if (confirm('Tem certeza que deseja finalizar a prova? Não será possível alterar as respostas após a entrega.')) {
            document.getElementById('provaForm').submit();
        }
    }
    
    // Prevenir saída da página
    window.onbeforeunload = function() {
        return "Você está realizando uma prova. Se sair agora, poderá perder seu progresso.";
    };
</script>
</body>
</html>