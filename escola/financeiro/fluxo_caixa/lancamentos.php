<?php
// escola/financeiro/fluxo_caixa/lancamentos.php - Lançamentos Diários
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

// Adicionar lançamento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_lancamento') {
    $data_movimento = $_POST['data_movimento'];
    $tipo = $_POST['tipo'];
    $categoria = $_POST['categoria'];
    $descricao = $_POST['descricao'];
    $valor = str_replace(',', '', $_POST['valor']);
    $documento = $_POST['documento'];
    $conta_id = $_POST['conta_id'] ?: null;
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("
        INSERT INTO escola_fluxo_caixa 
        (escola_id, data_movimento, tipo, categoria, descricao, valor, documento, conta_id, forma_pagamento_id, status, usuario_id)
        VALUES 
        (:escola_id, :data_movimento, :tipo, :categoria, :descricao, :valor, :documento, :conta_id, :forma_pagamento_id, :status, :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':data_movimento' => $data_movimento,
        ':tipo' => $tipo,
        ':categoria' => $categoria,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':documento' => $documento,
        ':conta_id' => $conta_id,
        ':forma_pagamento_id' => $forma_pagamento_id,
        ':status' => $status,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Lançamento adicionado com sucesso!";
    header("Location: lancamentos.php");
    exit;
}

// Editar lançamento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_lancamento') {
    $id = $_POST['id'];
    $data_movimento = $_POST['data_movimento'];
    $tipo = $_POST['tipo'];
    $categoria = $_POST['categoria'];
    $descricao = $_POST['descricao'];
    $valor = str_replace(',', '', $_POST['valor']);
    $documento = $_POST['documento'];
    $conta_id = $_POST['conta_id'] ?: null;
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("
        UPDATE escola_fluxo_caixa 
        SET data_movimento = :data_movimento, tipo = :tipo, categoria = :categoria, 
            descricao = :descricao, valor = :valor, documento = :documento,
            conta_id = :conta_id, forma_pagamento_id = :forma_pagamento_id, status = :status
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':data_movimento' => $data_movimento,
        ':tipo' => $tipo,
        ':categoria' => $categoria,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':documento' => $documento,
        ':conta_id' => $conta_id,
        ':forma_pagamento_id' => $forma_pagamento_id,
        ':status' => $status
    ]);
    
    $_SESSION['mensagem'] = "Lançamento atualizado!";
    header("Location: lancamentos.php");
    exit;
}

// Excluir lançamento
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM escola_fluxo_caixa WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Lançamento excluído!";
    header("Location: lancamentos.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Filtros
$tipo_filter = $_GET['tipo'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');

$sql = "
    SELECT f.*, c.banco, c.numero_conta, fp.nome as forma_pagamento_nome
    FROM escola_fluxo_caixa f
    LEFT JOIN escola_contas_bancarias c ON c.id = f.conta_id
    LEFT JOIN escola_formas_pagamento fp ON fp.id = f.forma_pagamento_id
    WHERE f.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($tipo_filter) {
    $sql .= " AND f.tipo = :tipo";
    $params[':tipo'] = $tipo_filter;
}
if ($data_inicio && $data_fim) {
    $sql .= " AND f.data_movimento BETWEEN :data_inicio AND :data_fim";
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
}

$sql .= " ORDER BY f.data_movimento DESC, f.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar contas bancárias
$contas = $conn->prepare("SELECT id, banco, numero_conta FROM escola_contas_bancarias WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY banco");
$contas->execute([':escola_id' => $escola_id]);
$contas = $contas->fetchAll(PDO::FETCH_ASSOC);

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// Totais
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END) as total_entradas,
        SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END) as total_saidas
    FROM escola_fluxo_caixa
    WHERE escola_id = :escola_id AND data_movimento BETWEEN :data_inicio AND :data_fim
");
$stmt->execute([':escola_id' => $escola_id, ':data_inicio' => $data_inicio, ':data_fim' => $data_fim]);
$totais = $stmt->fetch(PDO::FETCH_ASSOC);

$categorias_entrada = [
    'mensalidade' => 'Mensalidades',
    'matricula' => 'Matrículas',
    'taxa' => 'Taxas',
    'doacao' => 'Doações',
    'outro_entrada' => 'Outras Entradas'
];

$categorias_saida = [
    'salario' => 'Salários',
    'material' => 'Material Escolar',
    'utilidade' => 'Utilidades',
    'manutencao' => 'Manutenção',
    'imposto' => 'Impostos',
    'outro_saida' => 'Outras Saídas'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançamentos | Fluxo de Caixa | SIGE Angola</title>
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
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .badge-entrada { background: #28a745; color: white; }
        .badge-saida { background: #dc3545; color: white; }
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-coins"></i> Financeiro</a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                    <li class="nav-item"><a href="lancamentos.php" class="nav-link active"><i class="fas fa-list"></i> Lançamentos</a></li>
                    <li class="nav-item"><a href="consolidado.php" class="nav-link"><i class="fas fa-chart-bar"></i> Consolidado</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-list"></i> Lançamentos Diários</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoLancamento">
                <i class="fas fa-plus"></i> Novo Lançamento
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Totais do Período -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo number_format($totais['total_entradas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Entradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo number_format($totais['total_saidas'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Total de Saídas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo (($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0)) >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format(($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0), 2, ',', '.'); ?> Kz
                </div>
                <div class="stat-label">Saldo do Período</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="tipo" class="form-control">
                        <option value="">Todos os tipos</option>
                        <option value="entrada" <?php echo $tipo_filter == 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                        <option value="saida" <?php echo $tipo_filter == 'saida' ? 'selected' : ''; ?>>Saídas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Lançamentos -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lançamentos</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaLancamentos">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Categoria</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Documento</th>
                                <th>Conta</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lancamentos as $lanc): ?>
                            <tr>
                                <td><?php echo $lanc['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($lanc['data_movimento'])); ?></td>
                                <td><span class="badge badge-<?php echo $lanc['tipo']; ?>"><?php echo ucfirst($lanc['tipo']); ?></span></div>
                                <td><?php echo $categorias_entrada[$lanc['categoria']] ?? $categorias_saida[$lanc['categoria']] ?? $lanc['categoria']; ?></div>
                                <td><?php echo htmlspecialchars($lanc['descricao']); ?></div>
                                <td>
                                    <span class="<?php echo $lanc['tipo'] == 'entrada' ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($lanc['valor'], 2, ',', '.'); ?> Kz
                                    </span>
                                 </div>
                                <td><?php echo $lanc['documento'] ?? '-'; ?></div>
                                <td><?php echo $lanc['banco'] ? $lanc['banco'] . ' - ' . $lanc['numero_conta'] : '-'; ?></div>
                                <td><span class="badge bg-secondary"><?php echo ucfirst($lanc['status']); ?></span></div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="editarLancamento(<?php echo $lanc['id']; ?>)"><i class="fas fa-edit"></i></button>
                                        <a href="?delete=1&id=<?php echo $lanc['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir este lançamento?')"><i class="fas fa-trash"></i></a>
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
    
    <!-- Modal Novo Lançamento -->
    <div class="modal fade" id="modalNovoLancamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Lançamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_lancamento">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Data</label>
                                <input type="date" name="data_movimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Tipo</label>
                                <select name="tipo" id="tipo_select" class="form-control" required onchange="atualizarCategorias()">
                                    <option value="">Selecione...</option>
                                    <option value="entrada">Entrada (Receita)</option>
                                    <option value="saida">Saída (Despesa)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Categoria</label>
                                <select name="categoria" id="categoria_select" class="form-control" required>
                                    <option value="">Selecione primeiro o tipo</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Valor (Kz)</label>
                                <input type="number" step="0.01" name="valor" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="required">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Documento</label>
                                <input type="text" name="documento" class="form-control" placeholder="Nº do documento">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Conta Bancária</label>
                                <select name="conta_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($contas as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['banco']; ?> - <?php echo $c['numero_conta']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Forma de Pagamento</label>
                                <select name="forma_pagamento_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($formas_pagamento as $fp): ?>
                                    <option value="<?php echo $fp['id']; ?>"><?php echo htmlspecialchars($fp['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="confirmado">Confirmado</option>
                                    <option value="pendente">Pendente</option>
                                </select>
                            </div>
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
    
    <!-- Modal Editar Lançamento -->
    <div class="modal fade" id="modalEditarLancamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Lançamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editarLancamentoContent">
                    <div class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
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
        
        $('#tabelaLancamentos').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        const categoriasEntrada = {
            'mensalidade': 'Mensalidades',
            'matricula': 'Matrículas',
            'taxa': 'Taxas',
            'doacao': 'Doações',
            'outro_entrada': 'Outras Entradas'
        };
        
        const categoriasSaida = {
            'salario': 'Salários',
            'material': 'Material Escolar',
            'utilidade': 'Utilidades',
            'manutencao': 'Manutenção',
            'imposto': 'Impostos',
            'outro_saida': 'Outras Saídas'
        };
        
        function atualizarCategorias() {
            const tipo = $('#tipo_select').val();
            const categoriaSelect = $('#categoria_select');
            categoriaSelect.empty();
            
            if (tipo === 'entrada') {
                categoriaSelect.append('<option value="">Selecione...</option>');
                for (const [key, value] of Object.entries(categoriasEntrada)) {
                    categoriaSelect.append(`<option value="${key}">${value}</option>`);
                }
            } else if (tipo === 'saida') {
                categoriaSelect.append('<option value="">Selecione...</option>');
                for (const [key, value] of Object.entries(categoriasSaida)) {
                    categoriaSelect.append(`<option value="${key}">${value}</option>`);
                }
            } else {
                categoriaSelect.append('<option value="">Selecione primeiro o tipo</option>');
            }
        }
        
        function editarLancamento(id) {
            $.ajax({
                url: 'buscar_lancamento.php',
                method: 'GET',
                data: { id: id },
                success: function(data) {
                    $('#editarLancamentoContent').html(data);
                    $('#modalEditarLancamento').modal('show');
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