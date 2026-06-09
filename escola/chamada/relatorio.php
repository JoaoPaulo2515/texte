<?php
// escola/chamada/relatorio.php - Relatório de Chamada
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];

$is_professor = ($usuario_tipo == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor');

// Buscar turmas
if ($is_professor) {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.id, t.nome, t.ano, t.turno
        FROM turmas t
        JOIN alocacoes a ON a.turma_id = t.id
        JOIN professores p ON p.id = a.professor_id
        WHERE p.usuario_id = :usuario_id AND t.status = 'ativa'
        ORDER BY t.nome
    ");
    $stmt->execute([':usuario_id' => $_SESSION['usuario_id']]);
    $turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $turmas = $conn->prepare("
        SELECT id, nome, ano, turno 
        FROM turmas 
        WHERE escola_id = :escola_id AND status = 'ativa'
        ORDER BY nome
    ");
    $turmas->execute([':escola_id' => $escola_id]);
    $turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);
}

$turma_id = $_GET['turma_id'] ?? 0;
$mes = $_GET['mes'] ?? date('m');
$ano = $_GET['ano'] ?? date('Y');
$export = $_GET['export'] ?? '';

// Buscar dados do relatório
$relatorio = [];
$estatisticas = [];

if ($turma_id) {
    $stmt = $conn->prepare("
        SELECT e.id, u.nome, e.matricula,
               COUNT(CASE WHEN p.presente = 1 THEN 1 END) as presentes,
               COUNT(CASE WHEN p.presente = 0 AND p.tipo_falta = 'injustificada' THEN 1 END) as faltas,
               COUNT(CASE WHEN p.presente = 0 AND p.tipo_falta = 'justificada' THEN 1 END) as justificadas,
               COUNT(*) as total_dias,
               ROUND((COUNT(CASE WHEN p.presente = 1 THEN 1 END) / COUNT(*)) * 100, 1) as percentual
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        JOIN matriculas m ON m.estudante_id = e.id
        LEFT JOIN presencas p ON p.matricula_id = m.id AND MONTH(p.data) = :mes AND YEAR(p.data) = :ano
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        GROUP BY e.id
        ORDER BY u.nome
    ");
    $stmt->execute([
        ':turma_id' => $turma_id,
        ':mes' => $mes,
        ':ano' => $ano
    ]);
    $relatorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas gerais
    $total_presentes = 0;
    $total_faltas = 0;
    $total_justificadas = 0;
    $total_dias = 0;
    
    foreach ($relatorio as $aluno) {
        $total_presentes += $aluno['presentes'];
        $total_faltas += $aluno['faltas'];
        $total_justificadas += $aluno['justificadas'];
        $total_dias = max($total_dias, $aluno['total_dias']);
    }
    
    $estatisticas = [
        'total_alunos' => count($relatorio),
        'total_presentes' => $total_presentes,
        'total_faltas' => $total_faltas,
        'total_justificadas' => $total_justificadas,
        'total_dias' => $total_dias,
        'media_presenca' => $total_dias > 0 ? round(($total_presentes / ($total_alunos * $total_dias)) * 100, 1) : 0
    ];
}

// Exportar CSV
if ($export == 'csv' && !empty($relatorio)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_chamada_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['RELATÓRIO DE CHAMADA - SIGE ANGOLA']);
    fputcsv($output, ['Mês:', date('F/Y', strtotime("$ano-$mes-01"))]);
    fputcsv($output, []);
    fputcsv($output, ['Matrícula', 'Aluno', 'Presentes', 'Faltas', 'Justificadas', 'Total Dias', '% Presença']);
    
    foreach ($relatorio as $aluno) {
        fputcsv($output, [
            $aluno['matricula'],
            $aluno['nome'],
            $aluno['presentes'],
            $aluno['faltas'],
            $aluno['justificadas'],
            $aluno['total_dias'],
            $aluno['percentual'] . '%'
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Chamada | SIGE Angola</title>
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
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .stats-card { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px; height: 100%; }
        .stats-number { font-size: 2em; font-weight: bold; }
        .percentual-alto { color: #28a745; font-weight: bold; }
        .percentual-medio { color: #ffc107; font-weight: bold; }
        .percentual-baixo { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Relatório de Chamada</h2>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Filtros</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label>Turma</label>
                        <select name="turma_id" class="form-control" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?> - <?php echo ucfirst($t['turno']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Mês</label>
                        <select name="mes" class="form-control">
                            <option value="01" <?php echo $mes == '01' ? 'selected' : ''; ?>>Janeiro</option>
                            <option value="02" <?php echo $mes == '02' ? 'selected' : ''; ?>>Fevereiro</option>
                            <option value="03" <?php echo $mes == '03' ? 'selected' : ''; ?>>Março</option>
                            <option value="04" <?php echo $mes == '04' ? 'selected' : ''; ?>>Abril</option>
                            <option value="05" <?php echo $mes == '05' ? 'selected' : ''; ?>>Maio</option>
                            <option value="06" <?php echo $mes == '06' ? 'selected' : ''; ?>>Junho</option>
                            <option value="07" <?php echo $mes == '07' ? 'selected' : ''; ?>>Julho</option>
                            <option value="08" <?php echo $mes == '08' ? 'selected' : ''; ?>>Agosto</option>
                            <option value="09" <?php echo $mes == '09' ? 'selected' : ''; ?>>Setembro</option>
                            <option value="10" <?php echo $mes == '10' ? 'selected' : ''; ?>>Outubro</option>
                            <option value="11" <?php echo $mes == '11' ? 'selected' : ''; ?>>Novembro</option>
                            <option value="12" <?php echo $mes == '12' ? 'selected' : ''; ?>>Dezembro</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>Ano</label>
                        <select name="ano" class="form-control">
                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($turma_id && !empty($relatorio)): ?>
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div>Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $estatisticas['total_presentes']; ?></div>
                    <div>Total de Presenças</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $estatisticas['total_faltas']; ?></div>
                    <div>Total de Faltas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $estatisticas['total_justificadas']; ?></div>
                    <div>Faltas Justificadas</div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Resumo de Presença</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="presencaChart" height="250"></canvas>
                        <div class="text-center mt-3">
                            <h4>Média de Presença: <?php echo $estatisticas['media_presenca']; ?>%</h4>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $estatisticas['media_presenca']; ?>%">
                                    <?php echo $estatisticas['media_presenca']; ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Top 5 Alunos com Maior Presença</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php 
                            $top_alunos = $relatorio;
                            usort($top_alunos, function($a, $b) {
                                return $b['percentual'] - $a['percentual'];
                            });
                            $top_alunos = array_slice($top_alunos, 0, 5);
                            ?>
                            <?php foreach ($top_alunos as $aluno): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                        <br>
                                        <small><?php echo $aluno['matricula']; ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success fs-6"><?php echo $aluno['percentual']; ?>%</span>
                                        <br>
                                        <small><?php echo $aluno['presentes']; ?>/<?php echo $aluno['total_dias']; ?> dias</small>
                                    </div>
                                </div>
                                <div class="progress mt-2" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $aluno['percentual']; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Alunos -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Detalhamento por Aluno</h3>
                <a href="?turma_id=<?php echo $turma_id; ?>&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>&export=csv" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Exportar CSV
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Matrícula</th>
                                <th>Aluno</th>
                                <th class="text-center">Presentes</th>
                                <th class="text-center">Faltas</th>
                                <th class="text-center">Justificadas</th>
                                <th class="text-center">Total Dias</th>
                                <th class="text-center">% Presença</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio as $i => $aluno): 
                                $percentual = $aluno['percentual'];
                                $percentual_class = '';
                                if ($percentual >= 75) {
                                    $percentual_class = 'percentual-alto';
                                } elseif ($percentual >= 50) {
                                    $percentual_class = 'percentual-medio';
                                } else {
                                    $percentual_class = 'percentual-baixo';
                                }
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo $aluno['matricula']; ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td class="text-center"><?php echo $aluno['presentes']; ?></td>
                                <td class="text-center text-danger"><?php echo $aluno['faltas']; ?></td>
                                <td class="text-center text-warning"><?php echo $aluno['justificadas']; ?></td>
                                <td class="text-center"><?php echo $aluno['total_dias']; ?></td>
                                <td class="text-center <?php echo $percentual_class; ?>"><?php echo $percentual; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php elseif ($turma_id): ?>
        <div class="alert alert-warning">Nenhum dado encontrado para os filtros selecionados.</div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        <?php if ($turma_id && !empty($relatorio)): ?>
        // Gráfico de presença
        const ctx = document.getElementById('presencaChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Presentes', 'Faltas', 'Justificadas'],
                datasets: [{
                    data: [
                        <?php echo $estatisticas['total_presentes']; ?>,
                        <?php echo $estatisticas['total_faltas']; ?>,
                        <?php echo $estatisticas['total_justificadas']; ?>
                    ],
                    backgroundColor: ['#28a745', '#dc3545', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>