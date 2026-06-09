<?php
// super-admin/escolas.php - Gestão de Escolas
require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Processar ações
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// ============================================
// CADASTRAR ESCOLA
// ============================================
if ($action == 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $subdominio = $_POST['subdominio'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $cidade = $_POST['cidade'] ?? '';
    $estado = $_POST['estado'] ?? '';
    $cep = $_POST['cep'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    $tipo_cobranca = $_POST['tipo_cobranca'] ?? 'mensal';
    $trial_dias = $_POST['trial_dias'] ?? 30;
    
    // Upload da logo
    $logo = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
        $upload_path = $upload_dir . $logo;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
            // Criar thumbnail
            if (function_exists('imagecreatefromjpeg')) {
                createThumbnail($upload_path, $upload_dir . 'thumb_' . $logo, 100);
            }
        } else {
            $error = "Erro ao fazer upload da logo.";
        }
    }
    
    if (empty($error)) {
        try {
            $conn->beginTransaction();
            
            // Verificar subdomínio único
            $stmt = $conn->prepare("SELECT id FROM escolas WHERE subdominio = :subdominio");
            $stmt->execute([':subdominio' => $subdominio]);
            if ($stmt->fetch()) {
                throw new Exception("Subdomínio já está em uso.");
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
            
            // Inserir escola
            $stmt = $conn->prepare("
                INSERT INTO escolas (
                    nome, subdominio, dominio_personalizado, plano_id,
                    email, telefone, endereco, cidade, estado, cep,
                    logo, responsavel_nome, responsavel_email, responsavel_telefone,
                    status, trial_ate, created_at
                ) VALUES (
                    :nome, :subdominio, :dominio_personalizado, :plano_id,
                    :email, :telefone, :endereco, :cidade, :estado, :cep,
                    :logo, :responsavel_nome, :responsavel_email, :responsavel_telefone,
                    'trial', :trial_ate, NOW()
                )
            ");
            
            $stmt->execute([
                ':nome' => $nome,
                ':subdominio' => $subdominio,
                ':dominio_personalizado' => $dominio_personalizado ?: null,
                ':plano_id' => $plano_id,
                ':email' => $email,
                ':telefone' => $telefone,
                ':endereco' => $endereco,
                ':cidade' => $cidade,
                ':estado' => $estado,
                ':cep' => $cep,
                ':logo' => $logo,
                ':responsavel_nome' => $responsavel_nome,
                ':responsavel_email' => $responsavel_email,
                ':responsavel_telefone' => $responsavel_telefone,
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
            
            // Enviar e-mail de boas-vindas
            $to = $responsavel_email;
            $subject = "Bem-vindo ao SIGE SaaS - {$nome}";
            $message_body = "
                <h2>Olá {$responsavel_nome}!</h2>
                <p>Sua escola <strong>{$nome}</strong> foi cadastrada com sucesso no SIGE SaaS.</p>
                <p><strong>Dados de acesso:</strong></p>
                <ul>
                    <li>URL: http://{$subdominio}.sige.com</li>
                    <li>E-mail: {$responsavel_email}</li>
                    <li>Senha temporária: {$senha_temp}</li>
                </ul>
                <p>Recomendamos alterar sua senha no primeiro acesso.</p>
                <p>Período de trial: {$trial_dias} dias</p>
                <p>Atenciosamente,<br>Equipe SIGE SaaS</p>
            ";
            
            // Enviar email (implementar com PHPMailer)
            // sendEmail($to, $subject, $message_body);
            
            $message = "Escola cadastrada com sucesso! E-mail enviado para {$responsavel_email}";
            
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
            
            header("Location: ?page=escolas&message=" . urlencode($message));
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ============================================
// EDITAR ESCOLA
// ============================================
if ($action == 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_GET['id'];
    $nome = $_POST['nome'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $cidade = $_POST['cidade'] ?? '';
    $estado = $_POST['estado'] ?? '';
    $cep = $_POST['cep'] ?? '';
    $status = $_POST['status'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    
    // Upload da logo
    $logo = $_POST['logo_atual'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
        $upload_path = $upload_dir . $logo;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
            if (function_exists('imagecreatefromjpeg')) {
                createThumbnail($upload_path, $upload_dir . 'thumb_' . $logo, 100);
            }
            // Remover logo antiga
            if (!empty($_POST['logo_atual']) && file_exists($upload_dir . $_POST['logo_atual'])) {
                unlink($upload_dir . $_POST['logo_atual']);
                if (file_exists($upload_dir . 'thumb_' . $_POST['logo_atual'])) {
                    unlink($upload_dir . 'thumb_' . $_POST['logo_atual']);
                }
            }
        }
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE escolas SET
                nome = :nome,
                dominio_personalizado = :dominio_personalizado,
                plano_id = :plano_id,
                email = :email,
                telefone = :telefone,
                endereco = :endereco,
                cidade = :cidade,
                estado = :estado,
                cep = :cep,
                logo = :logo,
                responsavel_nome = :responsavel_nome,
                responsavel_email = :responsavel_email,
                responsavel_telefone = :responsavel_telefone,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $id,
            ':nome' => $nome,
            ':dominio_personalizado' => $dominio_personalizado ?: null,
            ':plano_id' => $plano_id,
            ':email' => $email,
            ':telefone' => $telefone,
            ':endereco' => $endereco,
            ':cidade' => $cidade,
            ':estado' => $estado,
            ':cep' => $cep,
            ':logo' => $logo,
            ':responsavel_nome' => $responsavel_nome,
            ':responsavel_email' => $responsavel_email,
            ':responsavel_telefone' => $responsavel_telefone,
            ':status' => $status
        ]);
        
        $message = "Escola atualizada com sucesso!";
        
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
        
        header("Location: ?page=escolas&message=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// EXCLUIR ESCOLA
// ============================================
if ($action == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        // Buscar logo para deletar
        $stmt = $conn->prepare("SELECT logo FROM escolas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $escola = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($escola && !empty($escola['logo'])) {
            $upload_dir = __DIR__ . '/../uploads/escolas/';
            if (file_exists($upload_dir . $escola['logo'])) {
                unlink($upload_dir . $escola['logo']);
            }
            if (file_exists($upload_dir . 'thumb_' . $escola['logo'])) {
                unlink($upload_dir . 'thumb_' . $escola['logo']);
            }
        }
        
        $stmt = $conn->prepare("DELETE FROM escolas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $message = "Escola excluída com sucesso!";
        
        header("Location: ?page=escolas&message=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// LISTAR ESCOLAS
// ============================================
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$plano_filter = $_GET['plano'] ?? '';

$query = "
    SELECT e.*, p.nome as plano_nome, p.preco_mensal, p.preco_anual,
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id AND status = 'ativa') as tem_assinatura
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (e.nome LIKE :search OR e.subdominio LIKE :search OR e.email LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($status_filter) {
    $query .= " AND e.status = :status";
    $params[':status'] = $status_filter;
}
if ($plano_filter) {
    $query .= " AND e.plano_id = :plano";
    $params[':plano'] = $plano_filter;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar planos para filtro e formulário
$planos = $conn->query("SELECT * FROM planos WHERE status = 'ativo'")->fetchAll(PDO::FETCH_ASSOC);

// Buscar escola para edição
$escola_edit = null;
if ($action == 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :id");
    $stmt->execute([':id' => $_GET['id']]);
    $escola_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Função para criar thumbnail
function createThumbnail($source, $destination, $size) {
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
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escolas - Super Admin | SIGE SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        /* Mesmo estilo do dashboard.php */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .page-title h2 {
            margin: 0;
            font-size: 1.5em;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.1em;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .status-inativa { background: #ffebee; color: #d32f2f; }
        
        .logo-preview {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid #ddd;
        }
        
        .modal-lg {
            max-width: 800px;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1a1a2e;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE SaaS</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="?page=dashboard" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="?page=escolas" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="?page=planos" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="?page=assinaturas" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="?page=pagamentos" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="?page=suporte" class="nav-link"><i class="fas fa-headset"></i> Suporte</a></li>
            <li class="nav-item"><a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2><i class="fas fa-school"></i> Escolas</h2>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalEscola">
                    <i class="fas fa-plus"></i> Nova Escola
                </button>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="escolas">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Buscar escola..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="ativa" <?php echo $status_filter == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                            <option value="trial" <?php echo $status_filter == 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="suspensa" <?php echo $status_filter == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                            <option value="inativa" <?php echo $status_filter == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="plano" class="form-control">
                            <option value="">Todos os planos</option>
                            <?php foreach ($planos as $plano): ?>
                            <option value="<?php echo $plano['id']; ?>" <?php echo $plano_filter == $plano['id'] ? 'selected' : ''; ?>>
                                <?php echo $plano['nome']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Escolas -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Escolas Cadastradas</h3>
                <span class="badge bg-secondary">Total: <?php echo count($escolas); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Logo</th>
                                <th>Escola</th>
                                <th>Subdomínio</th>
                                <th>Plano</th>
                                <th>Responsável</th>
                                <th>Status</th>
                                <th>Trial até</th>
                                <th>Usuários</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($escolas as $escola): ?>
                            <tr>
                                <td>
                                    <?php if ($escola['logo']): ?>
                                        <img src="../uploads/escolas/thumb_<?php echo $escola['logo']; ?>" class="logo-preview" alt="Logo">
                                    <?php else: ?>
                                        <div class="logo-preview bg-light d-flex align-items-center justify-content-center">
                                            <i class="fas fa-school fa-2x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($escola['nome']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($escola['email']); ?></small>
                                 </td>
                                <td>
                                    <?php echo $escola['subdominio']; ?>.sige.com<br>
                                    <?php if ($escola['dominio_personalizado']): ?>
                                        <small class="text-muted"><?php echo $escola['dominio_personalizado']; ?></small>
                                    <?php endif; ?>
                                 </td>
                                <td><?php echo $escola['plano_nome'] ?? 'Nenhum'; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($escola['responsavel_nome']); ?><br>
                                    <small><?php echo htmlspecialchars($escola['responsavel_email']); ?></small>
                                 </td>
                                <td>
                                    <span class="status-badge status-<?php echo $escola['status']; ?>">
                                        <?php echo ucfirst($escola['status']); ?>
                                    </span>
                                 </td>
                                <td><?php echo $escola['trial_ate'] ? date('d/m/Y', strtotime($escola['trial_ate'])) : '-'; ?></td>
                                <td><?php echo $escola['total_usuarios']; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=escolas&action=edit&id=<?php echo $escola['id']; ?>" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalEscolaEdit<?php echo $escola['id']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?page=assinaturas&escola=<?php echo $escola['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-credit-card"></i>
                                        </a>
                                        <a href="?page=escolas&action=delete&id=<?php echo $escola['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja excluir esta escola?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                 </td>
                            </tr>
                            
                            <!-- Modal Editar Escola -->
                            <div class="modal fade" id="modalEscolaEdit<?php echo $escola['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Editar Escola: <?php echo htmlspecialchars($escola['nome']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>Nome da Escola *</label>
                                                            <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($escola['nome']); ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>Domínio Personalizado</label>
                                                            <input type="text" name="dominio_personalizado" class="form-control" value="<?php echo htmlspecialchars($escola['dominio_personalizado']); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>E-mail</label>
                                                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($escola['email']); ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>Telefone</label>
                                                            <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($escola['telefone']); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Plano</label>
                                                            <select name="plano_id" class="form-control" required>
                                                                <?php foreach ($planos as $plano): ?>
                                                                <option value="<?php echo $plano['id']; ?>" <?php echo $escola['plano_id'] == $plano['id'] ? 'selected' : ''; ?>>
                                                                    <?php echo $plano['nome']; ?> - R$ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                                                </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Status</label>
                                                            <select name="status" class="form-control" required>
                                                                <option value="ativa" <?php echo $escola['status'] == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                                                                <option value="trial" <?php echo $escola['status'] == 'trial' ? 'selected' : ''; ?>>Trial</option>
                                                                <option value="suspensa" <?php echo $escola['status'] == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                                                                <option value="inativa" <?php echo $escola['status'] == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Logo</label>
                                                            <input type="file" name="logo" class="form-control" accept="image/*">
                                                            <input type="hidden" name="logo_atual" value="<?php echo $escola['logo']; ?>">
                                                            <?php if ($escola['logo']): ?>
                                                                <div class="mt-2">
                                                                    <img src="../uploads/escolas/thumb_<?php echo $escola['logo']; ?>" style="width: 50px;">
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>Responsável</label>
                                                            <input type="text" name="responsavel_nome" class="form-control" value="<?php echo htmlspecialchars($escola['responsavel_nome']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label>E-mail do Responsável</label>
                                                            <input type="email" name="responsavel_email" class="form-control" value="<?php echo htmlspecialchars($escola['responsavel_email']); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Endereço</label>
                                                            <input type="text" name="endereco" class="form-control" value="<?php echo htmlspecialchars($escola['endereco']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Cidade</label>
                                                            <input type="text" name="cidade" class="form-control" value="<?php echo htmlspecialchars($escola['cidade']); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label>Estado</label>
                                                            <input type="text" name="estado" class="form-control" value="<?php echo htmlspecialchars($escola['estado']); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($escolas)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Nenhuma escola cadastrada</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Escola -->
    <div class="modal fade" id="modalEscola" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Escola</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Nome da Escola *</label>
                                    <input type="text" name="nome" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Subdomínio *</label>
                                    <div class="input-group">
                                        <input type="text" name="subdominio" class="form-control" required>
                                        <span class="input-group-text">.sige.com</span>
                                    </div>
                                    <small class="text-muted">Ex: escola1 (resultará em escola1.sige.com)</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Domínio Personalizado</label>
                                    <input type="text" name="dominio_personalizado" class="form-control" placeholder="exemplo.com.br">
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Logo da Escola</label>
                                    <input type="file" name="logo" class="form-control" accept="image/*">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail *</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Telefone</label>
                                    <input type="text" name="telefone" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Plano *</label>
                                    <select name="plano_id" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($planos as $plano): ?>
                                        <option value="<?php echo $plano['id']; ?>">
                                            <?php echo $plano['nome']; ?> - R$ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Tipo de Cobrança</label>
                                    <select name="tipo_cobranca" class="form-control">
                                        <option value="mensal">Mensal</option>
                                        <option value="anual">Anual (10% desconto)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Período de Trial (dias)</label>
                                    <input type="number" name="trial_dias" class="form-control" value="30">
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        <h6>Dados do Responsável</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Nome do Responsável *</label>
                                    <input type="text" name="responsavel_nome" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>E-mail do Responsável *</label>
                                    <input type="email" name="responsavel_email" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Telefone do Responsável</label>
                                    <input type="text" name="responsavel_telefone" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        <h6>Endereço</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Endereço</label>
                                    <input type="text" name="endereco" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Cidade</label>
                                    <input type="text" name="cidade" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Estado</label>
                                    <input type="text" name="estado" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>CEP</label>
                                    <input type="text" name="cep" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Após o cadastro, um e-mail será enviado para o responsável com as instruções de acesso e senha temporária.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Cadastrar Escola</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() {
            $('#sidebar').toggleClass('open');
        });
    </script>
</body>
</html>