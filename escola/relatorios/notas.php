<?php
// escola/relatorios/notas.php - Relatório de Notas
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

// Buscar disciplinas
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

$turma_id = $_GET['turma_id'] ?? 0;
$disciplina_id = $_GET['disciplina_id'] ?? 0;
$bimestre = $_GET['bimestre'] ?? 0;
$export = $_GET['export'] ?? '';

// Buscar dados do relatório
$relatorio = [];
$estatisticas = [];
$turma_nome = '';
$disciplina_nome = '';

if ($turma_id && $disciplina_id) {
    // Buscar nome da turma
    $stmt = $conn->prepare("SELECT nome FROM turmas WHERE id = :id");
    $stmt->execute([':id' => $turma_id]);
    $turma_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'];
    
    // Buscar nome da disciplina
    $stmt = $conn->prepare("SELECT nome FROM disciplinas WHERE id = :id");
    $stmt->execute([':id' => $disciplina_id]);
    $disciplina_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'];
    
    // Buscar alunos e notas
    $sql = "
        SELECT e.id, u.nome, e.matricula, 
               n.mac, n.npt, n.exame_normal, n.exame_recurso, n.exame_especial,
               n.media_final, n.status
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        JOIN matriculas m ON m.estudante_id = e.id
        LEFT JOIN notas n ON n.estudante_id = m.id AND n.disciplina_id = :disciplina_id
    ";
    
    if ($bimestre > 0) {
        $sql .= " AND n.bimestre = :bimestre";
    }
    
    $sql .= " WHERE m.turma_id = :turma_id AND m.status = 'ativa' ORDER BY u.nome";
    
    $stmt = $conn->prepare($sql);
    $params = [
        ':disciplina_id' => $disciplina_id,
        ':turma_id' => $turma_id
    ];
    if ($bimestre > 0) {
        $params[':bimestre'] = $bimestre;
    }
    $stmt->execute($params);
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

// Exportar HTML para PDF (simulado)
if ($export == 'pdf' && !empty($relatorio)) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Notas - ' . htmlspecialchars($escola['nome']) . '</title>
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
            .approved { color: #28a745; font-weight: bold; }
            .failed { color: #dc3545; font-weight: bold; }
            .recovery { color: #ffc107; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            ' . ($escola['logo'] ? '<img src="../../uploads/escolas/' . $escola['logo'] . '" class="logo">' : '') . '
            <div class="title">' . htmlspecialchars($escola['nome']) . '</div>
            <div class="subtitle">Relatório de Notas</div>
            <div class="subtitle">' . htmlspecialchars($disciplina_nome) . ' - ' . htmlspecialchars($turma_nome) . '</div>
        </div>
        <div class="info">
            <strong>Data de emissão:</strong> ' . date('d/m/Y H:i:s') . '<br>
            <strong>Período:</strong> ' . ($bimestre > 0 ? $bimestre . 'º Bimestre' : 'Ano Letivo Completo') . '
        </div>
        <table>
            <thead>
                <tr><th>#</th><th>Matrícula</th><th>Aluno</th><th>MAC</th><th>NPT</th><th>Exame</th><th>Média Final</th><th>Status</th></tr>
            </thead>
            <tbody>';
    
    foreach ($relatorio as $i => $aluno) {
        $status_class = '';
        $status_text = '';
        if ($aluno['status'] == 'aprovado') {
            $status_class = 'approved';
            $status_text = 'Aprovado';
        } elseif ($aluno['status'] == 'reprovado') {
            $status_class = 'failed';
            $status_text = 'Reprovado';
        } elseif ($aluno['status'] == 'recuperacao') {
            $status_class = 'recovery';
            $status_text = 'Recuperação';
        } else {
            $status_text = 'Em curso';
        }
        
        $html .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td>' . $aluno['matricula'] . '</td>
                <td>' . htmlspecialchars($aluno['nome']) . '</td>
                <td>' . ($aluno['mac'] !== null ? number_format($aluno['mac'], 1) : '-') . '</td>
                <td>' . ($aluno['npt'] !== null ? number_format($aluno['npt'], 1) : '-') . '</td>
                <td>' . ($aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1) : '-') . '</td>
                <td><strong>' . ($aluno['media_final'] !== null ? number_format($aluno['media_final'], 1) : '-') . '</strong></td>
                <td class="' . $status_class . '">' . $status_text . '</td>
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
    <title>Relatório de Notas | SIGE Angola</title>
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
        .status-aprovado { background: #d4edda; color: #155724; }
        .status-reprovado { background: #f8d7da; color: #721c24; }
        .status-recuperacao { background: #fff3cd; color: #856404; }
        .print-header, .print-footer { display: none; }
        @media print {
            .sidebar, .top-bar, .menu-toggle, .btn, .no-print, .card-header .btn { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .print-header, .print-footer { display: block; }
            .status-aprovado, .status-reprovado, .status-recuperacao { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        .stats-card { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px; height: 100%; }
        .stats-number { font-size: 2em; font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
   <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-graduation-cap"></i> Relatório de Notas</h2>
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
                <form method="GET" class="row g-3" id="formFiltros">
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
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="form-control" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $disciplina_id == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>Bimestre</label>
                        <select name="bimestre" class="form-control">
                            <option value="0">Ano Completo</option>
                            <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($turma_id && $disciplina_id && !empty($relatorio)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div>Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $estatisticas['aprovados']; ?></div>
                    <div>Aprovados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-warning"><?php echo $estatisticas['recuperacao']; ?></div>
                    <div>Recuperação</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $estatisticas['reprovados']; ?></div>
                    <div>Reprovados</div>
                </div>
            </div>
        </div>
        
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
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0">Lista de Notas</h3>
                <div>
                    <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&bimestre=<?php echo $bimestre; ?>&export=pdf" class="btn btn-danger btn-sm" target="_blank">
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
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Exame</th>
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
                                <td><strong><?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1) : '-'; ?></strong></td>
                                <td class="<?php echo $status_class; ?>"><?php echo $status_text; ?></td>
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
        
        <?php if ($turma_id && $disciplina_id && !empty($relatorio)): ?>
        const ctx = document.getElementById('resultadosChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $estatisticas['aprovados']; ?>, <?php echo $estatisticas['recuperacao']; ?>, <?php echo $estatisticas['reprovados']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>