<?php
// super-admin/escolas/cadastrar.php - Cadastro de Escola (Angola)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar planos ativos
$planos = $conn->query("SELECT * FROM planos WHERE status = 'ativo' ORDER BY preco_mensal ASC")->fetchAll(PDO::FETCH_ASSOC);

// Buscar províncias para combobox
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';
$sugestoes = [];

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

// Função para validar telefone Angola
function validarTelefoneAngola($telefone) {
    // Remove caracteres não numéricos
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    
    // Verifica se tem 9 dígitos
    if (strlen($telefone) != 9) {
        return false;
    }
    
    // Verifica prefixos das operadoras em Angola
    $prefixos = ['91', '92', '93', '94', '95', '96', '97', '98', '99'];
    $prefixo = substr($telefone, 0, 2);
    
    return in_array($prefixo, $prefixos);
}

// Função para validar telefone fixo
function validarTelefoneFixo($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    return strlen($telefone) >= 9 && strlen($telefone) <= 12;
}

// Função para formatar telefone
function formatarTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// Função para gerar sugestões de subdomínio
function gerarSugestoesSubdominio($nome) {
    $sugestoes = [];
    
    // Remove acentos e caracteres especiais
    $nome = preg_replace('/[^a-zA-Z0-9]/', ' ', $nome);
    $nome = strtolower(trim($nome));
    $palavras = explode(' ', $nome);
    
    // Sugestão 1: nome sem espaços
    $sugestoes[] = preg_replace('/\s+/', '', $nome);
    
    // Sugestão 2: primeiras letras
    $sigla = '';
    foreach ($palavras as $palavra) {
        if (!empty($palavra)) {
            $sigla .= substr($palavra, 0, 1);
        }
    }
    if (!empty($sigla)) {
        $sugestoes[] = $sigla;
    }
    
    // Sugestão 3: primeira palavra
    if (!empty($palavras[0])) {
        $sugestoes[] = $palavras[0];
    }
    
    // Sugestão 4: nome + numero aleatorio
    $sugestoes[] = preg_replace('/\s+/', '', $nome) . rand(1, 99);
    
    // Sugestão 5: sigla + ano
    if (!empty($sigla)) {
        $sugestoes[] = $sigla . date('y');
    }
    
    // Remover duplicatas
    $sugestoes = array_unique($sugestoes);
    
    return $sugestoes;
}

// Função para verificar NUIT (API simulada - integrar com serviço real)
function verificarNUIT($nuit) {
    // Remove caracteres não numéricos
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    
    // Validação básica do NUIT angolano (14 dígitos)
    if (strlen($nuit) != 14) {
        return false;
    }
    
    // Aqui seria integração com API do AGT (Administração Geral Tributária)
    // Por enquanto, apenas validação básica
    return true;
}

// Processar AJAX para verificar NUIT
if (isset($_POST['acao']) && $_POST['acao'] == 'verificar_nuit') {
    $nuit = $_POST['nuit'] ?? '';
    $escola_id = $_POST['escola_id'] ?? 0;
    
    if (empty($nuit)) {
        echo json_encode(['success' => false, 'message' => 'NUIT não informado']);
        exit;
    }
    
    // Verificar formato
    if (!verificarNUIT($nuit)) {
        echo json_encode(['success' => false, 'message' => 'NUIT inválido (deve ter 14 dígitos)']);
        exit;
    }
    
    // Verificar duplicidade no banco
    $sql = "SELECT id, nome FROM escolas WHERE nuit = :nuit";
    $params = [':nuit' => $nuit];
    if ($escola_id) {
        $sql .= " AND id != :id";
        $params[':id'] = $escola_id;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existente) {
        echo json_encode(['success' => false, 'message' => 'NUIT já cadastrado para a escola: ' . $existente['nome']]);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'NUIT válido']);
    exit;
}

// Processar AJAX para verificar subdomínio
if (isset($_POST['acao']) && $_POST['acao'] == 'verificar_subdominio') {
    $subdominio = $_POST['subdominio'] ?? '';
    $escola_id = $_POST['escola_id'] ?? 0;
    
    if (empty($subdominio)) {
        echo json_encode(['success' => false, 'message' => 'Subdomínio não informado']);
        exit;
    }
    
    // Verificar formato (apenas letras, números e hífen)
    if (!preg_match('/^[a-z0-9-]+$/', $subdominio)) {
        echo json_encode(['success' => false, 'message' => 'Subdomínio deve conter apenas letras minúsculas, números e hífen']);
        exit;
    }
    
    // Verificar duplicidade
    $sql = "SELECT id FROM escolas WHERE subdominio = :subdominio";
    $params = [':subdominio' => $subdominio];
    if ($escola_id) {
        $sql .= " AND id != :id";
        $params[':id'] = $escola_id;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Subdomínio já está em uso']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Subdomínio disponível']);
    exit;
}

// Processar AJAX para gerar sugestões
if (isset($_GET['acao']) && $_GET['acao'] == 'gerar_sugestoes') {
    $nome = $_GET['nome'] ?? '';
    if (empty($nome)) {
        echo json_encode([]);
        exit;
    }
    
    $sugestoes = gerarSugestoesSubdominio($nome);
    echo json_encode($sugestoes);
    exit;
}

// Processar AJAX para buscar municípios
if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia_id = $_GET['provincia_id'] ?? 0;
    if ($provincia_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $provincia_id]);
        $municipios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($municipios);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Processar AJAX para buscar comunas
if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio_id = $_GET['municipio_id'] ?? 0;
    if ($municipio_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
        $stmt->execute([':municipio_id' => $municipio_id]);
        $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comunas);
    } else {
        echo json_encode([]);
    }
    exit;
}

// ============================================
// PROCESSAR CADASTRO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_escola'])) {
    $nome = $_POST['nome'] ?? '';
    $subdominio = $_POST['subdominio'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $celular = $_POST['celular'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $provincia_id = $_POST['provincia_id'] ?? '';
    $municipio_id = $_POST['municipio_id'] ?? '';
    $comuna_id = $_POST['comuna_id'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    $tipo_cobranca = $_POST['tipo_cobranca'] ?? 'mensal';
    $trial_dias = $_POST['trial_dias'] ?? 30;
    $nuit = $_POST['nuit'] ?? '';
    $ano_fundacao = $_POST['ano_fundacao'] ?? '';
    
    // Validações
    $errors = [];
    
    if (empty($nome)) {
        $errors[] = "Nome da escola é obrigatório";
    }
    if (empty($subdominio)) {
        $errors[] = "Subdomínio é obrigatório";
    }
    if (empty($email)) {
        $errors[] = "E-mail é obrigatório";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "E-mail inválido";
    }
    if (empty($plano_id)) {
        $errors[] = "Plano é obrigatório";
    }
    
    // Validar telefone celular se informado
    if (!empty($celular) && !validarTelefoneAngola($celular)) {
        $errors[] = "Número de celular inválido. Use 9 dígitos (ex: 923456789)";
    }
    
    // Validar NUIT
    if (!empty($nuit)) {
        if (!verificarNUIT($nuit)) {
            $errors[] = "NUIT inválido (deve ter 14 dígitos)";
        } else {
            // Verificar duplicidade
            $stmt = $conn->prepare("SELECT id FROM escolas WHERE nuit = :nuit");
            $stmt->execute([':nuit' => $nuit]);
            if ($stmt->fetch()) {
                $errors[] = "NUIT já cadastrado para outra escola";
            }
        }
    }
    
    // Upload da logo
    $logo = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        if (in_array($ext, $allowed)) {
            $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo)) {
                // Criar thumbnail
                if (extension_loaded('gd') && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    criarThumbnail($upload_dir . $logo, $upload_dir . 'thumb_' . $logo, 100);
                }
            } else {
                $errors[] = "Erro ao fazer upload da logo";
            }
        } else {
            $errors[] = "Formato de imagem não permitido. Use JPG, PNG, GIF, WEBP ou SVG";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Buscar nome da província, município e comuna
            $provincia_nome = '';
            $municipio_nome = '';
            $comuna_nome = '';
            
            if ($provincia_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_provincias WHERE id = :id");
                $stmt->execute([':id' => $provincia_id]);
                $provincia_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($municipio_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_municipios WHERE id = :id");
                $stmt->execute([':id' => $municipio_id]);
                $municipio_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($comuna_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_comunas WHERE id = :id");
                $stmt->execute([':id' => $comuna_id]);
                $comuna_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            
            // Buscar valor do plano
            $stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id AND status = 'ativo'");
            $stmt->execute([':id' => $plano_id]);
            $plano = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plano) {
                throw new Exception("Plano não encontrado.");
            }
            
            $valor = ($tipo_cobranca == 'mensal') ? $plano['preco_mensal'] : $plano['preco_anual'];
            $data_inicio = date('Y-m-d');
            $data_fim = ($tipo_cobranca == 'mensal') ? date('Y-m-d', strtotime('+1 month')) : date('Y-m-d', strtotime('+1 year'));
            $data_trial = date('Y-m-d', strtotime("+{$trial_dias} days"));
            
    // Inserir escola com os novos campos
$stmt = $conn->prepare("
    INSERT INTO escolas (
        nome, subdominio, dominio_personalizado, plano_id,
        email, telefone, celular, endereco, provincia, municipio, comuna,
        logo, responsavel_nome, responsavel_email, responsavel_telefone,
        nuit, ano_fundacao,
        director, director_contato, director_email,
        director_pedagogico, director_pedagogico_contato, director_pedagogico_email,
        secretario, secretario_contato, secretario_email,
        status, trial_ate, created_at
    ) VALUES (
        :nome, :subdominio, :dominio_personalizado, :plano_id,
        :email, :telefone, :celular, :endereco, :provincia, :municipio, :comuna,
        :logo, :responsavel_nome, :responsavel_email, :responsavel_telefone,
        :nuit, :ano_fundacao,
        :director, :director_contato, :director_email,
        :director_pedagogico, :director_pedagogico_contato, :director_pedagogico_email,
        :secretario, :secretario_contato, :secretario_email,
        'trial', :trial_ate, NOW()
    )
");

$stmt->execute([
    ':nome' => $nome,
    ':subdominio' => strtolower($subdominio),
    ':dominio_personalizado' => $dominio_personalizado ? strtolower($dominio_personalizado) : null,
    ':plano_id' => $plano_id,
    ':email' => $email,
    ':telefone' => $telefone ?: null,
    ':celular' => $celular ?: null,
    ':endereco' => $endereco ?: null,
    ':provincia' => $provincia_nome ?: null,
    ':municipio' => $municipio_nome ?: null,
    ':comuna' => $comuna_nome ?: null,
    ':logo' => $logo,
    ':responsavel_nome' => $responsavel_nome,
    ':responsavel_email' => $responsavel_email,
    ':responsavel_telefone' => $responsavel_telefone ?: null,
    ':nuit' => $nuit ?: null,
    ':ano_fundacao' => $ano_fundacao ?: null,
    ':director' => $_POST['director'] ?? null,
    ':director_contato' => $_POST['director_contato'] ?? null,
    ':director_email' => $_POST['director_email'] ?? null,
    ':director_pedagogico' => $_POST['director_pedagogico'] ?? null,
    ':director_pedagogico_contato' => $_POST['director_pedagogico_contato'] ?? null,
    ':director_pedagogico_email' => $_POST['director_pedagogico_email'] ?? null,
    ':secretario' => $_POST['secretario'] ?? null,
    ':secretario_contato' => $_POST['secretario_contato'] ?? null,
    ':secretario_email' => $_POST['secretario_email'] ?? null,
    ':trial_ate' => $data_trial
]);
            
            $escola_id = $conn->lastInsertId();
            
            // Criar assinatura
            $stmt = $conn->prepare("
                INSERT INTO assinaturas (
                    escola_id, plano_id, tipo_cobranca, valor,
                    data_inicio, data_fim, status, created_at
                ) VALUES (
                    :escola_id, :plano_id, :tipo_cobranca, :valor,
                    :data_inicio, :data_fim, 'pendente', NOW()
                )
            ");
            
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':plano_id' => $plano_id,
                ':tipo_cobranca' => $tipo_cobranca,
                ':valor' => $valor,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim
            ]);
            
            // Criar usuário admin da escola
            $senha_temp = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
            $senha_hash = password_hash($senha_temp, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO usuarios (
                    escola_id, nome, email, senha, tipo, status, created_at
                ) VALUES (
                    :escola_id, :nome, :email, :senha, 'admin_escola', 'ativo', NOW()
                )
            ");
            
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $responsavel_nome,
                ':email' => $responsavel_email,
                ':senha' => $senha_hash
            ]);
            
            $conn->commit();
            
            $success = "Escola cadastrada com sucesso!<br>
                        Subdomínio: <strong>{$subdominio}.sige.ao</strong><br>
                        Senha temporária do administrador: <strong>{$senha_temp}</strong>";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'cadastrar_escola', 'escolas', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $escola_id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Função para criar thumbnail
function criarThumbnail($source, $destination, $size) {
    if (!extension_loaded('gd')) {
        return;
    }
    
    list($width, $height) = getimagesize($source);
    $ratio = $width / $height;
    
    if ($width > $height) {
        $new_width = $size;
        $new_height = $size / $ratio;
    } else {
        $new_width = $size * $ratio;
        $new_height = $size;
    }
    
    $thumb = imagecreatetruecolor($new_width, $new_height);
    $source_img = imagecreatefromstring(file_get_contents($source));
    
    imagecopyresampled($thumb, $source_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    imagejpeg($thumb, $destination, 80);
    imagedestroy($thumb);
    imagedestroy($source_img);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Escola | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; }
        .form-container { max-width: 900px; margin: 30px auto; }
        .card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .logo-preview { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; border: 2px solid #006B3E; background: #f8f9fa; }
        .sugestao-item { cursor: pointer; padding: 5px 10px; margin: 2px; background: #e9ecef; border-radius: 5px; display: inline-block; font-size: 12px; }
        .sugestao-item:hover { background: #006B3E; color: white; }
        .valid-icon { color: #28a745; font-size: 18px; }
        .invalid-icon { color: #dc3545; font-size: 18px; }
        .loading-icon { color: #ffc107; font-size: 18px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .status-badge { font-size: 12px; padding: 2px 8px; border-radius: 20px; }
        .status-ok { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-warning { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <a href="index.php" class="btn btn-link mb-3"><i class="fas fa-arrow-left"></i> Voltar</a>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-school"></i> Cadastrar Nova Escola</h3>
                    <p class="mb-0 mt-2 opacity-75">Preencha os dados da escola (Angola)</p>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" id="formEscola">
                        <input type="hidden" name="cadastrar_escola" value="1">
                        
                        <!-- Dados da Escola -->
                        <h5 class="mb-3">Dados da Instituição</h5>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="required">Nome da Escola</label>
                                    <input type="text" name="nome" id="nome_escola" class="form-control" required autocomplete="off">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Ano de Fundação</label>
                                    <input type="number" name="ano_fundacao" class="form-control" placeholder="Ex: 2000" min="1900" max="<?php echo date('Y'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Subdomínio e Domínio com Sugestões -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Subdomínio</label>
                                    <div class="input-group">
                                        <input type="text" name="subdominio" id="subdominio" class="form-control" required>
                                        <span class="input-group-text">.sige.ao</span>
                                    </div>
                                    <div id="sugestoesSubdominio" class="mt-2"></div>
                                    <div id="statusSubdominio" class="mt-1"></div>
                                    <small class="text-muted">Ex: escola1 (resultará em escola1.sige.ao)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Domínio Personalizado</label>
                                    <div class="input-group">
                                        <input type="text" name="dominio_personalizado" id="dominio_personalizado" class="form-control" placeholder="exemplo.com">
                                    </div>
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contactos -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Telefone Fixo</label>
                                    <input type="text" name="telefone" id="telefone" class="form-control" placeholder="222 xxx xxx">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Celular</label>
                                    <input type="text" name="celular" id="celular" class="form-control" placeholder="9xx xxx xxx">
                                    <div id="statusCelular" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NUIT -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>NUIT (NIF Angola)</label>
                                    <div class="input-group">
                                        <input type="text" name="nuit" id="nuit" class="form-control" placeholder="14 dígitos">
                                        <button type="button" id="verificarNuitBtn" class="btn btn-outline-secondary">Verificar</button>
                                    </div>
                                    <div id="statusNuit" class="mt-1"></div>
                                    <small class="text-muted">Número de Identificação Tributária (14 dígitos)</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Endereço -->
                        <h5 class="mb-3 mt-4">Endereço (Angola)</h5>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Província</label>
                                    <select name="provincia_id" id="provincia_id" class="form-control">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($provincias as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Município</label>
                                    <select name="municipio_id" id="municipio_id" class="form-control" disabled>
                                        <option value="">Primeiro selecione a província</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Comuna</label>
                                    <select name="comuna_id" id="comuna_id" class="form-control" disabled>
                                        <option value="">Primeiro selecione o município</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Endereço Completo</label>
                            <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"></textarea>
                        </div>
                        
                        <!-- Logo -->
                        <h5 class="mb-3 mt-4">Logo da Escola</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Logo</label>
                                    <input type="file" name="logo" id="logoInput" class="form-control" accept="image/*">
                                    <small class="text-muted">Formatos: JPG, PNG, GIF, WEBP, SVG (Max: 2MB)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center">
                                    <img id="logoPreview" src="../../assets/images/no-logo.png" class="logo-preview">
                                    <p class="text-muted small mt-2">Pré-visualização da logo</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Plano e Assinatura -->
                        <h5 class="mb-3 mt-4">Plano e Assinatura</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Plano</label>
                                    <select name="plano_id" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($planos as $plano): ?>
                                        <option value="<?php echo $plano['id']; ?>">
                                            <?php echo $plano['nome']; ?> - KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Tipo de Cobrança</label>
                                    <select name="tipo_cobranca" class="form-control">
                                        <option value="mensal">Mensal</option>
                                        <option value="anual">Anual (10% desconto)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Período Trial (dias)</label>
                                    <input type="number" name="trial_dias" class="form-control" value="30">
                                </div>
                            </div>
                        </div>

                        <!-- Dados da Direção -->
<h5 class="mb-3 mt-4">Direção da Escola</h5>

<div class="row">
    <div class="col-md-12">
        <div class="mb-3">
            <label>Diretor da Escola</label>
            <input type="text" name="director" class="form-control" placeholder="Nome do Diretor">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>Contato do Diretor</label>
            <input type="text" name="director_contato" class="form-control" placeholder="9xx xxx xxx">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>E-mail do Diretor</label>
            <input type="email" name="director_email" class="form-control" placeholder="director@escola.com">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="mb-3">
            <label>Diretor Pedagógico</label>
            <input type="text" name="director_pedagogico" class="form-control" placeholder="Nome do Diretor Pedagógico">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>Contato do Diretor Pedagógico</label>
            <input type="text" name="director_pedagogico_contato" class="form-control" placeholder="9xx xxx xxx">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>E-mail do Diretor Pedagógico</label>
            <input type="email" name="director_pedagogico_email" class="form-control" placeholder="pedagogico@escola.com">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="mb-3">
            <label>Secretário</label>
            <input type="text" name="secretario" class="form-control" placeholder="Nome do Secretário">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>Contato do Secretário</label>
            <input type="text" name="secretario_contato" class="form-control" placeholder="9xx xxx xxx">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>E-mail do Secretário</label>
            <input type="email" name="secretario_email" class="form-control" placeholder="secretaria@escola.com">
        </div>
    </div>
</div>
                        
                        <!-- Responsável -->
                        <h5 class="mb-3 mt-4">Dados do Responsável</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Nome do Responsável</label>
                                    <input type="text" name="responsavel_nome" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail do Responsável</label>
                                    <input type="email" name="responsavel_email" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Telefone do Responsável</label>
                                    <input type="text" name="responsavel_telefone" class="form-control" placeholder="9xx xxx xxx">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            Após o cadastro, um e-mail será enviado para o responsável com as instruções de acesso e senha temporária.
                        </div>
                        
                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="fas fa-save"></i> Cadastrar Escola
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Preview da logo
        $('#logoInput').change(function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#logoPreview').attr('src', e.target.result);
                }
                reader.readAsDataURL(file);
            }
        });
        
        // Gerar sugestões de subdomínio
        let timeoutSugestao;
        $('#nome_escola').on('input', function() {
            clearTimeout(timeoutSugestao);
            const nome = $(this).val();
            
            timeoutSugestao = setTimeout(function() {
                if (nome.length >= 3) {
                    $.ajax({
                        url: 'cadastrar.php',
                        method: 'GET',
                        data: { acao: 'gerar_sugestoes', nome: nome },
                        success: function(data) {
                            const sugestoes = JSON.parse(data);
                            let html = '<div class="small text-muted mb-1">Sugestões de subdomínio:</div>';
                            sugestoes.forEach(function(sug) {
                                html += '<span class="sugestao-item" onclick="$(\'#subdominio\').val(\'' + sug + '\'); verificarSubdominio();">' + sug + '</span>';
                            });
                            $('#sugestoesSubdominio').html(html);
                        }
                    });
                }
            }, 500);
        });
        
        // Verificar subdomínio
        function verificarSubdominio() {
            const subdominio = $('#subdominio').val();
            if (subdominio.length < 3) {
                $('#statusSubdominio').html('<span class="status-badge status-warning"><i class="fas fa-exclamation-triangle"></i> Mínimo 3 caracteres</span>');
                return;
            }
            
            $('#statusSubdominio').html('<span class="status-badge status-warning"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>');
            
            $.ajax({
                url: 'cadastrar.php',
                method: 'POST',
                data: { acao: 'verificar_subdominio', subdominio: subdominio },
                success: function(data) {
                    const result = JSON.parse(data);
                    if (result.success) {
                        $('#statusSubdominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + result.message + '</span>');
                    } else {
                        $('#statusSubdominio').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + result.message + '</span>');
                    }
                }
            });
        }
        
        $('#subdominio').on('input', function() {
            verificarSubdominio();
        });
        
        // Validar celular
        function validarCelular() {
            const celular = $('#celular').val().replace(/[^0-9]/g, '');
            if (celular.length === 0) {
                $('#statusCelular').html('');
                return;
            }
            
            if (celular.length === 9) {
                const prefixos = ['91', '92', '93', '94', '95', '96', '97', '98', '99'];
                const prefixo = celular.substring(0, 2);
                if (prefixos.includes(prefixo)) {
                    $('#statusCelular').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Celular válido</span>');
                } else {
                    $('#statusCelular').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> Prefixo inválido</span>');
                }
            } else if (celular.length > 0 && celular.length !== 9) {
                $('#statusCelular').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> Celular deve ter 9 dígitos</span>');
            } else {
                $('#statusCelular').html('');
            }
        }
        
        $('#celular').on('input', validarCelular);
        
        // Verificar NUIT
        $('#verificarNuitBtn').click(function() {
            const nuit = $('#nuit').val().replace(/[^0-9]/g, '');
            if (nuit.length === 0) {
                $('#statusNuit').html('<span class="status-badge status-warning"><i class="fas fa-exclamation-triangle"></i> Digite o NUIT</span>');
                return;
            }
            
            $('#statusNuit').html('<span class="status-badge status-warning"><i class="fas fa-spinner fa-spin"></i> Verificando NUIT...</span>');
            
            $.ajax({
                url: 'cadastrar.php',
                method: 'POST',
                data: { acao: 'verificar_nuit', nuit: nuit },
                success: function(data) {
                    const result = JSON.parse(data);
                    if (result.success) {
                        $('#statusNuit').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + result.message + '</span>');
                    } else {
                        $('#statusNuit').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + result.message + '</span>');
                    }
                }
            });
        });
        
        $('#nuit').on('input', function() {
            const nuit = $(this).val().replace(/[^0-9]/g, '');
            if (nuit.length === 14) {
                $('#verificarNuitBtn').click();
            } else if (nuit.length > 0 && nuit.length !== 14) {
                $('#statusNuit').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> NUIT deve ter 14 dígitos</span>');
            } else {
                $('#statusNuit').html('');
            }
        });
        
        // Carregar municípios
        $('#provincia_id').change(function() {
            const provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_municipios', provincia_id: provinciaId },
                    success: function(data) {
                        const municipios = JSON.parse(data);
                        let options = '<option value="">Selecione...</option>';
                        for (let i = 0; i < municipios.length; i++) {
                            options += '<option value="' + municipios[i].id + '">' + municipios[i].nome + '</option>';
                        }
                        $('#municipio_id').html(options);
                        $('#municipio_id').prop('disabled', false);
                        $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
                    }
                });
            } else {
                $('#municipio_id').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true);
                $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Carregar comunas
        $('#municipio_id').change(function() {
            const municipioId = $(this).val();
            if (municipioId) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_comunas', municipio_id: municipioId },
                    success: function(data) {
                        const comunas = JSON.parse(data);
                        let options = '<option value="">Selecione...</option>';
                        for (let i = 0; i < comunas.length; i++) {
                            options += '<option value="' + comunas[i].id + '">' + comunas[i].nome + '</option>';
                        }
                        $('#comuna_id').html(options);
                        $('#comuna_id').prop('disabled', false);
                    }
                });
            } else {
                $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Formatar celular enquanto digita
        $('#celular').on('input', function() {
            let valor = $(this).val().replace(/[^0-9]/g, '');
            if (valor.length > 9) valor = valor.substr(0, 9);
            if (valor.length >= 4 && valor.length <= 6) {
                valor = valor.substr(0, 3) + ' ' + valor.substr(3);
            } else if (valor.length >= 7) {
                valor = valor.substr(0, 3) + ' ' + valor.substr(3, 3) + ' ' + valor.substr(6);
            }
            $(this).val(valor);
        });
        
        // Formatar telefone fixo
        $('#telefone').on('input', function() {
            let valor = $(this).val().replace(/[^0-9]/g, '');
            if (valor.length > 9) valor = valor.substr(0, 9);
            if (valor.length >= 4) {
                valor = valor.substr(0, 3) + ' ' + valor.substr(3);
            }
            $(this).val(valor);
        });
    </script>
</body>
</html>