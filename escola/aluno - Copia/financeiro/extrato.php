<?php
// escola/aluno/financeiro/extrato.php - Extrato Financeiro do Aluno

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Extrato Financeiro';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, email, telefone FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : '';
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : '';

// Buscar anos disponíveis
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano FROM pagamentos 
             WHERE assinatura_id = :aluno_id AND escola_id = :escola_id AND data_pagamento IS NOT NULL
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// BUSCAR LANÇAMENTOS (DÉBITOS E CRÉDITOS)
// ==============================================

// 1. DÉBITOS - Mensalidades, taxas, etc.
$sql_debitos = "SELECT 
                    'debito' as tipo,
                    p.id,
                    p.tipo_pagamento,
                    p.tipo_pagamento_id,
                    p.referente,
                    p.valor,
                    p.data_vencimento as data,
                    p.status,
                    p.numero_fatura,
                    p.numero_referencia,
                    NULL as metodo_pagamento,
                    NULL as comprovativo_numero,
                    NULL as quem_pagou
                FROM pagamentos p
                WHERE p.assinatura_id = :aluno_id 
                AND p.escola_id = :escola_id
                AND p.status IN ('pendente', 'pago', 'confirmado')";

// 2. CRÉDITOS - Pagamentos realizados
$sql_creditos = "SELECT 
                    'credito' as tipo,
                    p.id,
                    p.tipo_pagamento,
                    p.tipo_pagamento_id,
                    p.referente,
                    p.valor,
                    p.data_pagamento as data,
                    p.status,
                    p.numero_fatura,
                    p.numero_referencia,
                    p.metodo_pagamento,
                    p.comprovativo_numero,
                    p.quem_pagou
                FROM pagamentos p
                WHERE p.assinatura_id = :aluno_id 
                AND p.escola_id = :escola_id
                AND p.status IN ('pago', 'confirmado')";

// Aplicar filtros
if ($ano_filtro > 0) {
    $sql_debitos .= " AND YEAR(p.data_vencimento) = :ano";
    $sql_creditos .= " AND YEAR(p.data_pagamento) = :ano";
}
if ($mes_filtro > 0) {
    $sql_debitos .= " AND MONTH(p.data_vencimento) = :mes";
    $sql_creditos .= " AND MONTH(p.data_pagamento) = :mes";
}
if (!empty($data_inicio) && !empty($data_fim)) {
    $sql_debitos .= " AND p.data_vencimento BETWEEN :data_inicio AND :data_fim";
    $sql_creditos .= " AND p.data_pagamento BETWEEN :data_inicio AND :data_fim";
}

$sql_debitos .= " ORDER BY data ASC";
$sql_creditos .= " ORDER BY data ASC";

$stmt_debitos = $conn->prepare($sql_debitos);
$stmt_creditos = $conn->prepare($sql_creditos);

$params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if ($mes_filtro > 0) {
    $params[':mes'] = $mes_filtro;
}
if (!empty($data_inicio) && !empty($data_fim)) {
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
}

$stmt_debitos->execute($params);
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

$stmt_creditos->execute($params);
$creditos = $stmt_creditos->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// COMBINAR E ORDENAR LANÇAMENTOS POR DATA
// ==============================================
$lancamentos = array_merge($debitos, $creditos);

// Ordenar por data
usort($lancamentos, function($a, $b) {
    return strtotime($a['data']) - strtotime($b['data']);
});

// ==============================================
// CALCULAR SALDOS
// ==============================================
$saldo_atual = 0;
$total_debitos = 0;
$total_creditos = 0;

foreach ($lancamentos as &$lancamento) {
    if ($lancamento['tipo'] == 'debito') {
        $total_debitos += $lancamento['valor'];
        $saldo_atual -= $lancamento['valor'];
        $lancamento['saldo_parcial'] = $saldo_atual;
    } else {
        $total_creditos += $lancamento['valor'];
        $saldo_atual += $lancamento['valor'];
        $lancamento['saldo_parcial'] = $saldo_atual;
    }
}

// Se for pendente, o saldo é negativo (dívida)
$saldo_pendente = abs($total_debitos - $total_creditos);
$status_saldo = ($total_debitos > $total_creditos) ? 'devedor' : 'credor';

// ==============================================
// MENSALIDADES ESTRUTURADAS
// ==============================================
$meses_ano = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$resumo_mensal = [];
foreach ($meses_ano as $num => $nome) {
    $resumo_mensal[$num] = [
        'mes' => $nome,
        'debito' => 0,
        'credito' => 0,
        'saldo' => 0,
        'status' => 'pendente'
    ];
}

foreach ($debitos as $debito) {
    $mes = (int)date('n', strtotime($debito['data']));
    if ($mes >= 1 && $mes <= 12) {
        $resumo_mensal[$mes]['debito'] += $debito['valor'];
        $resumo_mensal[$mes]['status'] = ($debito['status'] == 'pago' || $debito['status'] == 'confirmado') ? 'pago' : 'pendente';
    }
}

foreach ($creditos as $credito) {
    $mes = (int)date('n', strtotime($credito['data']));
    if ($mes >= 1 && $mes <= 12) {
        $resumo_mensal[$mes]['credito'] += $credito['valor'];
    }
}

foreach ($resumo_mensal as $num => $dados) {
    $resumo_mensal[$num]['saldo'] = $dados['debito'] - $dados['credito'];
    if ($dados['saldo'] <= 0) {
        $resumo_mensal[$num]['status'] = 'quitado';
    } elseif ($dados['credito'] > 0 && $dados['saldo'] > 0) {
        $resumo_mensal[$num]['status'] = 'parcial';
    }
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => 'Mensalidade',
        'matricula' => 'Matrícula',
        'material' => 'Material Escolar',
        'atividade' => 'Atividade Extracurricular',
        'taxa' => 'Taxa Escolar',
        'laboratorio' => 'Laboratório',
        'campo' => 'Saída de Campo',
        'uniforme' => 'Uniforme',
        'outro' => 'Outro'
    ];
    return $tipos[$tipo] ?? ucfirst(str_replace('_', ' ', $tipo));
}

function getStatusExtratoLabel($status) {
    switch ($status) {
        case 'pago':
        case 'confirmado':
            return '<span class="badge bg-success">Pago</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'quitado':
            return '<span class="badge bg-success">Quitado</span>';
        case 'parcial':
            return '<span class="badge bg-info">Parcial</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getSaldoColor($saldo) {
    if ($saldo > 0) return 'text-danger';
    if ($saldo < 0) return 'text-success';
    return 'text-secondary';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card { transition: transform 0.2s, box-shadow 0.2s; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        
        .extrato-table th, .extrato-table td { vertical-align: middle; }
        .debito-row { background-color: #fff3f3; }
        .credito-row { background-color: #f0fff4; }
        
        .saldo-resumo {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
        }
        
        .mes-card {
            background: white;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .mes-card:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .mes-card.quitado { border-left: 4px solid #28a745; }
        .mes-card.pendente { border-left: 4px solid #ffc107; }
        .mes-card.parcial { border-left: 4px solid #17a2b8; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show { display: flex; }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body { padding: 20px; }
        .modal-ajuda-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; }
        .ajuda-item { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .ajuda-item:last-child { border-bottom: none; }
        .ajuda-titulo { font-weight: bold; color: #006B3E; margin-bottom: 8px; }
        .ajuda-texto { color: #666; font-size: 0.9rem; line-height: 1.4; } 
        .ajuda-badge { display: inline-block; width: 30px; height: 30px; background: #e8f5e9; border-radius: 8px; text-align: center; line-height: 30px; margin-right: 10px; color: #006B3E; font-weight: bold; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .btn-print { display: none; }
        }
    </style>
</head>
<body>
   <?php include '../includes/menu_aluno.php'; ?>
   
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Extrato Financeiro</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe o extrato financeiro completo, incluindo débitos e créditos.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Cores e Símbolos</div>
                <div class="ajuda-texto">
                    <span class="text-danger">🔴 Débitos</span> - Valores a pagar<br>
                    <span class="text-success">🟢 Créditos</span> - Pagamentos realizados<br>
                    <span class="text-info">🔵 Saldo</span> - Posição atual
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Resumo Mensal</div>
                <div class="ajuda-texto">Visualize o status de cada mês: Quitado, Pendente ou Parcial.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Filtre por ano, mês ou período específico para análise detalhada.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-invoice"></i> Extrato Financeiro</h4>
            <p class="text-muted mb-0">Histórico completo de débitos e pagamentos</p>
        </div>
        <div>
            <button class="btn btn-secondary btn-print" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir Extrato
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome'] ?? $aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['matricula'] ?? $aluno_matricula); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-chart-line"></i> Situação</small>
                    <h6 class="mb-0 <?php echo $status_saldo == 'devedor' ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $status_saldo == 'devedor' ? 'Em Débito' : 'Regular'; ?>
                    </h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Resumo -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo formatarMoeda($total_debitos); ?> KZ</div>
                <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total Débitos</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_creditos); ?> KZ</div>
                <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total Pagamentos</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value <?php echo $saldo_atual < 0 ? 'text-danger' : 'text-success'; ?>">
                    <?php echo formatarMoeda(abs($saldo_atual)); ?> KZ
                </div>
                <div class="stat-label">
                    <i class="fas fa-wallet"></i> 
                    <?php echo $saldo_atual < 0 ? 'Saldo Devedor' : 'Crédito Disponível'; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Mês</label>
                    <select name="mes" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os meses</option>
                        <?php foreach ($meses_ano as $num => $nome): ?>
                        <option value="<?php echo $num; ?>" <?php echo $mes_filtro == $num ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="extrato.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Resumo Mensal -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-line"></i> Resumo Mensal - <?php echo $ano_filtro; ?></div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($resumo_mensal as $num => $dados): ?>
                <div class="col-md-2 col-4">
                    <div class="mes-card <?php echo $dados['status']; ?>">
                        <small class="text-muted"><?php echo $dados['mes']; ?></small>
                        <div class="fw-bold <?php echo getSaldoColor($dados['saldo']); ?>">
                            <?php echo formatarMoeda($dados['saldo']); ?>
                        </div>
                        <div>
                            <?php if ($dados['status'] == 'quitado'): ?>
                            <i class="fas fa-check-circle text-success" title="Quitado"></i>
                            <?php elseif ($dados['status'] == 'pendente'): ?>
                            <i class="fas fa-clock text-warning" title="Pendente"></i>
                            <?php else: ?>
                            <i class="fas fa-charging-station text-info" title="Parcial"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Extrato -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-list"></i> Movimentações
            <span class="badge bg-secondary ms-2"><?php echo count($lancamentos); ?> registros</span>
        </div>
        <div class="card-body">
            <?php if (empty($lancamentos)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma movimentação encontrada para o período selecionado.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered extrato-table">
                        <thead class="table-dark">
                            <tr>
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Tipo</th>
                                <th>Documento</th>
                                <th>Débito (KZ)</th>
                                <th>Crédito (KZ)</th>
                                <th>Saldo Parcial</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $saldo_exibido = 0;
                            foreach ($lancamentos as $lancamento): 
                                $classe_row = ($lancamento['tipo'] == 'debito') ? 'debito-row' : 'credito-row';
                                $is_debito = ($lancamento['tipo'] == 'debito');
                                $valor = $lancamento['valor'];
                                
                                // Calcular saldo atualizado
                                if ($is_debito) {
                                    $saldo_exibido -= $valor;
                                } else {
                                    $saldo_exibido += $valor;
                                }
                            ?>
                            <tr class="<?php echo $classe_row; ?>">
                                <td><?php echo date('d/m/Y', strtotime($lancamento['data'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($lancamento['referente'] ?? getTipoPagamentoLabel($lancamento['tipo_pagamento'])); ?></strong>
                                    <?php if ($lancamento['metodo_pagamento']): ?>
                                    <br><small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $lancamento['metodo_pagamento'])); ?></small>
                                    <?php endif; ?>
                                    <?php if ($lancamento['comprovativo_numero']): ?>
                                    <br><small class="text-muted">Comprovante: <?php echo $lancamento['comprovativo_numero']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_debito): ?>
                                        <span class="badge bg-danger">Débito</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Crédito</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($lancamento['numero_fatura']): ?>
                                    <small><?php echo $lancamento['numero_fatura']; ?></small>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-danger fw-bold">
                                    <?php echo $is_debito ? formatarMoeda($valor) . ' KZ' : '-'; ?>
                                </td>
                                <td class="text-end text-success fw-bold">
                                    <?php echo !$is_debito ? formatarMoeda($valor) . ' KZ' : '-'; ?>
                                </td>
                                <td class="text-end fw-bold <?php echo $saldo_exibido < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatarMoeda(abs($saldo_exibido)) . ' KZ ' . ($saldo_exibido < 0 ? 'devedor' : 'credor'); ?>
                                </td>
                                <td><?php echo getStatusExtratoLabel($lancamento['status']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="4" class="text-end">TOTAIS:</td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($total_debitos); ?> KZ</td>
                                <td class="text-end text-success"><?php echo formatarMoeda($total_creditos); ?> KZ</td>
                                <td class="text-end <?php echo $saldo_atual < 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatarMoeda(abs($saldo_atual)); ?> KZ
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-info-circle"></i> Legenda</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-danger" style="width: 20px; height: 20px; border-radius: 4px;"></div>
                        <span><strong>Débito</strong> - Valores a pagar</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-success" style="width: 20px; height: 20px; border-radius: 4px;"></div>
                        <span><strong>Crédito</strong> - Pagamentos realizados</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-check-circle text-success"></i>
                        <span><strong>Quitado</strong> - Mês sem débito</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-clock text-warning"></i>
                        <span><strong>Pendente</strong> - Mês com débito</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
</script>
</body>
</html>