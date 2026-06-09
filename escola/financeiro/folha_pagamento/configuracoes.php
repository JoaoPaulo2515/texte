<?php
// escola/financeiro/folha_pagamento/configuracoes.php - Configurações da Folha
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Verificar se escola_id é numérico
if (!is_numeric($_SESSION['escola_id'])) {
    session_destroy();
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// TABELAS PADRÃO (ANGOLA 2024)
// ============================================

// Tabela INSS Angola 2024
$tabela_inss_padrao = [
    ['faixa' => 'Até 100.000 Kz', 'limite' => 100000, 'aliquota' => 3, 'deducao' => 0],
    ['faixa' => 'De 100.001 a 200.000 Kz', 'limite' => 200000, 'aliquota' => 6, 'deducao' => 3000],
    ['faixa' => 'De 200.001 a 350.000 Kz', 'limite' => 350000, 'aliquota' => 9, 'deducao' => 9000],
    ['faixa' => 'Acima de 350.000 Kz', 'limite' => 999999999, 'aliquota' => 12, 'deducao' => 19500]
];

// Tabela IRRF Angola 2024 (Anexo I - Rendimentos do Trabalho)
$tabela_irrf_padrao = [
    ['faixa' => 'Até 100.000 Kz', 'limite' => 100000, 'aliquota' => 0, 'deducao' => 0],
    ['faixa' => 'De 100.001 a 200.000 Kz', 'limite' => 200000, 'aliquota' => 10, 'deducao' => 10000],
    ['faixa' => 'De 200.001 a 350.000 Kz', 'limite' => 350000, 'aliquota' => 15, 'deducao' => 20000],
    ['faixa' => 'De 350.001 a 500.000 Kz', 'limite' => 500000, 'aliquota' => 20, 'deducao' => 37500],
    ['faixa' => 'Acima de 500.000 Kz', 'limite' => 999999999, 'aliquota' => 25, 'deducao' => 62500]
];

// ============================================
// PROCESSAR CONFIGURAÇÕES
// ============================================

// Salvar configurações gerais
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_config') {
    try {
        $configs = [
            'vale_transporte_percentual' => $_POST['vale_transporte_percentual'],
            'vale_refeicao_valor' => $_POST['vale_refeicao_valor'],
            'horas_semanais' => $_POST['horas_semanais'],
            'salario_minimo' => $_POST['salario_minimo'],
            'decimo_terceiro' => isset($_POST['decimo_terceiro']) ? 1 : 0,
            'ferias_proporcionais' => isset($_POST['ferias_proporcionais']) ? 1 : 0,
            'notificacao_email' => $_POST['notificacao_email'],
            'dias_pagamento' => $_POST['dias_pagamento']
        ];
        
        foreach ($configs as $chave => $valor) {
            $parametro = 'folha_' . $chave;
            // Usar abordagem diferente para evitar erro de parâmetros
            $stmt = $conn->prepare("
                INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE valor = ?
            ");
            $stmt->execute([$escola_id, $parametro, $valor, $valor]);
        }
        
        $_SESSION['mensagem'] = "Configurações salvas com sucesso!";
        header("Location: configuracoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem'] = "Erro ao salvar configurações: " . $e->getMessage();
        header("Location: configuracoes.php");
        exit;
    }
}

// Salvar tabela INSS
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_inss') {
    try {
        $faixas = $_POST['faixas'] ?? [];
        $limites = $_POST['limites'] ?? [];
        $aliquotas = $_POST['aliquotas'] ?? [];
        $deducoes = $_POST['deducoes'] ?? [];
        
        $tabela_json = [];
        for ($i = 0; $i < count($faixas); $i++) {
            // Converter formato angolano (1.000.000,00) para número
            $limite_limpo = str_replace(['.', ','], ['', '.'], $limites[$i]);
            $deducao_limpa = str_replace(['.', ','], ['', '.'], $deducoes[$i]);
            
            $tabela_json[] = [
                'faixa' => $faixas[$i],
                'limite' => floatval($limite_limpo),
                'aliquota' => floatval($aliquotas[$i]),
                'deducao' => floatval($deducao_limpa)
            ];
        }
        
        $valor_json = json_encode($tabela_json);
        
        $stmt = $conn->prepare("
            INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
            VALUES (?, 'folha_tabela_inss', ?)
            ON DUPLICATE KEY UPDATE valor = ?
        ");
        $stmt->execute([$escola_id, $valor_json, $valor_json]);
        
        $_SESSION['mensagem'] = "Tabela INSS atualizada com sucesso!";
        header("Location: configuracoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem'] = "Erro ao salvar tabela INSS: " . $e->getMessage();
        header("Location: configuracoes.php");
        exit;
    }
}

// Salvar tabela IRRF
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_irrf') {
    try {
        $faixas = $_POST['faixas'] ?? [];
        $limites = $_POST['limites'] ?? [];
        $aliquotas = $_POST['aliquotas'] ?? [];
        $deducoes = $_POST['deducoes'] ?? [];
        
        $tabela_json = [];
        for ($i = 0; $i < count($faixas); $i++) {
            // Converter formato angolano (1.000.000,00) para número
            $limite_limpo = str_replace(['.', ','], ['', '.'], $limites[$i]);
            $deducao_limpa = str_replace(['.', ','], ['', '.'], $deducoes[$i]);
            
            $tabela_json[] = [
                'faixa' => $faixas[$i],
                'limite' => floatval($limite_limpo),
                'aliquota' => floatval($aliquotas[$i]),
                'deducao' => floatval($deducao_limpa)
            ];
        }
        
        $valor_json = json_encode($tabela_json);
        
        $stmt = $conn->prepare("
            INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
            VALUES (?, 'folha_tabela_irrf', ?)
            ON DUPLICATE KEY UPDATE valor = ?
        ");
        $stmt->execute([$escola_id, $valor_json, $valor_json]);
        
        $_SESSION['mensagem'] = "Tabela IRRF atualizada com sucesso!";
        header("Location: configuracoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem'] = "Erro ao salvar tabela IRRF: " . $e->getMessage();
        header("Location: configuracoes.php");
        exit;
    }
}

// Restaurar tabelas padrão
if (isset($_GET['restaurar']) && $_GET['restaurar'] == 'inss') {
    try {
        $valor_json = json_encode($tabela_inss_padrao);
        
        // Primeiro tentar atualizar se existir
        $stmt = $conn->prepare("
            UPDATE escola_parametros_sistema 
            SET valor = ? 
            WHERE escola_id = ? AND parametro = 'folha_tabela_inss'
        ");
        $stmt->execute([$valor_json, $escola_id]);
        
        // Se não atualizou nenhuma linha, inserir
        if ($stmt->rowCount() == 0) {
            $stmt = $conn->prepare("
                INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
                VALUES (?, 'folha_tabela_inss', ?)
            ");
            $stmt->execute([$escola_id, $valor_json]);
        }
        
        $_SESSION['mensagem'] = "Tabela INSS restaurada para os valores padrão!";
        header("Location: configuracoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem'] = "Erro ao restaurar tabela INSS: " . $e->getMessage();
        header("Location: configuracoes.php");
        exit;
    }
}

if (isset($_GET['restaurar']) && $_GET['restaurar'] == 'irrf') {
    try {
        $valor_json = json_encode($tabela_irrf_padrao);
        
        // Primeiro tentar atualizar se existir
        $stmt = $conn->prepare("
            UPDATE escola_parametros_sistema 
            SET valor = ? 
            WHERE escola_id = ? AND parametro = 'folha_tabela_irrf'
        ");
        $stmt->execute([$valor_json, $escola_id]);
        
        // Se não atualizou nenhuma linha, inserir
        if ($stmt->rowCount() == 0) {
            $stmt = $conn->prepare("
                INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
                VALUES (?, 'folha_tabela_irrf', ?)
            ");
            $stmt->execute([$escola_id, $valor_json]);
        }
        
        $_SESSION['mensagem'] = "Tabela IRRF restaurada para os valores padrão!";
        header("Location: configuracoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem'] = "Erro ao restaurar tabela IRRF: " . $e->getMessage();
        header("Location: configuracoes.php");
        exit;
    }
}

// ============================================
// BUSCAR CONFIGURAÇÕES ATUAIS
// ============================================

$configs_padrao = [
    'vale_transporte_percentual' => '6',
    'vale_refeicao_valor' => '15',
    'horas_semanais' => '44',
    'salario_minimo' => '100000',
    'decimo_terceiro' => '1',
    'ferias_proporcionais' => '1',
    'notificacao_email' => '',
    'dias_pagamento' => '5'
];

$configs = [];
foreach ($configs_padrao as $chave => $valor_padrao) {
    $stmt = $conn->prepare("
        SELECT valor FROM escola_parametros_sistema 
        WHERE escola_id = ? AND parametro = ?
    ");
    $stmt->execute([$escola_id, 'folha_' . $chave]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $configs[$chave] = $row['valor'] ?? $valor_padrao;
}

// Buscar tabela INSS
$stmt = $conn->prepare("
    SELECT valor FROM escola_parametros_sistema 
    WHERE escola_id = ? AND parametro = 'folha_tabela_inss'
");
$stmt->execute([$escola_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$tabela_inss = $row ? json_decode($row['valor'], true) : $tabela_inss_padrao;

// Buscar tabela IRRF
$stmt = $conn->prepare("
    SELECT valor FROM escola_parametros_sistema 
    WHERE escola_id = ? AND parametro = 'folha_tabela_irrf'
");
$stmt->execute([$escola_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$tabela_irrf = $row ? json_decode($row['valor'], true) : $tabela_irrf_padrao;

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações | Folha de Pagamento | SIGE Angola</title>
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
        
        .config-section { margin-bottom: 25px; }
        .config-section h5 { color: #006B3E; margin-bottom: 15px; padding-bottom: 5px; border-bottom: 1px solid #ddd; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .required:after { content: "*"; color: red; margin-left: 5px; }
        
        .tabela-faixa {
            transition: background-color 0.3s;
            cursor: pointer;
        }
        .tabela-faixa:hover {
            background-color: #e8f5e9;
        }
        .tabela-faixa.selecionada {
            background-color: #c8e6c9;
            border-left: 3px solid #006B3E;
        }
        .btn-atualizar-auto {
            background: #ffc107;
            color: #000;
        }
        .btn-atualizar-auto:hover {
            background: #e0a800;
            color: #000;
        }
        .observacao {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 5px;
        }
        .badge-angola {
            background: #006B3E;
            color: white;
            font-size: 0.7em;
        }
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
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="cargos.php" class="nav-link"><i class="fas fa-briefcase"></i> Cargos</a></li>
                    <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
                    <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link active"><i class="fas fa-cog"></i> Configurações</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-cog"></i> Configurações da Folha
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <div>
                <button class="btn btn-info btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalTabelaINSS">
                    <i class="fas fa-chart-line"></i> Tabela INSS
                </button>
                <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalTabelaIRRF">
                    <i class="fas fa-chart-line"></i> Tabela IRRF
                </button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($mensagem); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="acao" value="salvar_config">
            
            <div class="card">
                <div class="card-header" style="background: #006B3E; color: white;">
                    <i class="fas fa-gavel"></i> Parâmetros Legais (Angola)
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Salário Mínimo Nacional (Kz)</label>
                                <input type="number" step="0.01" name="salario_minimo" class="form-control" value="<?php echo $configs['salario_minimo']; ?>">
                                <small class="text-muted">Referência para cálculos de INSS e IRRF</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Dia de Pagamento</label>
                                <select name="dias_pagamento" class="form-control">
                                    <option value="5" <?php echo $configs['dias_pagamento'] == '5' ? 'selected' : ''; ?>>Dia 5</option>
                                    <option value="10" <?php echo $configs['dias_pagamento'] == '10' ? 'selected' : ''; ?>>Dia 10</option>
                                    <option value="15" <?php echo $configs['dias_pagamento'] == '15' ? 'selected' : ''; ?>>Dia 15</option>
                                    <option value="20" <?php echo $configs['dias_pagamento'] == '20' ? 'selected' : ''; ?>>Dia 20</option>
                                    <option value="25" <?php echo $configs['dias_pagamento'] == '25' ? 'selected' : ''; ?>>Dia 25</option>
                                    <option value="ultimo" <?php echo $configs['dias_pagamento'] == 'ultimo' ? 'selected' : ''; ?>>Último dia útil</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" name="decimo_terceiro" class="form-check-input" id="decimo_terceiro" value="1" <?php echo $configs['decimo_terceiro'] == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="decimo_terceiro">Calcular 13º Salário</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" name="ferias_proporcionais" class="form-check-input" id="ferias_proporcionais" value="1" <?php echo $configs['ferias_proporcionais'] == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ferias_proporcionais">Calcular Férias Proporcionais</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header" style="background: #006B3E; color: white;">
                    <i class="fas fa-bus"></i> Benefícios
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Vale Transporte (%)</label>
                                <input type="number" step="0.01" name="vale_transporte_percentual" class="form-control" value="<?php echo $configs['vale_transporte_percentual']; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Vale Refeição (Kz/dia)</label>
                                <input type="number" step="0.01" name="vale_refeicao_valor" class="form-control" value="<?php echo $configs['vale_refeicao_valor']; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Horas Semanais</label>
                                <input type="number" name="horas_semanais" class="form-control" value="<?php echo $configs['horas_semanais']; ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header" style="background: #006B3E; color: white;">
                    <i class="fas fa-envelope"></i> Notificações
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label>E-mail para Notificações</label>
                        <input type="email" name="notificacao_email" class="form-control" value="<?php echo $configs['notificacao_email']; ?>">
                        <small class="text-muted">Receber alertas sobre processamento da folha</small>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
                <a href="index.php" class="btn btn-secondary btn-lg"><i class="fas fa-times"></i> Cancelar</a>
            </div>
        </form>
    </div>
    
    <!-- Modal Tabela INSS -->
    <div class="modal fade" id="modalTabelaINSS" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #17a2b8; color: white;">
                    <h5 class="modal-title"><i class="fas fa-chart-line"></i> Tabela INSS - Angola</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Tabela INSS - Angola (Lei Geral do Trabalho)</strong><br>
                        Contribuição obrigatória para a Segurança Social. As alíquotas são aplicadas sobre o salário bruto.
                    </div>
                    
                    <form method="POST" id="formINSS">
                        <input type="hidden" name="acao" value="salvar_inss">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="tabelaINSS">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Faixa Salarial</th>
                                        <th>Limite Superior (Kz)</th>
                                        <th>Alíquota (%)</th>
                                        <th>Dedução (Kz)</th>
                                    </thead>
                                <tbody id="tbodyINSS">
                                    <?php foreach ($tabela_inss as $index => $faixa): ?>
                                    <tr class="tabela-faixa" data-index="<?php echo $index; ?>">
                                        <td><input type="text" name="faixas[]" class="form-control" value="<?php echo htmlspecialchars($faixa['faixa']); ?>" style="min-width: 150px;"></td>
                                        <td><input type="text" name="limites[]" class="form-control limite" value="<?php echo number_format($faixa['limite'], 0, ',', '.'); ?>"></td>
                                        <td><input type="number" step="0.01" name="aliquotas[]" class="form-control aliquota" value="<?php echo $faixa['aliquota']; ?>"></td>
                                        <td><input type="text" name="deducoes[]" class="form-control deducao" value="<?php echo number_format($faixa['deducao'], 0, ',', '.'); ?>"></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4">
                                            <div class="observacao">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Como calcular:</strong> INSS = (Salário Bruto × Alíquota) - Dedução
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                             </table>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Observação:</strong> Esta tabela segue a legislação angolana. Qualquer alteração deve ser baseada em decreto oficial.
                        </div>
                        
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-success" onclick="atualizarPorSalarioMinimoINSS()">
                                <i class="fas fa-sync-alt"></i> Aplicar com Base no Salário Mínimo
                            </button>
                            <button type="button" class="btn btn-warning" onclick="restaurarTabelaINSS()">
                                <i class="fas fa-undo"></i> Restaurar Padrão
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Tabela
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Tabela IRRF -->
    <div class="modal fade" id="modalTabelaIRRF" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #ffc107; color: #000;">
                    <h5 class="modal-title"><i class="fas fa-chart-line"></i> Tabela IRRF - Angola</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Tabela IRRF - Angola (Imposto sobre Rendimentos)</strong><br>
                        Aplicado sobre o salário bruto após dedução do INSS.
                    </div>
                    
                    <form method="POST" id="formIRRF">
                        <input type="hidden" name="acao" value="salvar_irrf">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="tabelaIRRF">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Faixa Salarial</th>
                                        <th>Limite Superior (Kz)</th>
                                        <th>Alíquota (%)</th>
                                        <th>Dedução (Kz)</th>
                                    </thead>
                                <tbody id="tbodyIRRF">
                                    <?php foreach ($tabela_irrf as $index => $faixa): ?>
                                    <tr class="tabela-faixa" data-index="<?php echo $index; ?>">
                                        <td><input type="text" name="faixas[]" class="form-control" value="<?php echo htmlspecialchars($faixa['faixa']); ?>" style="min-width: 150px;"></td>
                                        <td><input type="text" name="limites[]" class="form-control limite" value="<?php echo number_format($faixa['limite'], 0, ',', '.'); ?>"></td>
                                        <td><input type="number" step="0.01" name="aliquotas[]" class="form-control aliquota" value="<?php echo $faixa['aliquota']; ?>"></td>
                                        <td><input type="text" name="deducoes[]" class="form-control deducao" value="<?php echo number_format($faixa['deducao'], 0, ',', '.'); ?>"></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4">
                                            <div class="observacao">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Como calcular:</strong> IRRF = (Salário Bruto - INSS) × Alíquota - Dedução
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                             </table>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Observação:</strong> Esta tabela segue o Código Fiscal Angolano. Atualize conforme publicação oficial.
                        </div>
                        
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-success" onclick="atualizarPorSalarioMinimoIRRF()">
                                <i class="fas fa-sync-alt"></i> Aplicar com Base no Salário Mínimo
                            </button>
                            <button type="button" class="btn btn-warning" onclick="restaurarTabelaIRRF()">
                                <i class="fas fa-undo"></i> Restaurar Padrão
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salvar Tabela
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Configurações da Folha</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> Sobre as Configurações</h6>
                    <p>Configure os parâmetros para cálculo da folha de pagamento conforme legislação angolana.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Tabelas Disponíveis:</h6>
                    <ul>
                        <li><strong>INSS:</strong> Contribuição para Segurança Social</li>
                        <li><strong>IRRF:</strong> Imposto sobre Rendimentos</li>
                        <li><strong>Benefícios:</strong> Vale transporte, refeição, etc.</li>
                        <li><strong>13º Salário e Férias:</strong> Conforme Lei Geral do Trabalho</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-warning"></i> Dicas:</h6>
                    <ul>
                        <li>Utilize as tabelas padrão de Angola 2024 como referência</li>
                        <li>Atualize as tabelas quando houver mudanças na legislação</li>
                        <li>Configure o e-mail para receber alertas de processamento</li>
                    </ul>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-gavel"></i>
                        <strong>Base Legal:</strong> Lei Geral do Trabalho (Lei 7/15, de 15 de Junho) e Código Fiscal Angolano.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu toggle para mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        // Função para toggle de submenu
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Formatação de números nos campos de limite e dedução
        function formatarNumero(valor) {
            if (!valor) return '';
            let num = valor.toString().replace(/\./g, '');
            num = num.replace(/,/g, '.');
            let numero = parseFloat(num);
            if (isNaN(numero)) return valor;
            return numero.toLocaleString('pt-AO', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        }
        
        // Aplicar formatação a todos os campos de limite e dedução
        document.querySelectorAll('.limite, .deducao').forEach(campo => {
            campo.addEventListener('blur', function() {
                this.value = formatarNumero(this.value);
            });
        });
        
        // ============================================
        // FUNÇÕES PARA TABELA INSS
        // ============================================
        
        // Restaurar tabela INSS padrão
        function restaurarTabelaINSS() {
            if (confirm('Deseja restaurar a tabela INSS para os valores padrão de Angola 2024?')) {
                window.location.href = 'configuracoes.php?restaurar=inss';
            }
        }
        
        // Atualizar faixas INSS com base no salário mínimo
        function atualizarPorSalarioMinimoINSS() {
            const salarioMinimo = parseFloat(document.querySelector('input[name="salario_minimo"]').value) || 100000;
            
            // Valores base da tabela INSS Angola (proporcional ao salário mínimo)
            const faixasBase = [
                { limite: 1, aliquota: 3, deducao: 0 },
                { limite: 2, aliquota: 6, deducao: 3000 },
                { limite: 3.5, aliquota: 9, deducao: 9000 },
                { limite: 9999999, aliquota: 12, deducao: 19500 }
            ];
            
            const novasFaixas = faixasBase.map((faixa, index) => {
                let limite = faixa.limite === 9999999 ? 9999999 : Math.round(faixa.limite * salarioMinimo);
                let deducao = Math.round(faixa.deducao * (salarioMinimo / 100000));
                
                return {
                    faixa: getDescricaoFaixaINSS(index, limite, salarioMinimo),
                    limite: limite,
                    aliquota: faixa.aliquota,
                    deducao: deducao
                };
            });
            
            atualizarTabela('tbodyINSS', novasFaixas);
        }
        
        function getDescricaoFaixaINSS(index, limite, salarioMinimo) {
            if (index === 0) return `Até ${formatarNumero(limite)} Kz`;
            if (index === 1) return `De ${formatarNumero(salarioMinimo + 1)} a ${formatarNumero(limite)} Kz`;
            if (index === 2) return `De ${formatarNumero(salarioMinimo * 2 + 1)} a ${formatarNumero(limite)} Kz`;
            return `Acima de ${formatarNumero(salarioMinimo * 3.5 + 1)} Kz`;
        }
        
        // ============================================
        // FUNÇÕES PARA TABELA IRRF
        // ============================================
        
        // Restaurar tabela IRRF padrão
        function restaurarTabelaIRRF() {
            if (confirm('Deseja restaurar a tabela IRRF para os valores padrão de Angola 2024?')) {
                window.location.href = 'configuracoes.php?restaurar=irrf';
            }
        }
        
        // Atualizar faixas IRRF com base no salário mínimo
        function atualizarPorSalarioMinimoIRRF() {
            const salarioMinimo = parseFloat(document.querySelector('input[name="salario_minimo"]').value) || 100000;
            
            // Valores base da tabela IRRF Angola (proporcional ao salário mínimo)
            const faixasBase = [
                { limite: 1, aliquota: 0, deducao: 0 },
                { limite: 2, aliquota: 10, deducao: 10000 },
                { limite: 3.5, aliquota: 15, deducao: 20000 },
                { limite: 5, aliquota: 20, deducao: 37500 },
                { limite: 9999999, aliquota: 25, deducao: 62500 }
            ];
            
            const novasFaixas = faixasBase.map((faixa, index) => {
                let limite = faixa.limite === 9999999 ? 9999999 : Math.round(faixa.limite * salarioMinimo);
                let deducao = Math.round(faixa.deducao * (salarioMinimo / 100000));
                
                return {
                    faixa: getDescricaoFaixaIRRF(index, limite, salarioMinimo),
                    limite: limite,
                    aliquota: faixa.aliquota,
                    deducao: deducao
                };
            });
            
            atualizarTabela('tbodyIRRF', novasFaixas);
        }
        
        function getDescricaoFaixaIRRF(index, limite, salarioMinimo) {
            if (index === 0) return `Até ${formatarNumero(limite)} Kz`;
            if (index === 1) return `De ${formatarNumero(salarioMinimo + 1)} a ${formatarNumero(limite)} Kz`;
            if (index === 2) return `De ${formatarNumero(salarioMinimo * 2 + 1)} a ${formatarNumero(limite)} Kz`;
            if (index === 3) return `De ${formatarNumero(salarioMinimo * 3.5 + 1)} a ${formatarNumero(limite)} Kz`;
            return `Acima de ${formatarNumero(salarioMinimo * 5 + 1)} Kz`;
        }
        
        // Função genérica para atualizar tabela
        function atualizarTabela(tbodyId, novasFaixas) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            
            tbody.innerHTML = '';
            novasFaixas.forEach((faixa, index) => {
                const tr = document.createElement('tr');
                tr.className = 'tabela-faixa';
                tr.setAttribute('data-index', index);
                tr.innerHTML = `
                    <td><input type="text" name="faixas[]" class="form-control" value="${escapeHtml(faixa.faixa)}" style="min-width: 150px;"></td>
                    <td><input type="text" name="limites[]" class="form-control limite" value="${formatarNumero(faixa.limite)}"></td>
                    <td><input type="number" step="0.01" name="aliquotas[]" class="form-control" value="${faixa.aliquota}"></td>
                    <td><input type="text" name="deducoes[]" class="form-control deducao" value="${formatarNumero(faixa.deducao)}"></td>
                `;
                tbody.appendChild(tr);
            });
            
            // Reaplicar eventos de formatação
            document.querySelectorAll('.limite, .deducao').forEach(campo => {
                campo.addEventListener('blur', function() {
                    this.value = formatarNumero(this.value);
                });
            });
            
            // Mostrar mensagem de sucesso temporária
            mostrarMensagemTemporaria('Tabela atualizada com base no salário mínimo!', 'success');
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function mostrarMensagemTemporaria(mensagem, tipo) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${tipo} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
            alertDiv.style.zIndex = '9999';
            alertDiv.style.minWidth = '300px';
            alertDiv.innerHTML = `
                ${mensagem}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
        
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            // Fechar alertas automaticamente após 5 segundos
            setTimeout(() => {
                document.querySelectorAll('.alert-dismissible').forEach(alert => {
                    const closeBtn = alert.querySelector('.btn-close');
                    if (closeBtn) closeBtn.click();
                });
            }, 5000);
            
            // Destacar linha ao clicar
            document.querySelectorAll('.tabela-faixa').forEach(row => {
                row.addEventListener('click', function() {
                    document.querySelectorAll('.tabela-faixa').forEach(r => r.classList.remove('selecionada'));
                    this.classList.add('selecionada');
                });
            });
        });
    </script>
</body>
</html>