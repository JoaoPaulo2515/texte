<?php
// escola/professor/dividas_receber.php - Dívidas a Receber

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR FUNCIONARIO_ID DO PROFESSOR LOGADO
// ============================================
$sql_funcionario = "
    SELECT f.id as funcionario_id, f.nome, f.cargo, f.salario_base
    FROM funcionarios f
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
    WHERE p.id = :professor_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['funcionario_id'] ?? $professor_id;
$funcionario_nome = $funcionario['nome'] ?? '';
$funcionario_salario = $funcionario['salario_base'] ?? 0;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// PROCESSAR RECEBIMENTO MANUAL
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_recebimento'])) {
    $divida_id = (int)$_POST['divida_id'];
    $valor_recebido = (float)$_POST['valor_recebido'];
    $data_recebimento = $_POST['data_recebimento'];
    $forma_recebimento = $_POST['forma_recebimento'];
    $observacao = $_POST['observacao'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        $sql_divida = "SELECT * FROM dividas_a_receber WHERE id = :id AND funcionario_id = :funcionario_id";
        $stmt_divida = $conn->prepare($sql_divida);
        $stmt_divida->execute([':id' => $divida_id, ':funcionario_id' => $funcionario_id]);
        $divida = $stmt_divida->fetch(PDO::FETCH_ASSOC);
        
        if ($divida) {
            $sql_hist = "
                INSERT INTO recebimentos_historico (
                    divida_id, funcionario_id, valor_recebido, data_recebimento, forma_recebimento, observacao, created_at
                ) VALUES (
                    :divida_id, :funcionario_id, :valor_recebido, :data_recebimento, :forma_recebimento, :observacao, NOW()
                )
            ";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':divida_id' => $divida_id,
                ':funcionario_id' => $funcionario_id,
                ':valor_recebido' => $valor_recebido,
                ':data_recebimento' => $data_recebimento,
                ':forma_recebimento' => $forma_recebimento,
                ':observacao' => $observacao
            ]);
            
            $success = "✅ Recebimento registrado com sucesso! O status foi atualizado automaticamente.";
        }
        
        $conn->commit();
        header("Location: dividas_receber.php?success=1");
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao processar recebimento: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR DÍVIDAS A RECEBER
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

$sql_dividas = "
    SELECT 
        d.*,
        COALESCE(d.valor_original, 0) as valor_original,
        COALESCE(d.valor_recebido, 0) as valor_recebido,
        (d.valor_original - COALESCE(d.valor_recebido, 0)) as valor_restante,
        COALESCE(d.juros, 0) as juros,
        COALESCE(d.multas, 0) as multas,
        COALESCE(d.desconto, 0) as desconto,
        COALESCE(d.gerado_automaticamente, 0) as gerado_automaticamente
    FROM dividas_a_receber d
    WHERE d.funcionario_id = :funcionario_id
";

if ($status_filtro != 'todas') {
    $sql_dividas .= " AND d.status = :status";
}
if ($tipo_filtro != 'todos') {
    $sql_dividas .= " AND d.tipo = :tipo";
}
if (!empty($busca)) {
    $sql_dividas .= " AND (d.descricao LIKE :busca OR d.referencia LIKE :busca OR d.devedor_nome LIKE :busca)";
}

$sql_dividas .= " ORDER BY 
    CASE 
        WHEN d.status = 'vencido' THEN 0 
        WHEN d.status = 'pendente' AND d.data_vencimento < CURDATE() THEN 1
        WHEN d.status = 'pendente' THEN 2
        WHEN d.status = 'parcial' THEN 3
        ELSE 4 
    END,
    d.data_vencimento ASC, 
    d.created_at DESC";

$stmt_dividas = $conn->prepare($sql_dividas);
$params = [':funcionario_id' => $funcionario_id];
if ($status_filtro != 'todas') {
    $params[':status'] = $status_filtro;
}
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = '%' . $busca . '%';
}
$stmt_dividas->execute($params);
$dividas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_dividas = count($dividas);
$total_valor_a_receber = 0;
$total_valor_recebido = 0;
$total_vencidas = 0;
$total_parciais = 0;
$total_geradas_auto = 0;

foreach ($dividas as $divida) {
    $total_valor_a_receber += $divida['valor_restante'];
    $total_valor_recebido += $divida['valor_recebido'];
    
    if ($divida['status'] == 'parcial') $total_parciais++;
    if ($divida['gerado_automaticamente'] == 1) $total_geradas_auto++;
    
    if ($divida['data_vencimento'] < date('Y-m-d') && $divida['status'] != 'recebido') {
        $total_vencidas++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) { return number_format($valor, 2, ',', '.'); }
function formatarData($data) { return empty($data) ? '-' : date('d/m/Y', strtotime($data)); }

function getStatusBadge($status) {
    switch ($status) {
        case 'recebido': return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Recebido</span>';
        case 'pendente': return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'vencido': return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
        case 'parcial': return '<span class="badge bg-primary"><i class="fas fa-chart-line"></i> Parcial</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoBadge($tipo) {
    $tipos = [
        'emprestimo' => '<span class="badge bg-primary"><i class="fas fa-hand-holding-usd"></i> Empréstimo</span>',
        'beneficio' => '<span class="badge bg-success"><i class="fas fa-gift"></i> Benefício</span>',
        'taxa' => '<span class="badge bg-info"><i class="fas fa-percent"></i> Taxa</span>',
        'multa' => '<span class="badge bg-danger"><i class="fas fa-gavel"></i> Multa</span>',
        'mensalidade' => '<span class="badge bg-warning text-dark"><i class="fas fa-calendar-alt"></i> Mensalidade</span>',
        'ressarcimento' => '<span class="badge bg-secondary"><i class="fas fa-hand-holding-heart"></i> Ressarcimento</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge bg-secondary">' . $tipo . '</span>';
}

function getSituacaoVencimento($data_vencimento) {
    if (empty($data_vencimento)) return '';
    $hoje = date('Y-m-d');
    if ($data_vencimento < $hoje) {
        $dias = ceil((strtotime($hoje) - strtotime($data_vencimento)) / (60 * 60 * 24));
        return '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> Vencida há ' . $dias . ' dias</span>';
    } elseif ($data_vencimento == $hoje) {
        return '<span class="text-warning"><i class="fas fa-bell"></i> Vence hoje</span>';
    } else {
        $dias = ceil((strtotime($data_vencimento) - strtotime($hoje)) / (60 * 60 * 24));
        return '<span class="text-muted"><i class="fas fa-calendar-day"></i> Vence em ' . $dias . ' dias</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dívidas a Receber | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .filter-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 12px; color: #666; }
        .divida-card { background: white; border-radius: 12px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; overflow: hidden; }
        .divida-card:hover { transform: translateY(-2px); }
        .divida-card.vencido { border-left: 4px solid #dc3545; background: #fff5f5; }
        .divida-card.pendente { border-left: 4px solid #ffc107; }
        .divida-card.recebido { border-left: 4px solid #28a745; opacity: 0.7; }
        .divida-card.parcial { border-left: 4px solid #17a2b8; background: #e8f4f8; }
        .divida-header { background: #f8f9fa; padding: 12px 15px; border-bottom: 1px solid #eee; }
        .divida-body { padding: 15px; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .btn-receber { background: #28a745; color: white; border-radius: 20px; padding: 5px 15px; font-size: 12px; }
        .btn-receber:hover { background: #1e7e34; color: white; }
        .btn-detalhes { background: #17a2b8; color: white; border-radius: 20px; padding: 5px 15px; font-size: 12px; }
        .btn-detalhes:hover { background: #138496; color: white; }
        .btn-ajuda { background: #fd7e14; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-ajuda:hover { background: #e66a00; color: white; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .progress-recebimento { height: 6px; border-radius: 3px; }
        .valor-restante { font-size: 1.1em; font-weight: bold; }
        .badge-auto { background: #6f42c1; color: white; padding: 2px 6px; border-radius: 10px; font-size: 9px; }
        .help-step { display: flex; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .help-number { width: 40px; height: 40px; background: #006B3E; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; margin-right: 15px; }
        .help-content { flex: 1; }
        .help-content h6 { margin-bottom: 5px; color: #006B3E; }
        .help-content p { margin-bottom: 0; font-size: 13px; color: #666; }
        .alerta-auto { background: #e8f5e9; border-left: 4px solid #28a745; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</h2>
                    <p>Gerencie os valores que você tem a receber - Sistema Automático de Geração</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button type="button" class="btn-ajuda btn me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda"><i class="fas fa-question-circle"></i> Como Funciona</button>
                    <button onclick="window.print()" class="btn-voltar btn" style="background: #17a2b8;"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
        
        <!-- Alerta de Processamento Automático -->
        <div class="alerta-auto">
            <i class="fas fa-robot text-success"></i> 
            <strong>🤖 Sistema Automático de Gestão de Créditos</strong><br>
            As dívidas são geradas automaticamente ao cadastrar funcionários e ao iniciar um novo ano letivo. 
            Os status são atualizados em tempo real conforme os recebimentos são registrados.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3"><div class="stat-card"><div class="stat-number"><?php echo $total_dividas; ?></div><div class="stat-label">Total de Créditos</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-success">KZ <?php echo formatarMoeda($total_valor_a_receber); ?></div><div class="stat-label">Valor a Receber</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-info">KZ <?php echo formatarMoeda($total_valor_recebido); ?></div><div class="stat-label">Valor Recebido</div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-number text-warning"><?php echo $total_vencidas; ?></div><div class="stat-label">Créditos Vencidos</div></div></div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4"><div class="stat-card"><div class="stat-number text-primary"><?php echo $total_parciais; ?></div><div class="stat-label">Recebimentos Parciais</div></div></div>
            <div class="col-md-4"><div class="stat-card"><div class="stat-number text-secondary"><?php echo $total_geradas_auto; ?></div><div class="stat-label">Geradas Automaticamente</div></div></div>
            <div class="col-md-4"><div class="stat-card"><div class="stat-number"><?php echo $total_dividas - $total_vencidas - $total_parciais; ?></div><div class="stat-label">Em Dia</div></div></div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                    <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                    <option value="vencido" <?php echo $status_filtro == 'vencido' ? 'selected' : ''; ?>>Vencidas</option>
                    <option value="recebido" <?php echo $status_filtro == 'recebido' ? 'selected' : ''; ?>>Recebidas</option>
                    <option value="parcial" <?php echo $status_filtro == 'parcial' ? 'selected' : ''; ?>>Parciais</option>
                </select></div>
                <div class="col-md-3"><label class="form-label">Tipo</label><select name="tipo" class="form-select" onchange="this.form.submit()">
                    <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                    <option value="emprestimo" <?php echo $tipo_filtro == 'emprestimo' ? 'selected' : ''; ?>>Empréstimo</option>
                    <option value="beneficio" <?php echo $tipo_filtro == 'beneficio' ? 'selected' : ''; ?>>Benefício</option>
                    <option value="taxa" <?php echo $tipo_filtro == 'taxa' ? 'selected' : ''; ?>>Taxa</option>
                    <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                </select></div>
                <div class="col-md-4"><label class="form-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Descrição, referência ou devedor..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
            </form>
        </div>
        
        <!-- Lista de Dívidas a Receber -->
        <?php if (empty($dividas)): ?>
            <div class="alert alert-info text-center"><i class="fas fa-info-circle"></i> Nenhum crédito a receber encontrado.</div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($dividas as $divida): 
                    $status_divida = $divida['status'];
                    $valor_restante = $divida['valor_restante'];
                    $percentual_recebido = $divida['valor_original'] > 0 ? round(($divida['valor_recebido'] / $divida['valor_original']) * 100, 1) : 0;
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="divida-card <?php echo $status_divida; ?>">
                        <div class="divida-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php echo getTipoBadge($divida['tipo']); ?>
                                    <?php echo getStatusBadge($status_divida); ?>
                                    <?php if ($divida['gerado_automaticamente'] == 1): ?>
                                        <span class="badge-auto ms-1"><i class="fas fa-robot"></i> Automático</span>
                                    <?php endif; ?>
                                </div>
                                <div><small class="text-muted">Ref: <?php echo htmlspecialchars($divida['referencia'] ?? '-'); ?></small></div>
                            </div>
                        </div>
                        <div class="divida-body">
                            <h6 class="mb-2"><?php echo htmlspecialchars($divida['descricao']); ?></h6>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between"><small>Devedor:</small><strong><?php echo htmlspecialchars($divida['devedor_nome'] ?? '-'); ?></strong></div>
                                <div class="d-flex justify-content-between"><small>Valor Original:</small><strong>KZ <?php echo formatarMoeda($divida['valor_original']); ?></strong></div>
                                <div class="d-flex justify-content-between"><small>Valor Recebido:</small><strong class="text-success">KZ <?php echo formatarMoeda($divida['valor_recebido']); ?></strong></div>
                                <div class="d-flex justify-content-between"><small>Valor a Receber:</small><strong class="text-danger valor-restante">KZ <?php echo formatarMoeda($valor_restante); ?></strong></div>
                                <div class="progress-recebimento bg-light mt-2"><div class="progress-bar bg-success" style="width: <?php echo $percentual_recebido; ?>%"></div></div>
                            </div>
                            <div class="mb-2"><div class="d-flex justify-content-between"><small><i class="fas fa-calendar"></i> Vencimento:</small><span><?php echo formatarData($divida['data_vencimento']); ?></span></div></div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div><?php echo getSituacaoVencimento($divida['data_vencimento']); ?></div>
                                <div>
                                    <?php if ($status_divida != 'recebido'): ?>
                                        <button class="btn btn-receber btn-sm" onclick="abrirModalRecebimento(<?php echo $divida['id']; ?>, '<?php echo addslashes($divida['descricao']); ?>', <?php echo $valor_restante; ?>, '<?php echo addslashes($divida['devedor_nome']); ?>')"><i class="fas fa-money-bill-wave"></i> Receber</button>
                                    <?php endif; ?>
                                    <button class="btn btn-detalhes btn-sm" onclick="verDetalhes(<?php echo $divida['id']; ?>)"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Como Funciona o Sistema de Dívidas a Receber?</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="text-center mb-4"><i class="fas fa-hand-holding-heart fa-4x text-primary mb-3"></i><h4>Sistema Automático de Gestão de Créditos</h4><p class="text-muted">Entenda como funciona o processamento automático</p></div>
                    
                    <div class="help-step"><div class="help-number">1</div><div class="help-content"><h6><i class="fas fa-user-plus text-primary"></i> Cadastro de Funcionário</h6><p>Ao cadastrar um funcionário, o sistema automaticamente gera todas as dívidas/benefícios configurados para o cargo dele.</p></div></div>
                    <div class="help-step"><div class="help-number">2</div><div class="help-content"><h6><i class="fas fa-calendar-alt text-primary"></i> Novo Ano Letivo</h6><p>Quando um novo ano letivo é aberto, o sistema processa automaticamente a geração de todas as dívidas para os funcionários ativos.</p></div></div>
                    <div class="help-step"><div class="help-number">3</div><div class="help-content"><h6><i class="fas fa-calculator text-primary"></i> Configurações por Cargo</h6><p>Cada cargo pode ter configurações específicas de benefícios/dívidas (valor fixo ou percentual do salário).</p></div></div>
                    <div class="help-step"><div class="help-number">4</div><div class="help-content"><h6><i class="fas fa-chart-line text-primary"></i> Status Automático</h6><p>Os status são atualizados automaticamente: "Pendente" → "Vencido" após a data, "Parcial" após recebimento, "Recebido" quando completo.</p></div></div>
                    <div class="help-step"><div class="help-number">5</div><div class="help-content"><h6><i class="fas fa-hand-holding-heart text-primary"></i> Registro de Recebimento</h6><p>Ao registrar um recebimento, o sistema atualiza automaticamente o valor recebido e o status da dívida em tempo real.</p></div></div>
                    <div class="alert alert-info mt-3"><i class="fas fa-lightbulb"></i> <strong>Dica Importante:</strong><ul class="mb-0 mt-2"><li>✅ Tudo é processado automaticamente - sem necessidade de ações manuais repetitivas</li><li>✅ Os status são atualizados em tempo real</li><li>✅ O sistema mantém histórico completo de todos os recebimentos</li><li>⚠️ Recebimentos manuais substituem os automáticos para evitar duplicidade</li></ul></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="fas fa-check"></i> Entendi</button><button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Ajuda</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Recebimento -->
    <div class="modal fade" id="modalRecebimento" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header" style="background: #28a745; color: white;"><h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Registrar Recebimento</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="divida_id" id="divida_id">
                <div class="modal-body">
                    <div class="alert alert-info"><strong>Crédito:</strong> <span id="divida_descricao"></span><br><strong>Devedor:</strong> <span id="devedor_nome"></span></div>
                    <div class="alert alert-warning"><i class="fas fa-info-circle"></i> <strong>Atenção:</strong> O sistema atualizará automaticamente o status e o valor restante.</div>
                    <div class="mb-3"><label>Valor a Receber</label><input type="number" step="0.01" name="valor_recebido" id="valor_recebido" class="form-control" required><small class="text-muted">Valor restante: KZ <span id="valor_restante"></span></small></div>
                    <div class="mb-3"><label>Data do Recebimento</label><input type="date" name="data_recebimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="mb-3"><label>Forma de Recebimento</label><select name="forma_recebimento" class="form-select" required><option value="">Selecione...</option><option value="transferencia">Transferência Bancária</option><option value="deposito">Depósito</option><option value="dinheiro">Dinheiro</option><option value="cheque">Cheque</option><option value="compensacao">Compensação</option></select></div>
                    <div class="mb-3"><label>Observação</label><textarea name="observacao" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="registrar_recebimento" class="btn btn-success">Confirmar Recebimento</button></div>
            </form>
        </div></div>
    </div>
    
    <!-- Modal de Detalhes -->
    <div class="modal fade" id="modalDetalhes" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background: #006B3E; color: white;"><h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes do Crédito</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="detalhesBody">Carregando...</div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
    </div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function abrirModalRecebimento(id, descricao, valorRestante, devedor) {
            document.getElementById('divida_id').value = id;
            document.getElementById('divida_descricao').innerText = descricao;
            document.getElementById('devedor_nome').innerText = devedor;
            document.getElementById('valor_restante').innerText = valorRestante.toFixed(2).replace('.', ',');
            document.getElementById('valor_recebido').value = valorRestante;
            document.getElementById('valor_recebido').max = valorRestante;
            new bootstrap.Modal(document.getElementById('modalRecebimento')).show();
        }
        
        function verDetalhes(id) {
            document.getElementById('detalhesBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            new bootstrap.Modal(document.getElementById('modalDetalhes')).show();
            fetch(`ajax_divida_receber_detalhes.php?id=${id}`).then(r=>r.json()).then(d=>{if(d.success){
                let h=`<table class="table table-bordered"><tr><th>Descrição:</th><td>${d.descricao||'-'}</td></tr><tr><th>Referência:</th><td>${d.referencia||'-'}</td></tr><tr><th>Tipo:</th><td>${d.tipo||'-'}</td></tr><tr><th>Devedor:</th><td>${d.devedor_nome||'-'}</td></tr><tr><th>Valor Original:</th><td>KZ ${formatarMoeda(d.valor_original)}</td></tr><tr><th>Valor Recebido:</th><td>KZ ${formatarMoeda(d.valor_recebido)}</td></tr><tr><th>Valor Restante:</th><td>KZ ${formatarMoeda(d.valor_restante)}</td></tr><tr><th>Data Vencimento:</th><td>${formatarData(d.data_vencimento)}</td></tr><tr><th>Status:</th><td>${getStatusBadgeText(d.status)}</td></tr><tr><th>Criação:</th><td>${formatarData(d.created_at)}</td></tr></table>`;
                document.getElementById('detalhesBody').innerHTML = h;
            } else document.getElementById('detalhesBody').innerHTML = '<div class="alert alert-danger">Erro ao carregar</div>';}).catch(e=>{document.getElementById('detalhesBody').innerHTML='<div class="alert alert-danger">Erro de conexão</div>';});
        }
        function formatarMoeda(v){return parseFloat(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
        function formatarData(d){return d?new Date(d).toLocaleDateString('pt-BR'):'-';}
        function getStatusBadgeText(s){return {recebido:'<span class="badge bg-success">Recebido</span>',pendente:'<span class="badge bg-warning text-dark">Pendente</span>',vencido:'<span class="badge bg-danger">Vencido</span>',parcial:'<span class="badge bg-primary">Parcial</span>'}[s]||'<span class="badge bg-secondary">'+s+'</span>';}
    </script>
</body>
</html>