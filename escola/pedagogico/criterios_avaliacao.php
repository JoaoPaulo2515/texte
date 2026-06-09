<?php
// escola/pedagogico/criterios_avaliacao.php - Gestão de Critérios de Avaliação

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// Incluir modal de confirmação global
if (file_exists(__DIR__ . '/includes/modal_confirmacao.php')) {
    include_once __DIR__ . '/includes/modal_confirmacao.php';
}

// ============================================
// PROCESSAR AJAX PARA BUSCAR CRITÉRIO
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM criterios_avaliacao WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $criterio = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($criterio) {
            echo json_encode(['success' => true, 'criterio' => $criterio]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Critério não encontrado']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `criterios_avaliacao` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(30) NOT NULL,
    `descricao` TEXT,
    `tipo` ENUM('prova', 'trabalho', 'participacao', 'projeto', 'laboratorio', 'frequencia', 'outro') DEFAULT 'prova',
    `peso` DECIMAL(5,2) DEFAULT 1.00,
    `nota_maxima` DECIMAL(5,2) DEFAULT 20.00,
    `nota_minima_aprovacao` DECIMAL(5,2) DEFAULT 10.00,
    `periodo` ENUM('bimestral', 'trimestral', 'semestral', 'anual') DEFAULT 'bimestral',
    `permite_recuperacao` TINYINT DEFAULT 1,
    `quantidade_avaliacoes` INT DEFAULT 1,
    `instrucoes` TEXT,
    `escola_id` INT NOT NULL,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_codigo_escola` (`escola_id`, `codigo`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

$mensagem = '';
$erro = '';

// Inserir novo critério
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $codigo = trim($_POST['codigo']);
    $descricao = trim($_POST['descricao']);
    $tipo = $_POST['tipo'];
    $peso = !empty($_POST['peso']) ? (float)str_replace(',', '.', $_POST['peso']) : 1.00;
    $nota_maxima = !empty($_POST['nota_maxima']) ? (float)str_replace(',', '.', $_POST['nota_maxima']) : 20.00;
    $nota_minima_aprovacao = !empty($_POST['nota_minima_aprovacao']) ? (float)str_replace(',', '.', $_POST['nota_minima_aprovacao']) : 10.00;
    $periodo = $_POST['periodo'];
    $permite_recuperacao = isset($_POST['permite_recuperacao']) ? 1 : 0;
    $quantidade_avaliacoes = !empty($_POST['quantidade_avaliacoes']) ? (int)$_POST['quantidade_avaliacoes'] : 1;
    $instrucoes = trim($_POST['instrucoes']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Verificar se já existe critério com este código
    $sql_check = "SELECT id FROM criterios_avaliacao WHERE escola_id = :escola_id AND codigo = :codigo";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um critério cadastrado com o código '$codigo'.";
    } else {
        $sql = "INSERT INTO criterios_avaliacao (escola_id, nome, codigo, descricao, tipo, peso, nota_maxima, nota_minima_aprovacao, periodo, permite_recuperacao, quantidade_avaliacoes, instrucoes, status, created_at) 
                VALUES (:escola_id, :nome, :codigo, :descricao, :tipo, :peso, :nota_maxima, :nota_minima_aprovacao, :periodo, :permite_recuperacao, :quantidade_avaliacoes, :instrucoes, :status, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':peso' => $peso,
            ':nota_maxima' => $nota_maxima,
            ':nota_minima_aprovacao' => $nota_minima_aprovacao,
            ':periodo' => $periodo,
            ':permite_recuperacao' => $permite_recuperacao,
            ':quantidade_avaliacoes' => $quantidade_avaliacoes,
            ':instrucoes' => $instrucoes,
            ':status' => $status
        ]);
        
        $mensagem = "Critério de avaliação cadastrado com sucesso!";
    }
}

// Atualizar critério
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $codigo = trim($_POST['codigo']);
    $descricao = trim($_POST['descricao']);
    $tipo = $_POST['tipo'];
    $peso = !empty($_POST['peso']) ? (float)str_replace(',', '.', $_POST['peso']) : 1.00;
    $nota_maxima = !empty($_POST['nota_maxima']) ? (float)str_replace(',', '.', $_POST['nota_maxima']) : 20.00;
    $nota_minima_aprovacao = !empty($_POST['nota_minima_aprovacao']) ? (float)str_replace(',', '.', $_POST['nota_minima_aprovacao']) : 10.00;
    $periodo = $_POST['periodo'];
    $permite_recuperacao = isset($_POST['permite_recuperacao']) ? 1 : 0;
    $quantidade_avaliacoes = !empty($_POST['quantidade_avaliacoes']) ? (int)$_POST['quantidade_avaliacoes'] : 1;
    $instrucoes = trim($_POST['instrucoes']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Verificar se já existe outro critério com este código
    $sql_check = "SELECT id FROM criterios_avaliacao WHERE escola_id = :escola_id AND codigo = :codigo AND id != :id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo, ':id' => $id]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um critério cadastrado com o código '$codigo'.";
    } else {
        $sql = "UPDATE criterios_avaliacao SET 
                nome = :nome,
                codigo = :codigo,
                descricao = :descricao,
                tipo = :tipo,
                peso = :peso,
                nota_maxima = :nota_maxima,
                nota_minima_aprovacao = :nota_minima_aprovacao,
                periodo = :periodo,
                permite_recuperacao = :permite_recuperacao,
                quantidade_avaliacoes = :quantidade_avaliacoes,
                instrucoes = :instrucoes,
                status = :status,
                updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':peso' => $peso,
            ':nota_maxima' => $nota_maxima,
            ':nota_minima_aprovacao' => $nota_minima_aprovacao,
            ':periodo' => $periodo,
            ':permite_recuperacao' => $permite_recuperacao,
            ':quantidade_avaliacoes' => $quantidade_avaliacoes,
            ':instrucoes' => $instrucoes,
            ':status' => $status,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem = "Critério de avaliação atualizado com sucesso!";
    }
}

// Excluir critério
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se existem avaliações associadas a este critério
    $sql_check = "SELECT COUNT(*) as total FROM avaliacoes WHERE criterio_id = :criterio_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':criterio_id' => $id]);
    $total_avaliacoes = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_avaliacoes > 0) {
        $erro = "Não é possível excluir este critério pois existem $total_avaliacoes avaliações associadas.";
    } else {
        $sql = "DELETE FROM criterios_avaliacao WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Critério excluído com sucesso!";
    }
}

// ============================================
// BUSCAR CRITÉRIOS
// ============================================
$sql_criterios = "SELECT * FROM criterios_avaliacao WHERE escola_id = :escola_id ORDER BY tipo, nome ASC";
$stmt_criterios = $conn->prepare($sql_criterios);
$stmt_criterios->execute([':escola_id' => $escola_id]);
$criterios = $stmt_criterios->fetchAll(PDO::FETCH_ASSOC);

$total_criterios = count($criterios);
$criterios_ativos = 0;
$soma_pesos = 0;

foreach ($criterios as $c) {
    if ($c['status'] == 1) $criterios_ativos++;
    if ($c['status'] == 1) $soma_pesos += $c['peso'];
}

// Tipos de critério
$tipos_criterio = [
    'prova' => 'Prova',
    'trabalho' => 'Trabalho',
    'participacao' => 'Participação',
    'projeto' => 'Projeto',
    'laboratorio' => 'Laboratório',
    'frequencia' => 'Frequência',
    'outro' => 'Outro'
];

// Períodos
$periodos = [
    'bimestral' => 'Bimestral',
    'trimestral' => 'Trimestral',
    'semestral' => 'Semestral',
    'anual' => 'Anual'
];
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critérios de Avaliação - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-body { padding: 25px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 1px; }
        .stat-total .stat-number { color: #1e5799; }
        .stat-ativos .stat-number { color: #27ae60; }
        .stat-pesos .stat-number { color: #e67e22; font-size: 24px; }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        .btn-novo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .table-criterios { width: 100%; border-collapse: collapse; }
        .table-criterios th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-criterios td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-criterios tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
        .badge-tipo {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        .badge-prova { background: #3498db; color: white; }
        .badge-trabalho { background: #9b59b6; color: white; }
        .badge-participacao { background: #2ecc71; color: white; }
        .badge-projeto { background: #e74c3c; color: white; }
        .badge-laboratorio { background: #f39c12; color: white; }
        .badge-frequencia { background: #1abc9c; color: white; }
        
        .btn-acao {
            padding: 5px 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s ease;
            margin: 0 2px;
        }
        .btn-editar { background: #17a2b8; color: white; }
        .btn-editar:hover { background: #138496; transform: translateY(-2px); }
        .btn-excluir { background: #dc3545; color: white; }
        .btn-excluir:hover { background: #c82333; transform: translateY(-2px); }
        
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 800px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-custom-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-custom-header h3 { font-size: 20px; margin: 0; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        .close-modal:hover { color: #ddd; }
        .modal-custom-body { padding: 25px; max-height: 70vh; overflow-y: auto; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { font-weight: 600; font-size: 13px; color: #2c3e50; margin-bottom: 8px; display: block; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1e5799;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30,87,153,0.1);
        }
        textarea.form-control { resize: vertical; min-height: 80px; }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        .checkbox-group input {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }
        
        .btn-salvar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-salvar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-cancelar {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        .btn-cancelar:hover { transform: translateY(-2px); background: #5a6268; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .info-box {
            background: #e8f4f8;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .table-criterios { font-size: 11px; }
            .table-criterios th, .table-criterios td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-clipboard-list"></i> Critérios de Avaliação</h1>
            <p>Definição dos critérios, pesos e regras de avaliação</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <?php if ($soma_pesos != 100 && $soma_pesos != 0): ?>
        <div class="alert alert-warning">
            ⚠️ A soma dos pesos dos critérios ativos é <strong><?php echo $soma_pesos; ?>%</strong>. 
            O ideal é que a soma seja 100% para uma avaliação balanceada.
        </div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_criterios; ?></div>
            <div class="stat-label">Total de Critérios</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $criterios_ativos; ?></div>
            <div class="stat-label">Critérios Ativos</div>
        </div>
        <div class="stat-card stat-pesos">
            <div class="stat-number"><?php echo number_format($soma_pesos, 1); ?>%</div>
            <div class="stat-label">Soma dos Pesos</div>
        </div>
    </div>
    
    <!-- Lista de Critérios -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Critérios Cadastrados
            <span class="badge bg-light text-dark ms-2"><?php echo $total_criterios; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Critério</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($criterios)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                    <p>Nenhum critério de avaliação cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro critério</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-criterios">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Critério</th>
                                <th>Tipo</th>
                                <th>Peso</th>
                                <th>Nota Máx.</th>
                                <th>Período</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($criterios as $criterio): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($criterio['codigo']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($criterio['nome']); ?></strong>
                                        <?php if ($criterio['descricao']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($criterio['descricao'], 0, 50)) . (strlen($criterio['descricao']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $classe_tipo = 'badge-' . $criterio['tipo'];
                                        $nome_tipo = $tipos_criterio[$criterio['tipo']] ?? ucfirst($criterio['tipo']);
                                        ?>
                                        <span class="badge-tipo <?php echo $classe_tipo; ?>"><?php echo $nome_tipo; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo number_format($criterio['peso'], 1); ?>%</strong>
                                        <?php if ($criterio['quantidade_avaliacoes'] > 1): ?>
                                            <br><small class="text-muted"><?php echo $criterio['quantidade_avaliacoes']; ?> avaliações</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format($criterio['nota_maxima'], 1); ?> valores</td>
                                    <td class="text-center"><?php echo $periodos[$criterio['periodo']] ?? $criterio['periodo']; ?></td>
                                    <td class="text-center">
                                        <?php if ($criterio['status'] == 1): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                        <?php if ($criterio['permite_recuperacao']): ?>
                                            <br><small class="text-success">✓ c/ recuperação</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarCriterio(<?php echo $criterio['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="excluirCriterio(<?php echo $criterio['id']; ?>, '<?php echo htmlspecialchars(addslashes($criterio['nome'])); ?>')">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Critério -->
<div id="modalCriterio" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Critério de Avaliação</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formCriterio">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="criterio_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Código *</label>
                            <input type="text" name="codigo" class="form-control" required placeholder="Ex: AVAL01, PROVA_FINAL">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Tipo *</label>
                            <select name="tipo" class="form-select" required>
                                <?php foreach ($tipos_criterio as $valor => $nome): ?>
                                    <option value="<?php echo $valor; ?>"><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome do Critério *</label>
                    <input type="text" name="nome" class="form-control" required placeholder="Ex: Avaliação Final, Trabalho de Pesquisa">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2" placeholder="Descreva o propósito e características deste critério"></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Peso (%) *</label>
                            <input type="text" name="peso" class="form-control" required placeholder="Ex: 30.0" value="1.0">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nota Máxima *</label>
                            <input type="text" name="nota_maxima" class="form-control" required placeholder="Ex: 20.0" value="20.0">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nota Mínima Aprovação</label>
                            <input type="text" name="nota_minima_aprovacao" class="form-control" placeholder="Ex: 10.0" value="10.0">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Período *</label>
                            <select name="periodo" class="form-select" required>
                                <?php foreach ($periodos as $valor => $nome): ?>
                                    <option value="<?php echo $valor; ?>"><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Quantidade de Avaliações</label>
                            <input type="number" name="quantidade_avaliacoes" class="form-control" min="1" max="10" value="1">
                            <small class="text-muted">Número de avaliações deste tipo por período</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="permite_recuperacao" id="permite_recuperacao" value="1" checked>
                        <label for="permite_recuperacao">Permite recuperação/segunda chamada</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Instruções Específicas</label>
                    <textarea name="instrucoes" class="form-control" rows="3" placeholder="Instruções para aplicação da avaliação, critérios de correção, etc."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informação:</strong>
                    <ul class="mb-0 mt-2">
                        <li>O peso representa o percentual deste critério na nota final do período</li>
                        <li>A soma dos pesos de todos os critérios ativos deve ser 100%</li>
                        <li>A nota mínima de aprovação é opcional (deixe em branco para usar o padrão da escola)</li>
                    </ul>
                </div>
                
                <div class="text-end mt-3">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        if (toast) {
            toast.textContent = message;
            toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
            toast.style.display = 'block';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Critério de Avaliação';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('criterio_id').value = '0';
        document.getElementById('formCriterio').reset();
        
        // Valores padrão
        document.querySelector('input[name="peso"]').value = '1.0';
        document.querySelector('input[name="nota_maxima"]').value = '20.0';
        document.querySelector('input[name="nota_minima_aprovacao"]').value = '10.0';
        document.querySelector('select[name="status"]').value = '1';
        document.querySelector('select[name="tipo"]').value = 'prova';
        document.querySelector('select[name="periodo"]').value = 'bimestral';
        document.querySelector('input[name="quantidade_avaliacoes"]').value = '1';
        document.getElementById('permite_recuperacao').checked = true;
        
        document.getElementById('modalCriterio').style.display = 'block';
    }
    
    function editarCriterio(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Critério de Avaliação';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('criterio_id').value = id;
        
        showToast('Carregando dados do critério...');
        
        const url = window.location.pathname + '?ajax=1&id=' + id;
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const c = data.criterio;
                    document.querySelector('input[name="codigo"]').value = c.codigo || '';
                    document.querySelector('input[name="nome"]').value = c.nome || '';
                    document.querySelector('textarea[name="descricao"]').value = c.descricao || '';
                    document.querySelector('select[name="tipo"]').value = c.tipo || 'prova';
                    document.querySelector('input[name="peso"]').value = c.peso || '1.0';
                    document.querySelector('input[name="nota_maxima"]').value = c.nota_maxima || '20.0';
                    document.querySelector('input[name="nota_minima_aprovacao"]').value = c.nota_minima_aprovacao || '10.0';
                    document.querySelector('select[name="periodo"]').value = c.periodo || 'bimestral';
                    document.querySelector('input[name="quantidade_avaliacoes"]').value = c.quantidade_avaliacoes || '1';
                    document.querySelector('textarea[name="instrucoes"]').value = c.instrucoes || '';
                    document.querySelector('select[name="status"]').value = c.status !== undefined ? c.status : '1';
                    
                    if (c.permite_recuperacao == 1) {
                        document.getElementById('permite_recuperacao').checked = true;
                    } else {
                        document.getElementById('permite_recuperacao').checked = false;
                    }
                    
                    document.getElementById('modalCriterio').style.display = 'block';
                } else {
                    alert('Erro: ' + (data.message || 'Critério não encontrado'));
                }
            })
            .catch(error => {
                console.error('Erro detalhado:', error);
                alert('Erro ao carregar dados do critério. Verifique o console para mais detalhes.\n\n' + error.message);
            });
    }
    
    function excluirCriterio(id, nome) {
        if (confirm(`Tem certeza que deseja excluir o critério "${nome}"?\n\nEsta ação não pode ser desfeita.`)) {
            window.location.href = '?action=excluir&id=' + id;
        }
    }
    
    function fecharModal() {
        document.getElementById('modalCriterio').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalCriterio');
        if (event.target == modal) {
            fecharModal();
        }
    }
    
    console.log('Script de Critérios de Avaliação carregado com sucesso!');
</script>
</body>
</html>