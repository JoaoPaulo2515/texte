<?php
// escola/tesouraria/contas_bancarias.php - Gestão de Contas Bancárias

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// TABELAS NECESSÁRIAS
// ============================================

$sql_criar_tabelas = "
CREATE TABLE IF NOT EXISTS contas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NOT NULL,
    banco VARCHAR(100) NOT NULL,
    agencia VARCHAR(50),
    numero_conta VARCHAR(50) NOT NULL,
    tipo_conta ENUM('corrente', 'poupanca', 'salario') DEFAULT 'corrente',
    titular VARCHAR(200),
    iban VARCHAR(50),
    swift VARCHAR(20),
    saldo_inicial DECIMAL(10,2) DEFAULT 0,
    saldo_atual DECIMAL(10,2) DEFAULT 0,
    moeda VARCHAR(3) DEFAULT 'AOA',
    ativo TINYINT DEFAULT 1,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS movimentos_bancarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NOT NULL,
    conta_id INT NOT NULL,
    tipo ENUM('entrada', 'saida') NOT NULL,
    categoria VARCHAR(100),
    descricao TEXT NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_movimento DATE NOT NULL,
    data_conciliacao DATE NULL,
    referencia VARCHAR(100),
    documento VARCHAR(200),
    conciliado TINYINT DEFAULT 0,
    observacoes TEXT,
    usuario_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
    FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
);
";
$conn->exec($sql_criar_tabelas);

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'contas';

// Processar conta bancária
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'save_conta') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $banco = trim($_POST['banco']);
        $agencia = trim($_POST['agencia']);
        $numero_conta = trim($_POST['numero_conta']);
        $tipo_conta = $_POST['tipo_conta'];
        $titular = trim($_POST['titular']);
        $iban = trim($_POST['iban']);
        $swift = trim($_POST['swift']);
        $saldo_inicial = floatval(str_replace(',', '.', str_replace('.', '', $_POST['saldo_inicial'] ?? '0')));
        $moeda = $_POST['moeda'];
        $observacoes = trim($_POST['observacoes']);
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        
        if (empty($banco) || empty($numero_conta)) {
            $error = "Banco e número da conta são obrigatórios.";
        } else {
            try {
                if ($id > 0) {
                    // Atualizar conta existente
                    $sql = "UPDATE contas_bancarias SET 
                                banco = :banco, agencia = :agencia, numero_conta = :numero_conta,
                                tipo_conta = :tipo_conta, titular = :titular, iban = :iban,
                                swift = :swift, moeda = :moeda, observacoes = :observacoes,
                                ativo = :ativo
                            WHERE id = :id AND escola_id = :escola_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id' => $id, ':escola_id' => $escola_id, ':banco' => $banco,
                        ':agencia' => $agencia, ':numero_conta' => $numero_conta, ':tipo_conta' => $tipo_conta,
                        ':titular' => $titular, ':iban' => $iban, ':swift' => $swift,
                        ':moeda' => $moeda, ':observacoes' => $observacoes, ':ativo' => $ativo
                    ]);
                    $success = "Conta bancária atualizada com sucesso!";
                } else {
                    // Inserir nova conta
                    $sql = "INSERT INTO contas_bancarias (escola_id, banco, agencia, numero_conta, tipo_conta, titular, iban, swift, saldo_inicial, saldo_atual, moeda, observacoes, ativo) 
                            VALUES (:escola_id, :banco, :agencia, :numero_conta, :tipo_conta, :titular, :iban, :swift, :saldo_inicial, :saldo_inicial, :moeda, :observacoes, :ativo)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id, ':banco' => $banco, ':agencia' => $agencia,
                        ':numero_conta' => $numero_conta, ':tipo_conta' => $tipo_conta, ':titular' => $titular,
                        ':iban' => $iban, ':swift' => $swift, ':saldo_inicial' => $saldo_inicial,
                        ':moeda' => $moeda, ':observacoes' => $observacoes, ':ativo' => $ativo
                    ]);
                    $success = "Conta bancária cadastrada com sucesso!";
                }
            } catch (Exception $e) {
                $error = "Erro ao salvar: " . $e->getMessage();
            }
        }
    }
    
    // Excluir conta
    elseif (isset($_POST['action']) && $_POST['action'] == 'delete_conta') {
        $id = (int)$_POST['id'];
        
        // Verificar se há movimentos na conta
        $sql_check = "SELECT COUNT(*) as total FROM movimentos_bancarios WHERE conta_id = :conta_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':conta_id' => $id]);
        $total = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total > 0) {
            $error = "Não é possível excluir a conta pois existem $total movimentos associados.";
        } else {
            $sql = "DELETE FROM contas_bancarias WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
            $success = "Conta excluída com sucesso!";
        }
    }
    
    // Registrar movimento bancário
    elseif (isset($_POST['action']) && $_POST['action'] == 'save_movimento') {
        $conta_id = (int)$_POST['conta_id'];
        $tipo = $_POST['tipo'];
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $data_movimento = $_POST['data_movimento'];
        $referencia = trim($_POST['referencia']);
        $observacoes = trim($_POST['observacoes']);
        
        if ($valor <= 0) {
            $error = "Valor inválido.";
        } elseif (empty($descricao)) {
            $error = "Descrição é obrigatória.";
        } else {
            try {
                $sql = "INSERT INTO movimentos_bancarios (escola_id, conta_id, tipo, categoria, descricao, valor, data_movimento, referencia, observacoes, usuario_id) 
                        VALUES (:escola_id, :conta_id, :tipo, :categoria, :descricao, :valor, :data_movimento, :referencia, :observacoes, :usuario_id)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id, ':conta_id' => $conta_id, ':tipo' => $tipo,
                    ':categoria' => $categoria, ':descricao' => $descricao, ':valor' => $valor,
                    ':data_movimento' => $data_movimento, ':referencia' => $referencia,
                    ':observacoes' => $observacoes, ':usuario_id' => $usuario_id
                ]);
                
                // Atualizar saldo da conta
                if ($tipo == 'entrada') {
                    $sql_saldo = "UPDATE contas_bancarias SET saldo_atual = saldo_atual + :valor WHERE id = :id";
                } else {
                    $sql_saldo = "UPDATE contas_bancarias SET saldo_atual = saldo_atual - :valor WHERE id = :id";
                }
                $stmt_saldo = $conn->prepare($sql_saldo);
                $stmt_saldo->execute([':valor' => $valor, ':id' => $conta_id]);
                
                $success = "Movimento registrado com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao registrar movimento: " . $e->getMessage();
            }
        }
    }
    
    // Conciliar movimento
    elseif (isset($_POST['action']) && $_POST['action'] == 'conciliar') {
        $id = (int)$_POST['id'];
        $data_conciliacao = $_POST['data_conciliacao'];
        
        $sql = "UPDATE movimentos_bancarios SET conciliado = 1, data_conciliacao = :data_conciliacao WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':data_conciliacao' => $data_conciliacao]);
        $success = "Movimento conciliado com sucesso!";
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar contas bancárias
$sql_contas = "SELECT * FROM contas_bancarias WHERE escola_id = :escola_id ORDER BY banco ASC";
$stmt_contas = $conn->prepare($sql_contas);
$stmt_contas->execute([':escola_id' => $escola_id]);
$contas = $stmt_contas->fetchAll(PDO::FETCH_ASSOC);

// Buscar movimentos bancários
$sql_movimentos = "SELECT mb.*, cb.banco, cb.numero_conta 
                   FROM movimentos_bancarios mb
                   JOIN contas_bancarias cb ON cb.id = mb.conta_id
                   WHERE mb.escola_id = :escola_id 
                   ORDER BY mb.data_movimento DESC 
                   LIMIT 50";
$stmt_movimentos = $conn->prepare($sql_movimentos);
$stmt_movimentos->execute([':escola_id' => $escola_id]);
$movimentos = $stmt_movimentos->fetchAll(PDO::FETCH_ASSOC);

// Saldo total das contas
$saldo_total = array_sum(array_column($contas, 'saldo_atual'));

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoContaLabel($tipo) {
    switch ($tipo) {
        case 'corrente': return '<span class="badge bg-primary">Corrente</span>';
        case 'poupanca': return '<span class="badge bg-success">Poupança</span>';
        case 'salario': return '<span class="badge bg-info">Salário</span>';
        default: return '<span class="badge bg-secondary">' . $tipo . '</span>';
    }
}

function getTipoMovimentoLabel($tipo) {
    if ($tipo == 'entrada') {
        return '<span class="badge bg-success"><i class="fas fa-arrow-up"></i> Entrada</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-arrow-down"></i> Saída</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas Bancárias | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        
        .saldo-positivo { color: #28a745; }
        .saldo-negativo { color: #dc3545; }
        
        .conta-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .conta-card:hover { transform: translateY(-3px); }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-university"></i> Contas Bancárias</h2>
                <p class="text-muted">Gestão de contas bancárias e movimentos financeiros</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaConta">
                    <i class="fas fa-plus"></i> Nova Conta
                </button>
                <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#modalNovoMovimento">
                    <i class="fas fa-exchange-alt"></i> Novo Movimento
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($contas); ?></div>
                    <div class="stat-label"><i class="fas fa-university"></i> Total de Contas</div>
                    <small>Ativas e inativas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?php echo $saldo_total >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>">
                        <?php echo formatarMoeda($saldo_total); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo Total</div>
                    <small>Todas as contas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($movimentos); ?></div>
                    <div class="stat-label"><i class="fas fa-exchange-alt"></i> Movimentos (últimos 50)</div>
                    <small>Registros recentes</small>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab == 'contas' ? 'active' : ''; ?>" href="?tab=contas">
                    <i class="fas fa-university"></i> Contas Bancárias
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $active_tab == 'movimentos' ? 'active' : ''; ?>" href="?tab=movimentos">
                    <i class="fas fa-list"></i> Movimentos
                </a>
            </li>
        </ul>
        
        <!-- CONTAS BANCÁRIAS -->
        <?php if ($active_tab == 'contas'): ?>
        <div class="row">
            <?php foreach ($contas as $conta): ?>
            <div class="col-md-6 mb-4">
                <div class="card conta-card" style="border-left-color: <?php echo $conta['ativo'] ? '#28a745' : '#dc3545'; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title">
                                    <i class="fas fa-university"></i> <?php echo htmlspecialchars($conta['banco']); ?>
                                </h5>
                                <p class="card-text">
                                    <small class="text-muted">Agência: <?php echo htmlspecialchars($conta['agencia'] ?: '-'); ?></small><br>
                                    <strong>Conta: <?php echo htmlspecialchars($conta['numero_conta']); ?></strong><br>
                                    Tipo: <?php echo getTipoContaLabel($conta['tipo_conta']); ?>
                                </p>
                                <?php if ($conta['titular']): ?>
                                <small>Titular: <?php echo htmlspecialchars($conta['titular']); ?></small><br>
                                <?php endif; ?>
                                <?php if ($conta['iban']): ?>
                                <small>IBAN: <?php echo htmlspecialchars($conta['iban']); ?></small><br>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <h4 class="<?php echo $conta['saldo_atual'] >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>">
                                    <?php echo formatarMoeda($conta['saldo_atual']); ?>
                                </h4>
                                <small>Saldo atual</small>
                                <div class="mt-2">
                                    <button class="btn btn-sm btn-warning" onclick="editarConta(<?php echo htmlspecialchars(json_encode($conta)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="excluirConta(<?php echo $conta['id']; ?>, '<?php echo htmlspecialchars($conta['banco']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="small text-muted">
                            <i class="fas fa-calendar-alt"></i> Criada em: <?php echo date('d/m/Y', strtotime($conta['created_at'])); ?>
                            <?php if (!$conta['ativo']): ?>
                            <span class="badge bg-secondary ms-2">Inativa</span>
                            <?php else: ?>
                            <span class="badge bg-success ms-2">Ativa</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($contas)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center">Nenhuma conta bancária cadastrada.</div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- MOVIMENTOS BANCÁRIOS -->
        <?php if ($active_tab == 'movimentos'): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Últimos Movimentos Bancários</h5>
            </div>
            <div class="card-body">
                <?php if (empty($movimentos)): ?>
                    <div class="alert alert-info text-center">Nenhum movimento registrado.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Conta</th>
                                    <th>Tipo</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th class="text-end">Valor</th>
                                    <th>Conciliado</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movimentos as $mov): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?></td>
                                    <td><?php echo htmlspecialchars($mov['banco']); ?><br><small><?php echo htmlspecialchars($mov['numero_conta']); ?></small></td>
                                    <td><?php echo getTipoMovimentoLabel($mov['tipo']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['categoria'] ?: '-'); ?></small></td>
                                    <td><?php echo htmlspecialchars($mov['descricao']); ?></small></td>
                                    <td class="text-end <?php echo $mov['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        <?php echo formatarMoeda($mov['valor']); ?>
                                    </td>
                                    <td>
                                        <?php if ($mov['conciliado']): ?>
                                            <span class="badge bg-success">Conciliado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$mov['conciliado']): ?>
                                        <button class="btn btn-sm btn-success" onclick="conciliarMovimento(<?php echo $mov['id']; ?>)">
                                            <i class="fas fa-check"></i> Conciliar
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAL:</td>
                                    <td class="text-end">
                                        <?php 
                                        $total_entradas = array_sum(array_column(array_filter($movimentos, function($m) { return $m['tipo'] == 'entrada'; }), 'valor'));
                                        $total_saidas = array_sum(array_column(array_filter($movimentos, function($m) { return $m['tipo'] == 'saida'; }), 'valor'));
                                        ?>
                                        <span class="text-success">+ <?php echo formatarMoeda($total_entradas); ?></span><br>
                                        <span class="text-danger">- <?php echo formatarMoeda($total_saidas); ?></span>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Nova/Editar Conta -->
    <div class="modal fade" id="modalConta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalContaTitle"><i class="fas fa-plus-circle"></i> Nova Conta Bancária</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_conta">
                    <input type="hidden" name="id" id="conta_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Banco <span class="text-danger">*</span></label>
                                <input type="text" name="banco" id="conta_banco" class="form-control" required placeholder="Ex: BFA, BAI, BIC...">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Agência</label>
                                <input type="text" name="agencia" id="conta_agencia" class="form-control" placeholder="Número da agência">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Número da Conta <span class="text-danger">*</span></label>
                                <input type="text" name="numero_conta" id="conta_numero" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Conta</label>
                                <select name="tipo_conta" id="conta_tipo" class="form-select">
                                    <option value="corrente">Corrente</option>
                                    <option value="poupanca">Poupança</option>
                                    <option value="salario">Salário</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Titular</label>
                                <input type="text" name="titular" id="conta_titular" class="form-control" placeholder="Nome do titular da conta">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Moeda</label>
                                <select name="moeda" id="conta_moeda" class="form-select">
                                    <option value="AOA">AOA - Kwanza</option>
                                    <option value="USD">USD - Dólar</option>
                                    <option value="EUR">EUR - Euro</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">IBAN</label>
                                <input type="text" name="iban" id="conta_iban" class="form-control" placeholder="Código IBAN">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SWIFT/BIC</label>
                                <input type="text" name="swift" id="conta_swift" class="form-control" placeholder="Código SWIFT">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Saldo Inicial</label>
                            <input type="text" name="saldo_inicial" id="conta_saldo" class="form-control valor" placeholder="0,00">
                            <small class="text-muted">Valor inicial da conta no momento do cadastro</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" id="conta_observacoes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="ativo" class="form-check-input" id="conta_ativo" checked>
                            <label class="form-check-label" for="conta_ativo">Conta ativa</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Movimento -->
    <div class="modal fade" id="modalMovimento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Novo Movimento Bancário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_movimento">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Conta Bancária <span class="text-danger">*</span></label>
                            <select name="conta_id" class="form-select" required>
                                <option value="">Selecione uma conta</option>
                                <?php foreach ($contas as $conta): ?>
                                <option value="<?php echo $conta['id']; ?>"><?php echo htmlspecialchars($conta['banco']); ?> - <?php echo htmlspecialchars($conta['numero_conta']); ?> (<?php echo formatarMoeda($conta['saldo_atual']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select name="tipo" class="form-select" required>
                                <option value="entrada">📥 Entrada (Depósito/Recebimento)</option>
                                <option value="saida">📤 Saída (Pagamento/Retirada)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Categoria</label>
                            <select name="categoria" class="form-select">
                                <option value="">Selecione...</option>
                                <option value="Transferência">Transferência</option>
                                <option value="Depósito">Depósito</option>
                                <option value="Pagamento">Pagamento</option>
                                <option value="Taxa Bancária">Taxa Bancária</option>
                                <option value="Juros">Juros</option>
                                <option value="Salário">Salário</option>
                                <option value="Fornecedor">Fornecedor</option>
                                <option value="Outro">Outro</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" class="form-control" required placeholder="Ex: Pagamento de fornecedor, Depósito de mensalidades...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor <span class="text-danger">*</span></label>
                            <input type="text" name="valor" class="form-control valor" required placeholder="0,00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Data do Movimento</label>
                            <input type="date" name="data_movimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nº Referência</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Nº de documento, transferência...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Movimento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('.valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        function editarConta(conta) {
            $('#conta_id').val(conta.id);
            $('#conta_banco').val(conta.banco);
            $('#conta_agencia').val(conta.agencia);
            $('#conta_numero').val(conta.numero_conta);
            $('#conta_tipo').val(conta.tipo_conta);
            $('#conta_titular').val(conta.titular);
            $('#conta_iban').val(conta.iban);
            $('#conta_swift').val(conta.swift);
            $('#conta_moeda').val(conta.moeda);
            $('#conta_saldo').val(formatarValor(conta.saldo_inicial.toString()));
            $('#conta_observacoes').val(conta.observacoes);
            $('#conta_ativo').prop('checked', conta.ativo == 1);
            $('#modalContaTitle').html('<i class="fas fa-edit"></i> Editar Conta Bancária');
            new bootstrap.Modal(document.getElementById('modalConta')).show();
        }
        
        function excluirConta(id, banco) {
            if(confirm('Tem certeza que deseja excluir a conta do banco "' + banco + '"?')) {
                $('<form method="POST"><input type="hidden" name="action" value="delete_conta"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        function conciliarMovimento(id) {
            let data = prompt('Data de conciliação (YYYY-MM-DD):', '<?php echo date('Y-m-d'); ?>');
            if (data) {
                $('<form method="POST"><input type="hidden" name="action" value="conciliar"><input type="hidden" name="id" value="' + id + '"><input type="hidden" name="data_conciliacao" value="' + data + '"></form>').appendTo('body').submit();
            }
        }
        
        $('#modalConta').on('hidden.bs.modal', function() {
            $('#conta_id').val('');
            $('#modalContaTitle').html('<i class="fas fa-plus-circle"></i> Nova Conta Bancária');
        });
    </script>
</body>
</html>