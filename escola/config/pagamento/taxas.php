<?php
// escola/config/pagamento/taxas.php - Taxas e Multas
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
$check = $conn->query("SHOW TABLES LIKE 'escola_taxas'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_taxas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            tipo ENUM('matricula', 'mensalidade', 'taxa_escolar', 'multa', 'juros', 'outros') DEFAULT 'outros',
            valor DECIMAL(15,2) DEFAULT 0,
            percentual DECIMAL(5,2) DEFAULT 0,
            aplicacao ENUM('fixo', 'percentual') DEFAULT 'fixo',
            periodo VARCHAR(50),
            data_inicio DATE,
            data_fim DATE,
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
    
    if ($acao == 'add_taxa') {
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $valor = str_replace(',', '', $_POST['valor']);
        $percentual = str_replace(',', '.', $_POST['percentual']);
        $aplicacao = $_POST['aplicacao'];
        $periodo = $_POST['periodo'];
        $data_inicio = $_POST['data_inicio'];
        $data_fim = $_POST['data_fim'];
        
        $stmt = $conn->prepare("
            INSERT INTO escola_taxas (escola_id, nome, descricao, tipo, valor, percentual, aplicacao, periodo, data_inicio, data_fim, status)
            VALUES (:escola_id, :nome, :descricao, :tipo, :valor, :percentual, :aplicacao, :periodo, :data_inicio, :data_fim, 'ativo')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':valor' => $valor,
            ':percentual' => $percentual,
            ':aplicacao' => $aplicacao,
            ':periodo' => $periodo,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        
        $_SESSION['mensagem'] = "Taxa adicionada com sucesso!";
        header("Location: taxas.php");
        exit;
    }
    
    if ($acao == 'edit_taxa') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $descricao = $_POST['descricao'];
        $tipo = $_POST['tipo'];
        $valor = str_replace(',', '', $_POST['valor']);
        $percentual = str_replace(',', '.', $_POST['percentual']);
        $aplicacao = $_POST['aplicacao'];
        $periodo = $_POST['periodo'];
        $data_inicio = $_POST['data_inicio'];
        $data_fim = $_POST['data_fim'];
        
        $stmt = $conn->prepare("
            UPDATE escola_taxas 
            SET nome = :nome, descricao = :descricao, tipo = :tipo, valor = :valor, 
                percentual = :percentual, aplicacao = :aplicacao, periodo = :periodo,
                data_inicio = :data_inicio, data_fim = :data_fim
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':valor' => $valor,
            ':percentual' => $percentual,
            ':aplicacao' => $aplicacao,
            ':periodo' => $periodo,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        
        $_SESSION['mensagem'] = "Taxa atualizada!";
        header("Location: taxas.php");
        exit;
    }
}

// Ativar/Desativar
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE escola_taxas SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: taxas.php");
    exit;
}

// Excluir
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM escola_taxas WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Taxa excluída!";
    header("Location: taxas.php");
    exit;
}

// Buscar dados
$taxas = $conn->prepare("SELECT * FROM escola_taxas WHERE escola_id = :escola_id ORDER BY status DESC, nome ASC");
$taxas->execute([':escola_id' => $escola_id]);
$taxas = $taxas->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taxas e Multas | SIGE Angola</title>
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
                    <li class="nav-item"><a href="formas.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-percent"></i> Taxas e Multas</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaTaxa"><i class="fas fa-plus"></i> Nova Taxa</button>
        </div>
        
        <?php if ($mensagem): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Taxas e Multas</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaTaxas">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Nome</th><th>Tipo</th><th>Valor</th><th>Percentual</th><th>Aplicação</th><th>Período</th><th>Vigência</th><th>Status</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taxas as $taxa): ?>
                            <tr>
                                <td><?php echo $taxa['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($taxa['nome']); ?></strong><br><small><?php echo htmlspecialchars($taxa['descricao']); ?></small></div>
                                <td><?php echo ucfirst($taxa['tipo']); ?></div>
                                <td><?php echo $taxa['aplicacao'] == 'fixo' ? number_format($taxa['valor'], 2, ',', '.') . ' Kz' : '-'; ?></div>
                                <td><?php echo $taxa['aplicacao'] == 'percentual' ? $taxa['percentual'] . '%' : '-'; ?></div>
                                <td><?php echo $taxa['aplicacao'] == 'fixo' ? 'Fixo' : 'Percentual'; ?></div>
                                <td><?php echo $taxa['periodo']; ?></div>
                                <td><?php echo $taxa['data_inicio'] ? date('d/m/Y', strtotime($taxa['data_inicio'])) : '-'; ?> até <?php echo $taxa['data_fim'] ? date('d/m/Y', strtotime($taxa['data_fim'])) : '-'; ?></div>
                                <td><span class="badge <?php echo $taxa['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $taxa['status']; ?></span></div>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="editarTaxa(<?php echo $taxa['id']; ?>, '<?php echo addslashes($taxa['nome']); ?>', '<?php echo addslashes($taxa['descricao']); ?>', '<?php echo $taxa['tipo']; ?>', '<?php echo $taxa['valor']; ?>', '<?php echo $taxa['percentual']; ?>', '<?php echo $taxa['aplicacao']; ?>', '<?php echo $taxa['periodo']; ?>', '<?php echo $taxa['data_inicio']; ?>', '<?php echo $taxa['data_fim']; ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?toggle=1&id=<?php echo $taxa['id']; ?>&status=<?php echo $taxa['status']; ?>" class="btn btn-sm btn-success"><i class="fas fa-toggle-<?php echo $taxa['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                    <a href="?delete=1&id=<?php echo $taxa['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Taxa -->
    <div class="modal fade" id="modalNovaTaxa" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus"></i> Nova Taxa</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="add_taxa"><div class="modal-body"><div class="row"><div class="col-md-6 mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" class="form-control"><option value="matricula">Matrícula</option><option value="mensalidade">Mensalidade</option><option value="taxa_escolar">Taxa Escolar</option><option value="multa">Multa</option><option value="juros">Juros</option><option value="outros">Outros</option></select></div></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Aplicação</label><select name="aplicacao" id="aplicacao" class="form-control" onchange="toggleValorPercentual()"><option value="fixo">Valor Fixo (Kz)</option><option value="percentual">Percentual (%)</option></select></div><div class="col-md-6 mb-3" id="div_valor"><label>Valor (Kz)</label><input type="number" step="0.01" name="valor" class="form-control" value="0"></div><div class="col-md-6 mb-3" id="div_percentual" style="display:none"><label>Percentual (%)</label><input type="number" step="0.01" name="percentual" class="form-control" value="0"></div></div><div class="mb-3"><label>Período</label><input type="text" name="periodo" class="form-control" placeholder="Ex: 1º Bimestre, Anual"></div><div class="row"><div class="col-md-6 mb-3"><label>Data Início</label><input type="date" name="data_inicio" class="form-control"></div><div class="col-md-6 mb-3"><label>Data Fim</label><input type="date" name="data_fim" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <!-- Modal Editar Taxa -->
    <div class="modal fade" id="modalEditarTaxa" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar Taxa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="acao" value="edit_taxa"><input type="hidden" name="id" id="edit_id"><div class="modal-body"><div class="row"><div class="col-md-6 mb-3"><label>Nome</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="col-md-6 mb-3"><label>Tipo</label><select name="tipo" id="edit_tipo" class="form-control"><option value="matricula">Matrícula</option><option value="mensalidade">Mensalidade</option><option value="taxa_escolar">Taxa Escolar</option><option value="multa">Multa</option><option value="juros">Juros</option><option value="outros">Outros</option></select></div></div><div class="mb-3"><label>Descrição</label><textarea name="descricao" id="edit_descricao" class="form-control" rows="2"></textarea></div><div class="row"><div class="col-md-6 mb-3"><label>Aplicação</label><select name="aplicacao" id="edit_aplicacao" class="form-control"><option value="fixo">Valor Fixo (Kz)</option><option value="percentual">Percentual (%)</option></select></div><div class="col-md-6 mb-3"><label>Valor/Percentual</label><input type="number" step="0.01" name="valor" id="edit_valor" class="form-control"></div></div><div class="mb-3"><label>Período</label><input type="text" name="periodo" id="edit_periodo" class="form-control"></div><div class="row"><div class="col-md-6 mb-3"><label>Data Início</label><input type="date" name="data_inicio" id="edit_data_inicio" class="form-control"></div><div class="col-md-6 mb-3"><label>Data Fim</label><input type="date" name="data_fim" id="edit_data_fim" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaTaxas').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25 });
        
        function toggleValorPercentual() { var aplicacao = $('#aplicacao').val(); if (aplicacao == 'fixo') { $('#div_valor').show(); $('#div_percentual').hide(); } else { $('#div_valor').hide(); $('#div_percentual').show(); } }
        
        function editarTaxa(id, nome, desc, tipo, valor, percentual, aplicacao, periodo, data_inicio, data_fim) {
            $('#edit_id').val(id); $('#edit_nome').val(nome); $('#edit_descricao').val(desc); $('#edit_tipo').val(tipo);
            $('#edit_aplicacao').val(aplicacao);
            if (aplicacao == 'fixo') { $('#edit_valor').val(valor); } else { $('#edit_valor').val(percentual); }
            $('#edit_periodo').val(periodo); $('#edit_data_inicio').val(data_inicio); $('#edit_data_fim').val(data_fim);
            $('#modalEditarTaxa').modal('show');
        }
    </script>
</body>
</html>