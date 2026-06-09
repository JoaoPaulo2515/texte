<?php
// escola/rh/avaliacao/resultados.php - Resultados das Avaliações
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar períodos para filtro
$stmt = $conn->prepare("SELECT id, nome FROM avaliacao_periodos WHERE escola_id = ? ORDER BY data_inicio DESC");
$stmt->execute([$escola_id]);
$periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodo_id = $_GET['periodo_id'] ?? ($periodos[0]['id'] ?? 0);

// Buscar resultados
$sql = "
    SELECT a.*, f.nome as funcionario_nome, f.numero_processo, f.cargo, f.foto,
           ap.nome as periodo_nome
    FROM avaliacoes a
    JOIN funcionarios f ON a.funcionario_id = f.id
    JOIN avaliacao_periodos ap ON a.periodo_id = ap.id
    WHERE f.escola_id = ?
";

$params = [$escola_id];

if ($periodo_id) {
    $sql .= " AND a.periodo_id = ?";
    $params[] = $periodo_id;
}

$sql .= " ORDER BY a.pontuacao_total DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_avaliados = count($resultados);
$media_geral = 0;
$distribuicao = ['Excelente' => 0, 'Bom' => 0, 'Regular' => 0, 'Insatisfatório' => 0];

foreach ($resultados as $r) {
    $media_geral += $r['pontuacao_total'];
    $distribuicao[$r['classificacao']]++;
}
$media_geral = $total_avaliados > 0 ? round($media_geral / $total_avaliados, 2) : 0;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados de Avaliação | SIGE Angola</title>
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
        .stat-card { background: white; border-radius: 10px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .avatar-funcionario { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-bar"></i> Resultados de Avaliação</h2>
            <div>
                <select id="filtroPeriodo" class="form-select d-inline-block w-auto" onchange="filtrarPorPeriodo()">
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $periodo_id == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="exportar_resultados.php?periodo_id=<?php echo $periodo_id; ?>" class="btn btn-success ms-2">
                    <i class="fas fa-file-excel"></i> Exportar
                </a>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_avaliados; ?></div>
                    <div>Funcionários Avaliados</div>
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $media_geral; ?></div>
                    <div>Média Geral (pts)</div>
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $distribuicao['Excelente']; ?></div>
                    <div>Excelente</div>
                    <i class="fas fa-star"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $distribuicao['Insatisfatório']; ?></div>
                    <div>Insatisfatório</div>
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Distribuição -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> Distribuição por Classificação
                    </div>
                    <div class="card-body">
                        <canvas id="distribuicaoChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-trophy"></i> Top 5 Melhores Avaliações
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Funcionário</th><th>Pontuação</th><th>Classificação</th></tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $top5 = array_slice($resultados, 0, 5);
                                    foreach ($top5 as $r): 
                                    ?>
                                    <tr>
                                        <td>
                                            <img src="../../../uploads/funcionarios/fotos/<?php echo $r['foto']; ?>" class="avatar-funcionario me-2" onerror="this.src='../../../assets/images/avatar-padrao.png'">
                                            <?php echo htmlspecialchars($r['funcionario_nome']); ?>
                                        </td>
                                        <td><strong><?php echo $r['pontuacao_total']; ?></strong> pts</td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $r['classificacao'] == 'Excelente' ? 'success' : 
                                                    ($r['classificacao'] == 'Bom' ? 'primary' : 
                                                    ($r['classificacao'] == 'Regular' ? 'warning' : 'danger')); 
                                            ?>">
                                                <?php echo $r['classificacao']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Resultados -->
        <div class="card mt-3">
            <div class="card-header">
                <i class="fas fa-list"></i> Lista de Avaliações
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaResultados">
                        <thead>
                            <tr>
                                <th>Funcionário</th>
                                <th>Cargo</th>
                                <th>Período</th>
                                <th>Pontuação</th>
                                <th>Classificação</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $r): ?>
                            <tr>
                                <td>
                                    <img src="../../../uploads/funcionarios/fotos/<?php echo $r['foto']; ?>" class="avatar-funcionario me-2" onerror="this.src='../../../assets/images/avatar-padrao.png'">
                                    <?php echo htmlspecialchars($r['funcionario_nome']); ?>
                                    <br><small class="text-muted"><?php echo $r['numero_processo']; ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($r['cargo']); ?>                                
                                <td><?php echo htmlspecialchars($r['periodo_nome']); ?></td>
                                <td><strong><?php echo $r['pontuacao_total']; ?></strong> pts</td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $r['classificacao'] == 'Excelente' ? 'success' : 
                                            ($r['classificacao'] == 'Bom' ? 'primary' : 
                                            ($r['classificacao'] == 'Regular' ? 'warning' : 'danger')); 
                                    ?>">
                                        <?php echo $r['classificacao']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($r['data_avaliacao'])); ?></td>
                                <td>
                                    <a href="detalhes_avaliacao.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="gerar_certificado.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-certificate"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        
        $(document).ready(function() {
            $('#tabelaResultados').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                },
                order: [[3, 'desc']]
            });
        });
        
        function filtrarPorPeriodo() {
            const periodoId = document.getElementById('filtroPeriodo').value;
            window.location.href = 'resultados.php?periodo_id=' + periodoId;
        }
        
        // Gráfico de Distribuição
        const ctx = document.getElementById('distribuicaoChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Excelente', 'Bom', 'Regular', 'Insatisfatório'],
                datasets: [{
                    data: [<?php echo $distribuicao['Excelente']; ?>, <?php echo $distribuicao['Bom']; ?>, <?php echo $distribuicao['Regular']; ?>, <?php echo $distribuicao['Insatisfatório']; ?>],
                    backgroundColor: ['#28a745', '#007bff', '#ffc107', '#dc3545'],
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
    </script>
</body>
</html>