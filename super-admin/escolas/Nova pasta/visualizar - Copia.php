<?php
// super-admin/escolas/visualizar.php - Visualizar detalhes da escola
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar dados da escola
$stmt = $conn->prepare("
    SELECT e.*, p.nome as plano_nome, p.preco_mensal, p.preco_anual,
           (SELECT COUNT(*) FROM usuarios WHERE escola_id = e.id) as total_usuarios,
           (SELECT COUNT(*) FROM assinaturas WHERE escola_id = e.id AND status = 'ativa') as tem_assinatura
    FROM escolas e
    LEFT JOIN planos p ON p.id = e.plano_id
    WHERE e.id = :id
");
$stmt->execute([':id' => $id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$escola) {
    header('Location: index.php?error=Escola não encontrada');
    exit;
}

// Buscar assinaturas da escola
$stmt = $conn->prepare("
    SELECT a.*, p.nome as plano_nome
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    WHERE a.escola_id = :id
    ORDER BY a.created_at DESC
");
$stmt->execute([':id' => $id]);
$assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar pagamentos da escola
$stmt = $conn->prepare("
    SELECT p.*, a.tipo_cobranca
    FROM pagamentos p
    JOIN assinaturas a ON a.id = p.assinatura_id
    WHERE p.escola_id = :id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute([':id' => $id]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuários da escola
$stmt = $conn->prepare("
    SELECT * FROM usuarios
    WHERE escola_id = :id
    ORDER BY created_at DESC
");
$stmt->execute([':id' => $id]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Províncias de Angola
$provincias = [
    'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 
    'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 
    'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 
    'Namibe', 'Uíge', 'Zaire'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($escola['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-ativa { background: #e8f5e9; color: #388e3c; }
        .status-suspensa { background: #fff3e0; color: #f57c00; }
        .status-trial { background: #e3f2fd; color: #1976d2; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 180px; font-weight: 600; color: #555; }
        .info-value { flex: 1; color: #333; }
        .logo-grande { width: 120px; height: 120px; border-radius: 15px; object-fit: cover; border: 2px solid #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola['nome']); ?></h2>
                <small><?php echo $escola['subdominio']; ?>.sige.ao</small>
            </div>
            <div>
                <a href="editar.php?id=<?php echo $escola['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center p-4">
                    <?php if ($escola['logo']): ?>
                        <img src="../../uploads/escolas/<?php echo $escola['logo']; ?>" class="logo-grande mx-auto mb-3">
                    <?php else: ?>
                        <div class="logo-grande mx-auto mb-3 bg-light d-flex align-items-center justify-content-center">
                            <i class="fas fa-school fa-4x text-muted"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($escola['nome']); ?></h4>
                    <p class="text-muted"><?php echo $escola['subdominio']; ?>.sige.ao</p>
                    <span class="status-badge status-<?php echo $escola['status']; ?> mx-auto"><?php echo ucfirst($escola['status']); ?></span>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Resumo Financeiro</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Plano Atual:</div><div class="info-value"><?php echo $escola['plano_nome'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Valor Mensal:</div><div class="info-value">KZ <?php echo number_format($escola['preco_mensal'] ?? 0, 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Total Pago:</div><div class="info-value">KZ <?php echo number_format(array_sum(array_column($pagamentos, 'valor')), 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Último Pagamento:</div><div class="info-value"><?php echo $pagamentos ? date('d/m/Y', strtotime($pagamentos[0]['created_at'])) : '-'; ?></div></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-building"></i> Dados da Instituição</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">NUIT/NIF:</div><div class="info-value"><?php echo $escola['nuit'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Ano de Fundação:</div><div class="info-value"><?php echo $escola['ano_fundacao'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['email']); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo $escola['telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Celular:</div><div class="info-value"><?php echo $escola['celular'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo htmlspecialchars($escola['endereco'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Província:</div><div class="info-value"><?php echo $escola['provincia'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Município:</div><div class="info-value"><?php echo $escola['municipio'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Comuna:</div><div class="info-value"><?php echo $escola['comuna'] ?? '-'; ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-tie"></i> Responsável Legal</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($escola['responsavel_nome'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo htmlspecialchars($escola['responsavel_email'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo $escola['responsavel_telefone'] ?? '-'; ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-users"></i> Usuários da Escola</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Nome</th><th>E-mail</th><th>Tipo</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($usuario['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $usuario['tipo'])); ?></td>
                                        <td><span class="badge bg-<?php echo $usuario['status'] == 'ativo' ? 'success' : 'danger'; ?>"><?php echo ucfirst($usuario['status']); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>