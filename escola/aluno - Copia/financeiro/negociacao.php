<?php
// aluno/financeiro/negociacao.php - Negociação de Débitos

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, e.email, e.telefone,
                     tur.nome as turma_nome, tur.ano as turma_ano,
                     es.nome as escola_nome
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              LEFT JOIN escolas es ON es.id = e.escola_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Solicitar negociação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar') {
    $tipo_negociacao = $_POST['tipo_negociacao'] ?? 'parcelamento';
    $qtd_parcelas = (int)($_POST['qtd_parcelas'] ?? 0);
    $valor_entrada = (float)($_POST['valor_entrada'] ?? 0);
    $justificativa = trim($_POST['justificativa'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    // Buscar débito total do aluno
    $sql_debito = "SELECT SUM(valor) as total_debito 
                   FROM pagamentos 
                   WHERE assinatura_id = :aluno_id 
                   AND status = 'pendente'
                   AND escola_id = :escola_id";
    $stmt_debito = $conn->prepare($sql_debito);
    $stmt_debito->execute([
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $debito = $stmt_debito->fetch(PDO::FETCH_ASSOC);
    $valor_total = $debito['total_debito'] ?? 0;
    
    if ($valor_total <= 0) {
        $mensagem_erro = "Você não possui débitos pendentes para negociar.";
    } else {
        // Calcular valor da parcela
        $valor_parcela = ($valor_total - $valor_entrada) / $qtd_parcelas;
        
        // Gerar código da negociação
        $codigo = 'NEG-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Inserir solicitação de negociação
        $sql_insert = "INSERT INTO solicitacoes_negociacao 
                       (escola_id, aluno_id, codigo, tipo_negociacao, valor_total, valor_entrada, 
                        qtd_parcelas, valor_parcela, justificativa, observacoes, status, data_solicitacao)
                       VALUES 
                       (:escola_id, :aluno_id, :codigo, :tipo_negociacao, :valor_total, :valor_entrada,
                        :qtd_parcelas, :valor_parcela, :justificativa, :observacoes, 'pendente', NOW())";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $result = $stmt_insert->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':codigo' => $codigo,
            ':tipo_negociacao' => $tipo_negociacao,
            ':valor_total' => $valor_total,
            ':valor_entrada' => $valor_entrada,
            ':qtd_parcelas' => $qtd_parcelas,
            ':valor_parcela' => $valor_parcela,
            ':justificativa' => $justificativa,
            ':observacoes' => $observacoes
        ]);
        
        if ($result) {
            $mensagem_sucesso = "Solicitação de negociação enviada com sucesso! Código: $codigo";
        } else {
            $mensagem_erro = "Erro ao enviar solicitação. Tente novamente.";
        }
    }
}

// Cancelar solicitação
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $solicitacao_id = (int)$_GET['cancelar'];
    
    $sql_cancelar = "UPDATE solicitacoes_negociacao 
                     SET status = 'cancelado', data_resposta = NOW(), resposta_motivo = 'Cancelado pelo aluno'
                     WHERE id = :id AND aluno_id = :aluno_id AND escola_id = :escola_id
                     AND status = 'pendente'";
    $stmt_cancelar = $conn->prepare($sql_cancelar);
    $stmt_cancelar->execute([
        ':id' => $solicitacao_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    
    if ($stmt_cancelar->rowCount() > 0) {
        $mensagem_sucesso = "Solicitação cancelada com sucesso!";
    } else {
        $mensagem_erro = "Não foi possível cancelar a solicitação.";
    }
}

// ============================================
// BUSCAR DÉBITOS PENDENTES
// ============================================

$sql_debitos = "SELECT 
                   p.id,
                   p.tipo_pagamento,
                   p.valor,
                   p.data_pagamento as data_vencimento,
                   p.numero_fatura,
                   p.observacoes,
                   CASE 
                       WHEN p.tipo_pagamento = 'mensalidade' THEN 'Mensalidade'
                       WHEN p.tipo_pagamento = 'matricula' THEN 'Matrícula'
                       WHEN p.tipo_pagamento = 'certificado' THEN 'Certificado'
                       WHEN p.tipo_pagamento = 'material' THEN 'Material'
                       ELSE 'Outro'
                   END as tipo_label,
                   DATEDIFF(NOW(), p.data_pagamento) as dias_atraso,
                   CASE 
                       WHEN DATEDIFF(NOW(), p.data_pagamento) > 30 THEN 'alto'
                       WHEN DATEDIFF(NOW(), p.data_pagamento) > 15 THEN 'medio'
                       ELSE 'baixo'
                   END as prioridade
                FROM pagamentos p
                WHERE p.assinatura_id = :aluno_id 
                AND p.status = 'pendente'
                AND p.escola_id = :escola_id
                ORDER BY p.data_pagamento ASC";

$stmt_debitos = $conn->prepare($sql_debitos);
$stmt_debitos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$total_debito = array_sum(array_column($debitos, 'valor'));
$total_debitos_vencidos = count($debitos);
$total_atraso_leve = count(array_filter($debitos, function($d) { return $d['dias_atraso'] <= 15; }));
$total_atraso_grave = count(array_filter($debitos, function($d) { return $d['dias_atraso'] > 30; }));

// ============================================
// BUSCAR SOLICITAÇÕES DE NEGOCIAÇÃO
// ============================================

$sql_solicitacoes = "SELECT s.*,
                            CASE 
                                WHEN s.tipo_negociacao = 'parcelamento' THEN 'Parcelamento'
                                WHEN s.tipo_negociacao = 'desconto' THEN 'Desconto à Vista'
                                WHEN s.tipo_negociacao = 'renegociacao' THEN 'Renegociação'
                                ELSE 'Outro'
                            END as tipo_label,
                            CASE 
                                WHEN s.status = 'pendente' THEN 'Pendente'
                                WHEN s.status = 'aprovado' THEN 'Aprovado'
                                WHEN s.status = 'rejeitado' THEN 'Rejeitado'
                                WHEN s.status = 'cancelado' THEN 'Cancelado'
                            END as status_label
                     FROM solicitacoes_negociacao s
                     WHERE s.aluno_id = :aluno_id AND s.escola_id = :escola_id
                     ORDER BY s.data_solicitacao DESC";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR NEGOCIAÇÕES ATIVAS (APROVADAS)
// ============================================

$sql_negociacoes = "SELECT n.*,
                           (SELECT COUNT(*) FROM negociacao_parcelas WHERE negociacao_id = n.id AND status = 'paga') as parcelas_pagas,
                           (SELECT COUNT(*) FROM negociacao_parcelas WHERE negociacao_id = n.id) as total_parcelas
                    FROM negociacoes n
                    WHERE n.aluno_id = :aluno_id AND n.escola_id = :escola_id
                    AND n.status = 'ativa'
                    ORDER BY n.data_negociacao DESC";
$stmt_negociacoes = $conn->prepare($sql_negociacoes);
$stmt_negociacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$negociacoes = $stmt_negociacoes->fetchAll(PDO::FETCH_ASSOC);

// Funções auxiliares
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getPrioridadeBadge($prioridade) {
    if ($prioridade == 'alto') {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Urgente</span>';
    } elseif ($prioridade == 'medio') {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Atenção</span>';
    }
    return '<span class="badge bg-info">Normal</span>';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Negociação de Débitos | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .debito-item {
            transition: all 0.3s;
        }
        .debito-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .btn-solicitar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            transition: all 0.3s;
        }
        .btn-solicitar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,107,62,0.3);
        }
        
        .simulacao-parcela {
            background: #f0fdf4;
            border: 1px solid #006B3E;
            border-radius: 10px;
            padding: 15px;
        }
        
        .parcela-item {
            transition: all 0.3s;
        }
        .parcela-item:hover {
            background: #f8f9fa;
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-handshake"></i> Negociação de Débitos</h2>
                <p class="text-muted">Regularize seus débitos com condições especiais</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($mensagem_erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $mensagem_erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($total_debito); ?></div>
                    <div class="stat-label"><i class="fas fa-money-bill-wave"></i> Débito Total</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_debitos_vencidos; ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-times"></i> Débitos Vencidos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_atraso_leve; ?></div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Atraso até 15 dias</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $total_atraso_grave; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Atraso > 30 dias</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Formulário de Solicitação -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-signature"></i> Solicitar Negociação</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($total_debito <= 0): ?>
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p>Você não possui débitos pendentes no momento.</p>
                                <small>Parabéns! Sua situação financeira está regularizada.</small>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i>
                                <strong>Débito total: <?php echo formatarMoeda($total_debito); ?></strong><br>
                                Solicite uma negociação para regularizar sua situação.
                            </div>
                            
                            <form method="POST" id="formNegociacao">
                                <input type="hidden" name="action" value="solicitar">
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Tipo de Negociação</label>
                                    <select name="tipo_negociacao" class="form-select" required onchange="atualizarSimulacao()">
                                        <option value="parcelamento">Parcelamento</option>
                                        <option value="desconto">Desconto à Vista</option>
                                        <option value="renegociacao">Renegociação Especial</option>
                                    </select>
                                </div>
                                
                                <div id="simulacaoParcelamento">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Valor de Entrada (Kz)</label>
                                        <input type="number" step="0.01" name="valor_entrada" id="valor_entrada" class="form-control" value="0" oninput="atualizarSimulacao()">
                                        <small class="text-muted">Quanto você pode pagar agora?</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Número de Parcelas</label>
                                        <select name="qtd_parcelas" id="qtd_parcelas" class="form-select" onchange="atualizarSimulacao()">
                                            <option value="2">2 parcelas</option>
                                            <option value="3">3 parcelas</option>
                                            <option value="4">4 parcelas</option>
                                            <option value="5">5 parcelas</option>
                                            <option value="6">6 parcelas</option>
                                        </select>
                                    </div>
                                    
                                    <div class="simulacao-parcela text-center" id="simulacaoResultado">
                                        <strong>Simulação do Parcelamento</strong><br>
                                        Valor total: <?php echo formatarMoeda($total_debito); ?><br>
                                        Valor restante: <?php echo formatarMoeda($total_debito); ?><br>
                                        Valor da parcela: -- Kz
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Justificativa</label>
                                    <textarea name="justificativa" class="form-control" rows="3" required 
                                              placeholder="Explique o motivo da solicitação de negociação..."></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Observações (opcional)</label>
                                    <textarea name="observacoes" class="form-control" rows="2" 
                                              placeholder="Informações adicionais..."></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-solicitar w-100">
                                    <i class="fas fa-paper-plane"></i> Solicitar Negociação
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Informações -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Condições de Negociação</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Parcelamento em até 6x</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Desconto de até 20% para pagamento à vista</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Isenção de multas e juros na negociação</li>
                            <li class="mb-2"><i class="fas fa-clock text-warning"></i> Prazo de resposta: até 5 dias úteis</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Débitos -->
            <div class="col-lg-7">
                <!-- Débitos Pendentes -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Débitos Pendentes</h5>
                        <small><?php echo $total_debitos_vencidos; ?> débito(s) encontrado(s)</small>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($debitos)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-check-circle fa-3x text-success mb-2"></i>
                                <p>Nenhum débito pendente encontrado.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Descrição</th>
                                            <th class="text-end">Valor</th>
                                            <th>Dias em atraso</th>
                                            <th>Prioridade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($debitos as $debito): ?>
                                        <tr class="debito-item">
                                            <td><?php echo $debito['tipo_label']; ?></td>
                                            <td><?php echo htmlspecialchars($debito['descricao'] ?? 'Pagamento pendente'); ?>Ne
                                            <td class="text-end text-danger fw-bold"><?php echo formatarMoeda($debito['valor']); ?></td>
                                            <td><?php echo $debito['dias_atraso']; ?> dias</td>
                                            <td><?php echo getPrioridadeBadge($debito['prioridade']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2" class="text-end">TOTAL:</td>
                                            <td class="text-end text-danger"><?php echo formatarMoeda($total_debito); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Negociações Ativas -->
                <?php if (!empty($negociacoes)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-handshake"></i> Negociações Ativas</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($negociacoes as $neg): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Código:</strong> <?php echo $neg['codigo']; ?><br>
                                    <small>Data: <?php echo formatarData($neg['data_negociacao']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-success">Ativa</span>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-4">
                                    <small class="text-muted">Valor Total</small>
                                    <div><strong><?php echo formatarMoeda($neg['valor_total']); ?></strong></div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Valor Entrada</small>
                                    <div><strong><?php echo formatarMoeda($neg['valor_entrada']); ?></strong></div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Parcelas</small>
                                    <div><strong><?php echo $neg['parcelas_pagas']; ?> / <?php echo $neg['total_parcelas']; ?> pagas</strong></div>
                                </div>
                            </div>
                            <div class="progress mt-2">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo ($neg['parcelas_pagas'] / $neg['total_parcelas']) * 100; ?>%">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Histórico de Solicitações -->
                <?php if (!empty($solicitacoes)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Histórico de Solicitações</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($solicitacoes as $solic): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo $solic['tipo_label']; ?></strong>
                                        <div class="small text-muted">
                                            Código: <?php echo $solic['codigo']; ?> | 
                                            Data: <?php echo formatarData($solic['data_solicitacao']); ?>
                                        </div>
                                        <div class="mt-1">
                                            Valor total: <?php echo formatarMoeda($solic['valor_total']); ?>
                                            <?php if ($solic['tipo_negociacao'] == 'parcelamento'): ?>
                                            | <?php echo $solic['qtd_parcelas']; ?>x de <?php echo formatarMoeda($solic['valor_parcela']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($solic['resposta_motivo']): ?>
                                        <div class="mt-2 small">
                                            <strong>Resposta:</strong> <?php echo htmlspecialchars($solic['resposta_motivo']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($solic['status'] == 'pendente'): ?>
                                            <span class="badge bg-warning text-dark">Pendente</span>
                                            <a href="?cancelar=<?php echo $solic['id']; ?>" class="btn btn-sm btn-outline-danger mt-1" 
                                               onclick="return confirm('Cancelar esta solicitação?')">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php elseif ($solic['status'] == 'aprovado'): ?>
                                            <span class="badge bg-success">Aprovado</span>
                                        <?php elseif ($solic['status'] == 'rejeitado'): ?>
                                            <span class="badge bg-danger">Rejeitado</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Cancelado</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let totalDebito = <?php echo $total_debito; ?>;
        
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Atualizar simulação de parcelamento
        function atualizarSimulacao() {
            let tipo = document.querySelector('select[name="tipo_negociacao"]').value;
            let simulacaoDiv = document.getElementById('simulacaoParcelamento');
            let resultadoDiv = document.getElementById('simulacaoResultado');
            
            if (tipo === 'desconto') {
                simulacaoDiv.style.display = 'none';
                // Mostrar simulação de desconto
                let valorComDesconto = totalDebito * 0.8;
                resultadoDiv.innerHTML = `
                    <strong>Simulação de Desconto</strong><br>
                    Valor total: ${formatarMoeda(totalDebito)}<br>
                    Desconto (20%): ${formatarMoeda(totalDebito * 0.2)}<br>
                    <strong>Valor a pagar: ${formatarMoeda(valorComDesconto)}</strong>
                `;
                resultadoDiv.classList.add('simulacao-parcela');
                resultadoDiv.style.display = 'block';
            } else {
                simulacaoDiv.style.display = 'block';
                
                let valorEntrada = parseFloat(document.getElementById('valor_entrada').value) || 0;
                let qtdParcelas = parseInt(document.getElementById('qtd_parcelas').value);
                let valorRestante = totalDebito - valorEntrada;
                let valorParcela = valorRestante / qtdParcelas;
                
                resultadoDiv.innerHTML = `
                    <strong>Simulação do Parcelamento</strong><br>
                    Valor total: ${formatarMoeda(totalDebito)}<br>
                    Valor de entrada: ${formatarMoeda(valorEntrada)}<br>
                    Valor restante: ${formatarMoeda(valorRestante)}<br>
                    <strong>${qtdParcelas}x de ${formatarMoeda(valorParcela)}</strong>
                `;
                resultadoDiv.classList.add('simulacao-parcela');
                resultadoDiv.style.display = 'block';
            }
        }
        
        // Formatar moeda
        function formatarMoeda(valor) {
            return new Intl.NumberFormat('pt-AO', { style: 'currency', currency: 'AOA' }).format(valor);
        }
        
        // Inicializar simulação
        document.addEventListener('DOMContentLoaded', function() {
            atualizarSimulacao();
        });
    </script>
</body>
</html>