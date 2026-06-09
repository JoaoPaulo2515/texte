<?php
// escola/pedagogico/anos_letivos.php - Gestão de Anos Letivos

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
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Inserir novo ano letivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $ano = trim($_POST['ano']);
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    // Verificar se já existe ano letivo com este ano
    $sql_check = "SELECT id FROM ano_letivo WHERE escola_id = :escola_id AND ano = :ano";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':ano' => $ano]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um ano letivo cadastrado com o ano $ano.";
    } else {
        // Se este for o primeiro ano letivo ou ativo for true, desativar outros
        if ($ativo == 1) {
            $sql_desativar = "UPDATE ano_letivo SET ativo = 0 WHERE escola_id = :escola_id";
            $stmt_desativar = $conn->prepare($sql_desativar);
            $stmt_desativar->execute([':escola_id' => $escola_id]);
        }
        
        $sql = "INSERT INTO ano_letivo (escola_id, ano, data_inicio, data_fim, ativo, created_at) 
                VALUES (:escola_id, :ano, :data_inicio, :data_fim, :ativo, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':ano' => $ano,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim,
            ':ativo' => $ativo
        ]);
        
        $mensagem = "Ano letivo cadastrado com sucesso!";
    }
}

// Atualizar ano letivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $ano = trim($_POST['ano']);
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    // Verificar se já existe outro ano letivo com este ano
    $sql_check = "SELECT id FROM ano_letivo WHERE escola_id = :escola_id AND ano = :ano AND id != :id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':ano' => $ano, ':id' => $id]);
    
    if ($stmt_check->fetch()) {
        $erro = "Já existe um ano letivo cadastrado com o ano $ano.";
    } else {
        // Se este for ativo, desativar outros
        if ($ativo == 1) {
            $sql_desativar = "UPDATE ano_letivo SET ativo = 0 WHERE escola_id = :escola_id AND id != :id";
            $stmt_desativar = $conn->prepare($sql_desativar);
            $stmt_desativar->execute([':escola_id' => $escola_id, ':id' => $id]);
        }
        
        $sql = "UPDATE ano_letivo SET 
                ano = :ano, 
                data_inicio = :data_inicio, 
                data_fim = :data_fim, 
                ativo = :ativo
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':ano' => $ano,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim,
            ':ativo' => $ativo,
            ':id' => $id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem = "Ano letivo atualizado com sucesso!";
    }
}

// Excluir ano letivo
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se existem dependências
    $sql_check = "SELECT COUNT(*) as total FROM matriculas WHERE ano_letivo = :ano_letivo_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':ano_letivo_id' => $id]);
    $matriculas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($matriculas > 0) {
        $erro = "Não é possível excluir este ano letivo pois existem $matriculas matrículas associadas.";
    } else {
        $sql = "DELETE FROM ano_letivo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Ano letivo excluído com sucesso!";
    }
}

// Ativar/Desativar ano letivo via AJAX
if (isset($_POST['action']) && $_POST['action'] === 'toggle_ativo') {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    $ativo = (int)$_POST['ativo'];
    
    if ($ativo == 1) {
        // Desativar todos os outros
        $sql_desativar = "UPDATE ano_letivo SET ativo = 0 WHERE escola_id = :escola_id AND id != :id";
        $stmt_desativar = $conn->prepare($sql_desativar);
        $stmt_desativar->execute([':escola_id' => $escola_id, ':id' => $id]);
    }
    
    $sql = "UPDATE ano_letivo SET ativo = :ativo WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':ativo' => $ativo, ':id' => $id, ':escola_id' => $escola_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Buscar ano letivo via AJAX para edição
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $sql = "SELECT * FROM ano_letivo WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $ano = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ano) {
        echo json_encode(['success' => true, 'ano' => $ano]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ano letivo não encontrado']);
    }
    exit;
}

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT * FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

$total_anos = count($anos_letivos);
$ano_atual = null;
foreach ($anos_letivos as $a) {
    if ($a['ativo'] == 1) {
        $ano_atual = $a;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anos Letivos - SIGE Angola</title>
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
        .stat-ativo .stat-number { color: #27ae60; }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-novo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .table-anos { width: 100%; border-collapse: collapse; }
        .table-anos th {
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
        .table-anos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-anos tr:hover { background: #f8f9fa; }
        
        .badge-ativo {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-ativo-sim { background: #d4edda; color: #155724; }
        .badge-ativo-nao { background: #f8d7da; color: #721c24; }
        
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
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #27ae60;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Modal Styles */
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
            max-width: 500px;
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
        .modal-custom-body { padding: 25px; }
        .modal-custom-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
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
        }
        .btn-cancelar:hover { transform: translateY(-2px); background: #5a6268; }
        .btn-confirmar {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-confirmar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(220,53,69,0.3); }
        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-info:hover { transform: translateY(-2px); }
        
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
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-anos { font-size: 11px; }
            .table-anos th, .table-anos td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 10% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-calendar-alt"></i> Anos Letivos</h1>
            <p>Gestão dos anos letivos da escola</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if (isset($mensagem)): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if (isset($erro)): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_anos; ?></div>
            <div class="stat-label">Total de Anos</div>
        </div>
        <div class="stat-card stat-ativo">
            <div class="stat-number"><?php echo $ano_atual ? $ano_atual['ano'] : '-'; ?></div>
            <div class="stat-label">Ano Ativo</div>
        </div>
    </div>
    
    <!-- Lista de Anos Letivos -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Anos Letivos
            <span class="badge bg-light text-dark ms-2"><?php echo $total_anos; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Ano Letivo</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($anos_letivos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-calendar-alt fa-3x mb-3"></i>
                    <p>Nenhum ano letivo cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro ano letivo</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-anos">
                        <thead>
                            <tr>
                                <th>Ano</th>
                                <th>Data Início</th>
                                <th>Data Fim</th>
                                <th>Status</th>
                                <th>Ativo</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ano['ano']); ?></strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($ano['data_inicio'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($ano['data_fim'])); ?></td>
                                    <td>
                                        <label class="toggle-switch">
                                            <input type="checkbox" class="toggle-ativo" data-id="<?php echo $ano['id']; ?>" data-ano="<?php echo htmlspecialchars($ano['ano']); ?>" <?php echo $ano['ativo'] == 1 ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <?php if ($ano['ativo'] == 1): ?>
                                            <span class="badge-ativo badge-ativo-sim">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-ativo badge-ativo-nao">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-acao btn-editar" onclick="editarAno(<?php echo $ano['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="confirmarExclusao(<?php echo $ano['id']; ?>, '<?php echo htmlspecialchars($ano['ano']); ?>')">
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

<!-- Modal de Confirmação -->
<div id="modalConfirmacao" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="confirmacaoTitulo"><i class="fas fa-question-circle"></i> Confirmar Ação</h3>
            <span class="close-modal" onclick="fecharModalConfirmacao()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="confirmacaoMensagem"></p>
            <input type="hidden" id="confirmacaoAcao" value="">
            <input type="hidden" id="confirmacaoId" value="">
            <input type="hidden" id="confirmacaoValor" value="">
        </div>
        <div class="modal-custom-footer">
            <button class="btn-cancelar" onclick="fecharModalConfirmacao()">Cancelar</button>
            <button class="btn-confirmar" id="btnConfirmarAcao" onclick="executarAcaoConfirmada()">Confirmar</button>
        </div>
    </div>
</div>

<!-- Modal de Informação -->
<div id="modalInfo" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3><i class="fas fa-info-circle"></i> Informação</h3>
            <span class="close-modal" onclick="fecharModalInfo()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="infoMensagem"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-info" onclick="fecharModalInfo()">OK</button>
        </div>
    </div>
</div>

<!-- Modal de Erro -->
<div id="modalErro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Erro</h3>
            <span class="close-modal" onclick="fecharModalErro()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="erroMensagem"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-info" onclick="fecharModalErro()">OK</button>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Ano Letivo -->
<div id="modalAno" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Ano Letivo</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formAno">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="ano_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Ano *</label>
                    <input type="text" name="ano" class="form-control" required placeholder="Ex: 2024" min="2000" max="2100">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Data Início *</label>
                    <input type="date" name="data_inicio" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Data Fim *</label>
                    <input type="date" name="data_fim" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="ativo" value="1"> Ativar este ano letivo
                    </label>
                    <small class="text-muted d-block">Apenas um ano letivo pode estar ativo por vez.</small>
                </div>
                
                <div class="text-end">
                    <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let acaoConfirmadaCallback = null;
    
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
    
    function showModalConfirmacao(titulo, mensagem, callback, id = null, valor = null) {
        document.getElementById('confirmacaoTitulo').innerHTML = titulo;
        document.getElementById('confirmacaoMensagem').innerHTML = mensagem;
        document.getElementById('confirmacaoId').value = id;
        document.getElementById('confirmacaoValor').value = valor;
        acaoConfirmadaCallback = callback;
        document.getElementById('modalConfirmacao').style.display = 'block';
    }
    
    function showModalInfo(mensagem) {
        document.getElementById('infoMensagem').innerHTML = mensagem;
        document.getElementById('modalInfo').style.display = 'block';
    }
    
    function showModalErro(mensagem) {
        document.getElementById('erroMensagem').innerHTML = mensagem;
        document.getElementById('modalErro').style.display = 'block';
    }
    
    function fecharModalConfirmacao() {
        document.getElementById('modalConfirmacao').style.display = 'none';
        acaoConfirmadaCallback = null;
    }
    
    function fecharModalInfo() {
        document.getElementById('modalInfo').style.display = 'none';
    }
    
    function fecharModalErro() {
        document.getElementById('modalErro').style.display = 'none';
    }
    
    function executarAcaoConfirmada() {
        if (acaoConfirmadaCallback) {
            acaoConfirmadaCallback();
        }
        fecharModalConfirmacao();
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Ano Letivo';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('ano_id').value = '0';
        document.getElementById('formAno').reset();
        document.getElementById('modalAno').style.display = 'block';
    }
    
    function editarAno(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Ano Letivo';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('ano_id').value = id;
        
        fetch(`anos_letivos.php?ajax=1&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const a = data.ano;
                    document.querySelector('input[name="ano"]').value = a.ano;
                    document.querySelector('input[name="data_inicio"]').value = a.data_inicio;
                    document.querySelector('input[name="data_fim"]').value = a.data_fim;
                    document.querySelector('input[name="ativo"]').checked = a.ativo == 1;
                    document.getElementById('modalAno').style.display = 'block';
                } else {
                    showModalErro('Erro ao carregar dados do ano letivo');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showModalErro('Erro de conexão');
            });
    }
    
    function confirmarExclusao(id, ano) {
        showModalConfirmacao(
            '<i class="fas fa-trash"></i> Confirmar Exclusão',
            `Tem certeza que deseja excluir o ano letivo <strong>${ano}</strong>?<br><br>Esta ação não pode ser desfeita.`,
            function() {
                window.location.href = `?action=excluir&id=${id}`;
            },
            id,
            ano
        );
    }
    
    function fecharModal() {
        document.getElementById('modalAno').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modalAno = document.getElementById('modalAno');
        const modalConfirmacao = document.getElementById('modalConfirmacao');
        const modalInfo = document.getElementById('modalInfo');
        const modalErro = document.getElementById('modalErro');
        
        if (event.target == modalAno) {
            fecharModal();
        }
        if (event.target == modalConfirmacao) {
            fecharModalConfirmacao();
        }
        if (event.target == modalInfo) {
            fecharModalInfo();
        }
        if (event.target == modalErro) {
            fecharModalErro();
        }
    }
    
    // Toggle ativo via AJAX
    document.querySelectorAll('.toggle-ativo').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const id = this.dataset.id;
            const ano = this.dataset.ano;
            const ativo = this.checked ? 1 : 0;
            
            if (ativo == 1) {
                // Mostrar modal de confirmação
                showModalConfirmacao(
                    '<i class="fas fa-toggle-on"></i> Ativar Ano Letivo',
                    `Ao ativar o ano letivo <strong>${ano}</strong>, o ano atualmente ativo será desativado.<br><br>Deseja continuar?`,
                    function() {
                        executarToggleAtivo(id, ativo, toggle);
                    },
                    id,
                    ano
                );
                // Reverter o toggle temporariamente
                this.checked = false;
            } else {
                executarToggleAtivo(id, ativo, toggle);
            }
        });
    });
    
    function executarToggleAtivo(id, ativo, toggleElement) {
        const formData = new FormData();
        formData.append('action', 'toggle_ativo');
        formData.append('id', id);
        formData.append('ativo', ativo);
        
        fetch('anos_letivos.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(ativo == 1 ? 'Ano letivo ativado com sucesso!' : 'Ano letivo desativado');
                setTimeout(() => location.reload(), 1000);
            } else {
                showModalErro('Erro ao alterar status do ano letivo');
                if (toggleElement) toggleElement.checked = !ativo;
            }
        })
        .catch(error => {
            showModalErro('Erro de conexão');
            if (toggleElement) toggleElement.checked = !ativo;
        });
    }
</script>
</body>
</html>