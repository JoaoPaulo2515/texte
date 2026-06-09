<?php
// escola/relatorios/estatistica_professor.php - Estatísticas de Professores

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$sexo_filtro = $_GET['genero'] ?? 'todos';

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_letivos)) {
    $anos_letivos = [['id' => 1, 'ano' => date('Y')]];
}
if ($ano_letivo_id == 0) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR ESTATÍSTICAS DOS PROFESSORES
// ============================================

// 1. Estatísticas gerais
$sql_geral = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN genero = 'masculino' THEN 1 ELSE 0 END) as masculino,
                SUM(CASE WHEN genero = 'feminino' THEN 1 ELSE 0 END) as feminino,
                SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as ativos,
                SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as inativos
              FROM funcionarios 
              WHERE escola_id = :escola_id and tipo_funcionario='professor'";
$stmt_geral = $conn->prepare($sql_geral);
$stmt_geral->execute([':escola_id' => $escola_id]);
$estatisticas_gerais = $stmt_geral->fetch(PDO::FETCH_ASSOC);

// 2. Distribuição por faixa etária
$sql_idade = "SELECT 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE()) < 25 THEN '18-24 anos'
                    WHEN TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE()) BETWEEN 25 AND 34 THEN '25-34 anos'
                    WHEN TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE()) BETWEEN 35 AND 44 THEN '35-44 anos'
                    WHEN TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE()) BETWEEN 45 AND 54 THEN '45-54 anos'
                    ELSE '55+ anos'
                END as faixa_etaria,
                COUNT(*) as total
              FROM funcionarios 
              WHERE escola_id = :escola_id and tipo_funcionario='professor' AND data_nascimento IS NOT NULL
              GROUP BY faixa_etaria
              ORDER BY MIN(TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE()))";
$stmt_idade = $conn->prepare($sql_idade);
$stmt_idade->execute([':escola_id' => $escola_id]);
$faixas_etarias = $stmt_idade->fetchAll(PDO::FETCH_ASSOC);

// 3. Professores por disciplina
$sql_disciplina = "SELECT 
                    d.nome as disciplina,
                    COUNT(DISTINCT pdt.professor_id) as total_professores
                  FROM disciplinas d
                  LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                  WHERE d.escola_id = :escola_id
                  GROUP BY d.id
                  ORDER BY total_professores DESC
                  LIMIT 10";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':escola_id' => $escola_id]);
$professores_por_disciplina = $stmt_disciplina->fetchAll(PDO::FETCH_ASSOC);

// 4. Professores por turma
$sql_turma = "SELECT 
                CONCAT(t.ano, 'ª ', t.nome) as turma,
                COUNT(DISTINCT pdt.professor_id) as total_professores
              FROM turmas t
              LEFT JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
              WHERE t.escola_id = :escola_id
              GROUP BY t.id
              ORDER BY total_professores DESC
              LIMIT 10";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':escola_id' => $escola_id]);
$professores_por_turma = $stmt_turma->fetchAll(PDO::FETCH_ASSOC);

// 5. Distribuição por nível de formação
$sql_formacao = "SELECT 
                    nivel_escolaridade,
                    COUNT(*) as total
                  FROM funcionarios 
                  WHERE escola_id = :escola_id  and tipo_funcionario='professor' AND nivel_escolaridade IS NOT NULL
                  GROUP BY nivel_escolaridade
                  ORDER BY total DESC";
$stmt_formacao = $conn->prepare($sql_formacao);
$stmt_formacao->execute([':escola_id' => $escola_id]);
$formacao_professores = $stmt_formacao->fetchAll(PDO::FETCH_ASSOC);

// 6. Professores com mais turmas
$sql_top_turmas = "SELECT 
                    p.nome,
                    COUNT(DISTINCT pdt.turma_id) as total_turmas,
                    GROUP_CONCAT(DISTINCT CONCAT(t.ano, 'ª ', t.nome) SEPARATOR ', ') as turmas
                  FROM funcionarios p
                  INNER JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                  INNER JOIN turmas t ON t.id = pdt.turma_id
                  WHERE p.escola_id = :escola_id AND p.status = 'ativo'  and p.tipo_funcionario='professor'
                  GROUP BY p.id
                  ORDER BY total_turmas DESC
                  LIMIT 10";
$stmt_top_turmas = $conn->prepare($sql_top_turmas);
$stmt_top_turmas->execute([':escola_id' => $escola_id]);
$top_professores_turmas = $stmt_top_turmas->fetchAll(PDO::FETCH_ASSOC);

// 7. Professores com mais disciplinas
$sql_top_disciplinas = "SELECT 
                          p.nome,
                          COUNT(DISTINCT pdt.disciplina_id) as total_disciplinas,
                          GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas
                        FROM funcionarios p
                        INNER JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                        INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
                        WHERE p.escola_id = :escola_id AND p.status = 'ativo'  and p.tipo_funcionario='professor'
                        GROUP BY p.id
                        ORDER BY total_disciplinas DESC
                        LIMIT 10";
$stmt_top_disciplinas = $conn->prepare($sql_top_disciplinas);
$stmt_top_disciplinas->execute([':escola_id' => $escola_id]);
$top_professores_disciplinas = $stmt_top_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// 8. Professores contratados por ano
$sql_contratacoes = "SELECT 
                        YEAR(created_at) as ano,
                        COUNT(*) as total
                      FROM funcionarios 
                      WHERE escola_id = :escola_id  and tipo_funcionario='professor' AND created_at IS NOT NULL
                      GROUP BY YEAR(created_at)
                      ORDER BY ano DESC
                      LIMIT 5";
$stmt_contratacoes = $conn->prepare($sql_contratacoes);
$stmt_contratacoes->execute([':escola_id' => $escola_id]);
$contratacoes_por_ano = $stmt_contratacoes->fetchAll(PDO::FETCH_ASSOC);

// 9. Média de idade dos professores
$sql_media_idade = "SELECT 
                      ROUND(AVG(TIMESTAMPDIFF(YEAR, data_nascimento, CURDATE())), 1) as media_idade
                    FROM funcionarios 
                    WHERE escola_id = :escola_id  and tipo_funcionario='professor' AND data_nascimento IS NOT NULL";
$stmt_media_idade = $conn->prepare($sql_media_idade);
$stmt_media_idade->execute([':escola_id' => $escola_id]);
$media_idade = $stmt_media_idade->fetch(PDO::FETCH_ASSOC)['media_idade'] ?? 0;

// 10. Contagem por gênero com porcentagens
$total_professores = $estatisticas_gerais['total'] ?: 1;
$porcentagem_masculino = round(($estatisticas_gerais['masculino'] / $total_professores) * 100, 1);
$porcentagem_feminino = round(($estatisticas_gerais['feminino'] / $total_professores) * 100, 1);

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas de Professores | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }
        
        .stat-number.total { color: #006B3E; }
        .stat-number.ativos { color: #28a745; }
        .stat-number.inativos { color: #dc3545; }
        .stat-number.media { color: #17a2b8; }
        
        .stat-label {
            color: #666;
            font-size: 13px;
            margin-top: 5px;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            border-left: 4px solid #006B3E;
            padding-left: 10px;
        }
        
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
        }
        
        .table-custom {
            font-size: 14px;
        }
        
        .table-custom th {
            background: #006B3E;
            color: white;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            .chart-container {
                break-inside: avoid;
            }
        }
        
        .btn-print {
            background: #17a2b8;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
        }
        
        .btn-print:hover {
            background: #138496;
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-line"></i> Estatísticas de Professores</h2>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Imprimir Relatório
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Cards de Resumo -->
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-success mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas_gerais['total']; ?></div>
                    <div class="stat-label">Total de Professores</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-user-check fa-2x text-primary mb-2"></i>
                    <div class="stat-number ativos"><?php echo $estatisticas_gerais['ativos']; ?></div>
                    <div class="stat-label">Professores Ativos</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-user-slash fa-2x text-danger mb-2"></i>
                    <div class="stat-number inativos"><?php echo $estatisticas_gerais['inativos']; ?></div>
                    <div class="stat-label">Professores Inativos</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-calendar-alt fa-2x text-info mb-2"></i>
                    <div class="stat-number media"><?php echo $media_idade; ?></div>
                    <div class="stat-label">Idade Média (anos)</div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos -->
        <div class="row">
            <!-- Gráfico de Gênero -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-venus-mars"></i> Distribuição por Gênero
                    </div>
                    <canvas id="generoChart" style="height: 250px;"></canvas>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Masculino: <?php echo $estatisticas_gerais['masculino']; ?> (<?php echo $porcentagem_masculino; ?>%) | 
                            Feminino: <?php echo $estatisticas_gerais['feminino']; ?> (<?php echo $porcentagem_feminino; ?>%)
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Gráfico de Faixa Etária -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-chart-bar"></i> Distribuição por Faixa Etária
                    </div>
                    <canvas id="idadeChart" style="height: 250px;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Professores por Disciplina -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-book"></i> Professores por Disciplina
                    </div>
                    <canvas id="disciplinaChart" style="height: 250px;"></canvas>
                </div>
            </div>
            
            <!-- Professores por Turma -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-users"></i> Professores por Turma
                    </div>
                    <canvas id="turmaChart" style="height: 250px;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Nível de Formação -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-graduation-cap"></i> Nível de Formação
                    </div>
                    <canvas id="formacaoChart" style="height: 250px;"></canvas>
                </div>
            </div>
            
            <!-- Contratações por Ano -->
            <div class="col-md-6">
                <div class="chart-container">
                    <div class="chart-title">
                        <i class="fas fa-chart-line"></i> Contratações por Ano
                    </div>
                    <canvas id="contratacoesChart" style="height: 250px;"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Top Professores por Turmas -->
        <div class="chart-container">
            <div class="chart-title">
                <i class="fas fa-trophy"></i> Top 10 Professores com Mais Turmas
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Professor</th>
                            <th>Total de Turmas</th>
                            <th>Turmas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_professores_turmas)): ?>
                            <tr><td colspan="4" class="text-center">Nenhum dado encontrado</td></tr>
                        <?php else: ?>
                            <?php foreach ($top_professores_turmas as $index => $prof): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($prof['nome']); ?></strong></td>
                                <td><span class="badge bg-primary"><?php echo $prof['total_turmas']; ?></span></td>
                                <td><?php echo htmlspecialchars($prof['turmas']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Top Professores por Disciplinas -->
        <div class="chart-container">
            <div class="chart-title">
                <i class="fas fa-trophy"></i> Top 10 Professores que Lecionam Mais Disciplinas
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Professor</th>
                            <th>Total de Disciplinas</th>
                            <th>Disciplinas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_professores_disciplinas)): ?>
                            <tr><td colspan="4" class="text-center">Nenhum dado encontrado</td></tr>
                        <?php else: ?>
                            <?php foreach ($top_professores_disciplinas as $index => $prof): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($prof['nome']); ?></strong></td>
                                <td><span class="badge bg-success"><?php echo $prof['total_disciplinas']; ?></span></td>
                                <td><?php echo htmlspecialchars($prof['disciplinas']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Resumo Geral -->
        <div class="chart-container">
            <div class="chart-title">
                <i class="fas fa-chart-pie"></i> Resumo Geral
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="fw-bold">Taxa de Atividade:</label>
                        <div class="progress mt-1" style="height: 25px;">
                            <?php 
                            $taxa_atividade = $estatisticas_gerais['total'] > 0 ? 
                                round(($estatisticas_gerais['ativos'] / $estatisticas_gerais['total']) * 100, 1) : 0;
                            ?>
                            <div class="progress-bar bg-success" style="width: <?php echo $taxa_atividade; ?>%;">
                                <?php echo $taxa_atividade; ?>% Ativos
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Proporção por Gênero:</label>
                        <div class="progress mt-1" style="height: 25px;">
                            <div class="progress-bar bg-primary" style="width: <?php echo $porcentagem_masculino; ?>%;">
                                <?php echo $porcentagem_masculino; ?>% Masculino
                            </div>
                            <div class="progress-bar bg-danger" style="width: <?php echo $porcentagem_feminino; ?>%;">
                                <?php echo $porcentagem_feminino; ?>% Feminino
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Informações Gerais:</strong><br>
                        • Média de idade: <?php echo $media_idade; ?> anos<br>
                        • Professores ativos: <?php echo $estatisticas_gerais['ativos']; ?><br>
                        • Professores inativos: <?php echo $estatisticas_gerais['inativos']; ?><br>
                        • Total de turmas atribuídas: <?php echo array_sum(array_column($top_professores_turmas, 'total_turmas')); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Gráfico de Gênero
        const ctxGenero = document.getElementById('generoChart').getContext('2d');
        new Chart(ctxGenero, {
            type: 'doughnut',
            data: {
                labels: ['Masculino', 'Feminino'],
                datasets: [{
                    data: [<?php echo $estatisticas_gerais['masculino']; ?>, <?php echo $estatisticas_gerais['feminino']; ?>],
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
        
        // Gráfico de Faixa Etária
        const idadesLabels = <?php echo json_encode(array_column($faixas_etarias, 'faixa_etaria')); ?>;
        const idadesValues = <?php echo json_encode(array_column($faixas_etarias, 'total')); ?>;
        
        const ctxIdade = document.getElementById('idadeChart').getContext('2d');
        new Chart(ctxIdade, {
            type: 'bar',
            data: {
                labels: idadesLabels,
                datasets: [{
                    label: 'Número de Professores',
                    data: idadesValues,
                    backgroundColor: '#006B3E',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        // Gráfico de Professores por Disciplina
        const disciplinasLabels = <?php echo json_encode(array_column($professores_por_disciplina, 'disciplina')); ?>;
        const disciplinasValues = <?php echo json_encode(array_column($professores_por_disciplina, 'total_professores')); ?>;
        
        const ctxDisciplina = document.getElementById('disciplinaChart').getContext('2d');
        new Chart(ctxDisciplina, {
            type: 'bar',
            data: {
                labels: disciplinasLabels,
                datasets: [{
                    label: 'Número de Professores',
                    data: disciplinasValues,
                    backgroundColor: '#1A2A6C',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        // Gráfico de Professores por Turma
        const turmasLabels = <?php echo json_encode(array_column($professores_por_turma, 'turma')); ?>;
        const turmasValues = <?php echo json_encode(array_column($professores_por_turma, 'total_professores')); ?>;
        
        const ctxTurma = document.getElementById('turmaChart').getContext('2d');
        new Chart(ctxTurma, {
            type: 'bar',
            data: {
                labels: turmasLabels,
                datasets: [{
                    label: 'Número de Professores',
                    data: turmasValues,
                    backgroundColor: '#28a745',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        // Gráfico de Formação
        const formacaoLabels = <?php echo json_encode(array_column($formacao_professores, 'nivel_escolaridade')); ?>;
        const formacaoValues = <?php echo json_encode(array_column($formacao_professores, 'total')); ?>;
        
        const ctxFormacao = document.getElementById('formacaoChart').getContext('2d');
        new Chart(ctxFormacao, {
            type: 'pie',
            data: {
                labels: formacaoLabels,
                datasets: [{
                    data: formacaoValues,
                    backgroundColor: ['#006B3E', '#1A2A6C', '#28a745', '#ffc107', '#17a2b8', '#dc3545'],
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
        
        // Gráfico de Contratações
        const contratacoesLabels = <?php echo json_encode(array_column($contratacoes_por_ano, 'ano')); ?>;
        const contratacoesValues = <?php echo json_encode(array_column($contratacoes_por_ano, 'total')); ?>;
        
        const ctxContratacoes = document.getElementById('contratacoesChart').getContext('2d');
        new Chart(ctxContratacoes, {
            type: 'line',
            data: {
                labels: contratacoesLabels,
                datasets: [{
                    label: 'Professores Contratados',
                    data: contratacoesValues,
                    backgroundColor: 'rgba(0, 107, 62, 0.1)',
                    borderColor: '#006B3E',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#006B3E',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
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