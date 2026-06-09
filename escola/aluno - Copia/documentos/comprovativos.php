<?php
// aluno/documentos/comprovativos.php - Comprovativos de Pagamentos e Documentos

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
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
// BUSCAR TIPOS DE PAGAMENTO
// ============================================
$sql_tipos_pagamento = "SELECT id, nome, descricao FROM tipos_pagamento WHERE ativo=1 ORDER BY nome";
$stmt_tipos = $conn->prepare($sql_tipos_pagamento);
$stmt_tipos->execute();
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// Array para mapear IDs dos tipos
$tipos_map = [];
foreach ($tipos_pagamento as $tipo) {
    $tipos_map[$tipo['id']] = $tipo['nome'];
}

// ============================================
// PROCESSAR FILTROS
// ============================================
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
$busca_filtro = isset($_GET['busca']) ? trim($_GET['busca']) : '';

// ============================================
// BUSCAR COMPROVATIVOS DA TABELA PAGAMENTOS
// ============================================

$sql_pagamentos = "SELECT 
                      p.id,
                      p.escola_id,
                      p.assinatura_id as aluno_id,
                      p.tipo_pagamento_id,
                      p.tipo_pagamento,
                      p.valor,
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
                      p.troco,
                      'pagamentos' as origem,
                      CASE 
                          WHEN p.tipo_pagamento = 'mensalidade' THEN 'Mensalidade'
                          WHEN p.tipo_pagamento = 'matricula' THEN 'Matrícula'
                          WHEN p.tipo_pagamento = 'certificado' THEN 'Certificado'
                          WHEN p.tipo_pagamento = 'material' THEN 'Material'
                          WHEN p.tipo_pagamento = 'taxa' THEN 'Taxa Escolar'
                          WHEN p.tipo_pagamento = 'uniforme' THEN 'Uniforme'
                          ELSE COALESCE(p.tipo_pagamento, 'Outro Pagamento')
                      END as tipo_label,
                      CASE 
                          WHEN p.metodo_pagamento = 'dinheiro' THEN 'Dinheiro'
                          WHEN p.metodo_pagamento = 'transferencia' THEN 'Transferência Bancária'
                          WHEN p.metodo_pagamento = 'deposito' THEN 'Depósito'
                          WHEN p.metodo_pagamento = 'cheque' THEN 'Cheque'
                          WHEN p.metodo_pagamento = 'multicaixa' THEN 'Multicaixa'
                          WHEN p.metodo_pagamento = 'pix' THEN 'PIX'
                          WHEN p.metodo_pagamento = 'cartao' THEN 'Cartão'
                          ELSE 'Outro'
                      END as metodo_label,
                      DATE_FORMAT(p.data_pagamento, '%d/%m/%Y') as data_formatada,
                      DATE_FORMAT(p.data_pagamento, '%H:%i') as hora_formatada,
                      COALESCE(p.referente, p.observacoes, 'Pagamento realizado') as descricao_completa,
                      YEAR(p.data_pagamento) as ano_pagamento,
                      p.tipo_pagamento as tipo_original,
                      p.data_pagamento as data_original,
                      p.valor as valor_pago,
                      0 as desconto,
                      0 as multa,
                      0 as juros
                   FROM pagamentos p
                   WHERE p.assinatura_id = :aluno_id 
                   AND p.status = 'confirmado'
                   AND p.escola_id = :escola_id";

// ============================================
// BUSCAR COMPROVATIVOS DA TABELA OUTROS_PAGAMENTOS
// ============================================

$sql_outros = "SELECT 
                  op.id,
                  op.escola_id,
                  op.aluno_id,
                  op.tipo_pagamento_id,
                  NULL as tipo_pagamento,
                  op.valor_pago as valor,
                  CONCAT('Pagamento - ', tp.nome) as referente,
                  NULL as metodo_pagamento,
                  op.status,
                  NULL as numero_fatura,
                  NULL as numero_referencia,
                  NULL as comprovativo_path,
                  NULL as comprovativo_numero,
                  NULL as comprovante,
                  op.data_pagamento,
                  op.data_vencimento,
                  NULL as codigo_transacao,
                  op.observacoes,
                  NULL as quem_recebeu,
                  NULL as quem_pagou,
                  NULL as troco,
                  'outros_pagamentos' as origem,
                  COALESCE(tp.nome, 'Outro Pagamento') as tipo_label,
                  'Diversos' as metodo_label,
                  DATE_FORMAT(op.data_pagamento, '%d/%m/%Y') as data_formatada,
                  DATE_FORMAT(op.data_pagamento, '%H:%i') as hora_formatada,
                  COALESCE(op.observacoes, CONCAT('Pagamento de ', tp.nome, ' - ', op.mes_referencia, '/', op.ano_referencia)) as descricao_completa,
                  YEAR(op.data_pagamento) as ano_pagamento,
                  tp.nome as tipo_original,
                  op.data_pagamento as data_original,
                  op.valor_pago,
                  op.desconto,
                  op.multa,
                  op.juros
               FROM outros_pagamentos op
               LEFT JOIN tipos_pagamento tp ON tp.id = op.tipo_pagamento_id
               WHERE op.aluno_id = :aluno_id2 
               AND op.status = 'confirmado'
               AND op.escola_id = :escola_id2";

// ============================================
// UNIFICAR E APLICAR FILTROS
// ============================================

$sql_unificado = "SELECT * FROM (
                      $sql_pagamentos
                      UNION ALL
                      $sql_outros
                  ) AS pagamentos_unificados
                  WHERE 1=1";

if ($ano_filtro > 0) {
    $sql_unificado .= " AND ano_pagamento = :ano";
}
if ($tipo_filtro != 'todos') {
    $sql_unificado .= " AND tipo_original = :tipo";
}
if (!empty($busca_filtro)) {
    $sql_unificado .= " AND (numero_fatura LIKE :busca OR numero_referencia LIKE :busca OR descricao_completa LIKE :busca OR codigo_transacao LIKE :busca)";
}

$sql_unificado .= " ORDER BY data_original DESC";

$stmt_pagamentos = $conn->prepare($sql_unificado);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':aluno_id2' => $aluno_id,
    ':escola_id2' => $escola_id
];
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($busca_filtro)) {
    $params[':busca'] = "%$busca_filtro%";
}
$stmt_pagamentos->execute($params);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR COMPROVATIVOS DE MATRÍCULA
// ============================================
$sql_matriculas = "SELECT m.*,
                          t.nome as turma_nome,
                          t.ano as turma_ano,
                          DATE_FORMAT(m.data_matricula, '%d/%m/%Y') as data_formatada
                   FROM matriculas m
                   JOIN turmas t ON t.id = m.turma_id
                   WHERE m.estudante_id = :aluno_id 
                   AND m.escola_id = :escola_id
                   ORDER BY m.ano_letivo DESC";
$stmt_matriculas = $conn->prepare($sql_matriculas);
$stmt_matriculas->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$matriculas = $stmt_matriculas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_pagamentos = count($pagamentos);
$total_valor = array_sum(array_column($pagamentos, 'valor_pago'));
$anos_disponiveis = array_unique(array_column($pagamentos, 'ano_pagamento'));
sort($anos_disponiveis);

// Totais por tipo
$totais_por_tipo = [];
foreach ($pagamentos as $pg) {
    $tipo = $pg['tipo_label'];
    if (!isset($totais_por_tipo[$tipo])) {
        $totais_por_tipo[$tipo] = [
            'total' => 0,
            'quantidade' => 0
        ];
    }
    $totais_por_tipo[$tipo]['total'] += $pg['valor_pago'];
    $totais_por_tipo[$tipo]['quantidade']++;
}

// Separar por origem
$pagamentos_principal = array_filter($pagamentos, function($p) {
    return $p['origem'] == 'pagamentos';
});
$pagamentos_outros = array_filter($pagamentos, function($p) {
    return $p['origem'] == 'outros_pagamentos';
});

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getIconeTipo($tipo_original, $origem) {
    $tipo_lower = strtolower($tipo_original);
    
    if ($origem == 'pagamentos') {
        $icones = [
            'mensalidade' => 'fa-calendar-alt',
            'matricula' => 'fa-user-graduate',
            'certificado' => 'fa-certificate',
            'material' => 'fa-book',
            'taxa' => 'fa-hand-holding-usd',
            'uniforme' => 'fa-tshirt'
        ];
    } else {
        $icones = [
            'mensalidade' => 'fa-calendar-alt',
            'matricula' => 'fa-user-graduate',
            'certificado' => 'fa-certificate',
            'segunda chamada' => 'fa-redo-alt',
            'material' => 'fa-box',
            'uniforme' => 'fa-tshirt',
            'excursão' => 'fa-bus',
            'evento' => 'fa-calendar-check',
            'biblioteca' => 'fa-book',
            'taxa' => 'fa-hand-holding-usd'
        ];
    }
    
    foreach ($icones as $key => $icone) {
        if (strpos($tipo_lower, $key) !== false) {
            return $icone;
        }
    }
    return 'fa-receipt';
}

function getStatusBadge($status) {
    if ($status == 'confirmado') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Confirmado</span>';
    } elseif ($status == 'pendente') {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
    }
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovativos | Área do Aluno</title>
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
        
        .comprovativo-card {
            transition: all 0.3s;
        }
        .comprovativo-card:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .btn-comprovativo {
            transition: all 0.3s;
        }
        .btn-comprovativo:hover {
            transform: scale(1.05);
        }
        
        .info-aluno {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 10px;
            padding: 15px;
        }
        
        .badge-origem {
            font-size: 10px;
            padding: 2px 6px;
            margin-left: 5px;
        }
        
        .detalhes-pagamento {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
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
                <h2><i class="fas fa-receipt"></i> Meus Comprovativos</h2>
                <p class="text-muted">Acesse e faça o download dos seus comprovativos de pagamento</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno mb-4">
            <div class="row">
                <div class="col-md-4">
                    <i class="fas fa-user-graduate"></i>
                    <strong>Aluno:</strong> <?php echo htmlspecialchars($aluno['nome']); ?>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-id-card"></i>
                    <strong>Matrícula:</strong> <?php echo $aluno['matricula']; ?>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-users"></i>
                    <strong>Turma:</strong> <?php echo $aluno['turma_ano'] . 'ª - ' . ($aluno['turma_nome'] ?? 'Não atribuída'); ?>
                </div>
                <div class="col-md-2">
                    <i class="fas fa-money-bill"></i>
                    <strong>Total Pago:</strong> <?php echo formatarMoeda($total_valor); ?>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_pagamentos; ?></div>
                    <div class="stat-label"><i class="fas fa-receipt"></i> Total de Pagamentos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($total_valor); ?></div>
                    <div class="stat-label"><i class="fas fa-money-bill-wave"></i> Valor Total Pago</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo count($pagamentos_principal); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Pagamentos Principais</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo count($pagamentos_outros); ?></div>
                    <div class="stat-label"><i class="fas fa-tag"></i> Outros Pagamentos</div>
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
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Ano</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos os anos</option>
                            <?php foreach ($anos_disponiveis as $ano): ?>
                            <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>>
                                <?php echo $ano; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                            <option value="matricula" <?php echo $tipo_filtro == 'matricula' ? 'selected' : ''; ?>>Matrícula</option>
                            <option value="certificado" <?php echo $tipo_filtro == 'certificado' ? 'selected' : ''; ?>>Certificado</option>
                            <option value="material" <?php echo $tipo_filtro == 'material' ? 'selected' : ''; ?>>Material</option>
                            <option value="taxa" <?php echo $tipo_filtro == 'taxa' ? 'selected' : ''; ?>>Taxa</option>
                            <option value="uniforme" <?php echo $tipo_filtro == 'uniforme' ? 'selected' : ''; ?>>Uniforme</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nº Fatura, Referência, Código..." value="<?php echo htmlspecialchars($busca_filtro); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($ano_filtro > 0 || $tipo_filtro != 'todos' || !empty($busca_filtro)): ?>
                        <a href="comprovativos.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Comprovativos -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Comprovativos de Pagamento</h5>
                        <small>Total de <?php echo $total_pagamentos; ?> registro(s)</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pagamentos)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                                <h5>Nenhum comprovativo encontrado</h5>
                                <p class="text-muted">Não há pagamentos registrados com os filtros selecionados.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Data</th>
                                            <th>Hora</th>
                                            <th>Tipo</th>
                                            <th>Descrição</th>
                                            <th class="text-end">Valor</th>
                                            <th>Status</th>
                                            <th class="text-center">Comprovativo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pagamentos as $pg): ?>
                                        <tr class="comprovativo-card">
                                            <td><?php echo $pg['data_formatada']; ?></td>
                                            <td><?php echo $pg['hora_formatada']; ?></td>
                                            <td>
                                                <i class="fas <?php echo getIconeTipo($pg['tipo_original'], $pg['origem']); ?> me-1"></i>
                                                <?php echo $pg['tipo_label']; ?>
                                                <?php if ($pg['origem'] == 'outros_pagamentos'): ?>
                                                    <span class="badge bg-info badge-origem">Extra</span>
                                                <?php endif; ?>
                                                <?php if ($pg['desconto'] > 0): ?>
                                                    <span class="badge bg-success badge-origem">Desconto</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars(substr($pg['descricao_completa'], 0, 60)); ?></strong>
                                                <?php if ($pg['codigo_transacao']): ?>
                                                <div class="detalhes-pagamento">
                                                    <i class="fas fa-qrcode"></i> Transação: <?php echo $pg['codigo_transacao']; ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($pg['numero_fatura']): ?>
                                                <div class="detalhes-pagamento">
                                                    <i class="fas fa-file-invoice"></i> Fatura: <?php echo $pg['numero_fatura']; ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($pg['comprovativo_numero']): ?>
                                                <div class="detalhes-pagamento">
                                                    <i class="fas fa-hashtag"></i> Nº Comprovativo: <?php echo $pg['comprovativo_numero']; ?>
                                                </div>
                                                <?php endif; ?>
                                             </td>
                                            <td class="text-end text-success fw-bold"><?php echo formatarMoeda($pg['valor_pago']); ?>
                                                <?php if ($pg['desconto'] > 0): ?>
                                                <br><small class="text-muted">Desconto: <?php echo formatarMoeda($pg['desconto']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($pg['multa'] > 0): ?>
                                                <br><small class="text-danger">Multa: <?php echo formatarMoeda($pg['multa']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatusBadge($pg['status']); ?></td>
                                            <td class="text-center">
                                                <?php if (!empty($pg['comprovativo_path']) || !empty($pg['comprovante'])): ?>
                                                    <?php $caminho = !empty($pg['comprovativo_path']) ? $pg['comprovativo_path'] : $pg['comprovante']; ?>
                                                    <a href="<?php echo $caminho; ?>" target="_blank" class="btn btn-sm btn-danger btn-comprovativo">
                                                        <i class="fas fa-file-pdf"></i> Ver
                                                    </a>
                                                    <a href="<?php echo $caminho; ?>" download class="btn btn-sm btn-success btn-comprovativo">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-info btn-comprovativo" onclick="gerarComprovativo(<?php echo $pg['id']; ?>, '<?php echo $pg['origem']; ?>')">
                                                        <i class="fas fa-file-pdf"></i> Gerar
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="4" class="text-end">TOTAL:</td>
                                            <td class="text-end"><?php echo formatarMoeda($total_valor); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Histórico de Matrículas -->
        <?php if (!empty($matriculas)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-graduate"></i> Histórico de Matrículas</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ano Letivo</th>
                                        <th>Turma</th>
                                        <th>Data Matrícula</th>
                                        <th>Status</th>
                                        <th class="text-center">Comprovativo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($matriculas as $mat): ?>
                                    <tr>
                                        <td><?php echo $mat['ano_letivo']; ?></td>
                                        <td><?php echo $mat['turma_ano'] . 'ª ' . $mat['turma_nome']; ?></td>
                                        <td><?php echo $mat['data_formatada']; ?></td>
                                        <td><span class="badge bg-success">Ativa</span></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-danger btn-comprovativo" onclick="gerarComprovativoMatricula(<?php echo $mat['id']; ?>)">
                                                <i class="fas fa-file-pdf"></i> Comprovativo
                                            </button>
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
        <?php endif; ?>
        
        <!-- Resumo por Tipo -->
        <?php if (!empty($totais_por_tipo)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Resumo por Tipo de Pagamento</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($totais_por_tipo as $tipo => $dados): ?>
                            <div class="col-md-4 mb-2">
                                <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                    <div>
                                        <i class="fas fa-tag me-2 text-primary"></i>
                                        <strong><?php echo $tipo; ?></strong>
                                    </div>
                                    <div>
                                        <span class="badge bg-info"><?php echo $dados['quantidade']; ?> pag.</span>
                                        <span class="badge bg-success ms-1"><?php echo formatarMoeda($dados['total']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Informações Importantes -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações Importantes</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb"></i>
                            <strong>Dica:</strong> Mantenha seus comprovantes de pagamento em local seguro. 
                            Em caso de dúvidas, procure a secretaria da escola.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <strong>Comprovante Oficial:</strong>
                            Os comprovativos gerados pelo sistema têm validade oficial.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Gerar Comprovativo -->
    <div class="modal fade" id="gerarModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-file-pdf"></i> Gerar Comprovativo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center" id="gerarConteudo">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Gerando...</span>
                    </div>
                    <p class="mt-2">Gerando comprovativo, aguarde...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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
        
        // Gerar comprovativo de pagamento
        function gerarComprovativo(pagamentoId, origem) {
            $('#gerarConteudo').html(`
                <div class="spinner-border text-danger" role="status">
                    <span class="visually-hidden">Gerando...</span>
                </div>
                <p class="mt-2">Gerando comprovativo, aguarde...</p>
            `);
            
            $.ajax({
                url: 'gerar_comprovativo_pdf.php',
                method: 'POST',
                data: { pagamento_id: pagamentoId, origem: origem },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#gerarConteudo').html(`
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5>Comprovativo Gerado!</h5>
                            <p>Seu comprovativo foi gerado com sucesso.</p>
                            <a href="${response.arquivo}" target="_blank" class="btn btn-danger mt-2">
                                <i class="fas fa-file-pdf"></i> Baixar Comprovativo
                            </a>
                        `);
                        setTimeout(() => {
                            location.reload();
                        }, 3000);
                    } else {
                        $('#gerarConteudo').html(`
                            <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                            <h5>Erro ao Gerar</h5>
                            <p>${response.message}</p>
                        `);
                    }
                },
                error: function() {
                    $('#gerarConteudo').html(`
                        <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
                        <h5>Erro!</h5>
                        <p>Não foi possível gerar o comprovativo. Tente novamente.</p>
                    `);
                }
            });
            
            new bootstrap.Modal(document.getElementById('gerarModal')).show();
        }
        
        // Gerar comprovativo de matrícula
        function gerarComprovativoMatricula(matriculaId) {
            $('#gerarConteudo').html(`
                <div class="spinner-border text-danger" role="status">
                    <span class="visually-hidden">Gerando...</span>
                </div>
                <p class="mt-2">Gerando comprovativo de matrícula, aguarde...</p>
            `);
            
            $.ajax({
                url: 'gerar_comprovativo_matricula.php',
                method: 'POST',
                data: { matricula_id: matriculaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#gerarConteudo').html(`
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5>Comprovativo Gerado!</h5>
                            <p>Seu comprovativo de matrícula foi gerado com sucesso.</p>
                            <a href="${response.arquivo}" target="_blank" class="btn btn-danger mt-2">
                                <i class="fas fa-file-pdf"></i> Baixar Comprovativo
                            </a>
                        `);
                    } else {
                        $('#gerarConteudo').html(`
                            <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                            <h5>Erro ao Gerar</h5>
                            <p>${response.message}</p>
                        `);
                    }
                },
                error: function() {
                    $('#gerarConteudo').html(`
                        <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
                        <h5>Erro!</h5>
                        <p>Não foi possível gerar o comprovativo. Tente novamente.</p>
                    `);
                }
            });
            
            new bootstrap.Modal(document.getElementById('gerarModal')).show();
        }
    </script>
</body>
</html>