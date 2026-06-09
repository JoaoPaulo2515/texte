<?php
// escola/financeiro/folha_pagamento/holerites.php - Visualização de Holerites
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar informações da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar folhas disponíveis
$folhas = $conn->prepare("
    SELECT * FROM rh_folhas_pagamento 
    WHERE escola_id = :escola_id 
    ORDER BY competencia DESC
");
$folhas->execute([':escola_id' => $escola_id]);
$folhas = $folhas->fetchAll(PDO::FETCH_ASSOC);

// Filtrar por folha específica
$folha_id = $_GET['folha_id'] ?? ($folhas[0]['id'] ?? 0);
$funcionario_id = $_GET['funcionario_id'] ?? 0;

// Buscar holerites da folha selecionada
$holerites = $conn->prepare("
    SELECT fi.*, f.competencia, f.status as folha_status,
           rf.nome as funcionario_nome, rf.numero_funcionario,
           rc.nome as cargo_nome
    FROM rh_folha_itens fi
    JOIN rh_folhas_pagamento f ON f.id = fi.folha_id
    JOIN rh_funcionarios rf ON rf.id = fi.funcionario_id
    LEFT JOIN rh_cargos rc ON rc.id = fi.cargo_id
    WHERE fi.escola_id = :escola_id AND fi.folha_id = :folha_id
");
$holerites->execute([':escola_id' => $escola_id, ':folha_id' => $folha_id]);
$holerites = $holerites->fetchAll(PDO::FETCH_ASSOC);

// Buscar um holerite específico para visualização
$holerite_view = null;
if ($funcionario_id && $folha_id) {
    $stmt = $conn->prepare("
        SELECT fi.*, f.competencia, f.status as folha_status,
               rf.nome as funcionario_nome, rf.numero_funcionario, rf.data_admissao,
               rc.nome as cargo_nome, rc.salario_base as cargo_salario
        FROM rh_folha_itens fi
        JOIN rh_folhas_pagamento f ON f.id = fi.folha_id
        JOIN rh_funcionarios rf ON rf.id = fi.funcionario_id
        LEFT JOIN rh_cargos rc ON rc.id = fi.cargo_id
        WHERE fi.escola_id = :escola_id AND fi.folha_id = :folha_id AND fi.funcionario_id = :funcionario_id
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':folha_id' => $folha_id,
        ':funcionario_id' => $funcionario_id
    ]);
    $holerite_view = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Gerar PDF (simplificado)
if (isset($_GET['pdf']) && isset($_GET['holerite_id'])) {
    $holerite_id = $_GET['holerite_id'];
    
    $stmt = $conn->prepare("
        SELECT fi.*, f.competencia, rf.nome as funcionario_nome, rf.numero_funcionario,
               rc.nome as cargo_nome
        FROM rh_folha_itens fi
        JOIN rh_folhas_pagamento f ON f.id = fi.folha_id
        JOIN rh_funcionarios rf ON rf.id = fi.funcionario_id
        LEFT JOIN rh_cargos rc ON rc.id = fi.cargo_id
        WHERE fi.id = :id AND fi.escola_id = :escola_id
    ");
    $stmt->execute([':id' => $holerite_id, ':escola_id' => $escola_id]);
    $holerite = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($holerite) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Holerite - ' . $holerite['funcionario_nome'] . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .info { margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background: #006B3E; color: white; }
                .text-end { text-align: right; }
                .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
                .total-row { background: #f8f9fa; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>' . htmlspecialchars($escola['nome']) . '</h2>
                <h3>Holerite - ' . date('m/Y', strtotime($holerite['competencia'])) . '</h3>
            </div>
            <div class="info">
                <strong>Funcionário:</strong> ' . htmlspecialchars($holerite['funcionario_nome']) . '<br>
                <strong>Cargo:</strong> ' . htmlspecialchars($holerite['cargo_nome'] ?? '-') . '<br>
                <strong>Nº Funcionário:</strong> ' . $holerite['numero_funcionario'] . '
            </div>
            <table>
                <tr><th colspan="2">PROVENTOS</th><th colspan="2">DESCONTOS</th></tr>
                <tr>
                    <td>Salário Base</td>
                    <td class="text-end">' . number_format($holerite['salario_base'], 2, ',', '.') . '</td>
                    <td>INSS</td>
                    <td class="text-end">' . number_format($holerite['inss'], 2, ',', '.') . '</td>
                </tr>
                <tr>
                    <td>Horas Extras 50%</td>
                    <td class="text-end">' . number_format($holerite['horas_extras_50'] * ($holerite['salario_base'] / 220) * 1.5, 2, ',', '.') . '</td>
                    <td>IRRF</td>
                    <td class="text-end">' . number_format($holerite['irrf'], 2, ',', '.') . '</td>
                </tr>
                <tr>
                    <td>Horas Extras 100%</td>
                    <td class="text-end">' . number_format($holerite['horas_extras_100'] * ($holerite['salario_base'] / 220) * 2, 2, ',', '.') . '</td>
                    <td>Outros Descontos</td>
                    <td class="text-end">' . number_format($holerite['outros_descontos'], 2, ',', '.') . '</td>
                </tr>
                <tr>
                    <td>Adicional Noturno</td>
                    <td class="text-end">' . number_format($holerite['adicional_noturno'], 2, ',', '.') . '</td>
                    <td></td><td></td>
                </tr>
                <tr>
                    <td>Bônus</td>
                    <td class="text-end">' . number_format($holerite['bonus'], 2, ',', '.') . '</td>
                    <td></td><td></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL PROVENTOS</td>
                    <td class="text-end">' . number_format($holerite['total_proventos'], 2, ',', '.') . '</td>
                    <td>TOTAL DESCONTOS</td>
                    <td class="text-end">' . number_format($holerite['total_descontos'], 2, ',', '.') . '</td>
                </tr>
            </table>
            <div class="text-end" style="font-size: 1.2em; margin-top: 20px;">
                <strong>VALOR LÍQUIDO: ' . number_format($holerite['valor_liquido'], 2, ',', '.') . ' Kz</strong>
            </div>
            <div class="footer">
                <p>Documento gerado por SIGE Angola - Sistema Integrado de Gestão Escolar</p>
                <p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>
            </div>
        </body>
        </html>';
        exit;
    }
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holerites | Folha de Pagamento | SIGE Angola</title>
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
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .holerite-card { background: white; border-radius: 10px; margin-bottom: 15px; transition: transform 0.2s; }
        .holerite-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .holerite-view {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .holerite-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #006B3E;
        }
        .holerite-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .holerite-table th { background: #006B3E; color: white; }
        .holerite-total { font-size: 1.2em; font-weight: bold; }
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
                    <li class="nav-item"><a href="cargos.php" class="nav-link"><i class="fas fa-briefcase"></i> Cargos</a></li>
                    <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link active"><i class="fas fa-receipt"></i> Holerites</a></li>
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
                <i class="fas fa-receipt"></i> Holerites
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label>Competência</label>
                    <select name="folha_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($folhas as $f): ?>
                        <option value="<?php echo $f['id']; ?>" <?php echo $folha_id == $f['id'] ? 'selected' : ''; ?>>
                            <?php echo date('m/Y', strtotime($f['competencia'])); ?> - <?php echo ucfirst($f['status']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-list"></i> Funcionários
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($holerites as $holerite): ?>
                            <a href="?folha_id=<?php echo $folha_id; ?>&funcionario_id=<?php echo $holerite['funcionario_id']; ?>" 
                               class="list-group-item list-group-item-action <?php echo ($funcionario_id == $holerite['funcionario_id']) ? 'active' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($holerite['funcionario_nome']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($holerite['cargo_nome'] ?? '-'); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success"><?php echo number_format($holerite['valor_liquido'], 2, ',', '.'); ?> Kz</span>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7">
                <?php if ($holerite_view): ?>
                <div class="holerite-view">
                    <div class="holerite-header">
                        <h3><?php echo htmlspecialchars($escola['nome']); ?></h3>
                        <h4>Holerite - <?php echo date('m/Y', strtotime($holerite_view['competencia'])); ?></h4>
                    </div>
                    
                    <div class="holerite-info">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Funcionário:</strong> <?php echo htmlspecialchars($holerite_view['funcionario_nome']); ?></p>
                                <p class="mb-1"><strong>Cargo:</strong> <?php echo htmlspecialchars($holerite_view['cargo_nome'] ?? '-'); ?></p>
                                <p class="mb-1"><strong>Nº Funcionário:</strong> <?php echo $holerite_view['numero_funcionario']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Data Admissão:</strong> <?php echo date('d/m/Y', strtotime($holerite_view['data_admissao'])); ?></p>
                                <p class="mb-1"><strong>Dias Trabalhados:</strong> <?php echo $holerite_view['dias_trabalhados']; ?></p>
                                <p class="mb-1"><strong>Faltas:</strong> <?php echo $holerite_view['faltas']; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered holerite-table">
                            <thead>
                                <tr><th colspan="2">PROVENTOS</th><th colspan="2">DESCONTOS</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Salário Base</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['salario_base'], 2, ',', '.'); ?> Kz</td>
                                    <td>INSS</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['inss'], 2, ',', '.'); ?> Kz</td>
                                </tr>
                                <tr>
                                    <td>Horas Extras 50%</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['horas_extras_50'] * ($holerite_view['salario_base'] / 220) * 1.5, 2, ',', '.'); ?> Kz</td>
                                    <td>IRRF</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['irrf'], 2, ',', '.'); ?> Kz</td>
                                </tr>
                                <tr>
                                    <td>Horas Extras 100%</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['horas_extras_100'] * ($holerite_view['salario_base'] / 220) * 2, 2, ',', '.'); ?> Kz</td>
                                    <td>Outros Descontos</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['outros_descontos'], 2, ',', '.'); ?> Kz</td>
                                </tr>
                                <tr>
                                    <td>Adicional Noturno</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['adicional_noturno'], 2, ',', '.'); ?> Kz</td>
                                    <td></td><td></td>
                                </tr>
                                <tr>
                                    <td>Bônus</td>
                                    <td class="text-end"><?php echo number_format($holerite_view['bonus'], 2, ',', '.'); ?> Kz</td>
                                    <td></td><td></td>
                                </tr>
                                <tr class="table-secondary">
                                    <td><strong>TOTAL PROVENTOS</strong></td>
                                    <td class="text-end"><strong><?php echo number_format($holerite_view['total_proventos'], 2, ',', '.'); ?> Kz</strong></td>
                                    <td><strong>TOTAL DESCONTOS</strong></td>
                                    <td class="text-end"><strong><?php echo number_format($holerite_view['total_descontos'], 2, ',', '.'); ?> Kz</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end holerite-total">
                        <h4>VALOR LÍQUIDO: <?php echo number_format($holerite_view['valor_liquido'], 2, ',', '.'); ?> Kz</h4>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="?pdf=1&holerite_id=<?php echo $holerite_view['id']; ?>" class="btn btn-danger" target="_blank">
                            <i class="fas fa-file-pdf"></i> Baixar PDF
                        </a>
                        <button class="btn btn-secondary" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-hand-point-left fa-3x text-muted mb-3"></i>
                        <h5>Selecione um funcionário para visualizar o holerite</h5>
                        <p class="text-muted">Clique no nome do funcionário na lista ao lado</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Holerites</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Holerites?</h6>
                    <p>Holerites são documentos que detalham os proventos e descontos do salário do funcionário.</p>
                    
                    <h6><i class="fas fa-file-pdf text-danger"></i> Funcionalidades:</h6>
                    <ul>
                        <li><strong>Visualização:</strong> Consulte os holerites por competência.</li>
                        <li><strong>Download PDF:</strong> Baixe o holerite em formato PDF.</li>
                        <li><strong>Impressão:</strong> Imprima diretamente o holerite.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Emita os holerites após o processamento da folha.</li>
                        <li>Arquive os PDFs para controle.</li>
                        <li>Disponibilize para os funcionários.</li>
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
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>