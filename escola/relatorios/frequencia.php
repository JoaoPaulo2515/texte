<?php
// escola/relatorios/frequencia.php - Relatório de Frequência
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar turmas
$turmas = $conn->prepare("SELECT id, nome FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

$turma_id = $_GET['turma_id'] ?? 0;
$mes = $_GET['mes'] ?? date('m');
$ano = $_GET['ano'] ?? date('Y');
$export = $_GET['export'] ?? '';

$meses_nomes = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

$relatorio = [];
$estatisticas = [];
$turma_nome = '';

if ($turma_id) {
    // Buscar nome da turma
    $stmt = $conn->prepare("SELECT nome FROM turmas WHERE id = :id");
    $stmt->execute([':id' => $turma_id]);
    $turma_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'];
    
    // Buscar dados de frequência
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
    
    // Calcular estatísticas
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
        'media_presenca' => $total_dias > 0 ? round(($total_presentes / (count($relatorio) * $total_dias)) * 100, 1) : 0
    ];
}

if ($export == 'pdf' && !empty($relatorio)) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Frequência - ' . htmlspecialchars($escola['nome']) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #006B3E; padding-bottom: 20px; }
            .logo { max-width: 100px; margin-bottom: 10px; }
            .title { font-size: 24px; color: #006B3E; margin-bottom: 5px; }
            .subtitle { color: #666; font-size: 14px; }
            .info { margin: 20px 0; padding: 10px; background: #f5f5f5; border-radius: 5px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
            th { background: #006B3E; color: white; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            .high { color: #28a745; font-weight: bold; }
            .medium { color: #ffc107; font-weight: bold; }
            .low { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            ' . ($escola['logo'] ? '<img src="../../uploads/escolas/' . $escola['logo'] . '" class="logo">' : '') . '
            <div class="title">' . htmlspecialchars($escola['nome']) . '</div>
            <div class="subtitle">Relatório de Frequência</div>
            <div class="subtitle">' . htmlspecialchars($turma_nome) . ' - ' . $meses_nomes[$mes] . '/' . $ano . '</div>
        </div>
        <div class="info">
            <strong>Data de emissão:</strong> ' . date('d/m/Y H:i:s') . '<br>
            <strong>Período:</strong> ' . $meses_nomes[$mes] . ' de ' . $ano . '
        </div>
        <table>
            <thead>
                <tr><th>#</th><th>Matrícula</th><th>Aluno</th><th>Presentes</th><th>Faltas</th><th>Justificadas</th><th>% Presença</th></tr>
            </thead>
            <tbody>';
    
    foreach ($relatorio as $i => $aluno) {
        $percentual = $aluno['percentual'];
        $class = '';
        if ($percentual >= 75) $class = 'high';
        elseif ($percentual >= 50) $class = 'medium';
        else $class = 'low';
        
        $html .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td>' . $aluno['matricula'] . '</td>
                <td>' . htmlspecialchars($aluno['nome']) . '</td>
                <td>' . $aluno['presentes'] . '</td>
                <td>' . $aluno['faltas'] . '</td>
                <td>' . $aluno['justificadas'] . '</td>
                <td class="' . $class . '">' . $percentual . '%</td>
            </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
        <div class="footer">
            <p>' . htmlspecialchars($escola['endereco']) . ' | Tel: ' . htmlspecialchars($escola['telefone']) . ' | Email: ' . htmlspecialchars($escola['email']) . '</p>
            <p>SIGE Angola - Sistema Integrado de Gestão Escolar | www.sige.ao</p>
            <p>Relatório gerado em ' . date('d/m/Y H:i:s') . '</p>
        </div>
    </body>
    </html>';
    
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Frequência | SIGE Angola</title>
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
        .stats-number { font-size: 2em; font-weight: bold; color: #006B3E; }
        .percentual-alto { color: #28a745; font-weight: bold; }
        .percentual-medio { color: #ffc107; font-weight: bold; }
        .percentual-baixo { color: #dc3545; font-weight: bold; }
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calendar-check"></i> Relatório de Frequência</h2>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Imprimir</button>
            </div>
        </div>
        
        <div class="print-header text-center mb-4" style="display: none;">
            <?php if ($escola['logo']): ?>
                <img src="../../uploads/escolas/<?php echo $escola['logo']; ?>" style="max-height: 80px;">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($escola['nome']); ?></h2>
            <p><?php echo htmlspecialchars($escola['endereco']); ?> | Tel: <?php echo htmlspecialchars($escola['telefone']); ?></p>
            <hr>
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
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Mês</label>
                        <select name="mes" class="form-control">
                            <?php foreach ($meses_nomes as $valor => $nome): ?>
                            <option value="<?php echo $valor; ?>" <?php echo $mes == $valor ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                            <?php endforeach; ?>
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
                                        <br><small><?php echo $aluno['matricula']; ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success fs-6"><?php echo $aluno['percentual']; ?>%</span>
                                        <br><small><?php echo $aluno['presentes']; ?>/<?php echo $aluno['total_dias']; ?> dias</small>
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
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Detalhamento por Aluno</h3>
                <div>
                    <a href="?turma_id=<?php echo $turma_id; ?>&mes=<?php echo $mes; ?>&ano=<?php echo $ano; ?>&export=pdf" class="btn btn-danger btn-sm" target="_blank">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                </div>
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
        
        <div class="print-footer text-center mt-4" style="display: none;">
            <hr>
            <p><?php echo htmlspecialchars($escola['endereco']); ?> | Tel: <?php echo htmlspecialchars($escola['telefone']); ?> | Email: <?php echo htmlspecialchars($escola['email']); ?></p>
            <p>SIGE Angola - Sistema Integrado de Gestão Escolar | Relatório gerado em <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        <?php if ($turma_id && !empty($relatorio)): ?>
        const ctx = document.getElementById('presencaChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Presentes', 'Faltas', 'Justificadas'],
                datasets: [{
                    data: [<?php echo $estatisticas['total_presentes']; ?>, <?php echo $estatisticas['total_faltas']; ?>, <?php echo $estatisticas['total_justificadas']; ?>],
                    backgroundColor: ['#28a745', '#dc3545', '#ffc107']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>