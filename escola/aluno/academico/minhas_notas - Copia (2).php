<?php
// escola/aluno/academico/minhas_notas.php - Minhas Notas do Aluno (Completo)

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

// Definir título da página
$titulo_pagina = 'Minhas Notas';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno para determinar o ciclo
$sql_turma = "SELECT t.id, t.nome, t.ano
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Determinar o ciclo do aluno (1º ciclo: 1ª a 6ª classe | 2º ciclo: 7ª em diante)
$ciclo = 2; // padrão 0-20
$nota_maxima = 20;
$nota_minima_aprovacao = 10;

if ($turma && $turma['ano'] <= 6) {
    $ciclo = 1; // 1º ciclo: notas de 0 a 10
    $nota_maxima = 10;
    $nota_minima_aprovacao = 5;
}

// Filtros
$ano_letivo = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

// Buscar anos letivos disponíveis
$sql_anos = "SELECT DISTINCT ano_letivo_id FROM notas WHERE estudante_id = :aluno_id ORDER BY ano_letivo_id DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// Buscar notas do aluno com todos os campos
$sql_notas = "SELECT n.*, d.nome as disciplina, d.codigo, d.carga_horaria
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id AND n.ano_letivo_id = :ano
              ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id, ':ano' => $ano_letivo]);
$notas_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas por disciplina e bimestre
$disciplinas = [];
$bimestres = [1, 2, 3, 4, 5, 6]; // 6 bimestres/trimestres

foreach ($notas_raw as $nota) {
    $disciplina = $nota['disciplina'];
    $bimestre = $nota['bimestre'] ?? 1;
    
    if (!isset($disciplinas[$disciplina])) {
        $disciplinas[$disciplina] = [
            'codigo' => $nota['codigo'],
            'carga_horaria' => $nota['carga_horaria'],
            'bimestres' => [],
            'mac_total' => 0,
            'npt_total' => 0,
            'media_parcial' => 0,
            'media_final' => 0,
            'exame_normal' => null,
            'exame_recurso' => null,
            'exame_especial' => null,
            'exame_oral' => null,
            'exame_escrito' => null
        ];
    }
    
    // Armazenar notas por bimestre
    $disciplinas[$disciplina]['bimestres'][$bimestre] = [
        'mac' => $nota['mac'] ?? null,
        'npt' => $nota['npt'] ?? null,
        'media' => $nota['media_parcial'] ?? null,
        'exame_normal' => $nota['exame_normal'] ?? null,
        'exame_recurso' => $nota['exame_recurso'] ?? null,
        'exame_especial' => $nota['exame_especial'] ?? null,
        'exame_oral' => $nota['exame_oral'] ?? null,
        'exame_escrito' => $nota['exame_escrito'] ?? null,
        'media_final' => $nota['media_final'] ?? null
    ];
    
    // Acumular totais para média final
    if (isset($nota['mac'])) $disciplinas[$disciplina]['mac_total'] += $nota['mac'];
    if (isset($nota['npt'])) $disciplinas[$disciplina]['npt_total'] += $nota['npt'];
}

// Calcular médias finais por disciplina
foreach ($disciplinas as $disciplina => $dados) {
    $qtd_bimestres = count($dados['bimestres']);
    if ($qtd_bimestres > 0) {
        $disciplinas[$disciplina]['media_parcial'] = $dados['mac_total'] / $qtd_bimestres;
    }
}

// Calcular estatísticas gerais
$total_disciplinas = count($disciplinas);
$aprovados = 0;
$reprovados = 0;
$soma_medias_finais = 0;

foreach ($disciplinas as $disciplina => $dados) {
    $media_final = $dados['media_final'] ?? $dados['media_parcial'];
    $soma_medias_finais += $media_final;
    if ($ciclo == 1) {
        if ($media_final >= 5) $aprovados++;
        else $reprovados++;
    } else {
        if ($media_final >= 10) $aprovados++;
        else $reprovados++;
    }
}

$media_geral = $total_disciplinas > 0 ? $soma_medias_finais / $total_disciplinas : 0;

// Função para classificar a nota baseada no ciclo
function classificarNota($nota, $ciclo) {
    if ($ciclo == 1) {
        // 1º ciclo: 0 a 10
        if ($nota >= 9) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 7.5) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 6) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 5) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 3.5) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    } else {
        // 2º ciclo: 0 a 20
        if ($nota >= 18) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 15) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 12) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 10) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 7) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    }
}

function getStatusBadge($nota, $ciclo) {
    $aprovado = ($ciclo == 1) ? $nota >= 5 : $nota >= 10;
    if ($aprovado) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}

function formatarNota($nota) {
    if ($nota === null || $nota === '') return '-';
    return number_format((float)$nota, 1, ',', '.');
}

// Incluir o menu
include '../includes/menu_aluno.php';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .nota-valor {
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .table-notas th, .table-notas td {
            vertical-align: middle;
            text-align: center;
        }
        
        .table-notas tr:hover {
            background: #f8f9fa;
        }
        
        .progress-bar-excelente { background: #28a745; }
        .progress-bar-muito-bom { background: #20c997; }
        .progress-bar-bom { background: #17a2b8; }
        .progress-bar-satisfatorio { background: #ffc107; }
        .progress-bar-insuficiente { background: #fd7e14; }
        .progress-bar-muito-insuficiente { background: #dc3545; }
        
        .status-aprovado { background: #d4edda; color: #155724; }
        .status-reprovado { background: #f8d7da; color: #721c24; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .nota-cell {
            min-width: 80px;
        }
        
        .badge-nota {
            font-size: 0.9rem;
            padding: 5px 10px;
        }
    </style>
</head>
<body>

<div class="main-content-aluno">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-line"></i> Minhas Notas</h4>
            <p class="text-muted mb-0">Acompanhe seu desempenho acadêmico - <?php echo $ciclo == 1 ? '1º Ciclo (0-10)' : '2º Ciclo (0-20)'; ?></p>
        </div>
        <div>
            <form method="GET" class="d-inline-flex gap-2">
                <select name="ano" class="form-select" style="width: auto;">
                    <?php foreach ($anos_disponiveis as $ano): ?>
                    <option value="<?php echo $ano; ?>" <?php echo $ano_letivo == $ano ? 'selected' : ''; ?>>
                        <?php echo $ano; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
            </form>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno e Turma -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                            <h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome']); ?></h6>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                            <h6 class="mb-0"><?php echo $aluno['matricula']; ?></h6>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                            <h6 class="mb-0"><?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted"><i class="fas fa-chart-simple"></i> Ciclo</small>
                            <h6 class="mb-0"><?php echo $ciclo == 1 ? '1º Ciclo (0-10)' : '2º Ciclo (0-20)'; ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-0 shadow-sm bg-primary text-white">
                <div class="card-body">
                    <small>Média Geral</small>
                    <h2 class="mb-0"><?php echo number_format($media_geral, 1, ',', '.'); ?></h2>
                    <small>/ <?php echo $nota_maxima; ?></small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-book fa-2x text-primary mb-2"></i>
                    <h3 class="mb-0"><?php echo $total_disciplinas; ?></h3>
                    <small class="text-muted">Total de Disciplinas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h3 class="mb-0"><?php echo $aprovados; ?></h3>
                    <small class="text-muted">Disciplinas Aprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <h3 class="mb-0"><?php echo $reprovados; ?></h3>
                    <small class="text-muted">Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <h3 class="mb-0"><?php echo number_format(($aprovados / max($total_disciplinas, 1)) * 100, 0); ?>%</h3>
                    <small class="text-muted">Aproveitamento</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Notas por Disciplina e Bimestre -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Resultados Acadêmicos - <?php echo $ano_letivo; ?>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma nota encontrada para o ano letivo <?php echo $ano_letivo; ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-notas align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th rowspan="2" style="vertical-align: middle;">#</th>
                                <th rowspan="2" style="vertical-align: middle;">Disciplina</th>
                                <th colspan="2" class="text-center">1º Bim</th>
                                <th colspan="2" class="text-center">2º Bim</th>
                                <th colspan="2" class="text-center">3º Bim</th>
                                <th colspan="2" class="text-center">4º Bim</th>
                                <th colspan="2" class="text-center">5º Bim</th>
                                <th colspan="2" class="text-center">6º Bim</th>
                                <th rowspan="2" class="text-center">Média Parcial</th>
                                <th colspan="5" class="text-center">Exames</th>
                                <th rowspan="2" class="text-center">Média Final</th>
                                <th rowspan="2" class="text-center">Status</th>
                            </tr>
                            <tr class="table-dark">
                                <th>MAC</th><th>NPT</th>
                                <th>MAC</th><th>NPT</th>
                                <th>MAC</th><th>NPT</th>
                                <th>MAC</th><th>NPT</th>
                                <th>MAC</th><th>NPT</th>
                                <th>MAC</th><th>NPT</th>
                                <th>Normal</th><th>Recurso</th><th>Especial</th><th>Oral</th><th>Escrito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $index = 1; foreach ($disciplinas as $disciplina => $dados): 
                                $classificacao = classificarNota($dados['media_final'] ?: $dados['media_parcial'], $ciclo);
                                $percentual = (($dados['media_final'] ?: $dados['media_parcial']) / $nota_maxima) * 100;
                            ?>
                            <tr>
                                <td><strong><?php echo $index++; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($disciplina); ?></strong>
                                    <br><small class="text-muted"><?php echo $dados['codigo']; ?></small>
                                </td>
                                <!-- Bimestre 1 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][1]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][1]['npt'] ?? null); ?></td>
                                <!-- Bimestre 2 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][2]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][2]['npt'] ?? null); ?></td>
                                <!-- Bimestre 3 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][3]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][3]['npt'] ?? null); ?></td>
                                <!-- Bimestre 4 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][4]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][4]['npt'] ?? null); ?></td>
                                <!-- Bimestre 5 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][5]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][5]['npt'] ?? null); ?></td>
                                <!-- Bimestre 6 -->
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][6]['mac'] ?? null); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['bimestres'][6]['npt'] ?? null); ?></td>
                                <!-- Média Parcial -->
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['media_parcial']); ?></td>
                                <!-- Exames -->
                                <td class="text-center"><?php echo formatarNota($dados['exame_normal']); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_recurso']); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_especial']); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_oral']); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_escrito']); ?></td>
                                <!-- Média Final -->
                                <td class="text-center fw-bold" style="background: <?php echo $classificacao['cor']; ?>20; color: <?php echo $classificacao['cor']; ?>;">
                                    <?php echo formatarNota($dados['media_final'] ?: $dados['media_parcial']); ?>
                                </td>
                                <!-- Status -->
                                <td class="text-center">
                                    <?php echo getStatusBadge($dados['media_final'] ?: $dados['media_parcial'], $ciclo); ?>
                                    <div class="progress mt-1" style="height: 4px;">
                                        <div class="progress-bar progress-bar-<?php echo $classificacao['classe']; ?>" style="width: <?php echo min($percentual, 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2" class="fw-bold text-end">Média Geral:</td>
                                <td colspan="17" class="fw-bold"><?php echo number_format($media_geral, 1, ',', '.'); ?> / <?php echo $nota_maxima; ?></td>
                                <td><?php echo $media_geral >= $nota_minima_aprovacao ? '<span class="text-success">Aluno Aprovado</span>' : '<span class="text-danger">Aluno Reprovado</span>'; ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Legenda de Classificação por Ciclo -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-info-circle"></i> Legenda de Classificação
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-child"></i> 1º Ciclo (1ª a 6ª Classe) - Escala 0-10</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><span class="badge" style="background: #28a745;">9.0 - 10.0</span> Excelente</div>
                        <div class="col-md-4"><span class="badge" style="background: #20c997;">7.5 - 8.9</span> Muito Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #17a2b8;">6.0 - 7.4</span> Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #ffc107;">5.0 - 5.9</span> Satisfatório</div>
                        <div class="col-md-4"><span class="badge" style="background: #fd7e14;">3.5 - 4.9</span> Insuficiente</div>
                        <div class="col-md-4"><span class="badge" style="background: #dc3545;">0.0 - 3.4</span> Muito Insuficiente</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-user-graduate"></i> 2º Ciclo (7ª Classe em diante) - Escala 0-20</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><span class="badge" style="background: #28a745;">18.0 - 20.0</span> Excelente</div>
                        <div class="col-md-4"><span class="badge" style="background: #20c997;">15.0 - 17.9</span> Muito Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #17a2b8;">12.0 - 14.9</span> Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #ffc107;">10.0 - 11.9</span> Satisfatório</div>
                        <div class="col-md-4"><span class="badge" style="background: #fd7e14;">7.0 - 9.9</span> Insuficiente</div>
                        <div class="col-md-4"><span class="badge" style="background: #dc3545;">0.0 - 6.9</span> Muito Insuficiente</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('btnExportar')?.addEventListener('click', function() {
        window.print();
    });
</script>

</body>
</html>