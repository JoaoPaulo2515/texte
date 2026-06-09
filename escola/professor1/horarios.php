<?php
// escola/professor/horarios.php - Meus Horários

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$ano_letivo_atual = date('Y');

// ============================================
// BUSCAR HORÁRIOS DO PROFESSOR
// ============================================

// Buscar horários agrupados por dia da semana
$sql_horarios = "
    SELECT 
        h.id,
        h.dia_semana,
        h.horario_inicio,
        h.horario_fim,
        h.turma_id,
        h.disciplina_id,
        h.sala,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo
    FROM horarios h
    INNER JOIN turmas t ON t.id = h.turma_id
    INNER JOIN disciplinas d ON d.id = h.disciplina_id
    WHERE h.professor_id = :professor_id 
    AND t.ano_letivo LIKE :ano_letivo
    ORDER BY 
        FIELD(h.dia_semana, 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'),
        h.horario_inicio
";

$stmt_horarios = $conn->prepare($sql_horarios);
$stmt_horarios->execute([
    ':professor_id' => $professor_id,
    ':ano_letivo' => '%' . $ano_letivo_atual . '%'
]);
$horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);

// Organizar horários por dia da semana
$dias_semana = [
    'segunda' => 'Segunda-feira',
    'terca' => 'Terça-feira',
    'quarta' => 'Quarta-feira',
    'quinta' => 'Quinta-feira',
    'sexta' => 'Sexta-feira',
    'sabado' => 'Sábado',
    'domingo' => 'Domingo'
];

$horarios_por_dia = [];
foreach ($dias_semana as $key => $label) {
    $horarios_por_dia[$key] = [
        'label' => $label,
        'horarios' => []
    ];
}

foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    if (isset($horarios_por_dia[$dia])) {
        $horarios_por_dia[$dia]['horarios'][] = $horario;
    }
}

// Buscar horários do dia atual
$dia_atual_map = [
    'Monday' => 'segunda',
    'Tuesday' => 'terca',
    'Wednesday' => 'quarta',
    'Thursday' => 'quinta',
    'Friday' => 'sexta',
    'Saturday' => 'sabado',
    'Sunday' => 'domingo'
];
$dia_hoje_key = $dia_atual_map[date('l')] ?? 'segunda';
$horarios_hoje = $horarios_por_dia[$dia_hoje_key]['horarios'] ?? [];

// Buscar próximas aulas (próximos 7 dias)
$proximas_aulas = [];
$dias_ordenados = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];
$dia_atual_index = array_search($dia_hoje_key, $dias_ordenados);

for ($i = 0; $i < 7; $i++) {
    $dia_index = ($dia_atual_index + $i) % 7;
    $dia_key = $dias_ordenados[$dia_index];
    if (!empty($horarios_por_dia[$dia_key]['horarios'])) {
        foreach ($horarios_por_dia[$dia_key]['horarios'] as $aula) {
            $proximas_aulas[] = array_merge($aula, ['dia_semana_nome' => $horarios_por_dia[$dia_key]['label']]);
        }
    }
}

// Limitar a 10 próximas aulas
$proximas_aulas = array_slice($proximas_aulas, 0, 10);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Horários | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .horario-table {
            width: 100%;
            border-collapse: collapse;
        }
        .horario-table th {
            background: #006B3E;
            color: white;
            padding: 12px;
            text-align: center;
            border: 1px solid #ddd;
        }
        .horario-table td {
            padding: 10px;
            border: 1px solid #ddd;
            vertical-align: top;
            background: white;
        }
        .aula-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 5px;
            border-left: 4px solid #006B3E;
        }
        .aula-card:last-child {
            margin-bottom: 0;
        }
        .aula-horario {
            font-weight: bold;
            color: #006B3E;
            font-size: 12px;
        }
        .aula-disciplina {
            font-weight: 600;
            font-size: 14px;
        }
        .aula-turma {
            font-size: 11px;
            color: #666;
        }
        .aula-sala {
            font-size: 11px;
            color: #17a2b8;
        }
        .dia-ativo {
            background: #e8f5e9;
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .proxima-aula-item {
            border-left: 4px solid #006B3E;
            margin-bottom: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .proxima-aula-item:hover {
            transform: translateX(5px);
            background: #e8f5e9;
        }
        .badge-dia {
            background: #006B3E;
            color: white;
            font-size: 10px;
        }
        .sem-horarios {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .legenda {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clock"></i> Meus Horários</h2>
            <div>
                <span class="badge bg-primary p-2">
                    <i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $ano_letivo_atual . '/' . ($ano_letivo_atual + 1); ?>
                </span>
            </div>
        </div>
        
        <div class="row">
            <!-- Grade de Horários -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-table"></i> Grade de Horários</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="horario-table">
                                <thead>
                                    <tr>
                                        <th width="14%">Horário</th>
                                        <?php foreach ($dias_semana as $key => $label): ?>
                                        <th width="12%" class="<?php echo $key == $dia_hoje_key ? 'dia-ativo' : ''; ?>">
                                            <?php echo $label; ?>
                                            <?php if ($key == $dia_hoje_key): ?>
                                                <br><small class="text-warning">Hoje</small>
                                            <?php endif; ?>
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Buscar todos os horários disponíveis
                                    $horarios_disponiveis = [];
                                    foreach ($horarios as $h) {
                                        $horarios_disponiveis[$h['horario_inicio'] . '-' . $h['horario_fim']] = [
                                            'inicio' => $h['horario_inicio'],
                                            'fim' => $h['horario_fim']
                                        ];
                                    }
                                    ksort($horarios_disponiveis);
                                    
                                    if (empty($horarios_disponiveis)):
                                    ?>
                                    <tr>
                                        <td colspan="8" class="sem-horarios">
                                            <i class="fas fa-clock fa-3x mb-3 d-block"></i>
                                            <p>Nenhum horário cadastrado para você.</p>
                                            <small class="text-muted">Entre em contato com a secretaria para configurar seus horários.</small>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($horarios_disponiveis as $slot): ?>
                                        <tr>
                                            <td class="bg-light fw-bold text-center">
                                                <?php echo date('H:i', strtotime($slot['inicio'])); ?> - <?php echo date('H:i', strtotime($slot['fim'])); ?>
                                            </td>
                                            <?php foreach ($dias_semana as $dia_key => $dia_label): ?>
                                            <td class="<?php echo $dia_key == $dia_hoje_key ? 'dia-ativo' : ''; ?>">
                                                <?php
                                                $aulas_dia = array_filter($horarios_por_dia[$dia_key]['horarios'], function($aula) use ($slot) {
                                                    return $aula['horario_inicio'] == $slot['inicio'] && $aula['horario_fim'] == $slot['fim'];
                                                });
                                                ?>
                                                <?php foreach ($aulas_dia as $aula): ?>
                                                <div class="aula-card">
                                                    <div class="aula-horario">
                                                        <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($aula['horario_inicio'])); ?>
                                                    </div>
                                                    <div class="aula-disciplina">
                                                        <?php echo htmlspecialchars($aula['disciplina_nome']); ?>
                                                    </div>
                                                    <div class="aula-turma">
                                                        <i class="fas fa-users"></i> <?php echo $aula['turma_ano'] . 'ª ' . $aula['turma_nome']; ?>
                                                    </div>
                                                    <?php if (!empty($aula['sala'])): ?>
                                                    <div class="aula-sala">
                                                        <i class="fas fa-door-open"></i> Sala: <?php echo htmlspecialchars($aula['sala']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Legenda -->
                <div class="legenda">
                    <div class="row">
                        <div class="col-md-4">
                            <i class="fas fa-square text-success"></i> Hoje
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-clock text-primary"></i> Horário da aula
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-door-open text-info"></i> Sala de aula
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Próximas Aulas -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-week"></i> Próximas Aulas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($proximas_aulas)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-calendar-times fa-3x mb-2 d-block"></i>
                                <p>Nenhuma aula programada nos próximos dias.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($proximas_aulas as $aula): ?>
                            <div class="proxima-aula-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge-dia badge p-1 mb-1">
                                            <i class="fas fa-calendar-day"></i> <?php echo $aula['dia_semana_nome']; ?>
                                        </span>
                                        <div class="aula-disciplina mt-1">
                                            <?php echo htmlspecialchars($aula['disciplina_nome']); ?>
                                        </div>
                                        <div class="aula-turma">
                                            <i class="fas fa-users"></i> <?php echo $aula['turma_ano'] . 'ª ' . $aula['turma_nome']; ?>
                                        </div>
                                        <div class="aula-horario mt-1">
                                            <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($aula['horario_inicio'])); ?> - <?php echo date('H:i', strtotime($aula['horario_fim'])); ?>
                                        </div>
                                        <?php if (!empty($aula['sala'])): ?>
                                        <div class="aula-sala">
                                            <i class="fas fa-door-open"></i> Sala: <?php echo htmlspecialchars($aula['sala']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <a href="registrar_chamada.php?turma_id=<?php echo $aula['turma_id']; ?>&disciplina_id=<?php echo $aula['disciplina_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-clipboard-list"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Resumo Semanal -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-simple"></i> Resumo Semanal</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $total_aulas = count($horarios);
                        $dias_com_aula = 0;
                        foreach ($horarios_por_dia as $dia) {
                            if (!empty($dia['horarios'])) {
                                $dias_com_aula++;
                            }
                        }
                        ?>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <h3 class="text-primary mb-0"><?php echo $total_aulas; ?></h3>
                                    <small>Total de Aulas</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <h3 class="text-success mb-0"><?php echo $dias_com_aula; ?></h3>
                                    <small>Dias com Aula</small>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>