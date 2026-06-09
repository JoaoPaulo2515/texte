<?php
// escola/aluno/financeiro/recibos.php - Recibos de Pagamentos do Aluno

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
$titulo_pagina = 'Meus Recibos';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, email, telefone FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno através da matrícula ativa
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
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// Buscar anos disponíveis nos pagamentos
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano FROM pagamentos 
             WHERE assinatura_id = :aluno_id AND escola_id = :escola_id AND data_pagamento IS NOT NULL
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// Buscar recibos/pagamentos do aluno
$sql_recibos = "SELECT 
                    p.id,
                    p.valor,
                    p.tipo_pagamento,
                    p.tipo_pagamento_id,
                    p.referente,
                    p.metodo_pagamento,
                    p.status,
                    p.numero_fatura,
                    p.numero_referencia,
                    p.comprovativo_path,
                    p.comprovativo_numero,
                    p.comprovante,
                    p.data_pagamento,
                    p.data_vencimento,
                    p.codigo_transacao,
                    p.observacoes,
                    p.quem_recebeu,
                    p.quem_pagou,
                    p.created_at,
                    u.nome as operador_nome,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    est.nome as aluno_nome,
                    est.matricula as aluno_matricula,
                    est.email as aluno_email
                FROM pagamentos p
                LEFT JOIN usuarios u ON u.id = p.usuario_id
                LEFT JOIN turmas t ON t.id = (
                    SELECT turma_id FROM matriculas 
                    WHERE estudante_id = p.assinatura_id AND status = 'ativa' 
                    LIMIT 1
                )
                LEFT JOIN estudantes est ON est.id = p.assinatura_id
                WHERE p.assinatura_id = :aluno_id 
                AND p.escola_id = :escola_id";

if ($ano_filtro > 0) {
    $sql_recibos .= " AND YEAR(p.data_pagamento) = :ano";
}
if ($status_filtro != 'todos') {
    $sql_recibos .= " AND p.status = :status";
}
if (!empty($busca)) {
    $sql_recibos .= " AND (p.numero_fatura LIKE :busca OR p.numero_referencia LIKE :busca OR p.referente LIKE :busca OR p.codigo_transacao LIKE :busca)";
}

$sql_recibos .= " ORDER BY p.data_pagamento DESC, p.id DESC";

$stmt_recibos = $conn->prepare($sql_recibos);
$params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_recibos->execute($params);
$recibos = $stmt_recibos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_pago = 0;
$total_pendente = 0;
$total_recibos = count($recibos);

foreach ($recibos as $recibo) {
    if ($recibo['status'] == 'pago' || $recibo['status'] == 'confirmado') {
        $total_pago += $recibo['valor'];
    } elseif ($recibo['status'] == 'pendente') {
        $total_pendente += $recibo['valor'];
    }
}

// Funções auxiliares
function getStatusBadge($status) {
    switch ($status) {
        case 'pago':
        case 'confirmado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
        case 'parcial':
            return '<span class="badge bg-info"><i class="fas fa-charging-station"></i> Parcial</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getFormaPagamentoIcone($forma) {
    switch ($forma) {
        case 'dinheiro':
            return '<i class="fas fa-money-bill-wave"></i> Dinheiro';
        case 'cartao_credito':
            return '<i class="fas fa-credit-card"></i> Cartão Crédito';
        case 'cartao_debito':
            return '<i class="fas fa-credit-card"></i> Cartão Débito';
        case 'transferencia':
            return '<i class="fas fa-exchange-alt"></i> Transferência';
        case 'pix':
            return '<i class="fas fa-qrcode"></i> PIX';
        case 'boleto':
            return '<i class="fas fa-barcode"></i> Boleto';
        default:
            return '<i class="fas fa-money-bill"></i> ' . ucfirst(str_replace('_', ' ', $forma));
    }
}

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
        
        .recibo-card {
            background: white;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
        }
        .recibo-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .recibo-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .recibo-body { padding: 20px; }
        .recibo-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-radius: 0 0 12px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-top: 1px solid #e0e0e0;
        }
        .numero-recibo {
            font-size: 1.2em;
            font-weight: bold;
            font-family: monospace;
        }
        .btn-imprimir-recibo {
            background: none;
            border: none;
            color: #006B3E;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-imprimir-recibo:hover { color: #004d2e; transform: scale(1.05); }
        
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
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .btn-print-all { display: none; }
            .recibo-card { break-inside: avoid; page-break-inside: avoid; margin-bottom: 20px; }
            .recibo-header { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        
        .modal-recibo {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
        }
        .modal-recibo.show { display: flex; }
        .modal-recibo-content {
            background: white;
            border-radius: 12px;
            max-width: 800px;
            width: 95%;
            margin: 20px auto;
            position: relative;
        }
        .modal-recibo-close {
            position: absolute;
            right: 15px;
            top: 10px;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            z-index: 10;
        }
        .modal-recibo-close:hover { color: #333; }
    </style>
</head>
<body>
   <?php include '../includes/menu_aluno.php'; ?>
   
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Meus Recibos</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todos os recibos de pagamentos realizados por você ou seus responsáveis.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status dos Recibos</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Pago</span> - Pagamento confirmado<br>
                    <span class="badge bg-warning">Pendente</span> - Aguardando pagamento<br>
                    <span class="badge bg-danger">Cancelado</span> - Pagamento cancelado<br>
                    <span class="badge bg-info">Parcial</span> - Pagamento parcial
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Imprimir Recibo</div>
                <div class="ajuda-texto">Clique no ícone <i class="fas fa-print"></i> para imprimir um recibo específico.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Filtros</div>
                <div class="ajuda-texto">Utilize os filtros para buscar recibos por ano, status ou número/descrição.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-receipt"></i> Meus Recibos</h4>
            <p class="text-muted mb-0">Histórico de pagamentos e recibos emitidos</p>
        </div>
        <div>
            <button class="btn btn-secondary btn-print-all" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir Todos
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
                    <small class="text-muted"><i class="fas fa-receipt"></i> Total Recibos</small>
                    <h6 class="mb-0"><?php echo $total_recibos; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_pago); ?> KZ</div>
                <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Total Pago</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo formatarMoeda($total_pendente); ?> KZ</div>
                <div class="stat-label"><i class="fas fa-clock text-warning"></i> Total Pendente</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $total_recibos; ?></div>
                <div class="stat-label"><i class="fas fa-receipt text-info"></i> Total de Recibos</div>
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
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos os status</option>
                        <option value="pago" <?php echo $status_filtro == 'pago' ? 'selected' : ''; ?>>Pagos</option>
                        <option value="confirmado" <?php echo $status_filtro == 'confirmado' ? 'selected' : ''; ?>>Confirmados</option>
                        <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="cancelado" <?php echo $status_filtro == 'cancelado' ? 'selected' : ''; ?>>Cancelados</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Nº fatura, referência, código ou referente..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Recibos -->
    <?php if (empty($recibos)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhum recibo encontrado</h5>
            <p>Não foram encontrados recibos para os filtros selecionados.</p>
        </div>
    <?php else: ?>
        <div class="recibos-list">
            <?php foreach ($recibos as $recibo): ?>
            <div class="recibo-card fade-in" id="recibo-<?php echo $recibo['id']; ?>">
                <div class="recibo-header">
                    <div>
                        <i class="fas fa-receipt fa-lg"></i>
                        <span class="numero-recibo ms-2">
                            Recibo <?php echo $recibo['numero_fatura'] ? 'nº ' . $recibo['numero_fatura'] : 'nº ' . str_pad($recibo['id'], 8, '0', STR_PAD_LEFT); ?>
                        </span>
                        <?php if ($recibo['numero_referencia']): ?>
                        <small class="ms-2 opacity-75">(Ref: <?php echo $recibo['numero_referencia']; ?>)</small>
                        <?php endif; ?>
                    </div>
                    <div><?php echo getStatusBadge($recibo['status']); ?></div>
                </div>
                <div class="recibo-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="140"><small class="text-muted">Data Pagamento:</small></td>
                                    <td><strong><?php echo $recibo['data_pagamento'] ? date('d/m/Y', strtotime($recibo['data_pagamento'])) : '-'; ?></strong></td>
                                </tr>
                                <?php if ($recibo['data_vencimento']): ?>
                                <tr>
                                    <td><small class="text-muted">Vencimento:</small></td>
                                    <td><?php echo date('d/m/Y', strtotime($recibo['data_vencimento'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><small class="text-muted">Tipo:</small></td>
                                    <td><?php echo getTipoPagamentoLabel($recibo['tipo_pagamento']); ?></td>
                                </tr>
                                <tr>
                                    <td><small class="text-muted">Referente:</small></td>
                                    <td><?php echo htmlspecialchars($recibo['referente'] ?? '-'); ?></td>
                                </tr>
                                <?php if ($recibo['codigo_transacao']): ?>
                                <tr>
                                    <td><small class="text-muted">Código Transação:</small></td>
                                    <td><code><?php echo htmlspecialchars($recibo['codigo_transacao']); ?></code></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="140"><small class="text-muted">Valor:</small></td>
                                    <td><strong><?php echo formatarMoeda($recibo['valor']); ?> KZ</strong></td>
                                </tr>
                                <tr>
                                    <td><small class="text-muted">Forma Pagamento:</small></td>
                                    <td><?php echo getFormaPagamentoIcone($recibo['metodo_pagamento']); ?></td>
                                </tr>
                                <?php if ($recibo['comprovativo_numero']): ?>
                                <tr>
                                    <td><small class="text-muted">Nº Comprovante:</small></td>
                                    <td><?php echo htmlspecialchars($recibo['comprovativo_numero']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><small class="text-muted">Quem Pagou:</small></td>
                                    <td><?php echo htmlspecialchars($recibo['quem_pagou'] ?? $aluno_nome); ?></td>
                                </tr>
                                <tr>
                                    <td><small class="text-muted">Operador:</small></td>
                                    <td><?php echo htmlspecialchars($recibo['quem_recebeu'] ?? $recibo['operador_nome'] ?? 'Sistema'); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <?php if ($recibo['observacoes']): ?>
                    <div class="alert alert-secondary mt-2 mb-0">
                        <small><i class="fas fa-comment"></i> <?php echo htmlspecialchars($recibo['observacoes']); ?></small>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="recibo-footer">
                    <div>
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt"></i> Emitido em: <?php echo date('d/m/Y H:i', strtotime($recibo['created_at'])); ?>
                        </small>
                    </div>
                    <div>
                        <button class="btn-imprimir-recibo" onclick="imprimirRecibo(<?php echo $recibo['id']; ?>)">
                            <i class="fas fa-print fa-lg"></i> Imprimir Recibo
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
    
    // Função para imprimir recibo
    function imprimirRecibo(id) {
        window.open('imprimir_recibo.php?id=' + id, '_blank', 'width=800,height=600,toolbar=yes,scrollbars=yes');
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>