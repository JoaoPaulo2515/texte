<?php
// escola/financeiro/folha_pagamento/processar_salario.php - Processamento de Salário (Estilo Primavera)
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
// CLASSE DE PROCESSAMENTO DE FOLHA
// ============================================

class ProcessamentoFolha {
    private $conn;
    private $escola_id;
    private $usuario_id;
    private $ano;
    private $mes;
    private $processamento_id;
    private $rubricas;
    private $tabela_inss;
    private $tabela_irrf;
    
    public function __construct($conn, $escola_id, $usuario_id, $ano, $mes) {
        $this->conn = $conn;
        $this->escola_id = $escola_id;
        $this->usuario_id = $usuario_id;
        $this->ano = $ano;
        $this->mes = $mes;
        $this->carregarRubricas();
        $this->carregarTabelasImpostos();
    }
    
    private function carregarRubricas() {
        $stmt = $this->conn->prepare("
            SELECT * FROM folha_rubricas 
            WHERE escola_id = ? AND status = 'ativo' 
            ORDER BY tipo, ordem
        ");
        $stmt->execute([$this->escola_id]);
        $this->rubricas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function carregarTabelasImpostos() {
        // Carregar tabela INSS
        $stmt = $this->conn->prepare("
            SELECT * FROM folha_tabelas_impostos 
            WHERE escola_id = ? AND tipo = 'inss' AND ano = ? 
            ORDER BY faixa_inicio
        ");
        $stmt->execute([$this->escola_id, $this->ano]);
        $this->tabela_inss = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Carregar tabela IRRF
        $stmt = $this->conn->prepare("
            SELECT * FROM folha_tabelas_impostos 
            WHERE escola_id = ? AND tipo = 'irrf' AND ano = ? 
            ORDER BY faixa_inicio
        ");
        $stmt->execute([$this->escola_id, $this->ano]);
        $this->tabela_irrf = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function iniciarProcessamento() {
        try {
            $this->conn->beginTransaction();
            
            // Verificar se já existe processamento para este período
            $stmt = $this->conn->prepare("
                SELECT id, status FROM folha_processamento_cabecalho 
                WHERE escola_id = ? AND ano = ? AND mes = ?
            ");
            $stmt->execute([$this->escola_id, $this->ano, $this->mes]);
            $existente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existente && $existente['status'] == 'fechado') {
                throw new Exception("Folha deste período já está fechada!");
            }
            
            // Criar ou atualizar cabeçalho do processamento
            if ($existente) {
                $this->processamento_id = $existente['id'];
                // Limpar linhas anteriores
                $stmt = $this->conn->prepare("DELETE FROM folha_processamento_linhas WHERE processamento_id = ?");
                $stmt->execute([$this->processamento_id]);
                $stmt = $this->conn->prepare("DELETE FROM folha_processamento_resumo WHERE processamento_id = ?");
                $stmt->execute([$this->processamento_id]);
            } else {
                $stmt = $this->conn->prepare("
                    INSERT INTO folha_processamento_cabecalho 
                    (escola_id, ano, mes, usuario_id, status, data_processamento)
                    VALUES (?, ?, ?, ?, 'rascunho', NOW())
                ");
                $stmt->execute([$this->escola_id, $this->ano, $this->mes, $this->usuario_id]);
                $this->processamento_id = $this->conn->lastInsertId();
            }
            
            // Buscar funcionários ativos
            $stmt = $this->conn->prepare("
                SELECT f.*, fc.* 
                FROM funcionarios f
                LEFT JOIN folha_funcionarios_config fc ON f.id = fc.funcionario_id AND fc.escola_id = ?
                WHERE f.escola_id = ? AND f.status = 'ativo'
            ");
            $stmt->execute([$this->escola_id, $this->escola_id]);
            $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_funcionarios = 0;
            $total_vencimentos = 0;
            $total_descontos = 0;
            $total_liquido = 0;
            
            foreach ($funcionarios as $funcionario) {
                $resultado = $this->processarFuncionario($funcionario);
                if ($resultado) {
                    $total_funcionarios++;
                    $total_vencimentos += $resultado['total_vencimentos'];
                    $total_descontos += $resultado['total_descontos'];
                    $total_liquido += $resultado['salario_liquido'];
                }
            }
            
            // Atualizar cabeçalho com totais
            $stmt = $this->conn->prepare("
                UPDATE folha_processamento_cabecalho SET
                    total_funcionarios = ?,
                    total_vencimentos = ?,
                    total_descontos = ?,
                    total_liquido = ?,
                    status = 'processado'
                WHERE id = ?
            ");
            $stmt->execute([$total_funcionarios, $total_vencimentos, $total_descontos, $total_liquido, $this->processamento_id]);
            
            // Registrar log
            $this->registrarLog("Processamento da folha realizado para {$mes}/{$ano}");
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'processamento_id' => $this->processamento_id,
                'total_funcionarios' => $total_funcionarios,
                'total_vencimentos' => $total_vencimentos,
                'total_descontos' => $total_descontos,
                'total_liquido' => $total_liquido
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processarFuncionario($funcionario) {
        $salario_base = $funcionario['salario_base'] ?? 0;
        if ($salario_base <= 0) return false;
        
        $vencimentos = [];
        $descontos = [];
        $bases_calculo = [];
        
        // Processar cada rubrica
        foreach ($this->rubricas as $rubrica) {
            $valor = $this->calcularRubrica($rubrica, $funcionario);
            
            if ($valor > 0) {
                if ($rubrica['tipo'] == 'vencimento') {
                    $vencimentos[] = [
                        'rubrica' => $rubrica,
                        'valor' => $valor
                    ];
                } elseif ($rubrica['tipo'] == 'desconto') {
                    $descontos[] = [
                        'rubrica' => $rubrica,
                        'valor' => $valor
                    ];
                } elseif ($rubrica['tipo'] == 'base_calculo') {
                    $bases_calculo[] = [
                        'rubrica' => $rubrica,
                        'valor' => $valor
                    ];
                }
                
                // Salvar linha de processamento
                $this->salvarLinhaProcessamento($funcionario['id'], $rubrica, $valor);
            }
        }
        
        // Calcular totais
        $total_vencimentos = array_sum(array_column($vencimentos, 'valor'));
        $total_descontos = array_sum(array_column($descontos, 'valor'));
        
        // Calcular INSS e IRRF
        $base_inss = $this->calcularBaseImposto($vencimentos, 'inss');
        $valor_inss = $this->calcularINSS($base_inss);
        $base_irrf = $this->calcularBaseImposto($vencimentos, 'irrf') - $valor_inss;
        $valor_irrf = $this->calcularIRRF($base_irrf);
        
        $total_descontos += $valor_inss + $valor_irrf;
        $salario_liquido = $total_vencimentos - $total_descontos;
        
        // Salvar resumo do funcionário
        $this->salvarResumoFuncionario($funcionario['id'], $total_vencimentos, $total_descontos, $base_inss, $valor_inss, $base_irrf, $valor_irrf, $salario_liquido);
        
        return [
            'funcionario_id' => $funcionario['id'],
            'total_vencimentos' => $total_vencimentos,
            'total_descontos' => $total_descontos,
            'salario_liquido' => $salario_liquido
        ];
    }
    
    private function calcularRubrica($rubrica, $funcionario) {
        $salario_base = $funcionario['salario_base'] ?? 0;
        
        switch ($rubrica['codigo']) {
            case 'BASE':
                return $salario_base;
            case 'SUB_TRANSP':
                return $this->buscarValorRubricaFuncionario($funcionario['id'], $rubrica['id']) ?: 5000;
            case 'SUB_ALIMENT':
                return $this->buscarValorRubricaFuncionario($funcionario['id'], $rubrica['id']) ?: 2500;
            case 'HORA_EXTRA':
                $horas_extras = $this->buscarHorasExtras($funcionario['id']);
                $valor_hora = $salario_base / 160;
                return $horas_extras * $valor_hora * 1.5;
            default:
                return $this->buscarValorRubricaFuncionario($funcionario['id'], $rubrica['id']);
        }
    }
    
    private function calcularINSS($base) {
        foreach ($this->tabela_inss as $faixa) {
            if ($base <= $faixa['faixa_fim']) {
                return ($base * $faixa['aliquota'] / 100) - $faixa['parcela_deducao'];
            }
        }
        return 0;
    }
    
    private function calcularIRRF($base) {
        if ($base <= 0) return 0;
        foreach ($this->tabela_irrf as $faixa) {
            if ($base <= $faixa['faixa_fim']) {
                return ($base * $faixa['aliquota'] / 100) - $faixa['parcela_deducao'];
            }
        }
        return 0;
    }
    
    private function calcularBaseImposto($vencimentos, $tipo_imposto) {
        $base = 0;
        foreach ($vencimentos as $venc) {
            $incide = ($tipo_imposto == 'inss') ? $venc['rubrica']['incide_inss'] : $venc['rubrica']['incide_irrf'];
            if ($incide == 'sim') {
                $base += $venc['valor'];
            }
        }
        return $base;
    }
    
    private function buscarValorRubricaFuncionario($funcionario_id, $rubrica_id) {
        $stmt = $this->conn->prepare("
            SELECT valor_fixo FROM folha_funcionario_rubricas 
            WHERE funcionario_id = ? AND rubrica_id = ? AND status = 'ativo'
        ");
        $stmt->execute([$funcionario_id, $rubrica_id]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return $resultado ? $resultado['valor_fixo'] : 0;
    }
    
    private function buscarHorasExtras($funcionario_id) {
        // Buscar horas extras registradas no período
        $stmt = $this->conn->prepare("
            SELECT SUM(quantidade) as total FROM folha_horas_extras 
            WHERE funcionario_id = ? AND mes = ? AND ano = ?
        ");
        $stmt->execute([$funcionario_id, $this->mes, $this->ano]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        return $resultado ? $resultado['total'] : 0;
    }
    
    private function salvarLinhaProcessamento($funcionario_id, $rubrica, $valor) {
        $stmt = $this->conn->prepare("
            INSERT INTO folha_processamento_linhas 
            (processamento_id, funcionario_id, rubrica_id, codigo_rubrica, nome_rubrica, tipo_rubrica, valor_total)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->processamento_id, $funcionario_id, $rubrica['id'],
            $rubrica['codigo'], $rubrica['nome'], $rubrica['tipo'], $valor
        ]);
    }
    
    private function salvarResumoFuncionario($funcionario_id, $total_vencimentos, $total_descontos, $base_inss, $valor_inss, $base_irrf, $valor_irrf, $salario_liquido) {
        $stmt = $this->conn->prepare("
            INSERT INTO folha_processamento_resumo 
            (processamento_id, funcionario_id, total_vencimentos, total_descontos, 
             base_inss, valor_inss, base_irrf, valor_irrf, salario_liquido)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->processamento_id, $funcionario_id, $total_vencimentos, $total_descontos,
            $base_inss, $valor_inss, $base_irrf, $valor_irrf, $salario_liquido
        ]);
    }
    
    private function registrarLog($descricao) {
        $stmt = $this->conn->prepare("
            INSERT INTO folha_logs (escola_id, processamento_id, usuario_id, acao, descricao, ip, data_log)
            VALUES (?, ?, ?, 'processamento', ?, ?, NOW())
        ");
        $stmt->execute([$this->escola_id, $this->processamento_id, $this->usuario_id, $descricao, $_SERVER['REMOTE_ADDR']]);
    }
}

// ============================================
// PROCESSAR REQUISIÇÃO
// ============================================

$ano = $_POST['ano'] ?? date('Y');
$mes = $_POST['mes'] ?? date('m');
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['processar'])) {
    $processamento = new ProcessamentoFolha($conn, $escola_id, $usuario_id, $ano, $mes);
    $resultado = $processamento->iniciarProcessamento();
    
    if ($resultado['success']) {
        $mensagem = "Folha processada com sucesso! Total de funcionários: {$resultado['total_funcionarios']}, Total líquido: " . number_format($resultado['total_liquido'], 2) . " Kz";
        $tipo_mensagem = "success";
    } else {
        $mensagem = "Erro ao processar: " . $resultado['error'];
        $tipo_mensagem = "danger";
    }
}

// Buscar histórico de processamentos
$stmt = $conn->prepare("
    SELECT * FROM folha_processamento_cabecalho 
    WHERE escola_id = ? 
    ORDER BY ano DESC, mes DESC
    LIMIT 12
");
$stmt->execute([$escola_id]);
$processamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processamento de Salário | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
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
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .processo-card { transition: transform 0.3s; cursor: pointer; }
        .processo-card:hover { transform: translateY(-5px); }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; }
        .status-rascunho { background: #ffc107; color: #000; }
        .status-processado { background: #17a2b8; color: #fff; }
        .status-fechado { background: #28a745; color: #fff; }
        .status-cancelado { background: #dc3545; color: #fff; }
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
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-coins"></i> Financeiro</a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                    <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
                    <li class="nav-item"><a href="processar_salario.php" class="nav-link active"><i class="fas fa-calculator"></i> Processar Salário</a></li>
                    <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
                    <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
                    <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calculator"></i> Processamento de Salário</h2>
            <a href="../index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show">
                <?php echo $mensagem; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-play"></i> Novo Processamento
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Ano</label>
                                        <select name="ano" class="form-control" required>
                                            <?php for ($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo $i == $ano ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Mês</label>
                                        <select name="mes" class="form-control" required>
                                            <?php foreach ($meses as $num => $nome): ?>
                                                <option value="<?php echo $num; ?>" <?php echo $num == $mes ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Etapas do Processamento:</strong>
                                <ol class="mb-0 mt-2">
                                    <li>Cálculo de vencimentos (salário base, subsídios, bónus)</li>
                                    <li>Cálculo de horas extras</li>
                                    <li>Cálculo de descontos legais (INSS, IRRF)</li>
                                    <li>Cálculo de descontos pessoais</li>
                                    <li>Geração de holerites</li>
                                </ol>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" name="processar" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play"></i> Processar Folha
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history"></i> Histórico de Processamentos
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Período</th>
                                        <th>Data</th>
                                        <th>Funcionários</th>
                                        <th>Total Líquido</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processamentos as $p): ?>
                                    <tr>
                                        <td><?php echo $meses[$p['mes']] . '/' . $p['ano']; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($p['data_processamento'])); ?></td>
                                        <td><?php echo $p['total_funcionarios']; ?></td>
                                        <td><?php echo number_format($p['total_liquido'], 2); ?> Kz</td>
                                        <td>
                                            <span class="status-badge status-<?php echo $p['status']; ?>">
                                                <?php echo ucfirst($p['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="detalhes_processamento.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($p['status'] != 'fechado'): ?>
                                            <a href="fechar_processamento.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Fechar este processamento?')">
                                                <i class="fas fa-lock"></i>
                                            </a>
                                            <?php endif; ?>
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
        
        <!-- Fluxograma do Processamento -->
        <div class="card mt-3">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Fluxo do Processamento de Salário
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="p-3 border rounded">
                            <i class="fas fa-users fa-2x text-primary"></i>
                            <h6>1. Funcionários</h6>
                            <small>Seleção dos funcionários ativos</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded">
                            <i class="fas fa-calculator fa-2x text-success"></i>
                            <h6>2. Cálculos</h6>
                            <small>Vencimentos, descontos, impostos</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded">
                            <i class="fas fa-file-invoice fa-2x text-warning"></i>
                            <h6>3. Holerites</h6>
                            <small>Geração dos recibos</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 border rounded">
                            <i class="fas fa-check-circle fa-2x text-danger"></i>
                            <h6>4. Fechamento</h6>
                            <small>Finalização do período</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
    </script>
</body>
</html>