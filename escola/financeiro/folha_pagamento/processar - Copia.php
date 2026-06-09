<?php
// escola/financeiro/folha_pagamento/processar.php - Processamento da Folha
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
// FUNÇÕES DE CÁLCULO
// ============================================

function calcularINSS($salario_bruto) {
    // Tabela INSS 2024
    $faixas = [
        ['ate' => 1412.00, 'aliquota' => 0.075],
        ['ate' => 2666.68, 'aliquota' => 0.09],
        ['ate' => 4000.03, 'aliquota' => 0.12],
        ['ate' => 7786.02, 'aliquota' => 0.14]
    ];
    
    $inss = 0;
    $base = $salario_bruto;
    
    foreach ($faixas as $faixa) {
        if ($base <= 0) break;
        $valor_faixa = min($base, $faixa['ate']);
        $inss += $valor_faixa * $faixa['aliquota'];
        $base -= $valor_faixa;
    }
    
    return round($inss, 2);
}

function calcularIRRF($salario_bruto, $inss, $dependentes = 0) {
    // Tabela IRRF 2024
    $base_calculo = $salario_bruto - $inss - ($dependentes * 189.59);
    
    if ($base_calculo <= 2259.20) {
        return 0;
    } elseif ($base_calculo <= 2826.65) {
        return round($base_calculo * 0.075 - 169.44, 2);
    } elseif ($base_calculo <= 3751.05) {
        return round($base_calculo * 0.15 - 381.44, 2);
    } elseif ($base_calculo <= 4664.68) {
        return round($base_calculo * 0.225 - 662.77, 2);
    } else {
        return round($base_calculo * 0.275 - 896.00, 2);
    }
}

// ============================================
// PROCESSAR FOLHA
// ============================================

$competencia = $_GET['competencia'] ?? date('Y-m-d', strtotime('first day of this month'));
$mensagem = '';
$erro = '';
$resultado_processamento = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'processar_folha') {
    $competencia = $_POST['competencia'];
    $funcionarios = $_POST['funcionarios'] ?? [];
    
    // Verificar se já existe folha para esta competência
    $check = $conn->prepare("SELECT id FROM rh_folhas_pagamento WHERE escola_id = :escola_id AND competencia = :competencia");
    $check->execute([':escola_id' => $escola_id, ':competencia' => $competencia]);
    
    if ($check->rowCount() > 0) {
        $erro = "Já existe uma folha processada para esta competência!";
    } else {
        $total_bruto = 0;
        $total_descontos = 0;
        $total_liquido = 0;
        $total_funcionarios = 0;
        
        // Inserir folha
        $stmt = $conn->prepare("
            INSERT INTO rh_folhas_pagamento (escola_id, competencia, data_processamento, status, usuario_id)
            VALUES (:escola_id, :competencia, NOW(), 'processado', :usuario_id)
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':competencia' => $competencia,
            ':usuario_id' => $usuario_id
        ]);
        $folha_id = $conn->lastInsertId();
        
        foreach ($funcionarios as $func_id => $dados) {
            $funcionario_id = $dados['id'];
            $cargo_id = $dados['cargo_id'] ?: null;
            $salario_base = $dados['salario_base'];
            $dias_trabalhados = $dados['dias_trabalhados'];
            $faltas = $dados['faltas'];
            $horas_extras_50 = $dados['horas_extras_50'];
            $horas_extras_100 = $dados['horas_extras_100'];
            $adicional_noturno = $dados['adicional_noturno'];
            $bonus = $dados['bonus'];
            $vale_transporte = $dados['vale_transporte'];
            $vale_refeicao = $dados['vale_refeicao'];
            $auxilio_saude = $dados['auxilio_saude'];
            $outros_descontos = $dados['outros_descontos'];
            
            // Calcular salário proporcional
            $salario_proporcional = $salario_base * ($dias_trabalhados / 30);
            
            // Calcular horas extras
            $valor_hora = $salario_base / 220;
            $valor_extra_50 = $horas_extras_50 * $valor_hora * 1.5;
            $valor_extra_100 = $horas_extras_100 * $valor_hora * 2;
            
            // Total de proventos
            $total_proventos = $salario_proporcional + $valor_extra_50 + $valor_extra_100 + $adicional_noturno + $bonus;
            
            // Calcular INSS e IRRF
            $inss = calcularINSS($total_proventos);
            $irrf = calcularIRRF($total_proventos, $inss);
            
            // Total de descontos
            $total_descontos_func = $inss + $irrf + $outros_descontos;
            
            // Valor líquido
            $valor_liquido = $total_proventos - $total_descontos_func;
            
            // Inserir itens da folha
            $stmt_item = $conn->prepare("
                INSERT INTO rh_folha_itens (
                    escola_id, folha_id, funcionario_id, cargo_id, salario_base, dias_trabalhados, faltas,
                    horas_extras_50, horas_extras_100, adicional_noturno, bonus, vale_transporte, vale_refeicao, auxilio_saude,
                    inss, irrf, outros_descontos, total_proventos, total_descontos, valor_liquido
                ) VALUES (
                    :escola_id, :folha_id, :funcionario_id, :cargo_id, :salario_base, :dias_trabalhados, :faltas,
                    :horas_extras_50, :horas_extras_100, :adicional_noturno, :bonus, :vale_transporte, :vale_refeicao, :auxilio_saude,
                    :inss, :irrf, :outros_descontos, :total_proventos, :total_descontos, :valor_liquido
                )
            ");
            $stmt_item->execute([
                ':escola_id' => $escola_id,
                ':folha_id' => $folha_id,
                ':funcionario_id' => $funcionario_id,
                ':cargo_id' => $cargo_id,
                ':salario_base' => $salario_base,
                ':dias_trabalhados' => $dias_trabalhados,
                ':faltas' => $faltas,
                ':horas_extras_50' => $horas_extras_50,
                ':horas_extras_100' => $horas_extras_100,
                ':adicional_noturno' => $adicional_noturno,
                ':bonus' => $bonus,
                ':vale_transporte' => $vale_transporte,
                ':vale_refeicao' => $vale_refeicao,
                ':auxilio_saude' => $auxilio_saude,
                ':inss' => $inss,
                ':irrf' => $irrf,
                ':outros_descontos' => $outros_descontos,
                ':total_proventos' => $total_proventos,
                ':total_descontos' => $total_descontos_func,
                ':valor_liquido' => $valor_liquido
            ]);
            
            $total_bruto += $total_proventos;
            $total_descontos += $total_descontos_func;
            $total_liquido += $valor_liquido;
            $total_funcionarios++;
        }
        
        // Atualizar totais da folha
        $stmt_update = $conn->prepare("
            UPDATE rh_folhas_pagamento 
            SET total_bruto = :total_bruto, total_descontos = :total_descontos, 
                total_liquido = :total_liquido, total_funcionarios = :total_funcionarios
            WHERE id = :id
        ");
        $stmt_update->execute([
            ':total_bruto' => $total_bruto,
            ':total_descontos' => $total_descontos,
            ':total_liquido' => $total_liquido,
            ':total_funcionarios' => $total_funcionarios,
            ':id' => $folha_id
        ]);
        
        $mensagem = "Folha de pagamento processada com sucesso! Total líquido: " . number_format($total_liquido, 2, ',', '.') . " Kz";
        $resultado_processamento = [
            'total_bruto' => $total_bruto,
            'total_descontos' => $total_descontos,
            'total_liquido' => $total_liquido,
            'total_funcionarios' => $total_funcionarios
        ];
    }
}

// ============================================
// BUSCAR DADOS PARA FORMULÁRIO
// ============================================

// Buscar funcionários ativos
$funcionarios_ativos = $conn->prepare("
    SELECT f.*, u.nome as funcionario_nome, c.nome as cargo_nome, c.salario_base as cargo_salario
    FROM rh_funcionarios f
    JOIN usuarios u ON u.id = f.usuario_id
    LEFT JOIN rh_cargos c ON c.id = f.cargo_id
    WHERE f.escola_id = :escola_id AND f.status = 'ativo'
    ORDER BY u.nome
");
$funcionarios_ativos->execute([':escola_id' => $escola_id]);
$funcionarios_ativos = $funcionarios_ativos->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? $mensagem;
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processar Folha | SIGE Angola</title>
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
        
        .funcionario-card { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 15px; border-left: 3px solid #006B3E; }
        .resultado-card { background: #e8f5e9; border-radius: 15px; padding: 20px; margin-top: 20px; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .btn-salvar { background: #28a745; color: white; }
        .btn-salvar:hover { background: #1e7e34; color: white; }
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
                    <li class="nav-item"><a href="processar.php" class="nav-link active"><i class="fas fa-calculator"></i> Processar</a></li>
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
                <i class="fas fa-calculator"></i> Processar Folha de Pagamento
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Resultado do Processamento -->
        <?php if ($resultado_processamento): ?>
        <div class="resultado-card">
            <h4><i class="fas fa-check-circle text-success"></i> Resultado do Processamento</h4>
            <div class="row">
                <div class="col-md-3 text-center">
                    <h6>Total Bruto</h6>
                    <h4><?php echo number_format($resultado_processamento['total_bruto'], 2, ',', '.'); ?> Kz</h4>
                </div>
                <div class="col-md-3 text-center">
                    <h6>Total Descontos</h6>
                    <h4 class="text-danger"><?php echo number_format($resultado_processamento['total_descontos'], 2, ',', '.'); ?> Kz</h4>
                </div>
                <div class="col-md-3 text-center">
                    <h6>Total Líquido</h6>
                    <h4 class="text-success"><?php echo number_format($resultado_processamento['total_liquido'], 2, ',', '.'); ?> Kz</h4>
                </div>
                <div class="col-md-3 text-center">
                    <h6>Funcionários</h6>
                    <h4><?php echo $resultado_processamento['total_funcionarios']; ?></h4>
                </div>
            </div>
            <div class="text-center mt-3">
                <a href="holerites.php" class="btn btn-primary"><i class="fas fa-receipt"></i> Ver Holerites</a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Formulário de Processamento -->
        <form method="POST">
            <input type="hidden" name="acao" value="processar_folha">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-calendar"></i> Selecionar Competência
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="required">Mês/Ano de Competência</label>
                            <input type="month" name="competencia" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-salvar btn-lg mt-4 w-100" onclick="return confirm('Confirmar processamento da folha?')">
                                <i class="fas fa-calculator"></i> Processar Folha
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-users"></i> Funcionários
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Funcionário</th>
                                    <th>Cargo</th>
                                    <th>Salário Base</th>
                                    <th>Dias Trabalhados</th>
                                    <th>Faltas</th>
                                    <th>HE 50%</th>
                                    <th>HE 100%</th>
                                    <th>Ad. Noturno</th>
                                    <th>Bônus</th>
                                    <th>Outros Desc.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($funcionarios_ativos as $func): 
                                    $salario = $func['cargo_salario'] ?? $func['salario_contratual'];
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="funcionarios[<?php echo $func['id']; ?>][id]" value="<?php echo $func['id']; ?>">
                                        <input type="hidden" name="funcionarios[<?php echo $func['id']; ?>][cargo_id]" value="<?php echo $func['cargo_id']; ?>">
                                        <input type="hidden" name="funcionarios[<?php echo $func['id']; ?>][salario_base]" value="<?php echo $salario; ?>">
                                        <strong><?php echo htmlspecialchars($func['funcionario_nome']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($func['cargo_nome'] ?? '-'); ?></td>
                                    <td class="text-end"><?php echo number_format($salario, 2, ',', '.'); ?> Kz</td>
                                    <td><input type="number" name="funcionarios[<?php echo $func['id']; ?>][dias_trabalhados]" class="form-control form-control-sm" value="30" min="0" max="30" style="width: 80px;"></td>
                                    <td><input type="number" name="funcionarios[<?php echo $func['id']; ?>][faltas]" class="form-control form-control-sm" value="0" min="0" style="width: 80px;"></td>
                                    <td><input type="number" step="0.5" name="funcionarios[<?php echo $func['id']; ?>][horas_extras_50]" class="form-control form-control-sm" value="0" min="0" style="width: 80px;"></td>
                                    <td><input type="number" step="0.5" name="funcionarios[<?php echo $func['id']; ?>][horas_extras_100]" class="form-control form-control-sm" value="0" min="0" style="width: 80px;"></td>
                                    <td><input type="number" step="0.01" name="funcionarios[<?php echo $func['id']; ?>][adicional_noturno]" class="form-control form-control-sm" value="0" min="0" style="width: 100px;"></td>
                                    <td><input type="number" step="0.01" name="funcionarios[<?php echo $func['id']; ?>][bonus]" class="form-control form-control-sm" value="0" min="0" style="width: 100px;"></td>
                                    <td><input type="number" step="0.01" name="funcionarios[<?php echo $func['id']; ?>][outros_descontos]" class="form-control form-control-sm" value="0" min="0" style="width: 100px;"></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-info-circle"></i> Os campos de horas extras, adicional noturno, bônus e descontos são opcionais.
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-salvar btn-lg" onclick="return confirm('Confirmar processamento da folha de pagamento?')">
                    <i class="fas fa-calculator"></i> Processar Folha
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Processamento da Folha</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> Como processar a folha?</h6>
                    <p>Selecione a competência, ajuste os dados dos funcionários e clique em "Processar Folha".</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Cálculos Automáticos:</h6>
                    <ul>
                        <li><strong>INSS:</strong> Calculado conforme tabela progressiva.</li>
                        <li><strong>IRRF:</strong> Calculado com base no salário e dependentes.</li>
                        <li><strong>Horas Extras:</strong> 50% para dias úteis, 100% para domingos/feriados.</li>
                        <li><strong>Salário Proporcional:</strong> Baseado nos dias trabalhados.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Verifique os dados antes de processar.</li>
                        <li>Não é possível processar o mesmo mês duas vezes.</li>
                        <li>Após processado, emita os holerites.</li>
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