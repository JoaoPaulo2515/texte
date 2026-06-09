<?php
// escola/tesouraria/pagamentos.php - Gestão de Pagamentos

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: login.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR NOVO PAGAMENTO
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pagamento'])) {
    $aluno_id = (int)$_POST['aluno_id'];
    $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'mensalidade';
    $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
    $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
    $referencia = trim($_POST['referencia'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $mes_referencia = (int)($_POST['mes_referencia'] ?? date('m'));
    $ano_referencia = (int)($_POST['ano_referencia'] ?? date('Y'));
    
    if ($aluno_id <= 0) {
        $error = "Selecione um aluno.";
    } elseif ($valor <= 0) {
        $error = "Valor do pagamento inválido.";
    } else {
        try {
            $conn->beginTransaction();
            
            // CORRIGIDO: Registrar pagamento com parâmetros corretos
            $sql = "INSERT INTO pagamentos (escola_id, assinatura_id, tipo_pagamento, valor, metodo_pagamento, referente, observacoes, data_pagamento, usuario_id, status, created_at) 
                    VALUES (:escola_id, :aluno_id, :tipo_pagamento, :valor, :forma_pagamento, :referencia, :observacoes, CURDATE(), :usuario_id, 'confirmado', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':tipo_pagamento' => $tipo_pagamento,
                ':valor' => $valor,
                ':forma_pagamento' => $forma_pagamento,
                ':referencia' => $referencia,
                ':observacoes' => $observacoes,
                ':usuario_id' => $usuario_id
            ]);
            $pagamento_id = $conn->lastInsertId();
            
            // Se for mensalidade, atualizar a mensalidade correspondente
            if ($tipo_pagamento == 'mensalidade' && $mes_referencia > 0) {
                // CORRIGIDO: Verificar se existe mensalidade, se não existir criar
                $sql_check = "SELECT id, valor_total, valor_pago FROM mensalidades 
                              WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                              AND mes_referencia = :mes AND ano_referencia = :ano";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':escola_id' => $escola_id,
                    ':aluno_id' => $aluno_id,
                    ':mes' => $mes_referencia,
                    ':ano' => $ano_referencia
                ]);
                $mensalidade = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($mensalidade) {
                    // Atualizar mensalidade existente
                    $novo_valor_pago = $mensalidade['valor_pago'] + $valor;
                    $novo_status = ($novo_valor_pago >= $mensalidade['valor_total']) ? 'pago' : 'parcial';
                    
                    $sql_update = "UPDATE mensalidades SET valor_pago = :valor_pago, status = :status, data_pagamento = CURDATE() 
                                   WHERE id = :id";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([
                        ':valor_pago' => $novo_valor_pago,
                        ':status' => $novo_status,
                        ':id' => $mensalidade['id']
                    ]);
                } else {
                    // Criar nova mensalidade
                    $sql_insert = "INSERT INTO mensalidades (escola_id, aluno_id, mes_referencia, ano_referencia, valor_total, valor_pago, status, data_vencimento, created_at) 
                                   VALUES (:escola_id, :aluno_id, :mes, :ano, :valor, :valor, 'pago', DATE_ADD(CURDATE(), INTERVAL 30 DAY), NOW())";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':aluno_id' => $aluno_id,
                        ':mes' => $mes_referencia,
                        ':ano' => $ano_referencia,
                        ':valor' => $valor
                    ]);
                }
            }
            
            // Registrar no caixa - CORRIGIDO
            $sql_caixa = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, usuario_id, pagamento_id, data_movimento, status, created_at) 
                          VALUES (:escola_id, 'entrada', :categoria, :descricao, :valor, :forma_pagamento, :referencia, :usuario_id, :pagamento_id, CURDATE(), 'ativo', NOW())";
            $stmt_caixa = $conn->prepare($sql_caixa);
            $stmt_caixa->execute([
                ':escola_id' => $escola_id,
                ':categoria' => $tipo_pagamento,
                ':descricao' => "Pagamento de " . ucfirst($tipo_pagamento) . " - Aluno ID: $aluno_id",
                ':valor' => $valor,
                ':forma_pagamento' => $forma_pagamento,
                ':referencia' => $referencia,
                ':usuario_id' => $usuario_id,
                ':pagamento_id' => $pagamento_id
            ]);
            
            $conn->commit();
            $success = "Pagamento registrado com sucesso!";
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao registrar pagamento: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR DADOS PARA OS SELECTS
// ============================================

// Buscar alunos - CORRIGIDO
$sql_alunos = "SELECT e.id, e.nome, e.matricula 
               FROM estudantes e 
               WHERE e.escola_id = :escola_id 
               ORDER BY e.nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar últimas mensalidades para débitos - CORRIGIDO
$sql_debitos = "SELECT m.*, e.nome as aluno_nome, e.matricula
                FROM mensalidades m
                JOIN estudantes e ON e.id = m.aluno_id
                WHERE m.escola_id = :escola_id AND m.status IN ('pendente', 'parcial')
                ORDER BY e.nome ASC, m.ano_referencia DESC, m.mes_referencia ASC
                LIMIT 20";
$stmt_debitos = $conn->prepare($sql_debitos);
$stmt_debitos->execute([':escola_id' => $escola_id]);
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

// Buscar últimos pagamentos - CORRIGIDO
$sql_ultimos = "SELECT p.*, e.nome as aluno_nome, e.matricula 
                FROM pagamentos p
                JOIN estudantes e ON e.id = p.assinatura_id
                WHERE p.escola_id = :escola_id 
                ORDER BY p.data_pagamento DESC 
                LIMIT 10";
$stmt_ultimos = $conn->prepare($sql_ultimos);
$stmt_ultimos->execute([':escola_id' => $escola_id]);
$ultimos_pagamentos = $stmt_ultimos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoPagamentoLabel($tipo) {
    $tipos = [
        'mensalidade' => '<span class="badge bg-primary">Mensalidade</span>',
        'matricula' => '<span class="badge bg-success">Matrícula</span>',
        'certificado' => '<span class="badge bg-info">Certificado</span>',
        'material' => '<span class="badge bg-warning">Material</span>',
        'outro' => '<span class="badge bg-secondary">Outro</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge bg-secondary">' . $tipo . '</span>';
}

function getFormaPagamentoIcone($forma) {
    switch ($forma) {
        case 'dinheiro': return '<i class="fas fa-money-bill-wave text-success"></i> Dinheiro';
        case 'transferencia': return '<i class="fas fa-university text-primary"></i> Transferência';
        case 'deposito': return '<i class="fas fa-money-bill text-info"></i> Depósito';
        case 'cheque': return '<i class="fas fa-check-circle text-warning"></i> Cheque';
        case 'multicaixa': return '<i class="fas fa-credit-card text-secondary"></i> Multicaixa';
        default: return '<i class="fas fa-question-circle"></i> ' . ucfirst($forma);
    }
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content-tesouraria {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content-tesouraria { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #218838; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .table-pagamentos td { vertical-align: middle; }
        .debito-row { background-color: #fff3cd; }
        .valor-debito { color: #dc3545; font-weight: bold; }
        
        .info-aluno { font-size: 0.85rem; color: #6c757d; }
        
        .pix-area { background: #e8f5e9; border-radius: 10px; padding: 15px; margin-top: 15px; }
    </style>
</head>
<body>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content-tesouraria">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-credit-card"></i> Gestão de Pagamentos</h2>
                <p class="text-muted">Registrar e gerenciar pagamentos de mensalidades e serviços</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPagamento">
                    <i class="fas fa-plus"></i> Novo Pagamento
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Dívidas em Aberto -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Dívidas em Aberto</h5>
            </div>
            <div class="card-body">
                <?php if (empty($debitos)): ?>
                    <div class="alert alert-success text-center">
                        <i class="fas fa-check-circle"></i> Nenhuma dívida em aberto no momento!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th>
                                    <th>Mês/Ano</th>
                                    <th>Valor Total</th>
                                    <th>Valor Pago</th>
                                    <th>Saldo Devedor</th>
                                    <th>Status</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debitos as $debito): ?>
                                <tr class="debito-row">
                                    <td><strong><?php echo htmlspecialchars($debito['aluno_nome']); ?></strong><br>
                                        <small class="info-aluno">Mat: <?php echo htmlspecialchars($debito['matricula'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo getMesNome($debito['mes_referencia']) . '/' . $debito['ano_referencia']; ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_total']); ?></td>
                                    <td><?php echo formatarMoeda($debito['valor_pago'] ?? 0); ?></td>
                                    <td class="valor-debito"><?php echo formatarMoeda(($debito['valor_total'] - ($debito['valor_pago'] ?? 0))); ?></td>
                                    <td><?php echo $debito['status'] == 'pendente' ? '<span class="badge bg-danger">Pendente</span>' : '<span class="badge bg-warning text-dark">Parcial</span>'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="registrarPagamentoRapido(<?php echo $debito['aluno_id']; ?>, <?php echo $debito['valor_total'] - ($debito['valor_pago'] ?? 0); ?>, <?php echo $debito['mes_referencia']; ?>, <?php echo $debito['ano_referencia']; ?>)">
                                            <i class="fas fa-money-bill"></i> Pagar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Últimos Pagamentos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Últimos Pagamentos</h5>
            </div>
            <div class="card-body">
                <?php if (empty($ultimos_pagamentos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum pagamento registrado ainda.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-pagamentos">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Aluno</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimos_pagamentos as $pg): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pg['data_pagamento'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($pg['aluno_nome']); ?></strong><br><small class="text-muted">Mat: <?php echo htmlspecialchars($pg['matricula'] ?? ''); ?></small></td>
                                    <td><?php echo getTipoPagamentoLabel($pg['tipo_pagamento']); ?></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($pg['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($pg['metodo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($pg['referente'] ?: '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Pagamento -->
    <div class="modal fade" id="modalNovoPagamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Registrar Novo Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formPagamento">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                <select name="aluno_id" id="aluno_id" class="form-select" required>
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos as $aluno): ?>
                                    <option value="<?php echo $aluno['id']; ?>">
                                        <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Pagamento</label>
                                <select name="tipo_pagamento" id="tipo_pagamento" class="form-select" onchange="toggleMensalidade()">
                                    <option value="mensalidade">📅 Mensalidade</option>
                                    <option value="matricula">📝 Matrícula</option>
                                    <option value="certificado">🎓 Certificado</option>
                                    <option value="material">📚 Material Escolar</option>
                                    <option value="outro">📌 Outro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" placeholder="0,00" required>
                            </div>
                        </div>
                        <div class="row" id="div_mensalidade">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês Referência</label>
                                <select name="mes_referencia" class="form-select">
                                    <option value="1">Janeiro</option>
                                    <option value="2">Fevereiro</option>
                                    <option value="3">Março</option>
                                    <option value="4">Abril</option>
                                    <option value="5">Maio</option>
                                    <option value="6">Junho</option>
                                    <option value="7">Julho</option>
                                    <option value="8">Agosto</option>
                                    <option value="9">Setembro</option>
                                    <option value="10">Outubro</option>
                                    <option value="11">Novembro</option>
                                    <option value="12">Dezembro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Referência</label>
                                <select name="ano_referencia" class="form-select">
                                    <?php for($i = date('Y')-1; $i <= date('Y')+1; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Forma de Pagamento</label>
                                <select name="forma_pagamento" class="form-select" onchange="togglePixArea()">
                                    <option value="dinheiro">💵 Dinheiro</option>
                                    <option value="transferencia">🏦 Transferência Bancária</option>
                                    <option value="deposito">💰 Depósito</option>
                                    <option value="cheque">📄 Cheque</option>
                                    <option value="multicaixa">💳 Multicaixa</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Referência</label>
                                <input type="text" name="referencia" class="form-control" placeholder="Nº do comprovativo, cheque, etc.">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                        
                        <div class="pix-area" id="pixArea" style="display: none;">
                            <i class="fab fa-pix"></i> 
                            <strong>Chave PIX:</strong> suporte@sige.ao<br>
                            <small class="text-muted">Para transferências PIX, utilize a chave acima</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_pagamento" class="btn btn-primary">Registrar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Pagamento Rápido -->
    <div class="modal fade" id="modalPagamentoRapido" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-money-bill"></i> Pagamento Rápido</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="aluno_id" id="rapido_aluno_id">
                    <input type="hidden" name="tipo_pagamento" value="mensalidade">
                    <input type="hidden" name="mes_referencia" id="rapido_mes">
                    <input type="hidden" name="ano_referencia" id="rapido_ano">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Aluno</label>
                            <input type="text" id="rapido_aluno_nome" class="form-control" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor a Pagar</label>
                            <input type="text" name="valor" id="rapido_valor" class="form-control" required placeholder="0,00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select">
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="cheque">📄 Cheque</option>
                                <option value="multicaixa">💳 Multicaixa</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_pagamento" class="btn btn-primary">Confirmar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Máscara para valor
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor, #rapido_valor').on('input', function() {
            let valor = $(this).val();
            $(this).val(formatarValor(valor));
        });
        
        function toggleMensalidade() {
            let tipo = $('#tipo_pagamento').val();
            if (tipo === 'mensalidade') {
                $('#div_mensalidade').show();
            } else {
                $('#div_mensalidade').hide();
            }
        }
        
        function togglePixArea() {
            let forma = $('select[name="forma_pagamento"]').val();
            if (forma === 'transferencia') {
                $('#pixArea').show();
            } else {
                $('#pixArea').hide();
            }
        }
        
        function registrarPagamentoRapido(alunoId, valor, mes, ano) {
            $('#rapido_aluno_id').val(alunoId);
            $('#rapido_valor').val(formatarValor(valor.toString()));
            $('#rapido_mes').val(mes);
            $('#rapido_ano').val(ano);
            
            let alunoNome = $('#aluno_id option[value="' + alunoId + '"]').text();
            $('#rapido_aluno_nome').val(alunoNome);
            
            $('#modalPagamentoRapido').modal('show');
        }
        
        // Inicializar
        toggleMensalidade();
        togglePixArea();
    </script>
</body>
</html>