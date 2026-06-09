<?php
// escola/professor/dividas_pagar.php - Dívidas a Pagar (associado a funcionarios)

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR FUNCIONARIO_ID DO PROFESSOR LOGADO
// ============================================
$sql_funcionario = "
    SELECT f.id as funcionario_id, f.nome, f.cargo
    FROM funcionarios f
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
    WHERE p.id = :professor_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['funcionario_id'] ?? $professor_id;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DO FUNCIONARIO
// ============================================
$sql_funcionario_dados = "
    SELECT f.*, u.email, u.nome 
    FROM funcionarios f
    LEFT JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.id = :funcionario_id
";
$stmt_dados = $conn->prepare($sql_funcionario_dados);
$stmt_dados->execute([':funcionario_id' => $funcionario_id]);
$funcionario_dados = $stmt_dados->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR PAGAMENTO MANUAL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['realizar_pagamento'])) {
    $divida_id = (int)$_POST['divida_id'];
    $valor_pago = (float)$_POST['valor_pago'];
    $data_pagamento = $_POST['data_pagamento'];
    $forma_pagamento = $_POST['forma_pagamento'];
    $observacao = $_POST['observacao'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        // Buscar dívida atual
        $sql_divida = "SELECT * FROM dividas WHERE id = :id AND funcionario_id = :funcionario_id";
        $stmt_divida = $conn->prepare($sql_divida);
        $stmt_divida->execute([':id' => $divida_id, ':funcionario_id' => $funcionario_id]);
        $divida = $stmt_divida->fetch(PDO::FETCH_ASSOC);
        
        if ($divida) {
            $valor_pago_anterior = $divida['valor_pago'] ?? 0;
            $valor_original = $divida['valor_original'];
            $novo_valor_pago = $valor_pago_anterior + $valor_pago;
            $valor_restante = $valor_original - $novo_valor_pago;
            $status = $valor_restante <= 0 ? 'pago' : 'pendente';
            
            // Atualizar dívida
            $sql_update = "
                UPDATE dividas SET 
                    valor_pago = :valor_pago,
                    status = :status,
                    forma_pagamento = :forma_pagamento,
                    data_pagamento = :data_pagamento,
                    observacao_pagamento = :observacao,
                    updated_at = NOW()
                WHERE id = :id
            ";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':valor_pago' => $novo_valor_pago,
                ':status' => $status,
                ':forma_pagamento' => $forma_pagamento,
                ':data_pagamento' => $data_pagamento,
                ':observacao' => $observacao,
                ':id' => $divida_id
            ]);
            
            // Registrar pagamento no histórico
            $sql_hist = "
                INSERT INTO pagamentos_historico (
                    divida_id, valor_pago, data_pagamento, forma_pagamento, observacao, created_at
                ) VALUES (
                    :divida_id, :valor_pago, :data_pagamento, :forma_pagamento, :observacao, NOW()
                )
            ";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':divida_id' => $divida_id,
                ':valor_pago' => $valor_pago,
                ':data_pagamento' => $data_pagamento,
                ':forma_pagamento' => $forma_pagamento,
                ':observacao' => $observacao
            ]);
            
            $success = "Pagamento realizado com sucesso!";
        }
        
        $conn->commit();
        header("Location: dividas_pagar.php?success=1");
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao processar pagamento: " . $e->getMessage();
    }
}

// ============================================
// PROCESSAR DESCONTO AUTOMÁTICO EM FOLHA
// ============================================
if (isset($_GET['processar_desconto_folha'])) {
    try {
        // Buscar dívidas vencidas com desconto em folha ativado
        $sql_dividas = "
            SELECT * FROM dividas 
            WHERE funcionario_id = :funcionario_id 
            AND status = 'pendente'
            AND (desconto_folha = 1)
            AND (processado_folha = 0 OR processado_folha IS NULL)
            AND data_vencimento < CURDATE()
            AND (valor_original - COALESCE(valor_pago, 0)) > 0
        ";
        $stmt_dividas = $conn->prepare($sql_dividas);
        $stmt_dividas->execute([':funcionario_id' => $funcionario_id]);
        $dividas_vencidas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);
        
        $total_processado = 0;
        $total_valor = 0;
        $mes_atual = (int)date('m');
        $ano_atual = (int)date('Y');
        
        if (!empty($dividas_vencidas)) {
            // Buscar ou criar processamento da folha para o mês atual
            $sql_processamento = "
                SELECT id FROM folha_processamento_funcionarios 
                WHERE funcionario_id = :funcionario_id 
                AND mes_competencia = :mes 
                AND ano_competencia = :ano
                LIMIT 1
            ";
            $stmt_proc = $conn->prepare($sql_processamento);
            $stmt_proc->execute([
                ':funcionario_id' => $funcionario_id,
                ':mes' => $mes_atual,
                ':ano' => $ano_atual
            ]);
            $processamento = $stmt_proc->fetch(PDO::FETCH_ASSOC);
            
            foreach ($dividas_vencidas as $divida) {
                $valor_restante = $divida['valor_original'] - ($divida['valor_pago'] ?? 0);
                
                if ($processamento) {
                    $processamento_id = $processamento['id'];
                    
                    // Atualizar o processamento com o desconto
                    $sql_update = "
                        UPDATE folha_processamento_funcionarios 
                        SET 
                            desconto_emprestimo = COALESCE(desconto_emprestimo, 0) + :valor,
                            total_descontos = COALESCE(total_descontos, 0) + :valor,
                            salario_liquido = COALESCE(salario_liquido, 0) - :valor,
                            updated_at = NOW()
                        WHERE id = :processamento_id
                    ";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([
                        ':valor' => $valor_restante,
                        ':processamento_id' => $processamento_id
                    ]);
                } else {
                    // Criar novo processamento
                    $sql_insert = "
                        INSERT INTO folha_processamento_funcionarios (
                            funcionario_id, mes_competencia, ano_competencia, 
                            desconto_emprestimo, total_descontos, salario_liquido,
                            status, created_at
                        ) VALUES (
                            :funcionario_id, :mes, :ano,
                            :valor, :valor, -:valor,
                            'processado', NOW()
                        )
                    ";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':funcionario_id' => $funcionario_id,
                        ':mes' => $mes_atual,
                        ':ano' => $ano_atual,
                        ':valor' => $valor_restante
                    ]);
                    $processamento_id = $conn->lastInsertId();
                }
                
                // Atualizar a dívida como processada
                $sql_update_divida = "
                    UPDATE dividas SET 
                        processado_folha = 1,
                        mes_processamento = :mes,
                        ano_processamento = :ano,
                        processamento_id = :processamento_id,
                        status = 'processado_folha'
                    WHERE id = :divida_id
                ";
                $stmt_update_divida = $conn->prepare($sql_update_divida);
                $stmt_update_divida->execute([
                    ':mes' => $mes_atual,
                    ':ano' => $ano_atual,
                    ':processamento_id' => $processamento_id,
                    ':divida_id' => $divida['id']
                ]);
                
                $total_processado++;
                $total_valor += $valor_restante;
            }
        }
        
        if ($total_processado > 0) {
            $success = "Processado $total_processado dívida(s) no valor total de KZ " . number_format($total_valor, 2, ',', '.') . " para desconto em folha!";
        } else {
            $info = "Nenhuma dívida vencida com desconto em folha ativado encontrada.";
        }
        
    } catch (PDOException $e) {
        $error = "Erro ao processar descontos: " . $e->getMessage();
    }
}

// ============================================
// ATUALIZAR CONFIGURAÇÃO DE DESCONTO EM FOLHA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configurar_desconto_folha'])) {
    $divida_id = (int)$_POST['divida_id'];
    $desconto_folha = isset($_POST['desconto_folha']) ? 1 : 0;
    
    $sql = "UPDATE dividas SET desconto_folha = :desconto_folha WHERE id = :id AND funcionario_id = :funcionario_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':desconto_folha' => $desconto_folha,
        ':id' => $divida_id,
        ':funcionario_id' => $funcionario_id
    ]);
    
    header("Location: dividas_pagar.php?config_success=1");
    exit;
}

// ============================================
// BUSCAR DÍVIDAS A PAGAR DO FUNCIONÁRIO
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

$sql_dividas = "
    SELECT 
        d.*,
        COALESCE(d.valor_original, 0) as valor_original,
        COALESCE(d.valor_pago, 0) as valor_pago,
        (d.valor_original - COALESCE(d.valor_pago, 0)) as valor_restante,
        COALESCE(d.juros, 0) as juros,
        COALESCE(d.multas, 0) as multas,
        COALESCE(d.desconto, 0) as desconto,
        COALESCE(d.desconto_folha, 0) as desconto_folha,
        COALESCE(d.processado_folha, 0) as processado_folha
    FROM dividas d
    WHERE d.funcionario_id = :funcionario_id
";

if ($status_filtro != 'todas') {
    $sql_dividas .= " AND d.status = :status";
}
if ($tipo_filtro != 'todos') {
    $sql_dividas .= " AND d.tipo = :tipo";
}
if (!empty($busca)) {
    $sql_dividas .= " AND (d.descricao LIKE :busca OR d.referencia LIKE :busca)";
}

$sql_dividas .= " ORDER BY 
    CASE WHEN d.status = 'vencido' THEN 0 
         WHEN d.status = 'pendente' THEN 1 
         ELSE 2 END,
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
$total_valor_pendente = 0;
$total_valor_pago = 0;
$total_vencidas = 0;
$total_processadas_folha = 0;

foreach ($dividas as $divida) {
    $valor_restante = $divida['valor_restante'];
    $total_valor_pendente += $valor_restante;
    $total_valor_pago += $divida['valor_pago'];
    
    if ($divida['processado_folha'] == 1) {
        $total_processadas_folha++;
    }
    
    $data_vencimento = $divida['data_vencimento'];
    if ($data_vencimento && $data_vencimento < date('Y-m-d') && $divida['status'] != 'pago' && $divida['processado_folha'] != 1) {
        $total_vencidas++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusBadge($status, $processado_folha = 0) {
    if ($processado_folha == 1) {
        return '<span class="badge bg-info"><i class="fas fa-calculator"></i> Desconto em Folha</span>';
    }
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'vencido':
            return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
        case 'negociando':
            return '<span class="badge bg-info"><i class="fas fa-handshake"></i> Negociando</span>';
        case 'processado_folha':
            return '<span class="badge bg-primary"><i class="fas fa-calculator"></i> Processado em Folha</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

function getTipoBadge($tipo) {
    switch ($tipo) {
        case 'emprestimo':
            return '<span class="badge bg-primary"><i class="fas fa-hand-holding-usd"></i> Empréstimo</span>';
        case 'taxa':
            return '<span class="badge bg-info"><i class="fas fa-percent"></i> Taxa</span>';
        case 'multa':
            return '<span class="badge bg-danger"><i class="fas fa-gavel"></i> Multa</span>';
        case 'mensalidade':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-calendar-alt"></i> Mensalidade</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($tipo) . '</span>';
    }
}

function getSituacaoVencimento($data_vencimento, $processado_folha = 0) {
    if ($processado_folha == 1) {
        return '<span class="text-info"><i class="fas fa-calculator"></i> Será descontado na folha</span>';
    }
    if (empty($data_vencimento)) return '';
    $hoje = date('Y-m-d');
    if ($data_vencimento < $hoje) {
        return '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> Vencida</span>';
    } elseif ($data_vencimento == $hoje) {
        return '<span class="text-warning"><i class="fas fa-bell"></i> Vence hoje</span>';
    } else {
        $dias = ceil((strtotime($data_vencimento) - strtotime($hoje)) / (60 * 60 * 24));
        return '<span class="text-muted">Vence em ' . $dias . ' dias</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dívidas a Pagar | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        .divida-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
            overflow: hidden;
        }
        .divida-card:hover {
            transform: translateY(-2px);
        }
        .divida-card.vencido {
            border-left: 4px solid #dc3545;
        }
        .divida-card.pendente {
            border-left: 4px solid #ffc107;
        }
        .divida-card.pago {
            border-left: 4px solid #28a745;
            opacity: 0.7;
        }
        .divida-card.processado_folha {
            border-left: 4px solid #17a2b8;
        }
        .divida-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        .divida-body {
            padding: 15px;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .btn-pagar {
            background: #28a745;
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
        }
        .btn-pagar:hover {
            background: #1e7e34;
            color: white;
        }
        .btn-detalhes {
            background: #17a2b8;
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
        }
        .btn-detalhes:hover {
            background: #138496;
            color: white;
        }
        .btn-procesar-folha {
            background: #6f42c1;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            border: none;
        }
        .btn-procesar-folha:hover {
            background: #5a32a3;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .progress-pagamento {
            height: 6px;
            border-radius: 3px;
        }
        .valor-restante {
            font-size: 1.1em;
            font-weight: bold;
        }
        .badge-desconto-folha {
            background: #6f42c1;
            color: white;
            padding: 2px 8px;
            border-radius: 15px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</h2>
                    <p>Gerencie suas obrigações financeiras - Com descontos automáticos em folha</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                    <a href="?processar_desconto_folha=1" class="btn-procesar-folha btn me-2" onclick="return confirm('Deseja processar os descontos em folha para as dívidas vencidas?')">
                        <i class="fas fa-calculator"></i> Processar Descontos em Folha
                    </a>
                    <button onclick="window.print()" class="btn-voltar btn" style="background: #17a2b8;">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($info)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle"></i> <?php echo $info; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_dividas; ?></div>
                    <div class="stat-label">Total de Dívidas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-danger">KZ <?php echo formatarMoeda($total_valor_pendente); ?></div>
                    <div class="stat-label">Valor Pendente</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success">KZ <?php echo formatarMoeda($total_valor_pago); ?></div>
                    <div class="stat-label">Valor Pago</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $total_vencidas; ?></div>
                    <div class="stat-label">Dívidas Vencidas</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                        <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="vencido" <?php echo $status_filtro == 'vencido' ? 'selected' : ''; ?>>Vencidas</option>
                        <option value="pago" <?php echo $status_filtro == 'pago' ? 'selected' : ''; ?>>Pagas</option>
                        <option value="negociando" <?php echo $status_filtro == 'negociando' ? 'selected' : ''; ?>>Negociando</option>
                        <option value="processado_folha" <?php echo $status_filtro == 'processado_folha' ? 'selected' : ''; ?>>Processado em Folha</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="emprestimo" <?php echo $tipo_filtro == 'emprestimo' ? 'selected' : ''; ?>>Empréstimo</option>
                        <option value="taxa" <?php echo $tipo_filtro == 'taxa' ? 'selected' : ''; ?>>Taxa</option>
                        <option value="multa" <?php echo $tipo_filtro == 'multa' ? 'selected' : ''; ?>>Multa</option>
                        <option value="mensalidade" <?php echo $tipo_filtro == 'mensalidade' ? 'selected' : ''; ?>>Mensalidade</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Descrição ou referência..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Dívidas -->
        <?php if (empty($dividas)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhuma dívida encontrada.
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($dividas as $divida): 
                    $status_divida = $divida['status'] ?? 'pendente';
                    $data_vencimento = $divida['data_vencimento'];
                    $hoje = date('Y-m-d');
                    
                    if ($status_divida != 'pago' && $divida['processado_folha'] != 1 && $data_vencimento && $data_vencimento < $hoje) {
                        $status_divida = 'vencido';
                    }
                    
                    $valor_restante = $divida['valor_restante'];
                    $percentual_pago = $divida['valor_original'] > 0 ? round(($divida['valor_pago'] / $divida['valor_original']) * 100, 1) : 0;
                    $desconto_folha = $divida['desconto_folha'] ?? 0;
                    $processado_folha = $divida['processado_folha'] ?? 0;
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="divida-card <?php echo $processado_folha == 1 ? 'processado_folha' : $status_divida; ?>">
                        <div class="divida-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php echo getTipoBadge($divida['tipo'] ?? 'outro'); ?>
                                    <?php echo getStatusBadge($status_divida, $processado_folha); ?>
                                    <?php if ($desconto_folha == 1 && $processado_folha == 0): ?>
                                        <span class="badge-desconto-folha ms-1"><i class="fas fa-calculator"></i> Desconto em Folha</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <small class="text-muted">Ref: <?php echo htmlspecialchars($divida['referencia'] ?? '-'); ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="divida-body">
                            <h6 class="mb-2"><?php echo htmlspecialchars($divida['descricao'] ?? 'Dívida'); ?></h6>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between">
                                    <small>Valor Original:</small>
                                    <strong>KZ <?php echo formatarMoeda($divida['valor_original']); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small>Valor Pago:</small>
                                    <strong class="text-success">KZ <?php echo formatarMoeda($divida['valor_pago']); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small>Valor Restante:</small>
                                    <strong class="text-danger valor-restante">KZ <?php echo formatarMoeda($valor_restante); ?></strong>
                                </div>
                                <div class="progress-pagamento bg-light mt-2">
                                    <div class="progress-bar bg-success" style="width: <?php echo $percentual_pago; ?>%"></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between">
                                    <small><i class="fas fa-calendar"></i> Vencimento:</small>
                                    <span><?php echo formatarData($data_vencimento); ?></span>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php echo getSituacaoVencimento($data_vencimento, $processado_folha); ?>
                                </div>
                                <div>
                                    <?php if ($processado_folha == 0 && $status_divida != 'pago'): ?>
                                        <button class="btn btn-pagar btn-sm" onclick="abrirModalPagamento(<?php echo $divida['id']; ?>, '<?php echo addslashes($divida['descricao']); ?>', <?php echo $valor_restante; ?>)">
                                            <i class="fas fa-money-bill-wave"></i> Pagar
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-detalhes btn-sm" onclick="verDetalhes(<?php echo $divida['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($processado_folha == 0 && $status_divida != 'pago'): ?>
                                    <button class="btn btn-sm btn-secondary" onclick="abrirModalConfigurarFolha(<?php echo $divida['id']; ?>, <?php echo $desconto_folha; ?>)">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Pagamento -->
    <div class="modal fade" id="modalPagamento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave"></i> Realizar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formPagamento" method="POST">
                    <input type="hidden" name="divida_id" id="divida_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Dívida:</strong> <span id="divida_descricao"></span>
                        </div>
                        <div class="mb-3">
                            <label>Valor a Pagar</label>
                            <input type="number" step="0.01" name="valor_pago" id="valor_pago" class="form-control" required>
                            <small class="text-muted">Valor restante: KZ <span id="valor_restante"></span></small>
                        </div>
                        <div class="mb-3">
                            <label>Data do Pagamento</label>
                            <input type="date" name="data_pagamento" id="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="transferencia">Transferência Bancária</option>
                                <option value="deposito">Depósito</option>
                                <option value="dinheiro">Dinheiro</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Observação</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Observação sobre o pagamento"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="realizar_pagamento" class="btn btn-success">Confirmar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Configurar Desconto em Folha -->
    <div class="modal fade" id="modalConfigurarFolha" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-calculator"></i> Desconto em Folha</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="divida_id" id="config_divida_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Desconto Automático em Folha</strong><br>
                            Ative esta opção para que o valor desta dívida seja descontado automaticamente da sua folha de pagamento no mês seguinte ao vencimento.
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="desconto_folha" name="desconto_folha" style="width: 50px; height: 25px;">
                                <label class="form-check-label ms-3" for="desconto_folha">
                                    <strong>Descontar automaticamente em folha</strong>
                                </label>
                            </div>
                            <small class="text-muted">Ao ativar, o valor será descontado na folha de pagamento do mês seguinte ao vencimento.</small>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção:</strong> Após o processamento, o desconto será aplicado automaticamente e você não precisará se preocupar com o pagamento manual.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="configurar_desconto_folha" class="btn btn-primary">Salvar Configuração</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Detalhes -->
    <div class="modal fade" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Dívida</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesBody">
                    Carregando...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function abrirModalPagamento(id, descricao, valorRestante) {
            document.getElementById('divida_id').value = id;
            document.getElementById('divida_descricao').innerText = descricao;
            document.getElementById('valor_restante').innerText = valorRestante.toFixed(2).replace('.', ',');
            document.getElementById('valor_pago').value = valorRestante;
            document.getElementById('valor_pago').max = valorRestante;
            new bootstrap.Modal(document.getElementById('modalPagamento')).show();
        }
        
        function abrirModalConfigurarFolha(id, descontoAtivo) {
            document.getElementById('config_divida_id').value = id;
            document.getElementById('desconto_folha').checked = (descontoAtivo == 1);
            new bootstrap.Modal(document.getElementById('modalConfigurarFolha')).show();
        }
        
        function verDetalhes(id) {
            document.getElementById('detalhesBody').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            new bootstrap.Modal(document.getElementById('modalDetalhes')).show();
            
            fetch(`ajax_divida_detalhes.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <table class="table table-bordered">
                                <tr><th>Descrição:</th><td>${data.descricao || '-'}</td></tr>
                                <tr><th>Referência:</th><td>${data.referencia || '-'}</td></tr>
                                <tr><th>Tipo:</th><td>${data.tipo || '-'}</td></tr>
                                <tr><th>Valor Original:</th><td>KZ ${formatarMoeda(data.valor_original)}</td></tr>
                                <tr><th>Valor Pago:</th><td>KZ ${formatarMoeda(data.valor_pago)}</td></tr>
                                <tr><th>Valor Restante:</th><td>KZ ${formatarMoeda(data.valor_restante)}</td></tr>
                                <tr><th>Juros:</th><td>KZ ${formatarMoeda(data.juros)}</td></tr>
                                <tr><th>Multas:</th><td>KZ ${formatarMoeda(data.multas)}</td></tr>
                                <tr><th>Desconto:</th><td>KZ ${formatarMoeda(data.desconto)}</td></tr>
                                <tr><th>Data de Vencimento:</th><td>${formatarData(data.data_vencimento)}</td></tr>
                                <tr><th>Status:</th><td>${getStatusBadgeText(data.status)}</td></tr>
                                <tr><th>Desconto em Folha:</th><td>${data.desconto_folha == 1 ? '<span class="badge bg-primary">Ativado</span>' : '<span class="badge bg-secondary">Desativado</span>'}您</td></tr>
                                <tr><th>Data de Criação:</th><td>${formatarData(data.created_at)}</td></tr>
                            </table>
                        `;
                        document.getElementById('detalhesBody').innerHTML = html;
                    } else {
                        document.getElementById('detalhesBody').innerHTML = '<div class="alert alert-danger">Erro ao carregar detalhes.</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('detalhesBody').innerHTML = '<div class="alert alert-danger">Erro de conexão.</div>';
                });
        }
        
        function formatarMoeda(valor) {
            return parseFloat(valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        function formatarData(data) {
            if (!data) return '-';
            return new Date(data).toLocaleDateString('pt-BR');
        }
        
        function getStatusBadgeText(status) {
            switch(status) {
                case 'pago': return '<span class="badge bg-success">Pago</span>';
                case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
                case 'vencido': return '<span class="badge bg-danger">Vencido</span>';
                case 'negociando': return '<span class="badge bg-info">Negociando</span>';
                case 'processado_folha': return '<span class="badge bg-primary">Processado em Folha</span>';
                default: return '<span class="badge bg-secondary">' + status + '</span>';
            }
        }
    </script>
</body>
</html>