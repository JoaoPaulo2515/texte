<?php
// escola/avaliacao/aproveitamento/index.php - Aproveitamento de Estudos

require_once __DIR__ . '/../../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'anual';
$view = isset($_GET['view']) ? $_GET['view'] : 'geral';

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos_turma = [];
if ($turma_id > 0) {
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatus($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'text-secondary', 'icone' => 'fa-minus-circle', 'bg' => 'secondary'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle', 'bg' => 'success'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle', 'bg' => 'warning'];
    return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle', 'bg' => 'danger'];
}

function getPercentual($valor, $total) {
    if ($total == 0) return 0;
    return round(($valor / $total) * 100, 1);
}

// ============================================
// BUSCAR DADOS DE APROVEITAMENTO
// ============================================
$aproveitamento = [];
$estatisticas_gerais = [
    'total_alunos' => 0,
    'total_aprovados' => 0,
    'total_exame' => 0,
    'total_reprovados' => 0,
    'total_sem_nota' => 0,
    'media_geral' => 0,
    'aproveitamento_geral' => 0,
    'taxa_aprovacao' => 0
];

$aproveitamento_por_turma = [];
$aproveitamento_por_disciplina = [];
$ranking_alunos = [];

if ($turma_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas da turma
    $sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                        FROM disciplinas d
                        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                        WHERE pdt.turma_id = :turma_id
                        ORDER BY d.nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Se visualização por aluno
    if ($view == 'aluno' && $aluno_id > 0) {
        // Buscar informações do aluno
        $sql_aluno_info = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento
                          FROM estudantes e
                          WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
        $stmt_aluno_info = $conn->prepare($sql_aluno_info);
        $stmt_aluno_info->execute([
            ':aluno_id' => $aluno_id,
            ':escola_id' => $escola_id
        ]);
        $aluno_info = $stmt_aluno_info->fetch(PDO::FETCH_ASSOC);
        
        if ($aluno_info) {
            $disciplinas_notas = [];
            $media_geral_aluno = 0;
            $total_notas = 0;
            $aprovadas = 0;
            $exame = 0;
            $reprovadas = 0;
            
            foreach ($disciplinas as $disciplina) {
                $notas_trimestres = [];
                $medias = [];
                
                for ($trim = 1; $trim <= 3; $trim++) {
                    $sql_nota = "SELECT mac, npt, exame_normal, media_final
                                FROM notas 
                                WHERE estudante_id = :aluno_id 
                                AND disciplina_id = :disciplina_id 
                                AND bimestre = :trimestre
                                AND ano_letivo_id = :ano_letivo_id";
                    $stmt_nota = $conn->prepare($sql_nota);
                    $stmt_nota->execute([
                        ':aluno_id' => $aluno_id,
                        ':disciplina_id' => $disciplina['id'],
                        ':trimestre' => $trim,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                    
                    $media = $nota_data ? (float)$nota_data['media_final'] : null;
                    $mac = $nota_data ? (float)$nota_data['mac'] : null;
                    $npt = $nota_data ? (float)$nota_data['npt'] : null;
                    $exame_normal = $nota_data ? (float)$nota_data['exame_normal'] : null;
                    
                    if ($media === null && ($mac !== null || $npt !== null)) {
                        if ($exame_normal !== null) {
                            $media = ($mac + $npt) / 2 * 0.4 + $exame_normal * 0.6;
                        } else {
                            $media = ($mac + $npt) / 2;
                        }
                        $media = round($media, 2);
                    }
                    
                    $medias[] = $media;
                    $status = getStatus($media);
                    
                    $notas_trimestres[$trim] = [
                        'mac' => $mac,
                        'npt' => $npt,
                        'exame' => $exame_normal,
                        'media' => $media,
                        'status' => $status
                    ];
                }
                
                $medias_validas = array_filter($medias, function($m) { return $m !== null && $m > 0; });
                $media_anual = !empty($medias_validas) ? round(array_sum($medias_validas) / count($medias_validas), 2) : null;
                $status_anual = getStatus($media_anual);
                
                if ($media_anual !== null && $media_anual > 0) {
                    $media_geral_aluno += $media_anual;
                    $total_notas++;
                    if ($media_anual >= 14) $aprovadas++;
                    elseif ($media_anual >= 10) $exame++;
                    else $reprovadas++;
                }
                
                $disciplinas_notas[] = [
                    'id' => $disciplina['id'],
                    'nome' => $disciplina['nome'],
                    'codigo' => $disciplina['codigo'],
                    'notas' => $notas_trimestres,
                    'media_anual' => $media_anual,
                    'status' => $status_anual
                ];
            }
            
            $media_geral_aluno = $total_notas > 0 ? round($media_geral_aluno / $total_notas, 2) : 0;
            $status_geral = getStatus($media_geral_aluno);
            
            $aproveitamento = [
                'aluno' => $aluno_info,
                'disciplinas' => $disciplinas_notas,
                'media_geral' => $media_geral_aluno,
                'status' => $status_geral,
                'aprovadas' => $aprovadas,
                'exame' => $exame,
                'reprovadas' => $reprovadas
            ];
        }
    } 
    // Visualização geral da turma
    else {
        // Calcular aproveitamento por aluno
        $alunos_aproveitamento = [];
        $soma_medias = 0;
        $total_alunos_com_media = 0;
        
        foreach ($alunos_turma as $aluno) {
            $soma_notas_aluno = 0;
            $total_disciplinas_aluno = 0;
            
            foreach ($disciplinas as $disciplina) {
                $sql_media = "SELECT AVG(media_final) as media_anual
                             FROM notas 
                             WHERE estudante_id = :aluno_id 
                             AND disciplina_id = :disciplina_id 
                             AND ano_letivo_id = :ano_letivo_id";
                $stmt_media = $conn->prepare($sql_media);
                $stmt_media->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disciplina_id' => $disciplina['id'],
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                $media_data = $stmt_media->fetch(PDO::FETCH_ASSOC);
                $media_anual = $media_data ? (float)$media_data['media_anual'] : null;
                
                if ($media_anual !== null && $media_anual > 0) {
                    $soma_notas_aluno += $media_anual;
                    $total_disciplinas_aluno++;
                }
            }
            
            $media_geral_aluno = $total_disciplinas_aluno > 0 ? round($soma_notas_aluno / $total_disciplinas_aluno, 2) : 0;
            $status = getStatus($media_geral_aluno);
            
            if ($media_geral_aluno > 0) {
                $soma_medias += $media_geral_aluno;
                $total_alunos_com_media++;
                
                if ($media_geral_aluno >= 14) $estatisticas_gerais['total_aprovados']++;
                elseif ($media_geral_aluno >= 10) $estatisticas_gerais['total_exame']++;
                else $estatisticas_gerais['total_reprovados']++;
            } else {
                $estatisticas_gerais['total_sem_nota']++;
            }
            
            $alunos_aproveitamento[] = [
                'id' => $aluno['id'],
                'nome' => $aluno['nome'],
                'matricula' => $aluno['matricula'],
                'genero' => $aluno['genero'],
                'media' => $media_geral_aluno,
                'status' => $status
            ];
            
            $ranking_alunos[] = [
                'id' => $aluno['id'],
                'nome' => $aluno['nome'],
                'matricula' => $aluno['matricula'],
                'media' => $media_geral_aluno
            ];
        }
        
        $estatisticas_gerais['total_alunos'] = count($alunos_turma);
        $estatisticas_gerais['media_geral'] = $total_alunos_com_media > 0 ? round($soma_medias / $total_alunos_com_media, 2) : 0;
        $estatisticas_gerais['aproveitamento_geral'] = $estatisticas_gerais['total_alunos'] > 0 ? 
            round(($estatisticas_gerais['total_aprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
        
        usort($ranking_alunos, function($a, $b) {
            return $b['media'] <=> $a['media'];
        });
        
        foreach ($ranking_alunos as $key => $aluno) {
            $ranking_alunos[$key]['posicao'] = $key + 1;
        }
        
        $aproveitamento = $alunos_aproveitamento;
        
        // Calcular aproveitamento por disciplina
        foreach ($disciplinas as $disciplina) {
            $soma_notas_disc = 0;
            $total_alunos_disc = 0;
            $aprovados_disc = 0;
            $exame_disc = 0;
            $reprovados_disc = 0;
            
            foreach ($alunos_turma as $aluno) {
                $sql_media = "SELECT AVG(media_final) as media_anual
                             FROM notas 
                             WHERE estudante_id = :aluno_id 
                             AND disciplina_id = :disciplina_id 
                             AND ano_letivo_id = :ano_letivo_id";
                $stmt_media = $conn->prepare($sql_media);
                $stmt_media->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disciplina_id' => $disciplina['id'],
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                $media_data = $stmt_media->fetch(PDO::FETCH_ASSOC);
                $media_anual = $media_data ? (float)$media_data['media_anual'] : null;
                
                if ($media_anual !== null && $media_anual > 0) {
                    $soma_notas_disc += $media_anual;
                    $total_alunos_disc++;
                    if ($media_anual >= 14) $aprovados_disc++;
                    elseif ($media_anual >= 10) $exame_disc++;
                    else $reprovados_disc++;
                }
            }
            
            $media_disc = $total_alunos_disc > 0 ? round($soma_notas_disc / $total_alunos_disc, 2) : 0;
            $taxa_aprovacao_disc = $total_alunos_disc > 0 ? round(($aprovados_disc / $total_alunos_disc) * 100, 1) : 0;
            
            $aproveitamento_por_disciplina[] = [
                'id' => $disciplina['id'],
                'nome' => $disciplina['nome'],
                'codigo' => $disciplina['codigo'],
                'media' => $media_disc,
                'aprovados' => $aprovados_disc,
                'exame' => $exame_disc,
                'reprovados' => $reprovados_disc,
                'total_alunos' => $total_alunos_disc,
                'taxa_aprovacao' => $taxa_aprovacao_disc
            ];
        }
        
        usort($aproveitamento_por_disciplina, function($a, $b) {
            return $b['taxa_aprovacao'] <=> $a['taxa_aprovacao'];
        });
    }
}

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
    <title>Aproveitamento de Estudos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }
        
        .card-dashboard {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
            border-left: 4px solid #006B3E;
            padding-left: 12px;
        }
        
        .table-disciplinas th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-disciplinas td {
            text-align: center;
            vertical-align: middle;
        }
        
        .ranking-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .ranking-position {
            width: 35px;
            height: 35px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .ranking-position.top1 { background: #ffd700; color: #333; }
        .ranking-position.top2 { background: #c0c0c0; color: #333; }
        .ranking-position.top3 { background: #cd7f32; color: white; }
        
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
        }
        
        .taxa-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .taxa-alta { background: #d4edda; color: #155724; }
        .taxa-media { background: #fff3cd; color: #856404; }
        .taxa-baixa { background: #f8d7da; color: #721c24; }
        
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 10px; }
            .page-header { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-chart-line"></i> Aproveitamento de Estudos</h2>
                    <p>Análise de desempenho e aproveitamento dos alunos</p>
                </div>
                <div class="no-print">
                    <button onclick="window.print()" class="btn btn-light">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <a href="../dashboard.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Selecione uma turma...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Aluno</label>
                    <select name="aluno_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($alunos_turma as $aluno): ?>
                        <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_id == $aluno['id'] ? 'selected' : ''; ?>>
                            <?php echo $aluno['matricula'] . ' - ' . $aluno['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0): ?>
        
        <!-- Visualização Geral da Turma -->
        <?php if ($view == 'geral'): ?>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="stat-number"><?php echo $estatisticas_gerais['total_alunos']; ?></div>
                    <small>Total de Alunos</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <div class="stat-number"><?php echo number_format($estatisticas_gerais['media_geral'], 1, ',', '.'); ?></div>
                    <small>Média Geral</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-percent fa-2x text-success mb-2"></i>
                    <div class="stat-number"><?php echo $estatisticas_gerais['aproveitamento_geral']; ?>%</div>
                    <small>Aproveitamento</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="stat-card">
                    <i class="fas fa-trophy fa-2x text-warning mb-2"></i>
                    <div class="stat-number"><?php echo $ranking_alunos[0]['media'] ?? 0; ?></div>
                    <small>Melhor Média</small>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number text-success"><?php echo $estatisticas_gerais['total_aprovados']; ?></div>
                    <small>Aprovados</small>
                    <div class="progress mt-2">
                        <div class="progress-bar bg-success" style="width: <?php echo getPercentual($estatisticas_gerais['total_aprovados'], $estatisticas_gerais['total_alunos']); ?>%">
                            <?php echo getPercentual($estatisticas_gerais['total_aprovados'], $estatisticas_gerais['total_alunos']); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <div class="stat-number text-warning"><?php echo $estatisticas_gerais['total_exame']; ?></div>
                    <small>Exame</small>
                    <div class="progress mt-2">
                        <div class="progress-bar bg-warning" style="width: <?php echo getPercentual($estatisticas_gerais['total_exame'], $estatisticas_gerais['total_alunos']); ?>%">
                            <?php echo getPercentual($estatisticas_gerais['total_exame'], $estatisticas_gerais['total_alunos']); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stat-number text-danger"><?php echo $estatisticas_gerais['total_reprovados']; ?></div>
                    <small>Reprovados</small>
                    <div class="progress mt-2">
                        <div class="progress-bar bg-danger" style="width: <?php echo getPercentual($estatisticas_gerais['total_reprovados'], $estatisticas_gerais['total_alunos']); ?>%">
                            <?php echo getPercentual($estatisticas_gerais['total_reprovados'], $estatisticas_gerais['total_alunos']); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ranking e Aproveitamento por Disciplina -->
        <div class="row">
            <!-- Ranking dos Alunos -->
            <div class="col-md-6">
                <div class="card-dashboard">
                    <div class="card-title">
                        <i class="fas fa-trophy"></i> Ranking de Desempenho
                    </div>
                    <div class="ranking-list">
                        <?php foreach (array_slice($ranking_alunos, 0, 10) as $aluno): ?>
                        <div class="ranking-item">
                            <div class="ranking-position <?php echo $aluno['posicao'] == 1 ? 'top1' : ($aluno['posicao'] == 2 ? 'top2' : ($aluno['posicao'] == 3 ? 'top3' : '')); ?>">
                                <?php echo $aluno['posicao']; ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                <br><small class="text-muted">Matrícula: <?php echo $aluno['matricula']; ?></small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary"><?php echo number_format($aluno['media'], 1, ',', '.'); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Aproveitamento por Disciplina - TABELA ORGANIZADA -->
            <div class="col-md-6">
                <div class="card-dashboard">
                    <div class="card-title">
                        <i class="fas fa-chart-bar"></i> Aproveitamento por Disciplina
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-disciplinas">
                            <thead>
                                <tr>
                                    <th width="30%">Disciplina</th>
                                    <th width="12%">Média</th>
                                    <th width="12%">Aprovados</th>
                                    <th width="12%">Exame</th>
                                    <th width="12%">Reprovados</th>
                                    <th width="12%">Total</th>
                                    <th width="10%">Taxa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($aproveitamento_por_disciplina)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Nenhum dado disponível</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($aproveitamento_por_disciplina as $disc): 
                                        $taxa_class = $disc['taxa_aprovacao'] >= 70 ? 'taxa-alta' : ($disc['taxa_aprovacao'] >= 50 ? 'taxa-media' : 'taxa-baixa');
                                    ?>
                                    <tr>
                                        <td class="text-start">
                                            <strong><?php echo htmlspecialchars($disc['nome']); ?></strong>
                                            <?php if ($disc['codigo']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($disc['codigo']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-bold">
                                            <?php echo number_format($disc['media'], 1, ',', '.'); ?>
                                        </td>
                                        <td class="text-center text-success">
                                            <i class="fas fa-check-circle"></i> <?php echo $disc['aprovados']; ?>
                                        </td>
                                        <td class="text-center text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> <?php echo $disc['exame']; ?>
                                        </td>
                                        <td class="text-center text-danger">
                                            <i class="fas fa-times-circle"></i> <?php echo $disc['reprovados']; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?php echo $disc['total_alunos']; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="taxa-badge <?php echo $taxa_class; ?>">
                                                <?php echo $disc['taxa_aprovacao']; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-secondary">
                                    <td><strong>Média da Turma</strong></td>
                                    <td class="text-center fw-bold"><?php echo number_format($estatisticas_gerais['media_geral'], 1, ',', '.'); ?></td>
                                    <td colspan="5" class="text-end fw-bold">
                                        Taxa Geral de Aproveitamento: 
                                        <span class="taxa-badge <?php echo $estatisticas_gerais['aproveitamento_geral'] >= 70 ? 'taxa-alta' : ($estatisticas_gerais['aproveitamento_geral'] >= 50 ? 'taxa-media' : 'taxa-baixa'); ?>">
                                            <?php echo $estatisticas_gerais['aproveitamento_geral']; ?>%
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Alunos -->
        <div class="card-dashboard mt-3">
            <div class="card-title">
                <i class="fas fa-table"></i> Desempenho Individual dos Alunos
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="tabelaAproveitamento">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="12%">Matrícula</th>
                            <th width="35%">Aluno</th>
                            <th width="8%">Gênero</th>
                            <th width="15%">Média Geral</th>
                            <th width="15%">Status</th>
                            <th width="10%">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aproveitamento as $index => $aluno): ?>
                        <tr>
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                            <td class="text-center">
                                <?php if ($aluno['genero'] == 'masculino'): ?>
                                    <i class="fas fa-mars text-primary"></i> M
                                <?php else: ?>
                                    <i class="fas fa-venus text-danger"></i> F
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold">
                                <?php echo $aluno['media'] > 0 ? number_format($aluno['media'], 1, ',', '.') : '---'; ?>
                            </td>
                            <td class="text-center <?php echo $aluno['status']['classe']; ?>">
                                <i class="fas <?php echo $aluno['status']['icone']; ?>"></i> <?php echo $aluno['status']['texto']; ?>
                            </td>
                            <td class="text-center">
                                <a href="?turma_id=<?php echo $turma_id; ?>&aluno_id=<?php echo $aluno['id']; ?>&view=aluno" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> Detalhes
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Visualização por Aluno -->
        <?php elseif ($view == 'aluno' && $aluno_id > 0 && !empty($aproveitamento)): ?>
        
        <!-- Informações do Aluno -->
        <div class="card-dashboard">
            <div class="row">
                <div class="col-md-8">
                    <h4><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($aproveitamento['aluno']['nome']); ?></h4>
                    <p class="mb-0">
                        <strong>Matrícula:</strong> <?php echo $aproveitamento['aluno']['matricula']; ?> |
                        <strong>Gênero:</strong> <?php echo $aproveitamento['aluno']['genero'] == 'masculino' ? 'Masculino' : 'Feminino'; ?> |
                        <strong>Data Nascimento:</strong> <?php echo date('d/m/Y', strtotime($aproveitamento['aluno']['data_nascimento'])); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($aproveitamento['media_geral'], 1, ',', '.'); ?></div>
                        <small>Média Geral</small>
                        <div class="mt-1">
                            <span class="badge bg-<?php echo $aproveitamento['status']['bg']; ?>">
                                <?php echo $aproveitamento['status']['texto']; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resumo de Aproveitamento -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number"><?php echo $aproveitamento['aprovadas']; ?></div>
                    <small>Disciplinas Aprovadas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <div class="stat-number"><?php echo $aproveitamento['exame']; ?></div>
                    <small>Disciplinas em Exame</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stat-number"><?php echo $aproveitamento['reprovadas']; ?></div>
                    <small>Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas por Disciplina -->
        <div class="card-dashboard">
            <div class="card-title">
                <i class="fas fa-table"></i> Desempenho por Disciplina
            </div>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th rowspan="2" width="25%">Disciplina</th>
                            <th colspan="3" class="text-center">1º Trimestre</th>
                            <th colspan="3" class="text-center">2º Trimestre</th>
                            <th colspan="3" class="text-center">3º Trimestre</th>
                            <th rowspan="2" width="10%">Média Anual</th>
                            <th rowspan="2" width="12%">Status</th>
                        </tr>
                        <tr>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                            <th>MAC</th><th>NPT</th><th>Média</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aproveitamento['disciplinas'] as $disciplina): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                                <?php if ($disciplina['codigo']): ?>
                                    <br><small class="text-muted"><?php echo $disciplina['codigo']; ?></small>
                                <?php endif; ?>
                            </td>
                            <?php for ($t = 1; $t <= 3; $t++): 
                                $nota = $disciplina['notas'][$t];
                            ?>
                                <td class="text-center"><?php echo $nota['mac'] !== null ? number_format($nota['mac'], 1, ',', '.') : '---'; ?></td>
                                <td class="text-center"><?php echo $nota['npt'] !== null ? number_format($nota['npt'], 1, ',', '.') : '---'; ?></td>
                                <td class="text-center fw-bold <?php echo $nota['status']['classe']; ?>">
                                    <?php echo $nota['media'] !== null ? number_format($nota['media'], 1, ',', '.') : '---'; ?>
                                 </td>
                            <?php endfor; ?>
                            <td class="text-center fw-bold <?php echo $disciplina['status']['classe']; ?>">
                                <?php echo $disciplina['media_anual'] !== null ? number_format($disciplina['media_anual'], 1, ',', '.') : '---'; ?>
                            </td>
                            <td class="text-center <?php echo $disciplina['status']['classe']; ?>">
                                <i class="fas <?php echo $disciplina['status']['icone']; ?>"></i> <?php echo $disciplina['status']['texto']; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="text-center mt-3">
            <a href="?turma_id=<?php echo $turma_id; ?>&view=geral" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar para Visão Geral
            </a>
        </div>
        
        <?php endif; ?>
        
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar o aproveitamento.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $('#tabelaAproveitamento').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[2, 'asc']],
            pageLength: 25
        });
    </script>
</body>
</html>