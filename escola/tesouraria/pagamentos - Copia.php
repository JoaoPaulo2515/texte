<?php
// escola/tesouraria/pagamentos.php - Gestão de Pagamentos

require_once 'includes/auth.php';
$escola = checkEscolaAuth();
$conn = getConnection();

$escola_id = $escola['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// VARIÁVEIS DE FILTRO E AÇÃO
// ============================================
$acao = $_GET['acao'] ?? $_POST['acao'] ?? 'listar';
$pagamento_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$status_filtro = $_GET['status'] ?? 'todos';
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : $ano_letivo_ano;

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Registrar novo pagamento
if ($acao == 'registrar' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $aluno_id = (int)$_POST['aluno_id'];
    $valor = (float)$_POST['valor'];
    $data_pagamento = $_POST['data_pagamento'];
    $tipo_pagamento = $_POST['tipo_pagamento'];
    $mes_referencia = (int)$_POST['mes_referencia'];
    $ano_referencia = (int)$_POST['ano_referencia'];
    $descricao = $_POST['descricao'] ?? '';
    $forma_pagamento = $_POST['forma_pagamento'];
    $comprovante = $_POST['comprovante'] ?? '';
    $observacoes = $_POST['observacoes'] ?? '';
    
    try {
        $sql = "INSERT INTO pagamentos (aluno_id, valor, data_pagamento, tipo_pagamento, 
                mes_referencia, ano_referencia, descricao, forma_pagamento, 
                comprovante, observacoes, status, escola_id, ano_letivo_id, data_registro)
                VALUES (:aluno_id, :valor, :data_pagamento, :tipo_pagamento, 
                :mes_referencia, :ano_referencia, :descricao, :forma_pagamento, 
                :comprovante, :observacoes, 'confirmado', :escola_id, :ano_letivo_id, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':aluno_id' => $aluno_id,
            ':valor' => $valor,
            ':data_pagamento' => $data_pagamento,
            ':tipo_pagamento' => $tipo_pagamento,
            ':mes_referencia' => $mes_referencia,
            ':ano_referencia' => $ano_referencia,
            ':descricao' => $descricao,
            ':forma_pagamento' => $forma_pagamento,
            ':comprovante' => $comprovante,
            ':observacoes' => $observacoes,
            ':escola_id' => $escola_id,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        $mensagem_sucesso = "Pagamento registrado com sucesso!";
        $acao = 'listar';
    } catch (PDOException $e) {
        $erro = "Erro ao registrar pagamento: " . $e->getMessage();
    }
}

// Confirmar pagamento pendente
if ($acao == 'confirmar' && $pagamento_id > 0) {
    try {
        $sql = "UPDATE pagamentos SET status = 'confirmado', data_confirmacao = NOW() 
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $pagamento_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Pagamento confirmado com sucesso!";
    } catch (PDOException $e) {
        $erro = "Erro ao confirmar pagamento: " . $e->getMessage();
    }
}

// Cancelar pagamento
if ($acao == 'cancelar' && $pagamento_id > 0) {
    try {
        $sql = "UPDATE pagamentos SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $pagamento_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Pagamento cancelado!";
    } catch (PDOException $e) {
        $erro = "Erro ao cancelar pagamento: " . $e->getMessage();
    }
}

// Excluir pagamento
if ($acao == 'excluir' && $pagamento_id > 0) {
    try {
        $sql = "DELETE FROM pagamentos WHERE id = :id AND escola_id = :escola_id AND status = 'pendente'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $pagamento_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Pagamento excluído!";
    } catch (PDOException $e) {
        $erro = "Erro ao excluir pagamento: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR DADOS PARA LISTAGEM
// ============================================

// Buscar alunos para o select
$sql_alunos = "SELECT e.id, e.nome, e.matricula, t.nome as turma_nome, t.ano as turma_ano
               FROM estudantes e
               LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa' AND m.ano_letivo_id = :ano_letivo_id
               LEFT JOIN turmas t ON t.id = m.turma_id
               WHERE e.escola_id = :escola_id AND e.status = 'ativo'
               ORDER BY e.nome";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id]);
$alunos_lista = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamentos com filtros
$sql_pagamentos = "SELECT p.*, e.nome as aluno_nome, e.matricula, t.nome as turma_nome, t.ano as turma_ano
                   FROM pagamentos p
                   INNER JOIN estudantes e ON e.id = p.aluno_id
                   LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa' AND m.ano_letivo_id = p.ano_letivo_id
                   LEFT JOIN turmas t ON t.id = m.turma_id
                   WHERE p.escola_id = :escola_id
                   AND p.ano_referencia = :ano_referencia";

$params = [':escola_id' => $escola_id, ':ano_referencia' => $ano_filtro];

if ($status_filtro != 'todos') {
    $sql_pagamentos .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if ($mes_filtro > 0) {
    $sql_pagamentos .= " AND p.mes_referencia = :mes_referencia";
    $params[':mes_referencia'] = $mes_filtro;
}

if ($turma_id > 0) {
    $sql_pagamentos .= " AND t.id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

if ($aluno_id > 0) {
    $sql_pagamentos .= " AND p.aluno_id = :aluno_id";
    $params[':aluno_id'] = $aluno_id;
}

$sql_pagamentos .= " ORDER BY p.data_pagamento DESC, p.id DESC";

$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute($params);
$pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total_recebido' => 0,
    'total_pendente' => 0,
    'total_mensalidades' => 0,
    'total_matriculas' => 0,
    'total_outros' => 0,
    'quantidade_pagos' => 0,
    'quantidade_pendentes' => 0,
    'media_pagamento' => 0
];

foreach ($pagamentos as $pag) {
    if ($pag['status'] == 'confirmado') {
        $estatisticas['total_recebido'] += $pag['valor'];
        $estatisticas['quantidade_pagos']++;
        
        if ($pag['tipo_pagamento'] == 'mensalidade') {
            $estatisticas['total_mensalidades'] += $pag['valor'];
        } elseif ($pag['tipo_pagamento'] == 'matricula') {
            $estatisticas['total_matriculas'] += $pag['valor'];
        } else {
            $estatisticas['total_outros'] += $pag['valor'];
        }
    } else {
        $estatisticas['total_pendente'] += $pag['valor'];
        $estatisticas['quantidade_pendentes']++;
    }
}

if ($estatisticas['quantidade_pagos'] > 0) {
    $estatisticas['media_pagamento'] = $estatisticas['total_recebido'] / $estatisticas['quantidade_pagos'];
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo, nif FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$tipos_pagamento = [
    'matricula' => 'Matrícula',
    'mensalidade' => 'Mensalidade',
    'taxa_escolar' => 'Taxa Escolar',
    'material' => 'Material Didático',
    'uniforme' => 'Uniforme',
    'extra' => 'Extra'
];

$formas_pagamento = [
    'dinheiro' => 'Dinheiro',
    'transferencia' => 'Transferência Bancária',
    'deposito' => 'Depósito',
    'multicaixa' => 'Multicaixa',
    'cheque' => 'Cheque'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Pagamentos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            gap: 12px;
            transition: all 0.3s;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 1001;
                background: #006B3E;
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 8px;
                cursor: pointer;
            }
        }
        
        .menu-toggle {
            display: none;
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-number.recebido { color: #28a745; }
        .stat-number.pendente { color: #dc3545; }
        .stat-number.media { color: #17a2b8; }
        
        .stat-label {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .btn-registrar {
            background: #006B3E;
            color: white;
            border-radius: 25px;
            padding: 10px 24px;
        }
        
        .btn-registrar:hover {
            background: #004d2d;
            color: white;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-confirmado { background: #d4edda; color: #155724; }
        .status-pendente { background: #fff3cd; color: #856404; }
        .status-cancelado { background: #f8d7da; color: #721c24; }
        
        .modal-lg-custom {
            max-width: 800px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-money-bill-wave"></i> Gestão de Pagamentos</h2>
            <div class="no-print">
                <button type="button" class="btn btn-registrar" data-bs-toggle="modal" data-bs-target="#modalRegistrarPagamento">
                    <i class="fas fa-plus-circle"></i> Novo Pagamento
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="confirmado" <?php echo $status_filtro == 'confirmado' ? 'selected' : ''; ?>>Confirmados</option>
                        <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                        <option value="cancelado" <?php echo $status_filtro == 'cancelado' ? 'selected' : ''; ?>>Cancelados</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Mês</label>
                    <select name="mes" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $mes_filtro == $i ? 'selected' : ''; ?>>
                            <?php echo $meses[$i]; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <?php for ($i = $ano_letivo_ano - 2; $i <= $ano_letivo_ano + 1; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano_filtro == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Aluno</label>
                    <select name="aluno_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($alunos_lista as $aluno): ?>
                        <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_id == $aluno['id'] ? 'selected' : ''; ?>>
                            <?php echo $aluno['matricula'] . ' - ' . $aluno['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-success mb-2"></i>
                    <div class="stat-number recebido"><?php echo number_format($estatisticas['total_recebido'], 2, ',', '.'); ?> KZ</div>
                    <div class="stat-label">Total Recebido</div>
                    <small class="text-muted"><?php echo $estatisticas['quantidade_pagos']; ?> pagamentos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                    <div class="stat-number pendente"><?php echo number_format($estatisticas['total_pendente'], 2, ',', '.'); ?> KZ</div>
                    <div class="stat-label">Total Pendente</div>
                    <small class="text-muted"><?php echo $estatisticas['quantidade_pendentes']; ?> pendentes</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-calculator fa-2x text-info mb-2"></i>
                    <div class="stat-number media"><?php echo number_format($estatisticas['media_pagamento'], 2, ',', '.'); ?> KZ</div>
                    <div class="stat-label">Ticket Médio</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-percent fa-2x text-primary mb-2"></i>
                    <div class="stat-number">
                        <?php 
                        $total_geral = $estatisticas['total_recebido'] + $estatisticas['total_pendente'];
                        $percentual = $total_geral > 0 ? round(($estatisticas['total_recebido'] / $total_geral) * 100, 1) : 0;
                        echo $percentual; ?>%
                    </div>
                    <div class="stat-label">Eficiência</div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Pagamentos -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-list"></i> Registro de Pagamentos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaPagamentos">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Aluno</th>
                                <th>Turma</th>
                                <th>Tipo</th>
                                <th>Mês Ref.</th>
                                <th>Valor</th>
                                <th>Forma</th>
                                <th>Status</th>
                                <th class="no-print">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagamentos as $pag): ?>
                            <tr>
                                <td><?php echo $pag['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($pag['data_pagamento'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($pag['aluno_nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo $pag['matricula']; ?></small>
                                </td>
                                <td><?php echo $pag['turma_ano'] . 'ª ' . htmlspecialchars($pag['turma_nome']); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo $tipos_pagamento[$pag['tipo_pagamento']] ?? $pag['tipo_pagamento']; ?>
                                    </span>
                                </td>
                                <td><?php echo $meses[$pag['mes_referencia']] . '/' . $pag['ano_referencia']; ?></td>
                                <td class="fw-bold text-success">
                                    <?php echo number_format($pag['valor'], 2, ',', '.'); ?> KZ
                                </td>
                                <td><?php echo $formas_pagamento[$pag['forma_pagamento']] ?? $pag['forma_pagamento']; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $pag['status']; ?>">
                                        <?php echo ucfirst($pag['status']); ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $pag['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($pag['status'] == 'pendente'): ?>
                                        <a href="?acao=confirmar&id=<?php echo $pag['id']; ?>&status=<?php echo $status_filtro; ?>&turma_id=<?php echo $turma_id; ?>&mes=<?php echo $mes_filtro; ?>&ano=<?php echo $ano_filtro; ?>&aluno_id=<?php echo $aluno_id; ?>" 
                                           class="btn btn-sm btn-success" onclick="return confirm('Confirmar este pagamento?')">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($pag['status'] != 'confirmado'): ?>
                                        <a href="?acao=cancelar&id=<?php echo $pag['id']; ?>&status=<?php echo $status_filtro; ?>&turma_id=<?php echo $turma_id; ?>&mes=<?php echo $mes_filtro; ?>&ano=<?php echo $ano_filtro; ?>&aluno_id=<?php echo $aluno_id; ?>" 
                                           class="btn btn-sm btn-warning" onclick="return confirm('Cancelar este pagamento?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($pag['status'] == 'pendente'): ?>
                                        <a href="?acao=excluir&id=<?php echo $pag['id']; ?>&status=<?php echo $status_filtro; ?>&turma_id=<?php echo $turma_id; ?>&mes=<?php echo $mes_filtro; ?>&ano=<?php echo $ano_filtro; ?>&aluno_id=<?php echo $aluno_id; ?>" 
                                           class="btn btn-sm btn-danger" onclick="return confirm('Excluir este pagamento?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="6" class="text-end fw-bold">TOTAL:</td>
                                <td colspan="3" class="fw-bold text-success">
                                    <?php echo number_format($estatisticas['total_recebido'], 2, ',', '.'); ?> KZ
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Pagamento -->
    <div class="modal fade no-print" id="modalRegistrarPagamento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Registrar Novo Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="registrar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Aluno *</label>
                                <select name="aluno_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($alunos_lista as $aluno): ?>
                                    <option value="<?php echo $aluno['id']; ?>">
                                        <?php echo $aluno['matricula'] . ' - ' . $aluno['nome']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Valor (KZ) *</label>
                                <input type="number" step="0.01" name="valor" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Data do Pagamento *</label>
                                <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Tipo de Pagamento *</label>
                                <select name="tipo_pagamento" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($tipos_pagamento as $key => $tipo): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $tipo; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Mês de Referência *</label>
                                <select name="mes_referencia" class="form-select" required>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $meses[$i]; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ano de Referência *</label>
                                <input type="number" name="ano_referencia" class="form-control" value="<?php echo $ano_letivo_ano; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Forma de Pagamento *</label>
                                <select name="forma_pagamento" class="form-select" required>
                                    <?php foreach ($formas_pagamento as $key => $forma): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $forma; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Comprovante/Nº Doc.</label>
                                <input type="text" name="comprovante" class="form-control" placeholder="Número do comprovante">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Descrição</label>
                                <input type="text" name="descricao" class="form-control" placeholder="Descrição do pagamento">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="2" placeholder="Observações adicionais"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar Pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalhes -->
    <div class="modal fade no-print" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes do Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesConteudo">
                    Carregando...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // DataTable
        $('#tabelaPagamentos').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[0, 'desc']],
            pageLength: 25
        });
        
        // Menu Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        // Ver detalhes do pagamento
        function verDetalhes(id) {
            // Buscar dados via AJAX ou mostrar modal com informações
            // Implementar conforme necessidade
            const modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
            document.getElementById('detalhesConteudo').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary"></div>
                    Carregando...
                </div>
            `;
            modal.show();
            
            // Simular carregamento (substituir por AJAX real)
            setTimeout(() => {
                document.getElementById('detalhesConteudo').innerHTML = `
                    <p><strong>ID:</strong> ${id}</p>
                    <hr>
                    <p>Implementar busca de detalhes do pagamento via AJAX</p>
                `;
            }, 500);
        }
    </script>
</body>
</html>