<?php
// super-admin/escolas/editar.php - Editar dados da escola
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

// Lista de Províncias de Angola
$provincias = [
    'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 
    'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 
    'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 
    'Namibe', 'Uíge', 'Zaire'
];

// Buscar planos ativos
$planos = $conn->query("SELECT * FROM planos WHERE status = 'ativo' ORDER BY preco_mensal ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $celular = $_POST['celular'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $provincia = $_POST['provincia'] ?? '';
    $municipio = $_POST['municipio'] ?? '';
    $comuna = $_POST['comuna'] ?? '';
    $status = $_POST['status'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    $nuit = $_POST['nuit'] ?? '';
    $ano_fundacao = $_POST['ano_fundacao'] ?? '';
    
    // Upload da logo
    $logo = $escola['logo'];
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo);
        
        // Remover logo antiga
        if ($escola['logo'] && file_exists($upload_dir . $escola['logo'])) {
            unlink($upload_dir . $escola['logo']);
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
                celular = :celular,
                endereco = :endereco,
                provincia = :provincia,
                municipio = :municipio,
                comuna = :comuna,
                logo = :logo,
                responsavel_nome = :responsavel_nome,
                responsavel_email = :responsavel_email,
                responsavel_telefone = :responsavel_telefone,
                nuit = :nuit,
                ano_fundacao = :ano_fundacao,
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
            ':celular' => $celular,
            ':endereco' => $endereco,
            ':provincia' => $provincia,
            ':municipio' => $municipio,
            ':comuna' => $comuna,
            ':logo' => $logo,
            ':responsavel_nome' => $responsavel_nome,
            ':responsavel_email' => $responsavel_email,
            ':responsavel_telefone' => $responsavel_telefone,
            ':nuit' => $nuit,
            ':ano_fundacao' => $ano_fundacao,
            ':status' => $status
        ]);
        
        $success = "Escola atualizada com sucesso!";
        
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
        
        // Atualizar dados na sessão
        $_SESSION['escola_nome'] = $nome;
        
        // Recarregar dados
        $stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $escola = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Escola - <?php echo htmlspecialchars($escola['nome']); ?> | SIGE Angola</title>
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
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .logo-preview { width: 100px; height: 100px; border-radius: 10px; object-fit: cover; border: 2px solid #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-edit"></i> Editar Escola</h2>
                <small><?php echo htmlspecialchars($escola['nome']); ?> (<?php echo $escola['subdominio']; ?>.sige.ao)</small>
            </div>
            <div>
                <a href="visualizar.php?id=<?php echo $escola['id']; ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> Visualizar</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-school"></i> Dados da Escola</h3>
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
                                <label class="required">Nome da Escola</label>
                                <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($escola['nome']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Ano de Fundação</label>
                                <input type="number" name="ano_fundacao" class="form-control" value="<?php echo $escola['ano_fundacao']; ?>" placeholder="Ex: 2000">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Subdomínio</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="<?php echo $escola['subdominio']; ?>" disabled>
                                    <span class="input-group-text">.sige.ao</span>
                                </div>
                                <small class="text-muted">O subdomínio não pode ser alterado</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Domínio Personalizado</label>
                                <input type="text" name="dominio_personalizado" class="form-control" value="<?php echo htmlspecialchars($escola['dominio_personalizado']); ?>" placeholder="exemplo.com">
                                <small class="text-muted">Opcional - use seu próprio domínio</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="required">E-mail</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($escola['email']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Telefone</label>
                                <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($escola['telefone']); ?>" placeholder="(xxx) xxx-xxx">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Celular</label>
                                <input type="text" name="celular" class="form-control" value="<?php echo htmlspecialchars($escola['celular']); ?>" placeholder="9xx xxx xxx">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>NUIT (NIF Angola)</label>
                                <input type="text" name="nuit" class="form-control" value="<?php echo htmlspecialchars($escola['nuit']); ?>" placeholder="Número de Identificação Tributária">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="ativa" <?php echo $escola['status'] == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                                    <option value="trial" <?php echo $escola['status'] == 'trial' ? 'selected' : ''; ?>>Trial</option>
                                    <option value="suspensa" <?php echo $escola['status'] == 'suspensa' ? 'selected' : ''; ?>>Suspensa</option>
                                    <option value="inativa" <?php echo $escola['status'] == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-3 mt-4">Endereço (Angola)</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Província</label>
                                <select name="provincia" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($provincias as $p): ?>
                                    <option value="<?php echo $p; ?>" <?php echo $escola['provincia'] == $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Município</label>
                                <input type="text" name="municipio" class="form-control" value="<?php echo htmlspecialchars($escola['municipio']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Comuna</label>
                                <input type="text" name="comuna" class="form-control" value="<?php echo htmlspecialchars($escola['comuna']); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Endereço</label>
                                <input type="text" name="endereco" class="form-control" value="<?php echo htmlspecialchars($escola['endereco']); ?>" placeholder="Rua, Bairro, Nº">
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-3 mt-4">Plano</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Plano</label>
                                <select name="plano_id" class="form-control">
                                    <option value="">Nenhum</option>
                                    <?php foreach ($planos as $plano): ?>
                                    <option value="<?php echo $plano['id']; ?>" <?php echo $escola['plano_id'] == $plano['id'] ? 'selected' : ''; ?>>
                                        <?php echo $plano['nome']; ?> - KZ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Trial até</label>
                                <input type="date" name="trial_ate" class="form-control" value="<?php echo $escola['trial_ate']; ?>">
                                <small class="text-muted">Data de término do período de teste</small>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-3 mt-4">Logo da Escola</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Logo</label>
                                <input type="file" name="logo" class="form-control" accept="image/*">
                                <small class="text-muted">Formatos: JPG, PNG, GIF (Max: 2MB)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php if ($escola['logo']): ?>
                                <div class="text-center">
                                    <img src="../../uploads/escolas/<?php echo $escola['logo']; ?>" class="logo-preview mb-2">
                                    <br>
                                    <small class="text-muted">Logo atual</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h5 class="mb-3 mt-4">Dados do Responsável</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Nome do Responsável</label>
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
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Telefone do Responsável</label>
                                <input type="text" name="responsavel_telefone" class="form-control" value="<?php echo htmlspecialchars($escola['responsavel_telefone']); ?>" placeholder="9xx xxx xxx">
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        As alterações serão aplicadas imediatamente.
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    </script>
</body>
</html>