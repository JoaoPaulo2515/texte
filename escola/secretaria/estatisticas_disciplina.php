<?php
// escola/secretaria/estatisticas_disciplina.php - Estatísticas por Disciplina

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$is_secretaria = ($_SESSION['usuario_tipo'] == 'secretaria' || ($_SESSION['papel'] ?? '') == 'secretaria');
$is_admin = ($_SESSION['usuario_tipo'] == 'super_admin' || $_SESSION['usuario_tipo'] == 'admin_escola' || $_SESSION['usuario_tipo'] == 'diretor' || ($_SESSION['papel'] ?? '') == 'admin');

if (!$is_secretaria && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// Buscar disciplinas
$sql_disciplinas = "SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

$estatisticas = [];
$disciplina_selecionada = null;

if (isset($_GET['disciplina_id']) && $_GET['disciplina_id'] > 0) {
    $disciplina_id = (int)$_GET['disciplina_id'];
    
    $sql = "SELECT * FROM disciplinas WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $disciplina_id]);
    $disciplina_selecionada = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Média geral da disciplina
    $sql = "SELECT AVG(media_final) as media, COUNT(*) as total_notas FROM notas WHERE disciplina_id = :disciplina_id AND media_final IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':disciplina_id' => $disciplina_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $estatisticas['media_geral'] = round($result['media'] ?? 0, 2);
    $estatisticas['total_avaliacoes'] = $result['total_notas'] ?? 0;
    
    // Distribuição por faixa de nota
    $sql = "SELECT 
                SUM(CASE WHEN media_final >= 14 THEN 1 ELSE 0 END) as aprovados,
                SUM(CASE WHEN media_final >= 10 AND media_final < 14 THEN 1 ELSE 0 END) as exame,
                SUM(CASE WHEN media_final < 10 AND media_final > 0 THEN 1 ELSE 0 END) as reprovados,
                SUM(CASE WHEN media_final IS NULL OR media_final = 0 THEN 1 ELSE 0 END) as sem_nota
            FROM notas WHERE disciplina_id = :disciplina_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':disciplina_id' => $disciplina_id]);
    $estatisticas['distribuicao'] = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas por Disciplina | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .stat-card { text-align: center; padding: 20px; background: #f8f9fa; border-radius: 10px; height: 100%; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Estatísticas por Disciplina</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Selecione a Disciplina</label>
                        <select name="disciplina_id" class="form-select" required>
                            <option value="">Selecione uma disciplina</option>
                            <?php foreach ($disciplinas as $disciplina): ?>
                            <option value="<?php echo $disciplina['id']; ?>" <?php echo (isset($_GET['disciplina_id']) && $_GET['disciplina_id'] == $disciplina['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disciplina['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Gerar Estatísticas</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($disciplina_selecionada): ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $estatisticas['media_geral']; ?></div>
                    <div><i class="fas fa-chart-line"></i> Média Geral</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $estatisticas['total_avaliacoes']; ?></div>
                    <div><i class="fas fa-check-circle"></i> Total de Avaliações</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $estatisticas['distribuicao']['aprovados'] ?? 0; ?></div>
                    <div><i class="fas fa-graduation-cap"></i> Alunos Aprovados</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-donut"></i> Distribuição de Desempenho</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="graficoDesempenho" height="250"></canvas>
                        <script>
                            new Chart(document.getElementById('graficoDesempenho'), {
                                type: 'doughnut',
                                data: {
                                    labels: ['Aprovados (≥14)', 'Exame (10-13.9)', 'Reprovados (<10)', 'Sem Nota'],
                                    datasets: [{
                                        data: [<?php echo $estatisticas['distribuicao']['aprovados'] ?? 0; ?>, <?php echo $estatisticas['distribuicao']['exame'] ?? 0; ?>, <?php echo $estatisticas['distribuicao']['reprovados'] ?? 0; ?>, <?php echo $estatisticas['distribuicao']['sem_nota'] ?? 0; ?>],
                                        backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d']
                                    }]
                                }
                            });
                        </script>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações da Disciplina</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <tr><th>Disciplina</th><td><?php echo htmlspecialchars($disciplina_selecionada['nome']); ?></td></tr>
                            <tr><th>Código</th><td><?php echo htmlspecialchars($disciplina_selecionada['codigo'] ?? 'N/A'); ?></td></tr>
                            <tr><th>Média Geral</th><td><?php echo $estatisticas['media_geral']; ?></td></tr>
                            <tr><th>Total de Avaliações</th><td><?php echo $estatisticas['total_avaliacoes']; ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>