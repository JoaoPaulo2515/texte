<?php
// aluno/index.php - Dashboard do Aluno (Design Moderno) - CORRIGIDO

define('ROOT_PATH', dirname(__DIR__, 2));

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';

// ============================================
// FUNÇÃO PARA DATAS EM PORTUGUÊS
// ============================================
function dataEmPortugues($data) {
    if (!$data) return '';
    
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    
    $dias = [
        1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira',
        4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'
    ];
    
    $timestamp = strtotime($data);
    $dia_numero = date('j', $timestamp);
    $mes_numero = (int)date('n', $timestamp);
    $ano = date('Y', $timestamp);
    $dia_semana_num = (int)date('N', $timestamp);
    
    return $dia_numero . ' de ' . $meses[$mes_numero] . ' de ' . $ano . ', ' . $dias[$dia_semana_num];
}

function nomeDiaSemanaPortugues($dia_numero) {
    $dias = [
        1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira',
        4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'
    ];
    return $dias[$dia_numero] ?? '';
}

function nomeMesPortugues($mes_numero) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[(int)$mes_numero] ?? '';
}

// Buscar dados do aluno
$sql_aluno = "SELECT * FROM estudantes WHERE id = :id AND escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma atual
$sql_turma = "SELECT t.*, t.ano as turma_ano, t.nome as turma_nome, t.turno, t.sala 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa' LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Verificar se encontrou turma
if ($turma) {
    $turma_ano = $turma['turma_ano'] ?? '';
    $turma_nome = $turma['turma_nome'] ?? 'Não atribuída';
    $turma_turno = $turma['turno'] ?? '';
    $turma_sala = $turma['sala'] ?? '';
    $turma_id = $turma['id'] ?? 0;
} else {
    $turma_ano = '';
    $turma_nome = 'Não atribuída';
    $turma_turno = '';
    $turma_sala = '';
    $turma_id = 0;
}

// Buscar notas do aluno
$sql_notas = "SELECT n.*, d.nome as disciplina 
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id 
              ORDER BY n.created_at DESC LIMIT 5";
$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id]);
$ultimas_notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Calcular média geral
$media_geral = 0;
$total_notas = count($ultimas_notas);
if ($total_notas > 0) {
    $soma = 0;
    foreach ($ultimas_notas as $nota) {
        $soma += $nota['media_final'] ?? 0;
    }
    $media_geral = $soma / $total_notas;
}

// Buscar mensalidades pendentes
$sql_mensalidades = "SELECT COUNT(*) as total, SUM(valor_total - valor_pago) as total_devedor
                     FROM mensalidades 
                     WHERE aluno_id = :aluno_id AND status IN ('pendente', 'parcial', 'atrasado') AND escola_id = :escola_id";
$stmt_mensalidades = $conn->prepare($sql_mensalidades);
$stmt_mensalidades->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$mensalidades = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);

if (!$mensalidades) {
    $mensalidades = ['total' => 0, 'total_devedor' => 0];
}

// Buscar tarefas pendentes
$tarefas_pendentes = 0;
if ($turma_id > 0) {
    $sql_tarefas = "SELECT COUNT(*) as total FROM tarefas t
                    LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = :aluno_id
                    WHERE t.turma_id = :turma_id AND r.id IS NULL AND t.data_entrega >= CURDATE()";
    $stmt_tarefas = $conn->prepare($sql_tarefas);
    $stmt_tarefas->execute([':aluno_id' => $aluno_id, ':turma_id' => $turma_id]);
    $tarefas_pendentes = $stmt_tarefas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
}

// Buscar próximos eventos
$sql_eventos = "SELECT * FROM comunicados 
                WHERE escola_id = :escola_id 
                AND status = 'ativo'
                AND (data_fim IS NULL OR data_fim >= CURDATE())
                ORDER BY data_inicio ASC LIMIT 4";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([':escola_id' => $escola_id]);
$proximos_eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Buscar avisos
$sql_avisos = "SELECT * FROM comunicados 
               WHERE escola_id = :escola_id 
               AND status = 'ativo'
               AND (turma_id IS NULL OR turma_id = :turma_id)
               AND (data_inicio IS NULL OR data_inicio <= CURDATE())
               AND (data_fim IS NULL OR data_fim >= CURDATE())
               ORDER BY created_at DESC LIMIT 4";
$stmt_avisos = $conn->prepare($sql_avisos);
$stmt_avisos->execute([':escola_id' => $escola_id, ':turma_id' => $turma_id]);
$avisos = $stmt_avisos->fetchAll(PDO::FETCH_ASSOC);

// Buscar horário de hoje
$dia_semana_num = date('N');
$horario_hoje = [];
if ($turma_id > 0) {
    $sql_horario = "SELECT h.*, d.nome as disciplina, d.cor 
                    FROM horarios h
                    JOIN disciplinas d ON d.id = h.disciplina_id
                    WHERE h.turma_id = :turma_id AND h.dia_semana = :dia
                    ORDER BY h.horario_inicio ASC";
    $stmt_horario = $conn->prepare($sql_horario);
    $stmt_horario->execute([':turma_id' => $turma_id, ':dia' => $dia_semana_num]);
    $horario_hoje = $stmt_horario->fetchAll(PDO::FETCH_ASSOC);
}

// Data atual em português
$data_atual = dataEmPortugues(date('Y-m-d'));
$dia_semana_atual = nomeDiaSemanaPortugues(date('N'));
$mes_atual = nomeMesPortugues(date('n'));
$dia_numero = date('d');
$ano_atual = date('Y');
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Área do Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Mantenha os estilos existentes aqui */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content-aluno {
            margin-left: 280px;
            margin-top: 80px;
            margin-bottom: 45px;
            padding: 20px 30px;
            min-height: calc(100vh - 125px);
        }
        
        @media (max-width: 768px) {
            .main-content-aluno {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 50%, #0d4d2e 100%);
            color: white;
            border-radius: 28px;
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.2);
        }
        
        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }
        
        .welcome-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        
        .welcome-card h3 {
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .welcome-card p {
            opacity: 0.9;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .welcome-date {
            text-align: right;
            position: relative;
            z-index: 1;
        }
        
        .welcome-date .day-number {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15);
        }
        
        .stat-card.media { border-left: 4px solid #4361ee; }
        .stat-card.mensalidades { border-left: 4px solid #f59e0b; }
        .stat-card.debito { border-left: 4px solid #ef4444; }
        .stat-card.tarefas { border-left: 4px solid #8b5cf6; }
        
        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 1.8rem;
        }
        
        .stat-card.media .stat-icon { background: linear-gradient(135deg, #4361ee20, #4361ee10); color: #4361ee; }
        .stat-card.mensalidades .stat-icon { background: linear-gradient(135deg, #f59e0b20, #f59e0b10); color: #f59e0b; }
        .stat-card.debito .stat-icon { background: linear-gradient(135deg, #ef444420, #ef444410); color: #ef4444; }
        .stat-card.tarefas .stat-icon { background: linear-gradient(135deg, #8b5cf620, #8b5cf610); color: #8b5cf6; }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-trend {
            font-size: 0.7rem;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0;
        }
        
        .content-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .content-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px -8px rgba(0,0,0,0.1);
        }
        
        .card-header-custom {
            background: transparent;
            padding: 18px 22px;
            border-bottom: 2px solid #f0f2f5;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .card-header-custom i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .nota-table {
            margin-bottom: 0;
        }
        
        .nota-table td, .nota-table th {
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        .badge-aprovado {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .badge-reprovado {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .list-item-custom {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f2f5;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .list-item-custom:last-child {
            border-bottom: none;
        }
        
        .list-item-custom:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .item-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .item-meta {
            font-size: 0.7rem;
            color: #95a5a6;
        }
        
        .event-date {
            background: linear-gradient(135deg, #1A2A6C, #006B3E);
            color: white;
            padding: 8px 12px;
            border-radius: 16px;
            text-align: center;
            min-width: 60px;
        }
        
        .event-date .day {
            font-size: 1.2rem;
            font-weight: 800;
            line-height: 1;
        }
        
        .event-date .month {
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        
        .horario-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #f0f2f5;
            transition: all 0.2s;
        }
        
        .horario-item:hover {
            background: #f8f9fa;
        }
        
        .horario-time {
            min-width: 80px;
            font-weight: 600;
            color: #006B3E;
        }
        
        .horario-disciplina {
            flex: 1;
            font-weight: 500;
        }
        
        .horario-professor {
            font-size: 0.7rem;
            color: #95a5a6;
        }
        
        .btn-view-all {
            background: none;
            border: none;
            color: #006B3E;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
        }
        
        .btn-view-all:hover {
            text-decoration: underline;
        }
        
        .quick-actions-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        
        .quick-stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 16px 20px;
            background: white;
            border-radius: 20px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
            position: relative;
            overflow: hidden;
        }
        
        .quick-stat-card:hover {
            transform: translateX(8px);
            border-color: transparent;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        
        .quick-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .quick-stat-icon i {
            font-size: 1.5rem;
            color: white;
        }
        
        .quick-stat-card:hover .quick-stat-icon {
            transform: scale(1.05);
        }
        
        .quick-stat-content {
            flex: 1;
        }
        
        .quick-stat-content h4 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 5px 0;
            color: #2c3e50;
        }
        
        .quick-stat-badge {
            font-size: 0.7rem;
            color: #006B3E;
            background: #e8f5e9;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .quick-stat-arrow {
            color: #cbd5e1;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }
        
        .quick-stat-card:hover .quick-stat-arrow {
            color: #006B3E;
            transform: translateX(5px);
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .welcome-card {
                text-align: center;
            }
            .welcome-date {
                text-align: center;
                margin-top: 15px;
            }
        }
    </style>
</head>
<body>

<?php include 'includes/menu_aluno.php'; ?>

<div class="main-content-aluno">
    <!-- Welcome Card Moderno com Data em Português -->
    <div class="welcome-card">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h3><i class="fas fa-graduation-cap me-2"></i> Olá, <?php echo htmlspecialchars($aluno['nome'] ?? $aluno_nome); ?>!</h3>
                <p class="mb-1">
                    <i class="fas fa-users me-2"></i> Turma: 
                    <?php echo $turma_ano ? $turma_ano . 'ª - ' : ''; ?><?php echo htmlspecialchars($turma_nome); ?>
                </p>
                <p>
                    <i class="fas fa-id-card me-2"></i> Matrícula: <?php echo $aluno['matricula'] ?? '---'; ?>
                </p>
            </div>
            <div class="col-md-4 welcome-date">
                <div class="day-number"><?php echo $dia_numero; ?></div>
                <div><?php echo $mes_atual; ?> de <?php echo $ano_atual; ?></div>
                <div class="mt-1">
                    <span class="badge bg-light text-dark"><?php echo $dia_semana_atual; ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card media">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value"><?php echo number_format($media_geral, 1, ',', '.'); ?> <span style="font-size: 0.9rem;">/ 20</span></div>
            <div class="stat-label">Média Geral</div>
            <div class="stat-trend">
                <i class="fas fa-arrow-up text-success me-1"></i> 
                <?php echo $media_geral >= 14 ? 'Excelente' : ($media_geral >= 10 ? 'Regular' : 'Atenção'); ?>
            </div>
        </div>
        
        <div class="stat-card mensalidades">
            <div class="stat-icon"><i class="fas fa-calendar-dollar"></i></div>
            <div class="stat-value"><?php echo $mensalidades['total'] ?? 0; ?></div>
            <div class="stat-label">Mensalidades Pendentes</div>
            <div class="stat-trend">
                <i class="fas fa-info-circle text-info me-1"></i>
                Regularize suas pendências
            </div>
        </div>
        
        <div class="stat-card debito">
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-value"><?php echo number_format($mensalidades['total_devedor'] ?? 0, 2, ',', '.'); ?> Kz</div>
            <div class="stat-label">Valor em Débito</div>
            <div class="stat-trend">
                <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                <?php echo ($mensalidades['total_devedor'] ?? 0) > 0 ? 'Pendente de pagamento' : 'Em dia'; ?>
            </div>
        </div>
        
        <div class="stat-card tarefas">
            <div class="stat-icon"><i class="fas fa-tasks"></i></div>
            <div class="stat-value"><?php echo $tarefas_pendentes; ?></div>
            <div class="stat-label">Tarefas Pendentes</div>
            <div class="stat-trend">
                <i class="fas fa-clock text-warning me-1"></i>
                Aguardando entrega
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Últimas Notas -->
        <div class="col-lg-6 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-chart-line" style="color: #4361ee;"></i> Últimas Avaliações
                </div>
                <div class="card-body p-0">
                    <?php if (empty($ultimas_notas)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-simple fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Nenhuma nota registrada ainda.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table nota-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Disciplina</th>
                                        <th class="text-end">Nota</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimas_notas as $nota): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($nota['disciplina'] ?? '---'); ?></strong>
                                        </td>
                                        <td class="text-end fw-bold"><?php echo number_format($nota['media_final'] ?? 0, 1, ',', '.'); ?></td>
                                        <td>
                                            <?php if (($nota['media_final'] ?? 0) >= 14): ?>
                                                <span class="badge-aprovado"><i class="fas fa-star me-1"></i> Excelente</span>
                                            <?php elseif (($nota['media_final'] ?? 0) >= 10): ?>
                                                <span class="badge-aprovado"><i class="fas fa-check me-1"></i> Aprovado</span>
                                            <?php else: ?>
                                                <span class="badge-reprovado"><i class="fas fa-exclamation me-1"></i> Reprovado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 py-3 text-center">
                    <a href="academico/boletim.php" class="btn-view-all">Ver Boletim Completo <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
        
        <!-- Horário de Hoje -->
        <div class="col-lg-6 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-clock" style="color: #f59e0b;"></i> Horário de Hoje
                    <span class="float-end small text-muted"><?php echo $dia_semana_atual . ', ' . $dia_numero . '/' . date('m') . '/' . $ano_atual; ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($horario_hoje)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-day fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Nenhuma aula programada para hoje.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($horario_hoje as $aula): ?>
                        <div class="horario-item">
                            <div class="horario-time">
                                <?php echo date('H:i', strtotime($aula['horario_inicio'] ?? '00:00')); ?> - <?php echo date('H:i', strtotime($aula['horario_fim'] ?? '00:00')); ?>
                            </div>
                            <div class="horario-disciplina">
                                <?php echo htmlspecialchars($aula['disciplina'] ?? '---'); ?>
                                <div class="horario-professor">Prof. <?php echo htmlspecialchars($aula['professor_nome'] ?? 'Não definido'); ?></div>
                            </div>
                            <div class="horario-sala">
                                <span class="badge bg-light text-dark">Sala: <?php echo $aula['sala'] ?? 'N/A'; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 py-3 text-center">
                    <a href="academico/horario.php" class="btn-view-all">Ver Horário Completo <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Avisos Recentes -->
        <div class="col-md-6 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-bullhorn" style="color: #ef4444;"></i> Avisos Recentes
                </div>
                <div class="card-body p-0">
                    <?php if (empty($avisos)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Nenhum aviso no momento.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($avisos as $aviso): ?>
                        <div class="list-item-custom">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="item-title"><?php echo htmlspecialchars($aviso['titulo'] ?? '---'); ?></div>
                                <small class="item-meta"><?php echo date('d/m/Y', strtotime($aviso['created_at'] ?? 'now')); ?></small>
                            </div>
                            <p class="mb-0 small text-muted"><?php echo htmlspecialchars(substr($aviso['conteudo'] ?? '', 0, 80)); ?>...</p>
                            <?php if (($aviso['prioridade'] ?? '') == 'alta'): ?>
                                <span class="badge bg-danger mt-2"><i class="fas fa-exclamation-triangle me-1"></i> Urgente</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 py-3 text-center">
                    <a href="comunicacao/avisos.php" class="btn-view-all">Ver Todos os Avisos <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
        
        <!-- Próximos Eventos -->
        <div class="col-md-6 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-calendar-alt" style="color: #8b5cf6;"></i> Próximos Eventos
                </div>
                <div class="card-body p-0">
                    <?php if (empty($proximos_eventos)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Nenhum evento programado.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($proximos_eventos as $evento): ?>
                        <div class="list-item-custom">
                            <div class="d-flex gap-3">
                                <div class="event-date text-center">
                                    <div class="day"><?php echo date('d', strtotime($evento['data_inicio'] ?? 'now')); ?></div>
                                    <div class="month"><?php echo strtoupper(substr(nomeMesPortugues(date('n', strtotime($evento['data_inicio'] ?? 'now'))), 0, 3)); ?></div>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="item-title"><?php echo htmlspecialchars($evento['titulo'] ?? '---'); ?></div>
                                    <p class="mb-0 small text-muted"><?php echo htmlspecialchars(substr($evento['conteudo'] ?? '', 0, 80)); ?>...</p>
                                    <div class="item-meta mt-1">
                                        <i class="fas fa-hourglass-half me-1"></i> 
                                        <?php
                                        $data_evento = isset($evento['data_inicio']) ? strtotime($evento['data_inicio']) : time();
                                        $dias_restantes = ($data_evento - time()) / 86400;
                                        $dias_restantes = round($dias_restantes);
                                        if ($dias_restantes == 0) echo "Hoje!";
                                        elseif ($dias_restantes == 1) echo "Amanhã!";
                                        elseif ($dias_restantes < 0) echo "Evento passado";
                                        else echo "Em $dias_restantes dias";
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 py-3 text-center">
                    <a href="comunicacao/avisos.php" class="btn-view-all">Ver Calendário Completo <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Ações Rápidas com Contadores -->
    <div class="row">
        <div class="col-12">
            <div class="content-card">
                <div class="card-header-custom">
                    <i class="fas fa-bolt" style="color: #f59e0b;"></i> Ações Rápidas
                </div>
                <div class="card-body">
                    <div class="quick-actions-stats">
                        <a href="mensalidades.php" class="quick-stat-card">
                            <div class="quick-stat-icon" style="background: linear-gradient(135deg, #4361ee, #3b82f6);">
                                <i class="fas fa-calendar-dollar"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h4>Mensalidades</h4>
                                <span class="quick-stat-badge"><?php echo $mensalidades['total'] ?? 0; ?> pendente(s)</span>
                            </div>
                            <i class="fas fa-chevron-right quick-stat-arrow"></i>
                        </a>
                        
                        <a href="minhas_tarefas.php" class="quick-stat-card">
                            <div class="quick-stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h4>Tarefas</h4>
                                <span class="quick-stat-badge"><?php echo $tarefas_pendentes; ?> pendente(s)</span>
                            </div>
                            <i class="fas fa-chevron-right quick-stat-arrow"></i>
                        </a>
                        
                        <a href="provas/disponiveis.php" class="quick-stat-card">
                            <div class="quick-stat-icon" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h4>Provas Online</h4>
                                <span class="quick-stat-badge">Disponíveis</span>
                            </div>
                            <i class="fas fa-chevron-right quick-stat-arrow"></i>
                        </a>
                        
                        <a href="biblioteca/emprestimos.php" class="quick-stat-card">
                            <div class="quick-stat-icon" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                                <i class="fas fa-hand-holding"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h4>Empréstimos</h4>
                                <span class="quick-stat-badge">Ver seus livros</span>
                            </div>
                            <i class="fas fa-chevron-right quick-stat-arrow"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Menu Toggle - CORRIGIDO
    (function() {
        // Aguardar o DOM carregar completamente
        document.addEventListener('DOMContentLoaded', function() {
            // Procurar pelo botão com ID correto
            const menuToggle = document.getElementById('menuToggleAluno');
            const sidebar = document.getElementById('sidebarAluno');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content-aluno');
            
            console.log('Botão encontrado:', menuToggle);
            console.log('Sidebar encontrada:', sidebar);
            
            if (menuToggle && sidebar) {
                // Abrir/fechar menu ao clicar no botão
                menuToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.toggle('open');
                    if (overlay) {
                        overlay.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
                    }
                    console.log('Menu toggled, open:', sidebar.classList.contains('open'));
                });
            } else {
                console.error('Elementos do menu não encontrados!');
                console.log('Verificando IDs: menuToggleAluno existe?', !!document.getElementById('menuToggleAluno'));
                console.log('Verificando IDs: sidebarAluno existe?', !!document.getElementById('sidebarAluno'));
            }
            
            // Fechar menu ao clicar no overlay
            if (overlay) {
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    overlay.style.display = 'none';
                });
            }
            
            // Fechar menu ao redimensionar a janela para desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    if (sidebar) sidebar.classList.remove('open');
                    if (overlay) overlay.style.display = 'none';
                }
            });
            
            // Fechar menu ao clicar em um link (mobile)
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        if (sidebar) sidebar.classList.remove('open');
                        if (overlay) overlay.style.display = 'none';
                    }
                });
            });
        });
    })();
</script>
</body>
</html>