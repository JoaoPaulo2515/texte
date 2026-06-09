<?php
// escola/biblioteca/cadastrar.php - Cadastro de Livros (Apenas Admin)
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

// Verificar permissão (apenas admin, diretor ou secretaria)
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $usuario_tipo == 'secretaria');

if (!$is_admin) {
    header('Location: index.php?error=Acesso negado');
    exit;
}

// Buscar disciplinas para associar - com escola_id
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Função para upload de arquivo
function uploadArquivo($arquivo, $pasta, $tipos_permitidos = ['pdf', 'jpg', 'jpeg', 'png'], $tamanho_maximo = 10485760) {
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
    
    if (empty($titulo)) {
        $error = "Preencha o título do livro.";
    } else {
        // Upload da capa
        $capa = uploadArquivo($_FILES['capa'] ?? null, __DIR__ . '/../../uploads/livros/capas/', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 2097152);
        
        // Upload do arquivo
        $arquivo = uploadArquivo($_FILES['arquivo'] ?? null, __DIR__ . '/../../uploads/livros/arquivos/', ['pdf'], 20971520);
        
        if ($_FILES['arquivo']['error'] != 0) {
            $error = "O arquivo do livro é obrigatório.";
        } elseif ($arquivo === false) {
            $error = "Formato de arquivo inválido. Use apenas PDF.";
        } else {
            try {
                // Inserir livro com escola_id
                $stmt = $conn->prepare("
                    INSERT INTO livros (
                        escola_id, titulo, autor, editora, ano_publicacao, isbn,
                        categoria, disciplina_id, descricao, capa, arquivo, status, created_at
                    ) VALUES (
                        :escola_id, :titulo, :autor, :editora, :ano_publicacao, :isbn,
                        :categoria, :disciplina_id, :descricao, :capa, :arquivo, 'disponivel', NOW()
                    )
                ");
                
                $stmt->execute([
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
                    ':arquivo' => $arquivo
                ]);
                
                $success = "Livro cadastrado com sucesso!";
                
                // Log com escola_id
                $stmt = $conn->prepare("
                    INSERT INTO logs_sistema (usuario_id, escola_id, acao, tabela, registro_id, ip, created_at)
                    VALUES (:usuario_id, :escola_id, 'cadastrar_livro', 'livros', :registro_id, :ip, NOW())
                ");
                $stmt->execute([
                    ':usuario_id' => $_SESSION['usuario_id'],
                    ':escola_id' => $escola_id,
                    ':registro_id' => $conn->lastInsertId(),
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);
                
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Livro | SIGE Angola</title>
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
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 1px solid #ddd; }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-plus"></i> Cadastrar Livro</h2>
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
                                <input type="text" name="titulo" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>ISBN</label>
                                <input type="text" name="isbn" class="form-control" placeholder="Ex: 978-3-16-148410-0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Autor</label>
                                <input type="text" name="autor" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Editora</label>
                                <input type="text" name="editora" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Ano de Publicação</label>
                                <input type="number" name="ano_publicacao" class="form-control" min="1900" max="<?php echo date('Y'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Categoria</label>
                                <input type="text" name="categoria" class="form-control" placeholder="Ex: Didático, Literatura, Técnico">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Disciplina Associada</label>
                                <select name="disciplina_id" class="form-control">
                                    <option value="">Nenhuma</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Descrição</label>
                        <textarea name="descricao" class="form-control" rows="3" placeholder="Sinopse do livro..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Capa do Livro</label>
                                <input type="file" name="capa" class="form-control" accept="image/*">
                                <small class="text-muted">Formatos: JPG, PNG, GIF (Max: 2MB)</small>
                                <div class="mt-2">
                                    <img id="previewCapa" class="preview-img" style="display: none;">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="required">Arquivo do Livro (PDF)</label>
                                <input type="file" name="arquivo" class="form-control" accept=".pdf" required>
                                <small class="text-muted">Apenas PDF (Max: 20MB)</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        O arquivo do livro deve estar em formato PDF para que possa ser visualizado online.
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Cadastrar Livro
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
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Preview da capa
        $('input[name="capa"]').change(function() {
            var file = this.files[0];
            if (file) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    $('#previewCapa').attr('src', e.target.result).show();
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>