<?php
// escola/pedagogico/ajax_buscar_tentativa.php

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

$tentativa_id = isset($_POST['tentativa_id']) ? (int)$_POST['tentativa_id'] : 0;
$prova_id = isset($_POST['prova_id']) ? (int)$_POST['prova_id'] : 0;

if (!$tentativa_id || !$prova_id) {
    echo json_encode([
        'success' => false, 
        'message' => 'Parâmetros inválidos',
        'redirect' => 'erro_prova.php?codigo=400&msg=parametros_invalidos'
    ]);
    exit;
}

// Buscar dados da tentativa
$sql_tentativa = "
    SELECT 
        t.*,
        e.id as aluno_id,
        e.nome as aluno_nome,
        e.matricula as aluno_matricula
    FROM online_provas_tentativas t
    JOIN estudantes e ON e.id = t.aluno_id
    WHERE t.id = :tentativa_id
";
$stmt_tentativa = $conn->prepare($sql_tentativa);
$stmt_tentativa->execute([':tentativa_id' => $tentativa_id]);
$tentativa = $stmt_tentativa->fetch(PDO::FETCH_ASSOC);

if (!$tentativa) {
    echo json_encode([
        'success' => false, 
        'message' => 'Tentativa não encontrada',
        'redirect' => 'erro_prova.php?codigo=404&msg=tentativa_nao_encontrada'
    ]);
    exit;
}

// Buscar dados da prova
$sql_prova = "
    SELECT p.*, d.nome as disciplina_nome
    FROM online_provas p
    JOIN disciplinas d ON d.id = p.disciplina_id
    WHERE p.id = :prova_id
";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    echo json_encode([
        'success' => false, 
        'message' => 'Prova não encontrada',
        'redirect' => 'erro_prova.php?codigo=404&msg=prova_nao_encontrada&id=' . $prova_id
    ]);
    exit;
}

// Buscar respostas do aluno
$sql_respostas = "
    SELECT 
        r.*,
        q.id as questao_id,
        q.texto as questao_texto,
        q.tipo as questao_tipo,
        q.pontuacao as questao_pontuacao,
        q.opcoes as questao_opcoes
    FROM online_provas_respostas r
    JOIN online_provas_questoes q ON q.id = r.questao_id
    WHERE r.tentativa_id = :tentativa_id
    ORDER BY q.ordem ASC
";
$stmt_respostas = $conn->prepare($sql_respostas);
$stmt_respostas->execute([':tentativa_id' => $tentativa_id]);
$respostas = $stmt_respostas->fetchAll(PDO::FETCH_ASSOC);

// Processar opções das questões
foreach ($respostas as &$resposta) {
    if ($resposta['questao_tipo'] == 'multipla_escolha' && $resposta['questao_opcoes']) {
        $opcoes = json_decode($resposta['questao_opcoes'], true);
        if (is_array($opcoes)) {
            foreach ($opcoes as $opcao) {
                if ($opcao['valor'] == $resposta['resposta']) {
                    $resposta['resposta_texto'] = $opcao['texto'];
                    break;
                }
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'tentativa' => $tentativa,
    'prova' => $prova,
    'respostas' => $respostas
]);