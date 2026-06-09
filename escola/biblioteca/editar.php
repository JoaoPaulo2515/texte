<?php
// escola/biblioteca/editar.php - Editar Livro (Apenas Admin)
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];

// Verificar permissão
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $usuario_tipo == 'secretaria');

if (!$is_admin) {
    header('Location: index.php?error=Acesso negado');
    exit;
}

$id = $_GET['id'] ?? 0;

// Buscar dados do livro - com escola_id
$stmt = $conn->prepare("SELECT * FROM livros WHERE id = :id AND escola_id = :escola_id");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$livro = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$livro) {
    header('Location: index.php?error=Livro não encontrado');
    exit;
}

// Buscar disciplinas - com escola_id
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

function uploadArquivoEdit($arquivo, $pasta, $tipos_permitidos = ['pdf', 'jpg', 'jpeg', 'png'], $tamanho_maximo = 10485760) {
    if (!isset($arquivo) || $arquivo['error'] != 0) {
        return null;
    }
    
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tipos_permitidos)) {
        return false;
    }
    
    if ($arquivo['size'] > $tamanho_maximo) {
        return false;
    }
    
    if (!is_dir($pasta)) {
        mkdir($pasta, 0777, true);
    }
    
    $nome_arquivo = time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($arquivo['tmp_name'], $pasta . $nome_arquivo)) {
        return $nome_arquivo;
    }
    
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $_POST['titulo'] ?? '';
    $autor = $_POST['autor'] ?? '';
    $editora = $_POST['editora'] ?? '';
    $ano_publicacao = $_POST['ano_publicacao'] ?? '';
    $isbn = $_POST['isbn'] ?? '';
    $categoria = $_POST['categoria'] ?? '';
    $disciplina_id = $_POST['disciplina_id'] ?? null;
    $descricao = $_POST['descricao'] ?? '';
    $status = $_POST['status'] ?? 'disponivel';
    
    if (empty($titulo)) {
        $error = "Preencha o título do livro.";
    } else {
        // Upload da capa
        $capa = $livro['capa'];
        if (isset($_FILES['capa']) && $_FILES['capa']['error'] == 0) {
            $nova_capa = uploadArquivoEdit($_FILES['capa'], __DIR__ . '/../../uploads/livros/capas/', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 2097152);
            if ($nova_capa) {
                // Remover capa antiga
                if ($capa && file_exists(__DIR__ . '/../../uploads/livros/capas/' . $capa)) {
                    unlink(__DIR__ . '/../../uploads/livros/capas/' . $capa);
                }
                $capa = $nova_capa;
            }
        }
        
        // Upload do arquivo
        $arquivo = $livro['arquivo'];
        if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0) {
            $novo_arquivo = uploadArquivoEdit($_FILES['arquivo'], __DIR__ . '/../../uploads/livros/arquivos/', ['pdf'], 20971520);
            if ($novo_arquivo) {
                if ($arquivo && file_exists(__DIR__ . '/../../uploads/livros/arquivos/' . $arquivo)) {
                    unlink(__DIR__ . '/../../uploads/livros/arquivos/' . $arquivo);
                }
                $arquivo = $novo_arquivo;
            }
        }
        
        try {
            $stmt = $conn->prepare("
                UPDATE livros SET
                    titulo = :titulo,
                    autor = :autor,
                    editora = :editora,
                    ano_publicacao = :ano_publicacao,
                    isbn = :isbn,
                    categoria = :categoria,
                    disciplina_id = :disciplina_id,
                    descricao = :descricao,
                    capa = :capa,
                    arquivo = :arquivo,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':escola_id' => $escola_id,
                ':titulo' => $titulo,
                ':autor' => $autor ?: null,
                ':editora' => $editora ?: null,
                ':ano_publicacao' => $ano_publicacao ?: null,
                ':isbn' => $isbn ?: null,
                ':categoria' => $categoria ?: null,
                ':disciplina_id' => $disciplina_id ?: null,
                ':descricao' => $descricao ?: null,
                ':capa' => $capa,
                ':arquivo' => $arquivo,
                ':status' => $status
            ]);
            
            $success = "Livro atualizado com sucesso!";
            
            // Log com escola_id
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, escola_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, :escola_id, 'editar_livro', 'livros', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':escola_id' => $escola_id,
                ':registro_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Livro | SIGE Angola</title>
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
        .preview-img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Editar Livro: <?php echo htmlspecialchars($livro['titulo']); ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Dados do Livro</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="required">Título do Livro</label>
                                <input type="text" name="titulo" class="form-control" value="<?php echo htmlspecialchars($livro['titulo']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>ISBN</label>
                                <input type="text" name="isbn" class="form-control" value="<?php echo htmlspecialchars($livro['isbn']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Autor</label>
                                <input type="text" name="autor" class="form-control" value="<?php echo htmlspecialchars($livro['autor']); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Editora</label>
                                <input type="text" name="editora" class="form-control" value="<?php echo htmlspecialchars($livro['editora']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Ano de Publicação</label>
                                <input type="number" name="ano_publicacao" class="form-control" value="<?php echo $livro['ano_publicacao']; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Categoria</label>
                                <input type="text" name="categoria" class="form-control" value="<?php echo htmlspecialchars($livro['categoria']); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Disciplina Associada</label>
                                <select name="disciplina_id" class="form-control">
                                    <option value="">Nenhuma</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>" <?php echo $livro['disciplina_id'] == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3"><?php echo htmlspecialchars($livro['descricao']); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Capa do Livro</label>
                                <input type="file" name="capa" class="form-control" accept="image/*">
                                <small class="text-muted">Deixe em branco para manter a capa atual</small>
                                <?php if ($livro['capa']): ?>
                                <div class="mt-2">
                                    <img src="../../uploads/livros/capas/<?php echo $livro['capa']; ?>" class="preview-img">
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Arquivo do Livro (PDF)</label>
                                <input type="file" name="arquivo" class="form-control" accept=".pdf">
                                <small class="text-muted">Deixe em branco para manter o arquivo atual</small>
                                <?php if ($livro['arquivo']): ?>
                                <div class="mt-2">
                                    <a href="../../uploads/livros/arquivos/<?php echo $livro['arquivo']; ?>" target="_blank" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> Ver arquivo atual
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="disponivel" <?php echo $livro['status'] == 'disponivel' ? 'selected' : ''; ?>>Disponível</option>
                                    <option value="indisponivel" <?php echo $livro['status'] == 'indisponivel' ? 'selected' : ''; ?>>Indisponível</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>