<?php
// escola/aluno/academico/horario.php - Horário de Aulas do Aluno

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';

// Buscar dados do aluno e sua turma atual
$sql_aluno = "SELECT 
                e.id, 
                e.nome, 
                e.matricula, 
                e.curso,
                m.id as matricula_id,
                m.status as matricula_status,
                m.data_matricula,
                t.id as turma_id,
                t.nome as turma_nome,
                t.ano as turma_ano,
                t.turno as turma_periodo,
                t.turno as turma_turno,
                s.id as serie_id,
                s.nome as serie_nome,
                s.codigo as serie_nivel,
                a.id as ano_letivo_id,
                a.ano as ano_letivo_ano,
                a.ano as ano_letivo_nome
              FROM estudantes e
              INNER JOIN matriculas m ON m.estudante_id = e.id
              INNER JOIN turmas t ON t.id = m.turma_id
              LEFT JOIN cursos s ON s.id = t.curso
              LEFT JOIN ano_letivo a ON a.id = m.ano_letivo
              WHERE e.id = :aluno_id 
              AND e.escola_id = :escola_id
              AND m.status = 'ativa'
              ORDER BY m.id DESC
              LIMIT 1";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: ../dashboard.php?msg=erro');
    exit;
}

$turma_id = $aluno['turma_id'];
$turma_nome = $aluno['turma_nome'] ?? 'Turma não definida';
$turma_ano = $aluno['turma_ano'] ?? '';
$turma_periodo = $aluno['turma_periodo'] ?? '';

// Buscar ano letivo atual
$sql_ano_atual = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id AND ativo = 1 LIMIT 1";
$stmt_ano_atual = $conn->prepare($sql_ano_atual);
$stmt_ano_atual->execute([':escola_id' => $escola_id]);
$ano_atual = $stmt_ano_atual->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_atual ? $ano_atual['id'] : null;

// Buscar dias da semana (apenas segunda a sexta)
$dias_semana = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira'
];

// Buscar horários da turma na tabela horarios
$sql_horario = "SELECT h.*, 
                       d.nome as disciplina_nome, 
                       d.codigo as disciplina_codigo,
                       p.nome as professor_nome,
                       s.nome as sala_nome
                FROM horarios h
                JOIN disciplinas d ON d.id = h.disciplina_id
                LEFT JOIN funcionarios p ON p.id = h.professor_id
                LEFT JOIN salas s ON s.id = h.sala_id
                WHERE h.turma_id = :turma_id 
                AND h.ano_letivo_id = :ano_letivo_id
                AND h.status = 1
                AND h.dia_semana IN (1,2,3,4,5)
                ORDER BY h.dia_semana, h.horario_inicio";
$stmt_horario = $conn->prepare($sql_horario);
$stmt_horario->execute([':turma_id' => $turma_id, ':ano_letivo_id' => $ano_letivo_id]);
$horarios_turma = $stmt_horario->fetchAll(PDO::FETCH_ASSOC);

// Buscar todos os horários distintos (para montar a grade)
$sql_todos_horarios = "SELECT DISTINCT horario_inicio, horario_fim 
                       FROM horarios 
                       WHERE turma_id = :turma_id 
                       AND ano_letivo_id = :ano_letivo_id
                       AND status = 1
                       ORDER BY horario_inicio";
$stmt_todos_horarios = $conn->prepare($sql_todos_horarios);
$stmt_todos_horarios->execute([':turma_id' => $turma_id, ':ano_letivo_id' => $ano_letivo_id]);
$horarios_disponiveis = $stmt_todos_horarios->fetchAll(PDO::FETCH_ASSOC);

// Organizar horários por dia e horário
$grade_horarios = [];
foreach ($horarios_turma as $aula) {
    $dia = $aula['dia_semana'];
    $horario_inicio = substr($aula['horario_inicio'], 0, 5);
    
    if (!isset($grade_horarios[$dia])) {
        $grade_horarios[$dia] = [];
    }
    
    $grade_horarios[$dia][$horario_inicio] = $aula;
}

// Criar lista de horários únicos ordenados
$horarios_unicos = [];
foreach ($horarios_disponiveis as $h) {
    $key = substr($h['horario_inicio'], 0, 5);
    if (!isset($horarios_unicos[$key])) {
        $horarios_unicos[$key] = [
            'inicio' => substr($h['horario_inicio'], 0, 5),
            'fim' => substr($h['horario_fim'], 0, 5)
        ];
    }
}
ksort($horarios_unicos);

// Buscar semana atual (para destacar o dia atual)
$dia_atual = date('N'); // 1 = Segunda, 5 = Sexta, 6 = Sábado, 7 = Domingo
if ($dia_atual > 5) $dia_atual = 1; // Se for sábado ou domingo, destacar segunda

// Buscar informações do ano letivo
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim 
                   FROM ano_letivo 
                   WHERE escola_id = :escola_id AND ativo = 1 
                   LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano_letivo);
$stmt_ano->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);

// Buscar próximos eventos/avaliações
$sql_eventos = "SELECT * FROM eventos_calendario 
                WHERE turma_id = :turma_id 
                AND data_evento >= CURDATE() 
                ORDER BY data_evento ASC 
                LIMIT 5";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([':turma_id' => $turma_id]);
$eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Buscar faltas do aluno no mês
$sql_faltas = "SELECT COUNT(*) as total_faltas 
               FROM presencas 
               WHERE matricula_id = :aluno_id 
               AND MONTH(created_at) = MONTH(CURDATE()) 
               AND tipo_falta = 'injustificada'";
$stmt_faltas = $conn->prepare($sql_faltas);
$stmt_faltas->execute([':aluno_id' => $aluno_id]);
$total_faltas = $stmt_faltas->fetch(PDO::FETCH_ASSOC)['total_faltas'] ?? 0;

// Buscar total de aulas no mês
$sql_total_aulas = "SELECT COUNT(*) as total 
                    FROM presencas 
                    WHERE matricula_id = :aluno_id 
                    AND MONTH(created_at) = MONTH(CURDATE())";
$stmt_total_aulas = $conn->prepare($sql_total_aulas);
$stmt_total_aulas->execute([':aluno_id' => $aluno_id]);
$total_aulas = $stmt_total_aulas->fetch(PDO::FETCH_ASSOC)['total'] ?? 1;

if($total_aulas!=0){
$percentual_presenca = round((($total_aulas - $total_faltas) / $total_aulas) * 100, 1);}else{$percentual_presenca =0;}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horário de Aulas | Área do Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
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
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
            display: inline-block;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
        }
        
        /* Tabela de Horário */
        .horario-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .horario-table th,
        .horario-table td {
            border: 1px solid #dee2e6;
            padding: 12px;
            text-align: center;
            vertical-align: middle;
        }
        
        .horario-table th {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            font-weight: bold;
        }
        
        .horario-table td {
            background: white;
        }
        
        .horario-table .aula-atual {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            font-weight: bold;
        }
        
        .dia-atual {
            background: #e8f5e9 !important;
            position: relative;
        }
        
        .dia-atual::before {
            content: "●";
            color: #006B3E;
            position: absolute;
            top: 5px;
            left: 5px;
            font-size: 10px;
        }
        
        .intervalo-cell {
            background: #f8f9fa;
            color: #6c757d;
            font-style: italic;
        }
        
        .disciplina-nome {
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .professor-nome {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        .sala-info {
            font-size: 0.7rem;
            color: #28a745;
        }
        
        .horario-info {
            font-size: 0.7rem;
            color: #17a2b8;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .evento-item {
            border-left: 4px solid #006B3E;
            padding: 10px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .evento-data {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        .presenca-boa {
            color: #28a745;
        }
        
        .presenca-media {
            color: #ffc107;
        }
        
        .presenca-ruim {
            color: #dc3545;
        }
    </style>
</head>
<body>
     <?php include 'includes/menu_aluno.php'; ?>
</br></br></br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 style="color: #333; text-shadow: none;">
                    <i class="fas fa-calendar-alt"></i> Horário de Aulas
                </h1>
                <p style="color: #666;">Visualize seu horário semanal e acompanhamento acadêmico</p>
            </div>
            <div>
                <a href="../dashboard.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($aluno['ano_letivo_nome']); ?></h5>
                                <p class="mb-1"><strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula']); ?></p>
                                <p class="mb-1"><strong>Curso:</strong> <?php echo htmlspecialchars($aluno['curso'] ?? 'Não definido'); ?></p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <h5><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($turma_nome); ?></h5>
                                <p class="mb-1"><strong>Ano:</strong> <?php echo $turma_ano; ?>º Ano</p>
                                <p class="mb-1"><strong>Período:</strong> <?php echo $turma_periodo; ?></p>
                                <?php if ($ano_letivo): ?>
                                <p class="mb-0"><strong>Ano Letivo:</strong> <?php echo htmlspecialchars($ano_letivo['ano']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?php echo ($percentual_presenca >= 75) ? 'presenca-boa' : (($percentual_presenca >= 50) ? 'presenca-media' : 'presenca-ruim'); ?>">
                        <?php echo $percentual_presenca; ?>%
                    </div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Frequência do Mês</div>
                    <small>Faltas: <?php echo $total_faltas; ?> / <?php echo $total_aulas; ?> aulas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($horarios_turma); ?></div>
                    <div class="stat-label"><i class="fas fa-book"></i> Disciplinas na Grade</div>
                    <small>Total de disciplinas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($eventos); ?></div>
                    <div class="stat-label"><i class="fas fa-calendar-day"></i> Próximos Eventos</div>
                    <small>Avaliações e atividades</small>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Horário -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Grade de Horários</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="horario-table">
                        <thead>
                            <tr>
                                <th width="120">Horário</th>
                                <?php foreach ($dias_semana as $num => $dia): ?>
                                <th class="<?php echo ($num == $dia_atual) ? 'dia-atual' : ''; ?>">
                                    <?php echo $dia; ?>
                                    <?php if ($num == $dia_atual): ?>
                                    <br><span class="badge bg-success mt-1" style="font-size: 9px;">Hoje</span>
                                    <?php endif; ?>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($horarios_unicos)): ?>
                            <tr>
                                <td colspan="6" class="text-center p-4">
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle"></i> Nenhum horário cadastrado para sua turma.
                                    </div>
                                 </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($horarios_unicos as $horario): ?>
                                <tr>
                                    <td class="horario-info">
                                        <strong><?php echo $horario['inicio']; ?> - <?php echo $horario['fim']; ?></strong>
                                    </td>
                                    <?php foreach ($dias_semana as $num => $dia): ?>
                                        <?php
                                        $aula = isset($grade_horarios[$num][$horario['inicio']]) ? $grade_horarios[$num][$horario['inicio']] : null;
                                        $is_atual = ($num == $dia_atual);
                                        ?>
                                        <td class="<?php echo ($is_atual && $aula) ? 'aula-atual' : ''; ?>">
                                            <?php if ($aula): ?>
                                                <div class="disciplina-nome">
                                                    <?php echo htmlspecialchars($aula['disciplina_nome']); ?>
                                                </div>
                                                <div class="professor-nome">
                                                    <i class="fas fa-chalkboard-user"></i> <?php echo htmlspecialchars($aula['professor_nome']); ?>
                                                </div>
                                                <div class="sala-info">
                                                    <i class="fas fa-door-open"></i> Sala: <?php echo htmlspecialchars($aula['sala_nome']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
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
        
        <!-- Próximos Eventos -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-day"></i> Próximos Eventos e Avaliações</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($eventos)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle"></i> Nenhum evento agendado.
                            </div>
                        <?php else: ?>
                            <?php foreach ($eventos as $evento): ?>
                            <div class="evento-item">
                                <div class="d-flex justify-content-between">
                                    <strong>
                                        <i class="fas <?php echo $evento['tipo'] == 'prova' ? 'fa-file-alt text-danger' : 'fa-calendar-alt text-primary'; ?>"></i>
                                        <?php echo htmlspecialchars($evento['titulo']); ?>
                                    </strong>
                                    <span class="badge <?php echo $evento['tipo'] == 'prova' ? 'bg-danger' : 'bg-info'; ?>">
                                        <?php echo ucfirst($evento['tipo']); ?>
                                    </span>
                                </div>
                                <div class="evento-data">
                                    <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?>
                                    <?php if ($evento['horario']): ?>
                                    | <i class="fas fa-clock"></i> <?php echo $evento['horario']; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1">
                                    <small><?php echo htmlspecialchars($evento['descricao']); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bell"></i> Informações Importantes</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-clock"></i>
                            <strong>Atenção à pontualidade!</strong>
                            <p class="mb-0 small">A tolerância máxima de atraso é de 15 minutos. Após este período, será registrada falta.</p>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-book"></i>
                            <strong>Material necessário</strong>
                            <p class="mb-0 small">Tenha sempre seu material escolar completo: caderno, livro didático e estojo.</p>
                        </div>
                        <div class="alert alert-success">
                            <i class="fas fa-mobile-alt"></i>
                            <strong>Acesso online</strong>
                            <p class="mb-0 small">Use o aplicativo SIGE Angola para acompanhar suas notas e frequência em tempo real.</p>
                        </div>
                        <?php if ($percentual_presenca < 75): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção!</strong>
                            <p class="mb-0 small">Sua frequência está abaixo de 75%. Procure melhorar sua assiduidade para não prejudicar sua aprovação.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Destacar horário atual
        function destacarHorarioAtual() {
            const agora = new Date();
            const horaAtual = agora.getHours();
            const minutoAtual = agora.getMinutes();
            const horaMinuto = `${String(horaAtual).padStart(2,'0')}:${String(minutoAtual).padStart(2,'0')}`;
            
            $('.horario-table td').each(function() {
                let horarioCell = $(this).closest('tr').find('td:first').text();
                if (horarioCell.includes(horaMinuto)) {
                    $(this).addClass('aula-atual');
                }
            });
        }
        
        // Atualizar a cada minuto
        // setInterval(destacarHorarioAtual, 60000);
    </script>
</body>
</html>