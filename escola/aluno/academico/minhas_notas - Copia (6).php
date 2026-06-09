<?php
// escola/aluno/academico/minhas_notas.php - Minhas Notas do Aluno (Completo com Controle Financeiro)

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

// ============================================
// VERIFICAÇÃO FINANCEIRA
// ============================================

$boletim_pago = false;
try {
    $sql_tipo = "SELECT id FROM tipos_pagamento WHERE nome LIKE '%Boletim%' LIMIT 1";
    $stmt_tipo = $conn->prepare($sql_tipo);
    $stmt_tipo->execute();
    $tipo = $stmt_tipo->fetch(PDO::FETCH_ASSOC);
    
    if ($tipo) {
        $sql_boletim = "SELECT id FROM outros_pagamentos 
                        WHERE escola_id = :escola_id 
                        AND aluno_id = :aluno_id 
                        AND tipo_pagamento_id = :tipo_id
                        AND status = 'pago'
                        LIMIT 1";
        $stmt_boletim = $conn->prepare($sql_boletim);
        $stmt_boletim->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':tipo_id' => $tipo['id']
        ]);
        $boletim_pago = $stmt_boletim->fetch();
    }
} catch (Exception $e) {
    $boletim_pago = false;
}

$dividas_outros = 0;
$dividas_mensalidades = 0;
$valor_divida = 0;
$tota_divida = 0;

try {
    $sql_outros = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - valor_pago), 0) as valor FROM outros_pagamentos 
                   WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                   AND status IN ('pendente', 'parcial')";
    $stmt_outros = $conn->prepare($sql_outros);
    $stmt_outros->execute([':escola_id' => $escola_id, ':aluno_id' => $aluno_id]);
    $dividas_outros_result = $stmt_outros->fetch(PDO::FETCH_ASSOC);
    $dividas_outros = $dividas_outros_result['total'] ?? 0;
    $valor_divida_outros = $dividas_outros_result['valor'] ?? 0;
    
    $sql_mensalidades = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - valor_pago), 0) as valor 
                         FROM mensalidades 
                         WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                         AND status IN ('pendente', 'parcial')";
    $stmt_mensalidades = $conn->prepare($sql_mensalidades);
    $stmt_mensalidades->execute([':escola_id' => $escola_id, ':aluno_id' => $aluno_id]);
    $mens_result = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);
    $dividas_mensalidades = $mens_result['total'] ?? 0;
    $valor_divida = $mens_result['valor'] ?? 0;

    $tota_divida = ($valor_divida_outros + $valor_divida);
    
} catch (Exception $e) {
    $dividas_outros = 0;
    $dividas_mensalidades = 0;
    $valor_divida = 0;
}

$tem_dividas = ($dividas_outros > 0 || $dividas_mensalidades > 0);

$boletim_liberado = ($boletim_pago && !$tem_dividas);
$boletim_parcial = (!$boletim_pago && !$tem_dividas);
$boletim_bloqueado = ($tem_dividas);

$titulo_pagina = 'Minhas Notas';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Determinar o ciclo
$ciclo = 2;
$nota_maxima = 20;
$nota_minima_aprovacao = 10;

if ($turma && $turma['ano'] <= 6) {
    $ciclo = 1;
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

// Buscar notas
$sql_notas = "SELECT n.*, d.nome as disciplina, d.codigo, d.carga_horaria
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id AND n.ano_letivo_id = :ano
              ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id, ':ano' => $ano_letivo]);
$notas_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas
$disciplinas = [];

foreach ($notas_raw as $nota) {
    $disciplina = $nota['disciplina'];
    $bimestre = $nota['bimestre'] ?? 1;
    
    if ($bimestre > 3) continue;
    
    if (!isset($disciplinas[$disciplina])) {
        $disciplinas[$disciplina] = [
            'codigo' => $nota['codigo'],
            'b1_mac' => null,
            'b1_npt' => null,
            'b1_media' => null,
            'b2_mac' => null,
            'b2_npt' => null,
            'b2_media' => null,
            'b3_mac' => null,
            'b3_npt' => null,
            'b3_media' => null,
            'media_parcial' => 0,
            'exame_normal' => null,
            'exame_recurso' => null,
            'exame_especial' => null,
            'exame_oral' => null,
            'exame_escrito' => null,
            'media_final' => 0
        ];
    }
    
    if ($bimestre == 1) {
        $disciplinas[$disciplina]['b1_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b1_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b1_media'] = $nota['media_parcial'] ?? null;
    } elseif ($bimestre == 2) {
        $disciplinas[$disciplina]['b2_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b2_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b2_media'] = $nota['media_parcial'] ?? null;
    } elseif ($bimestre == 3) {
        $disciplinas[$disciplina]['b3_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b3_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b3_media'] = $nota['media_parcial'] ?? null;
    }
    
    if (isset($nota['exame_normal'])) $disciplinas[$disciplina]['exame_normal'] = $nota['exame_normal'];
    if (isset($nota['exame_recurso'])) $disciplinas[$disciplina]['exame_recurso'] = $nota['exame_recurso'];
    if (isset($nota['exame_especial'])) $disciplinas[$disciplina]['exame_especial'] = $nota['exame_especial'];
    if (isset($nota['exame_oral'])) $disciplinas[$disciplina]['exame_oral'] = $nota['exame_oral'];
    if (isset($nota['exame_escrito'])) $disciplinas[$disciplina]['exame_escrito'] = $nota['exame_escrito'];
    if (isset($nota['media_final'])) $disciplinas[$disciplina]['media_final'] = $nota['media_final'];
}

// Calcular médias
foreach ($disciplinas as $disciplina => $dados) {
    $medias = [];
    if ($dados['b1_media'] !== null) $medias[] = $dados['b1_media'];
    if ($dados['b2_media'] !== null) $medias[] = $dados['b2_media'];
    if ($dados['b3_media'] !== null) $medias[] = $dados['b3_media'];
    
    if (!empty($medias)) {
        $disciplinas[$disciplina]['media_parcial'] = array_sum($medias) / count($medias);
    }
}

// Calcular estatísticas
$total_disciplinas = count($disciplinas);
$aprovados = 0;
$reprovados = 0;
$soma_medias_finais = 0;

foreach ($disciplinas as $disciplina => $dados) {
    $media_final = $dados['media_final'] ?? $dados['media_parcial'];
    $soma_medias_finais += $media_final;
    
    if ($boletim_liberado) {
        if ($ciclo == 1) {
            if ($media_final >= 5) $aprovados++;
            else $reprovados++;
        } else {
            if ($media_final >= 10) $aprovados++;
            else $reprovados++;
        }
    }
}

$media_geral = $total_disciplinas > 0 ? $soma_medias_finais / $total_disciplinas : 0;

// Funções auxiliares
function classificarNota($nota, $ciclo) {
    if ($ciclo == 1) {
        if ($nota >= 9) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 7.5) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 6) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 5) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 3.5) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    } else {
        if ($nota >= 18) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 15) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 12) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 10) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 7) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    }
}

function getStatusBadge($nota, $ciclo, $bloqueado = false) {
    if ($bloqueado) {
        return '<span class="badge bg-secondary"><i class="fas fa-lock"></i> Bloqueado</span>';
    }
    $aprovado = ($ciclo == 1) ? $nota >= 5 : $nota >= 10;
    if ($aprovado) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}

function formatarNota($nota, $bloqueado = false) {
    if ($bloqueado) return '---';
    if ($nota === null || $nota === '') return '-';
    return number_format((float)$nota, 1, ',', '.');
}

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
        
        .table-notas th, .table-notas td {
            vertical-align: middle;
            text-align: center;
        }
        
        .table-notas tr:hover {
            background: #f8f9fa;
        }
        
        /* NOVA COR DO CABEÇALHO DA TABELA */
        .table-dark th {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .table-bordered {
            border: 1px solid #dee2e6;
        }
        
        .progress-bar-excelente { background: #28a745; }
        .progress-bar-muito-bom { background: #20c997; }
        .progress-bar-bom { background: #17a2b8; }
        .progress-bar-satisfatorio { background: #ffc107; }
        .progress-bar-insuficiente { background: #fd7e14; }
        .progress-bar-muito-insuficiente { background: #dc3545; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .alert-financeiro {
            border-left: 4px solid;
            border-radius: 10px;
        }
        
        .bloqueado {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .bloqueado td {
            background: #f8f9fa;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table-notas {
            min-width: 1000px;
        }
        
        .nota-cell {
            min-width: 70px;
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
    
    <!-- ALERTA FINANCEIRO -->
    <?php if ($boletim_bloqueado): ?>
    <div class="alert alert-danger alert-financeiro mb-4 fade-in" style="border-left-color: #dc3545;">
        <div class="d-flex align-items-center">
            <i class="fas fa-lock fa-2x me-3"></i>
            <div>
                <strong><i class="fas fa-exclamation-triangle"></i> Acesso Bloqueado!</strong><br>
                Você possui dívidas pendentes no valor de <strong><?php echo number_format($tota_divida, 2, ',', '.'); ?> Kz</strong>.
                Regularize sua situação financeira para visualizar suas notas completas.
            </div>
        </div>
    </div>
    <?php elseif ($boletim_parcial): ?>
    <div class="alert alert-warning alert-financeiro mb-4 fade-in" style="border-left-color: #ffc107;">
        <div class="d-flex align-items-center">
            <i class="fas fa-eye fa-2x me-3"></i>
            <div>
                <strong><i class="fas fa-info-circle"></i> Visualização Parcial</strong><br>
                O boletim ainda não foi pago. Você pode visualizar as notas do 1º e 2º bimestre.
                As notas finais e status serão liberados após o pagamento do boletim.
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Informações do Aluno -->
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
                    <h2 class="mb-0">
                        <?php if ($boletim_bloqueado): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo number_format($media_geral, 1, ',', '.'); ?>
                        <?php endif; ?>
                    </h2>
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
                    <h3 class="mb-0">
                        <?php if ($boletim_bloqueado): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo $aprovados; ?>
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Disciplinas Aprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <h3 class="mb-0">
                        <?php if ($boletim_bloqueado): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo $reprovados; ?>
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <h3 class="mb-0">
                        <?php if ($boletim_bloqueado): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo number_format(($aprovados / max($total_disciplinas, 1)) * 100, 0); ?>%
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Aproveitamento</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Notas CORRIGIDA -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Resultados Acadêmicos - <?php echo $ano_letivo; ?>
            <?php if ($boletim_parcial): ?>
            <span class="badge bg-warning ms-2">Visualização Parcial (1º e 2º Bimestre)</span>
            <?php elseif ($boletim_bloqueado): ?>
            <span class="badge bg-danger ms-2">Bloqueado - Pendência Financeira</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma nota encontrada para o ano letivo <?php echo $ano_letivo; ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-notas">
                        <thead>
                            <tr class="table-dark">
                                <th rowspan="2" style="vertical-align: middle; width: 50px;">#</th>
                                <th rowspan="2" style="vertical-align: middle; width: 200px;">Disciplina</th>
                                <th colspan="3" class="text-center">1º Bimestre</th>
                                <th colspan="3" class="text-center">2º Bimestre</th>
                                <th colspan="3" class="text-center">3º Bimestre</th>
                                <th rowspan="2" class="text-center" style="width: 80px;">Média<br>Parcial</th>
                                <th colspan="5" class="text-center">Exames</th>
                                <th rowspan="2" class="text-center" style="width: 80px;">Média<br>Final</th>
                                <th rowspan="2" class="text-center" style="width: 100px;">Status</th>
                            </tr>
                            <tr class="table-dark">
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">Normal</th>
                                <th class="text-center">Recurso</th>
                                <th class="text-center">Especial</th>
                                <th class="text-center">Oral</th>
                                <th class="text-center">Escrito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $index = 1; foreach ($disciplinas as $disciplina => $dados): 
                                $media_final_disciplina = $dados['media_final'] ?? $dados['media_parcial'];
                                $classificacao = classificarNota($media_final_disciplina, $ciclo);
                                $percentual = ($media_final_disciplina / $nota_maxima) * 100;
                                $bloqueado = $boletim_bloqueado;
                                $parcial = $boletim_parcial;
                            ?>
                            <tr <?php echo $bloqueado ? 'class="bloqueado"' : ''; ?>>
                                <td class="text-center"><strong><?php echo $index++; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($disciplina); ?></strong>
                                    <br><small class="text-muted"><?php echo $dados['codigo']; ?></small>
                                </td>
                                
                                <!-- Bimestre 1 -->
                                <td class="text-center"><?php echo formatarNota($dados['b1_mac'], $bloqueado); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b1_npt'], $bloqueado); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b1_media'], $bloqueado); ?></td>
                                
                                <!-- Bimestre 2 -->
                                <td class="text-center"><?php echo formatarNota($dados['b2_mac'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b2_npt'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b2_media'], ($bloqueado || $parcial)); ?></td>
                                
                                <!-- Bimestre 3 -->
                                <td class="text-center"><?php echo formatarNota($dados['b3_mac'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b3_npt'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b3_media'], ($bloqueado || $parcial)); ?></td>
                                
                                <!-- Média Parcial -->
                                <td class="text-center fw-bold">
                                    <?php if ($bloqueado): ?>
                                        <i class="fas fa-lock"></i>
                                    <?php else: ?>
                                        <?php echo formatarNota($dados['media_parcial']); ?>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Exames -->
                                <td class="text-center"><?php echo formatarNota($dados['exame_normal'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_recurso'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_especial'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_oral'], ($bloqueado || $parcial)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_escrito'], ($bloqueado || $parcial)); ?></td>
                                
                                <!-- Média Final -->
                                <td class="text-center fw-bold" style="background: <?php echo $bloqueado ? '#e9ecef' : $classificacao['cor'] . '20'; ?>; color: <?php echo $bloqueado ? '#6c757d' : $classificacao['cor']; ?>;">
                                    <?php if ($bloqueado): ?>
                                        <i class="fas fa-lock"></i>
                                    <?php else: ?>
                                        <?php echo formatarNota($media_final_disciplina); ?>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status -->
                                <td class="text-center">
                                    <?php echo getStatusBadge($media_final_disciplina, $ciclo, $bloqueado); ?>
                                    <?php if (!$bloqueado && !$parcial): ?>
                                    <div class="progress mt-1" style="height: 4px;">
                                        <div class="progress-bar progress-bar-<?php echo $classificacao['classe']; ?>" style="width: <?php echo min($percentual, 100); ?>%"></div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="2" class="text-end">Média Geral:</td>
                                <td colspan="17" class="text-center">
                                    <?php if ($boletim_bloqueado): ?>
                                        <i class="fas fa-lock"></i> Bloqueado
                                    <?php else: ?>
                                        <?php echo number_format($media_geral, 1, ',', '.'); ?> / <?php echo $nota_maxima; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($boletim_bloqueado): ?>
                                        <span class="text-danger">Aguardando regularização</span>
                                    <?php elseif ($boletim_parcial): ?>
                                        <span class="text-warning">Boletim não pago</span>
                                    <?php else: ?>
                                        <?php echo $media_geral >= $nota_minima_aprovacao ? '<span class="text-success">Aluno Aprovado</span>' : '<span class="text-danger">Aluno Reprovado</span>'; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Legenda de Classificação -->
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