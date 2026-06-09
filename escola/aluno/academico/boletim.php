<?php
// escola/aluno/academico/boletim.php - Boletim do Aluno

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Boletim Escolar';


// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
$turma_id = $turma['id'] ?? 0;

// Filtros
$ano_letivo_id = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;


// Buscar anolectivo
$sql_ano_letivo= "SELECT id,ano 
              FROM ano_letivo
              WHERE id= :ano_letivo AND ativo =1 and escola_id=:escola_id";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([
    ':ano_letivo' => $ano_letivo_id,
    ':escola_id' => $escola_id
    ]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo = $ano_letivo['ano'] ?? 0;


// ==============================================
// BUSCAR ANOS LETIVOS DISPONÍVEIS (da tabela notas)
// ==============================================
$sql_anos = "SELECT DISTINCT al.id, al.ano 
             FROM ano_letivo al
             INNER JOIN notas n ON n.ano_letivo_id = al.id
             WHERE n.estudante_id = :aluno_id 
             AND n.escola_id = :escola_id
             ORDER BY al.ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([
    ':aluno_id' => $aluno_id, 
    ':escola_id' => $escola_id
]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// BUSCAR DISCIPLINAS DO ALUNO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo, d.carga_horaria
                    FROM disciplinas d
                    JOIN disciplina_turma dt ON dt.disciplina_id = d.id
                    WHERE dt.turma_id = :turma_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR NOTAS DO ALUNO (usando a estrutura correta)
// ==============================================
$sql_notas = "SELECT 
                    n.id,
                    n.disciplina_id,
                    n.bimestre,
                    n.mac,
                    n.npt,
                    n.exame_normal,
                    n.exame_recurso,
                    n.exame_especial,
                    n.exame_oral,
                    n.exame_escrito,
                    n.media_parcial,
                    n.media_final,
                    n.status,
                    n.data_lancamento,
                    n.lancado_por,
                    d.nome as disciplina_nome,
                    d.codigo as disciplina_codigo,
                    d.carga_horaria
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id 
              AND n.escola_id = :escola_id
              AND n.ano_letivo_id = :ano";

if ($bimestre_filtro > 0) {
    $sql_notas .= " AND n.bimestre = :bimestre";
}

$sql_notas .= " ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':ano' => $ano_letivo_id
];
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_notas->execute($params);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR MÉDIAS DA CLASSE
// ==============================================
$sql_medias_classe = "SELECT 
                        disciplina_id,
                        bimestre,
                        AVG(media_final) as media_classe,
                        COUNT(*) as total_alunos
                      FROM notas 
                      WHERE escola_id = :escola_id 
                      AND ano_letivo_id = :ano
                      GROUP BY disciplina_id, bimestre";
$stmt_medias = $conn->prepare($sql_medias_classe);
$stmt_medias->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo_id]);
$medias_classe = [];
foreach ($stmt_medias->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $medias_classe[$m['disciplina_id']][$m['bimestre']] = round($m['media_classe'], 1);
}

// ==============================================
// PROCESSAR NOTAS POR DISCIPLINA E BIMESTRE
// ==============================================
$boletim = [];
foreach ($disciplinas as $disciplina) {
    $boletim[$disciplina['id']] = [
        'disciplina_nome' => $disciplina['nome'],
        'disciplina_codigo' => $disciplina['codigo'],
        'carga_horaria' => $disciplina['carga_horaria'],
        'bimestres' => [
            1 => ['mac' => null, 'npt' => null, 'media_parcial' => null, 'media_classe' => null],
            2 => ['mac' => null, 'npt' => null, 'media_parcial' => null, 'media_classe' => null],
            3 => ['mac' => null, 'npt' => null, 'media_parcial' => null, 'media_classe' => null],
            4 => ['mac' => null, 'npt' => null, 'media_parcial' => null, 'media_classe' => null]
        ],
        'exame_normal' => null,
        'exame_recurso' => null,
        'exame_especial' => null,
        'exame_oral' => null,
        'exame_escrito' => null,
        'media_final' => null,
        'status' => null
    ];
}

foreach ($notas as $nota) {
    $disc_id = $nota['disciplina_id'];
    $bimestre = $nota['bimestre'];
    
    if (isset($boletim[$disc_id])) {
        $boletim[$disc_id]['bimestres'][$bimestre] = [
            'mac' => $nota['mac'],
            'npt' => $nota['npt'],
            'media_parcial' => $nota['media_parcial'],
            'media_classe' => $medias_classe[$disc_id][$bimestre] ?? null
        ];
        $boletim[$disc_id]['exame_normal'] = $nota['exame_normal'];
        $boletim[$disc_id]['exame_recurso'] = $nota['exame_recurso'];
        $boletim[$disc_id]['exame_especial'] = $nota['exame_especial'];
        $boletim[$disc_id]['exame_oral'] = $nota['exame_oral'];
        $boletim[$disc_id]['exame_escrito'] = $nota['exame_escrito'];
        $boletim[$disc_id]['media_final'] = $nota['media_final'];
        $boletim[$disc_id]['status'] = $nota['status'];
    }
}

// ==============================================
// CALCULAR MÉDIAS GERAIS
// ==============================================
$medias_bimestres = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$medias_classe_bimestres = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$total_disciplinas = count($disciplinas);
$total_aprovado = 0;
$total_reprovado = 0;
$total_recuperacao = 0;
$total_exame = 0;

foreach ($boletim as $disc_id => $dados) {
    for ($b = 1; $b <= 4; $b++) {
        if ($dados['bimestres'][$b]['media_parcial'] !== null) {
            $medias_bimestres[$b] += $dados['bimestres'][$b]['media_parcial'];
        }
        if ($dados['bimestres'][$b]['media_classe'] !== null) {
            $medias_classe_bimestres[$b] += $dados['bimestres'][$b]['media_classe'];
        }
    }
    
    if ($dados['status'] == 'aprovado') {
        $total_aprovado++;
    } elseif ($dados['status'] == 'reprovado') {
        $total_reprovado++;
    } elseif ($dados['status'] == 'recuperacao') {
        $total_recuperacao++;
    }
    
    if ($dados['exame_normal'] !== null || $dados['exame_recurso'] !== null || 
        $dados['exame_especial'] !== null || $dados['exame_oral'] !== null || 
        $dados['exame_escrito'] !== null) {
        $total_exame++;
    }
}

for ($b = 1; $b <= 4; $b++) {
    $medias_bimestres[$b] = $total_disciplinas > 0 ? round($medias_bimestres[$b] / $total_disciplinas, 1) : 0;
    $medias_classe_bimestres[$b] = $total_disciplinas > 0 ? round($medias_classe_bimestres[$b] / $total_disciplinas, 1) : 0;
}

$media_geral = array_sum($medias_bimestres) / 4;
$media_geral_classe = array_sum($medias_classe_bimestres) / 4;
$taxa_aprovacao = $total_disciplinas > 0 ? round(($total_aprovado / $total_disciplinas) * 100, 1) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getSituacaoBadge($status) {
    switch ($status) {
        case 'aprovado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Recuperação</span>';
        case 'exame':
            return '<span class="badge bg-info"><i class="fas fa-pen-alt"></i> Exame Final</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}

function getCorNota($nota) {
    if ($nota === null) return 'text-secondary';
    if ($nota >= 14) return 'text-success fw-bold';
    if ($nota >= 10) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

function getCorMedia($media) {
    if ($media >= 14) return '#28a745';
    if ($media >= 10) return '#ffc107';
    return '#dc3545';
}

function getComparacaoIcone($nota_aluno, $media_classe) {
    if ($nota_aluno === null || $media_classe === null) return '';
    if ($nota_aluno > $media_classe) return '<i class="fas fa-arrow-up text-success" title="Acima da média"></i>';
    if ($nota_aluno < $media_classe) return '<i class="fas fa-arrow-down text-danger" title="Abaixo da média"></i>';
    return '<i class="fas fa-minus text-secondary" title="Na média"></i>';
}

function getTipoExame($nota) {
    $exames = [];
    if ($nota['exame_normal'] !== null) $exames[] = 'Normal: ' . number_format($nota['exame_normal'], 1, ',', '.');
    if ($nota['exame_recurso'] !== null) $exames[] = 'Recurso: ' . number_format($nota['exame_recurso'], 1, ',', '.');
    if ($nota['exame_especial'] !== null) $exames[] = 'Especial: ' . number_format($nota['exame_especial'], 1, ',', '.');
    if ($nota['exame_oral'] !== null) $exames[] = 'Oral: ' . number_format($nota['exame_oral'], 1, ',', '.');
    if ($nota['exame_escrito'] !== null) $exames[] = 'Escrito: ' . number_format($nota['exame_escrito'], 1, ',', '.');
    return implode(' | ', $exames);
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
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
            font-size: 1.5em;
            font-weight: bold;
        }
        
        .boletim-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .boletim-table th, .boletim-table td {
            padding: 10px 6px;
            text-align: center;
            border: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        .boletim-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .boletim-table tr:hover {
            background: #f5f5f5;
        }
        
        .btn-boletim {
            background: #006B3E;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        .media-classe-cell {
            font-size: 0.7rem;
            color: #666;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .no-print { display: none; }
            .boletim-table { font-size: 9pt; }
            .main-content-aluno { margin: 0; padding: 0; }
        }
        
        .legenda {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
   <?php include '../includes/menu_aluno.php'; ?>
      
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Boletim Escolar</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe seu boletim escolar com notas por bimestre e situação final.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Componentes da Nota</div>
                <div class="ajuda-texto">
                    <strong>MAC</strong> - Média das Atividades Contínuas<br>
                    <strong>NPT</strong> - Nota da Prova Trimestral<br>
                    <strong>Média Parcial</strong> - Média de MAC e NPT
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Tipos de Exame</div>
                <div class="ajuda-texto">
                    Normal, Recurso, Especial, Oral, Escrito
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Situação</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Aprovado</span> - Média final ≥ 10<br>
                    <span class="badge bg-danger">Reprovado</span> - Média final &lt; 10<br>
                    <span class="badge bg-warning">Recuperação</span> - Aluno em recuperação
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-bar"></i> Boletim Escolar</h4>
            <p class="text-muted mb-0">Visualize suas notas e desempenho por bimestre</p>
        </div>
        <div class="no-print">
            <button class="btn btn-primary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <button class="btn btn-primary" onclick="gerarBoletim()">
    <i class="fas fa-file-pdf"></i> Ver / Imprimir Boletim
</button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-calendar"></i> Ano Letivo</small>
                    <h6 class="mb-0"><?php echo $ano_letivo; ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-chart-line"></i> Média Geral</small>
                    <h6 class="mb-0 <?php echo $media_geral >= 10 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo number_format($media_geral, 1, ',', '.'); ?>
                    </h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Resumo -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_aprovado; ?></div>
                <div class="stat-label">Aprovadas</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_reprovado; ?></div>
                <div class="stat-label">Reprovadas</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Recuperação</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $total_exame; ?></div>
                <div class="stat-label">Com Exame</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $taxa_aprovacao; ?>%</div>
                <div class="stat-label">Aproveitamento</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?php echo number_format($media_geral_classe, 1, ',', '.'); ?></div>
                <div class="stat-label">Média da Classe</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card no-print">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="formFiltros">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano["id"]; ?>" <?php echo $ano_letivo == $ano["id"] ? 'selected' : ''; ?>><?php echo $ano["ano"]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os bimestres</option>
                        <option value="1" <?php echo $bimestre_filtro == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre_filtro == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre_filtro == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="4" <?php echo $bimestre_filtro == 4 ? 'selected' : ''; ?>>4º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end gap-2">
                    <a href="boletim.php" class="btn btn-outline-secondary"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tabela do Boletim -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Boletim - <?php echo $ano_letivo; ?>
            <?php if ($bimestre_filtro > 0): ?>
            <span class="badge bg-info ms-2"><?php echo $bimestre_filtro; ?>º Bimestre</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma disciplina encontrada para sua turma.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="boletim-table" id="boletimTable">
                        <thead>
                            <tr>
                                <th rowspan="2">Disciplina</th>
                                <?php if ($bimestre_filtro == 0): ?>
                                <th colspan="4" class="text-center">Avaliações por Bimestre</th>
                                <th rowspan="2">MAC</th>
                                <th rowspan="2">NPT</th>
                                <th rowspan="2">Méd. Parc.</th>
                                <th rowspan="2">Méd. Classe</th>
                                <th rowspan="2">Exames</th>
                                <th rowspan="2">Média Final</th>
                                <th rowspan="2">Situação</th>
                                <?php else: ?>
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Média Parcial</th>
                                <th>Média Classe</th>
                                <th>Comparação</th>
                                <th>Exames</th>
                                <?php endif; ?>
                            </tr>
                            <?php if ($bimestre_filtro == 0): ?>
                            <tr class="text-center">
                                <th>1º</th>
                                <th>2º</th>
                                <th>3º</th>
                                <th>4º</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($boletim as $disc_id => $dados): ?>
                            <?php
                                // Calcular médias dos bimestres
                                $medias_parciais = [];
                                for ($b = 1; $b <= 4; $b++) {
                                    $medias_parciais[$b] = $dados['bimestres'][$b]['media_parcial'];
                                }
                                $media_mac = array_sum(array_column($dados['bimestres'], 'mac')) / 4;
                                $media_npt = array_sum(array_column($dados['bimestres'], 'npt')) / 4;
                                $media_parcial_geral = array_sum($medias_parciais) / 4;
                            ?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo htmlspecialchars($dados['disciplina_nome']); ?></td>
                                
                                <?php if ($bimestre_filtro == 0): ?>
                                    <!-- Todos os bimestres -->
                                    <?php for ($b = 1; $b <= 4; $b++): 
                                        $media_parcial = $dados['bimestres'][$b]['media_parcial'];
                                        $cor_media = getCorNota($media_parcial);
                                    ?>
                                    <td class="text-center <?php echo $cor_media; ?>">
                                        <?php echo $media_parcial !== null ? number_format($media_parcial, 1, ',', '.') : '-'; ?>
                                        <?php if ($dados['bimestres'][$b]['media_classe']): ?>
                                        <br><small class="media-classe-cell">(M: <?php echo number_format($dados['bimestres'][$b]['media_classe'], 1, ',', '.'); ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <?php endfor; ?>
                                    
                                    <!-- Médias gerais -->
                                    <td class="text-center"><?php echo number_format($media_mac, 1, ',', '.'); ?></td>
                                    <td class="text-center"><?php echo number_format($media_npt, 1, ',', '.'); ?></td>
                                    <td class="text-center <?php echo getCorNota($media_parcial_geral); ?>">
                                        <?php echo number_format($media_parcial_geral, 1, ',', '.'); ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format($medias_classe_bimestres[$b] ?? 0, 1, ',', '.'); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $exames = [];
                                        if ($dados['exame_normal'] !== null) $exames[] = 'N: ' . number_format($dados['exame_normal'], 1, ',', '.');
                                        if ($dados['exame_recurso'] !== null) $exames[] = 'R: ' . number_format($dados['exame_recurso'], 1, ',', '.');
                                        if ($dados['exame_especial'] !== null) $exames[] = 'E: ' . number_format($dados['exame_especial'], 1, ',', '.');
                                        echo !empty($exames) ? implode('<br>', $exames) : '-';
                                        ?>
                                    </td>
                                    <td class="text-center fw-bold" style="color: <?php echo getCorMedia($dados['media_final'] ?? 0); ?>">
                                        <?php echo $dados['media_final'] !== null ? number_format($dados['media_final'], 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getSituacaoBadge($dados['status']); ?>
                                    </td>
                                    
                                <?php else: ?>
                                    <!-- Bimestre específico -->
                                    <?php 
                                        $b = $bimestre_filtro;
                                        $mac = $dados['bimestres'][$b]['mac'];
                                        $npt = $dados['bimestres'][$b]['npt'];
                                        $media_parcial = $dados['bimestres'][$b]['media_parcial'];
                                        $media_classe = $dados['bimestres'][$b]['media_classe'];
                                    ?>
                                    <td class="text-center <?php echo getCorNota($mac); ?>">
                                        <?php echo $mac !== null ? number_format($mac, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center <?php echo getCorNota($npt); ?>">
                                        <?php echo $npt !== null ? number_format($npt, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center <?php echo getCorNota($media_parcial); ?>">
                                        <?php echo $media_parcial !== null ? number_format($media_parcial, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo $media_classe !== null ? number_format($media_classe, 1, ',', '.') : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getComparacaoIcone($media_parcial, $media_classe); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo getTipoExame($dados); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($bimestre_filtro == 0): ?>
                        <tfoot class="table-secondary">
                            <tr>
                                <td class="text-end fw-bold">Média da Turma</td>
                                <?php for ($b = 1; $b <= 4; $b++): ?>
                                <td class="text-center fw-bold"><?php echo number_format($medias_bimestres[$b], 1, ',', '.'); ?></td>
                                <?php endfor; ?>
                                <td class="text-center fw-bold"><?php echo number_format($media_geral, 1, ',', '.'); ?></td>
                                <td class="text-center fw-bold"><?php echo number_format($media_geral, 1, ',', '.'); ?></td>
                                <td class="text-center fw-bold"><?php echo number_format($media_geral, 1, ',', '.'); ?></td>
                                <td class="text-center fw-bold"><?php echo number_format($media_geral_classe, 1, ',', '.'); ?></td>
                                <td class="text-center">-</td>
                                <td class="text-center fw-bold" style="color: <?php echo getCorMedia($media_geral); ?>">
                                    <?php echo number_format($media_geral, 1, ',', '.'); ?>
                                </td>
                                <td class="text-center">-</td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Gráfico de Desempenho -->
    <?php if ($bimestre_filtro == 0 && !empty($disciplinas)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-chart-line"></i> Gráfico de Desempenho por Disciplina</div>
        <div class="card-body">
            <canvas id="graficoDesempenho" height="300"></canvas>
        </div>
    </div>
    
    <script>
        // Gráfico de desempenho
        const disciplinasNomes = <?php 
            $nomes = [];
            foreach ($boletim as $dados) {
                $nomes[] = "'" . addslashes($dados['disciplina_nome']) . "'";
            }
            echo '[' . implode(', ', $nomes) . ']';
        ?>;
        
        const mediasFinais = <?php 
            $medias = [];
            foreach ($boletim as $dados) {
                $medias[] = $dados['media_final'] ?? 0;
            }
            echo '[' . implode(', ', $medias) . ']';
        ?>;
        
        const cores = mediasFinais.map(m => {
            if (m >= 14) return '#28a745';
            if (m >= 10) return '#ffc107';
            return '#dc3545';
        });
        
        new Chart(document.getElementById('graficoDesempenho'), {
            type: 'bar',
            data: {
                labels: disciplinasNomes,
                datasets: [{
                    label: 'Média Final',
                    data: mediasFinais,
                    backgroundColor: cores,
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 20,
                        ticks: { callback: function(value) { return value; } },
                        title: { display: true, text: 'Nota' }
                    },
                    x: {
                        ticks: { autoSkip: false, rotation: 45, font: { size: 10 } }
                    }
                },
                plugins: {
                    tooltip: { callbacks: { label: function(context) { return 'Média: ' + context.raw.toFixed(1); } } },
                    legend: { display: false }
                }
            }
        });
    </script>
    <?php endif; ?>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in no-print">
        <div class="card-header bg-white fw-bold"><i class="fas fa-info-circle"></i> Legenda</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #28a745;"></div>
                        <span><strong>Excelente:</strong> Nota ≥ 14</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #ffc107;"></div>
                        <span><strong>Regular:</strong> Nota entre 10 e 13</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="legenda" style="background: #dc3545;"></div>
                        <span><strong>Insuficiente:</strong> Nota &lt; 10</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-chart-line text-info"></i>
                        <span><strong>Média de Aprovação:</strong> 10 pontos</span>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-12">
                    <small><strong>MAC:</strong> Média das Atividades Contínuas | <strong>NPT:</strong> Nota da Prova Trimestral</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });

    // Função para gerar boletim em nova janela
function gerarBoletim() {
    var ano = document.querySelector('select[name="ano"]').value;
    var bimestre = document.querySelector('select[name="bimestre"]').value;
    window.open('gerar_boletim.php?ano=' + ano + '&bimestre=' + bimestre, '_blank', 'width=1100,height=800,toolbar=yes,scrollbars=yes,resizable=yes');
}

</script>
</body>
</html>