<?php
// escola/biblioteca/visualizar.php
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['acao'])) {
    $id = (int)$_POST['id'];
    $acao = $_POST['acao'];
    
    if ($acao === 'visualizar') {
        // Atualizar visualizações
        $stmt = $conn->prepare("UPDATE livros SET visualizacoes = visualizacoes + 1 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        // Buscar dados do livro
        $stmt = $conn->prepare("SELECT titulo, arquivo FROM livros WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $livro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($livro) {
            $extensao = pathinfo($livro['arquivo'], PATHINFO_EXTENSION);
            $conteudo = '';
            
            if (in_array($extensao, ['jpg', 'jpeg', 'png', 'gif'])) {
                $conteudo = '<img src="../../uploads/livros/' . $livro['arquivo'] . '" class="img-fluid" alt="' . htmlspecialchars($livro['titulo']) . '">';
            } elseif ($extensao === 'pdf') {
                $conteudo = '<iframe src="../../uploads/livros/' . $livro['arquivo'] . '" class="document-viewer"></iframe>';
            } else {
                $conteudo = '<div class="alert alert-info"><i class="fas fa-download"></i> <a href="../../uploads/livros/' . $livro['arquivo'] . '" download>Clique aqui para baixar o arquivo</a></div>';
            }
            
            echo json_encode([
                'titulo' => $livro['titulo'],
                'conteudo' => $conteudo
            ]);
        } else {
            echo json_encode(['error' => 'Livro não encontrado']);
        }
    }
} elseif (isset($_GET['id'], $_GET['acao']) && $_GET['acao'] === 'download') {
    $id = (int)$_GET['id'];
    
    // Atualizar downloads
    $stmt = $conn->prepare("UPDATE livros SET downloads = downloads + 1 WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    // Buscar arquivo
    $stmt = $conn->prepare("SELECT arquivo FROM livros WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $livro = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($livro && $livro['arquivo']) {
        $arquivo_path = __DIR__ . '/../../uploads/livros/' . $livro['arquivo'];
        if (file_exists($arquivo_path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($livro['arquivo']) . '"');
            readfile($arquivo_path);
            exit;
        }
    }
}
?>