<?php
// escola/professor/relatorios/index.php - Dashboard de Relatórios do Professor

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// ESTATÍSTICAS PARA O DASHBOARD DE RELATÓRIOS
// ============================================

// Total de turmas do professor
$sql_turmas = "SELECT COUNT(DISTINCT turma_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$total_turmas = $stmt_turmas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql_disciplinas = "SELECT COUNT(DISTINCT disciplina_id) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$total_disciplinas = $stmt_disciplinas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de alunos
$sql_alunos = "
    SELECT COUNT(DISTINCT m.estudante_id) as total 
    FROM matriculas m
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = m.turma_id
    WHERE pdt.professor_id = :professor_id AND m.status = 'ativa'
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':professor_id' => $professor_id]);
$total_alunos = $stmt_alunos->fetch(PDO::FETCH_ASSOC)['total'];

// Total de atividades
$sql_atividades = "SELECT COUNT(*) as total FROM atividades WHERE professor_id = :professor_id";
$stmt_atividades = $conn->prepare($sql_atividades);
$stmt_atividades->execute([':professor_id' => $professor_id]);
$total_atividades = $stmt_atividades->fetch(PDO::FETCH_ASSOC)['total'];

// Total de chamadas realizadas
$sql_chamadas = "SELECT COUNT(*) as total FROM chamada WHERE professor_id = :professor_id";
$stmt_chamadas = $conn->prepare($sql_chamadas);
$stmt_chamadas->execute([':professor_id' => $professor_id]);
$total_chamadas = $stmt_chamadas->fetch(PDO::FETCH_ASSOC)['total'];

// Média geral de notas
$sql_media = "
    SELECT AVG(media_final) as media 
    FROM notas n
    INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = n.disciplina_id
    WHERE pdt.professor_id = :professor_id AND n.media_final > 0
";
$stmt_media = $conn->prepare($sql_media);
$stmt_media->execute([':professor_id' => $professor_id]);
$media_geral = round($stmt_media->fetch(PDO::FETCH_ASSOC)['media'] ?? 0, 1);

// Turmas por turno
$sql_turnos = "
    SELECT t.turno, COUNT(DISTINCT t.id) as total
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    GROUP BY t.turno
";
$stmt_turnos = $conn->prepare($sql_turnos);
$stmt_turnos->execute([':professor_id' => $professor_id]);
$turnos = $stmt_turnos->fetchAll(PDO::FETCH_ASSOC);

// Gráfico de notas por bimestre
$sql_notas_bimestre = "
    SELECT 
        n.bimestre,
        AVG(n.media_final) as media,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados
    FROM notas n
    INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = n.disciplina_id
    WHERE pdt.professor_id = :professor_id AND n.media_final > 0
    GROUP BY n.bimestre
    ORDER BY n.bimestre
";
$stmt_notas_bimestre = $conn->prepare($sql_notas_bimestre);
$stmt_notas_bimestre->execute([':professor_id' => $professor_id]);
$notas_bimestre = $stmt_notas_bimestre->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #006B3E;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .stat-icon {
            font-size: 35px;
            margin-bottom: 10px;
        }
        .card-header-custom {
            background: #f8f9fa;
            border-bottom: 2px solid #006B3E;
            font-weight: bold;
        }
        .btn-relatorio {
            border-radius: 25px;
            padding: 10px 20px;
            margin: 5px;
            transition: all 0.2s;
        }
        .btn-relatorio:hover {
            transform: translateY(-2px);
        }
        .btn-mini-pautas {
            background: #006B3E;
            color: white;
        }
        .btn-pautas-gerais {
            background: #1A2A6C;
            color: white;
        }
        .btn-estatistica {
            background: #17a2b8;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include '../includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chart-line"></i> Central de Relatórios</h2>
                    <p>Visualize e exporte relatórios detalhados do seu desempenho e dos seus alunos</p>
                </div>
                <a href="../dashboard.php" class="btn-voltar btn">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>
        
        <!-- Cards Estatísticos -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chalkboard text-primary"></i></div>
                    <div class="stat-number"><?php echo $total_turmas; ?></div>
                    <div class="stat-label">Turmas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-book text-success"></i></div>
                    <div class="stat-number"><?php echo $total_disciplinas; ?></div>
                    <div class="stat-label">Disciplinas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users text-info"></i></div>
                    <div class="stat-number"><?php echo $total_alunos; ?></div>
                    <div class="stat-label">Alunos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star text-warning"></i></div>
                    <div class="stat-number"><?php echo $media_geral; ?></div>
                    <div class="stat-label">Média Geral</div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-tasks text-secondary"></i></div>
                    <div class="stat-number"><?php echo $total_atividades; ?></div>
                    <div class="stat-label">Atividades</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clipboard-list text-danger"></i></div>
                    <div class="stat-number"><?php echo $total_chamadas; ?></div>
                    <div class="stat-label">Chamadas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock text-dark"></i></div>
                    <div class="stat-number">
                        <?php 
                        $total_turnos = 0;
                        foreach ($turnos as $t) $total_turnos += $t['total'];
                        echo $total_turnos;
                        ?>
                    </div>
                    <div class="stat-label">Turmas Ativas</div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header card-header-custom">
                        <i class="fas fa-chart-pie"></i> Distribuição por Turno
                    </div>
                    <div class="card-body">
                        <canvas id="chartTurnos" height="250"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header card-header-custom">
                        <i class="fas fa-chart-line"></i> Resultados por Bimestre
                    </div>
                    <div class="card-body">
                        <canvas id="chartNotas" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Relatórios Disponíveis -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header card-header-custom">
                        <i class="fas fa-file-alt"></i> Relatórios Disponíveis
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                        <h5>Mini Pautas</h5>
                                        <p class="text-muted">Relatório rápido de notas por turma/disciplina</p>
                                        <a href="mini_pautas.php" class="btn btn-relatorio btn-mini-pautas">
                                            <i class="fas fa-eye"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                        <h5>Pautas Gerais</h5>
                                        <p class="text-muted">Relatório completo com todas as notas da turma</p>
                                        <a href="pautas_gerais.php" class="btn btn-relatorio btn-pautas-gerais">
                                            <i class="fas fa-eye"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-chart-bar fa-3x text-primary mb-3"></i>
                                        <h5>Estatística por Turma</h5>
                                        <p class="text-muted">Análise estatística detalhada por turma</p>
                                        <a href="estatistica_turma.php" class="btn btn-relatorio btn-estatistica">
                                            <i class="fas fa-chart-line"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-chart-pie fa-3x text-success mb-3"></i>
                                        <h5>Estatística por Disciplina</h5>
                                        <p class="text-muted">Análise estatística detalhada por disciplina</p>
                                        <a href="estatistica_disciplina.php" class="btn btn-relatorio btn-estatistica">
                                            <i class="fas fa-chart-pie"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-history fa-3x text-info mb-3"></i>
                                        <h5>Histórico de Chamadas</h5>
                                        <p class="text-muted">Registro completo de frequência dos alunos</p>
                                        <a href="../historico_chamada.php" class="btn btn-relatorio" style="background:#17a2b8;color:white;">
                                            <i class="fas fa-history"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-graduation-cap fa-3x text-warning mb-3"></i>
                                        <h5>Boletim Individual</h5>
                                        <p class="text-muted">Notas e frequência detalhada por aluno</p>
                                        <a href="../meus_alunos.php" class="btn btn-relatorio" style="background:#ffc107;color:#212529;">
                                            <i class="fas fa-graduation-cap"></i> Visualizar
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de Turnos
        const turnosData = {
            labels: [<?php foreach ($turnos as $t) echo "'" . ucfirst($t['turno']) . "', "; ?>],
            datasets: [{
                data: [<?php foreach ($turnos as $t) echo $t['total'] . ", "; ?>],
                backgroundColor: ['#006B3E', '#1A2A6C', '#17a2b8', '#ffc107'],
                borderWidth: 0
            }]
        };
        
        const ctxTurnos = document.getElementById('chartTurnos').getContext('2d');
        new Chart(ctxTurnos, {
            type: 'pie',
            data: turnosData,
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Gráfico de Notas por Bimestre
        const bimestres = [<?php foreach ($notas_bimestre as $nb) echo $nb['bimestre'] . ", "; ?>];
        const medias = [<?php foreach ($notas_bimestre as $nb) echo $nb['media'] . ", "; ?>];
        const aprovados = [<?php foreach ($notas_bimestre as $nb) echo $nb['aprovados'] . ", "; ?>];
        const recuperacao = [<?php foreach ($notas_bimestre as $nb) echo $nb['recuperacao'] . ", "; ?>];
        const reprovados = [<?php foreach ($notas_bimestre as $nb) echo $nb['reprovados'] . ", "; ?>];
        
        const ctxNotas = document.getElementById('chartNotas').getContext('2d');
        new Chart(ctxNotas, {
            type: 'bar',
            data: {
                labels: bimestres.map(b => b + 'º Bimestre'),
                datasets: [
                    {
                        label: 'Média',
                        data: medias,
                        backgroundColor: '#006B3E',
                        borderRadius: 5
                    },
                    {
                        label: 'Aprovados',
                        data: aprovados,
                        backgroundColor: '#28a745',
                        borderRadius: 5
                    },
                    {
                        label: 'Recuperação',
                        data: recuperacao,
                        backgroundColor: '#ffc107',
                        borderRadius: 5
                    },
                    {
                        label: 'Reprovados',
                        data: reprovados,
                        backgroundColor: '#dc3545',
                        borderRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });
    </script>
</body>
</html>