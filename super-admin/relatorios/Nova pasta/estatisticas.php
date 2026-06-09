<?php
// super-admin/relatorios/estatisticas.php - Estatísticas Gerais do Sistema
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$ano = $_GET['ano'] ?? date('Y');

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================

// Crescimento de escolas por ano
$stmt = $conn->query("
    SELECT 
        YEAR(created_at) as ano,
        COUNT(*) as total
    FROM escolas
    GROUP BY YEAR(created_at)
    ORDER BY ano ASC
");
$crescimento_escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento de usuários por ano
$stmt = $conn->query("
    SELECT 
        YEAR(created_at) as ano,
        COUNT(*) as total
    FROM usuarios
    WHERE tipo != 'super_admin'
    GROUP BY YEAR(created_at)
    ORDER BY ano ASC
");
$crescimento_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distribuição de usuários por tipo
$stmt = $conn->query("
    SELECT 
        tipo,
        COUNT(*) as total
    FROM usuarios
    WHERE tipo != 'super_admin'
    GROUP BY tipo
    ORDER BY total DESC
");
$distribuicao_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Taxa de retenção de escolas (ativas vs total)
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ativa' THEN 1 ELSE 0 END) as ativas,
        SUM(CASE WHEN status = 'suspensa' THEN 1 ELSE 0 END) as suspensas,
        SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial
    FROM escolas
");
$retencao = $stmt->fetch(PDO::FETCH_ASSOC);

// Ticket médio por plano
$stmt = $conn->prepare("
    SELECT 
        p.nome as plano_nome,
        AVG(a.valor) as ticket_medio
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    WHERE a.status = 'ativa'
    GROUP BY p.id
");
$stmt->execute();
$ticket_medio = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crescimento mensal do ano (escolas)
$stmt = $conn->prepare("
    SELECT 
        MONTH(created_at) as mes,
        COUNT(*) as total
    FROM escolas
    WHERE YEAR(created_at) = :ano
    GROUP BY MONTH(created_at)
    ORDER BY mes ASC
");
$stmt->execute([':ano' => $ano]);
$crescimento_mensal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$meses_nomes = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
$dados_crescimento = array_fill(0, 12, 0);
foreach ($crescimento_mensal as $c) {
    $dados_crescimento[$c['mes'] - 1] = $c['total'];
}

// Top 5 planos mais contratados
$stmt = $conn->query("
    SELECT 
        p.nome as plano_nome,
        COUNT(a.id) as total_contratacoes
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    GROUP BY p.id
    ORDER BY total_contratacoes DESC
    LIMIT 5
");
$planos_contratados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Satisfação (baseado em tickets resolvidos)
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) as resolvidos
    FROM tickets_suporte
");
$satisfacao = $stmt->fetch(PDO::FETCH_ASSOC);
$taxa_resolucao = ($satisfacao['total_tickets'] > 0) ? ($satisfacao['resolvidos'] / $satisfacao['total_tickets'] * 100) : 0;

// Tempo médio de resposta (tickets)
$stmt = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as tempo_medio
    FROM tickets_suporte
    WHERE status = 'fechado'
");
$tempo_resposta = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas Gerais | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.9em; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .progress {
            height: 25px;
            border-radius: 12px;
        }
        .print-only { display: none; }
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .print-only { display: block; }
            .card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
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
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="escolas.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="financeiro.php" class="nav-link"><i class="fas fa-chart-pie"></i> Financeiro</a></li>
            <li class="nav-item"><a href="estatisticas.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Estatísticas</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-bar"></i> Estatísticas Gerais</h2>
            <div class="no-print">
                <form method="GET" class="d-inline-flex gap-2">
                    <select name="ano" class="form-control form-control-sm w-auto">
                        <?php for ($i = 2024; $i <= date('Y'); $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
                <button onclick="window.print()" class="btn btn-secondary btn-sm ms-2"><i class="fas fa-print"></i> Imprimir</button>
            </div>
        </div>
        
        <div class="print-only text-center mb-4">
            <h2>SIGE Angola</h2>
            <h4>Estatísticas Gerais do Sistema</h4>
            <p>Gerado em: <?php echo date('d/m/Y H:i:s'); ?></p>
            <hr>
        </div>
        
        <!-- KPIs Principais -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $retencao['total']; ?></div>
                <div class="stat-label">Total de Escolas</div>
                <small>Cadastradas no sistema</small>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round(($retencao['ativas'] / max($retencao['total'], 1)) * 100, 1); ?>%</div>
                <div class="stat-label">Taxa de Retenção</div>
                <small>Escolas ativas</small>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($taxa_resolucao, 1); ?>%</div>
                <div class="stat-label">Tickets Resolvidos</div>
                <small>Satisfação do cliente</small>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($tempo_resposta['tempo_medio'] ?? 0); ?>h</div>
                <div class="stat-label">Tempo Médio de Resposta</div>
                <small>Tickets de suporte</small>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento de Escolas</div>
                    <div class="card-body">
                        <canvas id="crescimentoEscolasChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Crescimento de Usuários</div>
                    <div class="card-body">
                        <canvas id="crescimentoUsuariosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Distribuição de Usuários</div>
                    <div class="card-body">
                        <canvas id="usuariosChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php 
                            $tipos_nomes = [
                                'admin_escola' => 'Administradores',
                                'diretor' => 'Diretores',
                                'professor' => 'Professores',
                                'secretaria' => 'Secretários',
                                'aluno' => 'Alunos',
                                'pai' => 'Pais/Encarregados'
                            ];
                            foreach ($distribuicao_usuarios as $tipo): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><?php echo $tipos_nomes[$tipo['tipo']] ?? ucfirst($tipo['tipo']); ?></span>
                                <span class="badge bg-secondary"><?php echo $tipo['total']; ?> usuários</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar"></i> Crescimento Mensal - <?php echo $ano; ?></div>
                    <div class="card-body">
                        <canvas id="crescimentoMensalChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Planos Mais Contratados</div>
                    <div class="card-body">
                        <canvas id="planosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-ticket-alt"></i> Ticket Médio por Plano</div>
                    <div class="card-body">
                        <?php foreach ($ticket_medio as $tm): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span><?php echo $tm['plano_nome']; ?></span>
                                <span class="fw-bold">KZ <?php echo number_format($tm['ticket_medio'], 2, ',', '.'); ?></span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-success" style="width: <?php echo min(100, ($tm['ticket_medio'] / 1000) * 100); ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-simple"></i> Resumo de Indicadores</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <h3><?php echo $retencao['ativas']; ?></h3>
                        <p class="text-muted">Escolas Ativas</p>
                        <div class="progress">
                            <div class="progress-bar bg-success" style="width: <?php echo ($retencao['ativas'] / max($retencao['total'], 1)) * 100; ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <h3><?php echo $retencao['suspensas']; ?></h3>
                        <p class="text-muted">Escolas Suspensas</p>
                        <div class="progress">
                            <div class="progress-bar bg-warning" style="width: <?php echo ($retencao['suspensas'] / max($retencao['total'], 1)) * 100; ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <h3><?php echo $retencao['trial']; ?></h3>
                        <p class="text-muted">Escolas em Trial</p>
                        <div class="progress">
                            <div class="progress-bar bg-info" style="width: <?php echo ($retencao['trial'] / max($retencao['total'], 1)) * 100; ?>%"></div>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <h3><?php echo $satisfacao['resolvidos']; ?>/<?php echo $satisfacao['total_tickets']; ?></h3>
                        <p class="text-muted">Tickets Resolvidos</p>
                        <div class="progress">
                            <div class="progress-bar bg-success" style="width: <?php echo $taxa_resolucao; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Gráfico de crescimento de escolas
        const ctx1 = document.getElementById('crescimentoEscolasChart').getContext('2d');
        const anosEscolas = <?php echo json_encode(array_column($crescimento_escolas, 'ano')); ?>;
        const totalEscolas = <?php echo json_encode(array_column($crescimento_escolas, 'total')); ?>;
        
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: anosEscolas,
                datasets: [{
                    label: 'Novas Escolas',
                    data: totalEscolas,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            }
        });
        
        // Gráfico de crescimento de usuários
        const ctx2 = document.getElementById('crescimentoUsuariosChart').getContext('2d');
        const anosUsuarios = <?php echo json_encode(array_column($crescimento_usuarios, 'ano')); ?>;
        const totalUsuarios = <?php echo json_encode(array_column($crescimento_usuarios, 'total')); ?>;
        
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: anosUsuarios,
                datasets: [{
                    label: 'Novos Usuários',
                    data: totalUsuarios,
                    borderColor: '#1A2A6C',
                    backgroundColor: 'rgba(26, 42, 108, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            }
        });
        
        // Gráfico de distribuição de usuários
        const ctx3 = document.getElementById('usuariosChart').getContext('2d');
        const tiposLabels = <?php 
            $tipos = [];
            foreach ($distribuicao_usuarios as $t) {
                $tipos_nomes = ['admin_escola'=>'Administradores','diretor'=>'Diretores','professor'=>'Professores','secretaria'=>'Secretários','aluno'=>'Alunos','pai'=>'Pais'];
                $tipos[] = $tipos_nomes[$t['tipo']] ?? ucfirst($t['tipo']);
            }
            echo json_encode($tipos);
        ?>;
        const tiposValues = <?php echo json_encode(array_column($distribuicao_usuarios, 'total')); ?>;
        
        new Chart(ctx3, {
            type: 'doughnut',
            data: {
                labels: tiposLabels,
                datasets: [{
                    data: tiposValues,
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        
        // Gráfico de crescimento mensal
        const ctx4 = document.getElementById('crescimentoMensalChart').getContext('2d');
        new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($meses_nomes); ?>,
                datasets: [{
                    label: 'Novas Escolas',
                    data: <?php echo json_encode($dados_crescimento); ?>,
                    backgroundColor: '#006B3E'
                }]
            }
        });
        
        // Gráfico de planos contratados
        const ctx5 = document.getElementById('planosChart').getContext('2d');
        const planosLabels = <?php echo json_encode(array_column($planos_contratados, 'plano_nome')); ?>;
        const planosValues = <?php echo json_encode(array_column($planos_contratados, 'total_contratacoes')); ?>;
        
        new Chart(ctx5, {
            type: 'pie',
            data: {
                labels: planosLabels,
                datasets: [{
                    data: planosValues,
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    </script>
</body>
</html>