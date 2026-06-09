<?php
// escola/professor/provas/resultados_prova.php - Resultados da Prova

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
// Verificar se o professor está logado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}*/

$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';

// Buscar dados do professor
$sql_professor = "SELECT f.id as funcionario_id FROM funcionarios f WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $professor['funcionario_id'] ?? 0;

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada ou você não tem permissão para visualizá-la.');
}

// Filtros
$apenas_finalizadas = isset($_GET['finalizadas']) ? (int)$_GET['finalizadas'] : 1;
$busca_aluno = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR RESULTADOS DOS ALUNOS
// ==============================================
$sql_resultados = "SELECT 
                        t.id as tentativa_id,
                        t.tentativa_numero,
                        t.data_inicio,
                        t.data_fim,
                        t.data_entrega,
                        t.tempo_gasto_segundos,
                        t.pontuacao_total,
                        t.porcentagem,
                        t.aprovado,
                        t.status,
                        e.id as aluno_id,
                        e.nome as aluno_nome,
                        e.matricula as aluno_matricula
                    FROM online_provas_tentativas t
                    JOIN estudantes e ON e.id = t.aluno_id
                    WHERE t.prova_id = :prova_id";

if ($apenas_finalizadas == 1) {
    $sql_resultados .= " AND t.status = 'finalizada'";
}
if (!empty($busca_aluno)) {
    $sql_resultados .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca)";
}

$sql_resultados .= " ORDER BY t.pontuacao_total DESC, t.tempo_gasto_segundos ASC";

$stmt_resultados = $conn->prepare($sql_resultados);
$params = [':prova_id' => $prova_id];
if (!empty($busca_aluno)) {
    $params[':busca'] = "%$busca_aluno%";
}
$stmt_resultados->execute($params);
$resultados = $stmt_resultados->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_alunos = count($resultados);
$total_aprovados = 0;
$total_reprovados = 0;
$total_abandonadas = 0;
$soma_notas = 0;
$melhor_nota = 0;
$melhor_aluno = '';
$pior_nota = 100;
$pior_aluno = '';

foreach ($resultados as $resultado) {
    if ($resultado['status'] == 'abandonada') {
        $total_abandonadas++;
    } else {
        if ($resultado['aprovado'] == 1) {
            $total_aprovados++;
        } else {
            $total_reprovados++;
        }
        $soma_notas += $resultado['pontuacao_total'];
        
        if ($resultado['pontuacao_total'] > $melhor_nota) {
            $melhor_nota = $resultado['pontuacao_total'];
            $melhor_aluno = $resultado['aluno_nome'];
        }
        if ($resultado['pontuacao_total'] < $pior_nota) {
            $pior_nota = $resultado['pontuacao_total'];
            $pior_aluno = $resultado['aluno_nome'];
        }
    }
}

$media_notas = ($total_aprovados + $total_reprovados) > 0 ? round($soma_notas / ($total_aprovados + $total_reprovados), 1) : 0;
$taxa_aprovacao = ($total_aprovados + $total_reprovados) > 0 ? round(($total_aprovados / ($total_aprovados + $total_reprovados)) * 100, 1) : 0;

// ==============================================
// BUSCAR QUESTÕES PARA ESTATÍSTICA DE ACERTOS
// ==============================================
$sql_questoes = "SELECT q.id, q.enunciado, q.pontuacao
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados da Prova - <?php echo htmlspecialchars($prova['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
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
            transition: all 0.3s;
        }
        .resultado-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .resultado-header {
            padding: 12px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        .btn-exportar {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        .aprovado { border-left: 4px solid #28a745; }
        .reprovado { border-left: 4px solid #dc3545; }
        .abandonada { border-left: 4px solid #6c757d; }
    </style>
</head>
<body>
    
     <?php include '../includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="container-fluid">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-chart-line"></i> Resultados da Prova</h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($prova['titulo']); ?> - <?php echo $prova['disciplina_nome']; ?></p>
                <p class="text-muted small">Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
            </div>
            <div>
                <a href="listar_provas.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
                <button class="btn-exportar" onclick="exportarResultados()">
                    <i class="fas fa-file-excel"></i> Exportar
                </button>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_alunos; ?></div>
                    <div class="stat-label">Total de Alunos</div>
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
                    <div class="stat-value text-secondary"><?php echo $total_abandonadas; ?></div>
                    <div class="stat-label">Abandonaram</div>
                </div>
            </div>
        </div>

        <!-- Estatísticas Detalhadas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo number_format($media_notas, 1); ?></div>
                    <div class="stat-label">Média das Notas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $taxa_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $prova['nota_minima_aprovacao']; ?></div>
                    <div class="stat-label">Nota Mínima para Aprovação</div>
                </div>
            </div>
        </div>

        <!-- Melhor e Pior Aluno -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <i class="fas fa-trophy fa-2x text-warning mb-2"></i>
                    <div class="stat-value text-success"><?php echo number_format($melhor_nota, 1); ?></div>
                    <div class="stat-label">Melhor Nota</div>
                    <div class="text-muted"><?php echo htmlspecialchars($melhor_aluno); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <div class="stat-value text-danger"><?php echo number_format($pior_nota, 1); ?></div>
                    <div class="stat-label">Pior Nota</div>
                    <div class="text-muted"><?php echo htmlspecialchars($pior_aluno); ?></div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="id" value="<?php echo $prova_id; ?>">
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="apenas_finalizadas" name="finalizadas" value="1" <?php echo $apenas_finalizadas == 1 ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <label class="form-check-label" for="apenas_finalizadas">Apenas provas finalizadas</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="busca" class="form-control" placeholder="Buscar por aluno..." value="<?php echo htmlspecialchars($busca_aluno); ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Resultados -->
        <div class="card">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-list"></i> Desempenho dos Alunos
                <span class="badge bg-primary float-end"><?php echo count($resultados); ?> registros</span>
            </div>
            <div class="card-body">
                <?php if (empty($resultados)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                        <p>Nenhum resultado encontrado para esta prova.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Tentativa</th>
                                    <th>Data</th>
                                    <th>Tempo</th>
                                    <th>Nota</th>
                                    <th>%</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $index => $resultado): 
                                    $porcentagem = round($resultado['porcentagem'], 1);
                                    $cor_nota = $resultado['aprovado'] == 1 ? 'text-success' : ($resultado['status'] == 'abandonada' ? 'text-secondary' : 'text-danger');
                                    $tempo_formatado = gmdate("H:i:s", $resultado['tempo_gasto_segundos']);
                                ?>
                                <tr class="<?php echo $resultado['aprovado'] == 1 ? 'table-success' : ($resultado['status'] == 'abandonada' ? 'table-secondary' : 'table-danger'); ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($resultado['aluno_nome']); ?></td>
                                    <td><?php echo $resultado['aluno_matricula']; ?></td>
                                    <td class="text-center"><?php echo $resultado['tentativa_numero']; ?>ª</td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($resultado['data_entrega'] ?? $resultado['data_fim'])); ?></td>
                                    <td class="text-center"><?php echo $tempo_formatado; ?></td>
                                    <td class="text-center fw-bold <?php echo $cor_nota; ?>">
                                        <?php echo number_format($resultado['pontuacao_total'], 1); ?> / <?php echo $prova['nota_maxima']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $porcentagem; ?>%
                                        <div class="progress mt-1" style="height: 4px;">
                                            <div class="progress-bar <?php echo $resultado['aprovado'] == 1 ? 'bg-success' : 'bg-danger'; ?>" style="width: <?php echo $porcentagem; ?>%;"></div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($resultado['status'] == 'abandonada'): ?>
                                            <span class="badge bg-secondary">Abandonou</span>
                                        <?php elseif ($resultado['aprovado'] == 1): ?>
                                            <span class="badge bg-success">Aprovado</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Reprovado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="detalhes_aluno_prova.php?id=<?php echo $resultado['tentativa_id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                            <i class="fas fa-eye"></i> Detalhes
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary">
                                <tr>
                                    <td colspan="6" class="text-end fw-bold">Média:</td>
                                    <td class="text-center fw-bold"><?php echo number_format($media_notas, 1); ?></td>
                                    <td class="text-center fw-bold"><?php echo $media_notas > 0 ? round(($media_notas / $prova['nota_maxima']) * 100, 1) : 0; ?>%</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function exportarResultados() {
        window.location.href = 'exportar_resultados.php?id=<?php echo $prova_id; ?>&finalizadas=<?php echo $apenas_finalizadas; ?>&busca=<?php echo urlencode($busca_aluno); ?>';
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>