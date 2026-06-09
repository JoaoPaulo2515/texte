<?php
// escola/tesouraria/mensalidades.php - Gestão de Mensalidades (Método Simplificado)

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano, ativo FROM ano_letivo WHERE escola_id = $escola_id ORDER BY ano DESC";
$anos_letivos = $conn->query($sql_anos)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CONFIGS
// ============================================
$valor_mensalidade_padrao = 50000;
$ano_atual = date('Y');
$mes_atual = date('m');

// Ano letivo ativo
$ano_letivo_ativo_valor = null;
foreach ($anos_letivos as $al) {
    if ($al['ativo'] == 1) {
        $ano_letivo_ativo_valor = $al['ano'];
        break;
    }
}
if (!$ano_letivo_ativo_valor && !empty($anos_letivos)) {
    $ano_letivo_ativo_valor = $anos_letivos[0]['ano'];
}

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : $ano_letivo_ativo_valor;
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ============================================
// PROCESSAR LANÇAMENTO DE MENSALIDADES
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lancar_mensalidades'])) {
    $turma_id = (int)$_POST['turma_id'];
    $mes_referencia = (int)$_POST['mes_referencia'];
    $ano_referencia = (int)$_POST['ano_referencia'];
    $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? $valor_mensalidade_padrao)));
    $data_vencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime("$ano_referencia-$mes_referencia-10"));
    
    if ($turma_id <= 0) {
        $error = "Selecione uma turma.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Buscar alunos da turma
            $sql_alunos = "SELECT e.id, e.nome, e.matricula 
                           FROM estudantes e
                           JOIN matriculas m ON m.estudante_id = e.id
                           WHERE m.turma_id = $turma_id AND m.status = 'ativa'";
            $alunos = $conn->query($sql_alunos)->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($alunos)) {
                $error = "Nenhum aluno encontrado nesta turma.";
            } else {
                $contador = 0;
                foreach ($alunos as $aluno) {
                    // Verificar se já existe mensalidade
                    $sql_check = "SELECT id FROM mensalidades 
                                  WHERE escola_id = $escola_id 
                                  AND aluno_id = {$aluno['id']}
                                  AND mes_referencia = $mes_referencia 
                                  AND ano_referencia = $ano_referencia";
                    $existe = $conn->query($sql_check)->fetch();
                    
                    if (!$existe) {
                        $sql_insert = "INSERT INTO mensalidades (
                                            escola_id, aluno_id, mes_referencia, ano_referencia, 
                                            valor_total, valor_pago, status, data_vencimento, created_at
                                        ) VALUES (
                                            $escola_id, {$aluno['id']}, $mes_referencia, $ano_referencia, 
                                            $valor, 0, 'pendente', '$data_vencimento', NOW()
                                        )";
                        $conn->exec($sql_insert);
                        $contador++;
                    }
                }
                $conn->commit();
                
                if ($contador == 0) {
                    $error = "Nenhuma mensalidade nova foi lançada.";
                } else {
                    $success = "$contador mensalidade(s) lançada(s) com sucesso!";
                }
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao lançar mensalidades: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR DESCONTO EM LOTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_desconto'])) {
    $turma_id = (int)$_POST['turma_id_desconto'];
    $mes_referencia = (int)$_POST['mes_referencia_desconto'];
    $ano_referencia = (int)$_POST['ano_referencia_desconto'];
    $percentual_desconto = floatval($_POST['percentual_desconto'] ?? 0);
    
    if ($turma_id <= 0) {
        $error = "Selecione uma turma.";
    } elseif ($percentual_desconto <= 0 || $percentual_desconto > 100) {
        $error = "Percentual de desconto inválido (1-100%).";
    } else {
        try {
            $sql = "UPDATE mensalidades m
                    JOIN matriculas mat ON mat.estudante_id = m.aluno_id
                    SET m.desconto = (m.valor_total * $percentual_desconto / 100),
                        m.valor_total = m.valor_total - (m.valor_total * $percentual_desconto / 100)
                    WHERE mat.turma_id = $turma_id 
                    AND m.mes_referencia = $mes_referencia 
                    AND m.ano_referencia = $ano_referencia
                    AND m.escola_id = $escola_id
                    AND m.status = 'pendente'";
            $conn->exec($sql);
            $success = "Desconto de $percentual_desconto% aplicado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao aplicar desconto: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = $escola_id AND status = 'ativa' ORDER BY ano, nome";
$turmas = $conn->query($sql_turmas)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// MÉTODO SIMPLIFICADO PARA BUSCAR MENSALIDADES
// ============================================
// Construir a query WHERE de forma segura
$where = "m.escola_id = $escola_id";

if ($status_filtro != 'todos') {
    $where .= " AND m.status = '$status_filtro'";
}

if ($turma_filtro > 0) {
    $where .= " AND mat.turma_id = $turma_filtro";
}

if ($ano_filtro > 0) {
    $where .= " AND m.ano_referencia = $ano_filtro";
}

if ($mes_filtro > 0) {
    $where .= " AND m.mes_referencia = $mes_filtro";
}

if (!empty($busca)) {
    $where .= " AND (e.nome LIKE '%$busca%' OR e.matricula LIKE '%$busca%')";
}

// Query principal com LIMIT e OFFSET diretos
$sql_mensalidades = "
    SELECT m.*, e.nome as aluno_nome, e.matricula, t.nome as turma_nome, t.ano as turma_ano
    FROM mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    WHERE $where
    GROUP BY m.id
    ORDER BY e.nome ASC, m.ano_referencia DESC, m.mes_referencia ASC
    LIMIT $limit OFFSET $offset
";

$mensalidades = $conn->query($sql_mensalidades)->fetchAll(PDO::FETCH_ASSOC);

// Query para total (sem LIMIT)
$sql_total = "
    SELECT COUNT(DISTINCT m.id) as total 
    FROM mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    WHERE $where
";

$total_mensalidades = $conn->query($sql_total)->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_mensalidades / $limit);

// ============================================
// ESTATÍSTICAS
// ============================================
$stats = [];

$row = $conn->query("SELECT COUNT(*) as total, SUM(valor_total) as valor_total FROM mensalidades WHERE escola_id = $escola_id")->fetch(PDO::FETCH_ASSOC);
$stats['total'] = $row['total'] ?? 0;
$stats['valor_total'] = $row['valor_total'] ?? 0;

$row = $conn->query("SELECT COUNT(*) as total, SUM(valor_total - valor_pago) as valor_pendente FROM mensalidades WHERE escola_id = $escola_id AND status IN ('pendente', 'parcial')")->fetch(PDO::FETCH_ASSOC);
$stats['pendentes'] = $row['total'] ?? 0;
$stats['valor_pendente'] = $row['valor_pendente'] ?? 0;

$row = $conn->query("SELECT COUNT(*) as total, SUM(valor_pago) as valor_pago FROM mensalidades WHERE escola_id = $escola_id AND status = 'pago'")->fetch(PDO::FETCH_ASSOC);
$stats['pagas'] = $row['total'] ?? 0;
$stats['valor_pago'] = $row['valor_pago'] ?? 0;

$stats['atrasadas'] = $conn->query("SELECT COUNT(*) as total FROM mensalidades WHERE escola_id = $escola_id AND status = 'atrasado'")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusMensalidadeBadge($status) {
    switch ($status) {
        case 'pago': return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'parcial': return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Parcial</span>';
        case 'pendente': return '<span class="badge bg-secondary"><i class="fas fa-hourglass-half"></i> Pendente</span>';
        case 'atrasado': return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMesNome($mes) {
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensalidades | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content-tesouraria { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content-tesouraria { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.3em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.7rem; color: #6c757d; }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .mensalidade-row.atrasado { background-color: #fff3cd; }
        .mensalidade-row.pendente { background-color: #f8f9fa; }
        .mensalidade-row.pago { background-color: #d4edda; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .table-responsive { overflow-x: auto; }
        .table { min-width: 800px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content-tesouraria">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-calendar-dollar"></i> Gestão de Mensalidades</h2>
                <p class="text-muted">Controle de mensalidades dos alunos</p>
            </div>
            <div class="mt-2 mt-md-0">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalLancarMensalidades"><i class="fas fa-plus"></i> Lançar Mensalidades</button>
                <button class="btn btn-info ms-2" data-bs-toggle="modal" data-bs-target="#modalDescontoLote"><i class="fas fa-percent"></i> Desconto em Lote</button>
                <a href="index.php" class="btn-voltar ms-2"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo formatarMoeda($stats['valor_total']); ?></div><div class="stat-label">Valor Total</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['pagas']; ?></div><div class="stat-label">Pagas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo formatarMoeda($stats['valor_pago']); ?></div><div class="stat-label">Valor Pago</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['pendentes']; ?></div><div class="stat-label">Pendentes</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo formatarMoeda($stats['valor_pendente']); ?></div><div class="stat-label">Valor Pendente</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['atrasadas']; ?></div><div class="stat-label">Atrasadas</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2"><label class="filter-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option>
                            <option value="pago" <?php echo $status_filtro=='pago'?'selected':''; ?>>Pago</option>
                            <option value="pendente" <?php echo $status_filtro=='pendente'?'selected':''; ?>>Pendente</option>
                            <option value="parcial" <?php echo $status_filtro=='parcial'?'selected':''; ?>>Parcial</option>
                            <option value="atrasado" <?php echo $status_filtro=='atrasado'?'selected':''; ?>>Atrasado</option>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="filter-label">Turma</label>
                        <select name="turma_id" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_filtro==$t['id']?'selected':''; ?>><?php echo $t['ano'].'ª - '.htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="filter-label">Ano Letivo</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos</option>
                            <?php foreach($anos_letivos as $al): ?>
                            <option value="<?php echo $al['ano']; ?>" <?php echo $ano_filtro == $al['ano'] ? 'selected' : ''; ?>><?php echo $al['ano']; ?> <?php echo $al['ativo'] == 1 ? '(Ativo)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="filter-label">Mês</label>
                        <select name="mes" class="form-select">
                            <option value="0">Todos</option>
                            <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $mes_filtro==$m?'selected':''; ?>><?php echo getMesNome($m); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="filter-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome ou matrícula..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="col-md-1"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Mensalidades -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-list"></i> Mensalidades</h5></div>
            <div class="card-body">
                <?php if (empty($mensalidades)): ?>
                    <div class="alert alert-info text-center">Nenhuma mensalidade encontrada.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Aluno</th><th>Turma</th><th>Mês/Ano</th><th>Valor Total</th><th>Valor Pago</th><th>Saldo</th><th>Vencimento</th><th>Status</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mensalidades as $row): ?>
                                <tr class="mensalidade-row <?php echo $row['status']; ?>">
                                    <td><strong><?php echo htmlspecialchars($row['aluno_nome']); ?></strong><br><small><?php echo $row['matricula']; ?></small></td>
                                    <td><?php echo $row['turma_ano'] . 'ª - ' . htmlspecialchars($row['turma_nome']); ?></td>
                                    <td><?php echo getMesNome($row['mes_referencia']) . '/' . $row['ano_referencia']; ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($row['valor_total']); ?></td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($row['valor_pago']); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($row['valor_total'] - $row['valor_pago']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['data_vencimento'])); ?></td>
                                    <td><?php echo getStatusMensalidadeBadge($row['status']); ?></td>
                                    <td>
                                        <a href="ver_pagamentos.php?aluno_id=<?php echo $row['aluno_id']; ?>" class="btn btn-sm btn-info" title="Ver Pagamentos"><i class="fas fa-eye"></i></a>
                                        <a href="pagamentos.php?aluno_id=<?php echo $row['aluno_id']; ?>&mensalidade_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success" title="Registrar Pagamento"><i class="fas fa-money-bill"></i></a>
                                     </row>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAIS:<tr>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total')) - array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&turma_id=<?php echo $turma_filtro; ?>&ano=<?php echo $ano_filtro; ?>&mes=<?php echo $mes_filtro; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Lançar Mensalidades -->
    <div class="modal fade" id="modalLancarMensalidades" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> Lançar Mensalidades</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Turma <span class="text-danger">*</span></label>
                            <select name="turma_id" class="form-select" required>
                                <option value="">Selecione uma turma</option>
                                <?php foreach($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo $t['ano'].'ª - '.htmlspecialchars($t['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Mês <span class="text-danger">*</span></label>
                                <select name="mes_referencia" class="form-select" required>
                                    <?php for($m=1;$m<=12;$m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == $mes_atual ? 'selected' : ''; ?>><?php echo getMesNome($m); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3"><label class="form-label">Ano Letivo <span class="text-danger">*</span></label>
                                <select name="ano_referencia" class="form-select" required>
                                    <?php foreach($anos_letivos as $al): ?>
                                    <option value="<?php echo $al['ano']; ?>"><?php echo $al['ano']; ?> <?php echo $al['ativo'] == 1 ? '(Ativo)' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label">Valor da Mensalidade</label>
                            <input type="text" name="valor" class="form-control" value="<?php echo number_format($valor_mensalidade_padrao, 2, ',', '.'); ?>">
                        </div>
                        <div class="mb-3"><label class="form-label">Data de Vencimento</label>
                            <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d', strtotime("$ano_letivo_ativo_valor-$mes_atual-10")); ?>">
                        </div>
                        <div class="alert alert-info">Serão lançadas mensalidades para todos os alunos ativos da turma selecionada.</div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="lancar_mensalidades" class="btn btn-primary">Lançar Mensalidades</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Desconto em Lote -->
    <div class="modal fade" id="modalDescontoLote" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-percent"></i> Aplicar Desconto em Lote</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Turma</label>
                            <select name="turma_id_desconto" class="form-select" required>
                                <option value="">Selecione uma turma</option>
                                <?php foreach($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo $t['ano'].'ª - '.htmlspecialchars($t['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Mês</label>
                                <select name="mes_referencia_desconto" class="form-select" required>
                                    <?php for($m=1;$m<=12;$m++): ?>
                                    <option value="<?php echo $m; ?>"><?php echo getMesNome($m); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3"><label class="form-label">Ano Letivo</label>
                                <select name="ano_referencia_desconto" class="form-select" required>
                                    <?php foreach($anos_letivos as $al): ?>
                                    <option value="<?php echo $al['ano']; ?>"><?php echo $al['ano']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label">Percentual de Desconto (%)</label>
                            <input type="number" name="percentual_desconto" class="form-control" step="1" min="1" max="100" required placeholder="Ex: 10">
                        </div>
                        <div class="alert alert-warning">O desconto será aplicado apenas nas mensalidades pendentes da turma selecionada.</div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="aplicar_desconto" class="btn btn-primary">Aplicar Desconto</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function formatarMoedaInput(valor) {
            let v = valor.toString().replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        $('input[name="valor"]').on('input', function() { $(this).val(formatarMoedaInput($(this).val())); });
    </script>
</body>
</html>