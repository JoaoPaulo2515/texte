<?php
// super-admin/escolas/cadastrar.php - Cadastro de Escola (Wizard)
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

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

// Função para validar telefone Angola (celular)
function validarCelularAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) != 9) return false;
    $prefixos = ['91', '92', '93', '94', '95', '96', '97', '98', '99'];
    $prefixo = substr($telefone, 0, 2);
    return in_array($prefixo, $prefixos);
}

// Função para validar telefone fixo Angola
function validarTelefoneFixoAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) != 9) return false;
    $prefixos = ['22', '23', '24', '25', '26'];
    $prefixo = substr($telefone, 0, 2);
    return in_array($prefixo, $prefixos);
}

// Função para formatar celular
function formatarCelular($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// Função para formatar telefone fixo
function formatarTelefoneFixo($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// Função para gerar sugestões de subdomínio
function gerarSugestoesSubdominio($nome) {
    $sugestoes = [];
    $nome = preg_replace('/[^a-zA-Z0-9]/', ' ', $nome);
    $nome = strtolower(trim($nome));
    $palavras = explode(' ', $nome);
    
    $sugestoes[] = preg_replace('/\s+/', '', $nome);
    
    $sigla = '';
    foreach ($palavras as $palavra) {
        if (!empty($palavra)) $sigla .= substr($palavra, 0, 1);
    }
    if (!empty($sigla)) $sugestoes[] = $sigla;
    if (!empty($palavras[0])) $sugestoes[] = $palavras[0];
    $sugestoes[] = preg_replace('/\s+/', '', $nome) . rand(1, 99);
    if (!empty($sigla)) $sugestoes[] = $sigla . date('y');
    
    return array_unique($sugestoes);
}

// Função para verificar NUIT
function verificarNUIT($nuit) {
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    return strlen($nuit) == 14;
}

// Função para criar thumbnail
function criarThumbnail($source, $destination, $size) {
    if (!extension_loaded('gd')) return;
    list($width, $height) = getimagesize($source);
    $ratio = $width / $height;
    $new_width = $width > $height ? $size : $size * $ratio;
    $new_height = $width > $height ? $size / $ratio : $size;
    $thumb = imagecreatetruecolor($new_width, $new_height);
    $source_img = imagecreatefromstring(file_get_contents($source));
    imagecopyresampled($thumb, $source_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    imagejpeg($thumb, $destination, 80);
    imagedestroy($thumb);
    imagedestroy($source_img);
}

// Processar AJAX
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];
    
    if ($ajax == 'get_municipios' && isset($_GET['provincia_id'])) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $_GET['provincia_id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($ajax == 'get_comunas' && isset($_GET['municipio_id'])) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
        $stmt->execute([':municipio_id' => $_GET['municipio_id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($ajax == 'verificar_subdominio') {
        $subdominio = $_GET['subdominio'] ?? '';
        if (empty($subdominio)) {
            echo json_encode(['valid' => false, 'message' => 'Subdomínio não informado']);
            exit;
        }
        if (!preg_match('/^[a-z0-9-]+$/', $subdominio)) {
            echo json_encode(['valid' => false, 'message' => 'Apenas letras minúsculas, números e hífen']);
            exit;
        }
        $stmt = $conn->prepare("SELECT id FROM escolas WHERE subdominio = :subdominio");
        $stmt->execute([':subdominio' => $subdominio]);
        if ($stmt->fetch()) {
            echo json_encode(['valid' => false, 'message' => 'Subdomínio já está em uso']);
            exit;
        }
        echo json_encode(['valid' => true, 'message' => 'Subdomínio disponível']);
        exit;
    }
    
    if ($ajax == 'verificar_nuit') {
        $nuit = $_GET['nuit'] ?? '';
        if (empty($nuit)) {
            echo json_encode(['valid' => false, 'message' => 'NUIT não informado']);
            exit;
        }
        if (!verificarNUIT($nuit)) {
            echo json_encode(['valid' => false, 'message' => 'NUIT deve ter 14 dígitos']);
            exit;
        }
        $stmt = $conn->prepare("SELECT id FROM escolas WHERE nuit = :nuit");
        $stmt->execute([':nuit' => $nuit]);
        if ($stmt->fetch()) {
            echo json_encode(['valid' => false, 'message' => 'NUIT já cadastrado']);
            exit;
        }
        echo json_encode(['valid' => true, 'message' => 'NUIT válido']);
        exit;
    }
    
    if ($ajax == 'gerar_sugestoes') {
        $nome = $_GET['nome'] ?? '';
        echo json_encode(gerarSugestoesSubdominio($nome));
        exit;
    }
    
    exit;
}

// ============================================
// PROCESSAR CADASTRO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    $step = (int)$_POST['step'];
    $errors = [];
    
    // Validação por etapa
    if ($step == 1) {
        if (empty($_POST['nome'])) $errors['nome'] = 'Nome da escola é obrigatório';
        if (empty($_POST['subdominio'])) $errors['subdominio'] = 'Subdomínio é obrigatório';
        if (!empty($_POST['subdominio']) && !preg_match('/^[a-z0-9-]+$/', $_POST['subdominio'])) $errors['subdominio'] = 'Apenas letras minúsculas, números e hífen';
        if (empty($_POST['email'])) $errors['email'] = 'E-mail é obrigatório';
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail inválido';
        if (empty($_POST['plano_id'])) $errors['plano_id'] = 'Plano é obrigatório';
        if (!empty($_POST['celular']) && !validarCelularAngola($_POST['celular'])) $errors['celular'] = 'Celular inválido. Use 9 dígitos (ex: 923 456 789)';
        
        if (empty($errors)) {
            $_SESSION['escola_temp'] = [
                'nome' => $_POST['nome'],
                'subdominio' => $_POST['subdominio'],
                'dominio_personalizado' => $_POST['dominio_personalizado'] ?? '',
                'email' => $_POST['email'],
                'telefone' => $_POST['telefone'] ?? '',
                'celular' => $_POST['celular'] ?? '',
                'nuit' => $_POST['nuit'] ?? '',
                'ano_fundacao' => $_POST['ano_fundacao'] ?? '',
                'plano_id' => $_POST['plano_id'],
                'tipo_cobranca' => $_POST['tipo_cobranca'] ?? 'mensal',
                'trial_dias' => $_POST['trial_dias'] ?? 30
            ];
            echo json_encode(['success' => true, 'next_step' => 2]);
        } else {
            echo json_encode(['success' => false, 'errors' => $errors]);
        }
        exit;
    }
    
    if ($step == 2) {
        if (empty($_POST['provincia_id'])) $errors['provincia_id'] = 'Selecione a província';
        if (empty($_POST['municipio_id'])) $errors['municipio_id'] = 'Selecione o município';
        
        if (empty($errors)) {
            $_SESSION['escola_temp']['provincia_id'] = $_POST['provincia_id'];
            $_SESSION['escola_temp']['municipio_id'] = $_POST['municipio_id'];
            $_SESSION['escola_temp']['comuna_id'] = $_POST['comuna_id'] ?? '';
            $_SESSION['escola_temp']['endereco'] = $_POST['endereco'] ?? '';
            echo json_encode(['success' => true, 'next_step' => 3]);
        } else {
            echo json_encode(['success' => false, 'errors' => $errors]);
        }
        exit;
    }
    
    if ($step == 3) {
        $upload_dir = __DIR__ . '/../../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $logo = '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $allowed)) {
                $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo) && extension_loaded('gd')) {
                    criarThumbnail($upload_dir . $logo, $upload_dir . 'thumb_' . $logo, 100);
                }
            }
        }
        
        $_SESSION['escola_temp']['logo'] = $logo;
        $_SESSION['escola_temp']['director'] = $_POST['director'] ?? '';
        $_SESSION['escola_temp']['director_contato'] = $_POST['director_contato'] ?? '';
        $_SESSION['escola_temp']['director_email'] = $_POST['director_email'] ?? '';
        $_SESSION['escola_temp']['director_pedagogico'] = $_POST['director_pedagogico'] ?? '';
        $_SESSION['escola_temp']['director_pedagogico_contato'] = $_POST['director_pedagogico_contato'] ?? '';
        $_SESSION['escola_temp']['director_pedagogico_email'] = $_POST['director_pedagogico_email'] ?? '';
        $_SESSION['escola_temp']['secretario'] = $_POST['secretario'] ?? '';
        $_SESSION['escola_temp']['secretario_contato'] = $_POST['secretario_contato'] ?? '';
        $_SESSION['escola_temp']['secretario_email'] = $_POST['secretario_email'] ?? '';
        
        echo json_encode(['success' => true, 'next_step' => 4]);
        exit;
    }
    
    if ($step == 4) {
        $temp = $_SESSION['escola_temp'];
        
        try {
            $conn->beginTransaction();
            
            // Buscar nomes da província, município e comuna
            $provincia_nome = '';
            if (!empty($temp['provincia_id'])) {
                $stmt = $conn->prepare("SELECT nome FROM angola_provincias WHERE id = :id");
                $stmt->execute([':id' => $temp['provincia_id']]);
                $provincia_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            
            $municipio_nome = '';
            if (!empty($temp['municipio_id'])) {
                $stmt = $conn->prepare("SELECT nome FROM angola_municipios WHERE id = :id");
                $stmt->execute([':id' => $temp['municipio_id']]);
                $municipio_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            
            $comuna_nome = '';
            if (!empty($temp['comuna_id'])) {
                $stmt = $conn->prepare("SELECT nome FROM angola_comunas WHERE id = :id");
                $stmt->execute([':id' => $temp['comuna_id']]);
                $comuna_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            
            // Buscar plano
            $stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id");
            $stmt->execute([':id' => $temp['plano_id']]);
            $plano = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $valor = ($temp['tipo_cobranca'] == 'mensal') ? $plano['preco_mensal'] : $plano['preco_anual'];
            $data_inicio = date('Y-m-d');
            $data_fim = ($temp['tipo_cobranca'] == 'mensal') ? date('Y-m-d', strtotime('+1 month')) : date('Y-m-d', strtotime('+1 year'));
            $data_trial = date('Y-m-d', strtotime("+{$temp['trial_dias']} days"));
            
            // Inserir escola
            $stmt = $conn->prepare("
                INSERT INTO escolas (
                    nome, subdominio, dominio_personalizado, plano_id,
                    email, telefone, celular, endereco, provincia, municipio, comuna,
                    logo, nuit, ano_fundacao,
                    director, director_contato, director_email,
                    director_pedagogico, director_pedagogico_contato, director_pedagogico_email,
                    secretario, secretario_contato, secretario_email,
                    status, trial_ate, created_at
                ) VALUES (
                    :nome, :subdominio, :dominio_personalizado, :plano_id,
                    :email, :telefone, :celular, :endereco, :provincia, :municipio, :comuna,
                    :logo, :nuit, :ano_fundacao,
                    :director, :director_contato, :director_email,
                    :director_pedagogico, :director_pedagogico_contato, :director_pedagogico_email,
                    :secretario, :secretario_contato, :secretario_email,
                    'trial', :trial_ate, NOW()
                )
            ");
            
            $stmt->execute([
                ':nome' => $temp['nome'],
                ':subdominio' => strtolower($temp['subdominio']),
                ':dominio_personalizado' => !empty($temp['dominio_personalizado']) ? strtolower($temp['dominio_personalizado']) : null,
                ':plano_id' => $temp['plano_id'],
                ':email' => $temp['email'],
                ':telefone' => !empty($temp['telefone']) ? $temp['telefone'] : null,
                ':celular' => !empty($temp['celular']) ? $temp['celular'] : null,
                ':endereco' => !empty($temp['endereco']) ? $temp['endereco'] : null,
                ':provincia' => $provincia_nome ?: null,
                ':municipio' => $municipio_nome ?: null,
                ':comuna' => $comuna_nome ?: null,
                ':logo' => $temp['logo'] ?: null,
                ':nuit' => !empty($temp['nuit']) ? $temp['nuit'] : null,
                ':ano_fundacao' => !empty($temp['ano_fundacao']) ? $temp['ano_fundacao'] : null,
                ':director' => !empty($temp['director']) ? $temp['director'] : null,
                ':director_contato' => !empty($temp['director_contato']) ? $temp['director_contato'] : null,
                ':director_email' => !empty($temp['director_email']) ? $temp['director_email'] : null,
                ':director_pedagogico' => !empty($temp['director_pedagogico']) ? $temp['director_pedagogico'] : null,
                ':director_pedagogico_contato' => !empty($temp['director_pedagogico_contato']) ? $temp['director_pedagogico_contato'] : null,
                ':director_pedagogico_email' => !empty($temp['director_pedagogico_email']) ? $temp['director_pedagogico_email'] : null,
                ':secretario' => !empty($temp['secretario']) ? $temp['secretario'] : null,
                ':secretario_contato' => !empty($temp['secretario_contato']) ? $temp['secretario_contato'] : null,
                ':secretario_email' => !empty($temp['secretario_email']) ? $temp['secretario_email'] : null,
                ':trial_ate' => $data_trial
            ]);
            
            $escola_id = $conn->lastInsertId();
            
            // Criar assinatura
            $stmt = $conn->prepare("
                INSERT INTO assinaturas (escola_id, plano_id, tipo_cobranca, valor, data_inicio, data_fim, status, created_at)
                VALUES (:escola_id, :plano_id, :tipo_cobranca, :valor, :data_inicio, :data_fim, 'pendente', NOW())
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':plano_id' => $temp['plano_id'],
                ':tipo_cobranca' => $temp['tipo_cobranca'],
                ':valor' => $valor,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim
            ]);
            
            // Criar usuário admin da escola
            $responsavel_nome = $_POST['responsavel_nome'] ?? '';
            $responsavel_email = $_POST['responsavel_email'] ?? '';
            $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
            $senha_temp = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
            $senha_hash = password_hash($senha_temp, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO usuarios (escola_id, nome, email, senha, tipo, status, created_at)
                VALUES (:escola_id, :nome, :email, :senha, 'admin_escola', 'ativo', NOW())
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $responsavel_nome,
                ':email' => $responsavel_email,
                ':senha' => $senha_hash
            ]);
            
            $conn->commit();
            unset($_SESSION['escola_temp']);
            
            echo json_encode([
                'success' => true, 
                'message' => "Escola cadastrada com sucesso!<br>Subdomínio: <strong>{$temp['subdominio']}.sige.ao</strong><br>Senha temporária: <strong>{$senha_temp}</strong>",
                'redirect' => 'index.php'
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
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
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .form-container { max-width: 900px; margin: 30px auto; }
        .card { border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: none; }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 20px 20px 0 0; padding: 20px 25px; }
        .btn-primary { background: #006B3E; border: none; padding: 12px 25px; border-radius: 10px; }
        .btn-primary:hover { background: #004d2d; transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; border: none; border-radius: 10px; }
        .progress { height: 8px; border-radius: 10px; background: #e9ecef; }
        .progress-bar { background: linear-gradient(90deg, #006B3E, #1A2A6C); border-radius: 10px; transition: width 0.3s ease; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 30px; position: relative; }
        .step-indicator:before { content: ''; position: absolute; top: 20px; left: 0; right: 0; height: 2px; background: #e9ecef; z-index: 1; }
        .step { text-align: center; position: relative; z-index: 2; background: white; width: 40px; height: 40px; line-height: 40px; border-radius: 50%; background: #e9ecef; color: #6c757d; font-weight: bold; margin: 0 auto; }
        .step.active { background: #006B3E; color: white; box-shadow: 0 0 0 3px rgba(0,107,62,0.2); }
        .step.completed { background: #28a745; color: white; }
        .step-label { font-size: 12px; margin-top: 8px; color: #6c757d; }
        .step.active .step-label { color: #006B3E; font-weight: bold; }
        .wizard-step { display: none; animation: fadeIn 0.3s ease; }
        .wizard-step.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        .required:after { content: "*"; color: #dc3545; margin-left: 5px; }
        .form-control, .form-select { border-radius: 10px; padding: 10px 15px; border: 1px solid #ddd; }
        .form-control:focus, .form-select:focus { border-color: #006B3E; box-shadow: 0 0 0 3px rgba(0,107,62,0.1); }
        .logo-preview { width: 120px; height: 120px; border-radius: 15px; object-fit: cover; border: 3px solid #006B3E; background: #f8f9fa; }
        .sugestao-item { cursor: pointer; padding: 5px 12px; margin: 3px; background: #e9ecef; border-radius: 20px; display: inline-block; font-size: 12px; transition: all 0.2s; }
        .sugestao-item:hover { background: #006B3E; color: white; }
        .valid-icon { color: #28a745; font-size: 18px; }
        .invalid-icon { color: #dc3545; font-size: 18px; }
        .loading-icon { color: #ffc107; font-size: 18px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .status-badge { font-size: 12px; padding: 3px 10px; border-radius: 20px; display: inline-block; margin-top: 5px; }
        .status-ok { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-warning { background: #fff3cd; color: #856404; }
        .nav-buttons { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; }
        .percentage-text { font-size: 14px; font-weight: bold; color: #006B3E; }
        .info-box { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; border-left: 4px solid #006B3E; }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <a href="index.php" class="btn btn-link mb-3"><i class="fas fa-arrow-left"></i> Voltar</a>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-school"></i> Cadastrar Nova Escola</h3>
                    <p class="mb-0 mt-1 opacity-75">Preencha os dados nos passos abaixo</p>
                </div>
                <div class="card-body p-4">
                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Progresso do cadastro</span>
                            <span class="percentage-text" id="percentageText">0%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <!-- Step Indicators -->
                    <div class="step-indicator">
                        <div class="step-wrapper text-center" style="flex: 1;">
                            <div class="step" id="step1Indicator">1</div>
                            <div class="step-label">Dados da Escola</div>
                        </div>
                        <div class="step-wrapper text-center" style="flex: 1;">
                            <div class="step" id="step2Indicator">2</div>
                            <div class="step-label">Endereço</div>
                        </div>
                        <div class="step-wrapper text-center" style="flex: 1;">
                            <div class="step" id="step3Indicator">3</div>
                            <div class="step-label">Direção e Logo</div>
                        </div>
                        <div class="step-wrapper text-center" style="flex: 1;">
                            <div class="step" id="step4Indicator">4</div>
                            <div class="step-label">Responsável</div>
                        </div>
                    </div>
                    
                    <div id="messageArea"></div>
                    
                    <!-- Step 1: Dados da Escola -->
                    <div class="wizard-step" id="step1">
                        <div class="info-box">
                            <i class="fas fa-info-circle text-success"></i> 
                            Informações básicas da instituição de ensino
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="required">Nome da Escola</label>
                                    <input type="text" id="nome_escola" class="form-control" placeholder="Ex: Escola Primária do Futuro">
                                    <div class="invalid-feedback" id="error_nome"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Ano de Fundação</label>
                                    <input type="number" id="ano_fundacao" class="form-control" placeholder="Ex: 2000" min="1900" max="2026">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Subdomínio</label>
                                    <div class="input-group">
                                        <input type="text" id="subdominio" class="form-control" placeholder="exemplo">
                                        <span class="input-group-text">.sige.ao</span>
                                    </div>
                                    <div id="sugestoesSubdominio" class="mt-2"></div>
                                    <div id="statusSubdominio"></div>
                                    <small class="text-muted">Usado para acesso: escola.sige.ao</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Domínio Personalizado</label>
                                    <input type="text" id="dominio_personalizado" class="form-control" placeholder="exemplo.com">
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail</label>
                                    <input type="email" id="email" class="form-control" placeholder="contato@escola.com">
                                    <div class="invalid-feedback" id="error_email"></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Telefone Fixo</label>
                                    <input type="text" id="telefone" class="form-control" placeholder="222 xxx xxx">
                                    <small class="text-muted">Ex: 222 123 456</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Celular</label>
                                    <input type="text" id="celular" class="form-control" placeholder="923 xxx xxx">
                                    <div id="statusCelular"></div>
                                    <small class="text-muted">9 dígitos (91-99)</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>NUIT (NIF)</label>
                                    <div class="input-group">
                                        <input type="text" id="nuit" class="form-control" placeholder="14 dígitos">
                                        <button type="button" id="verificarNuitBtn" class="btn btn-outline-secondary">Verificar</button>
                                    </div>
                                    <div id="statusNuit"></div>
                                    <small class="text-muted">Número de Identificação Tributária</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Plano</label>
                                    <select id="plano_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($planos as $plano): ?>
                                        <option value="<?php echo $plano['id']; ?>">
                                            <?php echo $plano['nome']; ?> - KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback" id="error_plano_id"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Tipo de Cobrança</label>
                                    <select id="tipo_cobranca" class="form-select">
                                        <option value="mensal">Mensal</option>
                                        <option value="anual">Anual (10% desconto)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Período Trial (dias)</label>
                                    <input type="number" id="trial_dias" class="form-control" value="30">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Endereço -->
                    <div class="wizard-step" id="step2">
                        <div class="info-box">
                            <i class="fas fa-map-marker-alt text-success"></i> 
                            Localização da escola em Angola
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="required">Província</label>
                                    <select id="provincia_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($provincias as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback" id="error_provincia_id"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="required">Município</label>
                                    <select id="municipio_id" class="form-select" disabled>
                                        <option value="">Primeiro selecione a província</option>
                                    </select>
                                    <div class="invalid-feedback" id="error_municipio_id"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Comuna</label>
                                    <select id="comuna_id" class="form-select" disabled>
                                        <option value="">Primeiro selecione o município</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Endereço Completo</label>
                            <textarea id="endereco" class="form-control" rows="3" placeholder="Rua, Bairro, Nº, Referências"></textarea>
                        </div>
                    </div>
                    
                    <!-- Step 3: Direção e Logo -->
                    <div class="wizard-step" id="step3">
                        <div class="info-box">
                            <i class="fas fa-users"></i> 
                            Informações da Direção e Identificação Visual
                        </div>
                        
                        <h5 class="mb-3">Diretor da Escola</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Diretor</label>
                                    <input type="text" id="director" class="form-control" placeholder="Nome completo do Diretor">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Diretor</label>
                                    <input type="text" id="director_contato" class="form-control" placeholder="923 xxx xxx">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Diretor</label>
                                    <input type="email" id="director_email" class="form-control" placeholder="diretor@escola.com">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3 mt-3">Diretor Pedagógico</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Diretor Pedagógico</label>
                                    <input type="text" id="director_pedagogico" class="form-control" placeholder="Nome completo do Diretor Pedagógico">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Diretor Pedagógico</label>
                                    <input type="text" id="director_pedagogico_contato" class="form-control" placeholder="923 xxx xxx">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Diretor Pedagógico</label>
                                    <input type="email" id="director_pedagogico_email" class="form-control" placeholder="pedagogico@escola.com">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3 mt-3">Secretário</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Secretário</label>
                                    <input type="text" id="secretario" class="form-control" placeholder="Nome completo do Secretário">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Secretário</label>
                                    <input type="text" id="secretario_contato" class="form-control" placeholder="923 xxx xxx">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Secretário</label>
                                    <input type="email" id="secretario_email" class="form-control" placeholder="secretaria@escola.com">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3 mt-3">Logo da Escola</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Logo</label>
                                    <input type="file" id="logoInput" class="form-control" accept="image/*">
                                    <small class="text-muted">Formatos: JPG, PNG, GIF, WEBP, SVG (Max: 2MB)</small>
                                </div>
                            </div>
                            <div class="col-md-6 text-center">
                                <img id="logoPreview" src="../../assets/images/no-logo.png" class="logo-preview">
                                <p class="text-muted small mt-2">Pré-visualização</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 4: Responsável -->
                    <div class="wizard-step" id="step4">
                        <div class="info-box">
                            <i class="fas fa-user-tie text-success"></i> 
                            Dados do Responsável Legal pela escola
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Nome do Responsável</label>
                                    <input type="text" id="responsavel_nome" class="form-control">
                                    <div class="invalid-feedback" id="error_responsavel_nome"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail do Responsável</label>
                                    <input type="email" id="responsavel_email" class="form-control">
                                    <div class="invalid-feedback" id="error_responsavel_email"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Telefone do Responsável</label>
                                    <input type="text" id="responsavel_telefone" class="form-control" placeholder="923 xxx xxx">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            Após o cadastro, um e-mail será enviado com as instruções de acesso e senha temporária.
                        </div>
                    </div>
                    
                    <!-- Botões de Navegação -->
                    <div class="nav-buttons">
                        <button type="button" class="btn btn-secondary" id="prevBtn" onclick="changeStep(-1)" style="display: none;">
                            <i class="fas fa-arrow-left"></i> Anterior
                        </button>
                        <button type="button" class="btn btn-primary" id="nextBtn" onclick="changeStep(1)">
                            Próximo <i class="fas fa-arrow-right"></i>
                        </button>
                        <button type="button" class="btn btn-success" id="submitBtn" onclick="submitForm()" style="display: none;">
                            <i class="fas fa-save"></i> Finalizar Cadastro
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentStep = 1;
        const totalSteps = 4;
        let validating = false;
        
        // Atualizar progresso
        function updateProgress() {
            const percent = (currentStep / totalSteps) * 100;
            $('#progressBar').css('width', percent + '%');
            $('#percentageText').text(Math.round(percent) + '%');
            
            for (let i = 1; i <= totalSteps; i++) {
                if (i < currentStep) {
                    $('#step' + i + 'Indicator').removeClass('active').addClass('completed');
                } else if (i === currentStep) {
                    $('#step' + i + 'Indicator').removeClass('completed').addClass('active');
                } else {
                    $('#step' + i + 'Indicator').removeClass('active completed');
                }
            }
            
            $('#prevBtn').toggle(currentStep > 1);
            $('#nextBtn').toggle(currentStep < totalSteps);
            $('#submitBtn').toggle(currentStep === totalSteps);
        }
        
        // Mudar de passo
        function changeStep(direction) {
            if (direction === 1) {
                validateCurrentStep();
            } else {
                currentStep += direction;
                showStep(currentStep);
                updateProgress();
            }
        }
        
        // Validar passo atual
        function validateCurrentStep() {
            if (currentStep === 1) {
                validateStep1();
            } else if (currentStep === 2) {
                validateStep2();
            } else if (currentStep === 3) {
                validateStep3();
            }
        }
        
        function showStep(step) {
            $('.wizard-step').removeClass('active');
            $('#step' + step).addClass('active');
        }
        
        // Validação Step 1
        function validateStep1() {
            let isValid = true;
            const nome = $('#nome_escola').val().trim();
            const subdominio = $('#subdominio').val().trim();
            const email = $('#email').val().trim();
            const planoId = $('#plano_id').val();
            const celular = $('#celular').val().replace(/[^0-9]/g, '');
            
            $('#error_nome').hide();
            $('#error_email').hide();
            $('#error_plano_id').hide();
            
            if (!nome) {
                $('#error_nome').text('Nome da escola é obrigatório').show();
                isValid = false;
            }
            
            if (!subdominio) {
                $('#statusSubdominio').html('<span class="status-badge status-error">Subdomínio é obrigatório</span>');
                isValid = false;
            }
            
            if (!email) {
                $('#error_email').text('E-mail é obrigatório').show();
                isValid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                $('#error_email').text('E-mail inválido').show();
                isValid = false;
            }
            
            if (!planoId) {
                $('#error_plano_id').text('Plano é obrigatório').show();
                isValid = false;
            }
            
            if (celular && celular.length !== 9) {
                $('#statusCelular').html('<span class="status-badge status-error">Celular deve ter 9 dígitos</span>');
                isValid = false;
            } else if (celular && !['91','92','93','94','95','96','97','98','99'].includes(celular.substr(0,2))) {
                $('#statusCelular').html('<span class="status-badge status-error">Prefixos válidos: 91-99</span>');
                isValid = false;
            }
            
            if (isValid) {
                const formData = new FormData();
                formData.append('step', 1);
                formData.append('nome', nome);
                formData.append('subdominio', subdominio);
                formData.append('dominio_personalizado', $('#dominio_personalizado').val());
                formData.append('email', email);
                formData.append('telefone', $('#telefone').val().replace(/[^0-9]/g, ''));
                formData.append('celular', celular);
                formData.append('nuit', $('#nuit').val().replace(/[^0-9]/g, ''));
                formData.append('ano_fundacao', $('#ano_fundacao').val());
                formData.append('plano_id', planoId);
                formData.append('tipo_cobranca', $('#tipo_cobranca').val());
                formData.append('trial_dias', $('#trial_dias').val());
                
                $('#nextBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Validando...');
                
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            currentStep++;
                            showStep(currentStep);
                            updateProgress();
                        } else {
                            if (response.errors) {
                                for (let field in response.errors) {
                                    if (field === 'celular') $('#statusCelular').html('<span class="status-badge status-error">' + response.errors[field] + '</span>');
                                    else if (field === 'subdominio') $('#statusSubdominio').html('<span class="status-badge status-error">' + response.errors[field] + '</span>');
                                    else $('#' + field).addClass('is-invalid');
                                }
                            }
                        }
                        $('#nextBtn').prop('disabled', false).html('Próximo <i class="fas fa-arrow-right"></i>');
                    },
                    error: function() {
                        $('#messageArea').html('<div class="alert alert-danger">Erro ao validar dados</div>');
                        $('#nextBtn').prop('disabled', false).html('Próximo <i class="fas fa-arrow-right"></i>');
                    }
                });
            }
        }
        
        // Validação Step 2
        function validateStep2() {
            const provinciaId = $('#provincia_id').val();
            const municipioId = $('#municipio_id').val();
            let isValid = true;
            
            $('#error_provincia_id').hide();
            $('#error_municipio_id').hide();
            
            if (!provinciaId) {
                $('#error_provincia_id').text('Selecione a província').show();
                isValid = false;
            }
            
            if (!municipioId) {
                $('#error_municipio_id').text('Selecione o município').show();
                isValid = false;
            }
            
            if (isValid) {
                const formData = new FormData();
                formData.append('step', 2);
                formData.append('provincia_id', provinciaId);
                formData.append('municipio_id', municipioId);
                formData.append('comuna_id', $('#comuna_id').val());
                formData.append('endereco', $('#endereco').val());
                
                $('#nextBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Validando...');
                
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            currentStep++;
                            showStep(currentStep);
                            updateProgress();
                        } else {
                            if (response.errors) {
                                for (let field in response.errors) {
                                    $('#error_' + field).text(response.errors[field]).show();
                                }
                            }
                        }
                        $('#nextBtn').prop('disabled', false).html('Próximo <i class="fas fa-arrow-right"></i>');
                    }
                });
            }
        }
        
        // Validação Step 3
        function validateStep3() {
            const formData = new FormData();
            formData.append('step', 3);
            formData.append('director', $('#director').val());
            formData.append('director_contato', $('#director_contato').val().replace(/[^0-9]/g, ''));
            formData.append('director_email', $('#director_email').val());
            formData.append('director_pedagogico', $('#director_pedagogico').val());
            formData.append('director_pedagogico_contato', $('#director_pedagogico_contato').val().replace(/[^0-9]/g, ''));
            formData.append('director_pedagogico_email', $('#director_pedagogico_email').val());
            formData.append('secretario', $('#secretario').val());
            formData.append('secretario_contato', $('#secretario_contato').val().replace(/[^0-9]/g, ''));
            formData.append('secretario_email', $('#secretario_email').val());
            
            const fileInput = document.getElementById('logoInput');
            if (fileInput.files[0]) {
                formData.append('logo', fileInput.files[0]);
            }
            
            $('#nextBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
            
            $.ajax({
                url: 'cadastrar.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        currentStep++;
                        showStep(currentStep);
                        updateProgress();
                    } else {
                        $('#messageArea').html('<div class="alert alert-danger">' + (response.message || 'Erro ao salvar') + '</div>');
                    }
                    $('#nextBtn').prop('disabled', false).html('Próximo <i class="fas fa-arrow-right"></i>');
                }
            });
        }
        
        // Submissão final
        function submitForm() {
            const responsavelNome = $('#responsavel_nome').val().trim();
            const responsavelEmail = $('#responsavel_email').val().trim();
            let isValid = true;
            
            $('#error_responsavel_nome').hide();
            $('#error_responsavel_email').hide();
            
            if (!responsavelNome) {
                $('#error_responsavel_nome').text('Nome do responsável é obrigatório').show();
                isValid = false;
            }
            
            if (!responsavelEmail) {
                $('#error_responsavel_email').text('E-mail do responsável é obrigatório').show();
                isValid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(responsavelEmail)) {
                $('#error_responsavel_email').text('E-mail inválido').show();
                isValid = false;
            }
            
            if (isValid) {
                const formData = new FormData();
                formData.append('step', 4);
                formData.append('responsavel_nome', responsavelNome);
                formData.append('responsavel_email', responsavelEmail);
                formData.append('responsavel_telefone', $('#responsavel_telefone').val().replace(/[^0-9]/g, ''));
                
                $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Cadastrando...');
                
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#messageArea').html('<div class="alert alert-success">' + response.message + '</div>');
                            setTimeout(function() {
                                window.location.href = response.redirect;
                            }, 3000);
                        } else {
                            $('#messageArea').html('<div class="alert alert-danger">' + (response.message || 'Erro ao cadastrar') + '</div>');
                            $('#submitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Finalizar Cadastro');
                        }
                    },
                    error: function() {
                        $('#messageArea').html('<div class="alert alert-danger">Erro ao cadastrar escola</div>');
                        $('#submitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Finalizar Cadastro');
                    }
                });
            }
        }
        
        // Eventos de validação em tempo real
        function verificarSubdominio() {
            const subdominio = $('#subdominio').val().toLowerCase();
            if (subdominio.length < 3) {
                $('#statusSubdominio').html('<span class="status-badge status-warning">Mínimo 3 caracteres</span>');
                return;
            }
            
            $('#statusSubdominio').html('<span class="status-badge status-warning"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>');
            
            $.ajax({
                url: 'cadastrar.php?ajax=verificar_subdominio&subdominio=' + encodeURIComponent(subdominio),
                method: 'GET',
                success: function(data) {
                    if (data.valid) {
                        $('#statusSubdominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + data.message + '</span>');
                    } else {
                        $('#statusSubdominio').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
                    }
                }
            });
        }
        
        function gerarSugestoes() {
            const nome = $('#nome_escola').val();
            if (nome.length >= 3) {
                $.ajax({
                    url: 'cadastrar.php?ajax=gerar_sugestoes&nome=' + encodeURIComponent(nome),
                    method: 'GET',
                    success: function(data) {
                        let html = '<div class="small text-muted mb-1">Sugestões:</div>';
                        data.forEach(function(sug) {
                            html += '<span class="sugestao-item" onclick="$(\'#subdominio\').val(\'' + sug + '\'); verificarSubdominio();">' + sug + '</span>';
                        });
                        $('#sugestoesSubdominio').html(html);
                    }
                });
            }
        }
        
        function validarCelular() {
            const celular = $('#celular').val().replace(/[^0-9]/g, '');
            if (celular.length === 0) {
                $('#statusCelular').html('');
                return;
            }
            
            if (celular.length === 9) {
                const prefixos = ['91', '92', '93', '94', '95', '96', '97', '98', '99'];
                if (prefixos.includes(celular.substr(0, 2))) {
                    $('#statusCelular').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Celular válido</span>');
                } else {
                    $('#statusCelular').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> Prefixo inválido. Use 91-99</span>');
                }
            } else if (celular.length > 0 && celular.length !== 9) {
                $('#statusCelular').html('<span class="status-badge status-error">Celular deve ter 9 dígitos</span>');
            }
        }
        
        function verificarNuit() {
            const nuit = $('#nuit').val().replace(/[^0-9]/g, '');
            if (nuit.length === 0) {
                $('#statusNuit').html('');
                return;
            }
            
            if (nuit.length !== 14) {
                $('#statusNuit').html('<span class="status-badge status-error">NUIT deve ter 14 dígitos</span>');
                return;
            }
            
            $('#statusNuit').html('<span class="status-badge status-warning"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>');
            
            $.ajax({
                url: 'cadastrar.php?ajax=verificar_nuit&nuit=' + nuit,
                method: 'GET',
                success: function(data) {
                    if (data.valid) {
                        $('#statusNuit').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + data.message + '</span>');
                    } else {
                        $('#statusNuit').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
                    }
                }
            });
        }
        
        function formatarCelularInput() {
            let valor = $('#celular').val().replace(/[^0-9]/g, '');
            if (valor.length > 9) valor = valor.substr(0, 9);
            if (valor.length >= 4 && valor.length <= 6) {
                valor = valor.substr(0, 3) + ' ' + valor.substr(3);
            } else if (valor.length >= 7) {
                valor = valor.substr(0, 3) + ' ' + valor.substr(3, 3) + ' ' + valor.substr(6);
            }
            $('#celular').val(valor);
        }
        
        // Carregar municípios
        $('#provincia_id').change(function() {
            const provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({
                    url: 'cadastrar.php?ajax=get_municipios&provincia_id=' + provinciaId,
                    method: 'GET',
                    success: function(data) {
                        let options = '<option value="">Selecione...</option>';
                        for (let i = 0; i < data.length; i++) {
                            options += '<option value="' + data[i].id + '">' + data[i].nome + '</option>';
                        }
                        $('#municipio_id').html(options).prop('disabled', false);
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
                    url: 'cadastrar.php?ajax=get_comunas&municipio_id=' + municipioId,
                    method: 'GET',
                    success: function(data) {
                        let options = '<option value="">Selecione...</option>';
                        for (let i = 0; i < data.length; i++) {
                            options += '<option value="' + data[i].id + '">' + data[i].nome + '</option>';
                        }
                        $('#comuna_id').html(options).prop('disabled', false);
                    }
                });
            } else {
                $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Preview da logo
        $('#logoInput').change(function() {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#logoPreview').attr('src', e.target.result);
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Event listeners
        $('#nome_escola').on('input', function() { setTimeout(gerarSugestoes, 500); });
        $('#subdominio').on('input', verificarSubdominio);
        $('#celular').on('input', function() { formatarCelularInput(); validarCelular(); });
        $('#verificarNuitBtn').click(verificarNuit);
        $('#nuit').on('input', function() { if ($(this).val().replace(/[^0-9]/g, '').length === 14) verificarNuit(); });
        
        // Inicializar
        showStep(1);
        updateProgress();
    </script>
</body>
</html>