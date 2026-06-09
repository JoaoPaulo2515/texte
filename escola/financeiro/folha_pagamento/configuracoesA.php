<?php
// escola/financeiro/folha_pagamento/configuracoes.php - Configurações da Folha
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
// PROCESSAR CONFIGURAÇÕES
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_config') {
    $configs = [
        'inss_aliquotas' => $_POST['inss_aliquotas'],
        'irrf_tabela' => $_POST['irrf_tabela'],
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
        $stmt = $conn->prepare("
            INSERT INTO escola_parametros_sistema (escola_id, parametro, valor)
            VALUES (:escola_id, :parametro, :valor)
            ON DUPLICATE KEY UPDATE valor = :valor
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':parametro' => 'folha_' . $chave,
            ':valor' => $valor
        ]);
    }
    
    $_SESSION['mensagem'] = "Configurações salvas com sucesso!";
    header("Location: configuracoes.php");
    exit;
}

// ============================================
// BUSCAR CONFIGURAÇÕES ATUAIS
// ============================================

$configs_padrao = [
    'inss_aliquotas' => '7.5,9,12,14',
    'irrf_tabela' => '0,7.5,15,22.5,27.5',
    'vale_transporte_percentual' => '6',
    'vale_refeicao_valor' => '15',
    'horas_semanais' => '44',
    'salario_minimo' => '1412',
    'decimo_terceiro' => '1',
    'ferias_proporcionais' => '1',
    'notificacao_email' => '',
    'dias_pagamento' => '5'
];

$configs = [];
foreach ($configs_padrao as $chave => $valor_padrao) {
    $stmt = $conn->prepare("
        SELECT valor FROM escola_parametros_sistema 
        WHERE escola_id = :escola_id AND parametro = :parametro
    ");
    $stmt->execute([':escola_id' => $escola_id, ':parametro' => 'folha_' . $chave]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $configs[$chave] = $row['valor'] ?? $valor_padrao;
}

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
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="acao" value="salvar_config">
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-calculator"></i> Parâmetros de Cálculo
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="config-section">
                                <h5><i class="fas fa-chart-line"></i> INSS</h5>
                                <div class="mb-3">
                                    <label>Alíquotas INSS (%)</label>
                                    <input type="text" name="inss_aliquotas" class="form-control" value="<?php echo $configs['inss_aliquotas']; ?>">
                                    <small class="text-muted">Separar por vírgula. Ex: 7.5,9,12,14</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="config-section">
                                <h5><i class="fas fa-chart-line"></i> IRRF</h5>
                                <div class="mb-3">
                                    <label>Alíquotas IRRF (%)</label>
                                    <input type="text" name="irrf_tabela" class="form-control" value="<?php echo $configs['irrf_tabela']; ?>">
                                    <small class="text-muted">Separar por vírgula. Ex: 0,7.5,15,22.5,27.5</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="config-section">
                                <h5><i class="fas fa-bus"></i> Benefícios</h5>
                                <div class="mb-3">
                                    <label>Vale Transporte (%)</label>
                                    <input type="number" step="0.01" name="vale_transporte_percentual" class="form-control" value="<?php echo $configs['vale_transporte_percentual']; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="config-section">
                                <h5><i class="fas fa-utensils"></i> Vale Refeição</h5>
                                <div class="mb-3">
                                    <label>Valor por dia (Kz)</label>
                                    <input type="number" step="0.01" name="vale_refeicao_valor" class="form-control" value="<?php echo $configs['vale_refeicao_valor']; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="config-section">
                                <h5><i class="fas fa-clock"></i> Carga Horária</h5>
                                <div class="mb-3">
                                    <label>Horas Semanais</label>
                                    <input type="number" name="horas_semanais" class="form-control" value="<?php echo $configs['horas_semanais']; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-gavel"></i> Parâmetros Legais
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Salário Mínimo Nacional (Kz)</label>
                                <input type="number" step="0.01" name="salario_minimo" class="form-control" value="<?php echo $configs['salario_minimo']; ?>">
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
                <div class="card-header bg-primary text-white">
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
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Configurações da Folha</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que configurar?</h6>
                    <p>Defina os parâmetros para cálculo correto da folha de pagamento.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Parâmetros importantes:</h6>
                    <ul>
                        <li><strong>INSS:</strong> Alíquotas conforme tabela vigente.</li>
                        <li><strong>IRRF:</strong> Alíquotas do imposto de renda.</li>
                        <li><strong>Benefícios:</strong> Vale transporte e refeição.</li>
                        <li><strong>Salário Mínimo:</strong> Base para cálculos.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Mantenha as alíquotas atualizadas conforme legislação.</li>
                        <li>Revise as configurações anualmente.</li>
                        <li>Teste os cálculos após alterações.</li>
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