<?php
// escola/tesouraria/caixa.php - Gestão de Caixa Diário

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
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';
$data_filtro = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';

// Inserir movimentação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'insert') {
        $tipo = $_POST['tipo'];
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $data_movimento = $_POST['data_movimento'];
        $forma_pagamento = $_POST['forma_pagamento'] ?? 'dinheiro';
        $referencia = trim($_POST['referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        if ($valor <= 0) {
            $error = "Valor inválido.";
        } elseif (empty($categoria)) {
            $error = "Informe a categoria.";
        } else {
            try {
                $sql = "INSERT INTO caixa (escola_id, tipo, categoria, descricao, valor, metodo_pagamento, referencia, observacoes, usuario_id, data_movimento, status, created_at) 
                        VALUES (:escola_id, :tipo, :categoria, :descricao, :valor, :forma_pagamento, :referencia, :observacoes, :usuario_id, :data_movimento, 'ativo', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':tipo' => $tipo,
                    ':categoria' => $categoria,
                    ':descricao' => $descricao,
                    ':valor' => $valor,
                    ':forma_pagamento' => $forma_pagamento,
                    ':referencia' => $referencia,
                    ':observacoes' => $observacoes,
                    ':usuario_id' => $usuario_id,
                    ':data_movimento' => $data_movimento
                ]);
                $success = "Movimentação registrada com sucesso!";
            } catch (Exception $e) {
                $error = "Erro ao registrar: " . $e->getMessage();
            }
        }
    }
    
    // Cancelar movimentação
    elseif ($_POST['action'] == 'cancelar') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE caixa SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $success = "Movimentação cancelada!";
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Totais do dia
$sql_totais = "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as saldo
              FROM caixa 
              WHERE escola_id = :escola_id AND DATE(data_movimento) = :data AND status = 'ativo'";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->execute([':escola_id' => $escola_id, ':data' => $data_filtro]);
$totais_dia = $stmt_totais->fetch(PDO::FETCH_ASSOC);

// Totais do mês
$sql_totais_mes = "SELECT 
                    COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                    COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas
                  FROM caixa 
                  WHERE escola_id = :escola_id AND MONTH(data_movimento) = MONTH(CURDATE()) AND YEAR(data_movimento) = YEAR(CURDATE()) AND status = 'ativo'";
$stmt_totais_mes = $conn->prepare($sql_totais_mes);
$stmt_totais_mes->execute([':escola_id' => $escola_id]);
$totais_mes = $stmt_totais_mes->fetch(PDO::FETCH_ASSOC);

// Buscar movimentações do dia
$sql_movimentacoes = "SELECT * FROM caixa 
                      WHERE escola_id = :escola_id 
                      AND DATE(data_movimento) = :data 
                      AND status = 'ativo'
                      ORDER BY id DESC";
$stmt_movimentacoes = $conn->prepare($sql_movimentacoes);
$stmt_movimentacoes->execute([':escola_id' => $escola_id, ':data' => $data_filtro]);
$movimentacoes = $stmt_movimentacoes->fetchAll(PDO::FETCH_ASSOC);

// Categorias mais comuns
$sql_categorias = "SELECT categoria, COUNT(*) as total FROM caixa WHERE escola_id = :escola_id AND status = 'ativo' GROUP BY categoria ORDER BY total DESC LIMIT 10";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_COLUMN, 0);

// Funções auxiliares
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getTipoBadge($tipo) {
    if ($tipo == 'entrada') {
        return '<span class="badge bg-success"><i class="fas fa-arrow-up"></i> Entrada</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-arrow-down"></i> Saída</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa Diário | Tesouraria | SIGE Angola</title>
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
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .entrada-row { border-left: 4px solid #28a745; }
        .saida-row { border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-cash-register"></i> Caixa Diário</h2>
                <p class="text-muted">Gestão de entradas e saídas financeiras</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaMovimentacao">
                    <i class="fas fa-plus"></i> Nova Movimentação
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
        
        <!-- Filtro de Data -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Filtro</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Data</label>
                        <input type="date" name="data" class="form-control" value="<?php echo $data_filtro; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                    <div class="col-md-4 text-end">
                        <label class="form-label">&nbsp;</label>
                        <a href="caixa.php" class="btn btn-secondary w-100"><i class="fas fa-sync-alt"></i> Hoje</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($totais_dia['total_entradas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Entradas do Dia</div>
                    <small><?php echo date('d/m/Y', strtotime($data_filtro)); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais_dia['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Saídas do Dia</div>
                    <small><?php echo date('d/m/Y', strtotime($data_filtro)); ?></small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?php echo ($totais_dia['saldo'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($totais_dia['saldo'] ?? 0); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo do Dia</div>
                    <small><?php echo ($totais_dia['saldo'] ?? 0) >= 0 ? 'Positivo' : 'Negativo'; ?></small>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($totais_mes['total_entradas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Entradas do Mês</div>
                    <small><?php echo date('M/Y'); ?></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais_mes['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-alt"></i> Saídas do Mês</div>
                    <small><?php echo date('M/Y'); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Lista de Movimentações -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Movimentações - <?php echo date('d/m/Y', strtotime($data_filtro)); ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($movimentacoes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma movimentação registrada para este dia.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Tipo</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                    <th>Data/Hora</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movimentacoes as $mov): ?>
                                <tr class="<?php echo $mov['tipo'] == 'entrada' ? 'entrada-row' : 'saida-row'; ?>">
                                    <td><?php echo $mov['id']; ?></td>
                                    <td><?php echo getTipoBadge($mov['tipo']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['categoria']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['descricao']); ?></td>
                                    <td class="<?php echo $mov['tipo'] == 'entrada' ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                        <?php echo formatarMoeda($mov['valor']); ?>
                                    </td>
                                    <td><?php echo ucfirst($mov['metodo_pagamento'] ?? 'Dinheiro'); ?></td>
                                    <td><?php echo htmlspecialchars($mov['referencia'] ?: '-'); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($mov['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="cancelarMovimentacao(<?php echo $mov['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancelar
                                        </button>
                                     </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">TOTAIS:</td>
                                    <td class="text-success"><?php echo formatarMoeda($totais_dia['total_entradas'] ?? 0); ?></td>
                                    <td class="text-danger"><?php echo formatarMoeda($totais_dia['total_saidas'] ?? 0); ?></td>
                                    <td colspan="3"><?php echo formatarMoeda($totais_dia['saldo'] ?? 0); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Movimentação -->
    <div class="modal fade" id="modalNovaMovimentacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nova Movimentação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                            <select name="tipo" id="tipoMov" class="form-select" required onchange="toggleTipo()">
                                <option value="entrada">📥 Entrada (Recebimento)</option>
                                <option value="saida">📤 Saída (Pagamento/Despesa)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Categoria <span class="text-danger">*</span></label>
                            <select name="categoria" class="form-select" required>
                                <option value="">Selecione...</option>
                                <optgroup label="📥 Entradas">
                                    <option value="Mensalidade">📅 Mensalidade</option>
                                    <option value="Matrícula">📝 Matrícula</option>
                                    <option value="Certificado">🎓 Certificado</option>
                                    <option value="Material Escolar">📚 Material Escolar</option>
                                    <option value="Doação">🎁 Doação</option>
                                    <option value="Outra Entrada">💰 Outra Entrada</option>
                                </optgroup>
                                <optgroup label="📤 Saídas">
                                    <option value="Salários">👨‍🏫 Salários</option>
                                    <option value="Água">💧 Água</option>
                                    <option value="Luz">⚡ Luz</option>
                                    <option value="Internet">🌐 Internet</option>
                                    <option value="Telefone">📞 Telefone</option>
                                    <option value="Material de Limpeza">🧹 Material de Limpeza</option>
                                    <option value="Material Escritório">✏️ Material Escritório</option>
                                    <option value="Manutenção">🔧 Manutenção</option>
                                    <option value="Impostos">📄 Impostos</option>
                                    <option value="Outra Saída">📤 Outra Saída</option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input type="text" name="descricao" class="form-control" required placeholder="Ex: Pagamento de mensalidade, Compra de material...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Valor <span class="text-danger">*</span></label>
                            <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Forma de Pagamento</label>
                            <select name="forma_pagamento" class="form-select">
                                <option value="dinheiro">💵 Dinheiro</option>
                                <option value="transferencia">🏦 Transferência Bancária</option>
                                <option value="deposito">💰 Depósito</option>
                                <option value="cheque">📄 Cheque</option>
                                <option value="multicaixa">💳 Multicaixa</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Data da Movimentação</label>
                            <input type="date" name="data_movimento" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nº Referência (Opcional)</label>
                            <input type="text" name="referencia" class="form-control" placeholder="Nº do documento, cheque, transferência...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registrar Movimentação</button>
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
        
        function toggleTipo() {
            let tipo = $('#tipoMov').val();
            if (tipo === 'entrada') {
                $('.modal-header-custom').css('background', 'linear-gradient(135deg, #28a745 0%, #20c997 100%)');
            } else {
                $('.modal-header-custom').css('background', 'linear-gradient(135deg, #dc3545 0%, #e83e8c 100%)');
            }
        }
        
        function cancelarMovimentacao(id) {
            if(confirm('Tem certeza que deseja cancelar esta movimentação?')) {
                $('<form method="POST"><input type="hidden" name="action" value="cancelar"><input type="hidden" name="id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        toggleTipo();
        $('#tipoMov').on('change', toggleTipo);
    </script>
</body>
</html>