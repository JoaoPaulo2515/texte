<?php
// super-admin/assinaturas.php - Gestão de Assinaturas
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
// RENOVAR ASSINATURA
// ============================================
if ($action == 'renovar' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        $conn->beginTransaction();
        
        // Buscar assinatura atual
        $stmt = $conn->prepare("SELECT * FROM assinaturas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assinatura) {
            throw new Exception("Assinatura não encontrada.");
        }
        
        // Calcular nova data de fim
        $tipo = $assinatura['tipo_cobranca'];
        $nova_data_fim = ($tipo == 'mensal') 
            ? date('Y-m-d', strtotime('+1 month', strtotime($assinatura['data_fim'])))
            : date('Y-m-d', strtotime('+1 year', strtotime($assinatura['data_fim'])));
        
        // Atualizar assinatura
        $stmt = $conn->prepare("
            UPDATE assinaturas SET
                data_fim = :data_fim,
                status = 'ativa',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':data_fim' => $nova_data_fim
        ]);
        
        // Criar registro de pagamento pendente
        $stmt = $conn->prepare("
            INSERT INTO pagamentos (
                escola_id, assinatura_id, valor, referente,
                data_vencimento, status, created_at
            ) VALUES (
                :escola_id, :assinatura_id, :valor, :referente,
                :data_vencimento, 'pendente', NOW()
            )
        ");
        
        $stmt->execute([
            ':escola_id' => $assinatura['escola_id'],
            ':assinatura_id' => $id,
            ':valor' => $assinatura['valor'],
            ':referente' => date('F/Y', strtotime($nova_data_fim)),
            ':data_vencimento' => $nova_data_fim
        ]);
        
        $conn->commit();
        
        $message = "Assinatura renovada com sucesso até " . date('d/m/Y', strtotime($nova_data_fim));
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'renovar_assinatura', 'assinaturas', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// ============================================
// CANCELAR ASSINATURA
// ============================================
if ($action == 'cancelar' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        $stmt = $conn->prepare("
            UPDATE assinaturas SET
                status = 'cancelada',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        
        $message = "Assinatura cancelada com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'cancelar_assinatura', 'assinaturas', :registro_id, :ip, NOW())
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
// LISTAR ASSINATURAS
// ============================================
$escola_id = $_GET['escola'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "
    SELECT a.*, e.nome as escola_nome, e.subdominio, e.logo,
           p.nome as plano_nome, p.preco_mensal, p.preco_anual
    FROM assinaturas a
    JOIN escolas e ON e.id = a.escola_id
    JOIN planos p ON p.id = a.plano_id
    WHERE 1=1
";

$params = [];

if ($escola_id) {
    $query .= " AND a.escola_id = :escola_id";
    $params[':escola_id'] = $escola_id;
}
if ($status_filter) {
    $query .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY a.data_fim ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as total FROM assinaturas WHERE status = 'ativa'");
$stats['ativas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM assinaturas WHERE status = 'expirada'");
$stats['expiradas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) as total FROM assinaturas WHERE status = 'cancelada'");
$stats['canceladas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT SUM(valor) as total FROM assinaturas WHERE status = 'ativa'");
$stats['valor_total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinaturas - Super Admin | SIGE SaaS</title>
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
            border-radius: 15px 15px 0 0;
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
        
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-expirada { background: #ffebee; color: #d32f2f; }
        .status-cancelada { background: #fff3e0; color: #f57c00; }
        .status-pendente { background: #fff3e0; color: #f57c00; }
        
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
            <li class="nav-item"><a href="?page=assinaturas" class="nav-link active"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="?page=pagamentos" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="?page=suporte" class="nav-link"><i class="fas fa-headset"></i> Suporte</a></li>
            <li class="nav-item"><a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2><i class="fas fa-credit-card"></i> Assinaturas</h2>
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
                <div class="stat-value"><?php echo $stats['ativas']; ?></div>
                <div>Assinaturas Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['expiradas']; ?></div>
                <div>Assinaturas Expiradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['canceladas']; ?></div>
                <div>Assinaturas Canceladas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?php echo number_format($stats['valor_total'], 2, ',', '.'); ?></div>
                <div>Valor Mensal Total</div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Lista de Assinaturas</h3>
                <div class="btn-group">
                    <a href="?page=assinaturas" class="btn btn-sm btn-secondary">Todas</a>
                    <a href="?page=assinaturas&status=ativa" class="btn btn-sm btn-success">Ativas</a>
                    <a href="?page=assinaturas&status=expirada" class="btn btn-sm btn-danger">Expiradas</a>
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
                                <th>Tipo</th>
                                <th>Início</th>
                                <th>Fim</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assinaturas as $assinatura): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($assinatura['escola_nome']); ?></strong><br>
                                    <small><?php echo $assinatura['subdominio']; ?>.sige.com</small>
                                 </td>
                                 <td><?php echo $assinatura['plano_nome']; ?></td>
                                 <td>R$ <?php echo number_format($assinatura['valor'], 2, ',', '.'); ?></td>
                                 <td><?php echo ucfirst($assinatura['tipo_cobranca']); ?></td>
                                 <td><?php echo date('d/m/Y', strtotime($assinatura['data_inicio'])); ?></td>
                                 <td>
                                    <?php echo date('d/m/Y', strtotime($assinatura['data_fim'])); ?>
                                    <?php if (strtotime($assinatura['data_fim']) < time() && $assinatura['status'] == 'ativa'): ?>
                                        <span class="badge bg-danger">Expirada</span>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                    <span class="status-badge status-<?php echo $assinatura['status']; ?>">
                                        <?php echo ucfirst($assinatura['status']); ?>
                                    </span>
                                 </td>
                                 <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=pagamentos&assinatura=<?php echo $assinatura['id']; ?>" class="btn btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($assinatura['status'] == 'ativa'): ?>
                                        <a href="?page=assinaturas&action=renovar&id=<?php echo $assinatura['id']; ?>" class="btn btn-success" onclick="return confirm('Renovar assinatura?')">
                                            <i class="fas fa-sync"></i>
                                        </a>
                                        <a href="?page=assinaturas&action=cancelar&id=<?php echo $assinatura['id']; ?>" class="btn btn-warning" onclick="return confirm('Cancelar assinatura?')">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                             </tr>
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