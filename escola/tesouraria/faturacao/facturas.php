<?php
// escola/tesouraria/faturacao/facturas.php - Gestão de Facturas (Faturas Definitivas)

require_once __DIR__ . '/../../../config/database.php';
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
// BUSCAR PRODUTOS
// ============================================
$sql_produtos = "SELECT id, nome, descricao, preco FROM produtos WHERE escola_id = :escola_id AND ativo = 1 ORDER BY nome ASC";
$stmt_produtos = $conn->prepare($sql_produtos);
$stmt_produtos->execute([':escola_id' => $escola_id]);
$produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : '';
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// ============================================
// PROCESSAR CANCELAR FACTURA
// ============================================
$success = '';
$error = '';

if (($is_financeiro || $is_admin) && isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $factura_id = (int)$_GET['cancelar'];
    try {
        $sql = "UPDATE facturas SET status = 'cancelada', updated_at = NOW() WHERE id = :id AND escola_id = :escola_id AND status = 'ativa'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $factura_id, ':escola_id' => $escola_id]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Factura cancelada com sucesso!";
        } else {
            $error = "Não foi possível cancelar a factura. Verifique se ela está ativa.";
        }
    } catch (Exception $e) {
        $error = "Erro ao cancelar factura: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR FACTURAS
// ============================================
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if ($status_filtro != 'todos') {
    $where_conditions[] = "f.status = :status";
    $params[':status'] = $status_filtro;
}

if (!empty($busca)) {
    $where_conditions[] = "(f.numero_factura LIKE :busca OR e.nome LIKE :busca OR e.matricula LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

if (!empty($data_inicio)) {
    $where_conditions[] = "f.data_emissao >= :data_inicio";
    $params[':data_inicio'] = $data_inicio;
}

if (!empty($data_fim)) {
    $where_conditions[] = "f.data_emissao <= :data_fim";
    $params[':data_fim'] = $data_fim;
}

$where_conditions[] = "f.escola_id = :escola_id";
$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE f.escola_id = :escola_id";

$sql_facturas = "
    SELECT f.*, e.nome as estudante_nome, e.matricula, 
           fp.numero_fatura as fatura_proforma_numero,
           (SELECT COUNT(*) FROM factura_itens WHERE factura_id = f.id) as total_itens
    FROM facturas f
    JOIN estudantes e ON e.id = f.estudante_id
    LEFT JOIN faturas_proforma fp ON fp.id = f.fatura_proforma_id
    $where_sql
    ORDER BY f.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql_facturas);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total para paginação
$count_query = "SELECT COUNT(*) as total FROM facturas f $where_sql";
$stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_facturas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_facturas / $limit);

// ============================================
// ESTATÍSTICAS
// ============================================
$stats = [];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM facturas WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM facturas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['ativas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM facturas WHERE escola_id = :escola_id AND status = 'cancelada'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['canceladas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT SUM(total) as total FROM facturas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['valor_total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusFacturaBadge($status) {
    switch ($status) {
        case 'ativa':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativa</span>';
        case 'cancelada':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturas | Tesouraria | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.5em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.8rem; color: #6c757d; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .factura-card { transition: all 0.3s ease; }
        .factura-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .factura-card.cancelada { opacity: 0.7; background-color: #f8f9fa; }
        .btn-actions { white-space: nowrap; }
        
        .item-row { background: #f8f9fa; margin-bottom: 10px; padding: 10px; border-radius: 8px; }
        .btn-remover-item { color: #dc3545; cursor: pointer; }
        .btn-remover-item:hover { color: #bd2130; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .valor-unitario-disabled { background-color: #e9ecef; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php include '../menu_tesouraria.php'; ?>
    
    <div class="main-content-tesouraria">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice-dollar"></i> Facturas</h2>
                <p class="text-muted">Gestão de facturas (faturas definitivas)</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaFactura">
                    <i class="fas fa-plus"></i> Nova Factura
                </button>
                <a href="../index.php" class="btn-voltar ms-2">
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
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total de Facturas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativas']; ?></div><div class="stat-label">Facturas Ativas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['canceladas']; ?></div><div class="stat-label">Canceladas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo formatarMoeda($stats['valor_total']); ?></div><div class="stat-label">Valor Total</div></div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option><option value="ativa" <?php echo $status_filtro=='ativa'?'selected':''; ?>>Ativas</option><option value="cancelada" <?php echo $status_filtro=='cancelada'?'selected':''; ?>>Canceladas</option></select></div>
                    <div class="col-md-3"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Nº Factura, Estudante..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-2"><label class="filter-label">Data Início</label><input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>"></div>
                    <div class="col-md-2"><label class="filter-label">Data Fim</label><input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>"></div>
                    <div class="col-md-3"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Facturas -->
        <div class="row">
            <?php if (empty($facturas)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i><h4>Nenhuma factura encontrada</h4><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaFactura"><i class="fas fa-plus"></i> Nova Factura</button></div></div></div>
            <?php else: ?>
                <?php foreach ($facturas as $factura): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card factura-card <?php echo $factura['status'] == 'cancelada' ? 'cancelada' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title"><strong><?php echo htmlspecialchars($factura['numero_factura']); ?></strong></h6>
                                <?php echo getStatusFacturaBadge($factura['status']); ?>
                            </div>
                            <p class="small text-muted mb-1"><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($factura['estudante_nome']); ?><br><small>Matrícula: <?php echo htmlspecialchars($factura['matricula']); ?></small></p>
                            <p class="small text-muted mb-1"><i class="fas fa-calendar-alt"></i> Emissão: <?php echo date('d/m/Y', strtotime($factura['data_emissao'])); ?></p>
                            <p class="small text-muted mb-2"><i class="fas fa-boxes"></i> Itens: <?php echo $factura['total_itens']; ?></p>
                            <?php if ($factura['fatura_proforma_numero']): ?>
                            <p class="small text-muted mb-2"><i class="fas fa-file-invoice"></i> Pró-Forma: <?php echo htmlspecialchars($factura['fatura_proforma_numero']); ?></p>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2"><span class="text-success fw-bold fs-5"><?php echo formatarMoeda($factura['total']); ?></span></div>
                        </div>
                        <div class="card-footer bg-transparent text-center btn-actions">
                            <a href="visualizar_fatura.php?id=<?php echo $factura['id']; ?>&tipo=factura" class="btn btn-sm btn-info" target="_blank"><i class="fas fa-eye"></i> Visualizar</a>
                            <a href="imprimir_fatura.php?id=<?php echo $factura['id']; ?>&tipo=factura" class="btn btn-sm btn-secondary" target="_blank"><i class="fas fa-print"></i> Imprimir</a>
                            <?php if ($factura['status'] == 'ativa' && ($is_financeiro || $is_admin)): ?>
                            <a href="?cancelar=<?php echo $factura['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancelar esta factura?')"><i class="fas fa-ban"></i> Cancelar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&busca=<?php echo urlencode($busca); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Nova Factura (SEMELHANTE À DA FATURA PRÓ-FORMA) -->
    <div class="modal fade" id="modalNovaFactura" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Emitir Nova Factura</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="emitir_factura.php">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-user-graduate"></i> Dados do Estudante</h6>
                                <div class="mb-3">
                                    <label class="form-label">Estudante <span class="text-danger">*</span></label>
                                    <select name="estudante_id" id="estudante_id" class="form-select" required>
                                        <option value="">Selecione um estudante</option>
                                        <?php
                                        $sql_est = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id ORDER BY nome ASC";
                                        $stmt_est = $conn->prepare($sql_est);
                                        $stmt_est->execute([':escola_id' => $escola_id]);
                                        $estudantes_lista = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($estudantes_lista as $est): ?>
                                        <option value="<?php echo $est['id']; ?>" data-nome="<?php echo htmlspecialchars($est['nome']); ?>" data-matricula="<?php echo $est['matricula']; ?>">
                                            <?php echo htmlspecialchars($est['nome']); ?> (<?php echo $est['matricula']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="infoEstudante" class="alert alert-info" style="display: none;">
                                    <strong><i class="fas fa-info-circle"></i> Informações do Estudante</strong><br>
                                    <span id="infoNome"></span><br>
                                    <span id="infoMatricula"></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-calendar-alt"></i> Dados da Factura</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Data de Emissão</label>
                                        <input type="date" name="data_emissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Converter de Fatura Pró-Forma</label>
                                        <select name="fatura_proforma_id" id="fatura_proforma_id" class="form-select">
                                            <option value="">Selecione uma fatura pró-forma</option>
                                            <?php
                                            $sql_pf = "SELECT fp.id, fp.numero_fatura, e.nome as estudante_nome, fp.total 
                                                       FROM faturas_proforma fp
                                                       JOIN estudantes e ON e.id = fp.estudante_id
                                                       WHERE fp.escola_id = :escola_id AND fp.status = 'pendente'
                                                       ORDER BY fp.created_at DESC";
                                            $stmt_pf = $conn->prepare($sql_pf);
                                            $stmt_pf->execute([':escola_id' => $escola_id]);
                                            $faturas_pf = $stmt_pf->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($faturas_pf as $pf): ?>
                                            <option value="<?php echo $pf['id']; ?>" data-total="<?php echo $pf['total']; ?>"><?php echo htmlspecialchars($pf['numero_fatura']); ?> - <?php echo htmlspecialchars($pf['estudante_nome']); ?> (<?php echo formatarMoeda($pf['total']); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Observações</label>
                                    <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-3"><i class="fas fa-box"></i> Produtos/Serviços</h6>
                        <div id="listaProdutos">
                            <div class="item-row row" id="item-1">
                                <div class="col-md-5">
                                    <label class="form-label">Produto/Serviço <span class="text-danger">*</span></label>
                                    <select name="produtos[0][produto_id]" class="form-select select-produto" data-item="1" required>
                                        <option value="">Selecione um produto</option>
                                        <?php foreach ($produtos as $prod): ?>
                                        <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>"><?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="produtos[0][descricao]" class="descricao-hidden" value="">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Quantidade</label>
                                    <input type="text" name="produtos[0][quantidade]" class="form-control quantidade" value="1" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Valor Unitário</label>
                                    <input type="text" name="produtos[0][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Total</label>
                                    <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                                </div>
                                <div class="col-md-1 text-center">
                                    <label class="form-label">&nbsp;</label>
                                    <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAdicionarItem">
                                <i class="fas fa-plus"></i> Adicionar Item
                            </button>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-8 offset-md-4">
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>Subtotal:</strong></div>
                                    <div class="col-md-6"><input type="text" id="subtotal" class="form-control form-control-sm text-end bg-light" readonly style="background:#e9ecef;"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>IVA (14%):</strong></div>
                                    <div class="col-md-6"><input type="text" id="iva" class="form-control form-control-sm text-end bg-light" readonly style="background:#e9ecef;"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>Desconto:</strong></div>
                                    <div class="col-md-6"><input type="text" name="desconto" id="desconto" class="form-control form-control-sm text-end" value="0,00"></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6 text-end"><strong>Total:</strong></div>
                                    <div class="col-md-6"><input type="text" id="total" class="form-control form-control-sm text-end bg-success text-white fw-bold" readonly style="background:#006B3E; color:white;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong>Nota:</strong> Esta factura tem validade fiscal. Após emitida, não pode ser alterada.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="emitir_factura" class="btn btn-primary">Emitir Factura</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCounter = 1;
        
        // Mostrar informações do estudante
        $('#estudante_id').change(function() {
            let selected = $(this).find('option:selected');
            let nome = selected.data('nome');
            let matricula = selected.data('matricula');
            
            if (selected.val()) {
                $('#infoNome').html('<i class="fas fa-user"></i> <strong>Nome:</strong> ' + nome);
                $('#infoMatricula').html('<i class="fas fa-id-card"></i> <strong>Matrícula:</strong> ' + matricula);
                $('#infoEstudante').show();
            } else {
                $('#infoEstudante').hide();
            }
        });
        
        function formatarMoedaInput(valor) {
            let v = valor.toString().replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        function converterMoedaParaNumero(valor) {
            if (!valor) return 0;
            return parseFloat(valor.toString().replace(/\./g, '').replace(',', '.'));
        }
        
        function carregarProduto(selectElement, itemId) {
            let selectedOption = $(selectElement).find('option:selected');
            let preco = selectedOption.data('preco');
            let nome = selectedOption.data('nome');
            
            if (preco && selectElement.val()) {
                $(`#item-${itemId} .valor-unitario`).val(formatarMoedaInput(preco.toString()));
                $(`#item-${itemId} .descricao-hidden`).val(nome);
                atualizarTotalItem(itemId);
            } else {
                $(`#item-${itemId} .valor-unitario`).val('0,00');
                $(`#item-${itemId} .descricao-hidden`).val('');
                atualizarTotalItem(itemId);
            }
            atualizarTotais();
        }
        
        function atualizarTotalItem(itemId) {
            let quantidade = $(`#item-${itemId} .quantidade`).val();
            let valorUnitario = $(`#item-${itemId} .valor-unitario`).val();
            let qtd = parseFloat(quantidade.toString().replace(',', '.')) || 0;
            let valor = converterMoedaParaNumero(valorUnitario);
            let totalItem = qtd * valor;
            $(`#item-${itemId} .total-item`).val(formatarMoedaInput(totalItem.toFixed(2)));
            return totalItem;
        }
        
        function atualizarTotais() {
            let subtotal = 0;
            $('.item-row').each(function() {
                let itemId = $(this).attr('id').split('-')[1];
                let totalItem = converterMoedaParaNumero($(`#item-${itemId} .total-item`).val());
                subtotal += totalItem;
            });
            let iva = subtotal * 0.14;
            let desconto = converterMoedaParaNumero($('#desconto').val()) || 0;
            let total = subtotal + iva - desconto;
            $('#subtotal').val(formatarMoedaInput(subtotal.toFixed(2)));
            $('#iva').val(formatarMoedaInput(iva.toFixed(2)));
            $('#total').val(formatarMoedaInput(total.toFixed(2)));
        }
        
        $('#btnAdicionarItem').click(function() {
            itemCounter++;
            let newItem = `
                <div class="item-row row" id="item-${itemCounter}">
                    <div class="col-md-5">
                        <select name="produtos[${itemCounter-1}][produto_id]" class="form-select select-produto" data-item="${itemCounter}" required>
                            <option value="">Selecione um produto</option>
                            <?php foreach ($produtos as $prod): ?>
                            <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>"><?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="produtos[${itemCounter-1}][descricao]" class="descricao-hidden" value="">
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="produtos[${itemCounter-1}][quantidade]" class="form-control quantidade" value="1" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="produtos[${itemCounter-1}][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                    </div>
                    <div class="col-md-1 text-center">
                        <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                    </div>
                </div>
            `;
            $('#listaProdutos').append(newItem);
            $('.select-produto').off('change').on('change', function() {
                let itemId = $(this).data('item');
                carregarProduto(this, itemId);
            });
            $('.quantidade').off('input').on('input', function() {
                let itemId = $(this).closest('.item-row').attr('id').split('-')[1];
                atualizarTotalItem(itemId);
                atualizarTotais();
            });
        });
        
        $(document).on('click', '.btn-remover-item', function() {
            if ($('.item-row').length > 1) {
                $(this).closest('.item-row').remove();
                atualizarTotais();
            } else {
                alert('É necessário manter pelo menos um item na factura.');
            }
        });
        
        $(document).on('input', '.quantidade', function() {
            let itemId = $(this).closest('.item-row').attr('id').split('-')[1];
            atualizarTotalItem(itemId);
            atualizarTotais();
        });
        
        $(document).on('change', '.select-produto', function() {
            let itemId = $(this).data('item');
            carregarProduto(this, itemId);
        });
        
        $('#desconto').on('input', function() {
            let valor = $(this).val();
            $(this).val(formatarMoedaInput(valor));
            atualizarTotais();
        });
        
        $('#fatura_proforma_id').change(function() {
            let selected = $(this).find('option:selected');
            let total = selected.data('total');
            if (selected.val() && total) {
                alert('Ao selecionar uma fatura pró-forma, os itens serão carregados automaticamente.');
            }
        });
        
        function inicializarEventos() {
            $('.select-produto').each(function() {
                let itemId = $(this).data('item');
                if ($(this).val()) {
                    carregarProduto(this, itemId);
                }
            });
        }
        
        $('#modalNovaFactura').on('hidden.bs.modal', function() {
            $('#formFatura')[0]?.reset();
            $('#infoEstudante').hide();
            $('#listaProdutos').html(`
                <div class="item-row row" id="item-1">
                    <div class="col-md-5">
                        <select name="produtos[0][produto_id]" class="form-select select-produto" data-item="1" required>
                            <option value="">Selecione um produto</option>
                            <?php foreach ($produtos as $prod): ?>
                            <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>"><?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="produtos[0][descricao]" class="descricao-hidden" value="">
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="produtos[0][quantidade]" class="form-control quantidade" value="1" required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" name="produtos[0][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                    </div>
                    <div class="col-md-1 text-center">
                        <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                    </div>
                </div>
            `);
            itemCounter = 1;
            inicializarEventos();
            atualizarTotais();
        });
        
        inicializarEventos();
        atualizarTotais();
    </script>
</body>
</html>