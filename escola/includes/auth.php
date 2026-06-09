<?php
// escola/includes/auth.php - Autenticação para áreas da escola (Professor, Pedagógico, Aluno)

session_start();
require_once dirname(__DIR__, 2) . '/config/database.php';

/**
 * Verifica se o usuário está autenticado na área escolar
 * @return array Dados do usuário autenticado
 * @throws Exception Se não estiver autenticado
 */
function checkAuth() {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Buscar dados atualizados do usuário
    $sql = "SELECT u.*, 
                   CASE 
                       WHEN u.tipo = 'aluno' THEN (SELECT e.nome FROM estudantes e WHERE e.usuario_id = u.id LIMIT 1)
                       WHEN u.tipo = 'professor' THEN (SELECT f.nome FROM funcionarios f WHERE f.usuario_id = u.id LIMIT 1)
                       ELSE u.nome
                   END as nome_completo
            FROM usuarios u
            WHERE u.id = :id AND u.status = 'ativo'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $_SESSION['usuario_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    return $usuario;
}

/**
 * Verifica se o usuário é um professor
 * @return array Dados do professor
 */
function checkProfessorAuth() {
    $usuario = checkAuth();
    
    if ($usuario['tipo'] !== 'professor' && $usuario['tipo'] !== 'admin') {
        die("Acesso negado. Área restrita para professores.");
    }
    
    // Buscar dados do professor
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $sql = "SELECT f.*, f.id as professor_id, f.nome, f.escola_id
            FROM funcionarios f
            WHERE f.usuario_id = :usuario_id AND f.cargo = 'professor'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $usuario['id']]);
    $professor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$professor) {
        // Se não encontrou como professor, tentar buscar qualquer funcionário
        $sql2 = "SELECT f.*, f.id as professor_id, f.nome, f.escola_id
                 FROM funcionarios f
                 WHERE f.usuario_id = :usuario_id";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->execute([':usuario_id' => $usuario['id']]);
        $professor = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$professor) {
        die("Professor não encontrado. Contacte o administrador.");
    }
    
    // Adicionar nome da escola
    $sql_escola = "SELECT nome as escola_nome FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $professor['escola_id']]);
    $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
    $professor['escola_nome'] = $escola ? $escola['escola_nome'] : '';
    
    return $professor;
}

/**
 * Verifica se o usuário é um aluno
 * @return array Dados do aluno
 */
function checkAlunoAuth() {
    $usuario = checkAuth();
    
    if ($usuario['tipo'] !== 'aluno' && $usuario['tipo'] !== 'admin') {
        die("Acesso negado. Área restrita para alunos.");
    }
    
    // Buscar dados do aluno
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $sql = "SELECT e.*, e.id as aluno_id, e.nome, e.escola_id
            FROM estudantes e
            WHERE e.usuario_id = :usuario_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $usuario['id']]);
    $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno) {
        die("Aluno não encontrado. Contacte o administrador.");
    }
    
    // Adicionar nome da escola
    $sql_escola = "SELECT nome as escola_nome FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $aluno['escola_id']]);
    $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
    $aluno['escola_nome'] = $escola ? $escola['escola_nome'] : '';
    
    return $aluno;
}

/**
 * Verifica se o usuário tem permissão para área pedagógica
 * @return array Dados do funcionário pedagógico
 */
function checkPedagogicoAuth() {
    $usuario = checkAuth();
    
    // Verificar se o usuário tem cargo pedagógico
    $cargos_permitidos = ['pedagogico', 'coordenador', 'diretor', 'admin'];
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
   // ============================================
// VERIFICAR PERMISSÃO NA TABELA USUARIOS E FUNCIONARIOS
// ============================================
$sql_verifica = "
    SELECT f.*, 
           u.id as usuario_id,
           u.usuario,
           u.email,
           u.tipo as usuario_tipo,
           (SELECT COUNT(*) FROM conselho_nota_permissoes WHERE funcionario_id = f.id AND ativo = 1) as tem_permissao_conselho
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
    AND f.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied_negado.php';
    exit;
}
$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];
    
    // Adicionar nome da escola
    $sql_escola = "SELECT nome as escola_nome FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $funcionario['escola_id']]);
    $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
    $funcionario['escola_nome'] = $escola ? $escola['escola_nome'] : '';
    
    // Verificar se tem permissão para o conselho de nota
    $sql_perm = "SELECT COUNT(*) as tem_perm FROM conselho_nota_permissoes 
                 WHERE funcionario_id = :funcionario_id AND ativo = 1";
    $stmt_perm = $conn->prepare($sql_perm);
    $stmt_perm->execute([':funcionario_id' => $funcionario['funcionario_id']]);
    $perm = $stmt_perm->fetch(PDO::FETCH_ASSOC);
    $funcionario['tem_permissao_conselho'] = $perm['tem_perm'] > 0;
    
    return $funcionario;
}

/**
 * Verifica se o usuário está logado (redireciona se não estiver)
 */
function requireLogin() {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Verifica se o usuário está logado como professor
 */
function requireProfessor() {
    requireLogin();
    $usuario = checkAuth();
    if ($usuario['tipo'] !== 'professor' && $usuario['tipo'] !== 'admin') {
        header('Location: ../dashboard.php');
        exit;
    }
}

/**
 * Verifica se o usuário está logado como aluno
 */
function requireAluno() {
    requireLogin();
    $usuario = checkAuth();
    if ($usuario['tipo'] !== 'aluno' && $usuario['tipo'] !== 'admin') {
        header('Location: ../dashboard.php');
        exit;
    }
}

/**
 * Verifica se o usuário está logado na área pedagógica
 */
function requirePedagogico() {
    requireLogin();
    $cargos_permitidos = ['pedagogo', 'coordenador', 'diretor', 'admin'];
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $sql = "SELECT cargo FROM funcionarios WHERE usuario_id = :usuario_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $_SESSION['usuario_id']]);
    $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!in_array($funcionario['cargo'], $cargos_permitidos) && $_SESSION['user_type'] !== 'admin') {
        header('Location: ../dashboard.php');
        exit;
    }
}

/**
 * Retorna a conexão com o banco de dados
 * @return PDO
 */
function getConnection() {
    $db = Database::getInstance();
    return $db->getConnection();
}

/**
 * Faz logout do usuário
 */
function logout() {
    // Registrar saída na tabela sessoes_ativas
    if (isset($_SESSION['usuario_id']) && isset($_SESSION['escola_id'])) {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        try {
            $sql = "DELETE FROM sessoes_ativas WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':escola_id' => $_SESSION['escola_id']
            ]);
        } catch (PDOException $e) {
            // Ignorar erro
        }
    }
    
    // Destruir sessão
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    session_destroy();
    
    header('Location: login.php');
    exit;
}