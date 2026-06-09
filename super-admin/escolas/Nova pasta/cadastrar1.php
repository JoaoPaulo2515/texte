<?php
// super-admin/escolas/cadastrar.php
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario']) || $_SESSION['usuario']['tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Buscar planos ativos
$planos = $conn->query("SELECT * FROM planos WHERE status = 'ativo'")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $subdominio = $_POST['subdominio'] ?? '';
    $dominio_personalizado = $_POST['dominio_personalizado'] ?? '';
    $plano_id = $_POST['plano_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    $responsavel_nome = $_POST['responsavel_nome'] ?? '';
    $responsavel_email = $_POST['responsavel_email'] ?? '';
    $responsavel_telefone = $_POST['responsavel_telefone'] ?? '';
    $tipo_cobranca = $_POST['tipo_cobranca'] ?? 'mensal';
    $trial_dias = $_POST['trial_dias'] ?? 30;
    
    try {
        $conn->beginTransaction();
        
        // Verificar se subdomínio já existe
        $stmt = $conn->prepare("SELECT id FROM escolas WHERE subdominio = :subdominio");
        $stmt->execute([':subdominio' => $subdominio]);
        if ($stmt->fetch()) {
            throw new Exception("Subdomínio já está em uso.");
        }
        
        // Buscar valor do plano
        $stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id");
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
                email, telefone, responsavel_nome, responsavel_email,
                responsavel_telefone, status, trial_ate, created_at
            ) VALUES (
                :nome, :subdominio, :dominio_personalizado, :plano_id,
                :email, :telefone, :responsavel_nome, :responsavel_email,
                :responsavel_telefone, 'trial', :trial_ate, NOW()
            )
        ");
        
        $stmt->execute([
            ':nome' => $nome,
            ':subdominio' => $subdominio,
            ':dominio_personalizado' => $dominio_personalizado ?: null,
            ':plano_id' => $plano_id,
            ':email' => $email,
            ':telefone' => $telefone,
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
        
        $conn->commit();
        
        $success = "Escola cadastrada com sucesso! Período de trial de {$trial_dias} dias.";
        
        // Log da ação
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'cadastrar_escola', 'escolas', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario']['id'],
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
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Escola - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }
        .form-container {
            max-width: 800px;
            margin: 30px auto;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-header {
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            font-weight: 500;
            margin-bottom: 8px;
            color: #333;
        }
        .required:after {
            content: "*";
            color: red;
            margin-left: 5px;
        }
        .btn-primary {
            background: #4361ee;
            border: none;
            padding: 12px 30px;
        }
        .btn-primary:hover {
            background: #3f37c9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <a href="index.php" class="btn btn-link mb-3">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0">
                        <i class="fas fa-school"></i> Nova Escola
                    </h3>
                    <p class="mb-0 mt-2 opacity-75">Cadastre uma nova escola no sistema</p>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <h5 class="mb-3">Dados da Escola</h5>
                        
                        <div class="form-group">
                            <label class="required">Nome da Escola</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required">Subdomínio</label>
                                    <div class="input-group">
                                        <input type="text" name="subdominio" class="form-control" required>
                                        <span class="input-group-text">.sige.com</span>
                                    </div>
                                    <small class="text-muted">Ex: escola1 (resultará em escola1.sige.com)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Domínio Personalizado</label>
                                    <input type="text" name="dominio_personalizado" class="form-control" placeholder="exemplo.com.br">
                                    <small class="text-muted">Opcional - use seu próprio domínio</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required">E-mail</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Telefone</label>
                                    <input type="text" name="telefone" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3 mt-4">Plano e Assinatura</h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required">Plano</label>
                                    <select name="plano_id" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($planos as $plano): ?>
                                        <option value="<?php echo $plano['id']; ?>">
                                            <?php echo $plano['nome']; ?> - 
                                            R$ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>/mês
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required">Tipo de Cobrança</label>
                                    <select name="tipo_cobranca" class="form-control" required>
                                        <option value="mensal">Mensal</option>
                                        <option value="anual">Anual (10% desconto)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Período de Trial (dias)</label>
                            <input type="number" name="trial_dias" class="form-control" value="30">
                            <small class="text-muted">Período de teste gratuito antes da primeira cobrança</small>
                        </div>
                        
                        <h5 class="mb-3 mt-4">Responsável</h5>
                        
                        <div class="form-group">
                            <label class="required">Nome do Responsável</label>
                            <input type="text" name="responsavel_nome" class="form-control" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required">E-mail do Responsável</label>
                                    <input type="email" name="responsavel_email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Telefone do Responsável</label>
                                    <input type="text" name="responsavel_telefone" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            Após o cadastro, um e-mail será enviado para o responsável com as instruções de acesso.
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
</body>
</html>