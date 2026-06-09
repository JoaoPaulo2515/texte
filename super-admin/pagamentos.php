<?php
// super-admin/pagamentos.php - Gestão de Pagamentos
require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// ============================================
// REGISTRAR PAGAMENTO
// ============================================
if ($action == 'registrar' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_GET['id'];
    $status = $_POST['status'] ?? 'pago';
    $data_pagamento = $_POST['data_pagamento'] ?? date('Y-m-d');
    $metodo_pagamento = $_POST['metodo_pagamento'] ?? 'transferencia';
    $codigo_transacao = $_POST['codigo_transacao'] ?? '';
    $observacoes = $_POST['observacoes'] ?? '';
    
    // Upload do comprovante
    $comprovante = '';
    if (isset($_FILES['comprovante']) && $_FILES['comprovante']['error'] == 0) {
        $upload_dir = __DIR__ . '/../uploads/comprovantes/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION);
        $comprovante = 'comp_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['comprovante']['tmp_name'], $upload_dir . $comprovante);
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE pagamentos SET
                status = :status,
                data_pagamento = :data_pagamento,
                metodo_pagamento = :metodo_pagamento,
                codigo_transacao = :codigo_transacao,
                comprovante = :comprovante,
                observacoes = :observacoes,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':data_pagamento' => $data_pagamento,
            ':metodo_pagamento' => $metodo_pagamento,
            ':codigo_transacao' => $codigo_transacao,
            ':comprovante' => $comprovante,
            ':observacoes' => $observacoes
        ]);
        
        $message = "Pagamento registrado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'registrar_pagamento', 'pagamentos', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// EXCLUIR PAGAMENTO
// ============================================
if ($action == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        // Buscar comprovante para deletar
        $stmt = $conn->prepare("SELECT comprovante FROM pagamentos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pagamento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pagamento && !empty($pagamento['comprovante'])) {
            $upload_dir = __DIR__ . '/../uploads/comprovantes/';
            if (file_exists($upload_dir . $pagamento['comprovante'])) {
                unlink($upload_dir . $pagamento['comprovante']);
            }
        }
        
        $stmt = $conn->prepare("DELETE FROM pagamentos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $message = "Pagamento excluído com sucesso!";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// LISTAR PAGAMENTOS
// ============================================
$assinatura_id = $_GET['assinatura'] ?? '';
$escola_id = $_GET['escola'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT p.*, e.nome as escola_nome, e.subdominio, e.logo,
           a.plano_id, a.tipo_cobranca, pl.nome as plano_nome
    FROM pagamentos p
    JOIN escolas e ON e.id = p.escola_id
    JOIN assinaturas a ON a.id = p.assinatura_id
    JOIN planos pl ON pl.id = a.plano_id
    WHERE 1=1
";

$params = [];

if ($assinatura_id) {
    $query .= " AND p.assinatura_id = :assinatura_id";
    $params[':assinatura_id'] = $assinatura_id;
}
if ($escola_id) {
    $query .= " AND p.escola_id = :escola_id";
    $params[':escola_id'] = $escola_id;
}
if ($status_filter) {
    $query .= " AND p.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [];
$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago' AND MONTH(data_pagamento) = MONTH(CURDATE())");
$stats['mes_atual'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->query("SELECT SUM(valor) as total FROM pagamentos WHERE status = 'pago'");
$stats['total_recebido'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->query("SELECT COUNT(*) as total FROM pagamentos WHERE status = 'pendente'");
$stats['pendentes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos - Super Admin | SIGE SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
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
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #4361ee;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-pago { background: #e8f5e9; color: #388e3c; }
        .status-pendente { background: #fff3e0; color: #f57c00; }
        .status-cancelado { background: #ffebee; color: #d32f2f; }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1a1a2e;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE SaaS</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="?page=dashboard" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="?page=escolas" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="?page=planos" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="?page=assinaturas" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="?page=pagamentos" class="nav-link active"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="?page=suporte" class="nav-link"><i class="fas fa-headset"></i> Suporte</a></li>
            <li class="nav-item"><a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2><i class="fas fa-money-bill-wave"></i> Pagamentos</h2>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">R$ <?php echo number_format($stats['mes_atual'], 2, ',', '.'); ?></div>
                <div>Recebido no Mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?php echo number_format($stats['total_recebido'], 2, ',', '.'); ?></div>
                <div>Total Recebido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pendentes']; ?></div>
                <div>Pagamentos Pendentes</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Histórico de Pagamentos</h3>
                <div class="btn-group">
                    <a href="?page=pagamentos" class="btn btn-sm btn-secondary">Todos</a>
                    <a href="?page=pagamentos&status=pago" class="btn btn-sm btn-success">Pagos</a>
                    <a href="?page=pagamentos&status=pendente" class="btn btn-sm btn-warning">Pendentes</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Escola</th>
                                <th>Plano</th>
                                <th>Valor</th>
                                <th>Referente</th>
                                <th>Vencimento</th>
                                <th>Pagamento</th>
                                <th>Método</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagamentos as $pagamento): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($pagamento['escola_nome']); ?></strong><br>
                                    <small><?php echo $pagamento['subdominio']; ?>.sige.com</small>
                                 </td>
                                 <td><?php echo $pagamento['plano_nome']; ?></td>
                                 <td>R$ <?php echo number_format($pagamento['valor'], 2, ',', '.'); ?></td>
                                 <td><?php echo $pagamento['referente']; ?></td>
                                 <td><?php echo date('d/m/Y', strtotime($pagamento['data_vencimento'])); ?></td>
                                 <td><?php echo $pagamento['data_pagamento'] ? date('d/m/Y', strtotime($pagamento['data_pagamento'])) : '-'; ?></td>
                                 <td>
                                    <?php
                                    $metodos = ['dinheiro' => 'Dinheiro', 'transferencia' => 'Transferência', 'deposito' => 'Depósito', 'cartao' => 'Cartão'];
                                    echo $metodos[$pagamento['metodo_pagamento']] ?? ucfirst($pagamento['metodo_pagamento']);
                                    ?>
                                  </td>
                                 <td>
                                    <span class="status-badge status-<?php echo $pagamento['status']; ?>">
                                        <?php echo ucfirst($pagamento['status']); ?>
                                    </span>
                                  </td>
                                 <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($pagamento['status'] == 'pendente'): ?>
                                        <a href="#" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalRegistrarPagamento<?php echo $pagamento['id']; ?>">
                                            <i class="fas fa-check"></i> Registrar
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($pagamento['comprovante']): ?>
                                        <a href="../uploads/comprovantes/<?php echo $pagamento['comprovante']; ?>" target="_blank" class="btn btn-info">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?page=pagamentos&action=delete&id=<?php echo $pagamento['id']; ?>" class="btn btn-danger" onclick="return confirm('Excluir pagamento?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                  </td>
                             </tr>
                             
                             <!-- Modal Registrar Pagamento -->
                             <div class="modal fade" id="modalRegistrarPagamento<?php echo $pagamento['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Registrar Pagamento - <?php echo htmlspecialchars($pagamento['escola_nome']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label>Valor</label>
                                                    <input type="text" class="form-control" value="R$ <?php echo number_format($pagamento['valor'], 2, ',', '.'); ?>" disabled>
                                                </div>
                                                <div class="mb-3">
                                                    <label>Status</label>
                                                    <select name="status" class="form-control">
                                                        <option value="pago">Pago</option>
                                                        <option value="cancelado">Cancelado</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label>Data do Pagamento</label>
                                                    <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label>Método de Pagamento</label>
                                                    <select name="metodo_pagamento" class="form-control">
                                                        <option value="dinheiro">Dinheiro</option>
                                                        <option value="transferencia">Transferência Bancária</option>
                                                        <option value="deposito">Depósito</option>
                                                        <option value="cartao">Cartão de Crédito</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label>Código da Transação</label>
                                                    <input type="text" name="codigo_transacao" class="form-control">
                                                </div>
                                                <div class="mb-3">
                                                    <label>Comprovante (PDF/Imagem)</label>
                                                    <input type="file" name="comprovante" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                                </div>
                                                <div class="mb-3">
                                                    <label>Observações</label>
                                                    <textarea name="observacoes" class="form-control" rows="2"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary">Registrar Pagamento</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() {
            $('#sidebar').toggleClass('open');
        });
    </script>
</body>
</html>