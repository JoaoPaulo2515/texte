<?php
// escola/config/pagamento/recibos.php - Recibos
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar recibos
$recibos = $conn->prepare("
    SELECT p.*, e.nome as aluno_nome, e.matricula, f.nome as forma_nome, u.nome as usuario_nome
    FROM escola_pagamentos p
    JOIN estudantes e ON e.id = p.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN escola_formas_pagamento f ON f.id = p.forma_pagamento_id
    WHERE p.escola_id = :escola_id
    ORDER BY p.created_at DESC
");
$recibos->execute([':escola_id' => $escola_id]);
$recibos = $recibos->fetchAll(PDO::FETCH_ASSOC);

// Buscar informações da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .recibo-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .recibo-header { border-bottom: 2px solid #006B3E; padding-bottom: 10px; margin-bottom: 15px; }
        .table-responsive { overflow-x: auto; }
        .btn-imprimir { background: #17a2b8; color: white; }
        .btn-pdf { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><div class="logo"><i class="fas fa-chalkboard-user"></i></div><h3>SIGE Angola</h3><p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p></div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuConfiguracoes">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)"><i class="fas fa-cogs"></i> Configurações</a>
                <ul class="nav-submenu show" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                    <li class="nav-item"><a href="../banco/contas.php" class="nav-link"><i class="fas fa-university"></i> Banco</a></li>
                    <li class="nav-item"><a href="formas.php" class="nav-link"><i class="fas fa-credit-card"></i> Forma de Pagamento</a></li>
                    <li class="nav-item"><a href="../sistema/index.php" class="nav-link"><i class="fas fa-chalkboard"></i> Abrir Sistema</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-receipt"></i> Recibos de Pagamento</h2>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Recibos</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaRecibos">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Data</th><th>Aluno</th><th>Matrícula</th><th>Forma</th><th>Valor</th><th>Desconto</th><th>Multa</th><th>Total Pago</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recibos as $recibo): ?>
                            <tr>
                                <td><?php echo $recibo['id']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($recibo['data_pagamento'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($recibo['aluno_nome']); ?></strong></td>
                                <td><?php echo $recibo['matricula']; ?></td>
                                <td><?php echo $recibo['forma_nome'] ?? 'N/A'; ?></td>
                                <td><?php echo number_format($recibo['valor'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($recibo['desconto'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($recibo['multa'], 2, ',', '.'); ?> Kz</div>
                                <td><strong><?php echo number_format($recibo['valor_pago'], 2, ',', '.'); ?> Kz</strong></div>
                                <td>
                                    <button class="btn btn-sm btn-imprimir" onclick="imprimirRecibo(<?php echo $recibo['id']; ?>)"><i class="fas fa-print"></i></button>
                                    <button class="btn btn-sm btn-pdf" onclick="gerarPDF(<?php echo $recibo['id']; ?>)"><i class="fas fa-file-pdf"></i></button>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
        $('#tabelaRecibos').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, pageLength: 25, order: [[0, 'desc']] });
        function imprimirRecibo(id) { window.open('recibo_imprimir.php?id=' + id, '_blank', 'width=800,height=600'); }
        function gerarPDF(id) { window.open('recibo_pdf.php?id=' + id, '_blank'); }
    </script>
</body>
</html>