<?php
// escola/financeiro/folha_pagamento/cargos.php - Cargos e Salários Base
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

// Adicionar cargo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_cargo') {
    $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $salario_base = str_replace(',', '', $_POST['salario_base']);
    $bonus_fixo = str_replace(',', '', $_POST['bonus_fixo'] ?? 0);
    $vale_transporte = str_replace(',', '', $_POST['vale_transporte'] ?? 0);
    $vale_refeicao = str_replace(',', '', $_POST['vale_refeicao'] ?? 0);
    $auxilio_saude = str_replace(',', '', $_POST['auxilio_saude'] ?? 0);
    
    $stmt = $conn->prepare("
        INSERT INTO rh_cargos (escola_id, nome, descricao, salario_base, bonus_fixo, vale_transporte, vale_refeicao, auxilio_saude, status)
        VALUES (:escola_id, :nome, :descricao, :salario_base, :bonus_fixo, :vale_transporte, :vale_refeicao, :auxilio_saude, 'ativo')
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':descricao' => $descricao,
        ':salario_base' => $salario_base,
        ':bonus_fixo' => $bonus_fixo,
        ':vale_transporte' => $vale_transporte,
        ':vale_refeicao' => $vale_refeicao,
        ':auxilio_saude' => $auxilio_saude
    ]);
    
    $_SESSION['mensagem'] = "Cargo adicionado com sucesso!";
    header("Location: cargos.php");
    exit;
}

// Editar cargo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_cargo') {
    $id = $_POST['id'];
    $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $salario_base = str_replace(',', '', $_POST['salario_base']);
    $bonus_fixo = str_replace(',', '', $_POST['bonus_fixo'] ?? 0);
    $vale_transporte = str_replace(',', '', $_POST['vale_transporte'] ?? 0);
    $vale_refeicao = str_replace(',', '', $_POST['vale_refeicao'] ?? 0);
    $auxilio_saude = str_replace(',', '', $_POST['auxilio_saude'] ?? 0);
    
    $stmt = $conn->prepare("
        UPDATE rh_cargos 
        SET nome = :nome, descricao = :descricao, salario_base = :salario_base,
            bonus_fixo = :bonus_fixo, vale_transporte = :vale_transporte,
            vale_refeicao = :vale_refeicao, auxilio_saude = :auxilio_saude
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':descricao' => $descricao,
        ':salario_base' => $salario_base,
        ':bonus_fixo' => $bonus_fixo,
        ':vale_transporte' => $vale_transporte,
        ':vale_refeicao' => $vale_refeicao,
        ':auxilio_saude' => $auxilio_saude
    ]);
    
    $_SESSION['mensagem'] = "Cargo atualizado!";
    header("Location: cargos.php");
    exit;
}

// Ativar/Desativar cargo
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE rh_cargos SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: cargos.php");
    exit;
}

// Excluir cargo
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar se há funcionários vinculados
    $check = $conn->prepare("SELECT COUNT(*) as total FROM rh_funcionarios WHERE cargo_id = :id");
    $check->execute([':id' => $id]);
    $total = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total > 0) {
        $_SESSION['erro'] = "Não é possível excluir um cargo com funcionários vinculados!";
    } else {
        $stmt = $conn->prepare("DELETE FROM rh_cargos WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['mensagem'] = "Cargo excluído!";
    }
    header("Location: cargos.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$cargos = $conn->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM rh_funcionarios WHERE cargo_id = c.id) as total_funcionarios
    FROM rh_cargos c
    WHERE c.escola_id = :escola_id
    ORDER BY c.nome
");
$cargos->execute([':escola_id' => $escola_id]);
$cargos = $cargos->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargos | Folha de Pagamento | SIGE Angola</title>
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
        
        .table-responsive { overflow-x: auto; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
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
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="cargos.php" class="nav-link active"><i class="fas fa-briefcase"></i> Cargos</a></li>
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
                <i class="fas fa-briefcase"></i> Cargos e Salários Base
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoCargo">
                <i class="fas fa-plus"></i> Novo Cargo
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Lista de Cargos -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Cargos</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaCargos">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Cargo</th>
                                <th>Salário Base</th>
                                <th>Bônus</th>
                                <th>Vale Transporte</th>
                                <th>Vale Refeição</th>
                                <th>Funcionários</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cargos as $cargo): ?>
                            <tr>
                                <td><?php echo $cargo['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cargo['nome']); ?></strong><br><small><?php echo htmlspecialchars($cargo['descricao']); ?></small></div>
                                <td><?php echo number_format($cargo['salario_base'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($cargo['bonus_fixo'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($cargo['vale_transporte'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($cargo['vale_refeicao'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo $cargo['total_funcionarios']; ?> </div>
                                <td><span class="badge <?php echo $cargo['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $cargo['status']; ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="editarCargo(<?php echo $cargo['id']; ?>)"><i class="fas fa-edit"></i></button>
                                        <a href="?toggle=1&id=<?php echo $cargo['id']; ?>&status=<?php echo $cargo['status']; ?>" class="btn btn-success"><i class="fas fa-toggle-<?php echo $cargo['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                        <a href="?delete=1&id=<?php echo $cargo['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir este cargo?')"><i class="fas fa-trash"></i></a>
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
    
    <!-- Modal Novo Cargo -->
    <div class="modal fade" id="modalNovoCargo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Cargo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_cargo">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="required">Nome do Cargo</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Salário Base (Kz)</label>
                                <input type="number" step="0.01" name="salario_base" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Bônus Fixo (Kz)</label>
                                <input type="number" step="0.01" name="bonus_fixo" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Vale Transporte (Kz)</label>
                                <input type="number" step="0.01" name="vale_transporte" class="form-control" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Vale Refeição (Kz)</label>
                                <input type="number" step="0.01" name="vale_refeicao" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>AUXÍLIO SAÚDE (Kz)</label>
                            <input type="number" step="0.01" name="auxilio_saude" class="form-control" value="0">
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
    
    <!-- Modal Editar Cargo -->
    <div class="modal fade" id="modalEditarCargo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Cargo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editarCargoContent">
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Cargos e Salários</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Cargos?</h6>
                    <p>Defina os cargos da escola e os respectivos salários base e benefícios.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Composição do Salário:</h6>
                    <ul>
                        <li><strong>Salário Base:</strong> Valor fixo do cargo.</li>
                        <li><strong>Bônus Fixo:</strong> Valor adicional mensal.</li>
                        <li><strong>Vale Transporte:</strong> Benefício de transporte.</li>
                        <li><strong>Vale Refeição:</strong> Benefício alimentação.</li>
                        <li><strong>Auxílio Saúde:</strong> Plano de saúde.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Mantenha os salários atualizados conforme convenção.</li>
                        <li>Registre todos os benefícios contratuais.</li>
                        <li>Cargos bem definidos facilitam a gestão.</li>
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
        
        $('#tabelaCargos').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: true
        });
        
        function editarCargo(id) {
            $.ajax({
                url: 'buscar_cargo.php',
                method: 'GET',
                data: { id: id },
                success: function(data) {
                    $('#editarCargoContent').html(data);
                    $('#modalEditarCargo').modal('show');
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