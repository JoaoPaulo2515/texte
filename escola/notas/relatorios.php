<?php
// escola/notas/relatorios.php - Relatórios de Notas
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

$ano_letivo = $_GET['ano'] ?? date('Y');
$turma_id = $_GET['turma_id'] ?? 0;
$disciplina_id = $_GET['disciplina_id'] ?? 0;
$bimestre = $_GET['bimestre'] ?? 0;
$export = $_GET['export'] ?? '';

// Buscar turmas
$turmas = $conn->prepare("
    SELECT id, nome FROM turmas 
    WHERE escola_id = :escola_id AND ano_letivo = :ano AND status = 'ativa'
    ORDER BY nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas
$disciplinas = $conn->prepare("
    SELECT id, nome, codigo FROM disciplinas 
    WHERE escola_id = :escola_id AND status = 'ativa'
    ORDER BY nome
");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados do relatório
$relatorio = [];
$estatisticas = [];

if ($turma_id && $disciplina_id) {
    // Buscar alunos e notas
    $stmt = $conn->prepare("
        SELECT e.id, u.nome, e.matricula, 
               n.mac, n.npt, n.exame_normal, n.exame_recurso, n.exame_especial,
               n.media_final, n.status
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        JOIN matriculas m ON m.estudante_id = e.id
        LEFT JOIN notas n ON n.matricula_id = m.id AND n.disciplina_id = :disciplina_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY u.nome
    ");
    $stmt->execute([
        ':disciplina_id' => $disciplina_id,
        ':turma_id' => $turma_id
    ]);
    $relatorio = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas
    $aprovados = 0;
    $reprovados = 0;
    $recuperacao = 0;
    $soma_notas = 0;
    $total_alunos = count($relatorio);
    
    foreach ($relatorio as $aluno) {
        $media = $aluno['media_final'];
        if ($media !== null) {
            $soma_notas += $media;
            if ($media >= 10) {
                $aprovados++;
            } elseif ($media >= 7) {
                $recuperacao++;
            } else {
                $reprovados++;
            }
        }
    }
    
    $estatisticas = [
        'total_alunos' => $total_alunos,
        'aprovados' => $aprovados,
        'reprovados' => $reprovados,
        'recuperacao' => $recuperacao,
        'media_geral' => $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0,
        'taxa_aprovacao' => $total_alunos > 0 ? round(($aprovados / $total_alunos) * 100, 1) : 0
    ];
}

// Exportar CSV
if ($export == 'csv' && !empty($relatorio)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_notas_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Cabeçalho
    fputcsv($output, ['RELATÓRIO DE NOTAS - SIGE ANGOLA']);
    fputcsv($output, ['Data:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['Matrícula', 'Aluno', 'MAC', 'NPT', 'Exame Normal', 'Exame Recurso', 'Média Final', 'Status']);
    
    foreach ($relatorio as $aluno) {
        $status_text = '';
        if ($aluno['status'] == 'aprovado') $status_text = 'Aprovado';
        elseif ($aluno['status'] == 'reprovado') $status_text = 'Reprovado';
        elseif ($aluno['status'] == 'recuperacao') $status_text = 'Recuperação';
        else $status_text = 'Em curso';
        
        fputcsv($output, [
            $aluno['matricula'],
            $aluno['nome'],
            $aluno['mac'] ?? '',
            $aluno['npt'] ?? '',
            $aluno['exame_normal'] ?? '',
            $aluno['exame_recurso'] ?? '',
            $aluno['media_final'] ?? '',
            $status_text
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
    <title>Relatórios de Notas | SIGE Angola</title>
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
        .stats-label { color: #666; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75em; font-weight: 500; }
        .status-aprovado { background: #d4edda; color: #155724; }
        .status-reprovado { background: #f8d7da; color: #721c24; }
        .status-recuperacao { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Relatórios de Notas</h2>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Filtros do Relatório</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label>Ano Letivo</label>
                        <select name="ano" class="form-control">
                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano_letivo == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Turma</label>
                        <select name="turma_id" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $disciplina_id == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($turma_id && $disciplina_id && !empty($relatorio)): ?>
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div class="stats-label">Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $estatisticas['aprovados']; ?></div>
                    <div class="stats-label">Aprovados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $estatisticas['recuperacao']; ?></div>
                    <div class="stats-label">Recuperação</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $estatisticas['reprovados']; ?></div>
                    <div class="stats-label">Reprovados</div>
                </div>
            </div>
        </div>
        
        <!-- Gráfico -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Distribuição de Resultados</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="resultadosChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Resumo Estatístico</h3>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h4><?php echo $estatisticas['media_geral']; ?></h4>
                                <p>Média Geral</p>
                            </div>
                            <div class="col-6">
                                <h4><?php echo $estatisticas['taxa_aprovacao']; ?>%</h4>
                                <p>Taxa de Aprovação</p>
                            </div>
                        </div>
                        <div class="progress mt-3" style="height: 30px;">
                            <div class="progress-bar bg-success" style="width: <?php echo ($estatisticas['aprovados'] / max($estatisticas['total_alunos'], 1)) * 100; ?>%">
                                Aprovados
                            </div>
                            <div class="progress-bar bg-warning" style="width: <?php echo ($estatisticas['recuperacao'] / max($estatisticas['total_alunos'], 1)) * 100; ?>%">
                                Recuperação
                            </div>
                            <div class="progress-bar bg-danger" style="width: <?php echo ($estatisticas['reprovados'] / max($estatisticas['total_alunos'], 1)) * 100; ?>%">
                                Reprovados
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Lista de Notas</h3>
                <a href="?ano=<?php echo $ano_letivo; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&export=csv" class="btn btn-success btn-sm">
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
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Exame Normal</th>
                                <th>Exame Recurso</th>
                                <th>Média Final</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio as $i => $aluno): 
                                $status_class = '';
                                $status_text = '';
                                if ($aluno['status'] == 'aprovado') {
                                    $status_class = 'status-aprovado';
                                    $status_text = 'Aprovado';
                                } elseif ($aluno['status'] == 'reprovado') {
                                    $status_class = 'status-reprovado';
                                    $status_text = 'Reprovado';
                                } elseif ($aluno['status'] == 'recuperacao') {
                                    $status_class = 'status-recuperacao';
                                    $status_text = 'Recuperação';
                                } else {
                                    $status_text = 'Em curso';
                                }
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo $aluno['matricula']; ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td><?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : '-'; ?></td>
                                <td><?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : '-'; ?></td>
                                <td><?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1) : '-'; ?></td>
                                <td><?php echo $aluno['exame_recurso'] !== null ? number_format($aluno['exame_recurso'], 1) : '-'; ?></td>
                                <td><strong><?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1) : '-'; ?></strong></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php elseif ($turma_id && $disciplina_id): ?>
        <div class="alert alert-warning">Nenhum dado encontrado para os filtros selecionados.</div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        <?php if ($turma_id && $disciplina_id && !empty($relatorio)): ?>
        // Gráfico de resultados
        const ctx = document.getElementById('resultadosChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [
                        <?php echo $estatisticas['aprovados']; ?>,
                        <?php echo $estatisticas['recuperacao']; ?>,
                        <?php echo $estatisticas['reprovados']; ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
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