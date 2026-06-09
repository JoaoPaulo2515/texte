<?php
// escola/pedagogico/buscar_alunos.php - Buscar Alunos para Transferência

// Ativar debug para identificar erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/auth.php';

// Verificar se o usuário está autenticado
try {
    $usuario = checkAuth();
    $conn = getConnection();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro de autenticação: ' . $e->getMessage()]);
    exit;
}

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

$escola_id = $funcionario['escola_id'];
$termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';

if (strlen($termo) < 2) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// CORRIGIDO: Usar o mesmo placeholder para todas as buscas
// ou usar named placeholders diferentes
$sql = "
    SELECT DISTINCT
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.data_nascimento,
        m.id as matricula_id,
        m.status as matricula_status
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    WHERE e.escola_id = :escola_id
    AND e.status = 'ativo'
    AND (e.nome LIKE :termo1 OR e.matricula LIKE :termo2 OR e.bi LIKE :termo3)
    ORDER BY e.nome ASC
    LIMIT 20
";

try {
    $stmt = $conn->prepare($sql);
    $termo_like = "%$termo%";
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':termo1' => $termo_like,
        ':termo2' => $termo_like,
        ':termo3' => $termo_like
    ]);
    
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar os dados para retorno
    $resultado = [];
    foreach ($alunos as $aluno) {
        $resultado[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'bi' => $aluno['bi'],
            'data_nascimento' => $aluno['data_nascimento'],
            'matricula_id' => $aluno['matricula_id'],
            'matricula_status' => $aluno['matricula_status']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($resultado);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro na consulta: ' . $e->getMessage()]);
}
?>