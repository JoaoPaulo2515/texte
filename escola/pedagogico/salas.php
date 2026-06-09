<?php
// escola/pedagogico/salas.php - Gestão de Salas (COM MODAIS CORRIGIDOS - VERSÃO FINAL)

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
// PROCESSAR AJAX PARA BUSCAR SALA
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM salas WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $sala = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sala) {
            echo json_encode(['success' => true, 'sala' => $sala]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sala não encontrada']);
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
CREATE TABLE IF NOT EXISTS `salas` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `codigo` VARCHAR(20) NOT NULL,
    `tipo` ENUM('sala_aula', 'laboratorio', 'auditorio', 'biblioteca', 'sala_informatica', 'sala_professores', 'secretaria', 'outro') DEFAULT 'sala_aula',
    `capacidade` INT DEFAULT 0,
    `localizacao` VARCHAR(100) DEFAULT NULL,
    `bloco` VARCHAR(50) DEFAULT NULL,
    `andar` INT DEFAULT NULL,
    `recursos` TEXT,
    `responsavel` VARCHAR(100) DEFAULT NULL,
    `telefone_ramal` VARCHAR(20) DEFAULT NULL,
    `escola_id` INT NOT NULL,
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_codigo_escola` (`escola_id`, `codigo`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarProximoCodigo($conn, $escola_id, $prefixo = 'SALA') {
    $sql = "SELECT codigo FROM salas WHERE escola_id = :escola_id AND codigo LIKE :prefixo ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':prefixo' => $prefixo . '%'
    ]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        $ultimo_codigo = $ultimo['codigo'];
        if (preg_match('/(\d+)$/', $ultimo_codigo, $matches)) {
            $numero = (int)$matches[1] + 1;
            return $prefixo . str_pad($numero, 3, '0', STR_PAD_LEFT);
        }
    }
    
    return $prefixo . '001';
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

$mensagem = '';
$erro = '';

// Inserir nova sala
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $tipo = $_POST['tipo'];
    $capacidade = !empty($_POST['capacidade']) ? (int)$_POST['capacidade'] : 0;
    $localizacao = trim($_POST['localizacao']);
    $bloco = trim($_POST['bloco']);
    $andar = !empty($_POST['andar']) ? (int)$_POST['andar'] : null;
    $recursos = trim($_POST['recursos']);
    $responsavel = trim($_POST['responsavel']);
    $telefone_ramal = trim($_POST['telefone_ramal']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Gerar código automaticamente
    $codigo = gerarProximoCodigo($conn, $escola_id);
    
    // Verificar se já existe sala com este código
    $sql_check = "SELECT id FROM salas WHERE escola_id = :escola_id AND codigo = :codigo";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe uma sala cadastrada com o código '$codigo'.";
    } else {
        $sql = "INSERT INTO salas (escola_id, nome, codigo, tipo, capacidade, localizacao, bloco, andar, recursos, responsavel, telefone_ramal, status) 
                VALUES (:escola_id, :nome, :codigo, :tipo, :capacidade, :localizacao, :bloco, :andar, :recursos, :responsavel, :telefone_ramal, :status)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':tipo' => $tipo,
            ':capacidade' => $capacidade,
            ':localizacao' => $localizacao,
            ':bloco' => $bloco,
            ':andar' => $andar,
            ':recursos' => $recursos,
            ':responsavel' => $responsavel,
            ':telefone_ramal' => $telefone_ramal,
            ':status' => $status
        ]);
        
        $mensagem = "Sala cadastrada com sucesso! Código: $codigo";
    }
}

// Atualizar sala
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $codigo = trim($_POST['codigo']); // Agora vem do campo hidden
    $tipo = $_POST['tipo'];
    $capacidade = !empty($_POST['capacidade']) ? (int)$_POST['capacidade'] : 0;
    $localizacao = trim($_POST['localizacao']);
    $bloco = trim($_POST['bloco']);
    $andar = !empty($_POST['andar']) ? (int)$_POST['andar'] : null;
    $recursos = trim($_POST['recursos']);
    $responsavel = trim($_POST['responsavel']);
    $telefone_ramal = trim($_POST['telefone_ramal']);
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Verificar se já existe outra sala com este código
    $sql_check = "SELECT id FROM salas WHERE escola_id = :escola_id AND codigo = :codigo AND id != :id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':codigo' => $codigo, ':id' => $id]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe uma sala cadastrada com o código '$codigo'.";
    } else {
        $sql = "UPDATE salas SET 
                nome = :nome,
                codigo = :codigo,
                tipo = :tipo,
                capacidade = :capacidade,
                localizacao = :localizacao,
                bloco = :bloco,
                andar = :andar,
                recursos = :recursos,
                responsavel = :responsavel,
                telefone_ramal = :telefone_ramal,
                status = :status
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':tipo' => $tipo,
            ':capacidade' => $capacidade,
            ':localizacao' => $localizacao,
            ':bloco' => $bloco,
            ':andar' => $andar,
            ':recursos' => $recursos,
            ':responsavel' => $responsavel,
            ':telefone_ramal' => $telefone_ramal,
            ':status' => $status,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem = "Sala atualizada com sucesso!";
    }
}

// Excluir sala
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se existem turmas associadas a esta sala
    $sql_check = "SELECT COUNT(*) as total FROM turmas WHERE sala_id = :sala_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':sala_id' => $id]);
    $total_turmas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_turmas > 0) {
        $erro = "Não é possível excluir esta sala pois existem $total_turmas turmas associadas.";
    } else {
        $sql = "DELETE FROM salas WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Sala excluída com sucesso!";
    }
}

// ============================================
// BUSCAR SALAS
// ============================================
$sql_salas = "SELECT * FROM salas WHERE escola_id = :escola_id ORDER BY bloco, andar, nome ASC";
$stmt_salas = $conn->prepare($sql_salas);
$stmt_salas->execute([':escola_id' => $escola_id]);
$salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

$total_salas = count($salas);
$salas_ativas = 0;
$capacidade_total = 0;

foreach ($salas as $s) {
    if ($s['status'] == 1) $salas_ativas++;
    $capacidade_total += $s['capacidade'];
}

// Gerar próximo código para novo cadastro
$proximo_codigo = gerarProximoCodigo($conn, $escola_id);

// Tipos de sala para o select
$tipos_sala = [
    'sala_aula' => 'Sala de Aula',
    'laboratorio' => 'Laboratório',
    'auditorio' => 'Auditório',
    'biblioteca' => 'Biblioteca',
    'sala_informatica' => 'Sala de Informática',
    'sala_professores' => 'Sala dos Professores',
    'secretaria' => 'Secretaria',
    'outro' => 'Outro'
];
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salas - SIGE Angola</title>
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
        .stat-capacidade .stat-number { color: #e67e22; }
        
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
        
        .table-salas { width: 100%; border-collapse: collapse; }
        .table-salas th {
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
        .table-salas td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-salas tr:hover { background: #f8f9fa; }
        
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
        .badge-sala_aula { background: #3498db; color: white; }
        .badge-laboratorio { background: #9b59b6; color: white; }
        .badge-auditorio { background: #e74c3c; color: white; }
        .badge-biblioteca { background: #2ecc71; color: white; }
        .badge-sala_informatica { background: #f39c12; color: white; }
        .badge-sala_professores { background: #1abc9c; color: white; }
        .badge-secretaria { background: #34495e; color: white; }
        
        .codigo-preview {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, #e8f4f8, #d4edda);
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            color: #1e5799;
            margin-top: 5px;
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
        
        /* Modais Globais */
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
            max-width: 800px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-custom-header {
            padding: 20px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-custom-header.modal-danger { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .modal-custom-header.modal-success { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; }
        .modal-custom-header.modal-info { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        
        .modal-custom-header h3 { font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .close-modal:hover { background: rgba(255,255,255,0.2); }
        
        .modal-custom-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-custom-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
            background: white;
            border-radius: 0 0 20px 20px;
        }
        
        .modal-custom-body p { font-size: 15px; line-height: 1.5; color: #333; margin-bottom: 0; }
        .modal-custom-body .modal-details { 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 12px; 
            font-size: 13px; 
            color: #666;
            border-left: 3px solid #dc3545;
            margin-top: 15px;
        }
        
        .btn-modal-cancelar {
            background: #6c757d;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-cancelar:hover { background: #5a6268; transform: translateY(-1px); }
        
        .btn-modal-confirmar {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-confirmar:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(220,53,69,0.3); }
        
        .btn-modal-ok {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            padding: 8px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-ok:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(40,167,69,0.3); }
        
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
        .btn-cancelar-form {
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
        .btn-cancelar-form:hover { transform: translateY(-2px); background: #5a6268; }
        
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
            .table-salas { font-size: 11px; }
            .table-salas th, .table-salas td { padding: 8px; }
            .modal-custom-content { margin: 10% auto; width: 95%; max-height: 85vh; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-door-open"></i> Salas</h1>
            <p>Gestão de salas, laboratórios e espaços físicos da escola</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_salas; ?></div>
            <div class="stat-label">Total de Salas</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $salas_ativas; ?></div>
            <div class="stat-label">Salas Ativas</div>
        </div>
        <div class="stat-card stat-capacidade">
            <div class="stat-number"><?php echo $capacidade_total; ?></div>
            <div class="stat-label">Capacidade Total</div>
        </div>
    </div>
    
    <!-- Lista de Salas -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Salas Cadastradas
            <span class="badge bg-light text-dark ms-2"><?php echo $total_salas; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Nova Sala</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($salas)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-door-open fa-3x mb-3"></i>
                    <p>Nenhuma sala cadastrada.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeira sala</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-salas">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Capacidade</th>
                                <th>Localização</th>
                                <th>Responsável</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salas as $sala): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($sala['codigo']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($sala['nome']); ?></strong>
                                        <?php if ($sala['bloco'] || $sala['andar']): ?>
                                            <br><small class="text-muted">
                                                <?php 
                                                $local = [];
                                                if ($sala['bloco']) $local[] = "Bloco " . htmlspecialchars($sala['bloco']);
                                                if ($sala['andar']) $local[] = $sala['andar'] . "º andar";
                                                echo implode(' - ', $local);
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $classe_tipo = 'badge-' . str_replace('_', '', $sala['tipo']);
                                        $nome_tipo = $tipos_sala[$sala['tipo']] ?? ucfirst(str_replace('_', ' ', $sala['tipo']));
                                        ?>
                                        <span class="badge-tipo <?php echo $classe_tipo; ?>"><?php echo $nome_tipo; ?></span>
                                    </td>
                                    <td class="text-center"><?php echo $sala['capacidade'] ?: '-'; ?></td>
                                    <td><?php echo htmlspecialchars($sala['localizacao'] ?: '-'); ?></td>
                                    <td>
                                        <?php if ($sala['responsavel']): ?>
                                            <?php echo htmlspecialchars($sala['responsavel']); ?>
                                            <?php if ($sala['telefone_ramal']): ?>
                                                <br><small class="text-muted">Ramal: <?php echo htmlspecialchars($sala['telefone_ramal']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($sala['status'] == 1): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarSala(<?php echo $sala['id']; ?>)"><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn-acao btn-excluir" onclick="confirmarExclusao(<?php echo $sala['id']; ?>, '<?php echo htmlspecialchars(addslashes($sala['nome'])); ?>')"><i class="fas fa-trash"></i> Excluir</button>
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

<!-- ============================================ -->
<!-- MODAL DE CONFIRMAÇÃO DE EXCLUSÃO -->
<!-- ============================================ -->
<div id="modalConfirmacao" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h3>
            <span class="close-modal" onclick="fecharModalConfirmacao()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemConfirmacao" class="modal-message"></p>
            <div id="detalhesConfirmacao" class="modal-details"></div>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-cancelar" onclick="fecharModalConfirmacao()">Cancelar</button>
            <button class="btn-modal-confirmar" id="btnConfirmarExclusao">Confirmar Exclusão</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL DE INFORMAÇÃO/SUCESSO -->
<!-- ============================================ -->
<div id="modalInfo" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-success">
            <h3><i class="fas fa-check-circle"></i> Sucesso!</h3>
            <span class="close-modal" onclick="fecharModalInfo()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemInfo"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalInfo()">OK</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL DE ERRO -->
<!-- ============================================ -->
<div id="modalErro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-times-circle"></i> Erro!</h3>
            <span class="close-modal" onclick="fecharModalErro()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemErro"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalErro()">OK</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL NOVA/EDITAR SALA -->
<!-- ============================================ -->
<div id="modalSala" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-info">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Nova Sala</h3>
            <span class="close-modal" onclick="fecharModalSala()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formSala">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="sala_id" value="0">
                <input type="hidden" name="codigo" id="campo_codigo_hidden" value="">
                
                <div class="form-group">
                    <label class="form-label">Código da Sala <span class="text-muted">(Gerado automaticamente)</span></label>
                    <div class="codigo-preview">
                        <i class="fas fa-code"></i> <span id="codigoDisplay"><?php echo $proximo_codigo; ?></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Tipo de Sala *</label>
                            <select name="tipo" id="campo_tipo" class="form-select" required>
                                <?php foreach ($tipos_sala as $valor => $nome): ?>
                                    <option value="<?php echo $valor; ?>"><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" id="campo_status" class="form-select">
                                <option value="1">Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome da Sala *</label>
                    <input type="text" name="nome" id="campo_nome" class="form-control" required placeholder="Ex: Sala 101, Laboratório de Química">
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Capacidade (pessoas)</label>
                            <input type="number" name="capacidade" id="campo_capacidade" class="form-control" placeholder="Ex: 40" min="0">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Bloco</label>
                            <input type="text" name="bloco" id="campo_bloco" class="form-control" placeholder="Ex: A, B, Principal">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Andar</label>
                            <input type="number" name="andar" id="campo_andar" class="form-control" placeholder="Ex: 1, 2" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Localização / Observações</label>
                    <input type="text" name="localizacao" id="campo_localizacao" class="form-control" placeholder="Ex: Prédio Principal, Ala Norte">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Responsável</label>
                            <input type="text" name="responsavel" id="campo_responsavel" class="form-control" placeholder="Nome do responsável pela sala">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Telefone / Ramal</label>
                            <input type="text" name="telefone_ramal" id="campo_telefone" class="form-control" placeholder="Ex: 2100">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Recursos Disponíveis</label>
                    <textarea name="recursos" id="campo_recursos" class="form-control" rows="2" placeholder="Ex: Projetor, Quadro branco, Ar condicionado, Computadores, etc."></textarea>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informações importantes:</strong>
                    <ul class="mb-0 mt-2">
                        <li>✨ O <strong>código</strong> é gerado automaticamente (sequencial)</li>
                        <li>📌 O código deve ser único por escola</li>
                        <li>🔢 A capacidade ajuda no planejamento de turmas</li>
                        <li>📍 A localização facilita a identificação da sala</li>
                    </ul>
                </div>
                
                <div class="text-end mt-3">
                    <button type="button" class="btn-cancelar-form" onclick="fecharModalSala()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    var idParaExcluir = null;
    var nomeParaExcluir = null;
    
    // ============================================
    // MODAL DE CONFIRMAÇÃO DE EXCLUSÃO
    // ============================================
    function confirmarExclusao(id, nome) {
        idParaExcluir = id;
        nomeParaExcluir = nome;
        
        document.getElementById('mensagemConfirmacao').innerHTML = 'Tem certeza que deseja excluir a sala <strong>"' + nome + '"</strong>?';
        document.getElementById('detalhesConfirmacao').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita.';
        document.getElementById('modalConfirmacao').style.display = 'block';
    }
    
    function fecharModalConfirmacao() {
        document.getElementById('modalConfirmacao').style.display = 'none';
        idParaExcluir = null;
        nomeParaExcluir = null;
    }
    
    document.getElementById('btnConfirmarExclusao').onclick = function() {
        if (idParaExcluir) {
            window.location.href = '?action=excluir&id=' + idParaExcluir;
        }
        fecharModalConfirmacao();
    };
    
    // ============================================
    // MODAL DE INFORMAÇÃO
    // ============================================
    function showModalInfo(mensagem) {
        document.getElementById('mensagemInfo').innerHTML = mensagem;
        document.getElementById('modalInfo').style.display = 'block';
        // Auto-fechar após 2 segundos
        setTimeout(function() {
            if (document.getElementById('modalInfo').style.display === 'block') {
                fecharModalInfo();
            }
        }, 2000);
    }
    
    function fecharModalInfo() {
        document.getElementById('modalInfo').style.display = 'none';
    }
    
    // ============================================
    // MODAL DE ERRO
    // ============================================
    function showModalErro(mensagem) {
        document.getElementById('mensagemErro').innerHTML = mensagem;
        document.getElementById('modalErro').style.display = 'block';
    }
    
    function fecharModalErro() {
        document.getElementById('modalErro').style.display = 'none';
    }
    
    // ============================================
    // FUNÇÕES DO FORMULÁRIO
    // ============================================
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Nova Sala';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('sala_id').value = '0';
        document.getElementById('formSala').reset();
        document.getElementById('campo_status').value = '1';
        document.getElementById('campo_tipo').value = 'sala_aula';
        document.getElementById('codigoDisplay').innerHTML = '<?php echo $proximo_codigo; ?>';
        document.getElementById('campo_codigo_hidden').value = '<?php echo $proximo_codigo; ?>';
        document.getElementById('modalSala').style.display = 'block';
    }
    
    function editarSala(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Sala';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('sala_id').value = id;
        
        showModalInfo('Carregando dados da sala...');
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { ajax: 1, id: id },
            dataType: 'json',
            success: function(data) {
                fecharModalInfo();
                if (data.success) {
                    var s = data.sala;
                    document.getElementById('campo_nome').value = s.nome || '';
                    document.getElementById('campo_tipo').value = s.tipo || 'sala_aula';
                    document.getElementById('campo_capacidade').value = s.capacidade || '';
                    document.getElementById('campo_bloco').value = s.bloco || '';
                    document.getElementById('campo_andar').value = s.andar || '';
                    document.getElementById('campo_localizacao').value = s.localizacao || '';
                    document.getElementById('campo_responsavel').value = s.responsavel || '';
                    document.getElementById('campo_telefone').value = s.telefone_ramal || '';
                    document.getElementById('campo_recursos').value = s.recursos || '';
                    document.getElementById('campo_status').value = s.status !== undefined ? s.status : '1';
                    document.getElementById('codigoDisplay').innerHTML = s.codigo || '';
                    document.getElementById('campo_codigo_hidden').value = s.codigo || '';
                    
                    document.getElementById('modalSala').style.display = 'block';
                } else {
                    showModalErro(data.message || 'Sala não encontrada');
                }
            },
            error: function(xhr, status, error) {
                fecharModalInfo();
                showModalErro('Erro ao carregar dados da sala: ' + error);
            }
        });
    }
    
    function fecharModalSala() {
        document.getElementById('modalSala').style.display = 'none';
    }
    
    // Fechar modais ao clicar fora
    window.onclick = function(event) {
        var modalConfirmacao = document.getElementById('modalConfirmacao');
        var modalInfo = document.getElementById('modalInfo');
        var modalErro = document.getElementById('modalErro');
        var modalSala = document.getElementById('modalSala');
        
        if (event.target == modalConfirmacao) fecharModalConfirmacao();
        if (event.target == modalInfo) fecharModalInfo();
        if (event.target == modalErro) fecharModalErro();
        if (event.target == modalSala) fecharModalSala();
    }
</script>
</body>
</html>