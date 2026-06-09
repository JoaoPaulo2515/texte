<?php
// escola/professores/editar.php - Editar dados do professor
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$id = $_GET['id'] ?? 0;

// Buscar dados do professor
$stmt = $conn->prepare("
    SELECT p.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone, u.status as usuario_status
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :id AND p.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$professor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$professor) {
    header('Location: index.php?error=Professor não encontrado');
    exit;
}

// Buscar disciplinas para atribuição
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas atuais do professor
$stmt = $conn->prepare("
    SELECT d.id, d.nome, a.id as alocacao_id
    FROM alocacoes a
    JOIN disciplinas d ON d.id = a.disciplina_id
    WHERE a.professor_id = :professor_id AND a.turma_id IS NULL
");
$stmt->execute([':professor_id' => $id]);
$disciplinas_atuais = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Províncias de Angola
$provincias = $conn->query("SELECT DISTINCT nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
if (empty($provincias)) {
    $provincias = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
}

$error = '';
$success = '';

// Função para upload de arquivo
function uploadArquivoProfessor($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
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

// Processar adição de nova província via AJAX
if (isset($_POST['acao']) && $_POST['acao'] == 'add_provincia') {
    $nova_provincia = $_POST['nova_provincia'] ?? '';
    if ($nova_provincia) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $nova_provincia]);
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
            $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (:nome, :provincia_id)");
            $stmt->execute([':nome' => $novo_municipio, ':provincia_id' => $provincia['id']]);
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
            $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (:nome, :municipio_id)");
            $stmt->execute([':nome' => $nova_comuna, ':municipio_id' => $municipio['id']]);
            echo json_encode(['success' => true, 'comuna' => $nova_comuna]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
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
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    $status = $_POST['status'] ?? 'ativo';
    
    // Disciplinas
    $disciplinas_selecionadas = $_POST['disciplinas'] ?? [];
    
    // Upload da Foto
    $foto = $professor['foto'];
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
        $foto_data = explode(',', $foto_capturada);
        if (count($foto_data) > 1) {
            $foto_decodificada = base64_decode($foto_data[1]);
            $foto_dir = __DIR__ . '/../../uploads/professores/fotos/';
            if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
            $foto = 'foto_prof_' . time() . '_' . uniqid() . '.png';
            file_put_contents($foto_dir . $foto, $foto_decodificada);
            
            // Remover foto antiga
            if ($professor['foto'] && file_exists($foto_dir . $professor['foto'])) {
                unlink($foto_dir . $professor['foto']);
            }
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/professores/fotos/';
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $nova_foto = 'foto_prof_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $nova_foto)) {
                if ($professor['foto'] && file_exists($upload_dir . $professor['foto'])) {
                    unlink($upload_dir . $professor['foto']);
                }
                $foto = $nova_foto;
            }
        }
    }
    
    // Upload de Documentos
    $upload_dir_docs = __DIR__ . '/../../uploads/professores/documentos/';
    
    $bi_documento = $professor['bi_documento'];
    if (isset($_FILES['bi_documento']) && $_FILES['bi_documento']['error'] == 0) {
        $novo_doc = uploadArquivoProfessor($_FILES['bi_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($bi_documento && file_exists($upload_dir_docs . $bi_documento)) {
                unlink($upload_dir_docs . $bi_documento);
            }
            $bi_documento = $novo_doc;
        }
    }
    
    $diploma_documento = $professor['diploma_documento'];
    if (isset($_FILES['diploma_documento']) && $_FILES['diploma_documento']['error'] == 0) {
        $novo_doc = uploadArquivoProfessor($_FILES['diploma_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($diploma_documento && file_exists($upload_dir_docs . $diploma_documento)) {
                unlink($upload_dir_docs . $diploma_documento);
            }
            $diploma_documento = $novo_doc;
        }
    }
    
    $certificacoes_documento = $professor['certificacoes_documento'];
    if (isset($_FILES['certificacoes_documento']) && $_FILES['certificacoes_documento']['error'] == 0) {
        $novo_doc = uploadArquivoProfessor($_FILES['certificacoes_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($certificacoes_documento && file_exists($upload_dir_docs . $certificacoes_documento)) {
                unlink($upload_dir_docs . $certificacoes_documento);
            }
            $certificacoes_documento = $novo_doc;
        }
    }
    
    $declaracao_documento = $professor['declaracao_documento'];
    if (isset($_FILES['declaracao_documento']) && $_FILES['declaracao_documento']['error'] == 0) {
        $novo_doc = uploadArquivoProfessor($_FILES['declaracao_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($declaracao_documento && file_exists($upload_dir_docs . $declaracao_documento)) {
                unlink($upload_dir_docs . $declaracao_documento);
            }
            $declaracao_documento = $novo_doc;
        }
    }
    
    try {
        $conn->beginTransaction();
        
        // Atualizar usuário
        $stmt = $conn->prepare("
            UPDATE usuarios SET
                nome = :nome,
                email = :email,
                telefone = :telefone,
                status = :status,
                updated_at = NOW()
            WHERE id = :usuario_id
        ");
        $stmt->execute([
            ':nome' => $nome_completo,
            ':email' => $email,
            ':telefone' => $telefone,
            ':status' => $status,
            ':usuario_id' => $professor['usuario_id']
        ]);
        
        // Atualizar professor
        $stmt = $conn->prepare("
            UPDATE professores SET
                especialidade = :especialidade,
                formacao = :formacao,
                data_admissao = :data_admissao,
                bi = :bi,
                bi_data_emissao = :bi_data_emissao,
                bi_local_emissao = :bi_local_emissao,
                nuit = :nuit,
                nacionalidade = :nacionalidade,
                naturalidade = :naturalidade,
                provincia = :provincia,
                municipio = :municipio,
                comuna = :comuna,
                endereco = :endereco,
                data_nascimento = :data_nascimento,
                genero = :genero,
                foto = :foto,
                bi_documento = :bi_documento,
                diploma_documento = :diploma_documento,
                certificacoes_documento = :certificacoes_documento,
                declaracao_documento = :declaracao_documento,
                carga_horaria = :carga_horaria,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $id,
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
            ':carga_horaria' => $carga_horaria,
            ':status' => $status
        ]);
        
        // Atualizar disciplinas
        // Remover disciplinas antigas
        $stmt = $conn->prepare("DELETE FROM alocacoes WHERE professor_id = :professor_id AND turma_id IS NULL");
        $stmt->execute([':professor_id' => $id]);
        
        // Adicionar novas disciplinas
        foreach ($disciplinas_selecionadas as $disciplina_id) {
            $stmt = $conn->prepare("
                INSERT INTO alocacoes (professor_id, disciplina_id, ano_letivo, created_at)
                VALUES (:professor_id, :disciplina_id, YEAR(CURDATE()), NOW())
            ");
            $stmt->execute([
                ':professor_id' => $id,
                ':disciplina_id' => $disciplina_id
            ]);
        }
        
        $conn->commit();
        
        $success = "Professor atualizado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'editar_professor', 'professores', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Recarregar dados
        $stmt = $conn->prepare("
            SELECT p.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone, u.status as usuario_status
            FROM professores p
            JOIN usuarios u ON u.id = p.usuario_id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $professor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Recarregar disciplinas atuais
        $stmt = $conn->prepare("
            SELECT d.id, d.nome
            FROM alocacoes a
            JOIN disciplinas d ON d.id = a.disciplina_id
            WHERE a.professor_id = :professor_id AND a.turma_id IS NULL
        ");
        $stmt->execute([':professor_id' => $id]);
        $disciplinas_atuais = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Professor | SIGE Angola</title>
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
        .disciplina-item { margin-bottom: 10px; }
    </style>
</head>
<body>
  
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Editar Professor: <?php echo htmlspecialchars($professor['nome']); ?></h2>
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
                                        <input type="text" name="nome_completo" class="form-control" value="<?php echo htmlspecialchars($professor['nome']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control" value="<?php echo $professor['data_nascimento']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="M" <?php echo $professor['genero'] == 'M' ? 'selected' : ''; ?>>Masculino</option>
                                            <option value="F" <?php echo $professor['genero'] == 'F' ? 'selected' : ''; ?>>Feminino</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº do BI</label>
                                        <input type="text" name="bi_numero" class="form-control" value="<?php echo htmlspecialchars($professor['bi']); ?>" placeholder="Ex: 001234567LA042">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_data_emissao" class="form-control" value="<?php echo $professor['bi_data_emissao']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Local de Emissão</label>
                                        <input type="text" name="bi_local_emissao" class="form-control" value="<?php echo htmlspecialchars($professor['bi_local_emissao']); ?>" placeholder="Ex: Luanda">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>NUIT (NIF)</label>
                                        <input type="text" name="nuit" class="form-control" value="<?php echo htmlspecialchars($professor['nuit']); ?>" placeholder="Ex: 1234567890">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nacionalidade</label>
                                        <input type="text" name="nacionalidade" class="form-control" value="<?php echo htmlspecialchars($professor['nacionalidade'] ?: 'Angolana'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade</label>
                                        <input type="text" name="naturalidade" class="form-control" value="<?php echo htmlspecialchars($professor['naturalidade']); ?>" placeholder="Cidade/Província">
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
                                                <option value="<?php echo $p; ?>" <?php echo $professor['provincia'] == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
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
                                            <select name="municipio" id="municipio" class="form-control" <?php echo !$professor['provincia'] ? 'disabled' : ''; ?>>
                                                <option value="">Selecione...</option>
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
                                            <select name="comuna" id="comuna" class="form-control" <?php echo !$professor['municipio'] ? 'disabled' : ''; ?>>
                                                <option value="">Selecione...</option>
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
                                <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"><?php echo htmlspecialchars($professor['endereco']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($professor['usuario_telefone']); ?>" placeholder="9xx xxx xxx">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($professor['usuario_email']); ?>" placeholder="exemplo@email.com">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Status</label>
                                        <select name="status" class="form-control">
                                            <option value="ativo" <?php echo $professor['usuario_status'] == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                                            <option value="inativo" <?php echo $professor['usuario_status'] == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                                        </select>
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
                                        <input type="text" name="especialidade" class="form-control" value="<?php echo htmlspecialchars($professor['especialidade']); ?>" placeholder="Ex: Matemática, Português">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Admissão</label>
                                        <input type="date" name="data_admissao" class="form-control" value="<?php echo $professor['data_admissao']; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Formação Académica</label>
                                <textarea name="formacao" class="form-control" rows="3" placeholder="Licenciatura, Mestrado, Especializações..."><?php echo htmlspecialchars($professor['formacao']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Carga Horária Semanal</label>
                                        <input type="number" name="carga_horaria" class="form-control" value="<?php echo $professor['carga_horaria']; ?>" placeholder="Horas por semana">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Disciplinas Ministradas</label>
                                <div class="row">
                                    <?php 
                                    $disciplinas_ids_atuais = array_column($disciplinas_atuais, 'id');
                                    foreach ($disciplinas as $disciplina): 
                                    ?>
                                    <div class="col-md-4 disciplina-item">
                                        <div class="form-check">
                                            <input type="checkbox" name="disciplinas[]" value="<?php echo $disciplina['id']; ?>" class="form-check-input" 
                                                id="disc_<?php echo $disciplina['id']; ?>"
                                                <?php echo in_array($disciplina['id'], $disciplinas_ids_atuais) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="disc_<?php echo $disciplina['id']; ?>">
                                                <?php echo htmlspecialchars($disciplina['nome']); ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (empty($disciplinas)): ?>
                                    <div class="alert alert-warning">
                                        Nenhuma disciplina cadastrada. <a href="../disciplinas/cadastrar.php">Cadastrar disciplina</a>
                                    </div>
                                <?php endif; ?>
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
                                            <?php if ($professor['bi_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/professores/documentos/<?php echo $professor['bi_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
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
                                            <?php if ($professor['diploma_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/professores/documentos/<?php echo $professor['diploma_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
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
                                            <?php if ($professor['certificacoes_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/professores/documentos/<?php echo $professor['certificacoes_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
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
                                            <?php if ($professor['declaracao_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/professores/documentos/<?php echo $professor['declaracao_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
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
                                        <?php 
                                        $foto_path = '../../uploads/professores/fotos/' . $professor['foto'];
                                        if ($professor['foto'] && file_exists($foto_path)): ?>
                                            <img id="fotoPreview" src="<?php echo $foto_path; ?>" class="preview-img">
                                        <?php else: ?>
                                            <img id="fotoPreview" src="../../assets/images/avatar-prof-padrao.png" class="preview-img">
                                        <?php endif; ?>
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
        
        // Carregar municípios
        function carregarMunicipios(provincia, selectedValue) {
            if (provincia) {
                $.ajax({
                    url: 'editar.php',
                    method: 'GET',
                    data: { acao: 'get_municipios', provincia: provincia },
                    success: function(data) {
                        var municipios = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < municipios.length; i++) {
                            var selected = (selectedValue == municipios[i].nome) ? 'selected' : '';
                            options += '<option value="' + municipios[i].nome + '" ' + selected + '>' + municipios[i].nome + '</option>';
                        }
                        $('#municipio').html(options);
                        $('#municipio').prop('disabled', false);
                    }
                });
            } else {
                $('#municipio').html('<option value="">Selecione...</option>').prop('disabled', true);
                $('#comuna').html('<option value="">Selecione...</option>').prop('disabled', true);
            }
        }
        
        // Carregar comunas
        function carregarComunas(municipio, selectedValue) {
            if (municipio) {
                $.ajax({
                    url: 'editar.php',
                    method: 'GET',
                    data: { acao: 'get_comunas', municipio: municipio },
                    success: function(data) {
                        var comunas = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < comunas.length; i++) {
                            var selected = (selectedValue == comunas[i].nome) ? 'selected' : '';
                            options += '<option value="' + comunas[i].nome + '" ' + selected + '>' + comunas[i].nome + '</option>';
                        }
                        $('#comuna').html(options);
                        $('#comuna').prop('disabled', false);
                    }
                });
            } else {
                $('#comuna').html('<option value="">Selecione...</option>').prop('disabled', true);
            }
        }
        
        // Carregar dados iniciais
        var provinciaAtual = $('#provincia').val();
        var municipioAtual = '<?php echo addslashes($professor['municipio']); ?>';
        var comunaAtual = '<?php echo addslashes($professor['comuna']); ?>';
        
        if (provinciaAtual) {
            carregarMunicipios(provinciaAtual, municipioAtual);
            if (municipioAtual) {
                carregarComunas(municipioAtual, comunaAtual);
            }
        }
        
        $('#provincia').change(function() {
            var provincia = $(this).val();
            carregarMunicipios(provincia, '');
        });
        
        $('#municipio').change(function() {
            var municipio = $(this).val();
            carregarComunas(municipio, '');
        });
        
        // Salvar nova província
        $('#salvarProvinciaBtn').click(function() {
            var novaProvincia = $('#novaProvincia').val();
            if (novaProvincia) {
                $.ajax({
                    url: 'editar.php',
                    method: 'POST',
                    data: { acao: 'add_provincia', nova_provincia: novaProvincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#provincia').append('<option value="' + result.provincia + '">' + result.provincia + '</option>');
                            $('#provincia').val(result.provincia);
                            $('#modalNovaProvincia').modal('hide');
                            $('#novaProvincia').val('');
                            carregarMunicipios(result.provincia, '');
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
                    url: 'editar.php',
                    method: 'POST',
                    data: { acao: 'add_municipio', novo_municipio: novoMunicipio, provincia_nome: provincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#municipio').append('<option value="' + result.municipio + '">' + result.municipio + '</option>');
                            $('#municipio').val(result.municipio);
                            $('#modalNovoMunicipio').modal('hide');
                            $('#novoMunicipio').val('');
                            carregarComunas(result.municipio, '');
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
                    url: 'editar.php',
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
        
        // Preencher modais
        $('#modalNovoMunicipio').on('show.bs.modal', function() {
            var provincia = $('#provincia').val();
            if (provincia) {
                $('#municipioProvincia').val(provincia);
            } else {
                alert('Selecione uma província primeiro!');
                return false;
            }
        });
        
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