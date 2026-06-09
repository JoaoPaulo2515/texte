<?php
// super-admin/assinaturas/index.php - Gestão de Assinaturas
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$status_filter = $_GET['status'] ?? '';
$escola_id = $_GET['escola'] ?? '';

$query = "
    SELECT a.*, e.nome as escola_nome, e.subdominio, e.logo, e.status as escola_status,
           p.nome as plano_nome, p.preco_mensal, p.preco_anual,
           (SELECT COUNT(*) FROM pagamentos WHERE assinatura_id = a.id AND status = 'pago') as total_pagamentos
    FROM assinaturas a
    JOIN escolas e ON e.id = a.escola_id
    JOIN planos p ON p.id = a.plano_id
    WHERE 1=1
";

$params = [];
if ($status_filter) {
    $query .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}
if ($escola_id) {
    $query .= " AND a.escola_id = :escola_id";
    $params[':escola_id'] = $escola_id;
}

$query .= " ORDER BY a.data_fim ASC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [];
$stmt = $conn->query("SELECT status, COUNT(*) as total FROM assinaturas GROUP BY status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stats[$row['status']] = $row['total'];
}
$stmt = $conn->query("SELECT SUM(valor) as total FROM assinaturas WHERE status = 'ativa'");
$stats['valor_total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Assinaturas a expirar nos próximos 30 dias
$stmt = $conn->prepare("
    SELECT a.*, e.nome as escola_nome, e.subdominio, p.nome as plano_nome
    FROM assinaturas a
    JOIN escolas e ON e.id = a.escola_id
    JOIN planos p ON p.id = a.plano_id
    WHERE a.status = 'ativa' AND a.data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY a.data_fim ASC
");
$stmt->execute();
$a_expirar = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinaturas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
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
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.2em;
            margin: 0;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.7;
            margin: 5px 0 0;
        }
        
        .user-info-sidebar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Navegação */
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
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
        
        .nav-link i {
            width: 20px;
            font-size: 1.1em;
        }
        
        /* Submenu */
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            margin: 0;
            display: none;
        }
        
        .nav-submenu.show {
            display: block;
        }
        
        .nav-submenu .nav-link {
            padding: 8px 25px;
            font-size: 0.9em;
        }
        
        .nav-item.has-submenu > .nav-link {
            position: relative;
        }
        
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        
        .nav-item.has-submenu.open > .nav-link:after {
            transform: rotate(180deg);
        }
        
        /* Main Content */
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
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
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
            color: #006B3E;
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
        
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }
        
        .warning-row { background-color: #fff3cd; }
        .danger-row { background-color: #f8d7da; }
        
        .modal-cancelar .modal-header {
            background: #dc3545;
            color: white;
            border-bottom: none;
        }
        .modal-cancelar .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .modal-cancelar .modal-footer {
            border-top: none;
        }
        .info-text-cancel {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
            <div class="user-info-sidebar">
                <small><i class="fas fa-user-shield"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Super Admin'; ?></small>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            
            <!-- Relatórios com Submenu -->
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
            <!-- Configurações com Submenu -->
            <li class="nav-item has-submenu" id="menuConfiguracoes">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-cog"></i> Configurações</a>
                <ul class="nav-submenu" id="submenuConfiguracoes">
                    <li class="nav-item"><a href="../config/sistema.php" class="nav-link"><i class="fas fa-globe"></i> Configurações do Sistema</a></li>
                    <li class="nav-item"><a href="../config/permissoes.php" class="nav-link"><i class="fas fa-lock"></i> Permissões e Papéis</a></li>
                </ul>
            </li>
            
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-credit-card"></i> Assinaturas</h2>
            <div class="btn-group">
                <a href="?status=ativa" class="btn btn-sm btn-success">Ativas</a>
                <a href="?status=expirada" class="btn btn-sm btn-danger">Expiradas</a>
                <a href="?status=cancelada" class="btn btn-sm btn-warning">Canceladas</a>
                <a href="index.php" class="btn btn-sm btn-secondary">Todas</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['ativa'] ?? 0; ?></div><div>Assinaturas Ativas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['expirada'] ?? 0; ?></div><div>Assinaturas Expiradas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['cancelada'] ?? 0; ?></div><div>Assinaturas Canceladas</div></div>
            <div class="stat-card"><div class="stat-value">KZ <?php echo number_format($stats['valor_total'], 2, ',', '.'); ?></div><div>Valor Mensal Total</div></div>
        </div>
        
        <?php if (!empty($a_expirar)): ?>
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="fas fa-clock"></i> Assinaturas a Expirar (Próximos 30 dias)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Escola</th><th>Plano</th><th>Valor</th><th>Data Fim</th><th>Dias Restantes</th><th>Ações</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($a_expirar as $a): 
                                $dias_restantes = ceil((strtotime($a['data_fim']) - time()) / 86400);
                            ?>
                            <tr class="<?php echo $dias_restantes <= 7 ? 'danger-row' : 'warning-row'; ?>">
                                <td><strong><?php echo htmlspecialchars($a['escola_nome']); ?></strong><br><small><?php echo $a['subdominio']; ?>.sige.ao</small></td>
                                <td><?php echo $a['plano_nome']; ?></td>
                                <td>KZ <?php echo number_format($a['valor'], 2, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($a['data_fim'])); ?></td>
                                <td><span class="badge bg-<?php echo $dias_restantes <= 7 ? 'danger' : 'warning'; ?>"><?php echo $dias_restantes; ?> dias</span></td>
                                <td><a href="renovar.php?id=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-sync"></i> Renovar</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Lista de Assinaturas</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Escola</th><th>Plano</th><th>Valor</th><th>Tipo</th><th>Início</th><th>Fim</th><th>Pagamentos</th><th>Status</th><th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assinaturas as $a): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($a['escola_nome']); ?></strong><br><small><?php echo $a['subdominio']; ?>.sige.ao</small></td>
                                <td><?php echo $a['plano_nome']; ?></td>
                                <td>KZ <?php echo number_format($a['valor'], 2, ',', '.'); ?></td>
                                <td><?php echo $a['tipo_cobranca'] == 'mensal' ? 'Mensal' : 'Anual'; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($a['data_inicio'])); ?></td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($a['data_fim'])); ?>
                                    <?php if (strtotime($a['data_fim']) < time() && $a['status'] == 'ativa'): ?>
                                        <span class="badge bg-danger">Expirada</span>
                                    <?php endif; ?>
                                  </div>
                                 </td>
                                <td><?php echo $a['total_pagamentos']; ?> / 12</small></td>
                                <td><span class="status-badge status-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="renovar.php?id=<?php echo $a['id']; ?>" class="btn btn-success" title="Renovar"><i class="fas fa-sync"></i></a>
                                        <button type="button" class="btn btn-danger" title="Cancelar" data-bs-toggle="modal" data-bs-target="#modalCancelarAssinatura" 
                                                data-id="<?php echo $a['id']; ?>"
                                                data-escola="<?php echo htmlspecialchars($a['escola_nome']); ?>"
                                                data-subdominio="<?php echo $a['subdominio']; ?>"
                                                data-plano="<?php echo $a['plano_nome']; ?>"
                                                data-valor="<?php echo number_format($a['valor'], 2, ',', '.'); ?>"
                                                data-data-fim="<?php echo date('d/m/Y', strtotime($a['data_fim'])); ?>">
                                            <i class="fas fa-ban"></i> Cancelar
                                        </button>
                                        <a href="../pagamentos/index.php?assinatura=<?php echo $a['id']; ?>" class="btn btn-info" title="Ver Pagamentos"><i class="fas fa-eye"></i></a>
                                    </div>
                                  </div>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                            <?php if (empty($assinaturas)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Nenhuma assinatura encontrada</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Cancelar Assinatura -->
    <div class="modal fade modal-cancelar" id="modalCancelarAssinatura" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Cancelar Assinatura
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-ban fa-4x text-danger"></i>
                    </div>
                    <p class="text-center">
                        <strong>Tem certeza absoluta que deseja cancelar esta assinatura?</strong>
                    </p>
                    
                    <div class="info-text-cancel" id="infoAssinaturaCancel">
                        <p><strong>📋 Detalhes da Assinatura:</strong></p>
                        <p><strong>Escola:</strong> <span id="cancel_escola"></span><br>
                        <strong>Subdomínio:</strong> <span id="cancel_subdominio"></span>.sige.ao<br>
                        <strong>Plano:</strong> <span id="cancel_plano"></span><br>
                        <strong>Valor:</strong> <span id="cancel_valor"></span><br>
                        <strong>Expira em:</strong> <span id="cancel_data_fim"></span></p>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-skull-crosswalk"></i>
                        <strong>Esta ação é irreversível!</strong><br>
                        Ao confirmar, a escola perderá acesso ao sistema imediatamente.
                    </div>
                    
                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="confirmarCancelamentoCheckbox">
                        <label class="form-check-label" for="confirmarCancelamentoCheckbox">
                            Confirmo que desejo cancelar esta assinatura permanentemente
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Não, Voltar
                    </button>
                    <a href="#" class="btn btn-danger" id="btnConfirmarCancelamento" disabled>
                        <i class="fas fa-ban"></i> Sim, Cancelar Assinatura
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        let assinaturaIdParaCancelar = null;
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Quando o modal for aberto, carregar os dados da assinatura
        $('#modalCancelarAssinatura').on('show.bs.modal', function(event) {
            const button = $(event.relatedTarget);
            assinaturaIdParaCancelar = button.data('id');
            
            $('#cancel_escola').text(button.data('escola'));
            $('#cancel_subdominio').text(button.data('subdominio'));
            $('#cancel_plano').text(button.data('plano'));
            $('#cancel_valor').text('KZ ' + button.data('valor'));
            $('#cancel_data_fim').text(button.data('data-fim'));
            
            // Atualizar o link do botão de confirmação
            $('#btnConfirmarCancelamento').attr('href', 'cancelar.php?id=' + assinaturaIdParaCancelar + '&confirm=yes');
            
            // Resetar checkbox e botão
            $('#confirmarCancelamentoCheckbox').prop('checked', false);
            $('#btnConfirmarCancelamento').prop('disabled', true);
        });
        
        // Habilitar botão de confirmação apenas quando checkbox estiver marcado
        $('#confirmarCancelamentoCheckbox').change(function() {
            $('#btnConfirmarCancelamento').prop('disabled', !$(this).is(':checked'));
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) {
            $('#menuRelatorios').addClass('open');
            $('#submenuRelatorios').addClass('show');
        }
        if (currentPage.includes('config')) {
            $('#menuConfiguracoes').addClass('open');
            $('#submenuConfiguracoes').addClass('show');
        }
    </script>
</body>
</html>