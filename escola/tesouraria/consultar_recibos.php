<?php
// escola/tesouraria/consultar_recibos.php - Consulta de Recibos

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
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
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// FILTROS
// ============================================
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-t');
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$forma_filtro = isset($_GET['forma']) ? $_GET['forma'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_por_pagina = 20;
$offset = ($pagina - 1) * $registros_por_pagina;

// ============================================
// BUSCAR RECIBOS
// ============================================

// Construir query WHERE
$where = "p.escola_id = :escola_id";
$params = [':escola_id' => $escola_id];

if (!empty($data_inicio) && !empty($data_fim)) {
    $where .= " AND DATE(p.data_pagamento) BETWEEN :data_inicio AND :data_fim";
    $params[':data_inicio'] = $data_inicio;
    $params[':data_fim'] = $data_fim;
}

if (!empty($busca)) {
    $where .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR p.numero_fatura LIKE :busca OR p.numero_referencia LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

if (!empty($status_filtro)) {
    $where .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if (!empty($forma_filtro)) {
    $where .= " AND p.metodo_pagamento = :forma";
    $params[':forma'] = $forma_filtro;
}

// Query para listar recibos
$sql_recibos = "SELECT p.*, e.nome as aluno_nome, e.matricula, e.curso
                FROM pagamentos p
                JOIN estudantes e ON e.id = p.assinatura_id
                WHERE $where
                ORDER BY p.data_pagamento DESC, p.id DESC
                LIMIT :offset, :limit";

$stmt_recibos = $conn->prepare($sql_recibos);
foreach ($params as $key => $value) {
    if ($key != ':offset' && $key != ':limit') {
        $stmt_recibos->bindValue($key, $value);
    }
}
$stmt_recibos->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_recibos->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt_recibos->execute();
$recibos = $stmt_recibos->fetchAll(PDO::FETCH_ASSOC);

// Query para contar total
$sql_total = "SELECT COUNT(*) as total
              FROM pagamentos p
              JOIN estudantes e ON e.id = p.assinatura_id
              WHERE $where";
$stmt_total = $conn->prepare($sql_total);
foreach ($params as $key => $value) {
    if ($key != ':offset' && $key != ':limit') {
        $stmt_total->bindValue($key, $value);
    }
}
$stmt_total->execute();
$total_registros = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// ============================================
// ESTATÍSTICAS DOS RECIBOS
// ============================================

$sql_stats = "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(valor), 0) as total_valor,
                COUNT(DISTINCT assinatura_id) as total_alunos
              FROM pagamentos p
              WHERE p.escola_id = :escola_id
              AND DATE(p.data_pagamento) BETWEEN :data_inicio AND :data_fim";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusReciboBadge($status) {
    switch ($status) {
        case 'confirmado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Confirmado</span>';
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'cancelado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
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
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Recibos | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .recibo-row { cursor: pointer; transition: all 0.2s; }
        .recibo-row:hover { background: #e8f5e9; transform: translateX(5px); }
        
        .btn-recibo { margin: 2px; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .pagination { margin-top: 20px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice"></i> Consultar Recibos</h2>
                <p class="text-muted">Pesquisar e visualizar recibos de pagamento</p>
            </div>
            <div>
                <a href="index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-receipt"></i> Total de Recibos</div>
                    <small><?php echo date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim)); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo formatarMoeda($stats['total_valor'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-money-bill"></i> Valor Total</div>
                    <small>Total arrecadado no período</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo number_format($stats['total_alunos'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-users"></i> Alunos Atendidos</div>
                    <small>Alunos com pagamentos no período</small>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros de Pesquisa</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="confirmado" <?php echo $status_filtro == 'confirmado' ? 'selected' : ''; ?>>Confirmado</option>
                            <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="cancelado" <?php echo $status_filtro == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Forma de Pagamento</label>
                        <select name="forma" class="form-select">
                            <option value="">Todas</option>
                            <option value="dinheiro" <?php echo $forma_filtro == 'dinheiro' ? 'selected' : ''; ?>>Dinheiro</option>
                            <option value="transferencia" <?php echo $forma_filtro == 'transferencia' ? 'selected' : ''; ?>>Transferência</option>
                            <option value="deposito" <?php echo $forma_filtro == 'deposito' ? 'selected' : ''; ?>>Depósito</option>
                            <option value="cheque" <?php echo $forma_filtro == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="multicaixa" <?php echo $forma_filtro == 'multicaixa' ? 'selected' : ''; ?>>Multicaixa</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Pesquisar</button>
                    </div>
                </form>
                
                <form method="GET" class="row g-3 mt-3">
                    <div class="col-md-10">
                        <label class="form-label">Buscar por Nome, Matrícula, Fatura ou Referência</label>
                        <input type="text" name="busca" class="form-control" placeholder="Digite o nome do aluno, matrícula, número da fatura ou referência..." value="<?php echo htmlspecialchars($busca); ?>">
                        <input type="hidden" name="data_inicio" value="<?php echo $data_inicio; ?>">
                        <input type="hidden" name="data_fim" value="<?php echo $data_fim; ?>">
                        <input type="hidden" name="status" value="<?php echo $status_filtro; ?>">
                        <input type="hidden" name="forma" value="<?php echo $forma_filtro; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Buscar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Recibos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Recibos</h5>
                <small>Total de <?php echo number_format($total_registros); ?> recibo(s) encontrado(s)</small>
            </div>
            <div class="card-body">
                <?php if (empty($recibos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum recibo encontrado com os filtros selecionados.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Nº Fatura</th>
                                    <th>Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recibos as $recibo): ?>
                                <tr class="recibo-row" onclick="visualizarRecibo(<?php echo $recibo['id']; ?>)">
                                    <td><?php echo date('d/m/Y', strtotime($recibo['data_pagamento'])); ?></td>
                                    <td>
                                        <span class="badge bg-dark"><?php echo htmlspecialchars($recibo['numero_fatura']); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($recibo['aluno_nome']); ?></strong>
                                    </small></td>
                                    <td><?php echo htmlspecialchars($recibo['matricula']); ?></small></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($recibo['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($recibo['metodo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($recibo['numero_referencia'] ?: '-'); ?></small></td>
                                    <td><?php echo getStatusReciboBadge($recibo['status']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="recibo_termico.php?pagamento_id=<?php echo $recibo['id']; ?>" target="_blank" class="btn btn-sm btn-info btn-recibo" title="Visualizar Recibo Térmico" onclick="event.stopPropagation()">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <a href="recibos.php?pagamento_id=<?php echo $recibo['id']; ?>" target="_blank" class="btn btn-sm btn-primary btn-recibo" title="Visualizar Recibo Completo" onclick="event.stopPropagation()">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            <?php if ($recibo['comprovativo_path'] && file_exists($recibo['comprovativo_path'])): ?>
                                            <a href="<?php echo $recibo['comprovativo_path']; ?>" target="_blank" class="btn btn-sm btn-secondary btn-recibo" title="Ver Comprovativo" onclick="event.stopPropagation()">
                                                <i class="fas fa-image"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAIS:</td>
                                    <td class="text-success"><?php echo formatarMoeda(array_sum(array_column($recibos, 'valor'))); ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <?php if ($total_paginas > 1): ?>
                    <nav>
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>&forma=<?php echo urlencode($forma_filtro); ?>">
                                    <i class="fas fa-chevron-left"></i> Anterior
                                </a>
                            </li>
                            <?php 
                            $max_paginas = 5;
                            $inicio = max(1, $pagina - floor($max_paginas / 2));
                            $fim = min($total_paginas, $inicio + $max_paginas - 1);
                            if ($inicio > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?pagina=1&data_inicio=' . urlencode($data_inicio) . '&data_fim=' . urlencode($data_fim) . '&busca=' . urlencode($busca) . '&status=' . urlencode($status_filtro) . '&forma=' . urlencode($forma_filtro) . '">1</a></li>';
                                if ($inicio > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            for($i = $inicio; $i <= $fim; $i++): 
                            ?>
                            <li class="page-item <?php echo $pagina == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $i; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>&forma=<?php echo urlencode($forma_filtro); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; 
                            if ($fim < $total_paginas) {
                                if ($fim < $total_paginas - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="?pagina=' . $total_paginas . '&data_inicio=' . urlencode($data_inicio) . '&data_fim=' . urlencode($data_fim) . '&busca=' . urlencode($busca) . '&status=' . urlencode($status_filtro) . '&forma=' . urlencode($forma_filtro) . '">' . $total_paginas . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>&forma=<?php echo urlencode($forma_filtro); ?>">
                                    Próxima <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Informações -->
        <div class="alert alert-info mt-4">
            <i class="fas fa-info-circle"></i>
            <strong>Dica:</strong> Clique em qualquer linha da tabela para visualizar o recibo. 
            Você também pode usar os botões para visualizar diferentes formatos de recibo.
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        function visualizarRecibo(id) {
            window.open('recibos.php?pagamento_id=' + id, '_blank');
        }
        
        // Destacar linha ao passar o mouse
        $('.recibo-row').hover(
            function() { $(this).css('background', '#e8f5e9'); },
            function() { $(this).css('background', ''); }
        );
    </script>
</body>
</html>