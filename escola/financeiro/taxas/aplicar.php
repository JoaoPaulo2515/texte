<?php
// escola/financeiro/taxas/aplicar.php - Aplicar Taxas Automaticamente
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR APLICAÇÃO DE TAXAS
// ============================================

$mensagem = '';
$erro = '';
$resultado = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'aplicar_taxas') {
    $tipo_taxa = $_POST['tipo_taxa'] ?? 'multa_juros';
    $data_referencia = $_POST['data_referencia'] ?? date('Y-m-d');
    $aplicar_multas = isset($_POST['aplicar_multas']);
    $aplicar_juros = isset($_POST['aplicar_juros']);
    
    // Buscar taxas ativas
    $stmt = $conn->prepare("
        SELECT * FROM escola_taxas 
        WHERE escola_id = :escola_id AND status = 'ativo'
            AND (data_inicio IS NULL OR data_inicio <= :data)
            AND (data_fim IS NULL OR data_fim >= :data)
    ");
    $stmt->execute([':escola_id' => $escola_id, ':data' => $data_referencia]);
    $taxas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar mensalidades vencidas
    $stmt = $conn->prepare("
        SELECT m.*, e.nome as aluno_nome, e.matricula
        FROM escola_mensalidades m
        JOIN estudantes e ON e.id = m.aluno_id
        WHERE m.escola_id = :escola_id 
            AND m.status = 'pendente' 
            AND m.data_vencimento < :data
            AND (m.multa = 0 OR m.juros = 0)
        ORDER BY m.data_vencimento ASC
    ");
    $stmt->execute([':escola_id' => $escola_id, ':data' => $data_referencia]);
    $mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_aplicacoes = 0;
    $total_valor_multas = 0;
    $total_valor_juros = 0;
    
    foreach ($mensalidades as $mensalidade) {
        $dias_atraso = (strtotime($data_referencia) - strtotime($mensalidade['data_vencimento'])) / 86400;
        $multa = 0;
        $juros = 0;
        
        // Aplicar multa
        if ($aplicar_multas) {
            foreach ($taxas as $taxa) {
                if ($taxa['tipo'] == 'multa') {
                    if ($taxa['aplicacao'] == 'fixo') {
                        $multa = $taxa['valor'];
                    } else {
                        $multa = $mensalidade['valor_original'] * ($taxa['percentual'] / 100);
                    }
                    break;
                }
            }
        }
        
        // Aplicar juros
        if ($aplicar_juros) {
            foreach ($taxas as $taxa) {
                if ($taxa['tipo'] == 'juros') {
                    if ($taxa['aplicacao'] == 'fixo') {
                        $juros = $taxa['valor'] * ($dias_atraso / 30);
                    } else {
                        $juros = $mensalidade['valor_original'] * ($taxa['percentual'] / 100) * ($dias_atraso / 30);
                    }
                    break;
                }
            }
        }
        
        if ($multa > 0 || $juros > 0) {
            $stmt_upd = $conn->prepare("
                UPDATE escola_mensalidades 
                SET multa = :multa, juros = :juros
                WHERE id = :id
            ");
            $stmt_upd->execute([
                ':multa' => round($multa, 2),
                ':juros' => round($juros, 2),
                ':id' => $mensalidade['id']
            ]);
            
            $total_aplicacoes++;
            $total_valor_multas += $multa;
            $total_valor_juros += $juros;
            
            $resultado[] = [
                'aluno' => $mensalidade['aluno_nome'],
                'matricula' => $mensalidade['matricula'],
                'dias_atraso' => $dias_atraso,
                'multa' => $multa,
                'juros' => $juros
            ];
        }
    }
    
    if ($total_aplicacoes > 0) {
        $mensagem = "Taxas aplicadas com sucesso! $total_aplicacoes mensalidade(s) atualizada(s).";
        
        // Registrar log
        $stmt = $conn->prepare("
            INSERT INTO logs_usuarios (usuario_id, acao, descricao, ip, created_at)
            VALUES (:usuario_id, 'aplicar_taxas', :descricao, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':descricao' => "Aplicação automática de taxas - Multas: " . number_format($total_valor_multas, 2) . " Kz, Juros: " . number_format($total_valor_juros, 2) . " Kz",
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
    } else {
        $erro = "Nenhuma mensalidade pendente encontrada para aplicação de taxas.";
    }
}

// ============================================
// BUSCAR DADOS PARA EXIBIÇÃO
// ============================================

// Buscar taxas ativas
$stmt = $conn->prepare("SELECT * FROM escola_taxas WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$taxas_ativas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar estatísticas de mensalidades vencidas
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_vencidas,
        SUM(valor_original - valor_pago) as valor_total_pendente,
        AVG(DATEDIFF(CURDATE(), data_vencimento)) as media_dias_atraso
    FROM escola_mensalidades
    WHERE escola_id = :escola_id AND status = 'pendente' AND data_vencimento < CURDATE()
");
$stmt->execute([':escola_id' => $escola_id]);
$estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);

$tipos_taxa = [
    'multa' => 'Multa por Atraso',
    'juros' => 'Juros Moratórios'
];

$mensagem = $_SESSION['mensagem'] ?? $mensagem;
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplicar Taxas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
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
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .badge-multa { background: #fd7e14; color: white; }
        .badge-juros { background: #20c997; color: white; }
        
        .table-responsive { overflow-x: auto; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .resultado-item { border-left: 3px solid #28a745; margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 8px; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> <span>Financeiro</span>
                </a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-percent"></i> Taxas</a></li>
                    <li class="nav-item"><a href="aplicar.php" class="nav-link active"><i class="fas fa-play"></i> Aplicar Taxas</a></li>
                    <li class="nav-item"><a href="isencoes.php" class="nav-link"><i class="fas fa-gift"></i> Isenções</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-play"></i> Aplicar Taxas Automaticamente
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $estatisticas['total_vencidas'] ?? 0; ?></div>
                <div class="stat-label">Mensalidades Vencidas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($estatisticas['valor_total_pendente'] ?? 0, 2, ',', '.'); ?> Kz</div>
                <div class="stat-label">Valor Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($estatisticas['media_dias_atraso'] ?? 0); ?> dias</div>
                <div class="stat-label">Média de Atraso</div>
            </div>
        </div>
        
        <!-- Taxas Configuradas -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="fas fa-percent"></i> Taxas Configuradas
            </div>
            <div class="card-body">
                <?php if (empty($taxas_ativas)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Nenhuma taxa ativa configurada. <a href="configurar.php">Clique aqui</a> para configurar.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Nome</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Período</th>
                                </thead>
                                <tbody>
                                    <?php foreach ($taxas_ativas as $taxa): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($taxa['nome']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $taxa['tipo'] == 'multa' ? 'badge-multa' : 'badge-juros'; ?>">
                                                <?php echo ucfirst($taxa['tipo']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($taxa['aplicacao'] == 'fixo'): ?>
                                                <?php echo number_format($taxa['valor'], 2, ',', '.'); ?> Kz
                                            <?php else: ?>
                                                <?php echo $taxa['percentual']; ?>% do valor
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $taxa['periodo'] ?? 'Sempre'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Formulário de Aplicação -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-calculator"></i> Aplicar Multas e Juros
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="acao" value="aplicar_taxas">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Data de Referência</label>
                                <input type="date" name="data_referencia" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                <small class="text-muted">Data base para cálculo de atraso</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Tipo de Taxa</label>
                                <select name="tipo_taxa" class="form-control">
                                    <option value="multa_juros">Multa + Juros</option>
                                    <option value="multa">Apenas Multa</option>
                                    <option value="juros">Apenas Juros</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="aplicar_multas" class="form-check-input" id="aplicar_multas" checked>
                                    <label class="form-check-label" for="aplicar_multas">
                                        Aplicar Multas
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="aplicar_juros" class="form-check-input" id="aplicar_juros" checked>
                                    <label class="form-check-label" for="aplicar_juros">
                                        Aplicar Juros
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção!</strong> Esta ação irá calcular e aplicar multas e juros para todas as mensalidades vencidas até a data de referência.
                            Recomenda-se fazer backup antes de aplicar.
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Tem certeza que deseja aplicar as taxas?')">
                                <i class="fas fa-play"></i> Aplicar Taxas
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Resultado da Aplicação -->
            <?php if (!empty($resultado)): ?>
            <div class="card mt-4">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-check-circle"></i> Resultado da Aplicação
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="tabelaResultado">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Dias em Atraso</th>
                                    <th>Multa (Kz)</th>
                                    <th>Juros (Kz)</th>
                                    <th>Total (Kz)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_geral = 0;
                                foreach ($resultado as $r): 
                                    $total = $r['multa'] + $r['juros'];
                                    $total_geral += $total;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['aluno']); ?></td>
                                    <td><?php echo $r['matricula']; ?></td>
                                    <td><?php echo $r['dias_atraso']; ?> dias</td>
                                    <td class="text-end"><?php echo number_format($r['multa'], 2, ',', '.'); ?> Kz</td>
                                    <td class="text-end"><?php echo number_format($r['juros'], 2, ',', '.'); ?> Kz</td>
                                    <td class="text-end"><strong><?php echo number_format($total, 2, ',', '.'); ?> Kz</strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary">
                                <tr>
                                    <th colspan="5" class="text-end">TOTAL GERAL</th>
                                    <th class="text-end"><?php echo number_format($total_geral, 2, ',', '.'); ?> Kz</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Modal de Ajuda -->
        <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-question-circle"></i> Aplicação Automática de Taxas</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <h6><i class="fas fa-info-circle text-primary"></i> Como funciona?</h6>
                        <p>A aplicação automática calcula multas e juros para todas as mensalidades vencidas com base nas taxas configuradas.</p>
                        
                        <h6><i class="fas fa-calculator text-warning"></i> Cálculos realizados:</h6>
                        <ul>
                            <li><strong>Multa:</strong> Valor fixo ou percentual sobre o valor original.</li>
                            <li><strong>Juros:</strong> Calculados proporcionalmente aos dias de atraso.</li>
                            <li><strong>Fórmula de Juros:</strong> Valor × (Taxa%/100) × (Dias atraso/30)</li>
                        </ul>
                        
                        <h6><i class="fas fa-lightbulb text-info"></i> Recomendações:</h6>
                        <ul>
                            <li>Verifique as taxas antes de aplicar.</li>
                            <li>Faça backup dos dados antes da aplicação.</li>
                            <li>Aplique as taxas periodicamente (ex: todo dia 10).</li>
                            <li>Revise os resultados após a aplicação.</li>
                        </ul>
                        
                        <hr>
                        <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
        
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
            
            $('#tabelaResultado').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
                pageLength: 25,
                order: [[0, 'asc']]
            });
            
            if (window.location.pathname.includes('financeiro')) {
                $('#menuFinanceiro').addClass('open');
                $('#submenuFinanceiro').addClass('show');
            }
        </script>
    </body>
    </html>