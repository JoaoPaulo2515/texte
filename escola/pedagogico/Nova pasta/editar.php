<?php
// escola/alunos/editar.php - Editar dados do aluno
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

// Buscar dados do aluno
$stmt = $conn->prepare("
    SELECT e.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone, u.status as usuario_status
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.id = :id AND e.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$aluno = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: index.php?error=Aluno não encontrado');
    exit;
}

// Buscar turmas para select
$turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar matrícula atual
$stmt = $conn->prepare("
    SELECT t.id as turma_id, t.nome as turma_nome, m.id as matricula_id
    FROM matriculas m
    JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :estudante_id AND m.status = 'ativa'
");
$stmt->execute([':estudante_id' => $id]);
$matricula = $stmt->fetch(PDO::FETCH_ASSOC);

// Províncias de Angola
$provincias = $conn->query("SELECT DISTINCT nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
if (empty($provincias)) {
    $provincias = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
}

$error = '';
$success = '';

// Função para upload de arquivo
function uploadArquivoAluno($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
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
    
    // Dados dos Pais
    $pai_nome = $_POST['pai_nome'] ?? '';
    $pai_bi = $_POST['pai_bi'] ?? '';
    $pai_telefone = $_POST['pai_telefone'] ?? '';
    $pai_profissao = $_POST['pai_profissao'] ?? '';
    $mae_nome = $_POST['mae_nome'] ?? '';
    $mae_bi = $_POST['mae_bi'] ?? '';
    $mae_telefone = $_POST['mae_telefone'] ?? '';
    $mae_profissao = $_POST['mae_profissao'] ?? '';
    
    // Encarregado
    $encarregado_nome = $_POST['encarregado_nome'] ?? '';
    $encarregado_parentesco = $_POST['encarregado_parentesco'] ?? '';
    $encarregado_bi = $_POST['encarregado_bi'] ?? '';
    $encarregado_telefone = $_POST['encarregado_telefone'] ?? '';
    $encarregado_email = $_POST['encarregado_email'] ?? '';
    $encarregado_endereco = $_POST['encarregado_endereco'] ?? '';
    
    // Dados Académicos
    $turma_id = $_POST['turma_id'] ?? '';
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    $ano_escolar = $_POST['ano_escolar'] ?? '';
    $numero_processo = $_POST['numero_processo'] ?? '';
    $escola_anterior = $_POST['escola_anterior'] ?? '';
    $ano_ingresso = $_POST['ano_ingresso'] ?? $ano_letivo;
    
    // Upload da Foto
    $foto = $aluno['foto'];
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
        $foto_data = explode(',', $foto_capturada);
        if (count($foto_data) > 1) {
            $foto_decodificada = base64_decode($foto_data[1]);
            $foto_dir = __DIR__ . '/../../uploads/alunos/fotos/';
            if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
            $foto = 'foto_' . time() . '_' . uniqid() . '.png';
            file_put_contents($foto_dir . $foto, $foto_decodificada);
            
            // Remover foto antiga
            if ($aluno['foto'] && file_exists($foto_dir . $aluno['foto'])) {
                unlink($foto_dir . $aluno['foto']);
            }
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/alunos/fotos/';
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $nova_foto = 'foto_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $nova_foto)) {
                if ($aluno['foto'] && file_exists($upload_dir . $aluno['foto'])) {
                    unlink($upload_dir . $aluno['foto']);
                }
                $foto = $nova_foto;
            }
        }
    }
    
    // Upload de Documentos
    $upload_dir_docs = __DIR__ . '/../../uploads/alunos/documentos/';
    
    $bi_documento = $aluno['bi_documento'];
    if (isset($_FILES['bi_documento']) && $_FILES['bi_documento']['error'] == 0) {
        $novo_doc = uploadArquivoAluno($_FILES['bi_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($bi_documento && file_exists($upload_dir_docs . $bi_documento)) {
                unlink($upload_dir_docs . $bi_documento);
            }
            $bi_documento = $novo_doc;
        }
    }
    
    $certificado_documento = $aluno['certificado_documento'];
    if (isset($_FILES['certificado_documento']) && $_FILES['certificado_documento']['error'] == 0) {
        $novo_doc = uploadArquivoAluno($_FILES['certificado_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($certificado_documento && file_exists($upload_dir_docs . $certificado_documento)) {
                unlink($upload_dir_docs . $certificado_documento);
            }
            $certificado_documento = $novo_doc;
        }
    }
    
    $atestado_documento = $aluno['atestado_documento'];
    if (isset($_FILES['atestado_documento']) && $_FILES['atestado_documento']['error'] == 0) {
        $novo_doc = uploadArquivoAluno($_FILES['atestado_documento'], $upload_dir_docs);
        if ($novo_doc) {
            if ($atestado_documento && file_exists($upload_dir_docs . $atestado_documento)) {
                unlink($upload_dir_docs . $atestado_documento);
            }
            $atestado_documento = $novo_doc;
        }
    }
    
    $declaracao_documento = $aluno['declaracao_documento'];
    if (isset($_FILES['declaracao_documento']) && $_FILES['declaracao_documento']['error'] == 0) {
        $novo_doc = uploadArquivoAluno($_FILES['declaracao_documento'], $upload_dir_docs);
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
                updated_at = NOW()
            WHERE id = :usuario_id
        ");
        $stmt->execute([
            ':nome' => $nome_completo,
            ':email' => $email,
            ':telefone' => $telefone,
            ':usuario_id' => $aluno['usuario_id']
        ]);
        
        // Atualizar estudante
        $stmt = $conn->prepare("
            UPDATE estudantes SET
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
                pai_nome = :pai_nome,
                pai_bi = :pai_bi,
                pai_telefone = :pai_telefone,
                pai_profissao = :pai_profissao,
                mae_nome = :mae_nome,
                mae_bi = :mae_bi,
                mae_telefone = :mae_telefone,
                mae_profissao = :mae_profissao,
                encarregado_nome = :encarregado_nome,
                encarregado_parentesco = :encarregado_parentesco,
                encarregado_bi = :encarregado_bi,
                encarregado_telefone = :encarregado_telefone,
                encarregado_email = :encarregado_email,
                encarregado_endereco = :encarregado_endereco,
                ano_letivo = :ano_letivo,
                ano_escolar = :ano_escolar,
                numero_processo = :numero_processo,
                escola_anterior = :escola_anterior,
                ano_ingresso = :ano_ingresso,
                bi_documento = :bi_documento,
                certificado_documento = :certificado_documento,
                atestado_documento = :atestado_documento,
                declaracao_documento = :declaracao_documento,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $id,
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
            ':pai_nome' => $pai_nome ?: null,
            ':pai_bi' => $pai_bi ?: null,
            ':pai_telefone' => $pai_telefone ?: null,
            ':pai_profissao' => $pai_profissao ?: null,
            ':mae_nome' => $mae_nome ?: null,
            ':mae_bi' => $mae_bi ?: null,
            ':mae_telefone' => $mae_telefone ?: null,
            ':mae_profissao' => $mae_profissao ?: null,
            ':encarregado_nome' => $encarregado_nome ?: null,
            ':encarregado_parentesco' => $encarregado_parentesco ?: null,
            ':encarregado_bi' => $encarregado_bi ?: null,
            ':encarregado_telefone' => $encarregado_telefone ?: null,
            ':encarregado_email' => $encarregado_email ?: null,
            ':encarregado_endereco' => $encarregado_endereco ?: null,
            ':ano_letivo' => $ano_letivo,
            ':ano_escolar' => $ano_escolar ?: null,
            ':numero_processo' => $numero_processo ?: null,
            ':escola_anterior' => $escola_anterior ?: null,
            ':ano_ingresso' => $ano_ingresso,
            ':bi_documento' => $bi_documento,
            ':certificado_documento' => $certificado_documento,
            ':atestado_documento' => $atestado_documento,
            ':declaracao_documento' => $declaracao_documento
        ]);
        
        // Atualizar matrícula se necessário
        if ($turma_id && (!$matricula || $matricula['turma_id'] != $turma_id)) {
            // Desativar matrícula antiga
            if ($matricula) {
                $stmt = $conn->prepare("UPDATE matriculas SET status = 'transferido', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $matricula['matricula_id']]);
            }
            
            // Criar nova matrícula
            $stmt = $conn->prepare("
                INSERT INTO matriculas (estudante_id, turma_id, ano_letivo, numero_matricula, status, data_matricula, created_at)
                VALUES (:estudante_id, :turma_id, :ano_letivo, :numero_matricula, 'ativa', CURDATE(), NOW())
            ");
            $stmt->execute([
                ':estudante_id' => $id,
                ':turma_id' => $turma_id,
                ':ano_letivo' => $ano_letivo,
                ':numero_matricula' => $aluno['matricula']
            ]);
        }
        
        $conn->commit();
        
        $success = "Aluno atualizado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'editar_aluno', 'estudantes', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Recarregar dados
        $stmt = $conn->prepare("
            SELECT e.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone
            FROM estudantes e
            JOIN usuarios u ON u.id = e.usuario_id
            WHERE e.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
        
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
    <title>Editar Aluno | SIGE Angola</title>
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
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Editar Aluno: <?php echo htmlspecialchars($aluno['nome']); ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="alunoTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#filiacao">Filiação</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#encarregado">Encarregado</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#academicos">Dados Académicos</button></li>
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
                
                <form method="POST" enctype="multipart/form-data" id="formAluno">
                    <div class="tab-content">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dadosPessoais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Nome Completo</label>
                                        <input type="text" name="nome_completo" class="form-control" value="<?php echo htmlspecialchars($aluno['nome']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control" value="<?php echo $aluno['data_nascimento']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="M" <?php echo $aluno['genero'] == 'M' ? 'selected' : ''; ?>>Masculino</option>
                                            <option value="F" <?php echo $aluno['genero'] == 'F' ? 'selected' : ''; ?>>Feminino</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº do BI</label>
                                        <input type="text" name="bi_numero" class="form-control" value="<?php echo htmlspecialchars($aluno['bi']); ?>" placeholder="Ex: 001234567LA042">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_data_emissao" class="form-control" value="<?php echo $aluno['bi_data_emissao']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Local de Emissão</label>
                                        <input type="text" name="bi_local_emissao" class="form-control" value="<?php echo htmlspecialchars($aluno['bi_local_emissao']); ?>" placeholder="Ex: Luanda">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>NUIT (NIF)</label>
                                        <input type="text" name="nuit" class="form-control" value="<?php echo htmlspecialchars($aluno['nuit']); ?>" placeholder="Ex: 1234567890">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nacionalidade</label>
                                        <input type="text" name="nacionalidade" class="form-control" value="<?php echo htmlspecialchars($aluno['nacionalidade'] ?: 'Angolana'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade</label>
                                        <input type="text" name="naturalidade" class="form-control" value="<?php echo htmlspecialchars($aluno['naturalidade']); ?>" placeholder="Cidade/Província">
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
                                                <option value="<?php echo $p; ?>" <?php echo $aluno['provincia'] == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
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
                                            <select name="municipio" id="municipio" class="form-control" <?php echo !$aluno['provincia'] ? 'disabled' : ''; ?>>
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
                                            <select name="comuna" id="comuna" class="form-control" <?php echo !$aluno['municipio'] ? 'disabled' : ''; ?>>
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
                                <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"><?php echo htmlspecialchars($aluno['endereco']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['usuario_telefone']); ?>" placeholder="9xx xxx xxx">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($aluno['usuario_email']); ?>" placeholder="exemplo@email.com">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filiação -->
                        <div class="tab-pane fade" id="filiacao">
                            <h5 class="mb-3">Dados do Pai</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome Completo do Pai</label>
                                        <input type="text" name="pai_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_nome']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>BI do Pai</label>
                                        <input type="text" name="pai_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_bi']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Telefone do Pai</label>
                                        <input type="text" name="pai_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_telefone']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Profissão do Pai</label>
                                        <input type="text" name="pai_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_profissao']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="mb-3 mt-4">Dados da Mãe</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome Completo da Mãe</label>
                                        <input type="text" name="mae_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_nome']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>BI da Mãe</label>
                                        <input type="text" name="mae_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_bi']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Telefone da Mãe</label>
                                        <input type="text" name="mae_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_telefone']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Profissão da Mãe</label>
                                        <input type="text" name="mae_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_profissao']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Encarregado de Educação -->
                        <div class="tab-pane fade" id="encarregado">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha os dados abaixo.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Encarregado</label>
                                        <input type="text" name="encarregado_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_nome']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Parentesco</label>
                                        <select name="encarregado_parentesco" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="Pai" <?php echo $aluno['encarregado_parentesco'] == 'Pai' ? 'selected' : ''; ?>>Pai</option>
                                            <option value="Mãe" <?php echo $aluno['encarregado_parentesco'] == 'Mãe' ? 'selected' : ''; ?>>Mãe</option>
                                            <option value="Tio" <?php echo $aluno['encarregado_parentesco'] == 'Tio' ? 'selected' : ''; ?>>Tio</option>
                                            <option value="Tia" <?php echo $aluno['encarregado_parentesco'] == 'Tia' ? 'selected' : ''; ?>>Tia</option>
                                            <option value="Avô" <?php echo $aluno['encarregado_parentesco'] == 'Avô' ? 'selected' : ''; ?>>Avô</option>
                                            <option value="Avó" <?php echo $aluno['encarregado_parentesco'] == 'Avó' ? 'selected' : ''; ?>>Avó</option>
                                            <option value="Irmão" <?php echo $aluno['encarregado_parentesco'] == 'Irmão' ? 'selected' : ''; ?>>Irmão</option>
                                            <option value="Irmã" <?php echo $aluno['encarregado_parentesco'] == 'Irmã' ? 'selected' : ''; ?>>Irmã</option>
                                            <option value="Outro" <?php echo $aluno['encarregado_parentesco'] == 'Outro' ? 'selected' : ''; ?>>Outro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>BI do Encarregado</label>
                                        <input type="text" name="encarregado_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_bi']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="encarregado_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_telefone']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="encarregado_email" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_email']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Endereço do Encarregado</label>
                                <textarea name="encarregado_endereco" class="form-control" rows="2"><?php echo htmlspecialchars($aluno['encarregado_endereco']); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Dados Académicos -->
                        <div class="tab-pane fade" id="academicos">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Ano Letivo</label>
                                        <select name="ano_letivo" class="form-control">
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($aluno['ano_letivo'] ?? date('Y')) == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Ano Escolar / Classe</label>
                                        <select name="ano_escolar" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="1º Ano" <?php echo $aluno['ano_escolar'] == '1º Ano' ? 'selected' : ''; ?>>1º Ano</option>
                                            <option value="2º Ano" <?php echo $aluno['ano_escolar'] == '2º Ano' ? 'selected' : ''; ?>>2º Ano</option>
                                            <option value="3º Ano" <?php echo $aluno['ano_escolar'] == '3º Ano' ? 'selected' : ''; ?>>3º Ano</option>
                                            <option value="4º Ano" <?php echo $aluno['ano_escolar'] == '4º Ano' ? 'selected' : ''; ?>>4º Ano</option>
                                            <option value="5º Ano" <?php echo $aluno['ano_escolar'] == '5º Ano' ? 'selected' : ''; ?>>5º Ano</option>
                                            <option value="6º Ano" <?php echo $aluno['ano_escolar'] == '6º Ano' ? 'selected' : ''; ?>>6º Ano</option>
                                            <option value="7º Ano" <?php echo $aluno['ano_escolar'] == '7º Ano' ? 'selected' : ''; ?>>7º Ano</option>
                                            <option value="8º Ano" <?php echo $aluno['ano_escolar'] == '8º Ano' ? 'selected' : ''; ?>>8º Ano</option>
                                            <option value="9º Ano" <?php echo $aluno['ano_escolar'] == '9º Ano' ? 'selected' : ''; ?>>9º Ano</option>
                                            <option value="10ª Classe" <?php echo $aluno['ano_escolar'] == '10ª Classe' ? 'selected' : ''; ?>>10ª Classe</option>
                                            <option value="11ª Classe" <?php echo $aluno['ano_escolar'] == '11ª Classe' ? 'selected' : ''; ?>>11ª Classe</option>
                                            <option value="12ª Classe" <?php echo $aluno['ano_escolar'] == '12ª Classe' ? 'selected' : ''; ?>>12ª Classe</option>
                                            <option value="13ª Classe" <?php echo $aluno['ano_escolar'] == '13ª Classe' ? 'selected' : ''; ?>>13ª Classe</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Turma</label>
                                        <select name="turma_id" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($turmas as $t): ?>
                                            <option value="<?php echo $t['id']; ?>" <?php echo ($matricula['turma_id'] ?? '') == $t['id'] ? 'selected' : ''; ?>><?php echo $t['nome']; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Número de Processo</label>
                                        <input type="text" name="numero_processo" class="form-control" value="<?php echo htmlspecialchars($aluno['numero_processo']); ?>" placeholder="Número do processo do aluno">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Escola Anterior</label>
                                        <input type="text" name="escola_anterior" class="form-control" value="<?php echo htmlspecialchars($aluno['escola_anterior']); ?>" placeholder="Escola onde estudou anteriormente">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Ano de Ingresso</label>
                                        <select name="ano_ingresso" class="form-control">
                                            <?php for ($i = 2010; $i <= date('Y'); $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($aluno['ano_ingresso'] ?? date('Y')) == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
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
                                            <?php if ($aluno['bi_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['bi_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
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
                                            <i class="fas fa-certificate fa-3x text-success mb-2"></i>
                                            <h6>Certificado de Conclusão / Histórico</h6>
                                            <?php if ($aluno['certificado_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['certificado_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="certificado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-stethoscope fa-3x text-info mb-2"></i>
                                            <h6>Atestado Médico</h6>
                                            <?php if ($aluno['atestado_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['atestado_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="atestado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                                            <small class="text-muted">PDF, JPG, PNG (Max: 2MB)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                            <h6>Declaração de Matrícula</h6>
                                            <?php if ($aluno['declaracao_documento']): ?>
                                                <div class="mb-2">
                                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['declaracao_documento']; ?>" target="_blank" class="btn btn-sm btn-info">Ver Documento Atual</a>
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
                                        $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                                        if ($aluno['foto'] && file_exists($foto_path)): ?>
                                            <img id="fotoPreview" src="<?php echo $foto_path; ?>" class="preview-img">
                                        <?php else: ?>
                                            <img id="fotoPreview" src="../../assets/images/avatar-padrao.png" class="preview-img">
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
            document.getElementById('formAluno').appendChild(hiddenInput);
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
        var municipioAtual = '<?php echo addslashes($aluno['municipio']); ?>';
        var comunaAtual = '<?php echo addslashes($aluno['comuna']); ?>';
        
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