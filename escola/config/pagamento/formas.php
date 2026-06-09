<?php
// escola/config/pagamento/formas.php - Formas de Pagamento
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar e criar tabela
$check = $conn->query("SHOW TABLES LIKE 'escola_formas_pagamento'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_formas_pagamento (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            tipo ENUM('dinheiro', 'transferencia', 'cheque', 'multicaixa', 'deposito', 'online') DEFAULT 'dinheiro',
            taxa_juros DECIMAL(5,2) DEFAULT 0,
            taxa_multa DECIMAL(5,2) DEFAULT 0,
            parcelas_maximo INT DEFAULT 1,
            desconto_vista DECIMAL(5,2) DEFAULT 0,
            instrucoes TEXT,
            icone VARCHAR(50),
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_forma') {
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $taxa_juros = str_replace(',', '.', $_POST['taxa_juros']);
        $taxa_multa = str_replace(',', '.', $_POST['taxa_multa']);
        $parcelas_maximo = $_POST['parcelas_maximo'];
        $desconto_vista = str_replace(',', '.', $_POST['desconto_vista']);
        $instrucoes = $_POST['instrucoes'];
        $icone = $_POST['icone'];
        
        $stmt = $conn->prepare("
            INSERT INTO escola_formas_pagamento 
            (escola_id, nome, descricao, tipo, taxa_juros, taxa_multa, parcelas_maximo, desconto_vista, instrucoes, icone, status)
            VALUES (:escola_id, :nome, :descricao, :tipo, :taxa_juros, :taxa_multa, :parcelas_maximo, :desconto_vista, :instrucoes, :icone, 'ativo')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':taxa_juros' => $taxa_juros,
            ':taxa_multa' => $taxa_multa,
            ':parcelas_maximo' => $parcelas_maximo,
            ':desconto_vista' => $desconto_vista,
            ':instrucoes' => $instrucoes,
            ':icone' => $icone
        ]);
        
        $_SESSION['mensagem'] = "Forma de pagamento adicionada com sucesso!";
        header("Location: formas.php");
        exit;
    }
    
    if ($acao == 'edit_forma') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $taxa_juros = str_replace(',', '.', $_POST['taxa_juros']);
        $taxa_multa = str_replace(',', '.', $_POST['taxa_multa']);
        $parcelas_maximo = $_POST['parcelas_maximo'];
        $desconto_vista = str_replace(',', '.', $_POST['desconto_vista']);
        $instrucoes = $_POST['instrucoes'];
        $icone = $_POST['icone'];
        
        $stmt = $conn->prepare("
            UPDATE escola_formas_pagamento 
            SET nome = :nome, descricao = :descricao, tipo = :tipo, 
                taxa_juros = :taxa_juros, taxa_multa = :taxa_multa, 
                parcelas_maximo = :parcelas_maximo, desconto_vista = :desconto_vista,
                instrucoes = :instrucoes, icone = :icone
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':taxa_juros' => $taxa_juros,
            ':taxa_multa' => $taxa_multa,
            ':parcelas_maximo' => $parcelas_maximo,
            ':desconto_vista' => $desconto_vista,
            ':instrucoes' => $instrucoes,
            ':icone' => $icone
        ]);
        
        $_SESSION['mensagem'] = "Forma de pagamento atualizada!";
        header("Location: formas.php");
        exit;
    }
}

// Ativar/Desativar
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE escola_formas_pagamento SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: formas.php");
    exit;
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM escola_formas_pagamento WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Forma de pagamento excluída!";
    header("Location: formas.php");
    exit;
}

// Buscar dados
$formas = $conn->prepare("SELECT * FROM escola_formas_pagamento WHERE escola_id = :escola_id ORDER BY status DESC, nome ASC");
$formas->execute([':escola_id' => $escola_id]);
$formas = $formas->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formas de Pagamento | SIGE Angola</title>
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
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
        .forma-icon { font-size: 2em; width: 50px; text-align: center; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-cogs"></i> Configurações</a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/contas.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="formas.php" class="nav-link active"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-credit-card"></i> Formas de Pagamento</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaForma"><i class="fas fa-plus"></i> Nova Forma</button>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Formas de Pagamento Disponíveis</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaFormas">
                        <thead class="table-light">
                            <tr><th>Ícone</th><th>Nome</th><th>Tipo</th><th>Taxa Juros</th><th>Taxa Multa</th><th>Desconto à Vista</th><th>Parcelas</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php $icones = ['dinheiro' => 'fa-money-bill', 'transferencia' => 'fa-exchange-alt', 'cheque' => 'fa-file-invoice', 'multicaixa' => 'fa-credit-card', 'deposito' => 'fa-university', 'online' => 'fa-globe']; ?>
                            <?php $tipos = ['dinheiro' => 'Dinheiro', 'transferencia' => 'Transferência', 'cheque' => 'Cheque', 'multicaixa' => 'Multicaixa', 'deposito' => 'Depósito', 'online' => 'Online']; ?>
                            <?php foreach ($formas as $forma): ?>
                            <tr>
                                <td><div class="forma-icon"><i class="fas <?php echo $forma['icone'] ?? $icones[$forma['tipo']]; ?>"></i></div></div>
                                <td><strong><?php echo htmlspecialchars($forma['nome']); ?></strong><br><small><?php echo htmlspecialchars($forma['descricao']); ?></small></div>
                                <td><?php echo $tipos[$forma['tipo']]; ?></div>
                                <td><?php echo $forma['taxa_juros']; ?>%</div>
                                <td><?php echo $forma['taxa_multa']; ?>%</div>
                                <td><?php echo $forma['desconto_vista']; ?>%</div>
                                <td><?php echo $forma['parcelas_maximo']; ?>x</div>
                                <td><span class="badge <?php echo $forma['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $forma['status']; ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarForma(<?php echo $forma['id']; ?>, '<?php echo addslashes($forma['nome']); ?>', '<?php echo addslashes($forma['descricao']); ?>', '<?php echo $forma['tipo']; ?>', '<?php echo $forma['taxa_juros']; ?>', '<?php echo $forma['taxa_multa']; ?>', '<?php echo $forma['parcelas_maximo']; ?>', '<?php echo $forma['desconto_vista']; ?>', '<?php echo addslashes($forma['instrucoes']); ?>', '<?php echo $forma['icone']; ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?toggle=1&id=<?php echo $forma['id']; ?>&status=<?php echo $forma['status']; ?>" class="btn btn-sm btn-success"><i class="fas fa-toggle-<?php echo $forma['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                    <a href="?delete=1&id=<?php echo $forma['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Forma -->
    <div class="modal fade" id="modalNovaForma" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Nova Forma de Pagamento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_forma"><div class="modal-body"><div class="row"><div class="col-md-6 mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div><div class="col-md-6 mb-3"><label>Ícone</label><select name="icone" class="form-control"><option value="fa-money-bill">💰 Dinheiro</option><option value="fa-exchange-alt">🔄 Transferência</option><option value="fa-file-invoice">📄 Cheque</option><option value="fa-credit-card">💳 Multicaixa</option><option value="fa-university">🏛 Depósito</option><option value="fa-globe">🌍 Online</option></select></div></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência Bancária</option><option value="cheque">Cheque</option><option value="multicaixa">Multicaixa</option><option value="deposito">Depósito</option><option value="online">Online</option></select></div><div class="col-md-6 mb-3"><label>Nº Máximo de Parcelas</label><input type="number" name="parcelas_maximo" class="form-control" value="1" min="1"></div></div><div class="row"><div class="col-md-4 mb-3"><label>Taxa de Juros (%)</label><input type="number" step="0.01" name="taxa_juros" class="form-control" value="0"></div><div class="col-md-4 mb-3"><label>Taxa de Multa (%)</label><input type="number" step="0.01" name="taxa_multa" class="form-control" value="0"></div><div class="col-md-4 mb-3"><label>Desconto à Vista (%)</label><input type="number" step="0.01" name="desconto_vista" class="form-control" value="0"></div></div><div class="mb-3"><label>Instruções de Pagamento</label><textarea name="instrucoes" class="form-control" rows="3" placeholder="Ex: NIB, IBAN, SWIFT, etc."></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Editar Forma -->
    <div class="modal fade" id="modalEditarForma" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Forma de Pagamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_forma"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="row"><div class="col-md-6 mb-3"><label>Nome</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="col-md-6 mb-3"><label>Ícone</label><select name="icone" id="edit_icone" class="form-control"><option value="fa-money-bill">💰 Dinheiro</option><option value="fa-exchange-alt">🔄 Transferência</option><option value="fa-file-invoice">📄 Cheque</option><option value="fa-credit-card">💳 Multicaixa</option><option value="fa-university">🏛 Depósito</option><option value="fa-globe">🌍 Online</option></select></div></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" id="edit_tipo" class="form-control"><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência Bancária</option><option value="cheque">Cheque</option><option value="multicaixa">Multicaixa</option><option value="deposito">Depósito</option><option value="online">Online</option></select></div><div class="col-md-6 mb-3"><label>Nº Máximo de Parcelas</label><input type="number" name="parcelas_maximo" id="edit_parcelas_maximo" class="form-control" min="1"></div></div><div class="row"><div class="col-md-4 mb-3"><label>Taxa de Juros (%)</label><input type="number" step="0.01" name="taxa_juros" id="edit_taxa_juros" class="form-control"></div><div class="col-md-4 mb-3"><label>Taxa de Multa (%)</label><input type="number" step="0.01" name="taxa_multa" id="edit_taxa_multa" class="form-control"></div><div class="col-md-4 mb-3"><label>Desconto à Vista (%)</label><input type="number" step="0.01" name="desconto_vista" id="edit_desconto_vista" class="form-control"></div></div><div class="mb-3"><label>Instruções de Pagamento</label><textarea name="instrucoes" id="edit_instrucoes" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaFormas').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        function editarForma(id, nome, desc, tipo, juros, multa, parcelas, desconto, instrucoes, icone) {
            $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_descricao').val(desc); $('#edit_tipo').val(tipo);
            $('#edit_taxa_juros').val(juros); $('#edit_taxa_multa').val(multa); $('#edit_parcelas_maximo').val(parcelas);
            $('#edit_desconto_vista').val(desconto); $('#edit_instrucoes').val(instrucoes); $('#edit_icone').val(icone);
            $('#modalEditarForma').modal('show');
        }
    </script>
</body>
</html>