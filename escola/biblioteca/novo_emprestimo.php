<?php
// escola/biblioteca/novo_emprestimo.php - Processar novo empréstimo

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$is_admin = ($_SESSION['usuario_tipo'] == 'super_admin' || $_SESSION['usuario_tipo'] == 'admin_escola' || $_SESSION['usuario_tipo'] == 'diretor');
$is_bibliotecario = ($_SESSION['papel'] == 'bibliotecario' || $is_admin);

if (!$is_bibliotecario) {
    header('Location: index.php?msg=acesso_negado');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_emprestimo'])) {
    $usuario_id = (int)$_POST['usuario_id'];
    $livro_id = (int)$_POST['livro_id'];
    $data_emprestimo = $_POST['data_emprestimo'];
    $data_devolucao = $_POST['data_devolucao'];
    $observacoes = $_POST['observacoes'] ?? '';
    
    if ($usuario_id <= 0 || $livro_id <= 0) {
        header('Location: emprestimos.php?msg=erro');
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Verificar disponibilidade do livro - com escola_id
        $sql_check = "SELECT quantidade_disponivel FROM livros WHERE id = :id AND status = 'disponivel' AND escola_id = :escola_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':id' => $livro_id, ':escola_id' => $escola_id]);
        $livro = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$livro || $livro['quantidade_disponivel'] <= 0) {
            throw new Exception("Livro não disponível para empréstimo.");
        }
        
        // Verificar limite de empréstimos do usuário - com escola_id
        $sql_limite = "SELECT COUNT(*) as total FROM emprestimos WHERE usuario_id = :usuario_id AND status = 'ativo' AND escola_id = :escola_id";
        $stmt_limite = $conn->prepare($sql_limite);
        $stmt_limite->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
        $total_ativos = $stmt_limite->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total_ativos >= 3) {
            throw new Exception("Usuário já possui 3 empréstimos ativos.");
        }
        
        // Registrar empréstimo - com escola_id
        $sql = "INSERT INTO emprestimos (livro_id, usuario_id, escola_id, data_emprestimo, data_devolucao_prevista, observacoes, status, created_at) 
                VALUES (:livro_id, :usuario_id, :escola_id, :data_emprestimo, :data_devolucao, :observacoes, 'ativo', NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':livro_id' => $livro_id,
            ':usuario_id' => $usuario_id,
            ':escola_id' => $escola_id,
            ':data_emprestimo' => $data_emprestimo,
            ':data_devolucao' => $data_devolucao,
            ':observacoes' => $observacoes
        ]);
        
        // Atualizar quantidade disponível do livro - com escola_id
        $sql_update = "UPDATE livros SET quantidade_disponivel = quantidade_disponivel - 1 WHERE id = :id AND escola_id = :escola_id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([':id' => $livro_id, ':escola_id' => $escola_id]);
        
        $conn->commit();
        header('Location: emprestimos.php?msg=success');
    } catch (Exception $e) {
        $conn->rollBack();
        header('Location: emprestimos.php?msg=error&erro=' . urlencode($e->getMessage()));
    }
} else {
    header('Location: emprestimos.php');
}
exit;
?>