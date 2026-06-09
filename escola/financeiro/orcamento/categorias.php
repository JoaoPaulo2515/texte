<?php
// escola/financeiro/orcamento/categorias.php - Categorias Orçamentárias
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

// Adicionar categoria
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_categoria') {
    $nome = $_POST['nome'];
    $tipo = $_POST['tipo'];
    $cor = $_POST['cor'];
    $ordem = $_POST['ordem'];
    
    $stmt = $conn->prepare("
        INSERT INTO escola_categorias_orcamento (escola_id, nome, tipo, cor, ordem, status)
        VALUES (:escola_id, :nome, :tipo, :cor, :ordem, 'ativo')
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':tipo' => $tipo,
        ':cor' => $cor,
        ':ordem' => $ordem
    ]);
    
    $_SESSION['mensagem'] = "Categoria adicionada com sucesso!";
    header("Location: categorias.php");
    exit;
}

// Editar categoria
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_categoria') {
    $id = $_POST['id'];
    $nome = $_POST['nome'];
    $tipo = $_POST['tipo'];
    $cor = $_POST['cor'];
    $ordem = $_POST['ordem'];
    
    $stmt = $conn->prepare("
        UPDATE escola_categorias_orcamento 
        SET nome = :nome, tipo = :tipo, cor = :cor, ordem = :ordem
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':nome' => $nome,
        ':tipo' => $tipo,
        ':cor' => $cor,
        ':ordem' => $ordem
    ]);
    
    $_SESSION['mensagem'] = "Categoria atualizada!";
    header("Location: categorias.php");
    exit;
}

// Ativar/Desativar categoria
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE escola_categorias_orcamento SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: categorias.php");
    exit;
}

// Excluir categoria
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Verificar se há orçamentos vinculados
    $check = $conn->prepare("SELECT COUNT(*) as total FROM escola_orcamento WHERE categoria = (SELECT nome FROM escola_categorias_orcamento WHERE id = :id)");
    $check->execute([':id' => $id]);
    $total = $check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total > 0) {
        $_SESSION['erro'] = "Não é possível excluir uma categoria com orçamentos vinculados!";
    } else {
        $stmt = $conn->prepare("DELETE FROM escola_categorias_orcamento WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $_SESSION['mensagem'] = "Categoria excluída!";
    }
    header("Location: categorias.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$categorias_receita = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'receita'
    ORDER BY ordem, nome
");
$categorias_receita->execute([':escola_id' => $escola_id]);
$categorias_receita = $categorias_receita->fetchAll(PDO::FETCH_ASSOC);

$categorias_despesa = $conn->prepare("
    SELECT * FROM escola_categorias_orcamento 
    WHERE escola_id = :escola_id AND tipo = 'despesa'
    ORDER BY ordem, nome
");
$categorias_despesa->execute([':escola_id' => $escola_id]);
$categorias_despesa = $categorias_despesa->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias Orçamentárias | SIGE Angola</title>
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
        
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
        .cor-preview { width: 30px; height: 30px; border-radius: 5px; display: inline-block; margin-right: 10px; vertical-align: middle; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
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
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                    <li class="nav-item"><a href="categorias.php" class="nav-link active"><i class="fas fa-tags"></i> Categorias</a></li>
                    <li class="nav-item"><a href="executado.php" class="nav-link"><i class="fas fa-chart-line"></i> Executado</a></li>
                    <li class="nav-item"><a href="desvios.php" class="nav-link"><i class="fas fa-exclamation-triangle"></i> Desvios</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-tags"></i> Categorias Orçamentárias
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaCategoria">
                <i class="fas fa-plus"></i> Nova Categoria
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-arrow-up"></i> Categorias de Receita
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabelaReceitas">
                                <thead class="table-light">
                                    <tr>
                                        <th>Cor</th>
                                        <th>Nome</th>
                                        <th>Ordem</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categorias_receita as $cat): ?>
                                    <tr>
                                        <td><div class="cor-preview" style="background-color: <?php echo $cat['cor']; ?>;"></div></td>
                                        <td><?php echo htmlspecialchars($cat['nome']); ?></td>
                                        <td><?php echo $cat['ordem']; ?></td>
                                        <td><span class="badge <?php echo $cat['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $cat['status']; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="editarCategoria(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['nome']); ?>', '<?php echo $cat['tipo']; ?>', '<?php echo $cat['cor']; ?>', <?php echo $cat['ordem']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle=1&id=<?php echo $cat['id']; ?>&status=<?php echo $cat['status']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-toggle-<?php echo $cat['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                         </div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <i class="fas fa-arrow-down"></i> Categorias de Despesa
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="tabelaDespesas">
                                <thead class="table-light">
                                    <tr>
                                        <th>Cor</th>
                                        <th>Nome</th>
                                        <th>Ordem</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categorias_despesa as $cat): ?>
                                    <tr>
                                        <td><div class="cor-preview" style="background-color: <?php echo $cat['cor']; ?>;"></div></td>
                                        <td><?php echo htmlspecialchars($cat['nome']); ?></td>
                                        <td><?php echo $cat['ordem']; ?></td>
                                        <td><span class="badge <?php echo $cat['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $cat['status']; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="editarCategoria(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['nome']); ?>', '<?php echo $cat['tipo']; ?>', '<?php echo $cat['cor']; ?>', <?php echo $cat['ordem']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle=1&id=<?php echo $cat['id']; ?>&status=<?php echo $cat['status']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-toggle-<?php echo $cat['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                            </a>
                                            <a href="?delete=1&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                         </div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Categoria -->
    <div class="modal fade" id="modalNovaCategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_categoria">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nome da Categoria</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Tipo</label>
                            <select name="tipo" class="form-control" required>
                                <option value="receita">Receita</option>
                                <option value="despesa">Despesa</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Cor</label>
                            <input type="color" name="cor" class="form-control" value="#006B3E">
                        </div>
                        <div class="mb-3">
                            <label>Ordem de Exibição</label>
                            <input type="number" name="ordem" class="form-control" value="0">
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
    
    <!-- Modal Editar Categoria -->
    <div class="modal fade" id="modalEditarCategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Categoria</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_categoria">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nome da Categoria</label>
                            <input type="text" name="nome" id="edit_nome" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Tipo</label>
                            <select name="tipo" id="edit_tipo" class="form-control" required>
                                <option value="receita">Receita</option>
                                <option value="despesa">Despesa</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Cor</label>
                            <input type="color" name="cor" id="edit_cor" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Ordem de Exibição</label>
                            <input type="number" name="ordem" id="edit_ordem" class="form-control">
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
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Sobre as Categorias Orçamentárias</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Categorias Orçamentárias?</h6>
                    <p>As categorias organizam as receitas e despesas da escola, facilitando o planejamento e a análise orçamentária.</p>
                    
                    <h6><i class="fas fa-tags text-success"></i> Como gerenciar:</h6>
                    <ul>
                        <li><strong>Adicionar:</strong> Crie novas categorias conforme necessário.</li>
                        <li><strong>Editar:</strong> Altere nome, cor ou ordem de exibição.</li>
                        <li><strong>Ativar/Desativar:</strong> Desative categorias não utilizadas.</li>
                        <li><strong>Excluir:</strong> Remova categorias sem orçamentos vinculados.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Use cores diferentes para facilitar a visualização nos gráficos.</li>
                        <li>Mantenha as categorias organizadas por ordem de importância.</li>
                        <li>Revise as categorias anualmente para manter a relevância.</li>
                        <li>Categorias bem definidas melhoram a qualidade da análise.</li>
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
        
        $('#tabelaReceitas, #tabelaDespesas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[2, 'asc']]
        });
        
        function editarCategoria(id, nome, tipo, cor, ordem) {
            $('#edit_id').val(id);
            $('#edit_nome').val(nome);
            $('#edit_tipo').val(tipo);
            $('#edit_cor').val(cor);
            $('#edit_ordem').val(ordem);
            $('#modalEditarCategoria').modal('show');
        }
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>