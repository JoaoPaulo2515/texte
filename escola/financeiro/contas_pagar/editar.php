<?php
// escola/financeiro/contas_pagar/editar.php - Editar Conta a Pagar
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

$id = $_GET['id'] ?? 0;

// Buscar conta
$stmt = $conn->prepare("SELECT * FROM escola_contas_pagar WHERE id = :id AND escola_id = :escola_id");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$conta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conta) {
    $_SESSION['erro'] = "Conta não encontrada!";
    header("Location: index.php");
    exit;
}

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

$categorias = [
    'material' => 'Material',
    'servico' => 'Serviço',
    'utilidade' => 'Utilidade',
    'salario' => 'Salário',
    'imposto' => 'Imposto',
    'outro' => 'Outro'
];

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fornecedor = $_POST['fornecedor'];
    $documento = $_POST['documento'];
    $descricao = $_POST['descricao'];
    $valor = str_replace(',', '', $_POST['valor']);
    $data_emissao = $_POST['data_emissao'];
    $data_vencimento = $_POST['data_vencimento'];
    $categoria = $_POST['categoria'];
    $parcela = $_POST['parcela'];
    $total_parcelas = $_POST['total_parcelas'];
    $observacoes = $_POST['observacoes'];
    
    $stmt = $conn->prepare("
        UPDATE escola_contas_pagar 
        SET fornecedor = :fornecedor, documento = :documento, descricao = :descricao, 
            valor = :valor, data_emissao = :data_emissao, data_vencimento = :data_vencimento,
            categoria = :categoria, parcela = :parcela, total_parcelas = :total_parcelas, 
            observacoes = :observacoes
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':fornecedor' => $fornecedor,
        ':documento' => $documento,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':data_emissao' => $data_emissao,
        ':data_vencimento' => $data_vencimento,
        ':categoria' => $categoria,
        ':parcela' => $parcela,
        ':total_parcelas' => $total_parcelas,
        ':observacoes' => $observacoes
    ]);
    
    $_SESSION['mensagem'] = "Conta atualizada com sucesso!";
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Conta a Pagar | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-arrow-down"></i> Contas a Pagar</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar"><h2><i class="fas fa-edit"></i> Editar Conta a Pagar</h2><a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a></div>
        
        <div class="card">
            <div class="card-header bg-warning"><i class="fas fa-edit"></i> Editar Conta</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="required">Fornecedor</label><input type="text" name="fornecedor" class="form-control" value="<?php echo htmlspecialchars($conta['fornecedor']); ?>" required></div>
                        <div class="col-md-6 mb-3"><label>Documento/Nº</label><input type="text" name="documento" class="form-control" value="<?php echo htmlspecialchars($conta['documento']); ?>"></div>
                    </div>
                    <div class="mb-3"><label class="required">Descrição</label><textarea name="descricao" class="form-control" rows="2" required><?php echo htmlspecialchars($conta['descricao']); ?></textarea></div>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="required">Valor (Kz)</label><input type="number" step="0.01" name="valor" class="form-control" value="<?php echo $conta['valor']; ?>" required></div>
                        <div class="col-md-4 mb-3"><label class="required">Data de Emissão</label><input type="date" name="data_emissao" class="form-control" value="<?php echo $conta['data_emissao']; ?>" required></div>
                        <div class="col-md-4 mb-3"><label class="required">Data de Vencimento</label><input type="date" name="data_vencimento" class="form-control" value="<?php echo $conta['data_vencimento']; ?>" required></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="required">Categoria</label><select name="categoria" class="form-control" required><?php foreach ($categorias as $key => $cat): ?><option value="<?php echo $key; ?>" <?php echo $conta['categoria'] == $key ? 'selected' : ''; ?>><?php echo $cat; ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4 mb-3"><label>Parcela</label><input type="number" name="parcela" class="form-control" value="<?php echo $conta['parcela']; ?>" min="1"></div>
                        <div class="col-md-4 mb-3"><label>Total de Parcelas</label><input type="number" name="total_parcelas" class="form-control" value="<?php echo $conta['total_parcelas']; ?>" min="1"></div>
                    </div>
                    <div class="mb-3"><label>Observações</label><textarea name="observacoes" class="form-control" rows="2"><?php echo htmlspecialchars($conta['observacoes']); ?></textarea></div>
                    <div class="text-center"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button><a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a></div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        if (window.location.pathname.includes('financeiro')) { $('#menuFinanceiro').addClass('open'); $('#submenuFinanceiro').addClass('show'); }
    </script>
</body>
</html>