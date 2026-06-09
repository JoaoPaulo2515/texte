<?php
// escola/professor/provas/resultados_provas.php - Resultados das Provas


require_once __DIR__ . '/../../config/database.php';
session_start();
/*
// Verificar se o professor está logado
if (!isset($_SESSION['professor_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}*/


$db = Database::getInstance();
$conn = $db->getConnection();
$professor_id = $_SESSION['professor_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['professor_nome'] ?? 'Professor';

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Definir título da página
$titulo_pagina = 'Resultados da Prova';

// ==============================================
// BUSCAR DADOS DA PROVA
// ==============================================
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :professor_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([
    ':prova_id' => $prova_id,
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    header('Location: historico_provas.php');
    exit;
}

// ==============================================
// BUSCAR TOTAL DE ALUNOS DA TURMA
// ==============================================
$sql_total_alunos = "SELECT COUNT(*) as total FROM matriculas 
                     WHERE turma_id = :turma_id AND status = 'ativa'";
$stmt_total = $conn->prepare($sql_total_alunos);
$stmt_total->execute([':turma_id' => $prova['turma_id']]);
$total_alunos_turma = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

// ==============================================
// BUSCAR RESULTADOS DOS ALUNOS
// ==============================================
$sql_resultados = "SELECT 
                        t.id as tentativa_id,
                        t.aluno_id,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.data_fim,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        t.status as tentativa_status,
                        est.nome as aluno_nome,
                        est.matricula as aluno_matricula,
                        (SELECT COUNT(*) FROM online_provas_respostas WHERE tentativa_id = t.id AND correta = 1) as acertos,
                        (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = t.prova_id) as total_questoes
                    FROM online_provas_tentativas t
                    JOIN estudantes est ON est.id = t.aluno_id
                    WHERE t.prova_id = :prova_id
                    AND t.status IN ('finalizada', 'abandonada')
                    ORDER BY t.pontuacao_total DESC, t.porcentagem DESC";

$stmt_resultados = $conn->prepare($sql_resultados);
$stmt_resultados->execute([':prova_id' => $prova_id]);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ALUNOS QUE NÃO FIZERAM A PROVA
// ==============================================
$ids_alunos = array_column($resultados, 'aluno_id');
$placeholders = implode(',', array_fill(0, count($ids_alunos), '?'));

if (!empty($ids_alunos)) {
    $sql_nao_fizeram = "SELECT est.id, est.nome, est.matricula
                        FROM estudantes est
                        JOIN matriculas m ON m.estudante_id = est.id
                        WHERE m.turma_id = :turma_id 
                        AND m.status = 'ativa'
                        AND est.id NOT IN ($placeholders)
                        ORDER BY est.nome ASC";
    $stmt_nao_fizeram = $conn->prepare($sql_nao_fizeram);
    $params = array_merge([':turma_id' => $prova['turma_id']], $ids_alunos);
    $stmt_nao_fizeram->execute($params);
    $nao_fizeram = $stmt_nao_fizeram->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql_nao_fizeram = "SELECT est.id, est.nome, est.matricula
                        FROM estudantes est
                        JOIN matriculas m ON m.estudante_id = est.id
                        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
                        ORDER BY est.nome ASC";
    $stmt_nao_fizeram = $conn->prepare($sql_nao_fizeram);
    $stmt_nao_fizeram->execute([':turma_id' => $prova['turma_id']]);
    $nao_fizeram = $stmt_nao_fizeram->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_realizaram = count($resultados);
$total_aprovados = 0;
$total_reprovados = 0;
$total_abandonaram = 0;
$soma_notas = 0;
$soma_acertos = 0;

foreach ($resultados as $res) {
    if ($res['aprovado'] == 1) {
        $total_aprovados++;
    } else {
        $total_reprovados++;
    }
    if ($res['tentativa_status'] == 'abandonada') {
        $total_abandonaram++;
    }
    $soma_notas += $res['pontuacao_total'];
    $soma_acertos += $res['acertos'];
}

$media_notas = $total_realizaram > 0 ? round($soma_notas / $total_realizaram, 1) : 0;
$media_acertos = $total_realizaram > 0 ? round($soma_acertos / $total_realizaram, 1) : 0;
$taxa_aprovacao = $total_realizaram > 0 ? round(($total_aprovados / $total_realizaram) * 100, 1) : 0;
$taxa_participacao = $total_alunos_turma > 0 ? round(($total_realizaram / $total_alunos_turma) * 100, 1) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarDuracao($segundos) {
    $minutos = floor($segundos / 60);
    $seg = $segundos % 60;
    return sprintf("%02d:%02d", $minutos, $seg);
}

function getNotaClass($nota, $max_nota = 20) {
    if ($nota === null) return 'text-secondary';
    $percentual = ($nota / $max_nota) * 100;
    if ($percentual >= 70) return 'text-success fw-bold';
    if ($percentual >= 50) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

function getAprovacaoBadge($aprovado) {
    if ($aprovado == 1) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Professor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .resultado-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .resultado-header {
            padding: 12px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .resultado-body {
            padding: 15px 20px;
        }
        
        .aluno-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .progress-bar-custom {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s;
        }
        
        .btn-ver-resposta {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.75rem;
        }
        
        .btn-ver-resposta:hover {
            background: #138496;
        }
        
        .nao-fizeram-card {
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin-bottom: 8px;
            border-radius: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-professor { display: none; }
        }
    </style>
</head>
<body>
 <?php include 'includes/menu_professor.php'; ?>
<div class="main-content-professor">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-line"></i> Resultados da Prova</h4>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($prova['titulo']); ?> - <?php echo $prova['disciplina_nome']; ?></p>
        </div>
        <div>
            <a href="historico_provas.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            <button class="btn btn-primary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações da Prova -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-book"></i> Disciplina</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($prova['disciplina_nome']); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-calendar-alt"></i> Data</small>
                    <h6 class="mb-0"><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?> até <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-star"></i> Nota Máxima</small>
                    <h6 class="mb-0"><?php echo $prova['nota_maxima']; ?> pontos</h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_realizaram; ?> / <?php echo $total_alunos_turma; ?></div>
                <div class="stat-label">Participação</div>
                <small><?php echo $taxa_participacao; ?>% da turma</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_aprovados; ?></div>
                <div class="stat-label">Aprovados</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_reprovados; ?></div>
                <div class="stat-label">Reprovados</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $taxa_aprovacao; ?>%</div>
                <div class="stat-label">Taxa de Aprovação</div>
            </div>
        </div>
    </div>
    
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($media_notas, 1); ?></div>
                <div class="stat-label">Média das Notas</div>
                <div class="progress-bar-custom mt-2">
                    <div class="progress-fill" style="width: <?php echo ($media_notas / $prova['nota_maxima']) * 100; ?>%; background: #006B3E;"></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($media_acertos, 1); ?></div>
                <div class="stat-label">Média de Acertos</div>
                <div class="progress-bar-custom mt-2">
                    <div class="progress-fill" style="width: <?php echo ($media_acertos / ($resultados[0]['total_questoes'] ?? 1)) * 100; ?>%; background: #17a2b8;"></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_abandonaram; ?></div>
                <div class="stat-label">Provas Abandonadas</div>
            </div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">Distribuição de Notas</div>
                <div class="card-body">
                    <canvas id="graficoDistribuicao" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">Situação dos Alunos</div>
                <div class="card-body">
                    <canvas id="graficoSituacao" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Lista de Resultados -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-users"></i> Desempenho dos Alunos
            <span class="badge bg-secondary ms-2"><?php echo $total_realizaram; ?> alunos</span>
        </div>
        <div class="card-body">
            <?php if (empty($resultados)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhum aluno realizou esta prova ainda.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Acertos</th>
                                <th>Nota</th>
                                <th>%</th>
                                <th>Tempo</th>
                                <th>Situação</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $index => $res): 
                                $percentual = ($res['pontuacao_total'] / $prova['nota_maxima']) * 100;
                                $nota_class = getNotaClass($res['pontuacao_total'], $prova['nota_maxima']);
                                $tempo_formatado = formatarDuracao($res['tempo_gasto_segundos']);
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="aluno-avatar" style="width: 32px; height: 32px; font-size: 12px;">
                                            <?php echo strtoupper(substr($res['aluno_nome'], 0, 1)); ?>
                                        </div>
                                        <?php echo htmlspecialchars($res['aluno_nome']); ?>
                                    </div>
                                </td>
                                <td><?php echo $res['aluno_matricula']; ?></td>
                                <td class="text-center"><?php echo $res['acertos']; ?> / <?php echo $res['total_questoes']; ?></td>
                                <td class="text-center <?php echo $nota_class; ?>"><?php echo number_format($res['pontuacao_total'], 1); ?></td>
                                <td class="text-center"><?php echo number_format($percentual, 1); ?>%</td>
                                <td class="text-center"><?php echo $tempo_formatado; ?></td>
                                <td class="text-center"><?php echo getAprovacaoBadge($res['aprovado']); ?></td>
                                <td class="text-center">
                                    <button class="btn-ver-resposta" onclick="verRespostas(<?php echo $res['tentativa_id']; ?>)">
                                        <i class="fas fa-eye"></i> Ver Respostas
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="3">Médias</th>
                                <th class="text-center"><?php echo number_format($media_acertos, 1); ?></th>
                                <th class="text-center"><?php echo number_format($media_notas, 1); ?></th>
                                <th class="text-center"><?php echo number_format(($media_notas / $prova['nota_maxima']) * 100, 1); ?>%</th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Alunos que não realizaram a prova -->
    <?php if (!empty($nao_fizeram)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-user-slash"></i> Alunos que não realizaram a prova
            <span class="badge bg-warning ms-2"><?php echo count($nao_fizeram); ?> alunos</span>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($nao_fizeram as $aluno): ?>
                <div class="col-md-4">
                    <div class="nao-fizeram-card">
                        <div class="d-flex align-items-center gap-2">
                            <div class="aluno-avatar" style="width: 32px; height: 32px; font-size: 12px; background: #6c757d;">
                                <?php echo strtoupper(substr($aluno['nome'], 0, 1)); ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                <small class="text-muted">Matrícula: <?php echo $aluno['matricula']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Gráfico de Distribuição de Notas
    const faixas = ['0-4', '4-8', '8-12', '12-16', '16-20'];
    const quantidades = [0, 0, 0, 0, 0];
    
    <?php foreach ($resultados as $res): 
        $nota = $res['pontuacao_total'];
        if ($nota < 4) echo "quantidades[0]++;";
        elseif ($nota < 8) echo "quantidades[1]++;";
        elseif ($nota < 12) echo "quantidades[2]++;";
        elseif ($nota < 16) echo "quantidades[3]++;";
        else echo "quantidades[4]++;";
    endforeach; ?>
    
    new Chart(document.getElementById('graficoDistribuicao'), {
        type: 'bar',
        data: {
            labels: faixas,
            datasets: [{
                label: 'Número de Alunos',
                data: quantidades,
                backgroundColor: '#006B3E',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'Quantidade de Alunos' } },
                x: { title: { display: true, text: 'Faixas de Nota' } }
            }
        }
    });
    
    // Gráfico de Situação
    new Chart(document.getElementById('graficoSituacao'), {
        type: 'pie',
        data: {
            labels: ['Aprovados', 'Reprovados'],
            datasets: [{
                data: [<?php echo $total_aprovados; ?>, <?php echo $total_reprovados; ?>],
                backgroundColor: ['#28a745', '#dc3545']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw + ' alunos (' + ((context.raw / <?php echo $total_realizaram; ?>) * 100).toFixed(1) + '%)'; } } }
            }
        }
    });
    
    // Função para ver respostas
    function verRespostas(tentativaId) {
        window.open('ver_respostas_aluno.php?tentativa=' + tentativaId, '_blank', 'width=900,height=700');
    }
</script>
</body>
</html>