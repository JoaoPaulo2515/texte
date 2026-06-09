<?php
// super-admin/escolas/editar.php - Editar Escola (Wizard)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar dados da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :id");
$stmt->execute([':id' => $id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    header('Location: index.php?error=Escola não encontrada');
    exit;
}

// Buscar planos ativos
$planos = $conn->query("SELECT * FROM planos WHERE status = 'ativo' ORDER BY preco_mensal ASC")->fetchAll(PDO::FETCH_ASSOC);

// Buscar províncias para combobox
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Buscar municípios e comunas para pré-seleção
$municipios = [];
$comunas = [];
$provincia_id_atual = 0;
$municipio_id_atual = 0;

if ($escola['provincia']) {
    $stmt = $conn->prepare("SELECT id FROM angola_provincias WHERE nome = :nome");
    $stmt->execute([':nome' => $escola['provincia']]);
    $provincia = $stmt->fetch(PDO::FETCH_ASSOC);
    $provincia_id_atual = $provincia['id'] ?? 0;
    
    if ($provincia_id_atual) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $provincia_id_atual]);
        $municipios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($escola['municipio']) {
            $stmt = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = :nome AND provincia_id = :provincia_id");
            $stmt->execute([':nome' => $escola['municipio'], ':provincia_id' => $provincia_id_atual]);
            $municipio = $stmt->fetch(PDO::FETCH_ASSOC);
            $municipio_id_atual = $municipio['id'] ?? 0;
            
            if ($municipio_id_atual) {
                $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
                $stmt->execute([':municipio_id' => $municipio_id_atual]);
                $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

$error = '';
$success = '';
$current_step = $_GET['step'] ?? 1;

// Funções de validação
function validarCelularAngolaEdit($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) != 9) return false;
    $prefixos = ['91', '92', '93', '94', '95', '96', '97', '98', '99'];
    $prefixo = substr($telefone, 0, 2);
    return in_array($prefixo, $prefixos);
}

function formatarTelefoneEdit($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

function formatarNUITEdit($nuit) {
    if (empty($nuit)) return '';
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    if (strlen($nuit) == 14) {
        return substr($nuit, 0, 3) . '.' . substr($nuit, 3, 3) . '.' . substr($nuit, 6, 3) . '.' . substr($nuit, 9, 5);
    }
    return $nuit;
}

function criarThumbnailEdit($source, $destination, $size) {
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
        $response = ['valid' => false, 'message' => ''];
        
        if (empty($subdominio)) {
            $response['message'] = 'Digite um subdomínio';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $subdominio)) {
            $response['message'] = 'Apenas letras minúsculas, números e hífen';
        } elseif (strlen($subdominio) < 3) {
            $response['message'] = 'Mínimo 3 caracteres';
        } else {
            $stmt = $conn->prepare("SELECT id FROM escolas WHERE subdominio = :subdominio AND id != :id");
            $stmt->execute([':subdominio' => $subdominio, ':id' => $id]);
            if ($stmt->fetch()) {
                $response['message'] = 'Subdomínio já está em uso';
            } else {
                $response['valid'] = true;
                $response['message'] = 'Subdomínio disponível';
            }
        }
        echo json_encode($response);
        exit;
    }
    
    if ($ajax == 'verificar_dominio') {
        $dominio = $_GET['dominio'] ?? '';
        $response = ['valid' => false, 'message' => ''];
        
        if (empty($dominio)) {
            $response['message'] = '';
        } elseif (!preg_match('/^[a-z0-9.-]+$/', $dominio)) {
            $response['message'] = 'Domínio inválido';
        } elseif (!preg_match('/\.[a-z]{2,}$/', $dominio)) {
            $response['message'] = 'Domínio deve ter extensão (ex: .com, .ao)';
        } else {
            $stmt = $conn->prepare("SELECT id FROM escolas WHERE dominio_personalizado = :dominio AND id != :id");
            $stmt->execute([':dominio' => $dominio, ':id' => $id]);
            if ($stmt->fetch()) {
                $response['message'] = 'Domínio já está em uso';
            } else {
                $response['valid'] = true;
                $response['message'] = 'Domínio disponível';
            }
        }
        echo json_encode($response);
        exit;
    }
    
    if ($ajax == 'verificar_nuit') {
        $nuit = $_GET['nuit'] ?? '';
        $response = ['valid' => false, 'message' => ''];
        
        if (empty($nuit)) {
            $response['message'] = 'Digite o NUIT';
        } else {
            $nuit_limpo = preg_replace('/[^0-9]/', '', $nuit);
            if (strlen($nuit_limpo) != 14) {
                $response['message'] = 'NUIT deve ter 14 dígitos';
            } else {
                $stmt = $conn->prepare("SELECT id, nome FROM escolas WHERE nuit = :nuit AND id != :id");
                $stmt->execute([':nuit' => $nuit_limpo, ':id' => $id]);
                if ($stmt->fetch()) {
                    $response['message'] = 'NUIT já cadastrado para outra escola';
                } else {
                    $response['valid'] = true;
                    $response['message'] = 'NUIT válido';
                }
            }
        }
        echo json_encode($response);
        exit;
    }
    
    exit;
}

// Processar edição
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    $step = (int)$_POST['step'];
    $errors = [];
    
    if ($step == 1) {
        if (empty($_POST['nome'])) $errors['nome'] = 'Nome da escola é obrigatório';
        if (empty($_POST['subdominio'])) $errors['subdominio'] = 'Subdomínio é obrigatório';
        if (empty($_POST['email'])) $errors['email'] = 'E-mail é obrigatório';
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail inválido';
        if (!empty($_POST['celular']) && !validarCelularAngolaEdit($_POST['celular'])) $errors['celular'] = 'Celular inválido. Use 9 dígitos (ex: 923 456 789)';
        
        if (empty($errors)) {
            $_SESSION['escola_edit_temp'] = [
                'nome' => $_POST['nome'],
                'subdominio' => $_POST['subdominio'],
                'dominio_personalizado' => $_POST['dominio_personalizado'] ?? '',
                'email' => $_POST['email'],
                'telefone' => $_POST['telefone'] ?? '',
                'celular' => $_POST['celular'] ?? '',
                'nuit' => $_POST['nuit'] ?? '',
                'ano_fundacao' => $_POST['ano_fundacao'] ?? '',
                'plano_id' => $_POST['plano_id'] ?? '',
                'trial_ate' => $_POST['trial_ate'] ?? '',
                'status' => $_POST['status'] ?? ''
            ];
            echo json_encode(['success' => true, 'next_step' => 2]);
        } else {
            echo json_encode(['success' => false, 'errors' => $errors]);
        }
        exit;
    }
    
    if ($step == 2) {
        if (empty($errors)) {
            $_SESSION['escola_edit_temp']['provincia_id'] = $_POST['provincia_id'] ?? '';
            $_SESSION['escola_edit_temp']['municipio_id'] = $_POST['municipio_id'] ?? '';
            $_SESSION['escola_edit_temp']['comuna_id'] = $_POST['comuna_id'] ?? '';
            $_SESSION['escola_edit_temp']['endereco'] = $_POST['endereco'] ?? '';
            echo json_encode(['success' => true, 'next_step' => 3]);
        } else {
            echo json_encode(['success' => false, 'errors' => $errors]);
        }
        exit;
    }
    
    if ($step == 3) {
        $_SESSION['escola_edit_temp']['director'] = $_POST['director'] ?? '';
        $_SESSION['escola_edit_temp']['director_contato'] = $_POST['director_contato'] ?? '';
        $_SESSION['escola_edit_temp']['director_email'] = $_POST['director_email'] ?? '';
        $_SESSION['escola_edit_temp']['director_pedagogico'] = $_POST['director_pedagogico'] ?? '';
        $_SESSION['escola_edit_temp']['director_pedagogico_contato'] = $_POST['director_pedagogico_contato'] ?? '';
        $_SESSION['escola_edit_temp']['director_pedagogico_email'] = $_POST['director_pedagogico_email'] ?? '';
        $_SESSION['escola_edit_temp']['secretario'] = $_POST['secretario'] ?? '';
        $_SESSION['escola_edit_temp']['secretario_contato'] = $_POST['secretario_contato'] ?? '';
        $_SESSION['escola_edit_temp']['secretario_email'] = $_POST['secretario_email'] ?? '';
        
        // Upload da logo
        $logo = $escola['logo'];
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $upload_dir = __DIR__ . '/../../uploads/escolas/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            
            if (in_array($ext, $allowed)) {
                $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo)) {
                    if (extension_loaded('gd')) {
                        criarThumbnailEdit($upload_dir . $logo, $upload_dir . 'thumb_' . $logo, 100);
                    }
                    // Remover logo antiga
                    if ($escola['logo'] && file_exists($upload_dir . $escola['logo'])) {
                        unlink($upload_dir . $escola['logo']);
                        if (file_exists($upload_dir . 'thumb_' . $escola['logo'])) {
                            unlink($upload_dir . 'thumb_' . $escola['logo']);
                        }
                    }
                }
            }
        }
        $_SESSION['escola_edit_temp']['logo'] = $logo;
        
        echo json_encode(['success' => true, 'next_step' => 4]);
        exit;
    }
    
    if ($step == 4) {
        $temp = $_SESSION['escola_edit_temp'];
        
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
        
        try {
            $stmt = $conn->prepare("
                UPDATE escolas SET
                    nome = :nome,
                    subdominio = :subdominio,
                    dominio_personalizado = :dominio_personalizado,
                    plano_id = :plano_id,
                    email = :email,
                    telefone = :telefone,
                    celular = :celular,
                    endereco = :endereco,
                    provincia = :provincia,
                    municipio = :municipio,
                    comuna = :comuna,
                    logo = :logo,
                    nuit = :nuit,
                    ano_fundacao = :ano_fundacao,
                    director = :director,
                    director_contato = :director_contato,
                    director_email = :director_email,
                    director_pedagogico = :director_pedagogico,
                    director_pedagogico_contato = :director_pedagogico_contato,
                    director_pedagogico_email = :director_pedagogico_email,
                    secretario = :secretario,
                    secretario_contato = :secretario_contato,
                    secretario_email = :secretario_email,
                    responsavel_nome = :responsavel_nome,
                    responsavel_email = :responsavel_email,
                    responsavel_telefone = :responsavel_telefone,
                    status = :status,
                    trial_ate = :trial_ate,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':nome' => $temp['nome'],
                ':subdominio' => strtolower($temp['subdominio']),
                ':dominio_personalizado' => !empty($temp['dominio_personalizado']) ? strtolower($temp['dominio_personalizado']) : null,
                ':plano_id' => $temp['plano_id'] ?: null,
                ':email' => $temp['email'],
                ':telefone' => !empty($temp['telefone']) ? $temp['telefone'] : null,
                ':celular' => !empty($temp['celular']) ? $temp['celular'] : null,
                ':endereco' => !empty($temp['endereco']) ? $temp['endereco'] : null,
                ':provincia' => $provincia_nome ?: null,
                ':municipio' => $municipio_nome ?: null,
                ':comuna' => $comuna_nome ?: null,
                ':logo' => $temp['logo'] ?: null,
                ':nuit' => !empty($temp['nuit']) ? preg_replace('/[^0-9]/', '', $temp['nuit']) : null,
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
                ':responsavel_nome' => $_POST['responsavel_nome'] ?? null,
                ':responsavel_email' => $_POST['responsavel_email'] ?? null,
                ':responsavel_telefone' => $_POST['responsavel_telefone'] ?? null,
                ':status' => $temp['status'],
                ':trial_ate' => !empty($temp['trial_ate']) ? $temp['trial_ate'] : null
            ]);
            
            unset($_SESSION['escola_edit_temp']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Escola atualizada com sucesso!',
                'redirect' => 'index.php'
            ]);
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'editar_escola', 'escolas', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Recuperar dados temporários ou da escola
$temp = $_SESSION['escola_edit_temp'] ?? [];
$dados = [
    'nome' => $temp['nome'] ?? $escola['nome'],
    'subdominio' => $temp['subdominio'] ?? $escola['subdominio'],
    'dominio_personalizado' => $temp['dominio_personalizado'] ?? $escola['dominio_personalizado'],
    'email' => $temp['email'] ?? $escola['email'],
    'telefone' => $temp['telefone'] ?? formatarTelefoneEdit($escola['telefone']),
    'celular' => $temp['celular'] ?? formatarTelefoneEdit($escola['celular']),
    'nuit' => $temp['nuit'] ?? formatarNUITEdit($escola['nuit']),
    'ano_fundacao' => $temp['ano_fundacao'] ?? $escola['ano_fundacao'],
    'plano_id' => $temp['plano_id'] ?? $escola['plano_id'],
    'trial_ate' => $temp['trial_ate'] ?? $escola['trial_ate'],
    'status' => $temp['status'] ?? $escola['status'],
    'endereco' => $temp['endereco'] ?? $escola['endereco'],
    'provincia_id' => $temp['provincia_id'] ?? $provincia_id_atual,
    'municipio_id' => $temp['municipio_id'] ?? $municipio_id_atual,
    'comuna_id' => $temp['comuna_id'] ?? '',
    'director' => $temp['director'] ?? $escola['director'],
    'director_contato' => $temp['director_contato'] ?? formatarTelefoneEdit($escola['director_contato']),
    'director_email' => $temp['director_email'] ?? $escola['director_email'],
    'director_pedagogico' => $temp['director_pedagogico'] ?? $escola['director_pedagogico'],
    'director_pedagogico_contato' => $temp['director_pedagogico_contato'] ?? formatarTelefoneEdit($escola['director_pedagogico_contato']),
    'director_pedagogico_email' => $temp['director_pedagogico_email'] ?? $escola['director_pedagogico_email'],
    'secretario' => $temp['secretario'] ?? $escola['secretario'],
    'secretario_contato' => $temp['secretario_contato'] ?? formatarTelefoneEdit($escola['secretario_contato']),
    'secretario_email' => $temp['secretario_email'] ?? $escola['secretario_email'],
    'responsavel_nome' => $escola['responsavel_nome'],
    'responsavel_email' => $escola['responsavel_email'],
    'responsavel_telefone' => formatarTelefoneEdit($escola['responsavel_telefone']),
    'logo' => $escola['logo']
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Escola - <?php echo htmlspecialchars($escola['nome']); ?> | SIGE Angola</title>
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
        .logo-preview { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; border: 2px solid #006B3E; background: #f8f9fa; }
        .status-badge { font-size: 12px; padding: 3px 10px; border-radius: 20px; display: inline-block; margin-top: 5px; }
        .status-ok { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-info { background: #d1ecf1; color: #0c5460; }
        .nav-buttons { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; }
        .percentage-text { font-size: 14px; font-weight: bold; color: #006B3E; }
        .info-box { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; border-left: 4px solid #006B3E; }
        .section-title { background: #f8f9fa; padding: 8px 12px; border-radius: 8px; margin: 15px 0 10px; border-left: 3px solid #006B3E; }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <a href="index.php" class="btn btn-link mb-3"><i class="fas fa-arrow-left"></i> Voltar</a>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-edit"></i> Editar Escola: <?php echo htmlspecialchars($escola['nome']); ?></h3>
                    <p class="mb-0 mt-1 opacity-75">Atualize os dados nos passos abaixo</p>
                </div>
                <div class="card-body p-4">
                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Progresso da edição</span>
                            <span class="percentage-text" id="percentageText">25%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" id="progressBar" style="width: 25%"></div>
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
                                    <input type="text" id="nome_escola" class="form-control" value="<?php echo htmlspecialchars($dados['nome']); ?>">
                                    <div class="invalid-feedback" id="error_nome"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Ano de Fundação</label>
                                    <input type="number" id="ano_fundacao" class="form-control" value="<?php echo $dados['ano_fundacao']; ?>" min="1900" max="<?php echo date('Y'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Subdomínio</label>
                                    <div class="input-group">
                                        <input type="text" id="subdominio" class="form-control" value="<?php echo $dados['subdominio']; ?>">
                                        <span class="input-group-text">.sige.ao</span>
                                    </div>
                                    <div id="statusSubdominio"></div>
                                    <small class="text-muted">Usado para acesso: escola.sige.ao</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Domínio Personalizado</label>
                                    <input type="text" id="dominio_personalizado" class="form-control" value="<?php echo htmlspecialchars($dados['dominio_personalizado']); ?>">
                                    <div id="statusDominio"></div>
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail</label>
                                    <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($dados['email']); ?>">
                                    <div class="invalid-feedback" id="error_email"></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Telefone Fixo</label>
                                    <input type="text" id="telefone" class="form-control" value="<?php echo $dados['telefone']; ?>">
                                    <div id="statusTelefone"></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Celular</label>
                                    <input type="text" id="celular" class="form-control" value="<?php echo $dados['celular']; ?>">
                                    <div id="statusCelular"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>NUIT (NIF)</label>
                                    <input type="text" id="nuit" class="form-control" value="<?php echo $dados['nuit']; ?>">
                                    <div id="statusNuit"></div>
                                    <small class="text-muted">Número de Identificação Tributária (14 dígitos)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Status</label>
                                    <select id="status" class="form-select">
                                        <option value="ativa" <?php echo $dados['status'] == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                                        <option value="trial" <?php echo $dados['status'] == 'trial' ? 'selected' : ''; ?>>Trial</option>
                                        <option value="suspensa" <?php echo $dados['status'] == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                                        <option value="inativa" <?php echo $dados['status'] == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Plano</label>
                                    <select id="plano_id" class="form-select">
                                        <option value="">Nenhum</option>
                                        <?php foreach ($planos as $plano): ?>
                                        <option value="<?php echo $plano['id']; ?>" <?php echo $dados['plano_id'] == $plano['id'] ? 'selected' : ''; ?>>
                                            <?php echo $plano['nome']; ?> - KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Trial até</label>
                                    <input type="date" id="trial_ate" class="form-control" value="<?php echo $dados['trial_ate']; ?>">
                                    <small class="text-muted">Data de término do período de teste</small>
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
                                    <label>Província</label>
                                    <select id="provincia_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($provincias as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $dados['provincia_id'] == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Município</label>
                                    <select id="municipio_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($municipios as $m): ?>
                                        <option value="<?php echo $m['id']; ?>" <?php echo $dados['municipio_id'] == $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Comuna</label>
                                    <select id="comuna_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($comunas as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $dados['comuna_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Endereço Completo</label>
                            <textarea id="endereco" class="form-control" rows="3" placeholder="Rua, Bairro, Nº, Referências"><?php echo htmlspecialchars($dados['endereco']); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Step 3: Direção e Logo -->
                    <div class="wizard-step" id="step3">
                        <div class="info-box">
                            <i class="fas fa-users"></i> 
                            Informações da Direção e Identificação Visual
                        </div>
                        
                        <h5 class="section-title">Diretor da Escola</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Diretor</label>
                                    <input type="text" id="director" class="form-control" value="<?php echo htmlspecialchars($dados['director']); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Diretor</label>
                                    <input type="text" id="director_contato" class="form-control" value="<?php echo $dados['director_contato']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Diretor</label>
                                    <input type="email" id="director_email" class="form-control" value="<?php echo htmlspecialchars($dados['director_email']); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="section-title mt-3">Diretor Pedagógico</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Diretor Pedagógico</label>
                                    <input type="text" id="director_pedagogico" class="form-control" value="<?php echo htmlspecialchars($dados['director_pedagogico']); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Diretor Pedagógico</label>
                                    <input type="text" id="director_pedagogico_contato" class="form-control" value="<?php echo $dados['director_pedagogico_contato']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Diretor Pedagógico</label>
                                    <input type="email" id="director_pedagogico_email" class="form-control" value="<?php echo htmlspecialchars($dados['director_pedagogico_email']); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="section-title mt-3">Secretário</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label>Nome do Secretário</label>
                                    <input type="text" id="secretario" class="form-control" value="<?php echo htmlspecialchars($dados['secretario']); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Contato do Secretário</label>
                                    <input type="text" id="secretario_contato" class="form-control" value="<?php echo $dados['secretario_contato']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Secretário</label>
                                    <input type="email" id="secretario_email" class="form-control" value="<?php echo htmlspecialchars($dados['secretario_email']); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="section-title mt-3">Logo da Escola</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Logo</label>
                                    <input type="file" id="logoInput" class="form-control" accept="image/*">
                                    <small class="text-muted">Formatos: JPG, PNG, GIF, WEBP, SVG (Max: 2MB)</small>
                                </div>
                            </div>
                            <div class="col-md-6 text-center">
                                <?php if ($dados['logo']): ?>
                                    <img src="../../uploads/escolas/<?php echo $dados['logo']; ?>" class="logo-preview mb-2" id="logoPreview">
                                    <br>
                                    <small class="text-muted">Logo atual</small>
                                <?php else: ?>
                                    <img src="../../assets/images/no-logo.png" class="logo-preview mb-2" id="logoPreview">
                                    <br>
                                    <small class="text-muted">Nenhuma logo cadastrada</small>
                                <?php endif; ?>
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
                                    <label>Nome do Responsável</label>
                                    <input type="text" id="responsavel_nome" class="form-control" value="<?php echo htmlspecialchars($dados['responsavel_nome']); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Responsável</label>
                                    <input type="email" id="responsavel_email" class="form-control" value="<?php echo htmlspecialchars($dados['responsavel_email']); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Telefone do Responsável</label>
                                    <input type="text" id="responsavel_telefone" class="form-control" value="<?php echo $dados['responsavel_telefone']; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            As alterações serão aplicadas imediatamente após salvar.
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
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = 1;
        const totalSteps = 4;
        let timeoutSubdominio, timeoutDominio, timeoutNuit, timeoutTelefone, timeoutCelular;
        
        // Dados iniciais
        const dadosIniciais = {
            nome: '<?php echo addslashes($dados['nome']); ?>',
            subdominio: '<?php echo addslashes($dados['subdominio']); ?>',
            dominio_personalizado: '<?php echo addslashes($dados['dominio_personalizado']); ?>',
            email: '<?php echo addslashes($dados['email']); ?>',
            telefone: '<?php echo addslashes($dados['telefone']); ?>',
            celular: '<?php echo addslashes($dados['celular']); ?>',
            nuit: '<?php echo addslashes($dados['nuit']); ?>',
            ano_fundacao: '<?php echo $dados['ano_fundacao']; ?>',
            plano_id: '<?php echo $dados['plano_id']; ?>',
            trial_ate: '<?php echo $dados['trial_ate']; ?>',
            status: '<?php echo $dados['status']; ?>',
            provincia_id: '<?php echo $dados['provincia_id']; ?>',
            municipio_id: '<?php echo $dados['municipio_id']; ?>',
            comuna_id: '<?php echo $dados['comuna_id']; ?>',
            endereco: '<?php echo addslashes($dados['endereco']); ?>',
            director: '<?php echo addslashes($dados['director']); ?>',
            director_contato: '<?php echo addslashes($dados['director_contato']); ?>',
            director_email: '<?php echo addslashes($dados['director_email']); ?>',
            director_pedagogico: '<?php echo addslashes($dados['director_pedagogico']); ?>',
            director_pedagogico_contato: '<?php echo addslashes($dados['director_pedagogico_contato']); ?>',
            director_pedagogico_email: '<?php echo addslashes($dados['director_pedagogico_email']); ?>',
            secretario: '<?php echo addslashes($dados['secretario']); ?>',
            secretario_contato: '<?php echo addslashes($dados['secretario_contato']); ?>',
            secretario_email: '<?php echo addslashes($dados['secretario_email']); ?>',
            responsavel_nome: '<?php echo addslashes($dados['responsavel_nome']); ?>',
            responsavel_email: '<?php echo addslashes($dados['responsavel_email']); ?>',
            responsavel_telefone: '<?php echo addslashes($dados['responsavel_telefone']); ?>'
        };
        
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
        
        function changeStep(direction) {
            if (direction === 1) {
                validateCurrentStep();
            } else {
                currentStep += direction;
                showStep(currentStep);
                updateProgress();
            }
        }
        
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
        
        // ============================================
        // VALIDAÇÕES EM TEMPO REAL
        // ============================================
        
        function verificarSubdominioRealtime() {
            clearTimeout(timeoutSubdominio);
            timeoutSubdominio = setTimeout(function() {
                const subdominio = $('#subdominio').val().toLowerCase();
                if (subdominio.length === 0) {
                    $('#statusSubdominio').html('');
                    return;
                }
                
                if (subdominio === dadosIniciais.subdominio) {
                    $('#statusSubdominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Subdomínio atual</span>');
                    return;
                }
                
                $('#statusSubdominio').html('<span class="status-badge status-info"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>');
                
                $.ajax({
                    url: 'editar.php?ajax=verificar_subdominio&subdominio=' + encodeURIComponent(subdominio),
                    method: 'GET',
                    success: function(data) {
                        if (data.valid) {
                            $('#statusSubdominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + data.message + '</span>');
                        } else {
                            $('#statusSubdominio').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
                        }
                    }
                });
            }, 500);
        }
        
        function verificarDominioRealtime() {
            clearTimeout(timeoutDominio);
            timeoutDominio = setTimeout(function() {
                const dominio = $('#dominio_personalizado').val().toLowerCase();
                if (dominio.length === 0) {
                    $('#statusDominio').html('');
                    return;
                }
                
                if (dominio === dadosIniciais.dominio_personalizado) {
                    $('#statusDominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Domínio atual</span>');
                    return;
                }
                
                $('#statusDominio').html('<span class="status-badge status-info"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>');
                
                $.ajax({
                    url: 'editar.php?ajax=verificar_dominio&dominio=' + encodeURIComponent(dominio),
                    method: 'GET',
                    success: function(data) {
                        if (data.valid) {
                            $('#statusDominio').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + data.message + '</span>');
                        } else if (data.message) {
                            $('#statusDominio').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
                        }
                    }
                });
            }, 500);
        }
        
        function verificarNuitRealtime() {
            clearTimeout(timeoutNuit);
            timeoutNuit = setTimeout(function() {
                let nuit = $('#nuit').val().replace(/[^0-9]/g, '');
                if (nuit.length === 0) {
                    $('#statusNuit').html('');
                    return;
                }
                
                if (nuit === dadosIniciais.nuit.replace(/[^0-9]/g, '')) {
                    $('#statusNuit').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> NUIT atual</span>');
                    return;
                }
                
                if (nuit.length !== 14) {
                    $('#statusNuit').html('<span class="status-badge status-error">NUIT deve ter 14 dígitos</span>');
                    return;
                }
                
                $('#statusNuit').html('<span class="status-badge status-info"><i class="fas fa-spinner fa-spin"></i> Verificando NUIT...</span>');
                
                $.ajax({
                    url: 'editar.php?ajax=verificar_nuit&nuit=' + nuit,
                    method: 'GET',
                    success: function(data) {
                        if (data.valid) {
                            $('#statusNuit').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> ' + data.message + '</span>');
                        } else {
                            $('#statusNuit').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
                        }
                    }
                });
            }, 800);
        }
        
        function validarCelularRealtime() {
            clearTimeout(timeoutCelular);
            timeoutCelular = setTimeout(function() {
                let celular = $('#celular').val().replace(/[^0-9]/g, '');
                if (celular.length === 0) {
                    $('#statusCelular').html('');
                    return;
                }
                
                if (celular === dadosIniciais.celular.replace(/[^0-9]/g, '')) {
                    $('#statusCelular').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Celular atual</span>');
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
            }, 500);
        }
        
        function validarTelefoneRealtime() {
            clearTimeout(timeoutTelefone);
            timeoutTelefone = setTimeout(function() {
                let telefone = $('#telefone').val().replace(/[^0-9]/g, '');
                if (telefone.length === 0) {
                    $('#statusTelefone').html('');
                    return;
                }
                
                if (telefone === dadosIniciais.telefone.replace(/[^0-9]/g, '')) {
                    $('#statusTelefone').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Telefone atual</span>');
                    return;
                }
                
                if (telefone.length === 9) {
                    const prefixos = ['22', '23', '24', '25', '26'];
                    if (prefixos.includes(telefone.substr(0, 2))) {
                        $('#statusTelefone').html('<span class="status-badge status-ok"><i class="fas fa-check-circle"></i> Telefone válido</span>');
                    } else {
                        $('#statusTelefone').html('<span class="status-badge status-error"><i class="fas fa-times-circle"></i> Prefixo inválido. Use 22-26</span>');
                    }
                } else if (telefone.length > 0 && telefone.length !== 9) {
                    $('#statusTelefone').html('<span class="status-badge status-error">Telefone deve ter 9 dígitos</span>');
                }
            }, 500);
        }
        
        // Funções de formatação
        function formatarNUIT(valor) {
            let nuit = valor.replace(/[^0-9]/g, '');
            if (nuit.length >= 14) {
                nuit = nuit.substr(0, 3) + '.' + nuit.substr(3, 3) + '.' + nuit.substr(6, 3) + '.' + nuit.substr(9, 5);
            }
            return nuit;
        }
        
        function formatarCelular(valor) {
            let cel = valor.replace(/[^0-9]/g, '');
            if (cel.length > 9) cel = cel.substr(0, 9);
            if (cel.length >= 4 && cel.length <= 6) {
                cel = cel.substr(0, 3) + ' ' + cel.substr(3);
            } else if (cel.length >= 7) {
                cel = cel.substr(0, 3) + ' ' + cel.substr(3, 3) + ' ' + cel.substr(6);
            }
            return cel;
        }
        
        function formatarTelefone(valor) {
            let tel = valor.replace(/[^0-9]/g, '');
            if (tel.length > 9) tel = tel.substr(0, 9);
            if (tel.length >= 4 && tel.length <= 6) {
                tel = tel.substr(0, 3) + ' ' + tel.substr(3);
            } else if (tel.length >= 7) {
                tel = tel.substr(0, 3) + ' ' + tel.substr(3, 3) + ' ' + tel.substr(6);
            }
            return tel;
        }
        
        // ============================================
        // VALIDAÇÕES DOS STEPS
        // ============================================
        
        function validateStep1() {
            let isValid = true;
            const nome = $('#nome_escola').val().trim();
            const subdominio = $('#subdominio').val().trim();
            const email = $('#email').val().trim();
            
            $('#error_nome').hide();
            $('#error_email').hide();
            
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
            
            if (isValid) {
                const formData = new FormData();
                formData.append('step', 1);
                formData.append('nome', nome);
                formData.append('subdominio', subdominio);
                formData.append('dominio_personalizado', $('#dominio_personalizado').val());
                formData.append('email', email);
                formData.append('telefone', $('#telefone').val().replace(/[^0-9]/g, ''));
                formData.append('celular', $('#celular').val().replace(/[^0-9]/g, ''));
                formData.append('nuit', $('#nuit').val().replace(/[^0-9]/g, ''));
                formData.append('ano_fundacao', $('#ano_fundacao').val());
                formData.append('plano_id', $('#plano_id').val());
                formData.append('trial_ate', $('#trial_ate').val());
                formData.append('status', $('#status').val());
                
                $('#nextBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Validando...');
                
                $.ajax({
                    url: 'editar.php',
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
        
        function validateStep2() {
            const formData = new FormData();
            formData.append('step', 2);
            formData.append('provincia_id', $('#provincia_id').val());
            formData.append('municipio_id', $('#municipio_id').val());
            formData.append('comuna_id', $('#comuna_id').val());
            formData.append('endereco', $('#endereco').val());
            
            $('#nextBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Validando...');
            
            $.ajax({
                url: 'editar.php',
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
                        $('#messageArea').html('<div class="alert alert-danger">Erro ao validar endereço</div>');
                    }
                    $('#nextBtn').prop('disabled', false).html('Próximo <i class="fas fa-arrow-right"></i>');
                }
            });
        }
        
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
                url: 'editar.php',
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
        
        function submitForm() {
            const formData = new FormData();
            formData.append('step', 4);
            formData.append('responsavel_nome', $('#responsavel_nome').val());
            formData.append('responsavel_email', $('#responsavel_email').val());
            formData.append('responsavel_telefone', $('#responsavel_telefone').val().replace(/[^0-9]/g, ''));
            
            $('#submitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
            
            $.ajax({
                url: 'editar.php',
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
                        }, 2000);
                    } else {
                        $('#messageArea').html('<div class="alert alert-danger">' + (response.message || 'Erro ao salvar') + '</div>');
                        $('#submitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Salvar Alterações');
                    }
                },
                error: function() {
                    $('#messageArea').html('<div class="alert alert-danger">Erro ao salvar alterações</div>');
                    $('#submitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Salvar Alterações');
                }
            });
        }
        
        // ============================================
        // EVENTOS DINÂMICOS
        // ============================================
        
        // Carregar municípios
        $('#provincia_id').change(function() {
            const provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({
                    url: 'editar.php?ajax=get_municipios&provincia_id=' + provinciaId,
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
                    url: 'editar.php?ajax=get_comunas&municipio_id=' + municipioId,
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
        
        // Eventos de validação em tempo real
        $('#subdominio').on('input', verificarSubdominioRealtime);
        $('#dominio_personalizado').on('input', verificarDominioRealtime);
        $('#nuit').on('input', verificarNuitRealtime);
        $('#celular').on('input', function() { $(this).val(formatarCelular($(this).val())); validarCelularRealtime(); });
        $('#telefone').on('input', function() { $(this).val(formatarTelefone($(this).val())); validarTelefoneRealtime(); });
        $('#nuit').on('input', function() { $(this).val(formatarNUIT($(this).val())); });
        
        // Inicializar
        showStep(1);
        updateProgress();
        
        // Carregar municípios e comunas iniciais
        if (dadosIniciais.provincia_id) {
            $('#provincia_id').trigger('change');
            setTimeout(function() {
                if (dadosIniciais.municipio_id) {
                    $('#municipio_id').val(dadosIniciais.municipio_id).trigger('change');
                    setTimeout(function() {
                        if (dadosIniciais.comuna_id) {
                            $('#comuna_id').val(dadosIniciais.comuna_id);
                        }
                    }, 300);
                }
            }, 300);
        }
    </script>
</body>
</html>