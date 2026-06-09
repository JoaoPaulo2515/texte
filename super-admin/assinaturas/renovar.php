<?php
// super-admin/assinaturas/renovar.php - Renovar assinatura
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
           p.nome as plano_nome, p.preco_mensal, p.preco_anual
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_cobranca = $_POST['tipo_cobranca'] ?? $assinatura['tipo_cobranca'];
    $meses = $_POST['meses'] ?? 12;
    
    try {
        $conn->beginTransaction();
        
        // Calcular novo valor e data
        if ($tipo_cobranca == 'mensal') {
            $valor = $assinatura['preco_mensal'];
            $nova_data_fim = date('Y-m-d', strtotime("+{$meses} months", strtotime($assinatura['data_fim'])));
        } else {
            $valor = $assinatura['preco_anual'];
            $nova_data_fim = date('Y-m-d', strtotime('+1 year', strtotime($assinatura['data_fim'])));
        }
        
        // Atualizar assinatura
        $stmt = $conn->prepare("
            UPDATE assinaturas SET
                tipo_cobranca = :tipo_cobranca,
                valor = :valor,
                data_fim = :data_fim,
                status = 'ativa',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':tipo_cobranca' => $tipo_cobranca,
            ':valor' => $valor,
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
            ':valor' => $valor,
            ':referente' => date('F/Y', strtotime($nova_data_fim)),
            ':data_vencimento' => $nova_data_fim
        ]);
        
        $conn->commit();
        
        $success = "Assinatura renovada com sucesso até " . date('d/m/Y', strtotime($nova_data_fim));
        
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
        
        // Recarregar dados
        $stmt = $conn->prepare("
            SELECT a.*, e.nome as escola_nome, e.subdominio,
                   p.nome as plano_nome, p.preco_mensal, p.preco_anual
            FROM assinaturas a
            JOIN escolas e ON e.id = a.escola_id
            JOIN planos p ON p.id = a.plano_id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);
        
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
    <title>Renovar Assinatura | SIGE Angola</title>
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
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 150px; font-weight: 600; }
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
            <h2><i class="fas fa-sync"></i> Renovar Assinatura</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><?php echo htmlspecialchars($assinatura['escola_nome']); ?></h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Escola:</div><div><?php echo htmlspecialchars($assinatura['escola_nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">Subdomínio:</div><div><?php echo $assinatura['subdominio']; ?>.sige.ao</div></div>
                        <div class="info-row"><div class="info-label">Plano Atual:</div><div><?php echo $assinatura['plano_nome']; ?></div></div>
                        <div class="info-row"><div class="info-label">Data de Início:</div><div><?php echo date('d/m/Y', strtotime($assinatura['data_inicio'])); ?></div></div>
                        <div class="info-row"><div class="info-label">Data de Expiração:</div><div><?php echo date('d/m/Y', strtotime($assinatura['data_fim'])); ?></div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><div class="info-label">Valor Mensal:</div><div>KZ <?php echo number_format($assinatura['preco_mensal'], 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Valor Anual:</div><div>KZ <?php echo number_format($assinatura['preco_anual'], 2, ',', '.'); ?></div></div>
                        <div class="info-row"><div class="info-label">Status:</div><div><span class="badge bg-<?php echo $assinatura['status'] == 'ativa' ? 'success' : 'warning'; ?>"><?php echo ucfirst($assinatura['status']); ?></span></div></div>
                    </div>
                </div>
                
                <hr>
                
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tipo de Cobrança</label>
                                <select name="tipo_cobranca" class="form-control" id="tipoCobranca">
                                    <option value="mensal" <?php echo $assinatura['tipo_cobranca'] == 'mensal' ? 'selected' : ''; ?>>Mensal</option>
                                    <option value="anual" <?php echo $assinatura['tipo_cobranca'] == 'anual' ? 'selected' : ''; ?>>Anual (10% desconto)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Período de Renovação</label>
                                <select name="meses" class="form-control" id="meses">
                                    <option value="1">1 mês</option>
                                    <option value="3">3 meses</option>
                                    <option value="6">6 meses</option>
                                    <option value="12" selected>12 meses</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Resumo da Renovação:</strong><br>
                        <span id="resumoValor">Valor: KZ <?php echo number_format($assinatura['preco_mensal'], 2, ',', '.'); ?></span><br>
                        <span id="resumoData">Nova data de expiração: <?php echo date('d/m/Y', strtotime('+12 months', strtotime($assinatura['data_fim']))); ?></span>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Após a renovação, será gerado um novo pagamento pendente. O responsável da escola será notificado por e-mail.
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-check"></i> Confirmar Renovação
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
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
        
        $('#tipoCobranca, #meses').on('change', function() {
            let tipo = $('#tipoCobranca').val();
            let meses = $('#meses').val();
            let valorMensal = <?php echo $assinatura['preco_mensal']; ?>;
            let valorAnual = <?php echo $assinatura['preco_anual']; ?>;
            let dataFim = '<?php echo $assinatura['data_fim']; ?>';
            
            let valor, novaData;
            if (tipo == 'mensal') {
                valor = valorMensal * meses;
                novaData = new Date(dataFim);
                novaData.setMonth(novaData.getMonth() + parseInt(meses));
            } else {
                valor = valorAnual;
                novaData = new Date(dataFim);
                novaData.setFullYear(novaData.getFullYear() + 1);
            }
            
            $('#resumoValor').text('Valor: KZ ' + valor.toLocaleString('pt-AO', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            $('#resumoData').text('Nova data de expiração: ' + novaData.toLocaleDateString('pt-BR'));
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('relatorios')) { $('#menuRelatorios').addClass('open'); $('#submenuRelatorios').addClass('show'); }
        if (currentPage.includes('config')) { $('#menuConfiguracoes').addClass('open'); $('#submenuConfiguracoes').addClass('show'); }
    </script>
</body>
</html>