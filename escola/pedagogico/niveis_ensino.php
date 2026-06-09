<?php
// escola/pedagogico/niveis_ensino.php - Gestão de Níveis de Ensino (VERSÃO CORRIGIDA)

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
// PROCESSAR AJAX PARA BUSCAR NÍVEL
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM niveis WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($nivel) {
            echo json_encode(['success' => true, 'nivel' => $nivel]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Nível de ensino não encontrado']);
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
CREATE TABLE IF NOT EXISTS `niveis` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `sigla` VARCHAR(20) NOT NULL,
    `descricao` TEXT,
    `ordem` INT DEFAULT 0,
    `idade_minima` INT DEFAULT NULL,
    `idade_maxima` INT DEFAULT NULL,
    `duracao_anos` INT DEFAULT NULL,
    `escola_id` INT NOT NULL,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_sigla_escola` (`escola_id`, `sigla`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_ordem` (`ordem`),
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

// Inserir novo nível
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $descricao = trim($_POST['descricao']);
    $ordem = !empty($_POST['ordem']) ? (int)$_POST['ordem'] : 0;
    $idade_minima = !empty($_POST['idade_minima']) ? (int)$_POST['idade_minima'] : null;
    $idade_maxima = !empty($_POST['idade_maxima']) ? (int)$_POST['idade_maxima'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $status = $_POST['status'];
    
    $sql_check = "SELECT id FROM niveis WHERE escola_id = :escola_id AND sigla = :sigla";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':sigla' => $sigla]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um nível de ensino cadastrado com a sigla '$sigla'.";
    } else {
        $sql = "INSERT INTO niveis (escola_id, nome, sigla, descricao, ordem, idade_minima, idade_maxima, duracao_anos, status) 
                VALUES (:escola_id, :nome, :sigla, :descricao, :ordem, :idade_minima, :idade_maxima, :duracao_anos, :status)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':sigla' => $sigla,
            ':descricao' => $descricao,
            ':ordem' => $ordem,
            ':idade_minima' => $idade_minima,
            ':idade_maxima' => $idade_maxima,
            ':duracao_anos' => $duracao_anos,
            ':status' => $status
        ]);
        $mensagem = "Nível de ensino cadastrado com sucesso!";
    }
}

// Atualizar nível
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $descricao = trim($_POST['descricao']);
    $ordem = !empty($_POST['ordem']) ? (int)$_POST['ordem'] : 0;
    $idade_minima = !empty($_POST['idade_minima']) ? (int)$_POST['idade_minima'] : null;
    $idade_maxima = !empty($_POST['idade_maxima']) ? (int)$_POST['idade_maxima'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $status = $_POST['status'];
    
    $sql_check = "SELECT id FROM niveis WHERE escola_id = :escola_id AND sigla = :sigla AND id != :id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':sigla' => $sigla, ':id' => $id]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um nível de ensino cadastrado com a sigla '$sigla'.";
    } else {
        $sql = "UPDATE niveis SET 
                nome = :nome,
                sigla = :sigla,
                descricao = :descricao,
                ordem = :ordem,
                idade_minima = :idade_minima,
                idade_maxima = :idade_maxima,
                duracao_anos = :duracao_anos,
                status = :status
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':sigla' => $sigla,
            ':descricao' => $descricao,
            ':ordem' => $ordem,
            ':idade_minima' => $idade_minima,
            ':idade_maxima' => $idade_maxima,
            ':duracao_anos' => $duracao_anos,
            ':status' => $status,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        $mensagem = "Nível de ensino atualizado com sucesso!";
    }
}

// Excluir nível
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $sql_check_cursos = "SELECT COUNT(*) as total FROM cursos WHERE nivel_id = :nivel_id";
    $stmt_check_cursos = $conn->prepare($sql_check_cursos);
    $stmt_check_cursos->execute([':nivel_id' => $id]);
    $total_cursos = $stmt_check_cursos->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql_check_classes = "SELECT COUNT(*) as total FROM classes WHERE nivel_id = :nivel_id";
    $stmt_check_classes = $conn->prepare($sql_check_classes);
    $stmt_check_classes->execute([':nivel_id' => $id]);
    $total_classes = $stmt_check_classes->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_cursos > 0 || $total_classes > 0) {
        $erro = "Não é possível excluir este nível pois existem $total_cursos curso(s) e $total_classes classe(s) associados.";
    } else {
        $sql = "DELETE FROM niveis WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Nível de ensino excluído com sucesso!";
    }
}

// Buscar níveis
$sql_niveis = "SELECT * FROM niveis WHERE escola_id = :escola_id ORDER BY ordem ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

$total_niveis = count($niveis);
$niveis_ativos = 0;
$total_duracao = 0;
foreach ($niveis as $n) {
    if ($n['status'] == 'ativo') $niveis_ativos++;
    if ($n['duracao_anos']) $total_duracao += $n['duracao_anos'];
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Níveis de Ensino - SIGE Angola</title>
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
        .stat-duracao .stat-number { color: #e67e22; font-size: 24px; }
        
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
        
        .table-niveis { width: 100%; border-collapse: collapse; }
        .table-niveis th {
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
        .table-niveis td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-niveis tr:hover { background: #f8f9fa; }
        
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
            width: 30px;
            height: 30px;
            background: #1e5799;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
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
        }
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 700px;
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
            .table-niveis { font-size: 11px; }
            .table-niveis th, .table-niveis td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-layer-group"></i> Níveis de Ensino</h1>
            <p>Gestão dos níveis de ensino oferecidos pela escola</p>
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
    
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_niveis; ?></div>
            <div class="stat-label">Total de Níveis</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $niveis_ativos; ?></div>
            <div class="stat-label">Níveis Ativos</div>
        </div>
        <div class="stat-card stat-duracao">
            <div class="stat-number"><?php echo $total_duracao; ?></div>
            <div class="stat-label">Total de Anos</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Níveis de Ensino Cadastrados
            <span class="badge bg-light text-dark ms-2"><?php echo $total_niveis; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Nível</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($niveis)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-layer-group fa-3x mb-3"></i>
                    <p>Nenhum nível de ensino cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro nível</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-niveis">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Ordem</th>
                                <th>Sigla</th>
                                <th>Nome</th>
                                <th>Duração</th>
                                <th>Idade</th>
                                <th>Status</th>
                                <th style="width: 120px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($niveis as $nivel): ?>
                                <tr>
                                    <td class="text-center"><span class="ordem-badge"><?php echo $nivel['ordem']; ?></span></td>
                                    <td class="text-center"><strong><?php echo htmlspecialchars($nivel['sigla']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($nivel['nome']); ?></strong>
                                        <?php if ($nivel['descricao']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($nivel['descricao'], 0, 50)) . (strlen($nivel['descricao']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo $nivel['duracao_anos'] ? $nivel['duracao_anos'] . ' ano(s)' : '-'; ?></td>
                                    <td class="text-center">
                                        <?php if ($nivel['idade_minima'] || $nivel['idade_maxima']): ?>
                                            <?php echo ($nivel['idade_minima'] ?: '?') . ' a ' . ($nivel['idade_maxima'] ?: '?') . ' anos'; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($nivel['status'] == 'Ativo'): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarNivel(<?php echo $nivel['id']; ?>)"><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn-acao btn-excluir" onclick="excluirNivel(<?php echo $nivel['id']; ?>, '<?php echo htmlspecialchars(addslashes($nivel['nome'])); ?>')"><i class="fas fa-trash"></i> Excluir</button>
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

<!-- Modal Novo/Editar Nível -->
<div id="modalNivel" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Nível de Ensino</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formNivel">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="nivel_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Nome do Nível *</label>
                            <input type="text" name="nome" class="form-control" id="campo_nome" required placeholder="Ex: Ensino Fundamental">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Sigla *</label>
                            <input type="text" name="sigla" class="form-control" id="campo_sigla" required placeholder="Ex: EF">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Ordem</label>
                            <input type="number" name="ordem" class="form-control" id="campo_ordem" placeholder="Ex: 1, 2, 3">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Duração (anos)</label>
                            <input type="number" name="duracao_anos" class="form-control" id="campo_duracao" placeholder="Ex: 9">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="campo_status">
                                <option value="Ativo">Ativo</option>
                                <option value="Inativo">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Idade Mínima</label>
                            <input type="number" name="idade_minima" class="form-control" id="campo_idade_min" placeholder="Ex: 6">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Idade Máxima</label>
                            <input type="number" name="idade_maxima" class="form-control" id="campo_idade_max" placeholder="Ex: 14">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" id="campo_descricao" rows="3" placeholder="Descreva as características deste nível"></textarea>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informações:</strong>
                    <ul class="mb-0 mt-2">
                        <li>A ordem define a sequência dos níveis</li>
                        <li>Níveis inativos não aparecem nos formulários</li>
                        <li>A sigla deve ser única por escola</li>
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
    function showToast(message, isError) {
        var toast = document.getElementById('toastMessage');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#dc3545' : '#28a745';
        toast.style.display = 'block';
        setTimeout(function() {
            toast.style.display = 'none';
        }, 3000);
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Nível de Ensino';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('nivel_id').value = '0';
        
        // Limpar campos
        document.getElementById('campo_nome').value = '';
        document.getElementById('campo_sigla').value = '';
        document.getElementById('campo_ordem').value = '';
        document.getElementById('campo_duracao').value = '';
        document.getElementById('campo_idade_min').value = '';
        document.getElementById('campo_idade_max').value = '';
        document.getElementById('campo_descricao').value = '';
        document.getElementById('campo_status').value = 'ativo';
        
        document.getElementById('modalNivel').style.display = 'block';
    }
    
    function editarNivel(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Nível de Ensino';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('nivel_id').value = id;
        
        showToast('Carregando dados...', false);
        
        var url = window.location.pathname + '?ajax=1&id=' + id;
        
        fetch(url)
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    var n = data.nivel;
                    document.getElementById('campo_nome').value = n.nome || '';
                    document.getElementById('campo_sigla').value = n.sigla || '';
                    document.getElementById('campo_ordem').value = n.ordem || '';
                    document.getElementById('campo_duracao').value = n.duracao_anos || '';
                    document.getElementById('campo_idade_min').value = n.idade_minima || '';
                    document.getElementById('campo_idade_max').value = n.idade_maxima || '';
                    document.getElementById('campo_descricao').value = n.descricao || '';
                    document.getElementById('campo_status').value = n.status || 'ativo';
                    
                    document.getElementById('modalNivel').style.display = 'block';
                } else {
                    showToast(data.message || 'Erro ao carregar', true);
                }
            })
            .catch(function(error) {
                console.error('Erro:', error);
                showToast('Erro ao carregar dados: ' + error.message, true);
            });
    }
    
    function excluirNivel(id, nome) {
        if (confirm('Tem certeza que deseja excluir o nível "' + nome + '"? Esta ação não pode ser desfeita.')) {
            window.location.href = '?action=excluir&id=' + id;
        }
    }
    
    function fecharModal() {
        document.getElementById('modalNivel').style.display = 'none';
    }
    
    window.onclick = function(event) {
        var modal = document.getElementById('modalNivel');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>