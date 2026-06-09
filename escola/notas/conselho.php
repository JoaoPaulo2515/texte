<?php
// escola/notas/conselho.php - Conselho de Notas (Visão completa por aluno)
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

// Verificar permissão (apenas admin, diretor ou super admin)
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor');

if (!$is_admin) {
    header('Location: index.php?error=Acesso negado');
    exit;
}

$ano_letivo = $_GET['ano'] ?? date('Y');
$turma_id = $_GET['turma_id'] ?? 0;
$aluno_id = $_GET['aluno_id'] ?? 0;

// Buscar turmas
$turmas = $conn->prepare("
    SELECT id, nome FROM turmas 
    WHERE escola_id = :escola_id AND ano_letivo = :ano AND status = 'ativa'
    ORDER BY nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos da turma
$alunos = [];
if ($turma_id) {
    $stmt = $conn->prepare("
        SELECT e.id, u.nome, e.matricula
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY u.nome
    ");
    $stmt->execute([':turma_id' => $turma_id]);
    $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar notas do aluno
$notas_aluno = [];
$disciplinas = [];
if ($aluno_id && $turma_id) {
    $stmt = $conn->prepare("
        SELECT d.id, d.nome, d.codigo,
               n.bimestre, n.mac, n.npt, n.exame_normal, n.exame_recurso, 
               n.exame_especial, n.media_final, n.status
        FROM disciplinas d
        LEFT JOIN notas n ON n.disciplina_id = d.id
        LEFT JOIN matriculas m ON m.id = n.matricula_id
        WHERE d.escola_id = :escola_id AND m.estudante_id = :aluno_id AND m.turma_id = :turma_id
        ORDER BY d.nome, n.bimestre
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':aluno_id' => $aluno_id,
        ':turma_id' => $turma_id
    ]);
    $notas_aluno = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por disciplina
    foreach ($notas_aluno as $nota) {
        $disciplinas[$nota['id']]['nome'] = $nota['nome'];
        $disciplinas[$nota['id']]['codigo'] = $nota['codigo'];
        $disciplinas[$nota['id']]['bimestres'][$nota['bimestre']] = $nota;
    }
}

$aluno_selecionado = null;
if ($aluno_id) {
    $stmt = $conn->prepare("
        SELECT e.*, u.nome, u.email
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.id = :id
    ");
    $stmt->execute([':id' => $aluno_id]);
    $aluno_selecionado = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conselho de Notas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .nota-aprovado { background-color: #d4edda; color: #155724; }
        .nota-reprovado { background-color: #f8d7da; color: #721c24; }
        .nota-recuperacao { background-color: #fff3cd; color: #856404; }
        .media-final { font-weight: bold; font-size: 1.1em; }
    </style>
</head>
<body>
   <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chalkboard"></i> Conselho de Notas</h2>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Selecionar Turma e Aluno</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label>Ano Letivo</label>
                        <select name="ano" class="form-control" onchange="this.form.submit()">
                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano_letivo == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>Turma</label>
                        <select name="turma_id" class="form-control" onchange="this.form.submit()">
                            <option value="">Selecione...</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Aluno</label>
                        <select name="aluno_id" class="form-control" <?php echo !$turma_id ? 'disabled' : ''; ?> onchange="this.form.submit()">
                            <option value="">Selecione...</option>
                            <?php foreach ($alunos as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo $aluno_id == $a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Consultar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($aluno_selecionado && !empty($disciplinas)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">
                    Histórico Académico - <?php echo htmlspecialchars($aluno_selecionado['nome']); ?>
                    <br><small>Matrícula: <?php echo $aluno_selecionado['matricula']; ?> | Ano Letivo: <?php echo $ano_letivo; ?></small>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Disciplina</th>
                                <th width="80">1º Bim</th>
                                <th width="80">2º Bim</th>
                                <th width="80">3º Bim</th>
                                <th width="100">Média Final</th>
                                <th width="100">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_medias = 0;
                            $total_disciplinas = 0;
                            foreach ($disciplinas as $disc_id => $disc):
                                $bim1 = $disc['bimestres'][1] ?? null;
                                $bim2 = $disc['bimestres'][2] ?? null;
                                $bim3 = $disc['bimestres'][3] ?? null;
                                
                                $media_bim1 = $bim1 ? round($bim1['media_final'] ?? ($bim1['mac'] + $bim1['npt']) / 2, 1) : null;
                                $media_bim2 = $bim2 ? round($bim2['media_final'] ?? ($bim2['mac'] + $bim2['npt']) / 2, 1) : null;
                                $media_bim3 = $bim3 ? round($bim3['media_final'] ?? ($bim3['mac'] + $bim3['npt']) / 2, 1) : null;
                                
                                $medias = array_filter([$media_bim1, $media_bim2, $media_bim3]);
                                $media_final = !empty($medias) ? array_sum($medias) / count($medias) : null;
                                $status = $bim3['status'] ?? ($bim2['status'] ?? ($bim1['status'] ?? ''));
                                
                                if ($media_final) {
                                    $total_medias += $media_final;
                                    $total_disciplinas++;
                                }
                                
                                $status_class = '';
                                $status_text = '';
                                if ($status == 'aprovado') {
                                    $status_class = 'nota-aprovado';
                                    $status_text = 'Aprovado';
                                } elseif ($status == 'reprovado') {
                                    $status_class = 'nota-reprovado';
                                    $status_text = 'Reprovado';
                                } elseif ($status == 'recuperacao') {
                                    $status_class = 'nota-recuperacao';
                                    $status_text = 'Recuperação';
                                } else {
                                    $status_text = 'Em curso';
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($disc['nome']); ?></strong></td>
                                <td class="text-center <?php echo $media_bim1 !== null ? ($media_bim1 >= 10 ? 'nota-aprovado' : ($media_bim1 >= 7 ? 'nota-recuperacao' : 'nota-reprovado')) : ''; ?>">
                                    <?php echo $media_bim1 !== null ? number_format($media_bim1, 1) : '-'; ?>
                                </td>
                                <td class="text-center <?php echo $media_bim2 !== null ? ($media_bim2 >= 10 ? 'nota-aprovado' : ($media_bim2 >= 7 ? 'nota-recuperacao' : 'nota-reprovado')) : ''; ?>">
                                    <?php echo $media_bim2 !== null ? number_format($media_bim2, 1) : '-'; ?>
                                </td>
                                <td class="text-center <?php echo $media_bim3 !== null ? ($media_bim3 >= 10 ? 'nota-aprovado' : ($media_bim3 >= 7 ? 'nota-recuperacao' : 'nota-reprovado')) : ''; ?>">
                                    <?php echo $media_bim3 !== null ? number_format($media_bim3, 1) : '-'; ?>
                                </td>
                                <td class="text-center media-final <?php echo $status_class; ?>">
                                    <?php echo $media_final !== null ? number_format($media_final, 1) : '-'; ?>
                                </td>
                                <td class="text-center <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="4">Média Geral</th>
                                <th class="text-center">
                                    <?php 
                                    $media_geral = $total_disciplinas > 0 ? $total_medias / $total_disciplinas : 0;
                                    echo number_format($media_geral, 1);
                                    ?>
                                </th>
                                <th class="text-center">
                                    <?php 
                                    if ($media_geral >= 10) {
                                        echo '<span class="badge bg-success">Aprovado</span>';
                                    } elseif ($media_geral >= 7) {
                                        echo '<span class="badge bg-warning">Recuperação</span>';
                                    } else {
                                        echo '<span class="badge bg-danger">Reprovado</span>';
                                    }
                                    ?>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php elseif ($aluno_id && empty($disciplinas)): ?>
        <div class="alert alert-info">Nenhuma nota encontrada para este aluno.</div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>