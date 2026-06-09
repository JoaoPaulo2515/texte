<?php
// escola/rh/relatorios.php - Relatórios de RH
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Estatísticas
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN tipo_funcionario = 'professor' THEN 1 ELSE 0 END) as professores,
        SUM(CASE WHEN tipo_funcionario = 'administrativo' THEN 1 ELSE 0 END) as administrativos,
        SUM(CASE WHEN genero = 'M' THEN 1 ELSE 0 END) as homens,
        SUM(CASE WHEN genero = 'F' THEN 1 ELSE 0 END) as mulheres,
        SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as inativos
    FROM funcionarios WHERE escola_id = ?
");
$stmt->execute([$escola_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Admitidos por mês
$stmt = $conn->prepare("
    SELECT MONTH(created_at) as mes, COUNT(*) as total 
    FROM funcionarios 
    WHERE escola_id = ? AND YEAR(created_at) = YEAR(NOW())
    GROUP BY MONTH(created_at)
    ORDER BY mes
");
$stmt->execute([$escola_id]);
$admitidos_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funcionários por cargo
$stmt = $conn->prepare("
    SELECT cargo, COUNT(*) as total 
    FROM funcionarios 
    WHERE escola_id = ? AND status = 'ativo'
    GROUP BY cargo
    ORDER BY total DESC
    LIMIT 10
");
$stmt->execute([$escola_id]);
$cargos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aniversariantes do mês
$stmt = $conn->prepare("
    SELECT nome, data_nascimento, cargo 
    FROM funcionarios 
    WHERE escola_id = ? AND MONTH(data_nascimento) = MONTH(NOW())
    ORDER BY DAY(data_nascimento)
");
$stmt->execute([$escola_id]);
$aniversariantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contratos a expirar
$stmt = $conn->prepare("
    SELECT nome, cargo, data_fim_contrato 
    FROM funcionarios 
    WHERE escola_id = ? AND data_fim_contrato IS NOT NULL 
    AND data_fim_contrato BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 DAY)
    ORDER BY data_fim_contrato
");
$stmt->execute([$escola_id]);
$contratos_expirar = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios RH | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .stat-number { font-size: 2.5em; font-weight: bold; color: #006B3E; }
        .export-buttons .btn { margin-right: 10px; }
    </style>
</head>
<body>
   <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Relatórios de RH</h2>
            <div class="export-buttons">
                <a href="exportar_excel.php?tipo=funcionarios" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </a>
                <a href="exportar_pdf.php?tipo=funcionarios" class="btn btn-danger btn-sm">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </a>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div>Total Funcionários</div>
                        <i class="fas fa-users text-muted"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="stat-number"><?php echo $stats['professores']; ?></div>
                        <div>Professores</div>
                        <i class="fas fa-chalkboard-user text-muted"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="stat-number"><?php echo $stats['administrativos']; ?></div>
                        <div>Administrativos</div>
                        <i class="fas fa-building text-muted"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="stat-number"><?php echo $stats['ativos']; ?></div>
                        <div>Ativos</div>
                        <i class="fas fa-user-check text-muted"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-bar"></i> Distribuição por Género
                    </div>
                    <div class="card-body">
                        <canvas id="generoChart" height="250"></canvas>
                        <div class="text-center mt-3">
                            <span class="badge bg-primary">Homens: <?php echo $stats['homens']; ?></span>
                            <span class="badge bg-danger">Mulheres: <?php echo $stats['mulheres']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i> Admissões por Mês (<?php echo date('Y'); ?>)
                    </div>
                    <div class="card-body">
                        <canvas id="admissoesChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Top Cargos
                    </div>
                    <div class="card-body">
                        <canvas id="cargosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-birthday-cake"></i> Aniversariantes do Mês
                    </div>
                    <div class="card-body">
                        <?php if (count($aniversariantes) > 0): ?>
                            <ul class="list-group">
                                <?php foreach ($aniversariantes as $a): ?>
                                    <li class="list-group-item">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($a['nome']); ?>
                                        <span class="badge bg-primary float-end"><?php echo date('d/m', strtotime($a['data_nascimento'])); ?></span>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($a['cargo']); ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted text-center">Nenhum aniversariante este mês</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Contratos a Expirar -->
        <?php if (count($contratos_expirar) > 0): ?>
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <i class="fas fa-exclamation-triangle"></i> Contratos a Expirar (Próximos 60 dias)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Funcionário</th><th>Cargo</th><th>Data Fim Contrato</th><th>Dias Restantes</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contratos_expirar as $c): ?>
                                <?php 
                                $dias_restantes = ceil((strtotime($c['data_fim_contrato']) - time()) / 86400);
                                $classe = $dias_restantes <= 30 ? 'danger' : 'warning';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($c['cargo']); ?>                                    <td><?php echo date('d/m/Y', strtotime($c['data_fim_contrato'])); ?></td>
                                    <td><span class="badge bg-<?php echo $classe; ?>"><?php echo $dias_restantes; ?> dias</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Links Rápidos para Relatórios -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-download"></i> Relatórios Disponíveis
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=funcionarios" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-file-excel"></i> Lista de Funcionários
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=avaliacoes" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-star"></i> Avaliações de Desempenho
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=formacoes" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-graduation-cap"></i> Planos de Formação
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=ferias" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-umbrella-beach"></i> Mapa de Férias
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=aniversariantes" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-birthday-cake"></i> Aniversariantes
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="exportar_excel.php?tipo=contratos" class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-file-contract"></i> Contratos a Expirar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Gráfico de Género
        const generoCtx = document.getElementById('generoChart').getContext('2d');
        new Chart(generoCtx, {
            type: 'doughnut',
            data: {
                labels: ['Masculino', 'Feminino'],
                datasets: [{
                    data: [<?php echo $stats['homens']; ?>, <?php echo $stats['mulheres']; ?>],
                    backgroundColor: ['#006B3E', '#1A2A6C'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Gráfico de Admissões
        const admissoesCtx = document.getElementById('admissoesChart').getContext('2d');
        const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        const admitidosData = Array(12).fill(0);
        
        <?php foreach ($admitidos_mes as $a): ?>
            admitidosData[<?php echo $a['mes'] - 1; ?>] = <?php echo $a['total']; ?>;
        <?php endforeach; ?>
        
        new Chart(admissoesCtx, {
            type: 'line',
            data: {
                labels: meses,
                datasets: [{
                    label: 'Admissões',
                    data: admitidosData,
                    borderColor: '#006B3E',
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
        
        // Gráfico de Cargos
        const cargosCtx = document.getElementById('cargosChart').getContext('2d');
        const cargosLabels = <?php echo json_encode(array_column($cargos, 'cargo')); ?>;
        const cargosData = <?php echo json_encode(array_column($cargos, 'total')); ?>;
        
        new Chart(cargosCtx, {
            type: 'bar',
            data: {
                labels: cargosLabels,
                datasets: [{
                    label: 'Quantidade',
                    data: cargosData,
                    backgroundColor: '#006B3E',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>