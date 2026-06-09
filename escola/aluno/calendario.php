<?php
// escola/aluno/academico/calendario.php - Calendário Acadêmico do Aluno

require_once __DIR__ . '/../../config/database.php';
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
$titulo_pagina = 'Calendário Académico';

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

// Filtros
$mes_selecionado = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano_selecionado = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

// Buscar feriados nacionais
$sql_feriados = "SELECT * FROM feriados_nacionais 
                 WHERE MONTH(data) = :mes AND YEAR(data) = :ano
                 ORDER BY data ASC";
$stmt_feriados = $conn->prepare($sql_feriados);
$stmt_feriados->execute([':mes' => $mes_selecionado, ':ano' => $ano_selecionado]);
$feriados = $stmt_feriados->fetchAll(PDO::FETCH_ASSOC);

// Buscar eventos da escola/turma
$sql_eventos = "SELECT * FROM eventos_calendario 
                WHERE escola_id = :escola_id 
                AND (turma_id IS NULL OR turma_id = :turma_id)
                AND MONTH(data_evento) = :mes 
                AND YEAR(data_evento) = :ano
                ORDER BY data_evento ASC";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma['id'] ?? 0,
    ':mes' => $mes_selecionado,
    ':ano' => $ano_selecionado
]);
$eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Buscar provas do aluno
$sql_provas = "SELECT p.*, d.nome as disciplina_nome
               FROM provas p
               JOIN disciplinas d ON d.id = p.disciplina_id
               WHERE p.turma_id = :turma_id 
               AND MONTH(p.data_prova) = :mes 
               AND YEAR(p.data_prova) = :ano
               ORDER BY p.data_prova ASC";
$stmt_provas = $conn->prepare($sql_provas);
$stmt_provas->execute([':turma_id' => $turma['id'] ?? 0, ':mes' => $mes_selecionado, ':ano' => $ano_selecionado]);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// Buscar datas de entrega de trabalhos
$sql_trabalhos = "SELECT t.*, d.nome as disciplina_nome
                  FROM trabalhos t
                  JOIN disciplinas d ON d.id = t.disciplina_id
                  WHERE t.turma_id = :turma_id 
                  AND MONTH(t.data_entrega) = :mes 
                  AND YEAR(t.data_entrega) = :ano
                  ORDER BY t.data_entrega ASC";
$stmt_trabalhos = $conn->prepare($sql_trabalhos);
$stmt_trabalhos->execute([':turma_id' => $turma['id'] ?? 0, ':mes' => $mes_selecionado, ':ano' => $ano_selecionado]);
$trabalhos = $stmt_trabalhos->fetchAll(PDO::FETCH_ASSOC);

// Buscar períodos letivos
$sql_periodos = "SELECT * FROM periodo_letivo 
                 WHERE escola_id = :escola_id 
                 AND YEAR(data_inicio) = :ano 
                 ORDER BY data_inicio ASC";
$stmt_periodos = $conn->prepare($sql_periodos);
$stmt_periodos->execute([':escola_id' => $escola_id, ':ano' => $ano_selecionado]);
$periodos = $stmt_periodos->fetchAll(PDO::FETCH_ASSOC);

// Dias do mês
$dias_no_mes = cal_days_in_month(CAL_GREGORIAN, $mes_selecionado, $ano_selecionado);
$primeiro_dia = date('w', strtotime("$ano_selecionado-$mes_selecionado-01"));
$primeiro_dia = ($primeiro_dia == 0) ? 6 : $primeiro_dia - 1; // Ajustar para segunda-feira como primeiro dia

// Nomes dos dias
$dias_semana = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

// Função para obter cor do evento
function getCorEventoCalendario($tipo) {
    switch ($tipo) {
        case 'prova': return '#dc3545';
        case 'trabalho': return '#ffc107';
        case 'evento': return '#17a2b8';
        case 'feriado': return '#6c757d';
        case 'reuniao': return '#fd7e14';
        case 'periodo_letivo': return '#28a745';
        default: return '#006B3E';
    }
}

// Função para obter ícone do evento
function getIconeEventoCalendario($tipo) {
    switch ($tipo) {
        case 'prova': return '<i class="fas fa-pen-alt"></i>';
        case 'trabalho': return '<i class="fas fa-file-alt"></i>';
        case 'evento': return '<i class="fas fa-calendar-day"></i>';
        case 'feriado': return '<i class="fas fa-umbrella-beach"></i>';
        case 'reuniao': return '<i class="fas fa-users"></i>';
        case 'periodo_letivo': return '<i class="fas fa-calendar-week"></i>';
        default: return '<i class="fas fa-calendar-alt"></i>';
    }
}

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
        
        /* Calendário */
        .calendario {
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .calendario-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px;
            text-align: center;
        }
        
        .calendario-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .calendario-dias {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .calendario-dia-semana {
            padding: 10px;
            text-align: center;
            font-weight: bold;
            color: #006B3E;
        }
        
        .calendario-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        
        .calendario-cell {
            min-height: 100px;
            border: 1px solid #dee2e6;
            padding: 5px;
            vertical-align: top;
            background: white;
            transition: all 0.2s;
        }
        
        .calendario-cell:hover {
            background: #f8f9fa;
        }
        
        .calendario-cell.outro-mes {
            background: #f8f9fa;
            color: #adb5bd;
        }
        
        .calendario-cell.hoje {
            background: #e8f5e9;
            border: 2px solid #006B3E;
        }
        
        .dia-numero {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .evento-item-calendario {
            font-size: 0.7rem;
            padding: 2px 4px;
            margin-bottom: 2px;
            border-radius: 3px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .evento-item-calendario:hover {
            opacity: 0.8;
        }
        
        .tooltip-evento {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.75rem;
            z-index: 1000;
            max-width: 250px;
            white-space: normal;
            display: none;
        }
        
        .lista-eventos {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .evento-card {
            border-left: 4px solid;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .evento-card:hover {
            transform: translateX(5px);
        }
        
        .evento-tipo {
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        
        .periodo-card {
            border-left: 4px solid #28a745;
            margin-bottom: 10px;
            padding: 10px;
            background: #d4edda;
            border-radius: 8px;
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
            .calendario-cell {
                min-height: 80px;
                font-size: 0.8rem;
            }
            .evento-item-calendario {
                font-size: 0.6rem;
            }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .legenda-cor {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 3px;
            margin-right: 8px;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<!-- Botão de Ajuda Flutuante -->
<button class="btn-ajuda" id="btnAjuda">
    <i class="fas fa-question fa-lg"></i>
</button>
    <?php include 'includes/menu_aluno.php'; ?>
   

<!-- Modal de Ajuda -->
<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Calendário Académico</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">
                    Esta página exibe o calendário académico com todas as datas importantes:
                    provas, trabalhos, eventos e feriados.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Navegação</div>
                <div class="ajuda-texto">
                    Utilize os botões de navegação para mudar de mês/ano. 
                    Clique nos eventos para ver mais detalhes.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Cores dos Eventos</div>
                <div class="ajuda-texto">
                    <div><span class="legenda-cor" style="background: #dc3545;"></span> Provas</div>
                    <div><span class="legenda-cor" style="background: #ffc107;"></span> Trabalhos</div>
                    <div><span class="legenda-cor" style="background: #17a2b8;"></span> Eventos</div>
                    <div><span class="legenda-cor" style="background: #6c757d;"></span> Feriados</div>
                    <div><span class="legenda-cor" style="background: #28a745;"></span> Períodos Letivos</div>
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Dicas</div>
                <div class="ajuda-texto">
                    • Fique atento às datas de provas e trabalhos<br>
                    • Planeje seus estudos com antecedência<br>
                    • Consulte o calendário regularmente
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-calendar-alt"></i> Calendário Académico</h4>
            <p class="text-muted mb-0">Acompanhe as datas importantes do seu ano letivo</p>
        </div>
        <div>
            <form method="GET" class="d-inline-flex gap-2">
                <select name="mes" class="form-select" style="width: auto;">
                    <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $mes_selecionado == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
                <select name="ano" class="form-select" style="width: auto;">
                    <?php for($a = date('Y')-2; $a <= date('Y')+2; $a++): ?>
                    <option value="<?php echo $a; ?>" <?php echo $ano_selecionado == $a ? 'selected' : ''; ?>>
                        <?php echo $a; ?>
                    </option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
            </form>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
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
                    <small class="text-muted"><i class="fas fa-calendar-alt"></i> Período</small>
                    <h6 class="mb-0"><?php echo date('F/Y', strtotime("$ano_selecionado-$mes_selecionado-01")); ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Períodos Letivos -->
    <?php if (!empty($periodos)): ?>
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-calendar-week"></i> Períodos Letivos - <?php echo $ano_selecionado; ?>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($periodos as $periodo): ?>
                <div class="col-md-4">
                    <div class="periodo-card">
                        <strong><?php echo htmlspecialchars($periodo['nome']); ?></strong>
                        <div class="small">
                            <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($periodo['data_inicio'])); ?> 
                            até <?php echo date('d/m/Y', strtotime($periodo['data_fim'])); ?>
                        </div>
                        <div class="small text-muted mt-1">
                            <?php echo htmlspecialchars($periodo['descricao'] ?? ''); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Calendário -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Calendário - <?php echo date('F', mktime(0,0,0,$mes_selecionado,1)); ?> de <?php echo $ano_selecionado; ?>
        </div>
        <div class="card-body p-0">
            <div class="calendario">
                <div class="calendario-dias">
                    <?php foreach ($dias_semana as $dia): ?>
                    <div class="calendario-dia-semana"><?php echo $dia; ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="calendario-grid">
                    <?php
                    // Dias do mês anterior
                    $dia_atual = 1;
                    for ($i = 0; $i < $primeiro_dia; $i++) {
                        $dia_anterior = date('d', strtotime("-$i days", strtotime("$ano_selecionado-$mes_selecionado-01")));
                        echo '<div class="calendario-cell outro-mes">';
                        echo '<div class="dia-numero">' . $dia_anterior . '</div>';
                        echo '</div>';
                    }
                    
                    // Dias do mês atual
                    $hoje = date('Y-m-d');
                    for ($dia = 1; $dia <= $dias_no_mes; $dia++) {
                        $data_atual = sprintf("%04d-%02d-%02d", $ano_selecionado, $mes_selecionado, $dia);
                        $is_hoje = ($data_atual == $hoje);
                        $eventos_dia = [];
                        
                        // Buscar eventos do dia
                        foreach ($eventos as $e) {
                            if (date('Y-m-d', strtotime($e['data_evento'])) == $data_atual) {
                                $eventos_dia[] = ['tipo' => $e['tipo'], 'titulo' => $e['titulo'], 'id' => $e['id']];
                            }
                        }
                        foreach ($provas as $p) {
                            if (date('Y-m-d', strtotime($p['data_prova'])) == $data_atual) {
                                $eventos_dia[] = ['tipo' => 'prova', 'titulo' => $p['disciplina_nome'] . ' - Prova', 'id' => $p['id']];
                            }
                        }
                        foreach ($trabalhos as $t) {
                            if (date('Y-m-d', strtotime($t['data_entrega'])) == $data_atual) {
                                $eventos_dia[] = ['tipo' => 'trabalho', 'titulo' => $t['disciplina_nome'] . ' - ' . $t['titulo'], 'id' => $t['id']];
                            }
                        }
                        foreach ($feriados as $f) {
                            if ($f['data'] == $data_atual) {
                                $eventos_dia[] = ['tipo' => 'feriado', 'titulo' => $f['nome'], 'id' => $f['id']];
                            }
                        }
                        
                        echo '<div class="calendario-cell ' . ($is_hoje ? 'hoje' : '') . '">';
                        echo '<div class="dia-numero">' . $dia . '</div>';
                        
                        foreach ($eventos_dia as $evento) {
                            $cor = getCorEventoCalendario($evento['tipo']);
                            echo '<div class="evento-item-calendario" style="background: ' . $cor . '; color: white;" title="' . htmlspecialchars($evento['titulo']) . '">';
                            echo getIconeEventoCalendario($evento['tipo']) . ' ' . htmlspecialchars(substr($evento['titulo'], 0, 20));
                            echo '</div>';
                        }
                        
                        echo '</div>';
                    }
                    
                    // Dias do mês seguinte
                    $dias_restantes = 42 - ($primeiro_dia + $dias_no_mes);
                    for ($i = 1; $i <= $dias_restantes; $i++) {
                        echo '<div class="calendario-cell outro-mes">';
                        echo '<div class="dia-numero">' . $i . '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Lista de Eventos do Mês -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-list"></i> Eventos - <?php echo date('F', mktime(0,0,0,$mes_selecionado,1)); ?> de <?php echo $ano_selecionado; ?>
        </div>
        <div class="card-body">
            <?php if (empty($eventos) && empty($provas) && empty($trabalhos) && empty($feriados)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhum evento programado para este mês.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <!-- Provas -->
                    <?php if (!empty($provas)): ?>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-pen-alt text-danger"></i> Provas</h6>
                        <div class="lista-eventos">
                            <?php foreach ($provas as $prova): ?>
                            <div class="evento-card" style="border-left-color: #dc3545;">
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo htmlspecialchars($prova['disciplina_nome']); ?></strong>
                                    <span class="badge bg-danger">Prova</span>
                                </div>
                                <div class="evento-data">
                                    <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?>
                                    <?php if ($prova['hora_inicio']): ?>
                                    | <i class="fas fa-clock"></i> <?php echo $prova['hora_inicio']."-".$prova['hora_fim']; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="evento-tipo mt-1">
                                    <small><?php echo htmlspecialchars($prova['conteudo'] ?? ''); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Trabalhos -->
                    <?php if (!empty($trabalhos)): ?>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-file-alt text-warning"></i> Trabalhos</h6>
                        <div class="lista-eventos">
                            <?php foreach ($trabalhos as $trabalho): ?>
                            <div class="evento-card" style="border-left-color: #ffc107;">
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo htmlspecialchars($trabalho['disciplina_nome']); ?></strong>
                                    <span class="badge bg-warning text-dark">Trabalho</span>
                                </div>
                                <div class="evento-data">
                                    <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($trabalho['data_entrega'])); ?>
                                    | <i class="fas fa-clock"></i> Entrega
                                </div>
                                <div class="evento-tipo mt-1">
                                    <small><strong><?php echo htmlspecialchars($trabalho['titulo']); ?></strong></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Eventos -->
                    <?php if (!empty($eventos)): ?>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-calendar-day text-info"></i> Eventos</h6>
                        <div class="lista-eventos">
                            <?php foreach ($eventos as $evento): ?>
                            <div class="evento-card" style="border-left-color: #17a2b8;">
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo htmlspecialchars($evento['titulo']); ?></strong>
                                    <span class="badge bg-info">Evento</span>
                                </div>
                                <div class="evento-data">
                                    <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?>
                                    <?php if ($evento['hora_inicio']): ?>
                                    | <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($evento['hora_inicio'])); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="evento-tipo mt-1">
                                    <small><?php echo htmlspecialchars($evento['descricao']); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Feriados -->
                    <?php if (!empty($feriados)): ?>
                    <div class="col-md-6">
                        <h6 class="mb-3"><i class="fas fa-umbrella-beach text-secondary"></i> Feriados</h6>
                        <div class="lista-eventos">
                            <?php foreach ($feriados as $feriado): ?>
                            <div class="evento-card" style="border-left-color: #6c757d;">
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo htmlspecialchars($feriado['nome']); ?></strong>
                                    <span class="badge bg-secondary">Feriado</span>
                                </div>
                                <div class="evento-data">
                                    <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($feriado['data'])); ?>
                                </div>
                                <div class="evento-tipo mt-1">
                                    <small><?php echo htmlspecialchars($feriado['descricao'] ?? ''); ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-info-circle"></i> Legenda
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #dc3545;"></span>
                        <span>Provas</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #ffc107;"></span>
                        <span>Trabalhos</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #17a2b8;"></span>
                        <span>Eventos</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #6c757d;"></span>
                        <span>Feriados</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #28a745;"></span>
                        <span>Períodos Letivos</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="legenda-cor" style="background: #e8f5e9;"></span>
                        <span>Hoje</span>
                    </div>
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
    
    btnAjuda.addEventListener('click', function() {
        modalAjuda.classList.add('show');
    });
    
    closeAjuda.addEventListener('click', function() {
        modalAjuda.classList.remove('show');
    });
    
    modalAjuda.addEventListener('click', function(e) {
        if (e.target === modalAjuda) {
            modalAjuda.classList.remove('show');
        }
    });
    
    // Tooltip para eventos do calendário
    document.querySelectorAll('.evento-item-calendario').forEach(el => {
        el.addEventListener('mouseenter', function(e) {
            // Pode ser implementado um tooltip mais elaborado
        });
    });
</script>

</body>
</html>