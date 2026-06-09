<?php
// escola/aluno/financeiro/extrato.php - Extrato Financeiro do Aluno

require_once __DIR__ . '/../../config/database.php';
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

// ==============================================
// BUSCAR DÉBITOS DAS TABELAS mensalidades e outros_pagamentos
// ==============================================

// 1. DÉBITOS DE mensalidades (pendentes e atrasados)
$sql_mensalidades = "SELECT 
                        m.id,
                        'mensalidade' as origem,
                        m.valor_total as valor,
                        m.data_vencimento,
                        m.status,
                        CASE 
                            WHEN m.mes_referencia = 1 THEN 'Janeiro'
                            WHEN m.mes_referencia = 2 THEN 'Fevereiro'
                            WHEN m.mes_referencia = 3 THEN 'Março'
                            WHEN m.mes_referencia = 4 THEN 'Abril'
                            WHEN m.mes_referencia = 5 THEN 'Maio'
                            WHEN m.mes_referencia = 6 THEN 'Junho'
                            WHEN m.mes_referencia = 7 THEN 'Julho'
                            WHEN m.mes_referencia = 8 THEN 'Agosto'
                            WHEN m.mes_referencia = 9 THEN 'Setembro'
                            WHEN m.mes_referencia = 10 THEN 'Outubro'
                            WHEN m.mes_referencia = 11 THEN 'Novembro'
                            WHEN m.mes_referencia = 12 THEN 'Dezembro'
                        END as mes_nome,
                        m.ano_referencia,
                        DATEDIFF(NOW(), m.data_vencimento) as dias_atraso
                    FROM mensalidades m
                    WHERE m.aluno_id = :aluno_id 
                    AND m.escola_id = :escola_id
                    AND m.status IN ('pendente', 'atrasado')";

// 2. DÉBITOS DE outros_pagamentos (pendentes e parciais)
$sql_outros = "SELECT 
                    op.id,
                    'outro_pagamento' as origem,
                    op.valor_total as valor,
                    op.data_vencimento,
                    op.status,
                    NULL as mes_nome,
                    NULL as ano_referencia,
                    DATEDIFF(NOW(), op.data_vencimento) as dias_atraso
                FROM outros_pagamentos op
                WHERE op.aluno_id = :aluno_id2 
                AND op.escola_id = :escola_id2
                AND op.status IN ('pendente', 'parcial')";

// Executar queries
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

$stmt_outros = $conn->prepare($sql_outros);
$stmt_outros->execute([
    ':aluno_id2' => $aluno_id,
    ':escola_id2' => $escola_id
]);
$outros = $stmt_outros->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// COMBINAR TODOS OS DÉBITOS
// ==============================================
$todos_debitos = array_merge($mensalidades, $outros);

// ==============================================
// CALCULAR ESTATÍSTICAS UNIFICADAS
// ==============================================
$total_debitos = 0;
$total_vencidos = 0;
$total_atraso_15 = 0;
$total_atraso_30 = 0;
$total_atraso_acima_30 = 0;

foreach ($todos_debitos as $debito) {
    $valor = $debito['valor'];
    $dias_atraso = $debito['dias_atraso'];
    
    // Total de débitos
    $total_debitos += $valor;
    
    if ($dias_atraso > 0) {
        // Débitos vencidos (qualquer atraso)
        $total_vencidos += $valor;
        
        // Atraso até 15 dias
        if ($dias_atraso <= 15) {
            $total_atraso_15 += $valor;
        }
        // Atraso > 30 dias
        if ($dias_atraso > 30) {
            $total_atraso_acima_30 += $valor;
        }
    }
}

// Calcular total atraso > 15 e <= 30
$total_atraso_15_30 = $total_vencidos - $total_atraso_15 - $total_atraso_acima_30;

// ==============================================
// BUSCAR CRÉDITOS (PAGAMENTOS REALIZADOS)
// ==============================================
$sql_creditos = "SELECT 
                    p.id,
                    p.valor,
                    p.data_pagamento,
                    p.metodo_pagamento,
                    p.numero_fatura,
                    p.referente as descricao
                FROM pagamentos p
                WHERE p.assinatura_id = :aluno_id 
                AND p.escola_id = :escola_id
                AND p.status IN ('pago', 'confirmado')
                ORDER BY p.data_pagamento DESC";

$stmt_creditos = $conn->prepare($sql_creditos);
$stmt_creditos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$creditos = $stmt_creditos->fetchAll(PDO::FETCH_ASSOC);

$total_creditos = array_sum(array_column($creditos, 'valor'));

// ==============================================
// CALCULAR SALDO
// ==============================================
$saldo_atual = $total_creditos - $total_debitos;
$status_saldo = ($total_debitos > $total_creditos) ? 'devedor' : 'credor';

// ==============================================
// BUSCAR NEGOCIAÇÕES EXISTENTES
// ==============================================
$sql_negociacoes = "SELECT n.*, 
                           m.nome as operador_nome
                    FROM negociacoes n
                    LEFT JOIN estudantes m ON m.id = n.aluno_id
                    WHERE n.aluno_id = :aluno_id 
                    AND n.escola_id = :escola_id
                    ORDER BY n.created_at DESC";
$stmt_negociacoes = $conn->prepare($sql_negociacoes);
$stmt_negociacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$negociacoes = $stmt_negociacoes->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// PROCESSAR SOLICITAÇÃO DE NEGOCIAÇÃO
// ==============================================
$mensagem_negociacao = '';
$erro_negociacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'solicitar_negociacao') {
    $entrada = isset($_POST['entrada']) ? (float)$_POST['entrada'] : 0;
    $parcelas = isset($_POST['parcelas']) ? (int)$_POST['parcelas'] : 0;
    $justificativa = isset($_POST['justificativa']) ? trim($_POST['justificativa']) : '';
    
    if ($parcelas <= 0 || $parcelas > 12) {
        $erro_negociacao = "Número de parcelas inválido (máximo 12)";
    } else {
        $valor_parcela = ($total_debitos - $entrada) / $parcelas;
        $codigo_proposta = 'NEG-' . strtoupper(uniqid()) . '-' . date('Ymd');
        
        $sql_insert = "INSERT INTO negociacoes (aluno_id, escola_id, valor_total, valor_entrada,qtd_parcelas, valor_parcela, justificativa, codigo_proposta, status, data_negociacao) 
                       VALUES (:aluno_id, :escola_id, :valor_total, :entrada, :parcelas, :valor_parcela, :justificativa, :codigo, 'pendente', NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([
            ':aluno_id' => $aluno_id,
            ':escola_id' => $escola_id,
            ':valor_total' => $total_debitos,
            ':entrada' => $entrada,
            ':parcelas' => $parcelas,
            ':valor_parcela' => $valor_parcela,
            ':justificativa' => $justificativa,
            ':codigo' => $codigo_proposta
        ]);
        
        $mensagem_negociacao = "Solicitação de negociação enviada com sucesso! Aguarde a análise da secretaria financeira.";
        
        // Recarregar a página para mostrar a nova negociação
        header('Location: negociacao.php?msg=negociacao_enviada');
        exit;
    }
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusExtratoLabel($status) {
    switch ($status) {
        case 'pago':
        case 'confirmado':
            return '<span class="badge bg-success">Pago</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'aprovada':
            return '<span class="badge bg-success">Aprovada</span>';
        case 'rejeitada':
            return '<span class="badge bg-danger">Rejeitada</span>';
        case 'analise':
            return '<span class="badge bg-info">Em análise</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getStatusNegociacaoLabel($status) {
    switch ($status) {
        case 'aprovada':
            return '<span class="badge bg-success">Aprovada</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark">Aguardando análise</span>';
        case 'rejeitada':
            return '<span class="badge bg-danger">Rejeitada</span>';
        case 'concluida':
            return '<span class="badge bg-info">Concluída</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card { transition: transform 0.2s, box-shadow 0.2s; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        
        .extrato-table th, .extrato-table td { vertical-align: middle; }
        .debito-row { background-color: #fff3f3; }
        .credito-row { background-color: #f0fff4; }
        
        .badge-atraso-alto { background: #dc3545; color: white; padding: 5px 10px; border-radius: 20px; }
        .badge-atraso-medio { background: #ffc107; color: #333; padding: 5px 10px; border-radius: 20px; }
        .badge-atraso-baixo { background: #28a745; color: white; padding: 5px 10px; border-radius: 20px; }
        
        .btn-negociar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 30px;
            font-weight: bold;
        }
        
        .btn-negociar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .negociacao-card {
            background: #f8f9fa;
            border-left: 4px solid #006B3E;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 10px;
        }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        
        .stats-debitos {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-debitos {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
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
    </style>
</head>
<body>
   <?php include 'includes/menu_aluno.php'; ?>
   
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
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Negociação</div>
                <div class="ajuda-texto">Você pode solicitar uma negociação de débitos clicando no botão "Solicitar Negociação".</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Condições</div>
                <div class="ajuda-texto">As negociações podem ser parceladas em até 12x, com entrada opcional.</div>
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
            <button class="btn btn-success btn-negociar" data-bs-toggle="modal" data-bs-target="#modalNegociacao">
                <i class="fas fa-handshake"></i> Solicitar Negociação
            </button>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Mensagem de sucesso -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'negociacao_enviada'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> Solicitação de negociação enviada com sucesso! Aguarde a análise da secretaria financeira.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
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
    
    <!-- Cards de Resumo de Débitos -->
    <div class="stats-debitos">
        <div class="stat-card">
            <div class="stat-value text-danger"><?php echo formatarMoeda($total_debitos); ?> KZ</div>
            <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total Débitos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-warning"><?php echo formatarMoeda($total_vencidos); ?> KZ</div>
            <div class="stat-label"><i class="fas fa-clock"></i> Débitos Vencidos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-info"><?php echo formatarMoeda($total_atraso_15); ?> KZ</div>
            <div class="stat-label"><i class="fas fa-hourglass-start"></i> Atraso ≤ 15 dias</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-warning"><?php echo formatarMoeda($total_atraso_15_30); ?> KZ</div>
            <div class="stat-label"><i class="fas fa-hourglass-half"></i> Atraso 15-30 dias</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-danger"><?php echo formatarMoeda($total_atraso_acima_30); ?> KZ</div>
            <div class="stat-label"><i class="fas fa-hourglass-end"></i> Atraso > 30 dias</div>
        </div>
    </div>
    
    <!-- Cards de Resumo Geral -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_creditos); ?> KZ</div>
                <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total Pagamentos</div>
            </div>
        </div>
        <div class="col-md-6">
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
    
    <!-- Tabela de Débitos Pendentes -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-list"></i> Débitos Pendentes
            <span class="badge bg-secondary ms-2"><?php echo count($todos_debitos); ?> registros</span>
        </div>
        <div class="card-body">
            <?php if (empty($todos_debitos)): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <p>Nenhum débito pendente encontrado.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Descrição</th>
                                <th>Data Vencimento</th>
                                <th>Valor</th>
                                <th>Dias Atraso</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todos_debitos as $debito):
                                $dias = $debito['dias_atraso'];
                                if ($dias > 30) {
                                    $badge_class = 'badge-atraso-alto';
                                    $status_texto = 'Atrasado (>30 dias)';
                                } elseif ($dias > 15) {
                                    $badge_class = 'badge-atraso-medio';
                                    $status_texto = 'Atrasado (15-30 dias)';
                                } elseif ($dias > 0) {
                                    $badge_class = 'badge-atraso-baixo';
                                    $status_texto = 'Atrasado (≤15 dias)';
                                } else {
                                    $badge_class = 'badge bg-warning text-dark';
                                    $status_texto = 'Pendente';
                                }
                                
                                if ($debito['origem'] == 'mensalidade') {
                                    $descricao = "Mensalidade - {$debito['mes_nome']}/{$debito['ano_referencia']}";
                                } else {
                                    $descricao = "Outro Pagamento";
                                }
                            ?>
                            <tr class="debito-row">
                                <td><strong><?php echo $descricao; ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($debito['data_vencimento'])); ?></td>
                                <td class="text-end text-danger fw-bold"><?php echo formatarMoeda($debito['valor']); ?> KZ</td>
                                <td class="text-center">
                                    <?php if ($dias > 0): ?>
                                        <span class="<?php echo $badge_class; ?>"><?php echo $dias; ?> dias</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Em dia</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo getStatusExtratoLabel($debito['status']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">TOTAL:</td>
                                <td class="text-end text-danger"><?php echo formatarMoeda($total_debitos); ?> KZ</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Condições de Negociação -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-file-contract"></i> Condições de Negociação
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="fas fa-percent fa-2x text-primary mb-2"></i>
                        <h6>Descontos Especiais</h6>
                        <p class="small text-muted">Descontos progressivos para pagamento à vista</p>
                        <ul class="text-start small">
                            <li>À vista: 10% de desconto</li>
                            <li>Até 3x: 5% de desconto</li>
                            <li>Até 6x: Sem juros</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="fas fa-calendar-alt fa-2x text-success mb-2"></i>
                        <h6>Parcelamento</h6>
                        <p class="small text-muted">Parcelamento facilitado em até 12x</p>
                        <ul class="text-start small">
                            <li>Máximo 12 parcelas</li>
                            <li>Entrada opcional</li>
                            <li>1ª parcela em 30 dias</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center p-3 border rounded">
                        <i class="fas fa-hand-holding-usd fa-2x text-warning mb-2"></i>
                        <h6>Benefícios</h6>
                        <p class="small text-muted">Vantagens para negociação em dia</p>
                        <ul class="text-start small">
                            <li>Regularização do nome</li>
                            <li>Liberação de documentos</li>
                            <li>Renovação de matrícula</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Histórico de Negociações -->
    <?php if (!empty($negociacoes)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-history"></i> Histórico de Negociações
        </div>
        <div class="card-body">
            <?php foreach ($negociacoes as $neg): ?>
            <div class="negociacao-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div>
                        <strong>Código:</strong> <?php echo $neg['codigo_proposta']; ?><br>
                        <small class="text-muted">Solicitado em: <?php echo date('d/m/Y', strtotime($neg['data_negociacao'])); ?></small>
                    </div>
                    <div><?php echo getStatusNegociacaoLabel($neg['status']); ?></div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <small class="text-muted">Valor Total:</small>
                        <strong><?php echo formatarMoeda($neg['valor_total']); ?> KZ</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Entrada:</small>
                        <strong><?php echo formatarMoeda($neg['valor_entrada']); ?> KZ</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Parcelas:</small>
                        <strong><?php echo $neg['qtd_parcelas']; ?>x de <?php echo formatarMoeda($neg['valor_parcela']); ?> KZ</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Valor Final:</small>
                        <strong><?php echo formatarMoeda($neg['valor_total'] - $neg['valor_entrada']); ?> KZ</strong>
                    </div>
                </div>
                <?php if ($neg['justificativa']): ?>
                <div class="mt-2">
                    <small class="text-muted">Justificativa:</small>
                    <p class="mb-0 small"><?php echo htmlspecialchars($neg['justificativa']); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($neg['status'] == 'aprovada'): ?>
                <div class="mt-2">
                    <a href="gerar_boletos_negociacao.php?id=<?php echo $neg['id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-barcode"></i> Gerar Boletos
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Solicitar Negociação -->
<div class="modal fade" id="modalNegociacao" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-handshake"></i> Solicitar Negociação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="acao" value="solicitar_negociacao">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Resumo da Dívida:</strong><br>
                        Total em débito: <strong><?php echo formatarMoeda($total_debitos); ?> KZ</strong><br>
                        Débitos vencidos: <strong><?php echo formatarMoeda($total_vencidos); ?> KZ</strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Valor de Entrada (opcional)</label>
                        <div class="input-group">
                            <span class="input-group-text">KZ</span>
                            <input type="number" name="entrada" id="entrada" class="form-control" step="0.01" min="0" max="<?php echo $total_debitos; ?>" value="0" onchange="calcularParcela()">
                        </div>
                        <small class="text-muted">Valor que deseja pagar à vista</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Número de Parcelas</label>
                        <select name="parcelas" id="parcelas" class="form-select" onchange="calcularParcela()">
                            <option value="1">1x (à vista)</option>
                            <option value="2">2x</option>
                            <option value="3">3x</option>
                            <option value="4">4x</option>
                            <option value="5">5x</option>
                            <option value="6">6x</option>
                            <option value="7">7x</option>
                            <option value="8">8x</option>
                            <option value="9">9x</option>
                            <option value="10">10x</option>
                            <option value="11">11x</option>
                            <option value="12">12x</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Valor da Parcela Estimado</label>
                        <div class="alert alert-secondary text-center">
                            <strong id="valorParcela"><?php echo formatarMoeda($total_debitos); ?> KZ</strong>
                        </div>
                        <small class="text-muted">* Valor sujeito a análise da secretaria financeira</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Justificativa / Motivo</label>
                        <textarea name="justificativa" class="form-control" rows="3" placeholder="Descreva o motivo da solicitação de negociação..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção:</strong> A proposta será analisada pela secretaria financeira e você receberá uma notificação sobre o resultado.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Enviar Proposta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Calcular valor da parcela
    function calcularParcela() {
        var totalDebito = <?php echo $total_debitos; ?>;
        var entrada = parseFloat(document.getElementById('entrada').value) || 0;
        var parcelas = parseInt(document.getElementById('parcelas').value) || 1;
        
        var restante = totalDebito - entrada;
        var valorParcela = restante / parcelas;
        
        document.getElementById('valorParcela').innerHTML = formatarMoeda(valorParcela) + ' KZ';
    }
    
    function formatarMoeda(valor) {
        return valor.toLocaleString('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
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