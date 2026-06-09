<?php
// escola/pedagogico/classes.php - Gestão de Classes/Níveis de Ensino

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

// ============================================
// PROCESSAR AJAX PARA BUSCAR CLASSE
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT c.*, n.nome as nivel_nome, n.sigla as nivel_sigla 
                FROM classes c
                LEFT JOIN niveis n ON n.id = c.nivel_id
                WHERE c.id = :id AND c.escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $classe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($classe) {
            echo json_encode(['success' => true, 'classe' => $classe]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Classe não encontrada']);
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
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `descricao` TEXT,
    `ordem` INT DEFAULT 0,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    `nivel_id` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_codigo_escola` (`escola_id`, `codigo`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_ordem` (`ordem`),
    KEY `idx_status` (`status`),
    KEY `idx_nivel_id` (`nivel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// FUNÇÕES PARA GERAR CÓDIGO E ORDEM AUTOMÁTICOS
// ============================================

function gerarProximaOrdem($conn, $escola_id) {
    $sql = "SELECT MAX(ordem) as max_ordem FROM classes WHERE escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $proxima_ordem = ($result['max_ordem'] ?? 0) + 1;
    return $proxima_ordem;
}

function gerarCodigoAutomatico($conn, $escola_id, $nome = null) {
    $sql = "SELECT codigo FROM classes WHERE escola_id = :escola_id ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $prefixo = 'CLS';
    
    if ($nome && preg_match('/(\d+)/', $nome, $matches)) {
        $numero = $matches[1];
        $prefixo = $numero . 'ANO';
    }
    
    if ($ultimo && preg_match('/(\d+)$/', $ultimo['codigo'], $matches)) {
        $ultimo_numero = (int)$matches[1];
        $novo_numero = $ultimo_numero + 1;
        $novo_codigo = $prefixo . str_pad($novo_numero, 3, '0', STR_PAD_LEFT);
    } else {
        $novo_codigo = $prefixo . '001';
    }
    
    // Verificar se o código já existe
    $sql_check = "SELECT id FROM classes WHERE escola_id = :escola_id AND codigo = :codigo";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $novo_codigo]);
    
    if ($stmt_check->fetch()) {
        $novo_codigo = $prefixo . time();
    }
    
    return $novo_codigo;
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

$mensagem = '';
$erro = '';

// Inserir nova classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $descricao = trim($_POST['descricao']);
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $status = $_POST['status'];
    
    if (empty($nome)) {
        $erro = "O nome da classe é obrigatório.";
    } else {
        $ordem = gerarProximaOrdem($conn, $escola_id);
        $codigo = gerarCodigoAutomatico($conn, $escola_id, $nome);
        
        $sql = "INSERT INTO classes (escola_id, nome, codigo, descricao, ordem, nivel_id, status, created_at) 
                VALUES (:escola_id, :nome, :codigo, :descricao, :ordem, :nivel_id, :status, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':descricao' => $descricao,
            ':ordem' => $ordem,
            ':nivel_id' => $nivel_id,
            ':status' => $status
        ]);
        
        $mensagem = "Classe cadastrada com sucesso! Código: $codigo | Ordem: $ordem";
    }
}

// Atualizar classe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $descricao = trim($_POST['descricao']);
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $status = $_POST['status'];
    
    // Buscar dados atuais para manter código e ordem
    $sql_get = "SELECT codigo, ordem FROM classes WHERE id = :id AND escola_id = :escola_id";
    $stmt_get = $conn->prepare($sql_get);
    $stmt_get->execute([':id' => $id, ':escola_id' => $escola_id]);
    $dados = $stmt_get->fetch(PDO::FETCH_ASSOC);
    
    if (empty($nome)) {
        $erro = "O nome da classe é obrigatório.";
    } elseif (!$dados) {
        $erro = "Classe não encontrada.";
    } else {
        $sql = "UPDATE classes SET 
                nome = :nome,
                descricao = :descricao,
                nivel_id = :nivel_id,
                status = :status,
                updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':nivel_id' => $nivel_id,
            ':status' => $status,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem = "Classe atualizada com sucesso!";
    }
}

// Reordenar classes (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'reordenar') {
    ob_clean();
    header('Content-Type: application/json');
    
    $ordenacao = json_decode($_POST['ordenacao'], true);
    
    if ($ordenacao) {
        try {
            foreach ($ordenacao as $item) {
                $sql = "UPDATE classes SET ordem = :ordem WHERE id = :id AND escola_id = :escola_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':ordem' => $item['ordem'],
                    ':id' => $item['id'],
                    ':escola_id' => $escola_id
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Ordenação atualizada com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao reordenar: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Nenhum dado recebido']);
    }
    exit;
}

// Excluir classe
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se existem alunos associados
    $sql_check_alunos = "SELECT COUNT(*) as total FROM matriculas WHERE classe_id = :classe_id";
    $stmt_check_alunos = $conn->prepare($sql_check_alunos);
    $stmt_check_alunos->execute([':classe_id' => $id]);
    $total_alunos = $stmt_check_alunos->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Verificar se existem turmas associadas
    $sql_check_turmas = "SELECT COUNT(*) as total FROM turmas WHERE classe_id = :classe_id";
    $stmt_check_turmas = $conn->prepare($sql_check_turmas);
    $stmt_check_turmas->execute([':classe_id' => $id]);
    $total_turmas = $stmt_check_turmas->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_alunos > 0 || $total_turmas > 0) {
        $erro = "Não é possível excluir esta classe pois existem ";
        if ($total_alunos > 0) $erro .= "$total_alunos aluno(s)";
        if ($total_alunos > 0 && $total_turmas > 0) $erro .= " e ";
        if ($total_turmas > 0) $erro .= "$total_turmas turma(s)";
        $erro .= " associados.";
    } else {
        $sql = "DELETE FROM classes WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Classe excluída com sucesso!";
    }
}

// ============================================
// BUSCAR NÍVEIS DE ENSINO
// ============================================
$sql_niveis = "SELECT id, nome, sigla, descricao, ordem, duracao_anos 
               FROM niveis 
               WHERE escola_id = :escola_id AND status = 'ativo' 
               ORDER BY ordem ASC, nome ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR CLASSES
// ============================================
$sql_classes = "SELECT c.*, n.nome as nivel_nome, n.sigla as nivel_sigla
               FROM classes c
               LEFT JOIN niveis n ON n.id = c.nivel_id
               WHERE c.escola_id = :escola_id 
               ORDER BY c.ordem ASC, c.nome ASC";
$stmt_classes = $conn->prepare($sql_classes);
$stmt_classes->execute([':escola_id' => $escola_id]);
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

$total_classes = count($classes);
$classes_ativas = 0;

foreach ($classes as $c) {
    if ($c['status'] == 'ativo') $classes_ativas++;
}

// Gerar próximo código e ordem para novo cadastro
$proximo_codigo = gerarCodigoAutomatico($conn, $escola_id);
$proxima_ordem = gerarProximaOrdem($conn, $escola_id);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f0f2f5 0%, #e8ecf1 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 24px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .header h1 { font-size: 28px; margin-bottom: 5px; font-weight: 700; }
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
        
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 1px; }
        .stat-total .stat-number { color: #1A2A6C; }
        .stat-ativos .stat-number { color: #006B3E; }
        
        .card {
            background: white;
            border-radius: 24px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .card:hover { transform: translateY(-4px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        
        .card-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body { padding: 0; }
        
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
        
        .btn-reordenar {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        
        .btn-reordenar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(23,162,184,0.3); }
        
        .btn-gerenciar-niveis {
            background: linear-gradient(135deg, #e67e22, #d35400);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        
        .table-classes { width: 100%; border-collapse: collapse; }
        
        .table-classes th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #006B3E;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-classes td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        
        .table-classes tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
        .ordem-badge {
            display: inline-block;
            width: 35px;
            height: 35px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 35px;
            font-weight: bold;
            cursor: move;
            transition: all 0.3s ease;
        }
        
        .ordem-badge:hover {
            transform: scale(1.1);
            background: #004d2e;
        }
        
        .badge-nivel {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            background: #e8f4f8;
            color: #006B3E;
        }
        
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
            backdrop-filter: blur(4px);
        }
        
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 600px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-custom-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-custom-header h3 { font-size: 20px; margin: 0; }
        .close-modal { font-size: 28px; font-weight: bold; cursor: pointer; transition: 0.3s; }
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
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        
        textarea.form-control { resize: vertical; min-height: 80px; }
        
        .codigo-preview, .ordem-preview {
            font-size: 12px;
            padding: 8px 15px;
            background: #e8f4f8;
            border-radius: 12px;
            margin-top: 5px;
        }
        
        .codigo-preview i, .ordem-preview i {
            color: #006B3E;
            margin-right: 5px;
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
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-classes { font-size: 11px; }
            .table-classes th, .table-classes td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-layer-group"></i> Classes</h1>
            <p>Gestão das classes/níveis de ensino da escola</p>
        </div>
        <div>
            <a href="niveis_ensino.php" class="btn-gerenciar-niveis"><i class="fas fa-cog"></i> Gerenciar Níveis</a>
            <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_classes; ?></div>
            <div class="stat-label">Total de Classes</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $classes_ativas; ?></div>
            <div class="stat-label">Classes Ativas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($niveis); ?></div>
            <div class="stat-label">Níveis Cadastrados</div>
        </div>
    </div>
    
    <!-- Lista de Classes -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Classes Cadastradas
            <span class="badge bg-light text-dark ms-2"><?php echo $total_classes; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Nova Classe</button>
        </div>
        <div class="card-body">
            <?php if (empty($classes)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-layer-group fa-3x mb-3"></i>
                    <p>Nenhuma classe cadastrada.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeira classe</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-classes">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Ordem</th>
                                <th>Código</th>
                                <th>Nome</th>
                                <th>Nível de Ensino</th>
                                <th>Status</th>
                                <th style="width: 120px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $classe): ?>
                                <tr data-id="<?php echo $classe['id']; ?>">
                                    <td class="text-center">
                                        <span class="ordem-badge"><?php echo $classe['ordem']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <strong><?php echo htmlspecialchars($classe['codigo']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($classe['nome']); ?></strong>
                                        <?php if ($classe['descricao']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($classe['descricao'], 0, 50)) . (strlen($classe['descricao']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($classe['nivel_id'] && $classe['nivel_nome']): ?>
                                            <span class="badge-nivel">
                                                <?php echo htmlspecialchars($classe['nivel_nome']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($classe['status'] == 'ativa'): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarClasse(<?php echo $classe['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="excluirClasse(<?php echo $classe['id']; ?>, '<?php echo htmlspecialchars(addslashes($classe['nome'])); ?>')">
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

<!-- Modal Nova/Editar Classe -->
<div id="modalClasse" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Nova Classe</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formClasse">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="classe_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Código <span class="text-muted">(Gerado automaticamente)</span></label>
                            <div class="codigo-preview" id="codigoPreview">
                                <i class="fas fa-code"></i> <span id="codigoDisplay"><?php echo $proximo_codigo; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Ordem <span class="text-muted">(Gerada automaticamente)</span></label>
                            <div class="ordem-preview" id="ordemPreview">
                                <i class="fas fa-sort-numeric-down"></i> <span id="ordemDisplay"><?php echo $proxima_ordem; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome da Classe *</label>
                    <input type="text" name="nome" id="nomeClasse" class="form-control" required placeholder="Ex: 1ª Classe, 1º Ano">
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Nível de Ensino</label>
                            <select name="nivel_id" id="nivelSelect" class="form-select">
                                <option value="">Selecione um nível</option>
                                <?php foreach ($niveis as $nivel): ?>
                                    <option value="<?php echo $nivel['id']; ?>">
                                        <?php echo htmlspecialchars($nivel['nome']); ?> 
                                        (<?php echo htmlspecialchars($nivel['sigla']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="1">Ativa</option>
                                <option value="2">Inativa</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" id="descricaoClasse" class="form-control" rows="3" placeholder="Descreva as características desta classe"></textarea>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informações importantes:</strong>
                    <ul class="mb-0 mt-2">
                        <li>✅ O <strong>código</strong> e a <strong>ordem</strong> são gerados automaticamente</li>
                        <li>📌 A ordem determina a sequência das classes</li>
                        <li>📚 O nível de ensino é opcional e pode ser definido posteriormente</li>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        if (toast) {
            toast.textContent = message;
            toast.style.backgroundColor = isError ? '#dc3545' : '#27ae60';
            toast.style.display = 'block';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Nova Classe';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('classe_id').value = '0';
        document.getElementById('formClasse').reset();
        document.querySelector('select[name="status"]').value = 'ativo';
        document.getElementById('nivelSelect').value = '';
        document.getElementById('descricaoClasse').value = '';
        document.getElementById('nomeClasse').value = '';
        
        // Mostrar códigos gerados
        document.getElementById('codigoDisplay').innerHTML = '<?php echo $proximo_codigo; ?>';
        document.getElementById('ordemDisplay').innerHTML = '<?php echo $proxima_ordem; ?>';
        
        document.getElementById('modalClasse').style.display = 'block';
    }
    
    function editarClasse(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Classe';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('classe_id').value = id;
        
        showToast('Carregando dados da classe...');
        
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
                    const c = data.classe;
                    document.getElementById('nomeClasse').value = c.nome || '';
                    document.getElementById('nivelSelect').value = c.nivel_id || '';
                    document.getElementById('descricaoClasse').value = c.descricao || '';
                    document.querySelector('select[name="status"]').value = c.status || 'ativo';
                    
                    // Mostrar código e ordem (apenas leitura na edição)
                    document.getElementById('codigoDisplay').innerHTML = c.codigo || '';
                    document.getElementById('ordemDisplay').innerHTML = c.ordem || '';
                    
                    document.getElementById('modalClasse').style.display = 'block';
                } else {
                    alert('Erro: ' + (data.message || 'Classe não encontrada'));
                }
            })
            .catch(error => {
                console.error('Erro detalhado:', error);
                alert('Erro ao carregar dados da classe. Verifique o console para mais detalhes.\n\n' + error.message);
            });
    }
    
    function excluirClasse(id, nome) {
        if (confirm(`Tem certeza que deseja excluir a classe "${nome}"?\n\nEsta ação não pode ser desfeita.`)) {
            window.location.href = '?action=excluir&id=' + id;
        }
    }
    
    function fecharModal() {
        document.getElementById('modalClasse').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalClasse');
        if (event.target == modal) {
            fecharModal();
        }
    }
    
    console.log('Script de Classes carregado com sucesso!');
</script>
</body>
</html>