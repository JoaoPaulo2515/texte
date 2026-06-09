<?php
// escola/pedagogico/buscar_turma.php - Buscar Detalhes da Turma (AJAX)

// Desabilitar exibição de erros para não corromper o JSON
error_reporting(0);
ini_set('display_errors', 0);

// Limpar qualquer saída anterior
if (ob_get_level()) ob_clean();

require_once __DIR__ . '/../includes/auth.php';

// Função para retornar erro em JSON
function returnError($message) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Verificar autenticação
try {
    $usuario = checkAuth();
    $conn = getConnection();
} catch (Exception $e) {
    returnError('Erro de autenticação: ' . $e->getMessage());
}

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    returnError('Acesso negado. Usuário não tem permissão.');
}

$escola_id = $funcionario['escola_id'];
$turma_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($turma_id <= 0) {
    returnError('ID da turma não informado');
}

try {
    // Buscar dados da turma
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            t.turno_id,
            tr.nome as turno_nome,
            t.sala_id,
            s.nome as sala_nome,
            s.capacidade as sala_capacidade,
            t.capacidade,
            t.vagas_disponiveis,
             COUNT(DISTINCT m.id) as numero_alunos,
            t.horario,
            t.status,
            t.data_inicio,
            t.data_fim,
            t.created_at,
            t.updated_at,
            c.id as curso_id,
            c.nome as curso_nome,
            cl.id as classe_id,
            cl.nome as classe_nome,
            cl.nivel as classe_ano,
            al.id as ano_letivo_id,
            al.ano as ano_letivo_ano,
            al.data_inicio as ano_letivo_inicio,
            al.data_fim as ano_letivo_fim
        FROM turmas t 
        LEFT JOIN matriculas m ON m.turma_id = t.id AND m.ano_letivo = t.ano_letivo_id AND m.status = 'ativa'
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN salas s ON s.id = t.sala_id
        LEFT JOIN cursos c ON c.id = t.curso_id
        LEFT JOIN classes cl ON cl.id = t.classe_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([
        ':turma_id' => $turma_id,
        ':escola_id' => $escola_id
    ]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if (!$turma) {
        returnError('Turma não encontrada');
    }
    
    // Buscar disciplinas da turma
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar professores da turma
    $sql_professores = "
        SELECT DISTINCT
            p.id,
            p.nome,
            p.email,
            p.telefone,
            d.nome as disciplina_nome
        FROM professor_disciplina_turma tp
        INNER JOIN funcionarios p ON p.id = tp.professor_id
        INNER JOIN disciplinas d ON d.id = tp.disciplina_id
        WHERE tp.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_professores = $conn->prepare($sql_professores);
    $stmt_professores->execute([':turma_id' => $turma_id]);
    $professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            m.status as matricula_status,
            m.data_matricula
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome ASC
        LIMIT 20
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular percentual de ocupação
    $percentual_ocupacao = 0;
    if ($turma['capacidade'] > 0) {
        $percentual_ocupacao = round(($turma['numero_alunos'] / $turma['capacidade']) * 100, 1);
    }
    
    // Preparar resposta
    $response = [
        'success' => true,
        'id' => $turma['id'],
        'nome' => $turma['nome'],
        'ano' => $turma['ano'],
        'turno' => $turma['turno_nome'] ?? 'Não definido',
        'sala' => $turma['sala_nome'] ?? 'Não definida',
        'capacidade' => $turma['capacidade'] ?? 0,
        'vagas_disponiveis' => $turma['vagas_disponiveis'] ?? 0,
        'numero_alunos' => $turma['numero_alunos'] ?? 0,
        'horario' => $turma['horario'] ?? 'Não definido',
        'status' => $turma['status'] ?? 'inativa',
        'data_inicio' => $turma['data_inicio'],
        'data_fim' => $turma['data_fim'],
        'curso' => $turma['curso_nome'] ?? 'Nenhum',
        'classe' => $turma['classe_nome'] ?? 'Não definida',
        'ano_letivo' => $turma['ano_letivo_ano'] ?? date('Y'),
        'percentual_ocupacao' => $percentual_ocupacao,
        'total_alunos' => $turma['numero_alunos'] ?? 0,
        'disciplinas' => $disciplinas,
        'professores' => $professores,
        'alunos' => $alunos
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    returnError('Erro no banco de dados: ' . $e->getMessage());
} catch (Exception $e) {
    returnError('Erro: ' . $e->getMessage());
}
?>