<?php
// escola/aluno/financeiro/fatura_pro_forma.php - Fatura Pró-Forma

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
$titulo_pagina = 'Fatura Pró-Forma';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar informações da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ==============================================
// CALCULAR VALORES
// ==============================================

// Buscar mensalidades pendentes da tabela outros_pagamentos
$sql_mensalidades = "SELECT op.*, tp.nome as tipo_nome
                     FROM outros_pagamentos op
                     LEFT JOIN tipos_pagamento tp ON tp.id = op.tipo_pagamento_id
                     WHERE op.aluno_id = :aluno_id 
                     AND op.escola_id = :escola_id 
                     AND op.status IN ('pendente', 'parcial')
                     ORDER BY op.ano_referencia ASC, op.mes_referencia ASC";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$mensalidades = $stmt_mensalidades->fetchAll(PDO::FETCH_ASSOC);

// Buscar débitos pendentes da tabela pagamentos
$sql_debitos = "SELECT p.* 
                FROM pagamentos p
                WHERE p.assinatura_id = :aluno_id 
                AND p.escola_id = :escola_id 
                AND p.status IN ('pendente', 'parcial')
                ORDER BY p.data_vencimento ASC";
$stmt_debitos = $conn->prepare($sql_debitos);
$stmt_debitos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$debitos = $stmt_debitos->fetchAll(PDO::FETCH_ASSOC);

// Valor padrão da mensalidade (se não houver registros)
$valor_mensalidade_padrao = 15000.00;
$total_mensalidades = array_sum(array_column($mensalidades, 'valor_total'));
$total_debitos = array_sum(array_column($debitos, 'valor'));

// Serviços adicionais com preços
$servicos_precos = [
    'certidao' => ['nome' => 'Certidão de Matrícula', 'valor' => 500],
    'declaracao' => ['nome' => 'Declaração de Frequência', 'valor' => 500],
    'historico' => ['nome' => 'Histórico Escolar', 'valor' => 1000],
    'transferencia' => ['nome' => 'Documentação de Transferência', 'valor' => 1500],
    'segunda_via' => ['nome' => '2ª Via de Diploma', 'valor' => 2500],
    'atestado' => ['nome' => 'Atestado Médico Escolar', 'valor' => 300]
];

// ==============================================
// PROCESSAR SOLICITAÇÃO DE FATURA
// ==============================================
$fatura_gerada = false;
$fatura_data = [];
$erro = '';
$fatura_id = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $meses_selecionados = isset($_POST['meses']) ? $_POST['meses'] : [];
    $incluir_debitos = isset($_POST['incluir_debitos']) ? true : false;
    $servicos_extras = isset($_POST['servicos_extras']) ? $_POST['servicos_extras'] : [];
    $cupom = isset($_POST['cupom']) ? trim($_POST['cupom']) : '';
    
    if (empty($meses_selecionados) && !$incluir_debitos && empty($servicos_extras)) {
        $erro = 'Selecione pelo menos um item para a fatura.';
    } else {
        $itens_fatura = [];
        $subtotal = 0;
        
        // Adicionar mensalidades selecionadas
        foreach ($mensalidades as $mensalidade) {
            $mes_ref = $mensalidade['mes_referencia'];
            $ano_ref = $mensalidade['ano_referencia'];
            if (in_array($mes_ref, $meses_selecionados)) {
                $itens_fatura[] = [
                    'descricao' => "Mensalidade - " . getNomeMes($mes_ref) . "/$ano_ref",
                    'valor' => $mensalidade['valor_total'],
                    'tipo' => 'mensalidade',
                    'id' => $mensalidade['id']
                ];
                $subtotal += $mensalidade['valor_total'];
            }
        }
        
        // Adicionar débitos pendentes selecionados
        if ($incluir_debitos && !empty($debitos)) {
            foreach ($debitos as $debito) {
                $itens_fatura[] = [
                    'descricao' => $debito['referente'] . " (Fatura: {$debito['numero_fatura']})",
                    'valor' => $debito['valor'],
                    'tipo' => 'debito',
                    'id' => $debito['id']
                ];
                $subtotal += $debito['valor'];
            }
        }
        
        // Adicionar serviços extras
        $valor_servicos = 0;
        foreach ($servicos_extras as $servico_id) {
            if (isset($servicos_precos[$servico_id])) {
                $servico = $servicos_precos[$servico_id];
                $itens_fatura[] = [
                    'descricao' => $servico['nome'],
                    'valor' => $servico['valor'],
                    'tipo' => 'servico'
                ];
                $valor_servicos += $servico['valor'];
            }
        }
        $subtotal += $valor_servicos;
        
        // Calcular desconto
        $desconto = 0;
        if (!empty($cupom)) {
            // Verificar cupom (simplificado - pode ser expandido)
            if ($cupom == 'DESC10') {
                $desconto = $subtotal * 0.10;
            } elseif ($cupom == 'DESC20') {
                $desconto = $subtotal * 0.20;
            } elseif ($cupom == 'BOLSISTA2025') {
                $desconto = $subtotal * 0.30;
            }
        }
        
        // Calcular IVA (15% padrão - pode ser ajustado)
        $iva = 0; // Fatura pró-forma não tem IVA
        $total = $subtotal - $desconto;
        
        // Gerar número da fatura
        $ano_atual = date('Y');
        $numero_fatura = 'PF/' . $ano_atual . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Salvar no banco
        $sql = "INSERT INTO faturas_proforma 
                (escola_id, numero_fatura, estudante_id, data_emissao, data_validade, subtotal, iva, desconto, total, observacoes, status, usuario_id) 
                VALUES 
                (:escola_id, :numero, :estudante_id, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), :subtotal, :iva, :desconto, :total, :observacoes, 'pendente', :usuario_id)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':numero' => $numero_fatura,
            ':estudante_id' => $aluno_id,
            ':subtotal' => $subtotal,
            ':iva' => $iva,
            ':desconto' => $desconto,
            ':total' => $total,
            ':observacoes' => "Fatura gerada via área do aluno. Cupom utilizado: " . ($cupom ?: 'Nenhum'),
            ':usuario_id' => null
        ]);
        
        $fatura_id = $conn->lastInsertId();
        
        // Salvar itens da fatura (opcional - criar tabela faturas_proforma_itens)
        $sql_item = "INSERT INTO fatura_proforma_itens (fatura_id, descricao, valor_unitario) 
                     VALUES (:fatura_id, :descricao, :valor)";
        $stmt_item = $conn->prepare($sql_item);
        
        foreach ($itens_fatura as $item) {
            $stmt_item->execute([
                ':fatura_id' => $fatura_id,
                ':descricao' => $item['descricao'],
                ':valor' => $item['valor']
            ]);
        }
        
        $fatura_data = [
            'id' => $fatura_id,
            'numero' => $numero_fatura,
            'data_emissao' => date('d/m/Y'),
            'validade' => date('d/m/Y', strtotime('+30 days')),
            'itens' => $itens_fatura,
            'subtotal' => $subtotal,
            'desconto' => $desconto,
            'iva' => $iva,
            'total' => $total,
            'cupom' => $cupom
        ];
        
        $fatura_gerada = true;
    }
}

// Buscar faturas anteriores
$sql_faturas = "SELECT fp.*, u.nome as usuario_nome 
                FROM faturas_proforma fp
                LEFT JOIN usuarios u ON u.id = fp.usuario_id
                WHERE fp.estudante_id = :aluno_id AND fp.escola_id = :escola_id 
                ORDER BY fp.created_at DESC LIMIT 10";
$stmt_faturas = $conn->prepare($sql_faturas);
$stmt_faturas->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$faturas_anteriores = $stmt_faturas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getNomeMes($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes];
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'paga': return '<span class="badge bg-success">Paga</span>';
        case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'cancelada': return '<span class="badge bg-danger">Cancelada</span>';
        case 'expirada': return '<span class="badge bg-secondary">Expirada</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

include '../includes/menu_aluno.php';
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
        
        .fatura-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        
        .fatura-header {
            text-align: center;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        
        .fatura-header h2 { color: #006B3E; margin: 0; }
        
        .fatura-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .itens-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .itens-table th, .itens-table td { padding: 12px; border-bottom: 1px solid #eee; }
        .itens-table th { background: #f5f5f5; font-weight: bold; }
        
        .total-linha { font-weight: bold; border-top: 2px solid #333; }
        
        .btn-gerar-fatura {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: bold;
        }
        
        .btn-imprimir {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .no-print, .btn-voltar, .faturas-anteriores { display: none; }
            .fatura-container { box-shadow: none; padding: 0; }
        }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        
        .mes-checkbox {
            display: inline-block;
            margin: 5px;
            padding: 8px 15px;
            background: #f8f9fa;
            border-radius: 25px;
            cursor: pointer;
        }
        .mes-checkbox input { margin-right: 5px; }
        
        .servico-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .debito-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .quantia-devida { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-invoice"></i> Fatura Pró-Forma</h4>
            <p class="text-muted mb-0">Solicite uma pré-fatura para planejamento de pagamentos</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4"><small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small><h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6></div>
                <div class="col-md-3"><small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small><h6 class="mb-0"><?php echo $aluno_matricula; ?></h6></div>
                <div class="col-md-3"><small class="text-muted"><i class="fas fa-users"></i> Turma</small><h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6></div>
                <div class="col-md-2"><small class="text-muted"><i class="fas fa-coins"></i> Débitos</small><h6 class="mb-0 quantia-devida"><?php echo formatarMoeda($total_debitos + $total_mensalidades); ?> KZ</h6></div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo count($mensalidades); ?></div>
                <div class="stat-label">Mensalidades Pendentes</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo count($debitos); ?></div>
                <div class="stat-label">Débitos Pendentes</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo formatarMoeda($total_mensalidades + $total_debitos); ?> KZ</div>
                <div class="stat-label">Total em Aberto</div>
            </div>
        </div>
    </div>
    
    <!-- Formulário de Solicitação -->
    <?php if (!$fatura_gerada): ?>
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-header bg-white fw-bold"><i class="fas fa-plus-circle"></i> Gerar Nova Fatura Pró-Forma</div>
        <div class="card-body">
            <?php if ($erro): ?>
                <div class="alert alert-danger"><?php echo $erro; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="row g-4">
                <div class="col-md-12">
                    <label class="form-label fw-bold">Mensalidades Pendentes:</label>
                    <div class="d-flex flex-wrap">
                        <?php 
                        $meses_processados = [];
                        foreach ($mensalidades as $mensalidade): 
                            $mes = $mensalidade['mes_referencia'];
                            $ano = $mensalidade['ano_referencia'];
                            if (!in_array($mes, $meses_processados)):
                                $meses_processados[] = $mes;
                        ?>
                        <label class="mes-checkbox">
                            <input type="checkbox" name="meses[]" value="<?php echo $mes; ?>">
                            <?php echo getNomeMes($mes) . "/$ano"; ?> - <?php echo formatarMoeda($mensalidade['valor_total']); ?> KZ
                        </label>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                        <?php if (empty($mensalidades)): ?>
                            <p class="text-muted">Nenhuma mensalidade pendente.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="incluir_debitos" id="incluir_debitos" value="1">
                        <label class="form-check-label" for="incluir_debitos">
                            <strong>Incluir débitos pendentes</strong> (<?php echo count($debitos); ?> itens - Total: <?php echo formatarMoeda($total_debitos); ?> KZ)
                        </label>
                    </div>
                    <?php if (!empty($debitos)): ?>
                    <div class="mt-2 small text-muted">
                        <?php foreach ($debitos as $debito): ?>
                        <div>• <?php echo htmlspecialchars($debito['referente']); ?>: <?php echo formatarMoeda($debito['valor']); ?> KZ</div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-12">
                    <label class="form-label fw-bold">Serviços Adicionais:</label>
                    <div class="row">
                        <?php foreach ($servicos_precos as $key => $servico): ?>
                        <div class="col-md-4">
                            <div class="servico-item">
                                <input type="checkbox" name="servicos_extras[]" value="<?php echo $key; ?>" id="servico_<?php echo $key; ?>">
                                <label for="servico_<?php echo $key; ?>"><?php echo $servico['nome']; ?> (<?php echo formatarMoeda($servico['valor']); ?> KZ)</label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Cupom de Desconto (opcional)</label>
                    <input type="text" name="cupom" class="form-control" placeholder="Digite o código do cupom">
                    <small class="text-muted">Cupons disponíveis: DESC10 (10%), DESC20 (20%), BOLSISTA2025 (30%)</small>
                </div>
                
                <div class="col-md-12">
                    <button type="submit" class="btn-gerar-fatura"><i class="fas fa-file-invoice"></i> Gerar Fatura Pró-Forma</button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Fatura Gerada -->
    <div class="fatura-container">
        <div class="fatura-header">
            <h2><?php echo htmlspecialchars($escola['nome'] ?? 'SIGE Angola'); ?></h2>
            <p><?php echo $escola['endereco'] ?? ''; ?></p>
            <p>NIF: <?php echo $escola['nif'] ?? '---'; ?> | Tel: <?php echo $escola['telefone'] ?? ''; ?></p>
            <h3 class="mt-3">FATURA PRÓ-FORMA</h3>
        </div>
        
        <div class="fatura-info">
            <div class="row">
                <div class="col-md-6">
                    <strong>Nº da Fatura:</strong> <?php echo $fatura_data['numero']; ?><br>
                    <strong>Data de Emissão:</strong> <?php echo $fatura_data['data_emissao']; ?><br>
                    <strong>Validade:</strong> <?php echo $fatura_data['validade']; ?>
                </div>
                <div class="col-md-6">
                    <strong>Cliente:</strong> <?php echo htmlspecialchars($aluno_nome); ?><br>
                    <strong>Matrícula:</strong> <?php echo $aluno_matricula; ?><br>
                    <strong>Turma:</strong> <?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? ''); ?>
                </div>
            </div>
        </div>
        
        <table class="itens-table">
            <thead>
                <tr><th>Descrição</th><th width="150">Valor (KZ)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($fatura_data['itens'] as $item): ?>
                <tr>
                    <td><?php echo $item['descricao']; ?></td>
                    <td class="text-end"><?php echo formatarMoeda($item['valor']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-linha"><td><strong>Subtotal</strong></td><td class="text-end"><strong><?php echo formatarMoeda($fatura_data['subtotal']); ?></strong></td></tr>
                <?php if ($fatura_data['desconto'] > 0): ?>
                <tr><td><strong>Desconto</strong></td><td class="text-end text-danger">- <?php echo formatarMoeda($fatura_data['desconto']); ?></td></tr>
                <?php endif; ?>
                <tr class="total-linha"><td><strong>TOTAL</strong></td><td class="text-end"><strong class="text-success"><?php echo formatarMoeda($fatura_data['total']); ?></strong></td></tr>
            </tbody>
        </table>
        
        <div class="row">
            <div class="col-md-12">
                <p class="text-muted small"><i class="fas fa-info-circle"></i> Esta é uma fatura pró-forma, não possui validade fiscal. Para pagamento, solicite a fatura definitiva.</p>
                <p class="text-muted small"><i class="fas fa-calendar-alt"></i> Condições de pagamento: À vista ou parcelado em até 3x no cartão.</p>
                <?php if ($fatura_data['cupom']): ?>
                <p class="text-success small"><i class="fas fa-tag"></i> Cupom aplicado: <?php echo $fatura_data['cupom']; ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-4 no-print">
            <button class="btn-imprimir" onclick="window.print();"><i class="fas fa-print"></i> Imprimir Fatura</button>
            <button class="btn btn-success ms-2" onclick="solicitarPagamento(<?php echo $fatura_data['id']; ?>)"><i class="fas fa-credit-card"></i> Solicitar Pagamento</button>
            <a href="fatura_pro_forma.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-plus"></i> Nova Fatura</a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Histórico de Faturas -->
    <?php if (!empty($faturas_anteriores)): ?>
    <div class="card border-0 shadow-sm mt-4 faturas-anteriores">
        <div class="card-header bg-white fw-bold"><i class="fas fa-history"></i> Faturas Pró-Forma Anteriores</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Número</th><th>Data</th><th>Validade</th><th>Subtotal</th><th>Total</th><th>Status</th><th>Ação</th></tr></thead>
                    <tbody>
                        <?php foreach ($faturas_anteriores as $fat): ?>
                        <tr>
                            <td><?php echo $fat['numero_fatura']; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($fat['data_emissao'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($fat['data_validade'])); ?></td>
                            <td><?php echo formatarMoeda($fat['subtotal']); ?> KZ</td>
                            <td><strong><?php echo formatarMoeda($fat['total']); ?> KZ</strong></td>
                            <td><?php echo getStatusBadge($fat['status']); ?></td>
                            <td><button class="btn btn-sm btn-outline-primary" onclick="verFatura(<?php echo $fat['id']; ?>)"><i class="fas fa-eye"></i> Ver</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal de Pagamento -->
<div class="modal fade" id="modalPagamento" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-credit-card"></i> Solicitar Pagamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Método de Pagamento</label>
                    <select class="form-select" id="metodo_pagamento">
                        <option value="transferencia">Transferência Bancária</option>
                        <option value="pix">PIX</option>
                        <option value="cartao_credito">Cartão de Crédito</option>
                        <option value="dinheiro">Dinheiro (na secretaria)</option>
                        <option value="boleto">Boleto Bancário</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observações</label>
                    <textarea class="form-control" id="obs_pagamento" rows="2" placeholder="Informações adicionais..."></textarea>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Após a solicitação, você receberá as instruções de pagamento por e-mail.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarSolicitacao()">Enviar Solicitação</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var faturaId = <?php echo $fatura_id ?? 'null'; ?>;
    
    function solicitarPagamento(id) {
        faturaId = id;
        new bootstrap.Modal(document.getElementById('modalPagamento')).show();
    }
    
    function confirmarSolicitacao() {
        var metodo = document.getElementById('metodo_pagamento').value;
        var obs = document.getElementById('obs_pagamento').value;
        
        $.ajax({
            url: 'solicitar_pagamento_fatura.php',
            method: 'POST',
            data: { fatura_id: faturaId, metodo: metodo, observacoes: obs },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Solicitação enviada com sucesso! Você receberá as instruções por e-mail.');
                    location.reload();
                } else {
                    alert('Erro: ' + response.message);
                }
            },
            error: function() {
                alert('Erro ao enviar solicitação. Tente novamente.');
            }
        });
    }
    
    function verFatura(id) {
        window.open('ver_fatura_proforma.php?id=' + id, '_blank', 'width=800,height=600');
    }
    
    function selecionarTodosMeses() {
        document.querySelectorAll('input[name="meses[]"]').forEach(cb => cb.checked = true);
    }
    
    function desmarcarTodosMeses() {
        document.querySelectorAll('input[name="meses[]"]').forEach(cb => cb.checked = false);
    }
</script>
</body>
</html>