<?php
// escola/pedagogico/detalhes_turma.php - Detalhes da Turma

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];
$turma_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($turma_id <= 0) {
    header('Location: listar_turmas.php');
    exit;
}

// Buscar dados completos da turma
$sql_turma = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        t.turno_id,
        tr.nome as turno_nome,
        t.sala_id,
        s.nome as sala_nome,
        s.capacidade as sala_capacidade,
        t.capacidade,
        t.vagas_disponiveis,
        t.numero_alunos,
        t.horario,
        t.status,
        t.data_inicio,
        t.data_fim,
        t.created_at,
        t.updated_at,
        t.curso_id,
        c.nome as curso_nome,
        t.classe_id,
        cl.nome as classe_nome,
        t.ano_letivo_id,
        al.ano as ano_letivo_ano,
        al.data_inicio as ano_letivo_inicio,
        al.data_fim as ano_letivo_fim
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    LEFT JOIN salas s ON s.id = t.sala_id
    LEFT JOIN cursos c ON c.id = t.curso_id
    LEFT JOIN classes cl ON cl.id = t.classe_id
    LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
    WHERE t.id = :turma_id AND t.escola_id = :escola_id
";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([
    ':turma_id' => $turma_id,
    ':escola_id' => $escola_id
]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: listar_turmas.php');
    exit;
}

// Buscar disciplinas da turma
$sql_disciplinas = "
    SELECT 
        dt.id,
        dt.disciplina_id,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        d.carga_horaria,
        c.nome as curso_nome
    FROM disciplina_turma dt
    INNER JOIN disciplinas d ON d.id = dt.disciplina_id
    LEFT JOIN cursos c ON c.id = d.curso_id
    WHERE dt.turma_id = :turma_id
    ORDER BY d.nome ASC
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores da turma
$sql_professores = "
    SELECT 
        p.id,
        p.nome,
        p.email,
        p.telefone,
        p.cargo,
        d.nome as disciplina_nome,
        pdt.dia_semana,
        pdt.horario_inicio,
        pdt.horario_fim,
        pdt.carga_horaria,
        pdt.status
    FROM professor_disciplina_turma pdt
    INNER JOIN funcionarios p ON p.id = pdt.professor_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.turma_id = :turma_id
    ORDER BY d.nome ASC
";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':turma_id' => $turma_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos da turma (últimos 10)
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.telefone,
        e.email,
        e.encarregado_nome,
        e.encarregado_telefone,
        m.data_matricula,
        m.status as matricula_status,
        COALESCE(AVG(n.mac), 0) as media_notas
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.ano_letivo_id = m.ano_letivo
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    GROUP BY e.id
    ORDER BY e.nome ASC
    LIMIT 10
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Contar total de alunos
$sql_total_alunos = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
$stmt_total = $conn->prepare($sql_total_alunos);
$stmt_total->execute([':turma_id' => $turma_id]);
$total_alunos = $stmt_total->fetch(PDO::FETCH_ASSOC);

// Buscar horários da turma
$sql_horarios = "
    SELECT 
        h.id,
        h.dia_semana,
        h.horario_inicio,
        h.horario_fim,
        d.nome as disciplina_nome,
        p.nome as professor_nome,
        s.nome as sala_nome,
        h.status
    FROM horarios h
    INNER JOIN disciplinas d ON d.id = h.disciplina_id
    LEFT JOIN funcionarios p ON p.id = h.professor_id
    LEFT JOIN salas s ON s.id = h.sala_id
    WHERE h.turma_id = :turma_id AND h.ano_letivo_id = :ano_letivo_id
    ORDER BY h.dia_semana, h.horario_inicio
";
$stmt_horarios = $conn->prepare($sql_horarios);
$stmt_horarios->execute([
    ':turma_id' => $turma_id,
    ':ano_letivo_id' => $turma['ano_letivo_id']
]);
$horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);

// Dias da semana - CORRIGIDO: usar números em vez de strings
$dias_semana = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    7 => 'Domingo'
];

// Calcular percentual de ocupação
$percentual_ocupacao = 0;
if ($turma['capacidade'] > 0) {
    $percentual_ocupacao = round(($turma['numero_alunos'] / $turma['capacidade']) * 100, 1);
}

// Organizar horários por dia - CORRIGIDO
$horarios_por_dia = [];
foreach ($horarios as $horario) {
    $dia = (int)$horario['dia_semana'];
    if (!isset($horarios_por_dia[$dia])) {
        $horarios_por_dia[$dia] = [];
    }
    $horarios_por_dia[$dia][] = $horario;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Turma - <?php echo htmlspecialchars($turma['nome']); ?> - SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header-title p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .btn-group-header {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }
        
        .btn-editar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-editar:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-header a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .card-header a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .card-body {
            padding: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid #1e5799;
        }
        
        .info-label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-ativa {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .status-inativa {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .status-concluida {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .progress-bar {
            background: #ecf0f1;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .progress-fill {
            background: #27ae60;
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .progress-fill.warning {
            background: #f39c12;
        }
        
        .progress-fill.danger {
            background: #e74c3c;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
        }
        
        .table td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .badge-info {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .badge-success {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .btn-ver-todos {
            text-align: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #ecf0f1;
        }
        
        .btn-ver-todos a {
            color: #1e5799;
            text-decoration: none;
            font-weight: 600;
        }
        
        .btn-ver-todos a:hover {
            text-decoration: underline;
        }
        
        .horario-mini {
            font-size: 12px;
            margin-bottom: 5px;
            padding: 5px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .two-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .btn-group-header {
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .two-columns {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📋 Detalhes da Turma</h1>
            <p><?php echo htmlspecialchars($turma['nome']); ?> - <?php echo $turma['ano']; ?>ª Classe</p>
        </div>
        <div class="btn-group-header">
            <a href="listar_turmas.php" class="btn-voltar">
                ← Voltar para Lista
            </a>
            <a href="editar_turma.php?id=<?php echo $turma_id; ?>" class="btn-editar">
                ✏️ Editar Turma
            </a>
        </div>
    </div>
    
    <!-- Informações Gerais -->
    <div class="card">
        <div class="card-header">
            📌 Informações Gerais
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">🏫 Nome da Turma</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎓 Ano</div>
                    <div class="info-value"><?php echo $turma['ano']; ?>ª Classe</div>
                </div>
                <div class="info-item">
                    <div class="info-label">🕐 Turno</div>
                    <div class="info-value"><?php echo ucfirst($turma['turno_nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🏠 Sala</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['sala_nome'] ?? 'Não definida'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎯 Curso</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['curso_nome'] ?? 'Geral'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📖 Classe</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['classe_nome'] ?? $turma['ano'] . 'ª'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">⏰ Horário</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['horario'] ?? 'Não definido'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📊 Status</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $turma['status']; ?>">
                            <?php echo strtoupper($turma['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Data de Criação</div>
                    <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($turma['created_at'])); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Capacidade e Ocupação -->
    <div class="card">
        <div class="card-header">
            📊 Capacidade e Ocupação
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Capacidade Máxima</div>
                    <div class="info-value"><?php echo $turma['capacidade'] ?? 'Ilimitada'; ?> alunos</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Alunos Matriculados</div>
                    <div class="info-value"><?php echo $turma['numero_alunos']; ?> alunos</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Vagas Disponíveis</div>
                    <div class="info-value"><?php echo $turma['vagas_disponiveis'] ?? ($turma['capacidade'] - $turma['numero_alunos']); ?> vagas</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Taxa de Ocupação</div>
                    <div class="info-value">
                        <?php echo $percentual_ocupacao; ?>%
                        <div class="progress-bar">
                            <div class="progress-fill <?php echo $percentual_ocupacao >= 90 ? 'danger' : ($percentual_ocupacao >= 75 ? 'warning' : ''); ?>" 
                                 style="width: <?php echo $percentual_ocupacao; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <!-- Disciplinas -->
        <div class="card">
            <div class="card-header">
                📚 Disciplinas da Turma
                <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma_id; ?>">+ Gerenciar</a>
            </div>
            <div class="card-body">
                <?php if (empty($disciplinas)): ?>
                    <p style="color: #7f8c8d; text-align: center;">Nenhuma disciplina atribuída.</p>
                    <div class="btn-ver-todos">
                        <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma_id; ?>">+ Atribuir Disciplinas</a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Disciplina</th>
                                <th>Código</th>
                                <th>Carga Horária</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($disciplinas as $disciplina): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($disciplina['disciplina_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($disciplina['disciplina_codigo']); ?></td>
                                    <td><?php echo $disciplina['carga_horaria'] ? $disciplina['carga_horaria'] . 'h' : '---'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="btn-ver-todos">
                        <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma_id; ?>">Gerenciar Disciplinas</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Professores -->
        <div class="card">
            <div class="card-header">
                👨‍🏫 Professores
                <a href="atribuir_professor.php?turma_id=<?php echo $turma_id; ?>">+ Gerenciar</a>
            </div>
            <div class="card-body">
                <?php if (empty($professores)): ?>
                    <p style="color: #7f8c8d; text-align: center;">Nenhum professor atribuído.</p>
                    <div class="btn-ver-todos">
                        <a href="atribuir_professor.php?turma_id=<?php echo $turma_id; ?>">+ Atribuir Professores</a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Professor</th>
                                <th>Disciplina</th>
                                <th>Horário</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professores as $professor): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($professor['nome']); ?>
                                        <?php if ($professor['cargo']): ?>
                                            <br><small><?php echo htmlspecialchars($professor['cargo']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($professor['disciplina_nome']); ?></td>
                                    <td>
                                        <?php 
                                        if ($professor['dia_semana'] && $professor['horario_inicio']) {
                                            $dia_num = (int)$professor['dia_semana'];
                                            echo isset($dias_semana[$dia_num]) ? $dias_semana[$dia_num] : 'Dia ' . $dia_num;
                                            echo '<br>';
                                            echo date('H:i', strtotime($professor['horario_inicio'])) . ' - ' . date('H:i', strtotime($professor['horario_fim']));
                                        } else {
                                            echo '---';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="btn-ver-todos">
                        <a href="atribuir_professor.php?turma_id=<?php echo $turma_id; ?>">Gerenciar Professores</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <!-- Horário de Aulas - CORRIGIDO -->
        <div class="card">
            <div class="card-header">
                🕐 Horário de Aulas
                <a href="horario_turma.php?turma_id=<?php echo $turma_id; ?>">+ Gerenciar</a>
            </div>
            <div class="card-body">
                <?php if (empty($horarios)): ?>
                    <p style="color: #7f8c8d; text-align: center;">Nenhum horário definido.</p>
                    <div class="btn-ver-todos">
                        <a href="horario_turma.php?turma_id=<?php echo $turma_id; ?>">+ Definir Horário</a>
                    </div>
                <?php else: ?>
                    <?php 
                    $dias_exibidos = [];
                    foreach ($horarios_por_dia as $dia => $aulas): 
                        $nome_dia = isset($dias_semana[$dia]) ? $dias_semana[$dia] : 'Dia ' . $dia;
                    ?>
                        <div style="margin-bottom: 15px;">
                            <strong><?php echo $nome_dia; ?></strong>
                            <?php foreach ($aulas as $aula): ?>
                                <div class="horario-mini">
                                    <?php echo date('H:i', strtotime($aula['horario_inicio'])); ?> - 
                                    <?php echo date('H:i', strtotime($aula['horario_fim'])); ?> |
                                    <strong><?php echo htmlspecialchars($aula['disciplina_nome']); ?></strong>
                                    <?php if ($aula['professor_nome']): ?>
                                        <br><small>👨‍🏫 <?php echo htmlspecialchars($aula['professor_nome']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="btn-ver-todos">
                        <a href="horario_turma.php?turma_id=<?php echo $turma_id; ?>">Ver Horário Completo</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Alunos -->
        <div class="card">
            <div class="card-header">
                👨‍🎓 Alunos Matriculados
                <a href="alunos_turma.php?turma_id=<?php echo $turma_id; ?>">+ Gerenciar</a>
            </div>
            <div class="card-body">
                <?php if (empty($alunos)): ?>
                    <p style="color: #7f8c8d; text-align: center;">Nenhum aluno matriculado.</p>
                    <div class="btn-ver-todos">
                        <a href="matricular_aluno.php?turma_id=<?php echo $turma_id; ?>">+ Matricular Aluno</a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Matrícula</th>
                                <th>Média</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $aluno): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($aluno['nome']); ?>
                                        <?php if ($aluno['encarregado_nome']): ?>
                                            <br><small>📞 Enc: <?php echo htmlspecialchars($aluno['encarregado_nome']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td>
                                        <?php 
                                        $media = round($aluno['media_notas'], 1);
                                        if ($media >= 14) {
                                            echo '<span class="badge badge-success">' . $media . '</span>';
                                        } elseif ($media >= 10) {
                                            echo '<span class="badge badge-info">' . $media . '</span>';
                                        } else {
                                            echo '<span class="badge" style="background:#fadbd8; color:#c0392b;">' . $media . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($total_alunos['total'] > 10): ?>
                        <div class="btn-ver-todos">
                            <a href="alunos_turma.php?turma_id=<?php echo $turma_id; ?>">
                                Ver todos os <?php echo $total_alunos['total']; ?> alunos
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="btn-ver-todos">
                        <a href="matricular_aluno.php?turma_id=<?php echo $turma_id; ?>">+ Nova Matrícula</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Datas Importantes -->
    <div class="card">
        <div class="card-header">
            📅 Datas Importantes
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Data de Início</div>
                    <div class="info-value">
                        <?php echo $turma['data_inicio'] ? date('d/m/Y', strtotime($turma['data_inicio'])) : 'Não definida'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Data de Término</div>
                    <div class="info-value">
                        <?php echo $turma['data_fim'] ? date('d/m/Y', strtotime($turma['data_fim'])) : 'Não definida'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Início do Ano Letivo</div>
                    <div class="info-value">
                        <?php echo $turma['ano_letivo_inicio'] ? date('d/m/Y', strtotime($turma['ano_letivo_inicio'])) : 'Não definida'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Término do Ano Letivo</div>
                    <div class="info-value">
                        <?php echo $turma['ano_letivo_fim'] ? date('d/m/Y', strtotime($turma['ano_letivo_fim'])) : 'Não definida'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>