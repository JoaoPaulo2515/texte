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
    $subdominio = $_POST['subdominio'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $celular = $_POST['celular'] ?? '';
    $endereco = $_POST['endereco'] ?? '';
    $provincia = $_POST['provincia'] ?? '';
    $municipio = $_POST['municipio'] ?? '';
    $comuna = $_POST['comuna'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    $tipo_cobranca = $_POST['tipo_cobranca'] ?? 'mensal';
    $trial_dias = $_POST['trial_dias'] ?? 30;
    $nuit = $_POST['nuit'] ?? ''; // Número de Identificação Tributária de Angola
    $ano_fundacao = $_POST['ano_fundacao'] ?? '';
    
    // Upload da logo
    $logo = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $upload_dir = __DIR__ . '/../../uploads/escolas/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo);
    }
    
    try {
        $conn->beginTransaction();
        
        // Verificar subdomínio único
        $stmt = $conn->prepare("SELECT id FROM escolas WHERE subdominio = :subdominio");
        $stmt->execute([':subdominio' => $subdominio]);
        if ($stmt->fetch()) throw new Exception("Subdomínio já está em uso.");
        
        // Buscar valor do plano
        $stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id AND status = 'ativo'");
        $stmt->execute([':id' => $plano_id]);
        $plano = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plano) throw new Exception("Plano não encontrado.");
        
        $valor = ($tipo_cobranca == 'mensal') ? $plano['preco_mensal'] : $plano['preco_anual'];
        $data_inicio = date('Y-m-d');
        $data_fim = ($tipo_cobranca == 'mensal') ? date('Y-m-d', strtotime('+1 month')) : date('Y-m-d', strtotime('+1 year'));
        $data_trial = date('Y-m-d', strtotime("+{$trial_dias} days"));
        
        // Inserir escola
        $stmt = $conn->prepare("
            INSERT INTO escolas (
                nome, subdominio, dominio_personalizado, plano_id,
                email, telefone, celular, endereco, provincia, municipio, comuna,
                logo, responsavel_nome, responsavel_email, responsavel_telefone,
                nuit, ano_fundacao, status, trial_ate, created_at
            ) VALUES (
                :nome, :subdominio, :dominio_personalizado, :plano_id,
                :email, :telefone, :celular, :endereco, :provincia, :municipio, :comuna,
                :logo, :responsavel_nome, :responsavel_email, :responsavel_telefone,
                :nuit, :ano_fundacao, 'trial', :trial_ate, NOW()
            )
        ");
        
        $stmt->execute([
            ':nome' => $nome,
            ':subdominio' => $subdominio,
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
        
        $success = "Escola cadastrada com sucesso! Período de trial de {$trial_dias} dias. Senha temporária enviada para {$responsavel_email}";
        
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
    <style>
        body { background: #f0f2f5; }
        .form-container { max-width: 900px; margin: 30px auto; }
        .card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
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
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <h5 class="mb-3">Dados da Escola</h5>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="required">Nome da Escola</label>
                                    <input type="text" name="nome" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Ano de Fundação</label>
                                    <input type="number" name="ano_fundacao" class="form-control" placeholder="Ex: 2000">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">Subdomínio</label>
                                    <div class="input-group">
                                        <input type="text" name="subdominio" class="form-control" required>
                                        <span class="input-group-text">.sige.ao</span>
                                    </div>
                                    <small class="text-muted">Ex: escola1 (resultará em escola1.sige.ao)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Domínio Personalizado</label>
                                    <input type="text" name="dominio_personalizado" class="form-control" placeholder="exemplo.com">
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="required">E-mail</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Telefone</label>
                                    <input type="text" name="telefone" class="form-control" placeholder="(xxx) xxx-xxx">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label>Celular</label>
                                    <input type="text" name="celular" class="form-control" placeholder="9xx xxx xxx">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>NUIT (NIF Angola)</label>
                                    <input type="text" name="nuit" class="form-control" placeholder="Número de Identificação Tributária">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Logo da Escola</label>
                                    <input type="file" name="logo" class="form-control" accept="image/*">
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
                                        <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Município</label>
                                    <input type="text" name="municipio" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Comuna</label>
                                    <input type="text" name="comuna" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Endereço</label>
                                    <input type="text" name="endereco" class="form-control" placeholder="Rua, Bairro, Nº">
                                </div>
                            </div>
                        </div>
                        
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
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Cadastrar Escola
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>