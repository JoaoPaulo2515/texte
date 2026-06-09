<?php
// super-admin/assinaturas/cancelar.php - Cancelar assinatura
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar assinatura
$stmt = $conn->prepare("
    SELECT a.*, e.nome as escola_nome, e.subdominio, e.email as escola_email,
           p.nome as plano_nome
    FROM assinaturas a
    JOIN escolas e ON e.id = a.escola_id
    JOIN planos p ON p.id = a.plano_id
    WHERE a.id = :id
");
$stmt->execute([':id' => $id]);
$assinatura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assinatura) {
    header('Location: index.php?error=Assinatura não encontrada');
    exit;
}

$error = '';
$success = '';

// Processar cancelamento via POST (modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_cancelamento'])) {
    try {
        $conn->beginTransaction();
        
        // Atualizar status da assinatura
        $stmt = $conn->prepare("
            UPDATE assinaturas SET
                status = 'cancelada',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        
        // Se a escola estiver ativa, mudar status para suspensa
        $stmt = $conn->prepare("
            UPDATE escolas SET
                status = 'suspensa',
                updated_at = NOW()
            WHERE id = :escola_id AND status = 'ativa'
        ");
        $stmt->execute([':escola_id' => $assinatura['escola_id']]);
        
        $conn->commit();
        
        $success = "Assinatura cancelada com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, dados_depois, ip, created_at)
            VALUES (:usuario_id, 'cancelar_assinatura', 'assinaturas', :registro_id, :dados, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':dados' => json_encode(['status' => 'cancelada']),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        header("refresh:2;url=index.php");
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelar Assinatura | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-submenu .nav-link { padding: 8px 25px; font-size: 0.9em; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: #dc3545; color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .warning-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .btn-danger { background: #dc3545; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 150px; font-weight: 600; }
        
        /* Modal Personalizada */
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
        .warning-text {
            color: #dc3545;
            font-weight: bold;
        }
        .info-text {
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
            
            <li class="nav-item has-submenu" id="menuRelatorios">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)"><i class="fas fa-chart-line"></i> Relatórios</a>
                <ul class="nav-submenu" id="submenuRelatorios">
                    <li class="nav-item"><a href="../relatorios/escolas.php" class="nav-link"><i class="fas fa-school"></i> Relatório de Escolas</a></li>
                    <li class="nav-item"><a href="../relatorios/estatisticas.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas Gerais</a></li>
                    <li class="nav-item"><a href="../relatorios/financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Relatório Financeiro</a></li>
                </ul>
            </li>
            
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
            <h2><i class="fas fa-ban"></i> Cancelar Assinatura</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Atenção!</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?> Redirecionando...</div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                <div class="warning-box">
                    <h5><i class="fas fa-skull-crosswalk"></i> Você está prestes a cancelar a assinatura de:</h5>
                    <div class="info-row"><div class="info-label">Escola:</div><div><strong><?php echo htmlspecialchars($assinatura['escola_nome']); ?></strong></div></div>
                    <div class="info-row"><div class="info-label">Subdomínio:</div><div><?php echo $assinatura['subdominio']; ?>.sige.ao</div></div>
                    <div class="info-row"><div class="info-label">Plano:</div><div><?php echo $assinatura['plano_nome']; ?></div></div>
                    <div class="info-row"><div class="info-label">Valor:</div><div>KZ <?php echo number_format($assinatura['valor'], 2, ',', '.'); ?></div></div>
                    <div class="info-row"><div class="info-label">Expira em:</div><div><?php echo date('d/m/Y', strtotime($assinatura['data_fim'])); ?></div></div>
                </div>
                
                <div class="alert alert-danger">
                    <i class="fas fa-skull-crosswalk"></i>
                    <strong>AVISO IMPORTANTE:</strong> Ao cancelar esta assinatura:
                    <ul class="mb-0 mt-2">
                        <li>A escola será suspensa imediatamente</li>
                        <li>O acesso ao sistema será bloqueado</li>
                        <li>Os dados permanecerão armazenados por 30 dias</li>
                        <li>Após 30 dias, os dados poderão ser permanentemente excluídos</li>
                    </ul>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Alternativas:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Se a escola está temporariamente inativa, considere apenas <strong>suspender</strong> a escola</li>
                        <li>Se deseja manter os dados, considere fazer um <strong>backup</strong> antes de cancelar</li>
                        <li>Entre em contato com o responsável da escola antes de cancelar</li>
                    </ul>
                </div>
                
                <div class="text-center mt-4">
                    <button type="button" class="btn btn-danger btn-lg px-5" data-bs-toggle="modal" data-bs-target="#modalConfirmarCancelamento">
                        <i class="fas fa-ban"></i> Cancelar Assinatura
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação de Cancelamento -->
    <div class="modal fade modal-cancelar" id="modalConfirmarCancelamento" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Confirmar Cancelamento
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
                    
                    <div class="info-text">
                        <p><strong>📋 Resumo da Assinatura:</strong></p>
                        <p><strong>Escola:</strong> <?php echo htmlspecialchars($assinatura['escola_nome']); ?><br>
                        <strong>Plano:</strong> <?php echo $assinatura['plano_nome']; ?><br>
                        <strong>Valor:</strong> KZ <?php echo number_format($assinatura['valor'], 2, ',', '.'); ?><br>
                        <strong>Expira em:</strong> <?php echo date('d/m/Y', strtotime($assinatura['data_fim'])); ?></p>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-skull-crosswalk"></i>
                        <strong>Esta ação é irreversível!</strong><br>
                        Ao confirmar, a escola perderá acesso ao sistema imediatamente.
                    </div>
                    
                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="confirmarCheckbox">
                        <label class="form-check-label" for="confirmarCheckbox">
                            Confirmo que desejo cancelar esta assinatura permanentemente
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Não, Voltar
                    </button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarCancelamento" disabled>
                        <i class="fas fa-ban"></i> Sim, Cancelar Assinatura
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        // Habilitar botão de confirmação apenas quando checkbox estiver marcado
        $('#confirmarCheckbox').change(function() {
            $('#btnConfirmarCancelamento').prop('disabled', !$(this).is(':checked'));
        });
        
        // Processar cancelamento via AJAX
        $('#btnConfirmarCancelamento').click(function() {
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processando...');
            
            $.ajax({
                url: 'cancelar.php',
                method: 'POST',
                data: {
                    confirmar_cancelamento: 1,
                    id: <?php echo $id; ?>
                },
                success: function(response) {
                    // Fechar modal
                    $('#modalConfirmarCancelamento').modal('hide');
                    // Mostrar mensagem de sucesso
                    $('.card-body').prepend('<div class="alert alert-success">Assinatura cancelada com sucesso! Redirecionando...</div>');
                    // Redirecionar após 2 segundos
                    setTimeout(function() {
                        window.location.href = 'index.php';
                    }, 2000);
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="fas fa-ban"></i> Sim, Cancelar Assinatura');
                    alert('Erro ao cancelar assinatura. Tente novamente.');
                }
            });
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>