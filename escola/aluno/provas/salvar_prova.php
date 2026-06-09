<?php
// escola/aluno/provas/salvar_prova.php - Salvar respostas e corrigir prova

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$tentativa_id = $_POST['tentativa_id'] ?? 0;
$prova_id = $_POST['prova_id'] ?? 0;

// Buscar informações da prova
$sql = "SELECT * FROM online_provas WHERE id = :prova_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':prova_id' => $prova_id]);
$prova = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die("Prova não encontrada.");
}

// Buscar todas as questões da prova
$sql = "SELECT q.* FROM online_provas_questoes q WHERE q.prova_id = :prova_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':prova_id' => $prova_id]);
$questoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pontuacao_total = 0;
$respostas_salvas = 0;

foreach ($questoes as $questao) {
    $resposta = $_POST["questao_{$questao['id']}"] ?? null;
    $correta = 0;
    $pontuacao_obtida = 0;
    $alternativa_id = null;
    $resposta_texto = null;
    $resposta_boolean = null;
    
    if ($questao['tipo'] == 'multipla_escolha') {
        $alternativa_id = (int)$resposta;
        
        // Verificar se a alternativa está correta
        $sql = "SELECT correta FROM online_provas_alternativas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $alternativa_id]);
        $alt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($alt && $alt['correta'] == 1) {
            $correta = 1;
            $pontuacao_obtida = $questao['pontuacao'];
        }
    } 
    elseif ($questao['tipo'] == 'verdadeiro_falso') {
        $resposta_boolean = (int)$resposta;
        
        // Buscar resposta correta
        $sql = "SELECT correta FROM online_provas_alternativas WHERE questao_id = :questao_id AND correta = 1 LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':questao_id' => $questao['id']]);
        $alt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $resposta_correta = $alt ? 1 : 0;
        
        if ($resposta_boolean == $resposta_correta) {
            $correta = 1;
            $pontuacao_obtida = $questao['pontuacao'];
        }
    }
    elseif ($questao['tipo'] == 'dissertativa') {
        $resposta_texto = $resposta;
        // Dissertativa precisa ser corrigida pelo professor
        $pontuacao_obtida = 0;
    }
    elseif ($questao['tipo'] == 'completar') {
        $resposta_texto = $resposta;
        $pontuacao_obtida = 0;
    }
    
    $pontuacao_total += $pontuacao_obtida;
    
    // Verificar se resposta já existe
    $sql = "SELECT id FROM online_provas_respostas 
            WHERE tentativa_id = :tentativa_id AND questao_id = :questao_id AND aluno_id = :aluno_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':tentativa_id' => $tentativa_id,
        ':questao_id' => $questao['id'],
        ':aluno_id' => $aluno_id
    ]);
    
    if ($stmt->fetch()) {
        // Atualizar resposta existente
        $sql = "UPDATE online_provas_respostas 
                SET alternativa_id = :alternativa_id, 
                    resposta_texto = :resposta_texto, 
                    resposta_boolean = :resposta_boolean, 
                    correta = :correta, 
                    pontuacao_obtida = :pontuacao_obtida,
                    data_resposta = NOW()
                WHERE tentativa_id = :tentativa_id AND questao_id = :questao_id AND aluno_id = :aluno_id";
    } else {
        // Inserir nova resposta
        $sql = "INSERT INTO online_provas_respostas (tentativa_id, prova_id, questao_id, aluno_id, alternativa_id, resposta_texto, resposta_boolean, correta, pontuacao_obtida) 
                VALUES (:tentativa_id, :prova_id, :questao_id, :aluno_id, :alternativa_id, :resposta_texto, :resposta_boolean, :correta, :pontuacao_obtida)";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':tentativa_id' => $tentativa_id,
        ':prova_id' => $prova_id,
        ':questao_id' => $questao['id'],
        ':aluno_id' => $aluno_id,
        ':alternativa_id' => $alternativa_id,
        ':resposta_texto' => $resposta_texto,
        ':resposta_boolean' => $resposta_boolean,
        ':correta' => $correta,
        ':pontuacao_obtida' => $pontuacao_obtida
    ]);
    
    $respostas_salvas++;
}

// Calcular porcentagem
$porcentagem = ($pontuacao_total / $prova['nota_maxima']) * 100;
$aprovado = $porcentagem >= $prova['nota_minima_aprovacao'] ? 1 : 0;
$tempo_gasto = isset($_SESSION['prova_inicio']) ? (time() - $_SESSION['prova_inicio']) : 0;

// Atualizar tentativa
$sql = "UPDATE online_provas_tentativas 
        SET data_fim = NOW(), 
            data_entrega = NOW(), 
            tempo_gasto_segundos = :tempo, 
            pontuacao_total = :pontuacao, 
            porcentagem = :porcentagem, 
            aprovado = :aprovado,
            status = 'finalizada'
        WHERE id = :tentativa_id";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':tempo' => $tempo_gasto,
    ':pontuacao' => $pontuacao_total,
    ':porcentagem' => $porcentagem,
    ':aprovado' => $aprovado,
    ':tentativa_id' => $tentativa_id
]);

// Limpar sessão
unset($_SESSION['prova_inicio']);
unset($_SESSION['prova_duracao']);

// Redirecionar para resultado
header("Location: resultado_prova.php?id=$tentativa_id");
exit;
?>