<?php
// escola/professores/cadastrar.php - Cadastro de Professor com Documentos e Foto
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y');

// Buscar dados existentes para comboboxes
$provincias = $conn->query("SELECT DISTINCT nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
if (empty($provincias)) {
    $provincias = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
}

// Buscar disciplinas para atribuição
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Função para criar avatar padrão
function criarAvatarProfessor($nome, $tamanho = 200) {
    $avatar_dir = __DIR__ . '/../../uploads/avatares/';
    if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0777, true);
    
    $iniciais = strtoupper(substr($nome, 0, 2));
    $cores = ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14'];
    $cor = $cores[abs(crc32($nome)) % count($cores)];
    
    $imagem = imagecreate($tamanho, $tamanho);
    $bg_color = imagecolorallocate($imagem, hexdec(substr($cor, 1, 2)), hexdec(substr($cor, 3, 2)), hexdec(substr($cor, 5, 2)));
    $text_color = imagecolorallocate($imagem, 255, 255, 255);
    
    imagefill($imagem, 0, 0, $bg_color);
    
    $fonte = __DIR__ . '/../../assets/fonts/arial.ttf';
    if (!file_exists($fonte)) {
        $fonte = 5; // fallback para fonte interna
        $texto = $iniciais;
        $x = ($tamanho - imagefontwidth($fonte) * strlen($texto)) / 2;
        $y = ($tamanho - imagefontheight($fonte)) / 2;
        imagestring($imagem, $fonte, $x, $y, $texto, $text_color);
    } else {
        $fonte_size = $tamanho / 3;
        $bbox = imagettfbbox($fonte_size, 0, $fonte, $iniciais);
        $x = ($tamanho - ($bbox[2] - $bbox[0])) / 2;
        $y = ($tamanho - ($bbox[1] - $bbox[7])) / 2;
        imagettftext($imagem, $fonte_size, 0, $x, $y, $text_color, $fonte, $iniciais);
    }
    
    $nome_avatar = 'avatar_prof_' . time() . '_' . uniqid() . '.png';
    imagepng($imagem, $avatar_dir . $nome_avatar);
    imagedestroy($imagem);
    
    return $nome_avatar;
}

// Função para upload de arquivo
function uploadArquivoProf($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
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

// Função para adicionar nova província
function adicionarProvincia($conn, $nova_provincia) {
    if (!empty($nova_provincia)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $nova_provincia]);
        return true;
    }
    return false;
}

// Função para adicionar novo município
function adicionarMunicipio($conn, $novo_municipio, $provincia_id) {
    if (!empty($novo_municipio)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (:nome, :provincia_id)");
        $stmt->execute([':nome' => $novo_municipio, ':provincia_id' => $provincia_id]);
        return true;
    }
    return false;
}

// Função para adicionar nova comuna
function adicionarComuna($conn, $nova_comuna, $municipio_id) {
    if (!empty($nova_comuna)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (:nome, :municipio_id)");
        $stmt->execute([':nome' => $nova_comuna, ':municipio_id' => $municipio_id]);
        return true;
    }
    return false;
}

// Processar adição de nova província via AJAX
if (isset($_POST['acao']) && $_POST['acao'] == 'add_provincia') {
    $nova_provincia = $_POST['nova_provincia'] ?? '';
    if ($nova_provincia) {
        adicionarProvincia($conn, $nova_provincia);
        echo json_encode(['success' => true, 'provincia' => $nova_provincia]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Processar adição de novo município via AJAX
if (isset($_POST['acao']) && $_POST['acao'] == 'add_municipio') {
    $novo_municipio = $_POST['novo_municipio'] ?? '';
    $provincia_nome = $_POST['provincia_nome'] ?? '';
    if ($novo_municipio && $provincia_nome) {
        $stmt = $conn->prepare("SELECT id FROM angola_provincias WHERE nome = :nome");
        $stmt->execute([':nome' => $provincia_nome]);
        $provincia = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($provincia) {
            adicionarMunicipio($conn, $novo_municipio, $provincia['id']);
            echo json_encode(['success' => true, 'municipio' => $novo_municipio]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Processar adição de nova comuna via AJAX
if (isset($_POST['acao']) && $_POST['acao'] == 'add_comuna') {
    $nova_comuna = $_POST['nova_comuna'] ?? '';
    $municipio_nome = $_POST['municipio_nome'] ?? '';
    if ($nova_comuna && $municipio_nome) {
        $stmt = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = :nome");
        $stmt->execute([':nome' => $municipio_nome]);
        $municipio = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($municipio) {
            adicionarComuna($conn, $nova_comuna, $municipio['id']);
            echo json_encode(['success' => true, 'comuna' => $nova_comuna]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['acao'])) {
    // Dados Pessoais
    $nome_completo = $_POST['nome_completo'] ?? '';
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $bi_numero = $_POST['bi_numero'] ?? '';
    $bi_data_emissao = $_POST['bi_data_emissao'] ?? '';
    $bi_local_emissao = $_POST['bi_local_emissao'] ?? '';
    $nuit = $_POST['nuit'] ?? '';
    $nacionalidade = $_POST['nacionalidade'] ?? 'Angolana';
    $naturalidade = $_POST['naturalidade'] ?? '';
    $provincia = $_POST['provincia'] ?? '';
    $municipio = $_POST['municipio'] ?? '';
    $comuna = $_POST['comuna'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $email = $_POST['email'] ?? '';
    
    // Dados Profissionais
    $especialidade = $_POST['especialidade'] ?? '';
    $formacao = $_POST['formacao'] ?? '';
    $data_admissao = $_POST['data_admissao'] ?? date('Y-m-d');
    $disciplina_id = $_POST['disciplina_id'] ?? null;
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    
    // Upload da Foto
    $foto = null;
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
        $foto_data = explode(',', $foto_capturada);
        if (count($foto_data) > 1) {
            $foto_decodificada = base64_decode($foto_data[1]);
            $foto_dir = __DIR__ . '/../../uploads/professores/fotos/';
            if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
            $foto = 'foto_prof_' . time() . '_' . uniqid() . '.png';
            file_put_contents($foto_dir . $foto, $foto_decodificada);
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $foto = uploadArquivoProf($_FILES['foto'], __DIR__ . '/../../uploads/professores/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
    }
    
    // Upload de Documentos
    $upload_dir_docs = __DIR__ . '/../../uploads/professores/documentos/';
    $bi_documento = uploadArquivoProf($_FILES['bi_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $diploma_documento = uploadArquivoProf($_FILES['diploma_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $certificacoes_documento = uploadArquivoProf($_FILES['certificacoes_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $declaracao_documento = uploadArquivoProf($_FILES['declaracao_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    
    // Criar avatar se não houver foto
    if (!$foto) {
        $foto = criarAvatarProfessor($nome_completo);
    }
    
    try {
        $conn->beginTransaction();
        
        // Verificar se BI já existe
        if ($bi_numero) {
            $stmt = $conn->prepare("SELECT id FROM professores WHERE bi = :bi AND escola_id = :escola_id");
            $stmt->execute([':bi' => $bi_numero, ':escola_id' => $escola_id]);
            if ($stmt->fetch()) {
                throw new Exception("BI já cadastrado no sistema.");
            }
        }
        
        // Criar usuário
        $email_usuario = $email ?: 'prof_' . time() . '@sige.ao';
        $senha_temp = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $senha_hash = password_hash($senha_temp, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            INSERT INTO usuarios (escola_id, nome, email, senha, tipo, telefone, status, created_at)
            VALUES (:escola_id, :nome, :email, :senha, 'professor', :telefone, 'ativo', NOW())
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome_completo,
            ':email' => $email_usuario,
            ':senha' => $senha_hash,
            ':telefone' => $telefone
        ]);
        $usuario_id = $conn->lastInsertId();
        
        // Inserir professor
        $stmt = $conn->prepare("
            INSERT INTO professores (
                usuario_id, escola_id, especialidade, formacao, data_admissao,
                bi, bi_data_emissao, bi_local_emissao, nuit, nacionalidade, naturalidade,
                provincia, municipio, comuna, endereco, data_nascimento, genero, foto,
                bi_documento, diploma_documento, certificacoes_documento, declaracao_documento,
                carga_horaria, status, created_at
            ) VALUES (
                :usuario_id, :escola_id, :especialidade, :formacao, :data_admissao,
                :bi, :bi_data_emissao, :bi_local_emissao, :nuit, :nacionalidade, :naturalidade,
                :provincia, :municipio, :comuna, :endereco, :data_nascimento, :genero, :foto,
                :bi_documento, :diploma_documento, :certificacoes_documento, :declaracao_documento,
                :carga_horaria, 'ativo', NOW()
            )
        ");
        
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':escola_id' => $escola_id,
            ':especialidade' => $especialidade ?: null,
            ':formacao' => $formacao ?: null,
            ':data_admissao' => $data_admissao,
            ':bi' => $bi_numero ?: null,
            ':bi_data_emissao' => $bi_data_emissao ?: null,
            ':bi_local_emissao' => $bi_local_emissao ?: null,
            ':nuit' => $nuit ?: null,
            ':nacionalidade' => $nacionalidade,
            ':naturalidade' => $naturalidade ?: null,
            ':provincia' => $provincia ?: null,
            ':municipio' => $municipio ?: null,
            ':comuna' => $comuna ?: null,
            ':endereco' => $endereco ?: null,
            ':data_nascimento' => $data_nascimento ?: null,
            ':genero' => $genero ?: null,
            ':foto' => $foto,
            ':bi_documento' => $bi_documento,
            ':diploma_documento' => $diploma_documento,
            ':certificacoes_documento' => $certificacoes_documento,
            ':declaracao_documento' => $declaracao_documento,
            ':carga_horaria' => $carga_horaria
        ]);
        
        $professor_id = $conn->lastInsertId();
        
        // Atribuir disciplina se selecionada
        if ($disciplina_id) {
            $stmt = $conn->prepare("
                INSERT INTO alocacoes (professor_id, disciplina_id, turma_id, ano_letivo, created_at)
                VALUES (:professor_id, :disciplina_id, NULL, :ano_letivo, NOW())
            ");
            $stmt->execute([
                ':professor_id' => $professor_id,
                ':disciplina_id' => $disciplina_id,
                ':ano_letivo' => $ano_letivo
            ]);
        }
        
        $conn->commit();
        
        $success = "Professor cadastrado com sucesso!<br>
                    Usuário de acesso: <strong>{$email_usuario}</strong><br>
                    Senha temporária: <strong>{$senha_temp}</strong>";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'cadastrar_professor', 'professores', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $professor_id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Buscar municípios por província (para AJAX)
if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia = $_GET['provincia'] ?? '';
    if ($provincia) {
        $stmt = $conn->prepare("
            SELECT m.id, m.nome 
            FROM angola_municipios m
            JOIN angola_provincias p ON p.id = m.provincia_id
            WHERE p.nome = :provincia
            ORDER BY m.nome
        ");
        $stmt->execute([':provincia' => $provincia]);
        $municipios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($municipios);
    }
    exit;
}

// Buscar comunas por município (para AJAX)
if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio = $_GET['municipio'] ?? '';
    if ($municipio) {
        $stmt = $conn->prepare("
            SELECT c.id, c.nome 
            FROM angola_comunas c
            JOIN angola_municipios m ON m.id = c.municipio_id
            WHERE m.nome = :municipio
            ORDER BY c.nome
        ");
        $stmt->execute([':municipio' => $municipio]);
        $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comunas);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 2px solid #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .modal-novo { z-index: 1050; }
        .input-group-btn { cursor: pointer; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../alunos/" class="nav-link"><i class="fas fa-users"></i> Alunos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
            <li class="nav-item"><a href="../turmas/" class="nav-link"><i class="fas fa-users-group"></i> Turmas</a></li>
            <li class="nav-item"><a href="../disciplinas/" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a></li>
            <li class="nav-item"><a href="../notas/" class="nav-link"><i class="fas fa-graduation-cap"></i> Notas</a></li>
            <li class="nav-item"><a href="../chamada/" class="nav-link"><i class="fas fa-calendar-check"></i> Chamada</a></li>
            <li class="nav-item"><a href="../biblioteca/" class="nav-link"><i class="fas fa-book-open"></i> Biblioteca</a></li>
            <li class="nav-item"><a href="../relatorios/" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-plus"></i> Cadastrar Professor</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="professorTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosProfissionais">Dados Profissionais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#documentos">Documentos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#foto">Foto</button></li>
                </ul>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="formProfessor">
                    <div class="tab-content">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dadosPessoais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Nome Completo</label>
                                        <input type="text" name="nome_completo" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="M">Masculino</option>
                                            <option value="F">Feminino</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº do BI</label>
                                        <input type="text" name="bi_numero" class="form-control" placeholder="Ex: 001234567LA042">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_data_emissao" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Local de Emissão</label>
                                        <input type="text" name="bi_local_emissao" class="form-control" placeholder="Ex: Luanda">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>NUIT (NIF)</label>
                                        <input type="text" name="nuit" class="form-control" placeholder="Ex: 1234567890">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nacionalidade</label>
                                        <input type="text" name="nacionalidade" class="form-control" value="Angolana">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade</label>
                                        <input type="text" name="naturalidade" class="form-control" placeholder="Cidade/Província">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <div class="input-group">
                                            <select name="provincia" id="provincia" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($provincias as $p): ?>
                                                <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaProvincia">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Município</label>
                                        <div class="input-group">
                                            <select name="municipio" id="municipio" class="form-control" disabled>
                                                <option value="">Primeiro selecione a província</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Comuna</label>
                                        <div class="input-group">
                                            <select name="comuna" id="comuna" class="form-control" disabled>
                                                <option value="">Primeiro selecione o município</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaComuna">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Endereço Completo</label>
                                <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" placeholder="9xx xxx xxx">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" placeholder="exemplo@email.com">
                                        <small class="text-muted">Opcional. Será usado para acesso ao sistema</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dados Profissionais -->
                        <div class="tab-pane fade" id="dadosProfissionais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Especialidade</label>
                                        <input type="text" name="especialidade" class="form-control" placeholder="Ex: Matemática, Português">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Admissão</label>
                                        <input type="date" name="data_admissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Formação Académica</label>
                                <textarea name="formacao" class="form-control" rows="3" placeholder="Licenciatura, Mestrado, Especializações..."></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Disciplina Principal</label>
                                        <select name="disciplina_id" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($disciplinas as $d): ?>
                                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Carga Horária Semanal</label>
                                        <input type="number" name="carga_horaria" class="form-control" placeholder="Horas por semana">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Documentos -->
                        <div class="tab-pane fade" id="documentos">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                            <h6>BI / Documento de Identificação</h6>
                                            <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-graduation-cap fa-3x text-success mb-2"></i>
                                            <h6>Diploma / Certificado</h6>
                                            <input type="file" name="diploma_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-certificate fa-3x text-info mb-2"></i>
                                            <h6>Certificações / Cursos</h6>
                                            <input type="file" name="certificacoes_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                            <h6>Declaração / Currículo</h6>
                                            <input type="file" name="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Foto -->
                        <div class="tab-pane fade" id="foto">
                            <div class="row">
                                <div class="col-md-6 text-center">
                                    <h5>Upload de Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*">
                                    <div class="preview-container">
                                        <img id="fotoPreview" src="../../assets/images/avatar-prof-padrao.png" class="preview-img">
                                    </div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Capturar com Webcam</h5>
                                    <div class="webcam-container">
                                        <video id="video" width="100%" autoplay></video>
                                        <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button>
                                        <canvas id="canvas" style="display:none;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Cadastrar Professor
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Província -->
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Nova Província</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nome da Província</label>
                        <input type="text" id="novaProvincia" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Município -->
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Novo Município</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Província</label>
                        <input type="text" id="municipioProvincia" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label>Nome do Município</label>
                        <input type="text" id="novoMunicipio" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Comuna -->
    <div class="modal fade" id="modalNovaComuna" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Nova Comuna</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Município</label>
                        <input type="text" id="comunaMunicipio" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label>Nome da Comuna</label>
                        <input type="text" id="novaComuna" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Preview da foto
        document.getElementById('fotoInput').onchange = function(evt) {
            const [file] = this.files;
            if (file) {
                document.getElementById('fotoPreview').src = URL.createObjectURL(file);
            }
        };
        
        // Webcam
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const capturarBtn = document.getElementById('capturarBtn');
        let stream = null;
        
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(mediaStream) {
                stream = mediaStream;
                video.srcObject = stream;
            })
            .catch(function(err) {
                console.log("Erro ao acessar webcam: " + err);
            });
        
        capturarBtn.addEventListener('click', function() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const fotoData = canvas.toDataURL('image/png');
            document.getElementById('fotoPreview').src = fotoData;
            
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'foto_capturada';
            hiddenInput.value = fotoData;
            document.getElementById('formProfessor').appendChild(hiddenInput);
        });
        
        // Carregar municípios por província
        $('#provincia').change(function() {
            var provincia = $(this).val();
            if (provincia) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_municipios', provincia: provincia },
                    success: function(data) {
                        var municipios = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < municipios.length; i++) {
                            options += '<option value="' + municipios[i].nome + '">' + municipios[i].nome + '</option>';
                        }
                        $('#municipio').html(options);
                        $('#municipio').prop('disabled', false);
                        $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
                    }
                });
            } else {
                $('#municipio').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true);
                $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Carregar comunas por município
        $('#municipio').change(function() {
            var municipio = $(this).val();
            if (municipio) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_comunas', municipio: municipio },
                    success: function(data) {
                        var comunas = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < comunas.length; i++) {
                            options += '<option value="' + comunas[i].nome + '">' + comunas[i].nome + '</option>';
                        }
                        $('#comuna').html(options);
                        $('#comuna').prop('disabled', false);
                    }
                });
            } else {
                $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Salvar nova província
        $('#salvarProvinciaBtn').click(function() {
            var novaProvincia = $('#novaProvincia').val();
            if (novaProvincia) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: { acao: 'add_provincia', nova_provincia: novaProvincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#provincia').append('<option value="' + result.provincia + '">' + result.provincia + '</option>');
                            $('#provincia').val(result.provincia);
                            $('#modalNovaProvincia').modal('hide');
                            $('#novaProvincia').val('');
                        }
                    }
                });
            }
        });
        
        // Salvar novo município
        $('#salvarMunicipioBtn').click(function() {
            var novoMunicipio = $('#novoMunicipio').val();
            var provincia = $('#provincia').val();
            if (novoMunicipio && provincia) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: { acao: 'add_municipio', novo_municipio: novoMunicipio, provincia_nome: provincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#municipio').append('<option value="' + result.municipio + '">' + result.municipio + '</option>');
                            $('#municipio').val(result.municipio);
                            $('#modalNovoMunicipio').modal('hide');
                            $('#novoMunicipio').val('');
                            // Trigger change to load comunas
                            $('#municipio').trigger('change');
                        }
                    }
                });
            }
        });
        
        // Salvar nova comuna
        $('#salvarComunaBtn').click(function() {
            var novaComuna = $('#novaComuna').val();
            var municipio = $('#municipio').val();
            if (novaComuna && municipio) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: { acao: 'add_comuna', nova_comuna: novaComuna, municipio_nome: municipio },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#comuna').append('<option value="' + result.comuna + '">' + result.comuna + '</option>');
                            $('#comuna').val(result.comuna);
                            $('#modalNovaComuna').modal('hide');
                            $('#novaComuna').val('');
                        }
                    }
                });
            }
        });
        
        // Preencher dados do modal de município
        $('#modalNovoMunicipio').on('show.bs.modal', function() {
            var provincia = $('#provincia').val();
            if (provincia) {
                $('#municipioProvincia').val(provincia);
            } else {
                alert('Selecione uma província primeiro!');
                return false;
            }
        });
        
        // Preencher dados do modal de comuna
        $('#modalNovaComuna').on('show.bs.modal', function() {
            var municipio = $('#municipio').val();
            if (municipio) {
                $('#comunaMunicipio').val(municipio);
            } else {
                alert('Selecione um município primeiro!');
                return false;
            }
        });
    </script>
</body>
</html>