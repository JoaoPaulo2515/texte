<?php
// escola/biblioteca/visualizar.php - Visualizar e Baixar Livro
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não exibir erros diretamente na resposta AJAX

require_once __DIR__ . '/../../config/database.php';
session_start();

// Configurar cabeçalho para JSON quando for requisição AJAX
if (isset($_POST['acao']) && $_POST['acao'] === 'visualizar') {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    if (isset($_POST['acao']) && $_POST['acao'] === 'visualizar') {
        echo json_encode(['error' => 'Sessão expirada. Faça login novamente.']);
        exit;
    }
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar se é requisição POST (AJAX) ou GET (download)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['acao'])) {
    $id = (int)$_POST['id'];
    $acao = $_POST['acao'];
    
    if ($acao === 'visualizar') {
        try {
            // Buscar dados do livro - apenas colunas que existem
            $stmt = $conn->prepare("
                SELECT id, titulo, autor, categoria, descricao, capa, arquivo, visualizacoes, downloads 
                FROM livros 
                WHERE id = :id AND escola_id = :escola_id AND status = 'disponivel'
            ");
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            $livro = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$livro) {
                echo json_encode(['error' => 'Livro não encontrado ou indisponível.']);
                exit;
            }
            
            // Incrementar contador de visualizações
            $stmt = $conn->prepare("UPDATE livros SET visualizacoes = visualizacoes + 1 WHERE id = :id AND escola_id = :escola_id");
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            
            // Verificar se arquivo existe
            $arquivo_path = __DIR__ . '/../../uploads/livros/arquivos/' . $livro['arquivo'];
            $capa_path = !empty($livro['capa']) && file_exists(__DIR__ . '/../../uploads/livros/capas/' . $livro['capa']) 
                ? '../../uploads/livros/capas/' . $livro['capa'] 
                : null;
            
            // Gerar HTML para o modal
            $html = '
            <div class="row">
                <div class="col-md-4 text-center">
                    ' . ($capa_path ? 
                        '<img src="' . $capa_path . '" class="img-fluid rounded mb-3" style="max-height: 300px;">' : 
                        '<div class="bg-light p-5 rounded mb-3"><i class="fas fa-book fa-5x text-muted"></i></div>') . '
                    <h5>' . htmlspecialchars($livro['titulo']) . '</h5>
                    <p class="text-muted">' . htmlspecialchars($livro['autor'] ?? 'Autor desconhecido') . '</p>
                    <div class="mt-3">
                        <a href="visualizar.php?id=' . $id . '&acao=download" class="btn btn-success w-100">
                            <i class="fas fa-download"></i> Baixar
                        </a>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-eye"></i> ' . number_format($livro['visualizacoes'] ?? 0) . ' visualizações<br>
                            <i class="fas fa-download"></i> ' . number_format($livro['downloads'] ?? 0) . ' downloads
                        </small>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="mb-3">
                        <strong>Categoria:</strong> ' . htmlspecialchars($livro['categoria'] ?? 'Não informada') . '<br>
                    </div>
                    <div class="mb-3">
                        <strong>Descrição:</strong><br>
                        <p>' . nl2br(htmlspecialchars($livro['descricao'] ?? 'Sem descrição disponível.')) . '</p>
                    </div>
                    <hr>
                    <div class="text-center">
                        <a href="visualizar.php?id=' . $id . '&acao=download" class="btn btn-success">
                            <i class="fas fa-download"></i> Baixar PDF
                        </a>
                    </div>
                    ' . (file_exists($arquivo_path) && $livro['arquivo'] ? '
                    <div class="mt-3 alert alert-info">
                        <i class="fas fa-info-circle"></i> Clique em "Baixar PDF" para fazer o download do arquivo.
                    </div>' : '
                    <div class="alert alert-warning text-center mt-3">
                        <i class="fas fa-exclamation-triangle"></i> Arquivo não disponível para visualização online.
                    </div>') . '
                </div>
            </div>';
            
            echo json_encode([
                'success' => true,
                'titulo' => $livro['titulo'],
                'conteudo' => $html
            ]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['error' => 'Erro ao carregar livro: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Se for download (GET)
if (isset($_GET['id'], $_GET['acao']) && $_GET['acao'] === 'download') {
    $id = (int)$_GET['id'];
    
    // Buscar arquivo - com escola_id
    $stmt = $conn->prepare("SELECT titulo, arquivo FROM livros WHERE id = :id AND escola_id = :escola_id AND status = 'disponivel'");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $livro = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($livro && $livro['arquivo']) {
        // Atualizar contador de downloads
        $stmt = $conn->prepare("UPDATE livros SET downloads = downloads + 1 WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        
        $arquivo_path = __DIR__ . '/../../uploads/livros/arquivos/' . $livro['arquivo'];
        if (file_exists($arquivo_path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . urlencode($livro['titulo']) . '"');
            header('Content-Length: ' . filesize($arquivo_path));
            header('Cache-Control: no-cache');
            readfile($arquivo_path);
            exit;
        } else {
            header('Location: index.php?error=Arquivo não encontrado');
            exit;
        }
    } else {
        header('Location: index.php?error=Livro não encontrado');
        exit;
    }
}

// Se chegou aqui, redirecionar para index
header('Location: index.php');
exit;
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($livro['titulo']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .pdf-viewer { width: 100%; height: 600px; border: none; border-radius: 10px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-book-open"></i> <?php echo htmlspecialchars($livro['titulo']); ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <?php 
                        $capa_path = !empty($livro['capa']) && file_exists(__DIR__ . '/../../uploads/livros/capas/' . $livro['capa']) 
                            ? '../../uploads/livros/capas/' . $livro['capa'] 
                            : null;
                        ?>
                        <?php if ($capa_path): ?>
                            <img src="<?php echo $capa_path; ?>" class="img-fluid rounded mb-3" style="max-height: 250px;">
                        <?php else: ?>
                            <div class="bg-light p-5 rounded mb-3">
                                <i class="fas fa-book fa-5x text-muted"></i>
                            </div>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($livro['titulo']); ?></h5>
                        <p class="text-muted"><?php echo htmlspecialchars($livro['autor'] ?? 'Autor desconhecido'); ?></p>
                        
                        <div class="mt-3">
                            <a href="visualizar.php?id=<?php echo $id; ?>&acao=download" class="btn btn-success w-100 mb-2">
                                <i class="fas fa-download"></i> Baixar PDF
                            </a>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="mb-3">
                            <strong>Autor:</strong> <?php echo htmlspecialchars($livro['autor'] ?? '-'); ?><br>
                            <strong>Categoria:</strong> <?php echo htmlspecialchars($livro['categoria'] ?? '-'); ?><br>
                            <strong>Visualizações:</strong> <?php echo number_format($livro['visualizacoes'] ?? 0); ?><br>
                            <strong>Downloads:</strong> <?php echo number_format($livro['downloads'] ?? 0); ?>
                        </div>
                        <div class="mb-3">
                            <strong>Descrição:</strong><br>
                            <p><?php echo nl2br(htmlspecialchars($livro['descricao'] ?? 'Sem descrição')); ?></p>
                        </div>
                        
                        <hr>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Pré-visualização do PDF disponível abaixo. Clique em "Baixar PDF" para salvar o arquivo.
                        </div>
                        
                        <?php 
                        $arquivo_path = __DIR__ . '/../../uploads/livros/arquivos/' . $livro['arquivo'];
                        if (file_exists($arquivo_path) && $livro['arquivo']): 
                        ?>
                        <iframe src="../../uploads/livros/arquivos/<?php echo $livro['arquivo']; ?>" class="pdf-viewer"></iframe>
                        <?php else: ?>
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle"></i> Pré-visualização não disponível.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { 
            $('.sidebar').toggleClass('open'); 
        });
    </script>
</body>
</html>