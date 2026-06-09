<?php
// escola/aluno/provas/realizar_prova.php - Realizar Prova Online

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql = "SELECT p.*, d.nome as disciplina_nome 
        FROM online_provas p
        JOIN disciplinas d ON d.id = p.disciplina_id
        WHERE p.id = :prova_id AND NOW() BETWEEN p.data_inicio AND p.data_fim";
$stmt = $conn->prepare($sql);
$stmt->execute([':prova_id' => $prova_id]);
$prova = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die("Prova não disponível no momento.");
}

// Verificar tentativas
$sql = "SELECT COUNT(*) as total FROM online_provas_tentativas 
        WHERE prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'finalizada'";
$stmt = $conn->prepare($sql);
$stmt->execute([':prova_id' => $prova_id, ':aluno_id' => $aluno_id]);
$tentativas = $stmt->fetch(PDO::FETCH_ASSOC);

if ($tentativas['total'] >= $prova['tentativas_permitidas']) {
    die("Você já utilizou todas as tentativas permitidas para esta prova.");
}

// Buscar questões da prova
$sql = "SELECT q.* FROM online_provas_questoes q WHERE q.prova_id = :prova_id ORDER BY q.ordem ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([':prova_id' => $prova_id]);
$questoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão
foreach ($questoes as &$questao) {
    $sql = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Criar nova tentativa
$tentativa_numero = $tentativas['total'] + 1;
$sql = "INSERT INTO online_provas_tentativas (prova_id, aluno_id, tentativa_numero, data_inicio, status) 
        VALUES (:prova_id, :aluno_id, :tentativa_numero, NOW(), 'em_andamento')";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':prova_id' => $prova_id, 
    ':aluno_id' => $aluno_id,
    ':tentativa_numero' => $tentativa_numero
]);
$tentativa_id = $conn->lastInsertId();

$_SESSION['tentativa_id'] = $tentativa_id;
$_SESSION['prova_inicio'] = time();
$_SESSION['prova_duracao'] = $prova['duracao_minutos'] * 60;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($prova['titulo']); ?></title>
    <style>
        body { background: #f5f7fb; }
        .timer-container {
            position: fixed;
            top: 0;
            right: 0;
            background: #dc3545;
            color: white;
            padding: 10px 20px;
            border-radius: 0 0 0 10px;
            font-size: 1.2em;
            z-index: 1000;
            font-weight: bold;
        }
        .question-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .alternativa {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .alternativa:hover {
            background: #f0f2f5;
            border-color: #006B3E;
        }
        .alternativa.selecionada {
            background: #006B3E20;
            border-color: #006B3E;
        }
        .btn-enviar {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 1.1em;
            cursor: pointer;
        }
        .btn-enviar:hover {
            opacity: 0.9;
        }
        .progress-bar-custom {
            height: 5px;
            background: #e0e0e0;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .progress-fill {
            height: 100%;
            background: #006B3E;
            border-radius: 5px;
            transition: width 0.3s;
        }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
<div class="timer-container" id="timer">
    ⏱️ Tempo restante: <span id="tempo">--:--</span>
</div>

<div class="container mt-5 pt-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="text-center mb-4">
                <h2><?php echo htmlspecialchars($prova['titulo']); ?></h2>
                <p><?php echo nl2br(htmlspecialchars($prova['descricao'])); ?></p>
                <div class="progress-bar-custom">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <hr>
            </div>
            
            <form id="provaForm" method="POST" action="salvar_prova.php">
                <input type="hidden" name="tentativa_id" value="<?php echo $tentativa_id; ?>">
                <input type="hidden" name="prova_id" value="<?php echo $prova_id; ?>">
                
                <?php foreach ($questoes as $index => $questao): ?>
                <div class="question-card" data-questao-id="<?php echo $questao['id']; ?>">
                    <h5>Questão <?php echo $index + 1; ?> (<?php echo $questao['pontuacao']; ?> pontos)</h5>
                    <p><?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?></p>
                    
                    <?php if ($questao['tipo'] == 'multipla_escolha'): ?>
                        <?php foreach ($questao['alternativas'] as $alt): ?>
                        <div class="alternativa" onclick="selecionarAlternativa(this, <?php echo $questao['id']; ?>, <?php echo $alt['id']; ?>)">
                            <input type="radio" name="questao_<?php echo $questao['id']; ?>" 
                                   value="<?php echo $alt['id']; ?>" id="alt_<?php echo $alt['id']; ?>" hidden>
                            <label for="alt_<?php echo $alt['id']; ?>" style="cursor: pointer; width: 100%; display: block;">
                                <?php echo htmlspecialchars($alt['texto']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    
                    <?php elseif ($questao['tipo'] == 'verdadeiro_falso'): ?>
                        <div class="alternativa" onclick="selecionarVF(this, <?php echo $questao['id']; ?>, 1)">
                            <input type="radio" name="questao_<?php echo $questao['id']; ?>" value="1" id="vf_true_<?php echo $questao['id']; ?>" hidden>
                            <label for="vf_true_<?php echo $questao['id']; ?>">✅ Verdadeiro</label>
                        </div>
                        <div class="alternativa" onclick="selecionarVF(this, <?php echo $questao['id']; ?>, 0)">
                            <input type="radio" name="questao_<?php echo $questao['id']; ?>" value="0" id="vf_false_<?php echo $questao['id']; ?>" hidden>
                            <label for="vf_false_<?php echo $questao['id']; ?>">❌ Falso</label>
                        </div>
                    
                    <?php elseif ($questao['tipo'] == 'dissertativa'): ?>
                        <textarea name="questao_<?php echo $questao['id']; ?>" class="form-control" rows="5" 
                                  placeholder="Digite sua resposta aqui..." style="border-radius: 8px;"></textarea>
                    
                    <?php elseif ($questao['tipo'] == 'completar'): ?>
                        <input type="text" name="questao_<?php echo $questao['id']; ?>" class="form-control" 
                               placeholder="Digite a resposta..." style="border-radius: 8px;">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                
                <div class="text-center mt-4 mb-5">
                    <button type="button" class="btn-enviar" onclick="confirmarEntrega()">
                        <i class="fas fa-paper-plane"></i> Finalizar Prova
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Timer da prova
    let tempoRestante = <?php echo $_SESSION['prova_duracao']; ?>;
    const totalTempo = tempoRestante;
    
    function atualizarTimer() {
        if (tempoRestante <= 0) {
            document.getElementById('timer').innerHTML = '⏰ Tempo esgotado!';
            document.getElementById('provaForm').submit();
            return;
        }
        
        let minutos = Math.floor(tempoRestante / 60);
        let segundos = tempoRestante % 60;
        document.getElementById('tempo').innerHTML = 
            String(minutos).padStart(2, '0') + ':' + String(segundos).padStart(2, '0');
        
        // Atualizar barra de progresso
        let progresso = ((totalTempo - tempoRestante) / totalTempo) * 100;
        document.getElementById('progressFill').style.width = progresso + '%';
        
        tempoRestante--;
        
        // Alertar quando faltar 5 minutos
        if (tempoRestante === 300) {
            alert('⚠️ Atenção! Faltam 5 minutos para finalizar a prova.');
        }
    }
    
    setInterval(atualizarTimer, 1000);
    
    // Funções para selecionar alternativas
    function selecionarAlternativa(elemento, questaoId, alternativaId) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => {
            alt.classList.remove('selecionada');
        });
        elemento.classList.add('selecionada');
        document.getElementById(`alt_${alternativaId}`).checked = true;
    }
    
    function selecionarVF(elemento, questaoId, valor) {
        const parent = elemento.parentElement;
        const alternativas = parent.querySelectorAll('.alternativa');
        alternativas.forEach(alt => {
            alt.classList.remove('selecionada');
        });
        elemento.classList.add('selecionada');
        document.querySelector(`input[name="questao_${questaoId}"]`).checked = true;
    }
    
    function confirmarEntrega() {
        // Verificar se todas as questões foram respondidas
        let totalQuestoes = <?php echo count($questoes); ?>;
        let respondidas = 0;
        
        <?php foreach ($questoes as $questao): ?>
            <?php if ($questao['tipo'] == 'multipla_escolha' || $questao['tipo'] == 'verdadeiro_falso'): ?>
                if (document.querySelector('input[name="questao_<?php echo $questao['id']; ?>"]:checked')) {
                    respondidas++;
                }
            <?php else: ?>
                if (document.querySelector('textarea[name="questao_<?php echo $questao['id']; ?>"]')?.value.trim() || 
                    document.querySelector('input[name="questao_<?php echo $questao['id']; ?>"]')?.value.trim()) {
                    respondidas++;
                }
            <?php endif; ?>
        <?php endforeach; ?>
        
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