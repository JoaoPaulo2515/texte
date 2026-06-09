<?php
// escola/tesouraria/receitas.php - Gestão de Receitas

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
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';
$forma_filtro = isset($_GET['forma']) ? $_GET['forma'] : '';

// ============================================
// PROCESSAR REGISTRO DE RECEITA
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $data_receita = $_POST['data_receita'];
        $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $aluno_id = !empty($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : null;
        
        if ($valor <= 0) {
            $error = "Valor inválido.";
        } elseif (empty($categoria)) {
            $error = "Informe a categoria.";
        } else {
            try {
                $sql = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, observacoes, aluno_id, usuario_id, data_movimento, status, created_at) 
                        VALUES (:escola_id, 'entrada', :categoria, :descricao, :valor, :forma_pagamento, :referencia, :observacoes, :aluno_id, :usuario_id, :data_movimento, 'ativo', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':categoria' => $categoria,
                    ':descricao' => $descricao,
                    ':valor' => $valor,
                    ':forma_pagamento' => $forma_pagamento,
                    ':referencia' => $referencia,
                    ':observacoes' => $observacoes,
                    ':aluno_id' => $aluno_id,
                    ':usuario_id' => $usuario_id,
                    ':data_movimento' => $data_receita
                ]);
                $success = "Receita registrada com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao registrar: " . $e->getMessage();
            }
        }
    }
    
    // Cancelar receita
    elseif ($_POST['action'] == 'cancelar') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE caixa SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id AND tipo = 'entrada'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Receita cancelada!";
    }
    
    // Excluir receita
    elseif ($_POST['action'] == 'delete') {
        $id = (int)$_POST['id'];
        $sql = "DELETE FROM caixa WHERE id = :id AND escola_id = :escola_id AND tipo = 'entrada' AND status = 'ativo'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Receita excluída!";
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Totais
$sql_totais = "SELECT 
                COALESCE(SUM(valor), 0) as total_receitas,
                COUNT(*) as total_registros
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND tipo = 'entrada' 
              AND status = 'ativo'
              AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);

// Receitas por categoria
$sql_categorias = "SELECT 
                    categoria,
                    COALESCE(SUM(valor), 0) as total,
                    COUNT(*) as quantidade
                  FROM caixa 
                  WHERE escola_id = :escola_id 
                  AND tipo = 'entrada' 
                  AND status = 'ativo'
                  AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
                  GROUP BY categoria
                  ORDER BY total DESC";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$receitas_por_categoria = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Receitas por forma de pagamento
$sql_formas = "SELECT 
                metodo_pagamento,
                COALESCE(SUM(valor), 0) as total,
                COUNT(*) as quantidade
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND tipo = 'entrada' 
              AND status = 'ativo'
              AND DATE(data_movimento) BETWEEN :data_inicio AND :data_fim
              GROUP BY metodo_pagamento
              ORDER BY total DESC";
$stmt_formas = $conn->prepare($sql_formas);
$stmt_formas->execute([
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$receitas_por_forma = $stmt_formas->fetchAll(PDO::FETCH_ASSOC);

// Lista de receitas
$sql_receitas = "SELECT r.*, a.nome as aluno_nome 
                 FROM caixa r
                 LEFT JOIN estudantes a ON a.id = r.aluno_id
                 WHERE r.escola_id = :escola_id 
                 AND r.tipo = 'entrada' 
                 AND r.status = 'ativo'
                 AND DATE(r.data_movimento) BETWEEN :data_inicio AND :data_fim";
if (!empty($categoria_filtro)) {
    $sql_receitas .= " AND r.categoria = :categoria";
}
if (!empty($forma_filtro)) {
    $sql_receitas .= " AND r.metodo_pagamento = :forma";
}
$sql_receitas .= " ORDER BY r.data_movimento DESC, r.id DESC";

$stmt_receitas = $conn->prepare($sql_receitas);
$params = [
    ':escola_id' => $escola_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
];
if (!empty($categoria_filtro)) {
    $params[':categoria'] = $categoria_filtro;
}
if (!empty($forma_filtro)) {
    $params[':forma'] = $forma_filtro;
}
$stmt_receitas->execute($params);
$receitas = $stmt_receitas->fetchAll(PDO::FETCH_ASSOC);

// Categorias disponíveis
$categorias_disponiveis = [
    'Mensalidade' => ['icon' => 'fa-calendar-dollar', 'color' => '#006B3E'],
    'Matrícula' => ['icon' => 'fa-user-graduate', 'color' => '#28a745'],
    'Certificado' => ['icon' => 'fa-certificate', 'color' => '#17a2b8'],
    'Material Escolar' => ['icon' => 'fa-book', 'color' => '#ffc107'],
    'Doação' => ['icon' => 'fa-gift', 'color' => '#fd7e14'],
    'Evento' => ['icon' => 'fa-calendar-alt', 'color' => '#6f42c1'],
    'Curso Extra' => ['icon' => 'fa-chalkboard', 'color' => '#20c997'],
    'Outra Receita' => ['icon' => 'fa-plus-circle', 'color' => '#6c757d']
];

// Buscar alunos para o select
$sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Funções auxiliares
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
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
    <title>Receitas | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .categoria-card { cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .categoria-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .categoria-card.selected { border-color: #006B3E; background: #e8f5e9; }
        
        .valor-entrada { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-arrow-up"></i> Receitas</h2>
                <p class="text-muted">Gestão de todas as entradas financeiras</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaReceita">
                    <i class="fas fa-plus"></i> Nova Receita
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value valor-entrada"><?php echo formatarMoeda($totais['total_receitas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-chart-line"></i> Total de Receitas</div>
                    <small><?php echo date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim)); ?></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($totais['total_registros'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-receipt"></i> Total de Transações</div>
                    <small>Registros no período</small>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
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
                    <div class="col-md-3">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <option value="">Todas</option>
                            <?php foreach ($categorias_disponiveis as $cat => $info): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $categoria_filtro == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                            <?php endforeach; ?>
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
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Receitas por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="categoriaChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Receitas por Forma de Pagamento</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="formaChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Receitas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Receitas</h5>
            </div>
            <div class="card-body">
                <?php if (empty($receitas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma receita encontrada no período.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Aluno</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($receitas as $rec): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($rec['data_movimento'])); ?></td>
                                    <td>
                                        <i class="fas <?php echo $categorias_disponiveis[$rec['categoria']]['icon'] ?? 'fa-tag'; ?>" style="color: <?php echo $categorias_disponiveis[$rec['categoria']]['color'] ?? '#6c757d'; ?>;"></i>
                                        <?php echo htmlspecialchars($rec['categoria']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($rec['descricao']); ?></small></td>
                                    <td><?php echo htmlspecialchars($rec['aluno_nome'] ?? '-'); ?></small></td>
                                    <td class="valor-entrada"><?php echo formatarMoeda($rec['valor']); ?></td>
                                    <td><?php echo getFormaPagamentoIcone($rec['metodo_pagamento']); ?></td>
                                    <td><?php echo htmlspecialchars($rec['referencia'] ?: '-'); ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="cancelarReceita(<?php echo $rec['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancelar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAL:</td>
                                    <td class="valor-entrada"><?php echo formatarMoeda(array_sum(array_column($receitas, 'valor'))); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Receita -->
    <div class="modal fade" id="modalNovaReceita" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Receita</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <!-- Categorias -->
                        <div class="mb-3">
                            <label class="form-label">Categoria <span class="text-danger">*</span></label>
                            <div class="row">
                                <?php foreach ($categorias_disponiveis as $cat => $info): ?>
                                <div class="col-md-3 mb-2">
                                    <div class="card categoria-card" data-categoria="<?php echo $cat; ?>" style="border-left: 4px solid <?php echo $info['color']; ?>; cursor: pointer;">
                                        <div class="card-body p-2 text-center">
                                            <i class="fas <?php echo $info['icon']; ?>" style="font-size: 1.2rem; color: <?php echo $info['color']; ?>;"></i>
                                            <div class="small fw-bold"><?php echo $cat; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="categoria" id="categoria_selecionada" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" class="form-control" required placeholder="Ex: Pagamento de mensalidade, Taxa de matrícula...">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data da Receita</label>
                                <input type="date" name="data_receita" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Forma de Pagamento</label>
                                <select name="forma_pagamento" class="form-select">
                                    <option value="dinheiro">💵 Dinheiro</option>
                                    <option value="transferencia">🏦 Transferência Bancária</option>
                                    <option value="deposito">💰 Depósito</option>
                                    <option value="cheque">📄 Cheque</option>
                                    <option value="multicaixa">💳 Multicaixa</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Aluno (opcional)</label>
                                <select name="aluno_id" class="form-select">
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos as $aluno): ?>
                                    <option value="<?php echo $aluno['id']; ?>"><?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nº Referência (Opcional)</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Nº do documento, transferência, cheque...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Receita</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        // Seleção de categoria
        $('.categoria-card').on('click', function() {
            $('.categoria-card').removeClass('selected');
            $(this).addClass('selected');
            let categoria = $(this).data('categoria');
            $('#categoria_selecionada').val(categoria);
        });
        
        // Funções de ação
        function cancelarReceita(id) {
            if(confirm('Tem certeza que deseja cancelar esta receita?')) {
                $('<form method="POST"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        // Gráficos
        const categoriaLabels = <?php echo json_encode(array_column($receitas_por_categoria, 'categoria')); ?>;
        const categoriaData = <?php echo json_encode(array_column($receitas_por_categoria, 'total')); ?>;
        
        new Chart(document.getElementById('categoriaChart'), {
            type: 'doughnut',
            data: {
                labels: categoriaLabels,
                datasets: [{
                    data: categoriaData,
                    backgroundColor: ['#006B3E', '#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6f42c1', '#20c997', '#6c757d']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        const formaLabels = <?php echo json_encode(array_column($receitas_por_forma, 'metodo_pagamento')); ?>;
        const formaData = <?php echo json_encode(array_column($receitas_por_forma, 'total')); ?>;
        
        new Chart(document.getElementById('formaChart'), {
            type: 'pie',
            data: {
                labels: formaLabels,
                datasets: [{
                    data: formaData,
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#6c757d']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        
        // Selecionar primeira categoria automaticamente
        setTimeout(function() { $('.categoria-card:first').click(); }, 100);
    </script>
</body>
</html>