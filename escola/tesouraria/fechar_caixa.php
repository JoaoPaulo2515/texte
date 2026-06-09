<?php
// escola/tesouraria/fechar_caixa.php - Fechamento de Caixa

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
// PROCESSAR FECHAMENTO
// ============================================
$success = '';
$error = '';
$data_fechamento = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');

// Verificar se já existe fechamento para esta data
$sql_check = "SELECT id, total_entradas, total_saidas, saldo_final FROM fechamento_caixa 
              WHERE escola_id = :escola_id AND DATE(data_fechamento) = :data";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->execute([':escola_id' => $escola_id, ':data' => $data_fechamento]);
$fechamento_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);

// Processar fechamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'fechar') {
        $observacoes = trim($_POST['observacoes'] ?? '');
        $total_em_dinheiro = floatval(str_replace(',', '.', str_replace('.', '', $_POST['total_dinheiro'] ?? '0')));
        $total_em_cheque = floatval(str_replace(',', '.', str_replace('.', '', $_POST['total_cheque'] ?? '0')));
        $total_em_transferencia = floatval(str_replace(',', '.', str_replace('.', '', $_POST['total_transferencia'] ?? '0')));
        $total_em_multicaixa = floatval(str_replace(',', '.', str_replace('.', '', $_POST['total_multicaixa'] ?? '0')));
        
        try {
            // Calcular totais do dia
            $sql_totais = "SELECT 
                            COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                            COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                            COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'dinheiro' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_dinheiro,
                            COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'cheque' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_cheque,
                            COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'transferencia' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_transferencia,
                            COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'multicaixa' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_multicaixa,
                            COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                            COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
                          FROM caixa 
                          WHERE escola_id = :escola_id 
                          AND DATE(data_movimento) = :data 
                          AND status = 'ativo'";
            $stmt_totais = $conn->prepare($sql_totais);
            $stmt_totais->execute([':escola_id' => $escola_id, ':data' => $data_fechamento]);
            $totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);
            
            $saldo_final = ($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0);
            
            // Verificar diferenças
            $diferenca_dinheiro = $total_em_dinheiro - ($totais['total_dinheiro'] ?? 0);
            $diferenca_total = ($total_em_dinheiro + $total_em_cheque + $total_em_transferencia + $total_em_multicaixa) - $saldo_final;
            
            // Inserir fechamento
            $sql_insert = "INSERT INTO fechamento_caixa (
                                escola_id, data_fechamento, total_entradas, total_saidas, saldo_final,
                                total_dinheiro, total_cheque, total_transferencia, total_multicaixa,
                                quantidade_entradas, quantidade_saidas, observacoes, usuario_id, created_at
                          ) VALUES (
                                :escola_id, :data_fechamento, :total_entradas, :total_saidas, :saldo_final,
                                :total_dinheiro, :total_cheque, :total_transferencia, :total_multicaixa,
                                :qtd_entradas, :qtd_saidas, :observacoes, :usuario_id, NOW()
                          )";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->execute([
                ':escola_id' => $escola_id,
                ':data_fechamento' => $data_fechamento,
                ':total_entradas' => $totais['total_entradas'],
                ':total_saidas' => $totais['total_saidas'],
                ':saldo_final' => $saldo_final,
                ':total_dinheiro' => $total_em_dinheiro,
                ':total_cheque' => $total_em_cheque,
                ':total_transferencia' => $total_em_transferencia,
                ':total_multicaixa' => $total_em_multicaixa,
                ':qtd_entradas' => $totais['qtd_entradas'],
                ':qtd_saidas' => $totais['qtd_saidas'],
                ':observacoes' => $observacoes,
                ':usuario_id' => $usuario_id
            ]);
            
            $success = "Caixa fechado com sucesso! Saldo final: " . formatarMoeda($saldo_final);
            
            // Redirecionar para visualizar
            header("Location: fechar_caixa.php?data=" . $data_fechamento . "&success=1");
            exit;
            
        } catch (Exception $e) {
            $error = "Erro ao fechar caixa: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR DADOS DO DIA
// ============================================

// Totais do dia
$sql_totais = "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'saida' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_saidas,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'dinheiro' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_dinheiro,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'cheque' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_cheque,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'transferencia' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_transferencia,
                COALESCE(SUM(CASE WHEN tipo = 'entrada' AND metodo_pagamento = 'multicaixa' AND status = 'ativo' THEN valor ELSE 0 END), 0) as total_multicaixa,
                COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as qtd_entradas,
                COUNT(CASE WHEN tipo = 'saida' THEN 1 END) as qtd_saidas
              FROM caixa 
              WHERE escola_id = :escola_id 
              AND DATE(data_movimento) = :data 
              AND status = 'ativo'";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->execute([':escola_id' => $escola_id, ':data' => $data_fechamento]);
$totais = $stmt_totais->fetch(PDO::FETCH_ASSOC);

$saldo_contabil = ($totais['total_entradas'] ?? 0) - ($totais['total_saidas'] ?? 0);

// Buscar movimentações do dia
$sql_movimentacoes = "SELECT * FROM caixa 
                      WHERE escola_id = :escola_id 
                      AND DATE(data_movimento) = :data 
                      AND status = 'ativo'
                      ORDER BY id DESC";
$stmt_movimentacoes = $conn->prepare($sql_movimentacoes);
$stmt_movimentacoes->execute([':escola_id' => $escola_id, ':data' => $data_fechamento]);
$movimentacoes = $stmt_movimentacoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar histórico de fechamentos
$sql_historico = "SELECT * FROM fechamento_caixa 
                  WHERE escola_id = :escola_id 
                  ORDER BY data_fechamento DESC 
                  LIMIT 10";
$stmt_historico = $conn->prepare($sql_historico);
$stmt_historico->execute([':escola_id' => $escola_id]);
$historico_fechamentos = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
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
    <title>Fechamento de Caixa | Tesouraria | SIGE Angola</title>
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
        
        .resumo-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .divider {
            border-top: 1px dashed #dee2e6;
            margin: 15px 0;
        }
        
        .print-only { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
        
        .fechado {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
        }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-cash-register"></i> Fechamento de Caixa</h2>
                <p class="text-muted">Finalização do caixa diário</p>
            </div>
            <div class="no-print">
                <a href="caixa_diario.php" class="btn btn-info me-2">
                    <i class="fas fa-chart-line"></i> Caixa Diário
                </a>
                <a href="index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">Fechamento realizado com sucesso!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Resumo do Dia -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo formatarMoeda($totais['total_entradas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-up text-success"></i> Total de Entradas</div>
                    <small><?php echo $totais['qtd_entradas'] ?? 0; ?> movimentações</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo formatarMoeda($totais['total_saidas'] ?? 0); ?></div>
                    <div class="stat-label"><i class="fas fa-arrow-down text-danger"></i> Total de Saídas</div>
                    <small><?php echo $totais['qtd_saidas'] ?? 0; ?> movimentações</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?php echo $saldo_contabil >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatarMoeda($saldo_contabil); ?>
                    </div>
                    <div class="stat-label"><i class="fas fa-wallet"></i> Saldo Contábil</div>
                    <small>Entradas - Saídas</small>
                </div>
            </div>
        </div>
        
        <!-- Detalhamento por Forma de Pagamento -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Detalhamento por Forma de Pagamento</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <div class="resumo-box">
                            <i class="fas fa-money-bill-wave fa-2x text-success"></i>
                            <h4 class="mt-2"><?php echo formatarMoeda($totais['total_dinheiro'] ?? 0); ?></h4>
                            <small>Dinheiro</small>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="resumo-box">
                            <i class="fas fa-university fa-2x text-primary"></i>
                            <h4 class="mt-2"><?php echo formatarMoeda($totais['total_transferencia'] ?? 0); ?></h4>
                            <small>Transferência</small>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="resumo-box">
                            <i class="fas fa-credit-card fa-2x text-secondary"></i>
                            <h4 class="mt-2"><?php echo formatarMoeda($totais['total_multicaixa'] ?? 0); ?></h4>
                            <small>Multicaixa</small>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="resumo-box">
                            <i class="fas fa-check-circle fa-2x text-warning"></i>
                            <h4 class="mt-2"><?php echo formatarMoeda($totais['total_cheque'] ?? 0); ?></h4>
                            <small>Cheque</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Fechamento de Caixa -->
        <?php if ($fechamento_existente): ?>
            <div class="card mb-4 fechado">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-check-circle"></i> Caixa Já Fechado</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle"></i> Este caixa já foi fechado em <?php echo date('d/m/Y H:i:s', strtotime($fechamento_existente['created_at'] ?? $data_fechamento)); ?>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Total de Entradas:</strong> <?php echo formatarMoeda($fechamento_existente['total_entradas']); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Total de Saídas:</strong> <?php echo formatarMoeda($fechamento_existente['total_saidas']); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Saldo Final:</strong> <?php echo formatarMoeda($fechamento_existente['saldo_final']); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-lock"></i> Fechar Caixa - <?php echo date('d/m/Y', strtotime($data_fechamento)); ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="no-print">
                        <input type="hidden" name="action" value="fechar">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">Total em Dinheiro</label>
                                <input type="text" name="total_dinheiro" class="form-control valor" value="<?php echo number_format($totais['total_dinheiro'] ?? 0, 2, ',', '.'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Total em Cheque</label>
                                <input type="text" name="total_cheque" class="form-control valor" value="<?php echo number_format($totais['total_cheque'] ?? 0, 2, ',', '.'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Total em Transferência</label>
                                <input type="text" name="total_transferencia" class="form-control valor" value="<?php echo number_format($totais['total_transferencia'] ?? 0, 2, ',', '.'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Total em Multicaixa</label>
                                <input type="text" name="total_multicaixa" class="form-control valor" value="<?php echo number_format($totais['total_multicaixa'] ?? 0, 2, ',', '.'); ?>">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Observações do Fechamento</label>
                            <textarea name="observacoes" class="form-control" rows="2" placeholder="Observações sobre o fechamento do caixa..."></textarea>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Tem certeza que deseja fechar o caixa? Esta ação não pode ser desfeita.')">
                                <i class="fas fa-lock"></i> Fechar Caixa
                            </button>
                            <small class="text-muted ms-3">Após fechar, não será possível adicionar movimentações para esta data</small>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Movimentações do Dia -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Movimentações do Dia - <?php echo date('d/m/Y', strtotime($data_fechamento)); ?></h5>
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
                                    <th>Hora</th>
                                    <th>Tipo</th>
                                    <th>Categoria</th>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Forma</th>
                                    <th>Referência</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movimentacoes as $mov): ?>
                                <tr class="<?php echo $mov['tipo'] == 'entrada' ? 'entrada-row' : 'saida-row'; ?>">
                                    <td><?php echo $mov['id']; ?></td>
                                    <td><?php echo date('H:i:s', strtotime($mov['created_at'])); ?></td>
                                    <td><?php echo getTipoBadge($mov['tipo']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['categoria']); ?></td>
                                    <td><?php echo htmlspecialchars($mov['descricao']); ?></td>
                                    <td class="<?php echo $mov['tipo'] == 'entrada' ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                        <?php echo formatarMoeda($mov['valor']); ?>
                                    </td>
                                    <td><?php echo ucfirst($mov['metodo_pagamento'] ?? 'Dinheiro'); ?></td>
                                    <td><?php echo htmlspecialchars($mov['referencia'] ?: '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="5" class="text-end">TOTAIS:</td>
                                    <td class="text-success"><?php echo formatarMoeda($totais['total_entradas'] ?? 0); ?></td>
                                    <td class="text-danger"><?php echo formatarMoeda($totais['total_saidas'] ?? 0); ?></td>
                                    <td><?php echo formatarMoeda($saldo_contabil); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Histórico de Fechamentos -->
        <?php if (!empty($historico_fechamentos)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Histórico de Fechamentos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr><th>Data</th><th>Entradas</th><th>Saídas</th><th>Saldo</th><th>Usuário</th><th>Data/Hora</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico_fechamentos as $hist): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($hist['data_fechamento'])); ?></td>
                                <td class="text-success"><?php echo formatarMoeda($hist['total_entradas']); ?></td>
                                <td class="text-danger"><?php echo formatarMoeda($hist['total_saidas']); ?></td>
                                <td class="fw-bold"><?php echo formatarMoeda($hist['saldo_final']); ?></td>
                                <td><?php echo $hist['usuario_id']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($hist['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
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
        
        $('.valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
    </script>
</body>
</html>