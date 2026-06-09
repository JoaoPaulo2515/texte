<?php
// aluno/financeiro/boletos.php - Segunda Via de Boletos e Pagamentos

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
                     es.nome as escola_nome, es.logo as escola_logo
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

// Gerar novo boleto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gerar_boleto') {
    $tipo = $_POST['tipo'] ?? 'mensalidade';
    $mes_referencia = $_POST['mes_referencia'] ?? null;
    $ano_referencia = $_POST['ano_referencia'] ?? date('Y');
    $valor = (float)($_POST['valor'] ?? 0);
    $data_vencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime('+10 days'));
    
    // Gerar código único do boleto
    $codigo_barras = gerarCodigoBarras($aluno_id, $tipo, $data_vencimento);
    $linha_digitavel = gerarLinhaDigitavel($codigo_barras);
    $qr_code_pix = gerarQRCodePIX($aluno['escola_nome'], $aluno['nome'], $valor, $codigo_barras);
    
    // Inserir boleto no banco
    $sql_insert = "INSERT INTO boletos (escola_id, aluno_id, tipo, mes_referencia, ano_referencia, 
                   valor, data_vencimento, codigo_barras, linha_digitavel, qr_code_pix, status, data_geracao)
                   VALUES (:escola_id, :aluno_id, :tipo, :mes_referencia, :ano_referencia,
                   :valor, :data_vencimento, :codigo_barras, :linha_digitavel, :qr_code_pix, 'gerado', NOW())";
    
    $stmt_insert = $conn->prepare($sql_insert);
    $result = $stmt_insert->execute([
        ':escola_id' => $escola_id,
        ':aluno_id' => $aluno_id,
        ':tipo' => $tipo,
        ':mes_referencia' => $mes_referencia,
        ':ano_referencia' => $ano_referencia,
        ':valor' => $valor,
        ':data_vencimento' => $data_vencimento,
        ':codigo_barras' => $codigo_barras,
        ':linha_digitavel' => $linha_digitavel,
        ':qr_code_pix' => $qr_code_pix
    ]);
    
    if ($result) {
        $boleto_id = $conn->lastInsertId();
        $mensagem_sucesso = "Boleto gerado com sucesso!";
        // Redirecionar para visualizar o boleto
        header("Location: boletos.php?visualizar=$boleto_id");
        exit;
    } else {
        $mensagem_erro = "Erro ao gerar boleto. Tente novamente.";
    }
}

// Marcar boleto como pago (simulação - integração com gateway)
if (isset($_GET['pagar']) && is_numeric($_GET['pagar'])) {
    $boleto_id = (int)$_GET['pagar'];
    
    $sql_update = "UPDATE boletos SET status = 'pago', data_pagamento = NOW() 
                   WHERE id = :id AND aluno_id = :aluno_id AND escola_id = :escola_id";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->execute([
        ':id' => $boleto_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    
    if ($stmt_update->rowCount() > 0) {
        $mensagem_sucesso = "Pagamento confirmado com sucesso!";
    } else {
        $mensagem_erro = "Erro ao confirmar pagamento.";
    }
}

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

// ============================================
// BUSCAR BOLETOS
// ============================================
$sql_boletos = "SELECT b.*,
                       CASE 
                           WHEN b.tipo = 'mensalidade' THEN 'Mensalidade'
                           WHEN b.tipo = 'matricula' THEN 'Matrícula'
                           WHEN b.tipo = 'certificado' THEN 'Certificado'
                           WHEN b.tipo = 'material' THEN 'Material'
                           WHEN b.tipo = 'taxa' THEN 'Taxa Escolar'
                           ELSE 'Outro'
                       END as tipo_label,
                       CASE 
                           WHEN b.status = 'gerado' AND b.data_vencimento < CURDATE() THEN 'vencido'
                           ELSE b.status
                       END as status_real,
                       DATEDIFF(b.data_vencimento, CURDATE()) as dias_restantes
                FROM boletos b
                WHERE b.aluno_id = :aluno_id AND b.escola_id = :escola_id";

if ($status_filtro != 'todos') {
    if ($status_filtro == 'vencidos') {
        $sql_boletos .= " AND b.status = 'gerado' AND b.data_vencimento < CURDATE()";
    } else {
        $sql_boletos .= " AND b.status = :status";
    }
}
if ($tipo_filtro != 'todos') {
    $sql_boletos .= " AND b.tipo = :tipo";
}

$sql_boletos .= " ORDER BY b.data_vencimento ASC, b.data_geracao DESC";

$stmt_boletos = $conn->prepare($sql_boletos);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
];
if ($status_filtro != 'todos' && $status_filtro != 'vencidos') {
    $params[':status'] = $status_filtro;
}
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
$stmt_boletos->execute($params);
$boletos = $stmt_boletos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR MENSALIDADES PENDENTES PARA GERAR BOLETO
// ============================================
$sql_mensalidades = "SELECT 
                        DISTINCT m.mes_referencia, m.ano_referencia, m.valor_total,
                        p.status as pagamento_status
                     FROM mensalidades m
                     LEFT JOIN pagamentos p ON p.assinatura_id = m.aluno_id   AND p.assinatura_id = :aluno_id
                     WHERE m.ano_referencia = :ano
                     AND (p.id IS NULL OR p.status != 'confirmado')
                     ORDER BY m.mes_referencia ASC";

$ano_atual = date('Y');
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([
    ':aluno_id' => $aluno_id,
    ':ano' => $ano_atual
]);
$mensalidades_pendentes = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_boletos = count($boletos);
$total_pagos = count(array_filter($boletos, function($b) { return $b['status'] == 'pago'; }));
$total_pendentes = count(array_filter($boletos, function($b) { return $b['status'] == 'gerado' && $b['data_vencimento'] >= date('Y-m-d'); }));
$total_vencidos = count(array_filter($boletos, function($b) { return $b['status'] == 'gerado' && $b['data_vencimento'] < date('Y-m-d'); }));
$valor_total_pendente = array_sum(array_filter(array_column($boletos, 'valor'), function($k, $b) use ($boletos) {
    return $boletos[$b]['status'] == 'gerado';
}, ARRAY_FILTER_USE_BOTH));

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadge($boleto) {
    if ($boleto['status'] == 'pago') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
    } elseif ($boleto['status'] == 'cancelado') {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
    } elseif ($boleto['status_real'] == 'vencido') {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
    } else {
        $dias = $boleto['dias_restantes'];
        if ($dias <= 3) {
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Vence em ' . $dias . ' dias</span>';
        }
        return '<span class="badge bg-info"><i class="fas fa-hourglass-half"></i> Pendente</span>';
    }
}

function gerarCodigoBarras($aluno_id, $tipo, $data_vencimento) {
    // Simulação - em produção usar biblioteca específica
    return '34191.79001 01043.510047 91020.150008 6 ' . date('Ymd', strtotime($data_vencimento));
}

function gerarLinhaDigitavel($codigo_barras) {
    // Simulação
    return '34191.79001 01043.510047 91020.150008 6 12345678901234';
}

function gerarQRCodePIX($escola, $aluno, $valor, $codigo) {
    // Simulação - em produção usar biblioteca para gerar PIX
    return 'pix.example.com/pay/' . base64_encode($codigo);
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletos | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
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
        
        .boleto-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .boleto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .btn-pagar {
            transition: all 0.3s;
        }
        .btn-pagar:hover {
            transform: scale(1.05);
        }
        
        .codigo-barras {
            font-family: monospace;
            font-size: 18px;
            letter-spacing: 2px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        
        .linha-digitavel {
            font-family: monospace;
            font-size: 14px;
            background: #e9ecef;
            padding: 8px;
            border-radius: 5px;
            text-align: center;
        }
        
        .qr-code {
            padding: 15px;
            background: white;
            border-radius: 10px;
            text-align: center;
        }
        
        @media print {
            .no-print { display: none; }
            .boleto-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
     <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-barcode"></i> Boletos e Pagamentos</h2>
                <p class="text-muted">Gere segunda via de boletos e realize pagamentos online</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gerarBoletoModal">
                    <i class="fas fa-plus"></i> Gerar Novo Boleto
                </button>
                <button class="btn btn-outline-primary ms-2" onclick="window.print()">
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
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_boletos; ?></div>
                    <div class="stat-label"><i class="fas fa-barcode"></i> Total de Boletos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_pagos; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Pagos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_pendentes; ?></div>
                    <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $total_vencidos; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Vencidos</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="gerado" <?php echo $status_filtro == 'gerado' ? 'selected' : ''; ?>>Pendentes</option>
                            <option value="vencidos" <?php echo $status_filtro == 'vencidos' ? 'selected' : ''; ?>>Vencidos</option>
                            <option value="pago" <?php echo $status_filtro == 'pago' ? 'selected' : ''; ?>>Pagos</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                            <option value="matricula" <?php echo $tipo_filtro == 'matricula' ? 'selected' : ''; ?>>Matrícula</option>
                            <option value="certificado" <?php echo $tipo_filtro == 'certificado' ? 'selected' : ''; ?>>Certificado</option>
                            <option value="material" <?php echo $tipo_filtro == 'material' ? 'selected' : ''; ?>>Material</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($status_filtro != 'todos' || $tipo_filtro != 'todos'): ?>
                        <a href="boletos.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Boletos -->
        <div class="row">
            <?php if (empty($boletos)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                            <h5>Nenhum boleto encontrado</h5>
                            <p class="text-muted">Não há boletos gerados com os filtros selecionados.</p>
                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#gerarBoletoModal">
                                <i class="fas fa-plus"></i> Gerar primeiro boleto
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($boletos as $boleto): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card boleto-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="mb-0">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <?php echo $boleto['tipo_label']; ?>
                                </h6>
                                <?php echo getStatusBadge($boleto); ?>
                            </div>
                            
                            <div class="mb-3">
                                <div class="text-center">
                                    <span class="text-muted">Valor</span>
                                    <h3 class="text-success"><?php echo formatarMoeda($boleto['valor']); ?></h3>
                                </div>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted">Data de Vencimento</small>
                                <div>
                                    <i class="fas fa-calendar-alt"></i>
                                    <strong><?php echo formatarData($boleto['data_vencimento']); ?></strong>
                                </div>
                            </div>
                            
                            <div class="mb-2">
                                <small class="text-muted">Data de Geração</small>
                                <div>
                                    <i class="fas fa-clock"></i>
                                    <?php echo formatarData($boleto['data_geracao']); ?>
                                </div>
                            </div>
                            
                            <?php if ($boleto['mes_referencia']): ?>
                            <div class="mb-2">
                                <small class="text-muted">Referente</small>
                                <div>
                                    <i class="fas fa-calendar-month"></i>
                                    <?php echo $boleto['mes_referencia'] . '/' . $boleto['ano_referencia']; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <hr>
                            
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary btn-sm" onclick="visualizarBoleto(<?php echo $boleto['id']; ?>)">
                                    <i class="fas fa-eye"></i> Visualizar Boleto
                                </button>
                                <?php if ($boleto['status'] == 'gerado'): ?>
                                <a href="?pagar=<?php echo $boleto['id']; ?>" class="btn btn-success btn-sm btn-pagar" onclick="return confirm('Confirmar pagamento deste boleto?')">
                                    <i class="fas fa-credit-card"></i> Marcar como Pago
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Informações -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações Importantes</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Como pagar:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Clique em "Visualizar Boleto" para ver o código de barras</li>
                                <li>Pague em qualquer banco ou aplicativo</li>
                                <li>Pagamento via PIX disponível</li>
                                <li>Após o pagamento, aguarde até 48h para confirmação</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Boletos vencidos podem ser pagos com multa e juros</li>
                                <li>Em caso de dúvidas, procure a secretaria</li>
                                <li>Guarde os comprovantes de pagamento</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Gerar Boleto -->
    <div class="modal fade" id="gerarBoletoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Gerar Novo Boleto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="gerar_boleto">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tipo de Pagamento</label>
                            <select name="tipo" class="form-select" required>
                                <option value="mensalidade">Mensalidade</option>
                                <option value="matricula">Matrícula</option>
                                <option value="certificado">Certificado</option>
                                <option value="material">Material Didático</option>
                                <option value="taxa">Taxa Escolar</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Mês Referência</label>
                            <select name="mes_referencia" class="form-select">
                                <option value="">Selecione (se aplicável)</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Ano Referência</label>
                            <select name="ano_referencia" class="form-select">
                                <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                                <option value="<?php echo date('Y') + 1; ?>"><?php echo date('Y') + 1; ?></option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Valor (Kz)</label>
                            <input type="number" step="0.01" name="valor" class="form-control" required placeholder="Digite o valor">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Data de Vencimento</label>
                            <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d', strtotime('+10 days')); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Gerar Boleto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Boleto -->
    <div class="modal fade" id="visualizarBoletoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-barcode"></i> Boleto Bancário</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="boletoConteudo">
                    <div class="text-center py-5">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando boleto...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Visualizar boleto
        function visualizarBoleto(boletoId) {
            $('#boletoConteudo').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando boleto...</p>
                </div>
            `);
            
            $.ajax({
                url: 'ajax_boleto.php',
                method: 'GET',
                data: { id: boletoId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let html = `
                            <div class="boleto-container">
                                <div class="text-center mb-4">
                                    <h4>${response.escola_nome}</h4>
                                    <p>Documento de Arrecadação</p>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <small class="text-muted">Beneficiário</small>
                                        <div><strong>${response.escola_nome}</strong></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Pagador</small>
                                        <div><strong>${response.aluno_nome}</strong></div>
                                        <div>Matrícula: ${response.matricula}</div>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-4">
                                        <small class="text-muted">Vencimento</small>
                                        <div><strong>${response.data_vencimento}</strong></div>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">Valor</small>
                                        <div><strong class="text-success">${response.valor}</strong></div>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">Nº do Documento</small>
                                        <div><strong>${response.id}</strong></div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-light">
                                    <small class="text-muted">Código de Barras</small>
                                    <div class="codigo-barras">${response.codigo_barras}</div>
                                </div>
                                
                                <div class="alert alert-light">
                                    <small class="text-muted">Linha Digitável</small>
                                    <div class="linha-digitavel">${response.linha_digitavel}</div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <div class="qr-code">
                                            <div id="qrcode"></div>
                                            <small class="text-muted mt-2">Pague via PIX</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Instruções:</strong><br>
                                            1. Imprima este boleto<br>
                                            2. Pague em qualquer banco<br>
                                            3. Ou pague via PIX lendo o QR Code<br>
                                            4. Após o pagamento, aguarde a confirmação
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        $('#boletoConteudo').html(html);
                        
                        // Gerar QR Code
                        if (response.qr_code_pix) {
                            new QRCode(document.getElementById("qrcode"), {
                                text: response.qr_code_pix,
                                width: 150,
                                height: 150
                            });
                        }
                    } else {
                        $('#boletoConteudo').html('<div class="alert alert-danger">' + response.message + '</div>');
                    }
                },
                error: function() {
                    $('#boletoConteudo').html('<div class="alert alert-danger">Erro ao carregar boleto</div>');
                }
            });
            
            new bootstrap.Modal(document.getElementById('visualizarBoletoModal')).show();
        }
        
        // Verificar boleto visualizado
        <?php if (isset($_GET['visualizar'])): ?>
        $(document).ready(function() {
            visualizarBoleto(<?php echo (int)$_GET['visualizar']; ?>);
        });
        <?php endif; ?>
    </script>
</body>
</html>