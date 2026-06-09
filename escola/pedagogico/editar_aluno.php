<?php
// escola/pedagogico/editar_aluno.php - Editar Aluno (Completo)

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    header('Location: listar_alunos.php?error=aluno_nao_encontrado');
    exit;
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.*,
        DATE_FORMAT(e.data_nascimento, '%Y-%m-%d') as data_nascimento_formatada,
        m.id as matricula_id,
        m.numero_processo,
        m.data_matricula,
        m.status as matricula_status,
        m.turma_id as matricula_turma_id,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        esc.nome as escola_nome,
        u.id as usuario_id,
        u.usuario,
        u.email as usuario_email
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    INNER JOIN turmas t ON t.id = m.turma_id
    INNER JOIN escolas esc ON esc.id = t.escola_id
    LEFT JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.id = :aluno_id 
    AND m.ano_letivo = :ano_letivo_id
    AND m.status = 'ativa'
    LIMIT 1
";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: listar_alunos.php?error=aluno_nao_encontrado');
    exit;
}

// ============================================
// BUSCAR DOCUMENTOS DO ALUNO
// ============================================
$sql_documentos = "
    SELECT * FROM documentos_aluno 
    WHERE aluno_id = :aluno_id AND escola_id = :escola_id
";
$stmt_documentos = $conn->prepare($sql_documentos);
$stmt_documentos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$documentos = $stmt_documentos->fetchAll(PDO::FETCH_ASSOC);

$documentos_por_tipo = [];
foreach ($documentos as $doc) {
    $documentos_por_tipo[$doc['tipo']] = $doc;
}

// ============================================
// BUSCAR DADOS PARA COMBOBOXES
// ============================================

// Buscar países
$paises = $conn->query("SELECT id, nome FROM paises ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($paises)) {
    $paises = [
        ['id' => 1, 'nome' => 'Angola'],
        ['id' => 2, 'nome' => 'Portugal'],
        ['id' => 3, 'nome' => 'Brasil'],
        ['id' => 4, 'nome' => 'Cabo Verde'],
        ['id' => 5, 'nome' => 'São Tomé e Príncipe'],
        ['id' => 6, 'nome' => 'Moçambique'],
        ['id' => 7, 'nome' => 'Guiné-Bissau'],
        ['id' => 8, 'nome' => 'Timor-Leste']
    ];
}

// Buscar províncias
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($provincias)) {
    $provincias = [
        ['id' => 1, 'nome' => 'Luanda'],
        ['id' => 2, 'nome' => 'Benguela'],
        ['id' => 3, 'nome' => 'Huíla'],
        ['id' => 4, 'nome' => 'Bié'],
        ['id' => 5, 'nome' => 'Malanje']
    ];
}

// Buscar turmas do ano letivo atual
$turmas = $conn->prepare("
    SELECT t.id, t.nome, t.ano, t.turno, t.sala, t.ano_letivo
    FROM turmas t
    WHERE t.escola_id = :escola_id AND t.ano_letivo = :ano AND t.status = 'ativa'
    ORDER BY t.nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo_ano]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

$tem_turmas = !empty($turmas);

// Buscar municípios e comunas para edição
$municipios = [];
$comunas = [];
if ($aluno['provincia_id']) {
    $stmt_mun = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
    $stmt_mun->execute([':provincia_id' => $aluno['provincia_id']]);
    $municipios = $stmt_mun->fetchAll(PDO::FETCH_ASSOC);
}
if ($aluno['municipio_id']) {
    $stmt_com = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
    $stmt_com->execute([':municipio_id' => $aluno['municipio_id']]);
    $comunas = $stmt_com->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar cidades por país
$cidades = [];
if ($aluno['pais_id']) {
    $stmt_cid = $conn->prepare("SELECT id, nome FROM cidades WHERE pais_id = :pais_id ORDER BY nome");
    $stmt_cid->execute([':pais_id' => $aluno['pais_id']]);
    $cidades = $stmt_cid->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar níveis de ensino
$sql_niveis = "SELECT id, nome, ordem FROM niveis WHERE status = '1' AND escola_id = :escola_id ORDER BY ordem ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

// Buscar cursos
$sql_cursos = "SELECT id, nome, codigo, duracao_anos, nivel_id FROM cursos WHERE status = 'ativo' AND escola_id = :escola_id ORDER BY nome ASC";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute([':escola_id' => $escola_id]);
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Agrupar cursos por nível
$cursos_por_nivel = [];
foreach ($cursos as $curso) {
    $cursos_por_nivel[$curso['nivel_id']][] = $curso;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

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

// ============================================
// PROCESSAR FORMULÁRIO
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_aluno'])) {
    // Dados Pessoais
    $nome_completo = $_POST['nome_completo'] ?? '';
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $bi_numero = $_POST['bi_numero'] ?? '';
    $bi_data_emissao = $_POST['bi_data_emissao'] ?? '';
    $bi_local_emissao = $_POST['bi_local_emissao'] ?? '';
    $pais_id = $_POST['pais_id'] ?? 1;
    $cidade_id = $_POST['cidade_id'] ?? null;
    $provincia_id = $_POST['provincia_id'] ?? null;
    $municipio_id = $_POST['municipio_id'] ?? null;
    $comuna_id = $_POST['comuna_id'] ?? null;
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
    $nivel = $_POST['nivel_id'] ?? '';
    $curso = $_POST['curso_id'] ?? '';
    
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
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $nova_foto = uploadArquivoAluno($_FILES['foto'], __DIR__ . '/../../uploads/alunos/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
        if ($nova_foto) {
            if ($foto && file_exists(__DIR__ . '/../../uploads/alunos/fotos/' . $foto)) {
                unlink(__DIR__ . '/../../uploads/alunos/fotos/' . $foto);
            }
            $foto = $nova_foto;
        }
    }
    
    // Upload de Documentos
    $upload_dir_docs = __DIR__ . '/../../uploads/alunos/documentos/';
    $bi_documento = isset($_FILES['bi_documento']) ? uploadArquivoAluno($_FILES['bi_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152) : null;
    $certificado_documento = isset($_FILES['certificado_documento']) ? uploadArquivoAluno($_FILES['certificado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152) : null;
    $atestado_documento = isset($_FILES['atestado_documento']) ? uploadArquivoAluno($_FILES['atestado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152) : null;
    $declaracao_documento = isset($_FILES['declaracao_documento']) ? uploadArquivoAluno($_FILES['declaracao_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152) : null;
    
    $outros_documentos = [];
    for ($i = 1; $i <= 3; $i++) {
        $doc = uploadArquivoAluno($_FILES["outro_documento_$i"] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf','doc','docx'], 5242880);
        if ($doc) {
            $outros_documentos[] = $doc;
        }
    }
    $outros_documentos_json = json_encode($outros_documentos);
    
    try {
        $conn->beginTransaction();
        
        // Atualizar usuário
        $sql_update_user = "
            UPDATE usuarios 
            SET nome = :nome, email = :email, telefone = :telefone 
            WHERE id = :usuario_id
        ";
        $stmt_update_user = $conn->prepare($sql_update_user);
        $stmt_update_user->execute([
            ':nome' => $nome_completo,
            ':email' => $email ?: $aluno['usuario_email'],
            ':telefone' => $telefone,
            ':usuario_id' => $aluno['usuario_id']
        ]);
        
        // Atualizar estudante
        $sql_update_estudante = "
            UPDATE estudantes SET
                nome = :nome,
                bi = :bi,
                bi_data_emissao = :bi_data_emissao,
                bi_local_emissao = :bi_local_emissao,
                pais_id = :pais_id,
                cidade_id = :cidade_id,
                provincia_id = :provincia_id,
                municipio_id = :municipio_id,
                comuna_id = :comuna_id,
                endereco = :endereco,
                telefone = :telefone,
                email = :email,
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
                nivel = :nivel,
                curso = :curso
            WHERE id = :id
        ";
        
        $stmt_update_estudante = $conn->prepare($sql_update_estudante);
        $stmt_update_estudante->execute([
            ':id' => $aluno_id,
            ':nome' => $nome_completo,
            ':bi' => $bi_numero ?: null,
            ':bi_data_emissao' => $bi_data_emissao ?: null,
            ':bi_local_emissao' => $bi_local_emissao ?: null,
            ':pais_id' => $pais_id ?: null,
            ':cidade_id' => $cidade_id ?: null,
            ':provincia_id' => $provincia_id ?: null,
            ':municipio_id' => $municipio_id ?: null,
            ':comuna_id' => $comuna_id ?: null,
            ':endereco' => $endereco ?: null,
            ':telefone' => $telefone ?: null,
            ':email' => $email ?: null,
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
            ':nivel' => $nivel ?: null,
            ':curso' => $curso ?: null
        ]);
        
        // Atualizar matrícula se a turma mudou
        if ($turma_id && $turma_id != $aluno['matricula_turma_id']) {
            $sql_update_matricula = "UPDATE matriculas SET status = 'transferida' WHERE id = :matricula_id";
            $stmt_update_matricula = $conn->prepare($sql_update_matricula);
            $stmt_update_matricula->execute([':matricula_id' => $aluno['matricula_id']]);
            
            $sql_new_matricula = "
                INSERT INTO matriculas (estudante_id, turma_id, ano_letivo, numero_processo, status, data_matricula, created_at)
                VALUES (:estudante_id, :turma_id, :ano_letivo, :numero_processo, 'ativa', CURDATE(), NOW())
            ";
            $stmt_new_matricula = $conn->prepare($sql_new_matricula);
            $stmt_new_matricula->execute([
                ':estudante_id' => $aluno_id,
                ':turma_id' => $turma_id,
                ':ano_letivo' => $ano_letivo_ano,
                ':numero_processo' => $aluno['numero_processo']
            ]);
        }
        
        $conn->commit();
        $success = "Dados do aluno atualizados com sucesso!";
        
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 2px solid #006B3E; cursor: pointer; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .document-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; }
        .webcam-container { position: relative; }
        #video { width: 100%; max-width: 400px; border-radius: 10px; border: 2px solid #006B3E; background: #000; }
        .document-frame { position: absolute; border: 3px solid #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); border-radius: 4px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { border-color: #00ff00; } 50% { border-color: #ffff00; } 100% { border-color: #00ff00; } }
        .list-group-item { cursor: pointer; transition: all 0.3s; }
        .list-group-item:hover { background-color: #e8f5e9; transform: translateX(5px); }
        .badge-turma { background: #17a2b8; }
        .alert-no-turmas { background: #fff3cd; border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
   
   </br></br></br>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-edit"></i> Editar Aluno</h2>
            <a href="listar_alunos.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
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
                    <input type="hidden" name="editar_aluno" value="1">
                    
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
                                        <input type="date" name="data_nascimento" class="form-control" value="<?php echo $aluno['data_nascimento_formatada']; ?>">
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
                                        <input type="text" name="bi_numero" class="form-control" value="<?php echo htmlspecialchars($aluno['bi'] ?? ''); ?>">
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
                                        <input type="text" name="bi_local_emissao" class="form-control" value="<?php echo htmlspecialchars($aluno['bi_local_emissao'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>País</label>
                                        <div class="input-group">
                                            <select name="pais_id" id="pais_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($paises as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" <?php echo $aluno['pais_id'] == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nome']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPais">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Cidade / Naturalidade</label>
                                        <div class="input-group">
                                            <select name="cidade_id" id="cidade_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($cidades as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo $aluno['cidade_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaCidade">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <div class="input-group">
                                            <select name="provincia_id" id="provincia_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($provincias as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" <?php echo $aluno['provincia_id'] == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nome']); ?></option>
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
                                            <select name="municipio_id" id="municipio_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($municipios as $m): ?>
                                                <option value="<?php echo $m['id']; ?>" <?php echo $aluno['municipio_id'] == $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['nome']); ?></option>
                                                <?php endforeach; ?>
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
                                            <select name="comuna_id" id="comuna_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($comunas as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo $aluno['comuna_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                                                <?php endforeach; ?>
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
                                <textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($aluno['endereco'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['telefone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($aluno['email'] ?? ''); ?>">
                                        <small class="text-muted">Opcional</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filiação -->
                        <div class="tab-pane fade" id="filiacao">
                            <h5 class="mb-3">Dados do Pai</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome Completo do Pai</label><input type="text" name="pai_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_nome'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI do Pai</label><input type="text" name="pai_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone do Pai</label><input type="text" name="pai_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão do Pai</label><input type="text" name="pai_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_profissao'] ?? ''); ?>"></div></div>
                            </div>
                            
                            <h5 class="mb-3 mt-4">Dados da Mãe</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome Completo da Mãe</label><input type="text" name="mae_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_nome'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI da Mãe</label><input type="text" name="mae_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone da Mãe</label><input type="text" name="mae_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão da Mãe</label><input type="text" name="mae_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_profissao'] ?? ''); ?>"></div></div>
                            </div>
                        </div>
                        
                        <!-- Encarregado -->
                        <div class="tab-pane fade" id="encarregado">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha os dados abaixo ou selecione um existente.
                            </div>
                            
                            <div class="mb-3">
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalSelecionarResponsavel">
                                    <i class="fas fa-search"></i> Selecionar Encarregado Existente
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnLimparResponsavel">
                                    <i class="fas fa-eraser"></i> Limpar
                                </button>
                                <small class="text-muted ms-2">Clique para buscar um responsável já cadastrado</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Encarregado</label>
                                        <input type="text" name="encarregado_nome" id="encarregado_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_nome'] ?? ''); ?>">
                                        <input type="hidden" name="encarregado_id" id="encarregado_id" value="">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Parentesco</label>
                                        <select name="encarregado_parentesco" id="encarregado_parentesco" class="form-control">
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
                                        <input type="text" name="encarregado_bi" id="encarregado_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_bi'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="encarregado_telefone" id="encarregado_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_telefone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="encarregado_email" id="encarregado_email" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_email'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Endereço do Encarregado</label>
                                        <textarea name="encarregado_endereco" id="encarregado_endereco" class="form-control" rows="2"><?php echo htmlspecialchars($aluno['encarregado_endereco'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dados Académicos -->
                        <div class="tab-pane fade" id="academicos">
                            <?php if ($tem_turmas): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Ano Letivo</label>
                                        <select name="ano_letivo" class="form-control" required>
                                            <option value="<?php echo $ano_letivo_ano; ?>"><?php echo $ano_letivo_ano; ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Turma</label>
                                        <select name="turma_id" id="turma_id" class="form-control" required>
                                            <option value="">Selecione...</option>
                                            <?php foreach ($turmas as $t): ?>
                                            <option value="<?php echo $t['id']; ?>" <?php echo $aluno['matricula_turma_id'] == $t['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?> - <?php echo ucfirst($t['turno']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Classe / Ano</label>
                                        <input type="text" id="ano_escolar" class="form-control" value="<?php echo $aluno['turma_ano']; ?>" disabled>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Turno</label>
                                        <input type="text" id="turno" class="form-control" value="<?php echo ucfirst($aluno['turno']); ?>" disabled>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Sala</label>
                                        <input type="text" id="sala" class="form-control" value="<?php echo $aluno['sala'] ?: 'Não definida'; ?>" disabled>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nível de Ensino</label>
                                        <select name="nivel_id" id="nivel_id" class="form-control">
                                            <option value="">Selecione o nível de ensino...</option>
                                            <?php foreach ($niveis as $nivel): ?>
                                            <option value="<?php echo $nivel['id']; ?>" <?php echo $aluno['nivel'] == $nivel['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($nivel['nome']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Curso</label>
                                        <select name="curso_id" id="curso_id" class="form-control">
                                            <option value="">Selecione o curso...</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i> 
                                Número de Processo: <strong><?php echo $aluno['numero_processo']; ?></strong> |
                                Matrícula: <strong><?php echo $aluno['matricula']; ?></strong> |
                                Data da Matrícula: <strong><?php echo date('d/m/Y', strtotime($aluno['data_matricula'])); ?></strong>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Nenhuma turma cadastrada para o ano letivo <?php echo $ano_letivo_ano; ?>!</strong><br>
                                <a href="../turmas/cadastrar.php" class="btn btn-sm btn-warning mt-2">Cadastrar Turma</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Documentos -->
                        <div class="tab-pane fade" id="documentos">
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle"></i> 
                                Documentos enviados ficarão disponíveis na ficha do aluno. 
                                Formatos aceitos: JPG, PNG, PDF (Máx: 5MB)
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                            <h6>BI / Documento de Identificação</h6>
                                            <input type="file" name="bi_documento" id="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                            <div id="bi_preview" class="mt-2">
                                                <?php if (isset($documentos_por_tipo['bi_documento'])): ?>
                                                <div class="position-relative d-inline-block">
                                                    <?php if (pathinfo($documentos_por_tipo['bi_documento']['caminho'], PATHINFO_EXTENSION) == 'pdf'): ?>
                                                        <i class="fas fa-file-pdf fa-3x text-danger"></i>
                                                    <?php else: ?>
                                                        <img src="../../<?php echo $documentos_por_tipo['bi_documento']['caminho']; ?>" class="document-preview">
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('bi_documento', 'bi_preview')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('bi_documento', 'bi_preview', 'bi')">
                                                <i class="fas fa-camera"></i> Scanner BI
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-certificate fa-3x text-success mb-2"></i>
                                            <h6>Certificado de Conclusão / Histórico</h6>
                                            <input type="file" name="certificado_documento" id="certificado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificado_preview')">
                                            <div id="certificado_preview" class="mt-2">
                                                <?php if (isset($documentos_por_tipo['certificado_documento'])): ?>
                                                <div class="position-relative d-inline-block">
                                                    <?php if (pathinfo($documentos_por_tipo['certificado_documento']['caminho'], PATHINFO_EXTENSION) == 'pdf'): ?>
                                                        <i class="fas fa-file-pdf fa-3x text-danger"></i>
                                                    <?php else: ?>
                                                        <img src="../../<?php echo $documentos_por_tipo['certificado_documento']['caminho']; ?>" class="document-preview">
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('certificado_documento', 'certificado_preview')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('certificado_documento', 'certificado_preview', 'certificado')">
                                                <i class="fas fa-camera"></i> Scanner Certificado
                                            </button>
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
                                            <input type="file" name="atestado_documento" id="atestado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')">
                                            <div id="atestado_preview" class="mt-2">
                                                <?php if (isset($documentos_por_tipo['atestado_documento'])): ?>
                                                <div class="position-relative d-inline-block">
                                                    <?php if (pathinfo($documentos_por_tipo['atestado_documento']['caminho'], PATHINFO_EXTENSION) == 'pdf'): ?>
                                                        <i class="fas fa-file-pdf fa-3x text-danger"></i>
                                                    <?php else: ?>
                                                        <img src="../../<?php echo $documentos_por_tipo['atestado_documento']['caminho']; ?>" class="document-preview">
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('atestado_documento', 'atestado_preview')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('atestado_documento', 'atestado_preview', 'atestado')">
                                                <i class="fas fa-camera"></i> Scanner Atestado
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                            <h6>Declaração de Matrícula</h6>
                                            <input type="file" name="declaracao_documento" id="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')">
                                            <div id="declaracao_preview" class="mt-2">
                                                <?php if (isset($documentos_por_tipo['declaracao_documento'])): ?>
                                                <div class="position-relative d-inline-block">
                                                    <?php if (pathinfo($documentos_por_tipo['declaracao_documento']['caminho'], PATHINFO_EXTENSION) == 'pdf'): ?>
                                                        <i class="fas fa-file-pdf fa-3x text-danger"></i>
                                                    <?php else: ?>
                                                        <img src="../../<?php echo $documentos_por_tipo['declaracao_documento']['caminho']; ?>" class="document-preview">
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('declaracao_documento', 'declaracao_preview')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('declaracao_documento', 'declaracao_preview', 'declaracao')">
                                                <i class="fas fa-camera"></i> Scanner Declaração
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="mt-3">Outros Documentos</h6>
                            <div class="row" id="outros-documentos">
                                <div class="col-md-4">
                                    <div class="mb-2 position-relative">
                                        <input type="file" name="outro_documento_1" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')">
                                        <div id="outro1_preview" class="mt-1"></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        <input type="file" name="outro_documento_2" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')">
                                        <div id="outro2_preview" class="mt-1"></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        <input type="file" name="outro_documento_3" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')">
                                        <div id="outro3_preview" class="mt-1"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="adicionarCampoDocumento()">
                                    <i class="fas fa-plus"></i> Adicionar mais documento
                                </button>
                            </div>
                        </div>
                        
                        <!-- Foto -->
                        <div class="tab-pane fade" id="foto">
                            <div class="row">
                                <div class="col-md-6 text-center">
                                    <h5>Foto Atual</h5>
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="preview-img" onclick="ampliarImagem('<?php echo $aluno['foto']; ?>', '<?php echo htmlspecialchars($aluno['nome']); ?>')" style="cursor: pointer;">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="preview-img">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Alterar Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)">
                                    <div class="webcam-container">
                                        <video id="video" width="100%" autoplay></video>
                                        <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button>
                                        <button type="button" id="recarregarCamBtn" class="btn btn-secondary btn-sm mt-2">Recarregar Câmara</button>
                                        <canvas id="canvas" style="display:none;"></canvas>
                                    </div>
                                    <div class="preview-container mt-3">
                                        <img id="fotoPreview" src="../../assets/images/avatar-padrao.png" class="preview-img" style="display: none;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5" <?php echo !$tem_turmas ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <a href="visualizar_aluno.php?id=<?php echo $aluno_id; ?>" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>


 

<!-- Modal para Selecionar Responsável -->
<div class="modal fade" id="modalSelecionarResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-users"></i> Selecionar Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Busca -->
                <div class="row mb-3">
                    <div class="col-md-8">
                        <input type="text" id="buscarResponsavel" class="form-control" placeholder="Buscar por nome, BI, telefone ou email...">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100" onclick="buscarResponsaveis()">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                    </div>
                </div>
                
                <!-- Lista de Responsáveis -->
                <div id="listaResponsaveis" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-search fa-2x mb-2"></i>
                        <p>Digite um termo para buscar responsáveis</p>
                    </div>
                </div>
                
                <!-- Botão para criar novo -->
                <hr>
                <div class="text-center">
                    <button type="button" class="btn btn-outline-success" onclick="abrirModalNovoResponsavel()">
                        <i class="fas fa-plus-circle"></i> Cadastrar Novo Encarregado
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Cadastrar Novo Responsável -->
<div class="modal fade" id="modalNovoResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Cadastrar Novo Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formNovoResponsavel" method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Nome Completo *</label>
                                <input type="text" name="nome" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Parentesco *</label>
                                <select name="parentesco" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <option value="Pai">Pai</option>
                                    <option value="Mãe">Mãe</option>
                                    <option value="Tio">Tio</option>
                                    <option value="Tia">Tia</option>
                                    <option value="Avô">Avô</option>
                                    <option value="Avó">Avó</option>
                                    <option value="Irmão">Irmão</option>
                                    <option value="Irmã">Irmã</option>
                                    <option value="Outro">Outro</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>BI / NIF</label>
                                <input type="text" name="bi" class="form-control" placeholder="BI ou NIF">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Principal *</label>
                                <input type="text" name="telefone" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Alternativo</label>
                                <input type="text" name="telefone2" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Profissão</label>
                                <input type="text" name="profissao" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Estado Civil</label>
                                <select name="estado_civil" class="form-control">
                                    <option value="">Selecione</option>
                                    <option value="Solteiro">Solteiro(a)</option>
                                    <option value="Casado">Casado(a)</option>
                                    <option value="Divorciado">Divorciado(a)</option>
                                    <option value="Viúvo">Viúvo(a)</option>
                                    <option value="União Estável">União Estável</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Província</label>
                                <input type="text" name="provincia" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Município</label>
                                <input type="text" name="municipio" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Bairro</label>
                                <input type="text" name="bairro" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Endereço Completo</label>
                        <textarea name="endereco" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Salvar e Selecionar</button>
                </div>
            </form>
        </div>
    </div>
</div>



  <!-- Modal para Scanner de Documento -->
<div class="modal fade" id="modalScanner" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-camera"></i> Scanner de Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="position-relative">
                            <video id="scannerVideo" width="100%" height="auto" autoplay style="background: #000; border-radius: 8px;"></video>
                            <canvas id="scannerCanvas" style="display: none;"></canvas>
                            <div id="scannerOverlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                                <div class="document-frame" style="position: absolute; top: 15%; left: 10%; width: 80%; height: 70%; border: 3px solid #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); border-radius: 4px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Preview</h6>
                                <div id="previewCaptura" style="min-height: 150px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-camera fa-3x text-muted"></i>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary w-100 mb-2" onclick="tirarFotoScanner()">
                                        <i class="fas fa-camera"></i> Tirar Foto
                                    </button>
                                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="ajustarDocumento()">
                                        <i class="fas fa-crop-alt"></i> Ajustar Documento
                                    </button>
                                    <select id="documentoFormato" class="form-control form-control-sm mb-2" onchange="ajustarFrameDocumento()">
                                        <option value="A4">A4 (210x297mm)</option>
                                        <option value="A5">A5 (148x210mm)</option>
                                        <option value="Carta">Carta (216x279mm)</option>
                                        <option value="BI">BI/Documento</option>
                                        <option value="Certificado">Certificado</option>
                                    </select>
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="zoomIn()">
                                            <i class="fas fa-search-plus"></i> Zoom +
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="zoomOut()">
                                            <i class="fas fa-search-minus"></i> Zoom -
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarCaptura()">
                    <i class="fas fa-check"></i> Confirmar e Salvar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Ajuste Manual do Documento -->
<div class="modal fade" id="modalAjuste" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-crop-alt"></i> Ajustar Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div style="position: relative; display: inline-block;">
                    <img id="imagemAjuste" src="" style="max-width: 100%; max-height: 500px; cursor: crosshair;">
                    <canvas id="canvasAjuste" style="display: none;"></canvas>
                </div>
                <div class="mt-3">
                    <p>Clique e arraste para selecionar a área do documento</p>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="girarImagem()">
                            <i class="fas fa-undo-alt"></i> Girar
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="aplicarFiltro()">
                            <i class="fas fa-magic"></i> Melhorar
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="resetarAjuste()">
                            <i class="fas fa-undo"></i> Resetar
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="aplicarCorte()">Aplicar Corte</button>
            </div>
        </div>
    </div>
</div>

    <!-- Modal Novo País -->
    <div class="modal fade" id="modalNovoPais" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novoPais" class="form-control" placeholder="Nome do País"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarPaisBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaCidade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Cidade</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>País</label><input type="text" id="cidadePais" class="form-control" readonly></div><div class="mb-3"><label>Nome da Cidade</label><input type="text" id="novaCidade" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarCidadeBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Província</label><input type="text" id="municipioProvincia" class="form-control" readonly></div><div class="mb-3"><label>Nome do Município</label><input type="text" id="novoMunicipio" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Município</label><input type="text" id="comunaMunicipio" class="form-control" readonly></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" id="novaComuna" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div></div>

    <!-- Modal para Ampliar Imagem -->
    <div class="modal fade" id="modalImagem" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i> Foto do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="imagemAmpliada" src="" alt="Foto do Aluno" style="max-width: 100%; max-height: 400px; border-radius: 10px;">
                    <p id="nomeAlunoImagem" class="mt-3 fw-bold"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let scannerStream = null;
        let fotoCapturada = null;
        let currentInputId = null;
        let currentPreviewId = null;
        let currentDocumentoTipo = null;
        let cropper = null;
        let currentZoom = 1;
        let rotacaoAtual = 0;
        let stream = null;
        let documentoCounter = 4;

        const documentoTipos = {
            'bi': { nome: 'BI_Documento', inputId: 'bi_documento', previewId: 'bi_preview', tamanhoMax: 5, orientacao: 'retrato' },
            'certificado': { nome: 'Certificado', inputId: 'certificado_documento', previewId: 'certificado_preview', tamanhoMax: 5, orientacao: 'paisagem' },
            'atestado': { nome: 'Atestado', inputId: 'atestado_documento', previewId: 'atestado_preview', tamanhoMax: 5, orientacao: 'retrato' },
            'declaracao': { nome: 'Declaracao', inputId: 'declaracao_documento', previewId: 'declaracao_preview', tamanhoMax: 5, orientacao: 'retrato' }
        };

        function capturarDocumento(inputId, previewId, tipoDocumento) {
            currentInputId = inputId;
            currentPreviewId = previewId;
            currentDocumentoTipo = tipoDocumento;
            const modal = new bootstrap.Modal(document.getElementById('modalScanner'));
            modal.show();
            iniciarCameraScanner();
            setTimeout(() => ajustarFramePorTipo(tipoDocumento), 500);
        }

        function ajustarFramePorTipo(tipo) {
            const config = documentoTipos[tipo];
            if (!config) return;
            const frame = document.querySelector('.document-frame');
            if (!frame) return;
            if (config.orientacao === 'paisagem') {
                frame.style.width = '85%'; frame.style.height = '60%'; frame.style.left = '7.5%'; frame.style.top = '20%';
            } else {
                frame.style.width = '60%'; frame.style.height = '85%'; frame.style.left = '20%'; frame.style.top = '7.5%';
            }
        }

        async function iniciarCameraScanner() {
            const video = document.getElementById('scannerVideo');
            try {
                if (scannerStream) scannerStream.getTracks().forEach(track => track.stop());
                scannerStream = await navigator.mediaDevices.getUserMedia({ video: true });
                video.srcObject = scannerStream;
                await video.play();
            } catch (err) { alert('Erro ao acessar a câmera: ' + err.message); }
        }

        function tirarFotoScanner() {
            const video = document.getElementById('scannerVideo');
            const canvas = document.getElementById('scannerCanvas');
            const context = canvas.getContext('2d');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
            document.getElementById('previewCaptura').innerHTML = `<img src="${fotoCapturada}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
        }

        function confirmarCaptura() {
            if (!fotoCapturada) { alert('Tire uma foto primeiro!'); return; }
            const config = documentoTipos[currentDocumentoTipo];
            fetch(fotoCapturada).then(res => res.blob()).then(blob => {
                const fileName = `${config.nome}_${Date.now()}.jpg`;
                const file = new File([blob], fileName, { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                const inputFile = document.getElementById(currentInputId);
                inputFile.files = dataTransfer.files;
                previewDocumento(inputFile, currentPreviewId);
                fecharModaisScanner();
                Swal.fire('Sucesso!', `${config.nome} capturado com sucesso!`, 'success');
            });
        }

        function fecharModaisScanner() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalScanner'));
            if (modal) modal.hide();
            if (scannerStream) scannerStream.getTracks().forEach(track => track.stop());
            scannerStream = null;
            fotoCapturada = null;
        }

        function previewDocumento(input, previewId) {
            const preview = document.getElementById(previewId);
            if (!preview) return;
            preview.innerHTML = '';
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="position-relative d-inline-block">
                            <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px; border: 2px solid #006B3E;">
                            <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('${previewId}', '${input.id}')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <small class="text-muted d-block">${file.name}</small>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }

        function removerDocumento(previewId, inputId) {
            Swal.fire({ title: 'Confirmar', text: 'Remover este documento?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Remover' }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(previewId).innerHTML = '';
                    document.getElementById(inputId).value = '';
                    Swal.fire('Removido!', 'Documento removido com sucesso.', 'success');
                }
            });
        }

        function adicionarCampoDocumento() {
            const container = document.getElementById('outros-documentos');
            const newCol = document.createElement('div');
            newCol.className = 'col-md-4';
            newCol.innerHTML = `<div class="mb-2 position-relative"><input type="file" name="outro_documento_${documentoCounter}" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro${documentoCounter}_preview')"><div id="outro${documentoCounter}_preview" class="mt-1"></div><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerCampoDocumento(this)"><i class="fas fa-times"></i></button></div>`;
            container.appendChild(newCol);
            documentoCounter++;
        }

        function removerCampoDocumento(btn) { btn.closest('.col-md-4').remove(); }

        // Webcam
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const capturarBtn = document.getElementById('capturarBtn');
        const recarregarCamBtn = document.getElementById('recarregarCamBtn');

        function iniciarWebcam() {
            if (stream) stream.getTracks().forEach(track => track.stop());
            navigator.mediaDevices.getUserMedia({ video: true }).then(mediaStream => { stream = mediaStream; video.srcObject = stream; }).catch(err => alert('Não foi possível acessar a câmara.'));
        }
        iniciarWebcam();
        recarregarCamBtn?.addEventListener('click', iniciarWebcam);
        capturarBtn?.addEventListener('click', function() {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const fotoData = canvas.toDataURL('image/png');
            document.getElementById('fotoPreview').src = fotoData;
            document.getElementById('fotoPreview').style.display = 'block';
            fetch(fotoData).then(res => res.blob()).then(blob => {
                const file = new File([blob], 'foto_capturada_' + Date.now() + '.png', { type: 'image/png' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('fotoInput').files = dataTransfer.files;
            });
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden'; hiddenInput.name = 'foto_capturada'; hiddenInput.value = fotoData;
            document.getElementById('formAluno').appendChild(hiddenInput);
        });

        function previewFoto(input) {
            const file = input.files[0];
            if (file) { const reader = new FileReader(); reader.onload = e => { document.getElementById('fotoPreview').src = e.target.result; document.getElementById('fotoPreview').style.display = 'block'; }; reader.readAsDataURL(file); }
        }

        function ampliarImagem(foto, nomeAluno) {
            let imagemUrl = foto ? '../../uploads/alunos/fotos/' + foto : '../../assets/images/avatar-padrao.png';
            document.getElementById('imagemAmpliada').src = imagemUrl;
            document.getElementById('nomeAlunoImagem').innerHTML = '<i class="fas fa-user me-2"></i>' + nomeAluno;
            new bootstrap.Modal(document.getElementById('modalImagem')).show();
        }

        // Relacionamentos em cascata
        $('#pais_id').change(function() {
            var paisId = $(this).val();
            if (paisId) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_cidades', pais_id: paisId }, success: function(data) {
                    var cidades = JSON.parse(data);
                    var options = '<option value="">Selecione...</option>';
                    for (var i = 0; i < cidades.length; i++) options += '<option value="' + cidades[i].id + '">' + cidades[i].nome + '</option>';
                    $('#cidade_id').html(options).prop('disabled', false);
                }});
            } else { $('#cidade_id').html('<option value="">Primeiro selecione o país</option>').prop('disabled', true); }
        });

        $('#provincia_id').change(function() {
            var provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_municipios', provincia_id: provinciaId }, success: function(data) {
                    var municipios = JSON.parse(data);
                    var options = '<option value="">Selecione...</option>';
                    for (var i = 0; i < municipios.length; i++) options += '<option value="' + municipios[i].id + '">' + municipios[i].nome + '</option>';
                    $('#municipio_id').html(options).prop('disabled', false);
                }});
            } else { $('#municipio_id').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true); $('#comuna_id').prop('disabled', true); }
        });

        $('#municipio_id').change(function() {
            var municipioId = $(this).val();
            if (municipioId) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_comunas', municipio_id: municipioId }, success: function(data) {
                    var comunas = JSON.parse(data);
                    var options = '<option value="">Selecione...</option>';
                    for (var i = 0; i < comunas.length; i++) options += '<option value="' + comunas[i].id + '">' + comunas[i].nome + '</option>';
                    $('#comuna_id').html(options).prop('disabled', false);
                }});
            } else { $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true); }
        });

        $('#turma_id').change(function() {
            var turmaId = $(this).val();
            if (turmaId) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_turma', turma_id: turmaId }, success: function(data) {
                    var turma = JSON.parse(data);
                    if (turma) { $('#ano_escolar').val(turma.ano); $('#turno').val(turma.turno == 'manha' ? 'Manhã' : (turma.turno == 'tarde' ? 'Tarde' : 'Noite')); $('#sala').val(turma.sala || 'Não definida'); }
                }});
            }
        });

        $('#nivel_id').change(function() {
            var nivelId = $(this).val();
            var cursoSelect = $('#curso_id');
            cursoSelect.html('<option value="">Carregando...</option>');
            if (!nivelId) { cursoSelect.html('<option value="">Selecione um nível primeiro</option>'); return; }
            fetch(`get_cursos.php?nivel_id=${nivelId}`).then(response => response.json()).then(data => {
                if (!data.success) { cursoSelect.html('<option value="">Erro ao carregar cursos</option>'); return; }
                if (data.cursos.length === 0) { cursoSelect.html('<option value="">Nenhum curso disponível</option>'); return; }
                let html = '<option value="">Selecione o curso...</option>';
                data.cursos.forEach(curso => { html += `<option value="${curso.id}">${curso.codigo ? '[' + curso.codigo + '] ' : ''}${curso.nome}</option>`; });
                cursoSelect.html(html);
            }).catch(() => { cursoSelect.html('<option value="">Erro ao carregar cursos</option>'); });
        });

        // Modais
        $('#salvarPaisBtn').click(function() { var novoPais = $('#novoPais').val(); if(novoPais){ $.ajax({url:'cadastrar.php',method:'POST',data:{acao:'add_pais',novo_pais:novoPais},success:function(){ location.reload(); }}); } });
        $('#salvarCidadeBtn').click(function() { var novaCidade = $('#novaCidade').val(); var paisId = $('#pais_id').val(); if(novaCidade && paisId){ $.ajax({url:'cadastrar.php',method:'POST',data:{acao:'add_cidade',nova_cidade:novaCidade,pais_id:paisId},success:function(){ location.reload(); }}); } });
        $('#salvarProvinciaBtn').click(function() { var novaProvincia = $('#novaProvincia').val(); if(novaProvincia){ $.ajax({url:'cadastrar.php',method:'POST',data:{acao:'add_provincia',nova_provincia:novaProvincia},success:function(){ location.reload(); }}); } });
        $('#salvarMunicipioBtn').click(function() { var novoMunicipio = $('#novoMunicipio').val(); var provinciaId = $('#provincia_id').val(); if(novoMunicipio && provinciaId){ $.ajax({url:'cadastrar.php',method:'POST',data:{acao:'add_municipio',novo_municipio:novoMunicipio,provincia_id:provinciaId},success:function(){ location.reload(); }}); } });
        $('#salvarComunaBtn').click(function() { var novaComuna = $('#novaComuna').val(); var municipioId = $('#municipio_id').val(); if(novaComuna && municipioId){ $.ajax({url:'cadastrar.php',method:'POST',data:{acao:'add_comuna',nova_comuna:novaComuna,municipio_id:municipioId},success:function(){ location.reload(); }}); } });

    
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        $('#modalNovaCidade').on('show.bs.modal', function() { $('#cidadePais').val($('#pais_id option:selected').text()); });
        $('#modalNovoMunicipio').on('show.bs.modal', function() { $('#municipioProvincia').val($('#provincia_id option:selected').text()); });
        $('#modalNovaComuna').on('show.bs.modal', function() { $('#comunaMunicipio').val($('#municipio_id option:selected').text()); });
        
   
   </script>





<script>
// Enviar formulário novo responsável



        $('#btnLimparResponsavel').click(function() {
            $('#encarregado_id').val(''); $('#encarregado_nome').val(''); $('#encarregado_parentesco').val(''); $('#encarregado_bi').val(''); $('#encarregado_telefone').val(''); $('#encarregado_email').val(''); $('#encarregado_endereco').val('');
            Swal.fire('Limpo!', 'Campos do encarregado limpos.', 'info');
        });

        document.getElementById('formNovoResponsavel')?.addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading() });
            fetch('salvar_responsavel.php', { method: 'POST', body: new FormData(this) }).then(r=>r.json()).then(data=>{
                if(data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalNovoResponsavel')).hide();
                    selecionarResponsavel(data.id, data.nome, data.parentesco, data.bi, data.telefone, data.email, data.endereco);
                    Swal.fire('Sucesso!', 'Responsável cadastrado e selecionado.', 'success');
                } else { Swal.fire('Erro!', data.message || 'Erro ao cadastrar', 'error'); }
            }).catch(()=>{ Swal.fire('Erro!', 'Erro ao cadastrar responsável', 'error'); });
        });


document.getElementById('formNovoResponsavel').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Mostrar loading apenas no botão
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);
    formData.append('escola_id', <?php echo $escola_id ?? 1; ?>);
    
    fetch('salvar_responsavel.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Restaurar botão
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        if (data.success) {
            // Fechar TODOS os modais
            const modais = ['modalNovoResponsavel', 'modalSelecionarResponsavel'];
            modais.forEach(modalId => {
                const modalElement = document.getElementById(modalId);
                if (modalElement) {
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                    }
                }
            });
            
            // REMOVER TODOS OS BACKDROPS
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                backdrop.remove();
            });
            
            // RESTAURAR O BODY
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Limpar o formulário
            document.getElementById('formNovoResponsavel').reset();
            
            // Selecionar automaticamente o recém-criado
            selecionarResponsavel(
                data.id, 
                data.nome, 
                data.parentesco || '', 
                data.bi || '', 
                data.telefone || '', 
                data.email || '', 
                data.endereco || ''
            );
            
            // Mostrar sucesso
            Swal.fire({
                icon: 'success',
                title: 'Cadastrado!',
                text: 'Responsável cadastrado e selecionado com sucesso!',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                // Garantir que após o SweetAlert fechar, tudo esteja normal
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                    backdrop.remove();
                });
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: data.message || 'Erro ao cadastrar responsável'
            });
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: 'Erro ao cadastrar responsável. Tente novamente.'
        });
    });
});


// Função para selecionar responsável e preencher campos

function selecionarResponsavel(id, nome, parentesco, bi, telefone, email, endereco) {
    // Preencher os campos do formulário principal
    document.getElementById('encarregado_id').value = id;
    document.getElementById('encarregado_nome').value = nome;
    document.getElementById('encarregado_parentesco').value = parentesco;
    document.getElementById('encarregado_bi').value = bi;
    document.getElementById('encarregado_telefone').value = telefone;
    document.getElementById('encarregado_email').value = email;
    document.getElementById('encarregado_endereco').value = endereco;
    
    // Fechar TODOS os modais
    const modais = ['modalSelecionarResponsavel', 'modalNovoResponsavel'];
    modais.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    });
    
    // REMOVER TODOS OS BACKDROPS
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.remove();
    });
    
    // RESTAURAR O BODY
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Usar toast em vez de SweetAlert (menos invasivo)
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <div class="toast show" role="alert">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-check-circle me-2"></i>
                <strong class="me-auto">Sucesso</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">
                Responsável ${nome} selecionado com sucesso!
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 2000);
}

// Função global para limpar tudo
function limparTudo() {
    // Remover todos os backdrops
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.remove();
    });
    
    // Restaurar o body
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Remover qualquer classe modal-open residual
    document.body.classList.remove('modal-open');
    
    // Garantir que o scroll volte
    document.body.style.overflow = 'auto';
    document.documentElement.style.overflow = 'auto';
}

// Chamar a função em todos os eventos de fechamento de modal
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('hidden.bs.modal', function() {
        setTimeout(limparTudo, 50);
    });
});

// Também chamar quando SweetAlert fechar
if (typeof Swal !== 'undefined') {
    const originalSwalFire = Swal.fire;
    Swal.fire = function(...args) {
        return originalSwalFire.apply(this, args).then((result) => {
            setTimeout(limparTudo, 50);
            return result;
        });
    };
}

// Função para buscar responsáveis
function buscarResponsaveis() {
    const termo = document.getElementById('buscarResponsavel').value;
    if (termo.length < 2) {
        Swal.fire({
            icon: 'warning',
            title: 'Atenção',
            text: 'Digite pelo menos 2 caracteres para buscar',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }
    
    const container = document.getElementById('listaResponsaveis');
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Buscando...</p></div>';
    
    fetch(`buscar_responsaveis.php?termo=${encodeURIComponent(termo)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let html = '<div class="list-group">';
                data.data.forEach(resp => {
                    html += `
                        <div class="list-group-item list-group-item-action" style="cursor: pointer;" 
                             onclick="selecionarResponsavelComFechamento(${resp.id}, '${escapeHtml(resp.nome)}', '${escapeHtml(resp.parentesco || '')}', '${escapeHtml(resp.bi || '')}', '${escapeHtml(resp.telefone || '')}', '${escapeHtml(resp.email || '')}', '${escapeHtml(resp.endereco || '')}')">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${escapeHtml(resp.nome)}</strong>
                                    <br><small class="text-muted">
                                        ${escapeHtml(resp.parentesco || 'Sem parentesco')} | 
                                        ${escapeHtml(resp.telefone || 'Sem telefone')}
                                    </small>
                                </div>
                                <i class="fas fa-chevron-right text-success"></i>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-user-slash fa-2x mb-2"></i>
                        <p>Nenhum responsável encontrado com "${escapeHtml(termo)}"</p>
                        <button class="btn btn-outline-success btn-sm" onclick="abrirModalNovoResponsavel()">
                            <i class="fas fa-plus-circle"></i> Cadastrar Novo
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            container.innerHTML = '<div class="alert alert-danger">Erro ao buscar responsáveis. Tente novamente.</div>';
        });
}

// Função para selecionar e fechar modal
function selecionarResponsavelComFechamento(id, nome, parentesco, bi, telefone, email, endereco) {
    // Selecionar o responsável
    selecionarResponsavel(id, nome, parentesco, bi, telefone, email, endereco);
    
    // Fechar o modal de busca
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalSelecionarResponsavel'));
    if (modal) {
        modal.hide();
    }
    
    // Mostrar feedback
    Swal.fire({
        icon: 'success',
        title: 'Selecionado!',
        text: `O responsável ${nome} foi selecionado com sucesso.`,
        timer: 1500,
        showConfirmButton: false
    });
}

// Abrir modal novo responsável
function abrirModalNovoResponsavel() {
    // Fechar modal de busca
    const modalBusca = bootstrap.Modal.getInstance(document.getElementById('modalSelecionarResponsavel'));
    if (modalBusca) {
        modalBusca.hide();
    }
    
    // Limpar formulário
    document.getElementById('formNovoResponsavel').reset();
    
    // Abrir modal de cadastro
    const modalNovo = new bootstrap.Modal(document.getElementById('modalNovoResponsavel'));
    modalNovo.show();
}

// Limpar campos do responsável
document.getElementById('btnLimparResponsavel').addEventListener('click', function() {
    document.getElementById('encarregado_id').value = '';
    document.getElementById('encarregado_nome').value = '';
    document.getElementById('encarregado_parentesco').value = '';
    document.getElementById('encarregado_bi').value = '';
    document.getElementById('encarregado_telefone').value = '';
    document.getElementById('encarregado_email').value = '';
    document.getElementById('encarregado_endereco').value = '';
    
    Swal.fire({
        icon: 'info',
        title: 'Campos Limpos',
        text: 'Os campos do encarregado foram limpos.',
        timer: 1500,
        showConfirmButton: false
    });
});

// Buscar ao pressionar Enter
document.getElementById('buscarResponsavel').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        buscarResponsaveis();
    }
});

// Função auxiliar para escapar HTML

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Quando o modal de busca for aberto, focar no campo de busca
document.getElementById('modalSelecionarResponsavel').addEventListener('shown.bs.modal', function() {
    document.getElementById('buscarResponsavel').focus();
});

// Quando o modal de cadastro for fechado, limpar o formulário
document.getElementById('modalNovoResponsavel').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formNovoResponsavel').reset();
});
</script>


















   
<script>

let currentDocumentoNome = null;

// Configurações dos documentos


// Abrir scanner com base no tipo de documento
function capturarDocumento(inputId, previewId, tipoDocumento) {
    currentInputId = inputId;
    currentPreviewId = previewId;
    currentDocumentoTipo = tipoDocumento;
    currentDocumentoNome = documentoTipos[tipoDocumento]?.nome || 'documento';
    
    // Configurar frame conforme o tipo de documento
    const modal = new bootstrap.Modal(document.getElementById('modalScanner'));
    modal.show();
    
    iniciarCamera();
    
    // Ajustar frame conforme orientação do documento
    setTimeout(() => {
        ajustarFramePorTipo(tipoDocumento);
    }, 500);
}

// Ajustar frame conforme o tipo de documento
function ajustarFramePorTipo(tipo) {
    const config = documentoTipos[tipo];
    if (!config) return;
    
    const frame = document.querySelector('.document-frame');
    if (!frame) return;
    
    if (config.orientacao === 'paisagem') {
        frame.style.width = '85%';
        frame.style.height = '60%';
        frame.style.left = '7.5%';
        frame.style.top = '20%';
    } else {
        frame.style.width = '60%';
        frame.style.height = '85%';
        frame.style.left = '20%';
        frame.style.top = '7.5%';
    }
}

// Iniciar câmera
async function iniciarCamera() {
    const video = document.getElementById('scannerVideo');
    
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Seu navegador não suporta acesso à câmera');
        }
        
        if (scannerStream) {
            scannerStream.getTracks().forEach(track => track.stop());
        }
        
        const constraints = {
            video: {
                width: { ideal: 1920 },
                height: { ideal: 1080 },
                facingMode: { exact: "environment" }
            }
        };
        
        try {
            scannerStream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (err) {
            scannerStream = await navigator.mediaDevices.getUserMedia({ video: true });
        }
        
        video.srcObject = scannerStream;
        await video.play();
        
    } catch (err) {
        console.error('Erro ao acessar câmera:', err);
        alert('Erro ao acessar a câmera: ' + err.message);
        const modal = bootstrap.Modal.getInstance(document.getElementById('modalScanner'));
        if (modal) modal.hide();
    }
}

// Tirar foto
function tirarFotoScanner() {
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    const context = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Aplicar correções específicas para o tipo de documento
    aplicarCorrecoesEspecificas(canvas);
    
    fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
    
    const previewDiv = document.getElementById('previewCaptura');
    previewDiv.innerHTML = `<img src="${fotoCapturada}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
    
    const btnTirarFoto = document.querySelector('#modalScanner .btn-primary');
    if (btnTirarFoto) {
        btnTirarFoto.innerHTML = '<i class="fas fa-check"></i> Foto tirada!';
        setTimeout(() => {
            btnTirarFoto.innerHTML = '<i class="fas fa-camera"></i> Tirar Foto';
        }, 2000);
    }
}

// Aplicar correções específicas para cada tipo de documento
function aplicarCorrecoesEspecificas(canvas) {
    const ctx = canvas.getContext('2d');
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    
    let brightness = 20;
    let contrast = 30;
    
    // Ajustes específicos por tipo de documento
    switch(currentDocumentoTipo) {
        case 'bi':
            brightness = 25;
            contrast = 35;
            break;
        case 'certificado':
            brightness = 15;
            contrast = 25;
            break;
        case 'atestado':
            brightness = 20;
            contrast = 30;
            break;
        case 'declaracao':
            brightness = 20;
            contrast = 30;
            break;
    }
    
    for (let i = 0; i < data.length; i += 4) {
        data[i] = Math.min(255, Math.max(0, data[i] + brightness));
        data[i+1] = Math.min(255, Math.max(0, data[i+1] + brightness));
        data[i+2] = Math.min(255, Math.max(0, data[i+2] + brightness));
        
        let factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
        data[i] = factor * (data[i] - 128) + 128;
        data[i+1] = factor * (data[i+1] - 128) + 128;
        data[i+2] = factor * (data[i+2] - 128) + 128;
    }
    
    ctx.putImageData(imageData, 0, 0);
}

// Confirmar captura e salvar no campo correto
function confirmarCaptura() {
    if (!fotoCapturada) {
        alert('Tire uma foto primeiro!');
        return;
    }
    
    // Validar formato e tamanho
    validarECarregarDocumento(fotoCapturada);
}

// Validar e carregar documento
function validarECarregarDocumento(base64Image) {
    const config = documentoTipos[currentDocumentoTipo];
    if (!config) {
        mostrarMensagem('Tipo de documento inválido', 'error');
        return;
    }
    
    // Verificar tamanho aproximado (base64 aumenta ~33%)
    const sizeInBytes = Math.ceil(base64Image.length * 0.75);
    const sizeInMB = sizeInBytes / (1024 * 1024);
    
    if (sizeInMB > config.tamanhoMax) {
        mostrarMensagem(`O documento é muito grande! Tamanho máximo: ${config.tamanhoMax}MB`, 'error');
        return;
    }
    
    // Converter base64 para blob
    fetch(base64Image)
        .then(res => res.blob())
        .then(blob => {
            // Validar tipo de arquivo
            if (!blob.type.match(/image\/(jpeg|png)/)) {
                mostrarMensagem('Formato não suportado. Use JPG ou PNG.', 'error');
                return;
            }
            
            // Criar nome do arquivo
            const timestamp = Date.now();
            const fileName = `${config.nome}_${timestamp}.jpg`;
            const file = new File([blob], fileName, { type: 'image/jpeg' });
            
            // Carregar no input correto
            carregarNoInput(file, currentInputId, currentPreviewId);
            
            // Fechar modais
            fecharTodosModais();
            
            mostrarMensagem(`${config.nome} capturado e anexado com sucesso!`, 'success');
        })
        .catch(error => {
            console.error('Erro:', error);
            mostrarMensagem('Erro ao processar o documento', 'error');
        });
}

// Carregar arquivo no input e mostrar preview
function carregarNoInput(file, inputId, previewId) {
    const inputFile = document.getElementById(inputId);
    if (!inputFile) {
        console.error('Input não encontrado:', inputId);
        return;
    }
    
    // Criar DataTransfer
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    inputFile.files = dataTransfer.files;
    
    // Disparar evento change
    const event = new Event('change', { bubbles: true });
    inputFile.dispatchEvent(event);
    
    // Mostrar preview personalizado
    mostrarPreviewPersonalizado(file, previewId);
}

// Mostrar preview personalizado
function mostrarPreviewPersonalizado(file, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    preview.innerHTML = '';
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        const config = documentoTipos[currentDocumentoTipo];
        const tipoNome = config ? config.nome : 'Documento';
        
        preview.innerHTML = `
            <div class="position-relative d-inline-block">
                <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px; border: 2px solid #006B3E;">
                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                        onclick="removerDocumento('${previewId}', '${currentInputId}')">
                    <i class="fas fa-times"></i>
                </button>
                <div class="mt-1">
                    <small class="text-success">
                        <i class="fas fa-check-circle"></i> ${tipoNome} capturado
                    </small>
                    <br><small class="text-muted">${file.name} (${fileSize} MB)</small>
                </div>
            </div>
        `;
    };
    reader.readAsDataURL(file);
}

// Remover documento
function removerDocumento(previewId, inputId) {
    if (confirm('Remover este documento?')) {
        const preview = document.getElementById(previewId);
        if (preview) preview.innerHTML = '';
        
        const input = document.getElementById(inputId);
        if (input) input.value = '';
        
        mostrarMensagem('Documento removido com sucesso!', 'info');
    }
}

// Fechar todos os modais
function fecharTodosModais() {
    const modais = ['modalScanner', 'modalAjuste'];
    modais.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    });
    
    // Parar câmera
    if (scannerStream) {
        scannerStream.getTracks().forEach(track => track.stop());
        scannerStream = null;
    }
    
    // Limpar variáveis
    fotoCapturada = null;
    currentZoom = 1;
    const video = document.getElementById('scannerVideo');
    if (video) video.style.transform = 'scale(1)';
    document.getElementById('previewCaptura').innerHTML = '<i class="fas fa-camera fa-3x text-muted"></i>';
}

// Preview de documento via upload
function previewDocumento(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    preview.innerHTML = '';
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileType = file.type;
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        const extension = fileName.split('.').pop().toLowerCase();
        
        // Validar extensão
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!allowedExtensions.includes(extension)) {
            alert('Formato de arquivo não permitido. Use: JPG, PNG ou PDF');
            input.value = '';
            return;
        }
        
        // Validar tamanho (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('O arquivo é muito grande! Máximo 5MB.');
            input.value = '';
            return;
        }
        
        if (fileType.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `
                    <div class="position-relative d-inline-block">
                        <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px;">
                        <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                                onclick="removerPreview('${previewId}', this.closest('.position-relative'), '${input.id}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block">${fileName} (${fileSize} MB)</small>
                `;
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `
                <div class="position-relative d-inline-block text-center p-2 border rounded">
                    <i class="fas fa-file-pdf fa-3x text-danger"></i>
                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                            onclick="removerPreview('${previewId}', this.closest('.position-relative'), '${input.id}')">
                        <i class="fas fa-times"></i>
                    </button>
                    <div><small>${fileName}</small></div>
                    <small class="text-muted">(${fileSize} MB)</small>
                </div>
            `;
        }
    }
}

// Remover preview
function removerPreview(previewId, element, inputId) {
    if (confirm('Remover este documento?')) {
        if (element) element.remove();
        document.getElementById(previewId).innerHTML = '';
        if (inputId) document.getElementById(inputId).value = '';
    }
}

// Mostrar mensagem
function mostrarMensagem(mensagem, tipo) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo === 'success' ? 'success' : tipo === 'error' ? 'danger' : 'info'} alert-dismissible fade show position-fixed`;
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.innerHTML = `
        ${mensagem}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 3000);
}

// Zoom
function zoomIn() {
    const video = document.getElementById('scannerVideo');
    currentZoom += 0.1;
    video.style.transform = `scale(${currentZoom})`;
}

function zoomOut() {
    const video = document.getElementById('scannerVideo');
    currentZoom = Math.max(0.5, currentZoom - 0.1);
    video.style.transform = `scale(${currentZoom})`;
}

// Ajustar documento
function ajustarDocumento() {
    if (!fotoCapturada) {
        alert('Tire uma foto primeiro!');
        return;
    }
    
    document.getElementById('imagemAjuste').src = fotoCapturada;
    const modal = new bootstrap.Modal(document.getElementById('modalAjuste'));
    modal.show();
}

// Iniciar cropper
function iniciarCropper() {
    const img = document.getElementById('imagemAjuste');
    if (cropper) {
        cropper.destroy();
    }
    
    cropper = new Cropper(img, {
        aspectRatio: NaN,
        viewMode: 1,
        dragMode: 'crop',
        cropBoxMovable: true,
        cropBoxResizable: true,
        guides: true,
        center: true,
        highlight: true,
        background: true
    });
}

// Aplicar corte
function aplicarCorte() {
    if (cropper) {
        const canvas = cropper.getCroppedCanvas();
        fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
        
        const previewDiv = document.getElementById('previewCaptura');
        previewDiv.innerHTML = `<img src="${fotoCapturada}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
        
        const modalAjuste = bootstrap.Modal.getInstance(document.getElementById('modalAjuste'));
        if (modalAjuste) {
            modalAjuste.hide();
        }
        
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        
        mostrarMensagem('Documento ajustado com sucesso!', 'success');
    }
}

// Girar imagem
function girarImagem() {
    rotacaoAtual = (rotacaoAtual + 90) % 360;
    const img = document.getElementById('imagemAjuste');
    img.style.transform = `rotate(${rotacaoAtual}deg)`;
    
    if (cropper) {
        cropper.setData({ rotate: rotacaoAtual });
    }
}

// Aplicar filtro
function aplicarFiltro() {
    const img = document.getElementById('imagemAjuste');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    
    for (let i = 0; i < data.length; i += 4) {
        const gray = (data[i] + data[i+1] + data[i+2]) / 3;
        const contrast = 1.5;
        const brightness = 20;
        
        let newColor = (gray - 128) * contrast + 128 + brightness;
        newColor = Math.min(255, Math.max(0, newColor));
        
        data[i] = newColor;
        data[i+1] = newColor;
        data[i+2] = newColor;
    }
    
    ctx.putImageData(imageData, 0, 0);
    fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('imagemAjuste').src = fotoCapturada;
    
    setTimeout(() => iniciarCropper(), 100);
    mostrarMensagem('Filtro de melhoria aplicado!', 'success');
}

function resetarAjuste() {
    rotacaoAtual = 0;
    document.getElementById('imagemAjuste').style.transform = 'rotate(0deg)';
    
    if (cropper) {
        cropper.reset();
        cropper.setData({ rotate: 0 });
    }
}

// Eventos dos modais
document.getElementById('modalScanner')?.addEventListener('shown.bs.modal', function() {
    currentZoom = 1;
    const video = document.getElementById('scannerVideo');
    if (video) video.style.transform = 'scale(1)';
});

document.getElementById('modalAjuste')?.addEventListener('shown.bs.modal', function() {
    setTimeout(() => iniciarCropper(), 100);
});

document.getElementById('modalAjuste')?.addEventListener('hidden.bs.modal', function() {
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    rotacaoAtual = 0;
});
</script>


</body>
</html>