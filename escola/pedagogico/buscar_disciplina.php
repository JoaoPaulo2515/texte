<?php
// escola/pedagogico/buscar_disciplina.php - Buscar Detalhes da Disciplina (AJAX)

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
$disciplina_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($disciplina_id <= 0) {
    returnError('ID da disciplina não informado');
}

try {
    // Buscar dados da disciplina
    $sql_disciplina = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria,
            d.descricao,
            d.cor,
            d.status,
            d.created_at,
            d.updated_at,
            d.curso_id,
            c.nome as curso_nome,
            COUNT(DISTINCT dt.turma_id) as total_turmas,
            COUNT(DISTINCT pdt.professor_id) as total_professores
        FROM disciplinas d
        LEFT JOIN cursos c ON c.id = d.curso_id
        LEFT JOIN disciplina_turma dt ON dt.disciplina_id = d.id
        LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
        WHERE d.id = :disciplina_id AND d.escola_id = :escola_id
        GROUP BY d.id
    ";
    $stmt_disciplina = $conn->prepare($sql_disciplina);
    $stmt_disciplina->execute([
        ':disciplina_id' => $disciplina_id,
        ':escola_id' => $escola_id
    ]);
    $disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
    
    if (!$disciplina) {
        returnError('Disciplina não encontrada');
    }
    
    // Buscar turmas detalhadas onde a disciplina é usada
    $sql_turmas = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            al.ano as ano_letivo
        FROM disciplina_turma dt
        INNER JOIN turmas t ON t.id = dt.turma_id
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE dt.disciplina_id = :disciplina_id
        ORDER BY t.ano ASC, t.nome ASC
        LIMIT 10
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':disciplina_id' => $disciplina_id]);
    $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar professores detalhados
    $sql_professores = "
        SELECT 
            p.id,
            p.nome,
            p.email,
            p.telefone,
            t.nome as turma_nome
        FROM professor_disciplina_turma pdt
        INNER JOIN funcionarios p ON p.id = pdt.professor_id
        INNER JOIN turmas t ON t.id = pdt.turma_id
        WHERE pdt.disciplina_id = :disciplina_id
        ORDER BY p.nome ASC
        LIMIT 10
    ";
    $stmt_professores = $conn->prepare($sql_professores);
    $stmt_professores->execute([':disciplina_id' => $disciplina_id]);
    $professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);
    
    // Preparar resposta
    $response = [
        'id' => $disciplina['id'],
        'nome' => $disciplina['nome'],
        'codigo' => $disciplina['codigo'],
        'carga_horaria' => $disciplina['carga_horaria'],
        'descricao' => $disciplina['descricao'],
        'cor' => $disciplina['cor'],
        'status' => $disciplina['status'],
        'created_at' => $disciplina['created_at'],
        'updated_at' => $disciplina['updated_at'],
        'curso_id' => $disciplina['curso_id'],
        'curso_nome' => $disciplina['curso_nome'] ?? 'Geral',
        'total_turmas' => $disciplina['total_turmas'] ?? 0,
        'total_professores' => $disciplina['total_professores'] ?? 0,
        'turmas' => $turmas,
        'professores' => $professores
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    returnError('Erro no banco de dados: ' . $e->getMessage());
} catch (Exception $e) {
    returnError('Erro: ' . $e->getMessage());
}
?>