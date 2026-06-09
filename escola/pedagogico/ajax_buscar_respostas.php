<?php
// escola/professor/ajax_buscar_respostas.php - Buscar Respostas do Aluno para Correção

require_once 'includes/auth.php';

// Verificar autenticação do professor
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];

// Receber parâmetros
$tentativa_id = isset($_POST['tentativa_id']) ? (int)$_POST['tentativa_id'] : 0;
$prova_id = isset($_POST['prova_id']) ? (int)$_POST['prova_id'] : 0;

if (!$tentativa_id || !$prova_id) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos.']);
    exit;
}

// Verificar se o professor tem permissão para corrigir esta prova
$sql_verifica = "SELECT p.id FROM online_provas p 
                 WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([
    ':prova_id' => $prova_id,
    ':funcionario_id' => $funcionario_id,
    ':escola_id' => $escola_id
]);

if (!$stmt_verifica->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Você não tem permissão para corrigir esta prova.']);
    exit;
}

// Buscar dados da tentativa
$sql_tentativa = "SELECT 
                    t.*, 
                    e.nome as aluno_nome, 
                    e.matricula as aluno_matricula,
                    e.foto as aluno_foto,
                    e.bi as aluno_bi,
                    TIMESTAMPDIFF(MINUTE, t.data_inicio, t.data_entrega) as minutos_gastos
                  FROM online_provas_tentativas t
                  JOIN estudantes e ON e.id = t.aluno_id
                  WHERE t.id = :tentativa_id";
$stmt_tentativa = $conn->prepare($sql_tentativa);
$stmt_tentativa->execute([':tentativa_id' => $tentativa_id]);
$tentativa = $stmt_tentativa->fetch(PDO::FETCH_ASSOC);

if (!$tentativa) {
    echo json_encode(['success' => false, 'message' => 'Tentativa não encontrada.']);
    exit;
}

// Buscar nota máxima da prova
$sql_prova = "SELECT 
                p.titulo, 
                p.nota_maxima, 
                p.nota_minima_aprovacao,
                p.duracao_minutos,
                d.nome as disciplina_nome
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              WHERE p.id = :prova_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id]);
$prova_info = $stmt_prova->fetch(PDO::FETCH_ASSOC);

// Buscar questões da prova
$sql_questoes = "SELECT q.*, 
                  (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = q.id) as num_alternativas
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar respostas para cada questão
$respostas = [];
foreach ($questoes as $questao) {
    // Buscar resposta do aluno
    $sql_resposta = "SELECT r.*, 
                            (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = r.questao_id AND correta = 1) as corretas
                     FROM online_provas_respostas r
                     WHERE r.tentativa_id = :tentativa_id AND r.questao_id = :questao_id";
    $stmt_resposta = $conn->prepare($sql_resposta);
    $stmt_resposta->execute([
        ':tentativa_id' => $tentativa_id,
        ':questao_id' => $questao['id']
    ]);
    $resposta = $stmt_resposta->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alternativas para questão de múltipla escolha
    $alternativas = [];
    if ($questao['tipo'] == 'multipla_escolha' || $questao['tipo'] == 'verdadeiro_falso') {
        $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
        $stmt_alt = $conn->prepare($sql_alt);
        $stmt_alt->execute([':questao_id' => $questao['id']]);
        $alternativas = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $respostas[] = [
        'id' => $questao['id'],
        'enunciado' => $questao['enunciado'],
        'tipo' => $questao['tipo'],
        'pontuacao' => $questao['pontuacao'],
        'resposta' => $resposta['resposta_texto'] ?? null,
        'alternativa_id' => $resposta['alternativa_id'] ?? null,
        'correta' => $resposta['correta'] ?? 0,
        'alternativas' => $alternativas,
        'num_alternativas' => $questao['num_alternativas']
    ];
}

// Buscar total de questões dissertativas (que precisam de correção manual)
$sql_dissertativas = "SELECT COUNT(*) as total FROM online_provas_questoes 
                      WHERE prova_id = :prova_id AND tipo = 'dissertativa'";
$stmt_dissertativas = $conn->prepare($sql_dissertativas);
$stmt_dissertativas->execute([':prova_id' => $prova_id]);
$total_dissertativas = $stmt_dissertativas->fetch(PDO::FETCH_ASSOC)['total'];

// Formatar tempo gasto
$tempo_gasto_formatado = '';
if ($tentativa['tempo_gasto_segundos'] > 0) {
    $horas = floor($tentativa['tempo_gasto_segundos'] / 3600);
    $minutos = floor(($tentativa['tempo_gasto_segundos'] % 3600) / 60);
    $segundos = $tentativa['tempo_gasto_segundos'] % 60;
    $tempo_gasto_formatado = sprintf("%02d:%02d:%02d", $horas, $minutos, $segundos);
} else {
    $tempo_gasto_formatado = gmdate("H:i:s", ($tentativa['minutos_gastos'] ?? 0) * 60);
}

// Calcular porcentagem atual
$porcentagem_atual = $tentativa['pontuacao_total'] > 0 ? 
    round(($tentativa['pontuacao_total'] / $prova_info['nota_maxima']) * 100, 1) : 0;

// Montar resposta
$response = [
    'success' => true,
    'tentativa_id' => $tentativa_id,
    'prova_id' => $prova_id,
    'prova_titulo' => $prova_info['titulo'] ?? '',
    'disciplina_nome' => $prova_info['disciplina_nome'] ?? '',
    'aluno_id' => $tentativa['aluno_id'],
    'aluno_nome' => $tentativa['aluno_nome'],
    'aluno_matricula' => $tentativa['aluno_matricula'],
    'aluno_bi' => $tentativa['aluno_bi'] ?? '',
    'aluno_foto' => $tentativa['aluno_foto'] ?? null,
    'data_entrega' => date('d/m/Y H:i:s', strtotime($tentativa['data_entrega'])),
    'data_entrega_original' => $tentativa['data_entrega'],
    'tempo_gasto' => $tempo_gasto_formatado,
    'tempo_gasto_segundos' => (int)$tentativa['tempo_gasto_segundos'],
    'tentativa_numero' => (int)$tentativa['tentativa_numero'],
    'nota_maxima' => floatval($prova_info['nota_maxima']),
    'nota_minima' => floatval($prova_info['nota_minima_aprovacao']),
    'duracao_minutos' => intval($prova_info['duracao_minutos']),
    'pontuacao_atual' => floatval($tentativa['pontuacao_total']),
    'porcentagem_atual' => $porcentagem_atual,
    'aprovado_atual' => $tentativa['aprovado'] == 1,
    'corrigida_atual' => $tentativa['corrigida'] == 1,
    'comentario_atual' => $tentativa['comentario_professor'] ?? '',
    'total_questoes' => count($questoes),
    'total_dissertativas' => $total_dissertativas,
    'questoes' => $respostas
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>