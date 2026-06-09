<?php
// escola/rh/funcionarios/editar.php - Edição de Funcionário
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$id = $_GET['id'] ?? 0;

// Buscar dados do funcionário
$stmt = $conn->prepare("
    SELECT f.*, u.email as user_email, u.status as user_status
    FROM funcionarios f 
    LEFT JOIN usuarios u ON f.usuario_id = u.id 
    WHERE f.id = ? AND f.escola_id = ?
");
$stmt->execute([$id, $escola_id]);
$funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    header('Location: listar.php');
    exit;
}

// Buscar documentos
$stmt = $conn->prepare("SELECT * FROM funcionarios_documentos WHERE funcionario_id = ?");
$stmt->execute([$id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES DE VALIDAÇÃO
// ============================================

function validarBIAngola($bi) {
    $bi = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bi));
    if (preg_match('/^[0-9]{9}[A-Z]{2}[0-9]{3}$/', $bi)) {
        return $bi;
    }
    return false;
}

function validarTelefoneAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (preg_match('/^9[1-9][0-9]{7}$/', $telefone)) {
        return $telefone;
    }
    return false;
}

function validarNUIT($nuit) {
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    if (preg_match('/^[0-9]{9}$/', $nuit)) {
        return $nuit;
    }
    return false;
}

// Função para detectar formato do papel - CORRIGIDA (evita divisão por zero)
function detectarFormatoPapel($caminho_imagem) {
    if (!file_exists($caminho_imagem)) return 'Outro';
    
    try {
        $image_info = getimagesize($caminho_imagem);
        if ($image_info === false) return 'Outro';
        
        $largura = $image_info[0];
        $altura = $image_info[1];
        
        // Verificar se largura e altura são válidas
        if ($largura <= 0 || $altura <= 0) return 'Outro';
        
        $ratio = max($largura, $altura) / min($largura, $altura);
        
        // Proporções típicas
        if ($ratio > 1.4 && $ratio < 1.45) return 'A4';
        if ($ratio > 1.29 && $ratio < 1.32) return 'A5';
        if ($ratio > 1.4 && $ratio < 1.42) return 'A3';
        if ($ratio > 1.29 && $ratio < 1.3) return 'Carta';
        
        return 'Outro';
    } catch (Exception $e) {
        return 'Outro';
    }
}

function uploadArquivo($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
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
// BUSCAR DADOS PARA COMBOBOXES
// ============================================

$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($provincias)) {
    $provincias = [
        ['id' => 1, 'nome' => 'Luanda'], ['id' => 2, 'nome' => 'Benguela'], ['id' => 3, 'nome' => 'Huíla']
    ];
}

try {
    $bancos = $conn->prepare("SELECT id, nome, codigo FROM escola_contas_bancarias WHERE escola_id = ? AND status = 'ativo' ORDER BY nome");
    $bancos->execute([$escola_id]);
    $bancos = $bancos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bancos = [];
}

$tipos_funcionario = ['professor', 'administrativo', 'auxiliar', 'seguranca', 'limpeza', 'manutencao', 'motorista', 'outro'];
$cargos = [
    'Diretor', 'Vice-Diretor', 'Coordenador Pedagógico', 'Chefe de Departamento',
    'Professor Auxiliar', 'Professor Assistente', 'Professor Principal',
    'Secretário', 'Assistente Administrativo', 'Contabilista', 'Tesoureiro',
    'Auxiliar de Limpeza', 'Segurança', 'Motorista', 'Manutenção', 'Porteiro'
];
$estadosCivis = ['Solteiro(a)', 'Casado(a)', 'Divorciado(a)', 'Viúvo(a)', 'União de Facto'];
$tiposContrato = ['Efetivo', 'Contratado', 'Estágio Profissional', 'Temporário', 'Prestador de Serviços'];
$habilitacoes = [
    '6ª Classe', '9ª Classe', '12ª Classe', 'Formação de Professores (IMED)',
    'Bacharelato', 'Licenciatura', 'Pós-Graduação', 'Mestrado', 'Doutoramento'
];
$niveis_escolaridade = ['Primário', 'Secundário', 'Médio', 'Superior'];

$error = '';
$success = '';

// ============================================
// PROCESSAR FORMULÁRIO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dados Pessoais
    $nome_completo = trim($_POST['nome_completo'] ?? '');
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $bi_numero = trim($_POST['bi_numero'] ?? '');
    $bi_emissao = $_POST['bi_emissao'] ?? '';
    $bi_validade = $_POST['bi_validade'] ?? '';
    $nuit = trim($_POST['nuit'] ?? '');
    $nacionalidade = $_POST['nacionalidade'] ?? 'Angolana';
    $naturalidade = trim($_POST['naturalidade'] ?? '');
    $provincia_id = $_POST['provincia_id'] ?? null;
    $municipio_id = $_POST['municipio_id'] ?? null;
    $comuna_id = $_POST['comuna_id'] ?? null;
    $endereco = trim($_POST['endereco'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $estado_civil = $_POST['estado_civil'] ?? '';
    $nome_pai = trim($_POST['nome_pai'] ?? '');
    $nome_mae = trim($_POST['nome_mae'] ?? '');
    $telefone_emergencia = trim($_POST['telefone_emergencia'] ?? '');
    $nome_emergencia = trim($_POST['nome_emergencia'] ?? '');
    $nivel_escolaridade = $_POST['nivel_escolaridade'] ?? '';
    
    // Dados Profissionais
    $tipo_funcionario = $_POST['tipo_funcionario'] ?? '';
    $cargo = $_POST['cargo'] ?? '';
    $data_admissao = $_POST['data_admissao'] ?? date('Y-m-d');
    $tipo_contrato = $_POST['tipo_contrato'] ?? 'Efetivo';
    $data_fim_contrato = $_POST['data_fim_contrato'] ?? null;
    $habilitacao = $_POST['habilitacao'] ?? '';
    $formacao = trim($_POST['formacao'] ?? '');
    $formacao_descricao = trim($_POST['formacao_descricao'] ?? '');
    $experiencia_anos = $_POST['experiencia_anos'] ?? 0;
    
    // Dados Bancários
    $banco_id = $_POST['banco_id'] ?? null;
    $numero_conta = trim($_POST['numero_conta'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $swift = trim($_POST['swift'] ?? '');
    $num_seguranca_social = trim($_POST['num_seguranca_social'] ?? '');
    $carteira_profissional = trim($_POST['carteira_profissional'] ?? '');
    
    // Validar campos
    $bi_validado = !empty($bi_numero) ? validarBIAngola($bi_numero) : null;
    if (!empty($bi_numero) && !$bi_validado) {
        $error = "BI inválido! Formato: 9 números + 2 letras + 3 números";
    }
    
    $telefone_validado = validarTelefoneAngola($telefone);
    if (!$telefone_validado && $error == '') {
        $error = "Telefone inválido! Deve ser um número da Unitel, Africell ou Movicel";
    }
    
    if (!empty($nuit) && !validarNUIT($nuit) && $error == '') {
        $error = "NUIT/NIF inválido! Deve ter 9 dígitos.";
    }
    
    if (!$error) {
        try {
            $conn->beginTransaction();
            
            // Verificar se BI já existe (exceto o próprio)
            if ($bi_validado) {
                $stmt = $conn->prepare("SELECT id FROM funcionarios WHERE bi = ? AND escola_id = ? AND id != ?");
                $stmt->execute([$bi_validado, $escola_id, $id]);
                if ($stmt->fetch()) {
                    throw new Exception("BI já cadastrado para outro funcionário.");
                }
            }
            
            // Atualizar usuário
            $stmt = $conn->prepare("
                UPDATE usuarios SET 
                    nome = ?, 
                    email = ?, 
                    telefone = ?,
                    updated_at = NOW()
                WHERE id = ? AND escola_id = ?
            ");
            $stmt->execute([$nome_completo, $email ?: $funcionario['numero_processo'] . '@sige.ao', $telefone_validado, $funcionario['usuario_id'], $escola_id]);
            
            // Upload da Foto (se houver nova)
            $foto = $funcionario['foto'];
            $foto_capturada = $_POST['foto_capturada'] ?? '';
            $foto_dir = __DIR__ . '/../../../uploads/funcionarios/fotos/';
            
            if (!empty($foto_capturada)) {
                $foto_data = explode(',', $foto_capturada);
                if (count($foto_data) > 1) {
                    if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
                    $foto = 'foto_func_' . time() . '_' . uniqid() . '.png';
                    file_put_contents($foto_dir . $foto, base64_decode($foto_data[1]));
                    
                    // Remover foto antiga
                    if ($funcionario['foto'] && file_exists($foto_dir . $funcionario['foto'])) {
                        unlink($foto_dir . $funcionario['foto']);
                    }
                }
            } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
                $nova_foto = uploadArquivo($_FILES['foto'], $foto_dir, ['jpg','jpeg','png','gif','webp'], 2097152);
                if ($nova_foto) {
                    // Remover foto antiga
                    if ($funcionario['foto'] && file_exists($foto_dir . $funcionario['foto'])) {
                        unlink($foto_dir . $funcionario['foto']);
                    }
                    $foto = $nova_foto;
                }
            }
            
            // Buscar nomes das localidades
            $provincia_nome = '';
            $municipio_nome = '';
            $comuna_nome = '';
            $banco_nome = '';
            $banco_codigo = '';
            
            if ($provincia_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_provincias WHERE id = ?");
                $stmt->execute([$provincia_id]);
                $provincia_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($municipio_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_municipios WHERE id = ?");
                $stmt->execute([$municipio_id]);
                $municipio_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($comuna_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_comunas WHERE id = ?");
                $stmt->execute([$comuna_id]);
                $comuna_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($banco_id) {
                try {
                    $stmt = $conn->prepare("SELECT nome, codigo FROM escola_contas_bancarias WHERE id = ?");
                    $stmt->execute([$banco_id]);
                    $banco_dados = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($banco_dados) {
                        $banco_nome = $banco_dados['nome'] ?? '';
                        $banco_codigo = $banco_dados['codigo'] ?? '';
                    }
                } catch (PDOException $e) {}
            }
            
            // Atualizar funcionário
            $stmt = $conn->prepare("
                UPDATE funcionarios SET 
                    nome = ?,
                    tipo_funcionario = ?,
                    cargo = ?,
                    bi = ?,
                    bi_emissao = ?,
                    bi_validade = ?,
                    nuit = ?,
                    nacionalidade = ?,
                    naturalidade = ?,
                    provincia_nome = ?,
                    municipio_nome = ?,
                    comuna_nome = ?,
                    endereco = ?,
                    data_nascimento = ?,
                    genero = ?,
                    estado_civil = ?,
                    nome_pai = ?,
                    nome_mae = ?,
                    telefone = ?,
                    telefone_emergencia = ?,
                    nome_emergencia = ?,
                    email = ?,
                    nivel_escolaridade = ?,
                    data_admissao = ?,
                    tipo_contrato = ?,
                    data_fim_contrato = ?,
                    habilitacao = ?,
                    formacao = ?,
                    formacao_descricao = ?,
                    experiencia_anos = ?,
                    banco_id = ?,
                    banco_nome = ?,
                    banco_codigo = ?,
                    numero_conta = ?,
                    iban = ?,
                    swift = ?,
                    num_seguranca_social = ?,
                    carteira_profissional = ?,
                    foto = ?,
                    updated_at = NOW()
                WHERE id = ? AND escola_id = ?
            ");
            
            $stmt->execute([
                $nome_completo, $tipo_funcionario, $cargo,
                $bi_validado, !empty($bi_emissao) ? $bi_emissao : null, !empty($bi_validade) ? $bi_validade : null,
                !empty($nuit) ? $nuit : null, $nacionalidade, !empty($naturalidade) ? $naturalidade : null,
                !empty($provincia_nome) ? $provincia_nome : null, !empty($municipio_nome) ? $municipio_nome : null,
                !empty($comuna_nome) ? $comuna_nome : null, !empty($endereco) ? $endereco : null,
                !empty($data_nascimento) ? $data_nascimento : null, !empty($genero) ? $genero : null,
                !empty($estado_civil) ? $estado_civil : null, !empty($nome_pai) ? $nome_pai : null,
                !empty($nome_mae) ? $nome_mae : null,
                $telefone_validado, !empty($telefone_emergencia) ? $telefone_emergencia : null,
                !empty($nome_emergencia) ? $nome_emergencia : null, !empty($email) ? $email : null,
                !empty($nivel_escolaridade) ? $nivel_escolaridade : null,
                $data_admissao, $tipo_contrato, !empty($data_fim_contrato) ? $data_fim_contrato : null,
                !empty($habilitacao) ? $habilitacao : null, !empty($formacao) ? $formacao : null,
                !empty($formacao_descricao) ? $formacao_descricao : null, $experiencia_anos ?: 0,
                !empty($banco_id) ? $banco_id : null, !empty($banco_nome) ? $banco_nome : null,
                !empty($banco_codigo) ? $banco_codigo : null, !empty($numero_conta) ? $numero_conta : null,
                !empty($iban) ? $iban : null, !empty($swift) ? $swift : null,
                !empty($num_seguranca_social) ? $num_seguranca_social : null,
                !empty($carteira_profissional) ? $carteira_profissional : null,
                $foto, $id, $escola_id
            ]);
            
            // Processar novos documentos
            $upload_dir_docs = __DIR__ . '/../../../uploads/funcionarios/documentos/' . $id . '/';
            if (!is_dir($upload_dir_docs)) mkdir($upload_dir_docs, 0777, true);
            
            $documentos_upload = [
                'bi_documento' => 'bi',
                'diploma_documento' => 'diploma',
                'certificacoes_documento' => 'certificacao',
                'declaracao_documento' => 'declaracao',
                'contrato_documento' => 'contrato',
                'atestado_medico_documento' => 'atestado_medico'
            ];
            
            foreach ($documentos_upload as $campo => $tipo) {
                if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == 0) {
                    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
                    $temp_file = $_FILES[$campo]['tmp_name'];
                    $formato = detectarFormatoPapel($temp_file);
                    $nome_arquivo = $tipo . '_' . time() . '_' . uniqid() . '.' . $ext;
                    
                    if (move_uploaded_file($temp_file, $upload_dir_docs . $nome_arquivo)) {
                        $stmt = $conn->prepare("
                            INSERT INTO funcionarios_documentos (funcionario_id, tipo_documento, nome_arquivo, caminho_arquivo, formato_papel, tamanho_arquivo)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$id, $tipo, $nome_arquivo, 'uploads/funcionarios/documentos/' . $id . '/' . $nome_arquivo, $formato, $_FILES[$campo]['size']]);
                    }
                }
            }
            
            $conn->commit();
            
            $_SESSION['mensagem'] = "Funcionário atualizado com sucesso!";
            $_SESSION['mensagem_tipo'] = "success";
            header("Location: visualizar.php?id=$id");
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Buscar ID da província pelo nome
$provincia_selecionada = null;
$municipio_selecionado = null;
$comuna_selecionada = null;

if (!empty($funcionario['provincia_nome'])) {
    $stmt = $conn->prepare("SELECT id FROM angola_provincias WHERE nome = ?");
    $stmt->execute([$funcionario['provincia_nome']]);
    $provincia_selecionada = $stmt->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
}

if (!empty($funcionario['municipio_nome']) && $provincia_selecionada) {
    $stmt = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = ? AND provincia_id = ?");
    $stmt->execute([$funcionario['municipio_nome'], $provincia_selecionada]);
    $municipio_selecionado = $stmt->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
}

if (!empty($funcionario['comuna_nome']) && $municipio_selecionado) {
    $stmt = $conn->prepare("SELECT id FROM angola_comunas WHERE nome = ? AND municipio_id = ?");
    $stmt->execute([$funcionario['comuna_nome'], $municipio_selecionado]);
    $comuna_selecionada = $stmt->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Funcionário | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
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
        
        .documento-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .documento-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .document-preview { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; }
        .webcam-container { position: relative; }
        #video { width: 100%; max-width: 400px; border-radius: 10px; border: 2px solid #006B3E; background: #000; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
    </style>
</head>
<body>
  
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-edit"></i> Editar Funcionário
                <small class="text-muted"><?php echo htmlspecialchars($funcionario['numero_processo']); ?></small>
            </h2>
            <a href="listar.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="funcionarioTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosProfissionais">Dados Profissionais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosBancarios">Dados Bancários</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#documentos">Documentos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#foto">Foto</button></li>
                </ul>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="formFuncionario">
                    <div class="tab-content">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dadosPessoais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Nome Completo</label>
                                        <input type="text" name="nome_completo" class="form-control" value="<?php echo htmlspecialchars($funcionario['nome']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control" value="<?php echo $funcionario['data_nascimento']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="M" <?php echo $funcionario['genero'] == 'M' ? 'selected' : ''; ?>>Masculino</option>
                                            <option value="F" <?php echo $funcionario['genero'] == 'F' ? 'selected' : ''; ?>>Feminino</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº do BI</label>
                                        <input type="text" name="bi_numero" id="bi_numero" class="form-control" 
                                               value="<?php echo htmlspecialchars($funcionario['bi']); ?>"
                                               placeholder="123456789AA001" pattern="[0-9]{9}[A-Za-z]{2}[0-9]{3}" 
                                               oninput="this.value = this.value.toUpperCase()" style="text-transform: uppercase;">
                                        <small class="text-muted">Formato: 9 números + 2 letras + 3 números</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_emissao" class="form-control" value="<?php echo $funcionario['bi_emissao']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Validade do BI</label>
                                        <input type="date" name="bi_validade" class="form-control" value="<?php echo $funcionario['bi_validade']; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>NUIT (NIF)</label>
                                        <input type="text" name="nuit" class="form-control" value="<?php echo htmlspecialchars($funcionario['nuit']); ?>" placeholder="9 dígitos">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nacionalidade</label>
                                        <input type="text" name="nacionalidade" class="form-control" value="<?php echo htmlspecialchars($funcionario['nacionalidade']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade</label>
                                        <input type="text" name="naturalidade" class="form-control" value="<?php echo htmlspecialchars($funcionario['naturalidade']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Estado Civil</label>
                                        <select name="estado_civil" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($estadosCivis as $ec): ?>
                                                <option value="<?php echo $ec; ?>" <?php echo $funcionario['estado_civil'] == $ec ? 'selected' : ''; ?>><?php echo $ec; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nível de Escolaridade</label>
                                        <select name="nivel_escolaridade" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($niveis_escolaridade as $ne): ?>
                                                <option value="<?php echo $ne; ?>" <?php echo $funcionario['nivel_escolaridade'] == $ne ? 'selected' : ''; ?>><?php echo $ne; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº Segurança Social</label>
                                        <input type="text" name="num_seguranca_social" class="form-control" value="<?php echo htmlspecialchars($funcionario['num_seguranca_social']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Carteira Profissional</label>
                                        <input type="text" name="carteira_profissional" class="form-control" value="<?php echo htmlspecialchars($funcionario['carteira_profissional']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <div class="input-group">
                                            <select name="provincia_id" id="provincia_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($provincias as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>" <?php echo $provincia_selecionada == $p['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p['nome']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Município</label>
                                        <select name="municipio_id" id="municipio_id" class="form-control">
                                            <option value="">Selecione...</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Comuna</label>
                                        <select name="comuna_id" id="comuna_id" class="form-control">
                                            <option value="">Selecione...</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label>Endereço Completo</label>
                                        <textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($funcionario['endereco']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Telefone</label>
                                        <input type="tel" name="telefone" id="telefone" class="form-control" required 
                                               value="<?php echo htmlspecialchars($funcionario['telefone']); ?>" placeholder="923456789">
                                        <small class="text-muted" id="operadora-info"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($funcionario['email']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Contacto de Emergência</label>
                                        <input type="tel" name="telefone_emergencia" class="form-control" value="<?php echo htmlspecialchars($funcionario['telefone_emergencia']); ?>" placeholder="9XX XXX XXX">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Contacto de Emergência</label>
                                        <input type="text" name="nome_emergencia" class="form-control" value="<?php echo htmlspecialchars($funcionario['nome_emergencia']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Pai</label>
                                        <input type="text" name="nome_pai" class="form-control" value="<?php echo htmlspecialchars($funcionario['nome_pai']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome da Mãe</label>
                                        <input type="text" name="nome_mae" class="form-control" value="<?php echo htmlspecialchars($funcionario['nome_mae']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dados Profissionais -->
                        <div class="tab-pane fade" id="dadosProfissionais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Tipo de Funcionário</label>
                                        <select name="tipo_funcionario" class="form-control" required>
                                            <option value="">Selecione...</option>
                                            <?php foreach ($tipos_funcionario as $tipo): ?>
                                                <option value="<?php echo $tipo; ?>" <?php echo $funcionario['tipo_funcionario'] == $tipo ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst($tipo); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Cargo/Função</label>
                                        <select name="cargo" class="form-control" required>
                                            <option value="">Selecione...</option>
                                            <?php foreach ($cargos as $cargo): ?>
                                                <option value="<?php echo $cargo; ?>" <?php echo $funcionario['cargo'] == $cargo ? 'selected' : ''; ?>>
                                                    <?php echo $cargo; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Admissão</label>
                                        <input type="date" name="data_admissao" class="form-control" value="<?php echo $funcionario['data_admissao']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Tipo de Contrato</label>
                                        <select name="tipo_contrato" class="form-control">
                                            <?php foreach ($tiposContrato as $tc): ?>
                                                <option value="<?php echo $tc; ?>" <?php echo $funcionario['tipo_contrato'] == $tc ? 'selected' : ''; ?>><?php echo $tc; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data Fim do Contrato (se aplicável)</label>
                                        <input type="date" name="data_fim_contrato" class="form-control" value="<?php echo $funcionario['data_fim_contrato']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Anos de Experiência</label>
                                        <input type="number" name="experiencia_anos" class="form-control" min="0" step="0.5" value="<?php echo $funcionario['experiencia_anos']; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Habilitação Literária</label>
                                        <select name="habilitacao" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($habilitacoes as $h): ?>
                                                <option value="<?php echo $h; ?>" <?php echo $funcionario['habilitacao'] == $h ? 'selected' : ''; ?>><?php echo $h; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Formação Académica / Cursos</label>
                                        <input type="text" name="formacao" class="form-control" value="<?php echo htmlspecialchars($funcionario['formacao']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Descrição da Formação</label>
                                <textarea name="formacao_descricao" class="form-control" rows="3"><?php echo htmlspecialchars($funcionario['formacao_descricao']); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Dados Bancários -->
                        <div class="tab-pane fade" id="dadosBancarios">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Informações bancárias para processamento salarial
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Banco</label>
                                        <select name="banco_id" id="banco_id" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php if (!empty($bancos)): ?>
                                                <?php foreach ($bancos as $banco): ?>
                                                    <option value="<?php echo $banco['id']; ?>" <?php echo $funcionario['banco_id'] == $banco['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($banco['nome']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Número da Conta</label>
                                        <input type="text" name="numero_conta" class="form-control" value="<?php echo htmlspecialchars($funcionario['numero_conta']); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>IBAN</label>
                                        <input type="text" name="iban" class="form-control" value="<?php echo htmlspecialchars($funcionario['iban']); ?>" placeholder="AO06.0000.0000.0000.0000.0000.000">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>SWIFT/BIC</label>
                                        <input type="text" name="swift" class="form-control" value="<?php echo htmlspecialchars($funcionario['swift']); ?>">
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
                                            <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                            <div id="bi_preview" class="mt-2"></div>
                                            <?php
                                            $doc_bi = array_filter($documentos, function($d) { return $d['tipo_documento'] == 'bi'; });
                                            if (!empty($doc_bi)) {
                                                $doc = reset($doc_bi);
                                                echo '<small class="text-muted">Documento atual: <a href="../../../' . $doc['caminho_arquivo'] . '" target="_blank">Ver arquivo</a></small>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-graduation-cap fa-3x text-success mb-2"></i>
                                            <h6>Diploma / Certificado</h6>
                                            <input type="file" name="diploma_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'diploma_preview')">
                                            <div id="diploma_preview" class="mt-2"></div>
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
                                            <input type="file" name="certificacoes_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificacoes_preview')">
                                            <div id="certificacoes_preview" class="mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                            <h6>Declaração / Comprovativo</h6>
                                            <input type="file" name="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')">
                                            <div id="declaracao_preview" class="mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-contract fa-3x text-secondary mb-2"></i>
                                            <h6>Contrato de Trabalho</h6>
                                            <input type="file" name="contrato_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'contrato_preview')">
                                            <div id="contrato_preview" class="mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-stethoscope fa-3x text-danger mb-2"></i>
                                            <h6>Atestado Médico</h6>
                                            <input type="file" name="atestado_medico_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')">
                                            <div id="atestado_preview" class="mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="mt-3">Outros Documentos</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_1" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')">
                                    <div id="outro1_preview" class="mt-1"></div>
                                </div>
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_2" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')">
                                    <div id="outro2_preview" class="mt-1"></div>
                                </div>
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_3" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')">
                                    <div id="outro3_preview" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Foto -->
                        <div class="tab-pane fade" id="foto">
                            <div class="row">
                                <div class="col-md-6 text-center">
                                    <h5>Foto Atual</h5>
                                    <img src="../../../uploads/funcionarios/fotos/<?php echo $funcionario['foto']; ?>" class="preview-img mb-3" 
                                         onerror="this.src='../../../assets/images/avatar-padrao.png'">
                                    <h5>Alterar Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)">
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Capturar com Webcam</h5>
                                    <div class="webcam-container">
                                        <video id="video" width="100%" autoplay></video>
                                        <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button>
                                        <button type="button" id="recarregarCamBtn" class="btn btn-secondary btn-sm mt-2">Recarregar Câmara</button>
                                        <canvas id="canvas" style="display:none;"></canvas>
                                    </div>
                                    <div class="mt-3">
                                        <img id="fotoPreview" src="../../../uploads/funcionarios/fotos/<?php echo $funcionario['foto']; ?>" class="preview-img" 
                                             onerror="this.src='../../../assets/images/avatar-padrao.png'">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Atualizar Funcionário
                        </button>
                        <a href="visualizar.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Detetar operadora
        function detectarOperadora() {
            const tel = document.getElementById('telefone').value.replace(/[^0-9]/g, '');
            const info = document.getElementById('operadora-info');
            if (tel.length >= 2) {
                const prefixo = tel.substring(0, 2);
                let operadora = '';
                if (['91','92','93'].includes(prefixo)) operadora = 'UNITEL';
                else if (['94','95','96'].includes(prefixo)) operadora = 'AFRICELL';
                else if (['97','98','99'].includes(prefixo)) operadora = 'MOVICEL';
                else operadora = 'Número inválido';
                info.innerHTML = operadora !== 'Número inválido' ? `<span class="badge bg-success">${operadora}</span>` : '<span class="text-danger">Número inválido</span>';
            } else { info.innerHTML = ''; }
        }
        detectarOperadora();
        document.getElementById('telefone').addEventListener('input', detectarOperadora);
        document.getElementById('bi_numero').addEventListener('input', function() { this.value = this.value.toUpperCase(); });
        
        function previewDocumento(input, previewId) {
            const preview = $('#' + previewId);
            preview.html('');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) { preview.html(`<img src="${e.target.result}" class="document-preview" style="max-width: 60px; border-radius: 5px;">`); };
                    reader.readAsDataURL(file);
                } else {
                    preview.html(`<span class="badge bg-info"><i class="fas fa-file-pdf"></i> ${file.name.substring(0,15)}</span>`);
                }
            }
        }
        
        function previewFoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) { $('#fotoPreview').attr('src', e.target.result); };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Carregar municípios
        $('#provincia_id').change(function() {
            const id = $(this).val();
            if (id) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_municipios', provincia_id: id },
                    success: function(data) {
                        let opts = '<option value="">Selecione...</option>';
                        JSON.parse(data).forEach(m => opts += `<option value="${m.id}">${m.nome}</option>`);
                        $('#municipio_id').html(opts).prop('disabled', false);
                        $('#comuna_id').html('<option value="">Selecione o município</option>').prop('disabled', true);
                    }
                });
            } else {
                $('#municipio_id').html('<option value="">Selecione a província</option>').prop('disabled', true);
                $('#comuna_id').html('<option value="">Selecione o município</option>').prop('disabled', true);
            }
        });
        
        $('#municipio_id').change(function() {
            const id = $(this).val();
            if (id) {
                $.ajax({ url: 'cadastrar.php', method: 'GET', data: { acao: 'get_comunas', municipio_id: id },
                    success: function(data) {
                        let opts = '<option value="">Selecione...</option>';
                        JSON.parse(data).forEach(c => opts += `<option value="${c.id}">${c.nome}</option>`);
                        $('#comuna_id').html(opts).prop('disabled', false);
                    }
                });
            } else {
                $('#comuna_id').html('<option value="">Selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Carregar municípios e comunas se já houver valores
        <?php if ($provincia_selecionada): ?>
        setTimeout(function() {
            $('#provincia_id').val(<?php echo $provincia_selecionada; ?>).trigger('change');
            setTimeout(function() {
                <?php if ($municipio_selecionado): ?>
                $('#municipio_id').val(<?php echo $municipio_selecionado; ?>).trigger('change');
                setTimeout(function() {
                    <?php if ($comuna_selecionada): ?>
                    $('#comuna_id').val(<?php echo $comuna_selecionada; ?>);
                    <?php endif; ?>
                }, 500);
                <?php endif; ?>
            }, 500);
        }, 100);
        <?php endif; ?>
        
        // Webcam
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        let webcamStream = null;
        
        function iniciarWebcam() {
            if (webcamStream) webcamStream.getTracks().forEach(t => t.stop());
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(s => { webcamStream = s; video.srcObject = s; })
                .catch(e => alert('Não foi possível acessar a câmara.'));
        }
        iniciarWebcam();
        
        document.getElementById('recarregarCamBtn')?.addEventListener('click', iniciarWebcam);
        document.getElementById('capturarBtn')?.addEventListener('click', function() {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            const data = canvas.toDataURL('image/png');
            $('#fotoPreview').attr('src', data);
            fetch(data).then(r => r.blob()).then(b => {
                const f = new File([b], 'foto.png', { type: 'image/png' });
                const dt = new DataTransfer(); dt.items.add(f);
                document.getElementById('fotoInput').files = dt.files;
            });
            $('<input>').attr({ type: 'hidden', name: 'foto_capturada', value: data }).appendTo('#formFuncionario');
        });
        
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    </script>
</body>
</html>