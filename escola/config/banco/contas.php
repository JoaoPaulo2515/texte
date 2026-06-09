<?php
// escola/config/banco/contas.php - Gestão de Contas Bancárias (VERSÃO CORRIGIDA)
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// ADICIONAR CONTA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_conta') {
    $banco = trim($_POST['banco']);
    $tipo_conta = $_POST['tipo_conta'];
    $agencia = trim($_POST['agencia']);
    $numero_conta = trim($_POST['numero_conta']);
    $digito = trim($_POST['digito']);
    $titular = trim($_POST['titular']);
    $nif = trim($_POST['nif']);
    $saldo_inicial = str_replace(',', '', $_POST['saldo_inicial']);
    $iban = trim($_POST['iban']);
    $swift = trim($_POST['swift']);
    $observacoes = trim($_POST['observacoes']);
    
    if (!is_numeric($saldo_inicial)) {
        $saldo_inicial = 0;
    }
    
    // CORREÇÃO: Query simplificada
    $sql = "INSERT INTO escola_contas_bancarias 
            (escola_id, banco, tipo_conta, agencia, numero_conta, digito, titular, nif, 
             saldo_inicial, saldo_atual, iban, swift, observacoes, status) 
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')";
    
    $stmt = $conn->prepare($sql);
    
    // Usando bindParam ou array posicional para evitar erros
    $stmt->bindParam(1, $escola_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $banco, PDO::PARAM_STR);
    $stmt->bindParam(3, $tipo_conta, PDO::PARAM_STR);
    $stmt->bindParam(4, $agencia, PDO::PARAM_STR);
    $stmt->bindParam(5, $numero_conta, PDO::PARAM_STR);
    $stmt->bindParam(6, $digito, PDO::PARAM_STR);
    $stmt->bindParam(7, $titular, PDO::PARAM_STR);
    $stmt->bindParam(8, $nif, PDO::PARAM_STR);
    $stmt->bindParam(9, $saldo_inicial, PDO::PARAM_STR);
    $stmt->bindParam(10, $saldo_inicial, PDO::PARAM_STR);
    $stmt->bindParam(11, $iban, PDO::PARAM_STR);
    $stmt->bindParam(12, $swift, PDO::PARAM_STR);
    $stmt->bindParam(13, $observacoes, PDO::PARAM_STR);
    
    $stmt->execute();
    
    $_SESSION['mensagem'] = "Conta bancária adicionada com sucesso!";
    header("Location: contas.php");
    exit;
}

// EDITAR CONTA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_conta') {
    $id = (int)$_POST['id'];
    $banco = trim($_POST['banco']);
    $tipo_conta = $_POST['tipo_conta'];
    $agencia = trim($_POST['agencia']);
    $numero_conta = trim($_POST['numero_conta']);
    $digito = trim($_POST['digito']);
    $titular = trim($_POST['titular']);
    $nif = trim($_POST['nif']);
    $iban = trim($_POST['iban']);
    $swift = trim($_POST['swift']);
    $observacoes = trim($_POST['observacoes']);
    
    $sql = "UPDATE escola_contas_bancarias 
            SET banco = ?, tipo_conta = ?, agencia = ?, numero_conta = ?, 
                digito = ?, titular = ?, nif = ?, iban = ?, swift = ?, observacoes = ?
            WHERE id = ? AND escola_id = ?";
    
    $stmt = $conn->prepare($sql);
    
    $stmt->bindParam(1, $banco, PDO::PARAM_STR);
    $stmt->bindParam(2, $tipo_conta, PDO::PARAM_STR);
    $stmt->bindParam(3, $agencia, PDO::PARAM_STR);
    $stmt->bindParam(4, $numero_conta, PDO::PARAM_STR);
    $stmt->bindParam(5, $digito, PDO::PARAM_STR);
    $stmt->bindParam(6, $titular, PDO::PARAM_STR);
    $stmt->bindParam(7, $nif, PDO::PARAM_STR);
    $stmt->bindParam(8, $iban, PDO::PARAM_STR);
    $stmt->bindParam(9, $swift, PDO::PARAM_STR);
    $stmt->bindParam(10, $observacoes, PDO::PARAM_STR);
    $stmt->bindParam(11, $id, PDO::PARAM_INT);
    $stmt->bindParam(12, $escola_id, PDO::PARAM_INT);
    
    $stmt->execute();
    
    $_SESSION['mensagem'] = "Conta bancária atualizada!";
    header("Location: contas.php");
    exit;
}

// ATIVAR/DESATIVAR CONTA
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $sql = "UPDATE escola_contas_bancarias SET status = ? WHERE id = ? AND escola_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(1, $novo_status, PDO::PARAM_STR);
    $stmt->bindParam(2, $id, PDO::PARAM_INT);
    $stmt->bindParam(3, $escola_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: contas.php");
    exit;
}

// EXCLUIR CONTA
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar se há transações
    $check = $conn->prepare("SELECT COUNT(*) as total FROM escola_transacoes_bancarias WHERE conta_id = ?");
    $check->bindParam(1, $id, PDO::PARAM_INT);
    $check->execute();
    $total = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total > 0) {
        $_SESSION['erro'] = "Não é possível excluir uma conta com transações registradas!";
    } else {
        $sql = "DELETE FROM escola_contas_bancarias WHERE id = ? AND escola_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(1, $id, PDO::PARAM_INT);
        $stmt->bindParam(2, $escola_id, PDO::PARAM_INT);
        $stmt->execute();
        $_SESSION['mensagem'] = "Conta excluída!";
    }
    header("Location: contas.php");
    exit;
}

// BUSCAR CONTA POR ID (para edição via AJAX)
if (isset($_GET['buscar']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    
    $sql = "SELECT * FROM escola_contas_bancarias WHERE id = ? AND escola_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(1, $id, PDO::PARAM_INT);
    $stmt->bindParam(2, $escola_id, PDO::PARAM_INT);
    $stmt->execute();
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conta) {
        echo json_encode(['success' => true, 'conta' => $conta]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Conta não encontrada']);
    }
    exit;
}

// ============================================
// BUSCAR DADOS PARA LISTAGEM
// ============================================

$sql = "SELECT c.*, 
               (SELECT COUNT(*) FROM escola_transacoes_bancarias WHERE conta_id = c.id) as total_transacoes
        FROM escola_contas_bancarias c
        WHERE c.escola_id = ?
        ORDER BY c.status DESC, c.banco ASC";

$stmt = $conn->prepare($sql);
$stmt->bindParam(1, $escola_id, PDO::PARAM_INT);
$stmt->execute();
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular saldo total
$saldo_total = 0;
foreach ($contas as $conta) {
    if ($conta['status'] == 'ativo') {
        $saldo_total += $conta['saldo_atual'];
    }
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
    <title>Contas Bancárias | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .saldo-positivo { color: #28a745; font-weight: bold; }
        .table-responsive { overflow-x: auto; }
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
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i> <span>Configurações</span>
                </a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="contas.php" class="nav-link active"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="../pagamento/index.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-university"></i> Contas Bancárias</h2>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2"><i class="fas fa-arrow-left"></i> Voltar</a>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaConta"><i class="fas fa-plus"></i> Nova Conta</button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo count($contas); ?></div><div>Total de Contas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo number_format($saldo_total, 2, ',', '.'); ?> Kz</div><div>Saldo Total</div></div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Contas Bancárias</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaContas">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Banco</th>
                                <th>Tipo</th>
                                <th>Agência</th>
                                <th>Nº Conta</th>
                                <th>Titular</th>
                                <th>Saldo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contas as $conta): ?>
                            <tr>
                                <td><?php echo $conta['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($conta['banco']); ?></strong><br><small><?php echo $conta['iban'] ?? ''; ?></small></div>
                                <td><?php echo ucfirst($conta['tipo_conta']); ?></div>
                                <td><?php echo $conta['agencia']; ?></div>
                                <td><?php echo $conta['numero_conta']; ?>-<?php echo $conta['digito']; ?></div>
                                <td><?php echo htmlspecialchars($conta['titular']); ?></div>
                                <td><span class="saldo-positivo"><?php echo number_format($conta['saldo_atual'], 2, ',', '.'); ?> Kz</span></div>
                                <td><span class="badge <?php echo $conta['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $conta['status']; ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarConta(<?php echo $conta['id']; ?>)"><i class="fas fa-edit"></i></button>
                                    <a href="?toggle=1&id=<?php echo $conta['id']; ?>&status=<?php echo $conta['status']; ?>" class="btn btn-sm btn-success"><i class="fas fa-toggle-<?php echo $conta['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                    <a href="?delete=1&id=<?php echo $conta['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Conta -->
    <div class="modal fade" id="modalNovaConta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Conta Bancária</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_conta">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Banco</label><input type="text" name="banco" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label>Tipo de Conta</label><select name="tipo_conta" class="form-control"><option value="corrente">Corrente</option><option value="poupanca">Poupança</option><option value="salario">Salário</option><option value="empresarial">Empresarial</option></select></div>
                            <div class="col-md-4 mb-3"><label>Agência</label><input type="text" name="agencia" class="form-control"></div>
                            <div class="col-md-4 mb-3"><label>Nº Conta</label><input type="text" name="numero_conta" class="form-control" required></div>
                            <div class="col-md-4 mb-3"><label>Dígito</label><input type="text" name="digito" class="form-control" maxlength="2"></div>
                            <div class="col-md-12 mb-3"><label>Titular</label><input type="text" name="titular" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label>NIF</label><input type="text" name="nif" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label>Saldo Inicial</label><input type="number" step="0.01" name="saldo_inicial" class="form-control" value="0"></div>
                            <div class="col-md-6 mb-3"><label>IBAN</label><input type="text" name="iban" class="form-control" placeholder="AO06..."></div>
                            <div class="col-md-6 mb-3"><label>SWIFT/BIC</label><input type="text" name="swift" class="form-control"></div>
                            <div class="col-md-12 mb-3"><label>Observações</label><textarea name="observacoes" class="form-control" rows="2"></textarea></div>
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
    
    <!-- Modal Editar Conta -->
    <div class="modal fade" id="modalEditarConta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Conta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_conta">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label>Banco</label><input type="text" name="banco" id="edit_banco" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label>Tipo de Conta</label><select name="tipo_conta" id="edit_tipo_conta" class="form-control"><option value="corrente">Corrente</option><option value="poupanca">Poupança</option><option value="salario">Salário</option><option value="empresarial">Empresarial</option></select></div>
                            <div class="col-md-4 mb-3"><label>Agência</label><input type="text" name="agencia" id="edit_agencia" class="form-control"></div>
                            <div class="col-md-4 mb-3"><label>Nº Conta</label><input type="text" name="numero_conta" id="edit_numero_conta" class="form-control" required></div>
                            <div class="col-md-4 mb-3"><label>Dígito</label><input type="text" name="digito" id="edit_digito" class="form-control"></div>
                            <div class="col-md-12 mb-3"><label>Titular</label><input type="text" name="titular" id="edit_titular" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label>NIF</label><input type="text" name="nif" id="edit_nif" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label>IBAN</label><input type="text" name="iban" id="edit_iban" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label>SWIFT/BIC</label><input type="text" name="swift" id="edit_swift" class="form-control"></div>
                            <div class="col-md-12 mb-3"><label>Observações</label><textarea name="observacoes" id="edit_observacoes" class="form-control" rows="2"></textarea></div>
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
        
        $('#tabelaContas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        function editarConta(id) {
            $.ajax({
                url: 'contas.php',
                method: 'GET',
                data: { buscar: 1, id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#edit_id').val(data.conta.id);
                        $('#edit_banco').val(data.conta.banco);
                        $('#edit_tipo_conta').val(data.conta.tipo_conta);
                        $('#edit_agencia').val(data.conta.agencia);
                        $('#edit_numero_conta').val(data.conta.numero_conta);
                        $('#edit_digito').val(data.conta.digito);
                        $('#edit_titular').val(data.conta.titular);
                        $('#edit_nif').val(data.conta.nif);
                        $('#edit_iban').val(data.conta.iban);
                        $('#edit_swift').val(data.conta.swift);
                        $('#edit_observacoes').val(data.conta.observacoes);
                        $('#modalEditarConta').modal('show');
                    } else {
                        alert(data.message || 'Erro ao carregar dados da conta');
                    }
                },
                error: function() {
                    alert('Erro ao carregar dados da conta');
                }
            });
        }
    </script>
</body>
</html>