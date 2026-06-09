<?php
// escola/financeiro/folha_pagamento/funcionarios.php - Gestão de Funcionários
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar funcionário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_funcionario') {
    $usuario_id_func = $_POST['usuario_id'];
    $cargo_id = $_POST['cargo_id'] ?: null;
    $numero_funcionario = $_POST['numero_funcionario'];
    $data_admissao = $_POST['data_admissao'];
    $tipo_contrato = $_POST['tipo_contrato'];
    $salario_contratual = str_replace(',', '', $_POST['salario_contratual']);
    $banco = $_POST['banco'];
    $agencia = $_POST['agencia'];
    $conta_bancaria = $_POST['conta_bancaria'];
    $pix = $_POST['pix'];
    $observacoes = $_POST['observacoes'];
    
    $stmt = $conn->prepare("
        INSERT INTO rh_funcionarios 
        (escola_id, usuario_id, cargo_id, numero_funcionario, data_admissao, tipo_contrato, 
         salario_contratual, banco, agencia, conta_bancaria, pix, observacoes, status)
        VALUES 
        (:escola_id, :usuario_id, :cargo_id, :numero_funcionario, :data_admissao, :tipo_contrato,
         :salario_contratual, :banco, :agencia, :conta_bancaria, :pix, :observacoes, 'ativo')
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':usuario_id' => $usuario_id_func,
        ':cargo_id' => $cargo_id,
        ':numero_funcionario' => $numero_funcionario,
        ':data_admissao' => $data_admissao,
        ':tipo_contrato' => $tipo_contrato,
        ':salario_contratual' => $salario_contratual,
        ':banco' => $banco,
        ':agencia' => $agencia,
        ':conta_bancaria' => $conta_bancaria,
        ':pix' => $pix,
        ':observacoes' => $observacoes
    ]);
    
    $_SESSION['mensagem'] = "Funcionário adicionado com sucesso!";
    header("Location: funcionarios.php");
    exit;
}

// Editar funcionário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_funcionario') {
    $id = $_POST['id'];
    $usuario_id_func = $_POST['usuario_id'];
    $cargo_id = $_POST['cargo_id'] ?: null;
    $numero_funcionario = $_POST['numero_funcionario'];
    $data_admissao = $_POST['data_admissao'];
    $tipo_contrato = $_POST['tipo_contrato'];
    $salario_contratual = str_replace(',', '', $_POST['salario_contratual']);
    $banco = $_POST['banco'];
    $agencia = $_POST['agencia'];
    $conta_bancaria = $_POST['conta_bancaria'];
    $pix = $_POST['pix'];
    $observacoes = $_POST['observacoes'];
    
    $stmt = $conn->prepare("
        UPDATE rh_funcionarios 
        SET usuario_id = :usuario_id, cargo_id = :cargo_id, numero_funcionario = :numero_funcionario,
            data_admissao = :data_admissao, tipo_contrato = :tipo_contrato,
            salario_contratual = :salario_contratual, banco = :banco, agencia = :agencia,
            conta_bancaria = :conta_bancaria, pix = :pix, observacoes = :observacoes
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':usuario_id' => $usuario_id_func,
        ':cargo_id' => $cargo_id,
        ':numero_funcionario' => $numero_funcionario,
        ':data_admissao' => $data_admissao,
        ':tipo_contrato' => $tipo_contrato,
        ':salario_contratual' => $salario_contratual,
        ':banco' => $banco,
        ':agencia' => $agencia,
        ':conta_bancaria' => $conta_bancaria,
        ':pix' => $pix,
        ':observacoes' => $observacoes
    ]);
    
    $_SESSION['mensagem'] = "Funcionário atualizado!";
    header("Location: funcionarios.php");
    exit;
}

// Ativar/Desativar funcionário
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE rh_funcionarios SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: funcionarios.php");
    exit;
}

// Excluir funcionário
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar se há folhas vinculadas
    $check = $conn->prepare("SELECT COUNT(*) as total FROM rh_folha_itens WHERE funcionario_id = :id");
    $check->execute([':id' => $id]);
    $total = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total > 0) {
        $_SESSION['erro'] = "Não é possível excluir um funcionário com folhas de pagamento vinculadas!";
    } else {
        $stmt = $conn->prepare("DELETE FROM rh_funcionarios WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['mensagem'] = "Funcionário excluído!";
    }
    header("Location: funcionarios.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? '';

$sql = "
    SELECT f.*, u.nome as usuario_nome, u.email, c.nome as cargo_nome
    FROM rh_funcionarios f
    JOIN usuarios u ON u.id = f.usuario_id
    LEFT JOIN rh_cargos c ON c.id = f.cargo_id
    WHERE f.escola_id = :escola_id
";
$params = [':escola_id' => $escola_id];

if ($status_filter) {
    $sql .= " AND f.status = :status";
    $params[':status'] = $status_filter;
}

$sql .= " ORDER BY f.data_admissao DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuários para select
$usuarios = $conn->prepare("
    SELECT id, nome, email FROM usuarios 
    WHERE escola_id = :escola_id AND tipo IN ('admin', 'secretaria', 'funcionario')
    ORDER BY nome
");
$usuarios->execute([':escola_id' => $escola_id]);
$usuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);

// Buscar cargos para select
$cargos = $conn->prepare("SELECT id, nome, salario_base FROM rh_cargos WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$cargos->execute([':escola_id' => $escola_id]);
$cargos = $cargos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => count($funcionarios),
    'ativos' => 0,
    'ferias' => 0,
    'licenca' => 0
];

foreach ($funcionarios as $f) {
    if ($f['status'] == 'ativo') $stats['ativos']++;
    if ($f['status'] == 'ferias') $stats['ferias']++;
    if ($f['status'] == 'licenca') $stats['licenca']++;
}

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funcionários | Folha de Pagamento | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .badge-ferias { background: #ffc107; color: #000; }
        .badge-licenca { background: #17a2b8; color: white; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .required:after { content: "*"; color: red; margin-left: 5px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> <span>Financeiro</span>
                </a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link active"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="cargos.php" class="nav-link"><i class="fas fa-briefcase"></i> Cargos</a></li>
                    <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
                    <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-users"></i> Funcionários
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoFuncionario">
                <i class="fas fa-plus"></i> Novo Funcionário
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $stats['ativos']; ?></div>
                <div class="stat-label">Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['ferias']; ?></div>
                <div class="stat-label">Férias</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['licenca']; ?></div>
                <div class="stat-label">Licença</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-12">
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filter == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        <option value="ferias" <?php echo $status_filter == 'ferias' ? 'selected' : ''; ?>>Férias</option>
                        <option value="licenca" <?php echo $status_filter == 'licenca' ? 'selected' : ''; ?>>Licença</option>
                    </select>
                </div>
                <div class="col-md-12 mt-2">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Funcionários -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Funcionários</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaFuncionarios">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Nº Funcionário</th>
                                <th>Nome</th>
                                <th>Cargo</th>
                                <th>Admissão</th>
                                <th>Salário</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($funcionarios as $f): ?>
                            <tr>
                                <td><?php echo $f['id']; ?></td>
                                <td><?php echo $f['numero_funcionario']; ?></td>
                                <td><strong><?php echo htmlspecialchars($f['usuario_nome']); ?></strong><br><small><?php echo $f['email']; ?></small></div>
                                <td><?php echo htmlspecialchars($f['cargo_nome'] ?? '-'); ?></div>
                                <td><?php echo date('d/m/Y', strtotime($f['data_admissao'])); ?></div>
                                <td><?php echo number_format($f['salario_contratual'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <span class="badge badge-<?php echo $f['status']; ?>">
                                        <?php echo ucfirst($f['status']); ?>
                                    </span>
                                 </div>
                                </div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="editarFuncionario(<?php echo $f['id']; ?>)"><i class="fas fa-edit"></i></button>
                                        <a href="?toggle=1&id=<?php echo $f['id']; ?>&status=<?php echo $f['status']; ?>" class="btn btn-success"><i class="fas fa-toggle-<?php echo $f['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                        <a href="?delete=1&id=<?php echo $f['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir este funcionário?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Funcionário -->
    <div class="modal fade" id="modalNovoFuncionario" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Funcionário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_funcionario">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Usuário</label>
                                <select name="usuario_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($usuarios as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nome']); ?> (<?php echo $u['email']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Cargo</label>
                                <select name="cargo_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($cargos as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?> (<?php echo number_format($c['salario_base'], 2, ',', '.'); ?> Kz)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Nº Funcionário</label>
                                <input type="text" name="numero_funcionario" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Data de Admissão</label>
                                <input type="date" name="data_admissao" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Tipo de Contrato</label>
                                <select name="tipo_contrato" class="form-control" required>
                                    <option value="CLT">CLT</option>
                                    <option value="PJ">PJ</option>
                                    <option value="Estagio">Estágio</option>
                                    <option value="Temporario">Temporário</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Salário Contratual (Kz)</label>
                                <input type="number" step="0.01" name="salario_contratual" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Banco</label>
                                <input type="text" name="banco" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Agência</label>
                                <input type="text" name="agencia" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Conta Bancária</label>
                                <input type="text" name="conta_bancaria" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>PIX</label>
                                <input type="text" name="pix" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
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
    
    <!-- Modal Editar Funcionário -->
    <div class="modal fade" id="modalEditarFuncionario" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Funcionário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editarFuncionarioContent">
                    <div class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Gestão de Funcionários</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é a Gestão de Funcionários?</h6>
                    <p>Cadastre e gerencie todos os funcionários da escola para controle da folha de pagamento.</p>
                    
                    <h6><i class="fas fa-user-plus text-success"></i> Funcionalidades:</h6>
                    <ul>
                        <li><strong>Cadastro:</strong> Adicione novos funcionários.</li>
                        <li><strong>Edição:</strong> Atualize dados pessoais e profissionais.</li>
                        <li><strong>Status:</strong> Controle de ativo, inativo, férias, licença.</li>
                        <li><strong>Dados bancários:</strong> Informações para pagamento.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Mantenha os dados sempre atualizados.</li>
                        <li>Verifique as informações bancárias antes do pagamento.</li>
                        <li>Registre corretamente a data de admissão.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        $('#tabelaFuncionarios').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        
        function editarFuncionario(id) {
            $.ajax({
                url: 'buscar_funcionario.php',
                method: 'GET',
                data: { id: id },
                success: function(data) {
                    $('#editarFuncionarioContent').html(data);
                    $('#modalEditarFuncionario').modal('show');
                }
            });
        }
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>