<?php
// escola/config/geral/index.php - Configurações Gerais
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar ações
$acao = $_GET['acao'] ?? '';
$id = $_GET['id'] ?? 0;
$tabela = $_GET['tabela'] ?? '';

// Ativar/Desativar registro
if ($acao == 'toggle' && $id && $tabela) {
    $stmt = $conn->prepare("SELECT status FROM $tabela WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $status_atual = $stmt->fetch(PDO::FETCH_ASSOC)['status'] ?? 'inativo';
    $novo_status = $status_atual == 'ativo' ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE $tabela SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $novo_status, ':id' => $id]);
    $_SESSION['mensagem'] = "Registro " . ($novo_status == 'ativo' ? 'ativado' : 'desativado') . " com sucesso!";
    header("Location: index.php");
    exit;
}

// Excluir registro
if ($acao == 'delete' && $id && $tabela) {
    $stmt = $conn->prepare("DELETE FROM $tabela WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $_SESSION['mensagem'] = "Registro excluído com sucesso!";
    header("Location: index.php");
    exit;
}

// Buscar dados
$anos_letivos = $conn->prepare("SELECT * FROM anos_letivos WHERE escola_id = :escola_id ORDER BY ano DESC");
$anos_letivos->execute([':escola_id' => $escola_id]);
$anos_letivos = $anos_letivos->fetchAll(PDO::FETCH_ASSOC);

$meses = $conn->prepare("SELECT * FROM meses ORDER BY numero");
$meses->execute();
$meses = $meses->fetchAll(PDO::FETCH_ASSOC);

$paises = $conn->prepare("SELECT * FROM paises ORDER BY nome");
$paises->execute();
$paises = $paises->fetchAll(PDO::FETCH_ASSOC);

$provincias = $conn->prepare("SELECT * FROM angola_provincias ORDER BY nome");
$provincias->execute();
$provincias = $provincias->fetchAll(PDO::FETCH_ASSOC);

$comunas = $conn->prepare("
    SELECT c.*, m.nome as municipio_nome, p.nome as provincia_nome 
    FROM angola_comunas c
    JOIN angola_municipios m ON m.id = c.municipio_id
    JOIN angola_provincias p ON p.id = m.provincia_id
    ORDER BY p.nome, m.nome, c.nome
");
$comunas->execute();
$comunas = $comunas->fetchAll(PDO::FETCH_ASSOC);

$bairros = $conn->prepare("
    SELECT b.*, c.nome as comuna_nome, m.nome as municipio_nome, p.nome as provincia_nome
    FROM bairros b
    JOIN angola_comunas c ON c.id = b.comuna_id
    JOIN angola_municipios m ON m.id = c.municipio_id
    JOIN angola_provincias p ON p.id = m.provincia_id
    ORDER BY p.nome, m.nome, c.nome, b.nome
");
$bairros->execute();
$bairros = $bairros->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações Gerais | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .table-responsive { overflow-x: auto; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
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
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfig">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-cogs"></i> <span>Configurações</span>
                </a>
                <ul class="nav-submenu show" id="submenuConfig">
                    <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/index.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
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
            <h2><i class="fas fa-globe"></i> Configurações Gerais</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovoRegistro">
                <i class="fas fa-plus"></i> Novo Registro
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="configTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#anosLetivos">Anos Letivos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#meses">Meses</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#paises">Países</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#provincias">Províncias</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#comunas">Comunas</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#bairros">Bairros</button></li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    <!-- Anos Letivos -->
                    <div class="tab-pane fade show active" id="anosLetivos">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr><th>ID</th><th>Ano</th><th>Data Início</th><th>Data Fim</th><th>Status</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($anos_letivos as $ano): ?>
                                    <tr>
                                        <td><?php echo $ano['id']; ?></td>
                                        <td><?php echo $ano['ano']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($ano['data_inicio'])); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($ano['data_fim'])); ?></td>
                                        <td><span class="badge <?php echo $ano['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $ano['status']; ?></span></td>
                                        <td>
                                            <a href="?acao=toggle&id=<?php echo $ano['id']; ?>&tabela=anos_letivos" class="btn btn-sm btn-warning"><i class="fas fa-toggle-<?php echo $ano['status'] == 'ativo' ? 'off' : 'on'; ?>"></i></a>
                                            <a href="?acao=delete&id=<?php echo $ano['id']; ?>&tabela=anos_letivos" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Meses -->
                    <div class="tab-pane fade" id="meses">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light"><tr><th>ID</th><th>Mês</th><th>Número</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php foreach ($meses as $mes): ?>
                                    <tr>
                                        <td><?php echo $mes['id']; ?></td>
                                        <td><?php echo $mes['nome']; ?></td>
                                        <td><?php echo $mes['numero']; ?></td>
                                        <td>
                                            <a href="editar_mes.php?id=<?php echo $mes['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                            <a href="?acao=delete&id=<?php echo $mes['id']; ?>&tabela=meses" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Países -->
                    <div class="tab-pane fade" id="paises">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light"><tr><th>ID</th><th>Nome</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php foreach ($paises as $pais): ?>
                                    <tr>
                                        <td><?php echo $pais['id']; ?></td>
                                        <td><?php echo $pais['nome']; ?></td>
                                        <td>
                                            <a href="editar_pais.php?id=<?php echo $pais['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                            <a href="?acao=delete&id=<?php echo $pais['id']; ?>&tabela=paises" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Províncias -->
                    <div class="tab-pane fade" id="provincias">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light"><tr><th>ID</th><th>Nome</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php foreach ($provincias as $provincia): ?>
                                    <tr>
                                        <td><?php echo $provincia['id']; ?></td>
                                        <td><?php echo $provincia['nome']; ?></td>
                                        <td>
                                            <a href="editar_provincia.php?id=<?php echo $provincia['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                            <a href="?acao=delete&id=<?php echo $provincia['id']; ?>&tabela=angola_provincias" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Comunas -->
                    <div class="tab-pane fade" id="comunas">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr><th>ID</th><th>Nome</th><th>Município</th><th>Província</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comunas as $comuna): ?>
                                    <tr>
                                        <td><?php echo $comuna['id']; ?></td>
                                        <td><?php echo $comuna['nome']; ?></td>
                                        <td><?php echo $comuna['municipio_nome']; ?></td>
                                        <td><?php echo $comuna['provincia_nome']; ?></td>
                                        <td>
                                            <a href="editar_comuna.php?id=<?php echo $comuna['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                            <a href="?acao=delete&id=<?php echo $comuna['id']; ?>&tabela=angola_comunas" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Bairros -->
                    <div class="tab-pane fade" id="bairros">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr><th>ID</th><th>Nome</th><th>Comuna</th><th>Município</th><th>Província</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bairros as $bairro): ?>
                                    <tr>
                                        <td><?php echo $bairro['id']; ?></td>
                                        <td><?php echo $bairro['nome']; ?></td>
                                        <td><?php echo $bairro['comuna_nome']; ?></td>
                                        <td><?php echo $bairro['municipio_nome']; ?></td>
                                        <td><?php echo $bairro['provincia_nome']; ?></td>
                                        <td>
                                            <a href="editar_bairro.php?id=<?php echo $bairro['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                                            <a href="?acao=delete&id=<?php echo $bairro['id']; ?>&tabela=bairros" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Registro -->
    <div class="modal fade" id="modalNovoRegistro" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title">Adicionar Novo Registro</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST" action="salvar.php">
                    <div class="modal-body">
                        <div class="mb-3"><label>Tipo</label><select name="tipo" class="form-control" required><option value="">Selecione...</option><option value="ano_letivo">Ano Letivo</option><option value="mes">Mês</option><option value="pais">País</option><option value="provincia">Província</option><option value="comuna">Comuna</option><option value="bairro">Bairro</option></select></div>
                        <div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div>
                        <div id="campos_extras"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        
        $('select[name="tipo"]').change(function() {
            var tipo = $(this).val();
            var extras = '';
            if (tipo == 'ano_letivo') extras = '<div class="mb-3"><label>Data Início</label><input type="date" name="data_inicio" class="form-control"></div><div class="mb-3"><label>Data Fim</label><input type="date" name="data_fim" class="form-control"></div>';
            if (tipo == 'mes') extras = '<div class="mb-3"><label>Número do Mês</label><input type="number" name="numero" class="form-control" min="1" max="12"></div>';
            if (tipo == 'comuna') extras = '<div class="mb-3"><label>Município ID</label><input type="number" name="municipio_id" class="form-control" placeholder="ID do Município"></div>';
            if (tipo == 'bairro') extras = '<div class="mb-3"><label>Comuna ID</label><input type="number" name="comuna_id" class="form-control" placeholder="ID da Comuna"></div>';
            $('#campos_extras').html(extras);
        });
    </script>
</body>
</html>