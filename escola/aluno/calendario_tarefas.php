<?php
// aluno/tarefas/calendario_tarefas.php - Calendário de Tarefas do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, e.foto 
              FROM estudantes e 
              WHERE e.id = :id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR REQUISIÇÕES AJAX
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
    $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
    
    // Buscar tarefas do mês
    $sql_tarefas = "SELECT 
                        t.id,
                        t.titulo,
                        t.descricao,
                        t.data_entrega,
                        t.max_pontos,
                        t.material_apoio,
                        d.id as disciplina_id,
                        d.nome as disciplina_nome,
                        d.cor as disciplina_cor,
                        p.nome as professor_nome,
                        r.id as resposta_id,
                        r.status as resposta_status,
                        r.nota,
                        CASE 
                            WHEN r.id IS NOT NULL AND r.status = 'corrigido' THEN 'concluida'
                            WHEN r.id IS NOT NULL THEN 'entregue'
                            WHEN t.data_entrega < NOW() THEN 'atrasada'
                            ELSE 'pendente'
                        END as status_tarefa
                    FROM tarefas t
                    JOIN disciplinas d ON d.id = t.disciplina_id
                    JOIN professores p ON p.id = t.professor_id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                    WHERE m.estudante_id = :aluno_id 
                    AND m.status = 'ativa'
                    AND t.status = 'publicada'
                    AND MONTH(t.data_entrega) = :mes
                    AND YEAR(t.data_entrega) = :ano
                    ORDER BY t.data_entrega ASC";
    
    $stmt_tarefas = $conn->prepare($sql_tarefas);
    $stmt_tarefas->execute([
        ':aluno_id' => $aluno_id,
        ':mes' => $mes,
        ':ano' => $ano
    ]);
    $tarefas = $stmt_tarefas->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar tarefas por dia
    $eventos = [];
    foreach ($tarefas as $tarefa) {
        $data = date('Y-m-d', strtotime($tarefa['data_entrega']));
        if (!isset($eventos[$data])) {
            $eventos[$data] = [];
        }
        
        // Definir cor baseada no status
        $cor = '#006B3E'; // verde padrão
        $textColor = '#ffffff';
        if ($tarefa['status_tarefa'] == 'atrasada') {
            $cor = '#dc3545'; // vermelho
        } elseif ($tarefa['status_tarefa'] == 'entregue') {
            $cor = '#ffc107'; // amarelo
            $textColor = '#000000';
        } elseif ($tarefa['status_tarefa'] == 'concluida') {
            $cor = '#28a745'; // verde
        } elseif ($tarefa['status_tarefa'] == 'pendente') {
            $cor = '#17a2b8'; // azul
        }
        
        $eventos[$data][] = [
            'id' => $tarefa['id'],
            'titulo' => $tarefa['titulo'],
            'descricao' => $tarefa['descricao'],
            'disciplina' => $tarefa['disciplina_nome'],
            'disciplina_cor' => $tarefa['disciplina_cor'] ?? '#006B3E',
            'professor' => $tarefa['professor_nome'],
            'data_entrega' => $tarefa['data_entrega'],
            'max_pontos' => $tarefa['max_pontos'],
            'status' => $tarefa['status_tarefa'],
            'resposta_status' => $tarefa['resposta_status'],
            'nota' => $tarefa['nota'],
            'material_apoio' => $tarefa['material_apoio'],
            'cor' => $cor,
            'textColor' => $textColor
        ];
    }
    
    echo json_encode(['success' => true, 'eventos' => $eventos]);
    exit;
}

// ============================================
// PROCESSAR EXPORTAÇÃO
// ============================================
if (isset($_GET['exportar'])) {
    $formato = $_GET['exportar'];
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
    $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
    
    // Buscar tarefas do mês
    $sql_tarefas = "SELECT 
                        t.titulo,
                        t.descricao,
                        t.data_entrega,
                        t.max_pontos,
                        d.nome as disciplina_nome,
                        p.nome as professor_nome,
                        CASE 
                            WHEN r.id IS NOT NULL AND r.status = 'corrigido' THEN 'Concluída'
                            WHEN r.id IS NOT NULL THEN 'Entregue'
                            WHEN t.data_entrega < NOW() THEN 'Atrasada'
                            ELSE 'Pendente'
                        END as status_tarefa,
                        r.nota
                    FROM tarefas t
                    JOIN disciplinas d ON d.id = t.disciplina_id
                    JOIN professores p ON p.id = t.professor_id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                    WHERE m.estudante_id = :aluno_id 
                    AND m.status = 'ativa'
                    AND t.status = 'publicada'
                    AND MONTH(t.data_entrega) = :mes
                    AND YEAR(t.data_entrega) = :ano
                    ORDER BY t.data_entrega ASC";
    
    $stmt_tarefas = $conn->prepare($sql_tarefas);
    $stmt_tarefas->execute([
        ':aluno_id' => $aluno_id,
        ':mes' => $mes,
        ':ano' => $ano
    ]);
    $tarefas = $stmt_tarefas->fetchAll(PDO::FETCH_ASSOC);
    
    if ($formato == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendario_tarefas_' . $ano . '_' . str_pad($mes, 2, '0', STR_PAD_LEFT) . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Título', 'Disciplina', 'Professor', 'Data Entrega', 'Status', 'Nota', 'Pontuação Máxima']);
        
        foreach ($tarefas as $tarefa) {
            fputcsv($output, [
                $tarefa['titulo'],
                $tarefa['disciplina_nome'],
                $tarefa['professor_nome'],
                date('d/m/Y H:i', strtotime($tarefa['data_entrega'])),
                $tarefa['status_tarefa'],
                $tarefa['nota'] ? number_format($tarefa['nota'], 1) : 'Não avaliada',
                $tarefa['max_pontos']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($formato == 'ical') {
        // Gerar arquivo ICS para Google Calendar/Outlook
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendario_tarefas_' . $ano . '_' . str_pad($mes, 2, '0', STR_PAD_LEFT) . '.ics"');
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//SIGE//Calendário de Tarefas//PT\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        
        foreach ($tarefas as $tarefa) {
            $data_inicio = date('Ymd\THis', strtotime($tarefa['data_entrega'] . ' -2 hours'));
            $data_fim = date('Ymd\THis', strtotime($tarefa['data_entrega']));
            
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . md5($tarefa['titulo'] . $tarefa['data_entrega']) . "@sige.com\r\n";
            $ical .= "DTSTAMP:" . date('Ymd\THis') . "\r\n";
            $ical .= "DTSTART:" . $data_inicio . "\r\n";
            $ical .= "DTEND:" . $data_fim . "\r\n";
            $ical .= "SUMMARY:" . $tarefa['titulo'] . " - " . $tarefa['disciplina_nome'] . "\r\n";
            $ical .= "DESCRIPTION:" . $tarefa['descricao'] . "\\nProfessor: " . $tarefa['professor_nome'] . "\\nStatus: " . $tarefa['status_tarefa'] . "\r\n";
            $ical .= "LOCATION:" . $tarefa['disciplina_nome'] . "\r\n";
            $ical .= "STATUS:CONFIRMED\r\n";
            $ical .= "END:VEVENT\r\n";
        }
        
        $ical .= "END:VCALENDAR\r\n";
        echo $ical;
        exit;
    }
}

// Estatísticas do mês atual
$mes_atual = date('m');
$ano_atual = date('Y');

$sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN r.id IS NOT NULL AND r.status = 'corrigido' THEN 1 ELSE 0 END) as concluidas,
                    SUM(CASE WHEN r.id IS NOT NULL AND r.status != 'corrigido' THEN 1 ELSE 0 END) as entregues,
                    SUM(CASE WHEN r.id IS NULL AND t.data_entrega < NOW() THEN 1 ELSE 0 END) as atrasadas,
                    SUM(CASE WHEN r.id IS NULL AND t.data_entrega >= NOW() THEN 1 ELSE 0 END) as pendentes,
                    AVG(CASE WHEN r.nota IS NOT NULL THEN r.nota END) as media_notas
                FROM tarefas t
                JOIN turmas tur ON tur.id = t.turma_id
                JOIN matriculas m ON m.turma_id = tur.id
                LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                WHERE m.estudante_id = :aluno_id 
                AND m.status = 'ativa'
                AND t.status = 'publicada'
                AND MONTH(t.data_entrega) = :mes
                AND YEAR(t.data_entrega) = :ano";

$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([
    ':aluno_id' => $aluno_id,
    ':mes' => $mes_atual,
    ':ano' => $ano_atual
]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendário de Tarefas | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.8rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .fc-event { cursor: pointer; border-radius: 8px; padding: 2px 4px; font-size: 12px; transition: transform 0.2s; }
        .fc-event:hover { transform: scale(1.02); }
        .fc-day-today { background: #e8f5e9 !important; }
        
        .legenda-item { display: inline-flex; align-items: center; margin-right: 15px; margin-bottom: 5px; }
        .legenda-cor { width: 20px; height: 20px; border-radius: 4px; margin-right: 5px; }
        
        .modal-detalhes { max-width: 600px; }
        .tarefa-status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .status-concluida { background: #d4edda; color: #155724; }
        .status-entregue { background: #fff3cd; color: #856404; }
        .status-pendente { background: #cce5ff; color: #004085; }
        .status-atrasada { background: #f8d7da; color: #721c24; }
        
        .btn-export { transition: all 0.3s; }
        .btn-export:hover { transform: translateY(-2px); }
        
        @media print {
            .no-print { display: none; }
            .fc { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
  <?php include 'includes/menu_aluno.php'; ?>
     </br> </br> </br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-calendar-alt"></i> Calendário de Tarefas</h2>
                <p class="text-muted">Visualize todas as suas tarefas organizadas por data</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <div class="btn-group">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download"></i> Exportar
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="exportarCSV()"><i class="fas fa-file-csv"></i> Exportar CSV</a></li>
                        <li><a class="dropdown-item" href="#" onclick="exportarICS()"><i class="fab fa-google"></i> Exportar para Google Calendar</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-tasks"></i> Total Tarefas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $stats['concluidas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Concluídas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $stats['pendentes'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $stats['atrasadas'] ?? 0; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Atrasadas</div>
                </div>
            </div>
        </div>
        
        <!-- Legenda -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12">
                        <strong><i class="fas fa-info-circle"></i> Legenda:</strong>
                        <div class="legenda-item">
                            <div class="legenda-cor" style="background: #28a745;"></div>
                            <span>Concluída</span>
                        </div>
                        <div class="legenda-item">
                            <div class="legenda-cor" style="background: #ffc107;"></div>
                            <span>Entregue (aguardando correção)</span>
                        </div>
                        <div class="legenda-item">
                            <div class="legenda-cor" style="background: #17a2b8;"></div>
                            <span>Pendente</span>
                        </div>
                        <div class="legenda-item">
                            <div class="legenda-cor" style="background: #dc3545;"></div>
                            <span>Atrasada</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Calendário -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-week"></i> Calendário de Entregas</h5>
            </div>
            <div class="card-body">
                <div id="calendar"></div>
            </div>
        </div>
        
        <!-- Dicas -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Dicas</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <i class="fas fa-mouse-pointer text-primary"></i> Clique nos eventos para ver detalhes
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-download text-success"></i> Exporte para seu calendário pessoal
                    </div>
                    <div class="col-md-4">
                        <i class="fas fa-bell text-warning"></i> Acompanhe os prazos para não atrasar
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalhes da Tarefa -->
    <div class="modal fade" id="tarefaModal" tabindex="-1">
        <div class="modal-dialog modal-detalhes">
            <div class="modal-content">
                <div class="modal-header" id="modalHeader">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="#" id="btnEntregar" class="btn btn-success" style="display: none;">
                        <i class="fas fa-paper-plane"></i> Entregar Tarefa
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/pt-br.js"></script>
    
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        let calendar;
        let eventosCache = {};
        
        // Inicializar calendário
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'pt-br',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,dayGridWeek,listMonth'
                },
                buttonText: {
                    today: 'Hoje',
                    month: 'Mês',
                    week: 'Semana',
                    list: 'Lista'
                },
                events: function(info, successCallback, failureCallback) {
                    let mes = info.start.getMonth() + 1;
                    let ano = info.start.getFullYear();
                    
                    let cacheKey = mes + '_' + ano;
                    if (eventosCache[cacheKey]) {
                        successCallback(eventosCache[cacheKey]);
                        return;
                    }
                    
                    $.ajax({
                        url: window.location.href,
                        method: 'GET',
                        data: {
                            ajax: 1,
                            mes: mes,
                            ano: ano
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                let eventos = [];
                                for (let data in response.eventos) {
                                    for (let evento of response.eventos[data]) {
                                        eventos.push({
                                            id: evento.id,
                                            title: evento.titulo + ' - ' + evento.disciplina,
                                            start: data,
                                            end: data,
                                            backgroundColor: evento.cor,
                                            borderColor: evento.cor,
                                            textColor: evento.textColor,
                                            extendedProps: evento
                                        });
                                    }
                                }
                                eventosCache[cacheKey] = eventos;
                                successCallback(eventos);
                            } else {
                                failureCallback(response);
                            }
                        },
                        error: function() {
                            failureCallback({ message: 'Erro ao carregar eventos' });
                        }
                    });
                },
                eventClick: function(info) {
                    verDetalhesTarefa(info.event.extendedProps);
                },
                height: 'auto',
                aspectRatio: 1.5,
                displayEventTime: false,
                eventDisplay: 'block',
                dayMaxEvents: 3,
                moreLinkText: '+ ver mais'
            });
            
            calendar.render();
        });
        
        // Ver detalhes da tarefa
        function verDetalhesTarefa(tarefa) {
            let statusText = '';
            let statusClass = '';
            let btnEntregar = false;
            let podeEntregar = (tarefa.status == 'pendente' || tarefa.status == 'atrasada');
            
            switch(tarefa.status) {
                case 'concluida':
                    statusText = 'Concluída';
                    statusClass = 'status-concluida';
                    break;
                case 'entregue':
                    statusText = 'Entregue (Aguardando correção)';
                    statusClass = 'status-entregue';
                    break;
                case 'pendente':
                    statusText = 'Pendente';
                    statusClass = 'status-pendente';
                    break;
                case 'atrasada':
                    statusText = 'Atrasada';
                    statusClass = 'status-atrasada';
                    break;
            }
            
            let html = `
                <div class="mb-3">
                    <h4>${tarefa.titulo}</h4>
                    <span class="tarefa-status ${statusClass}">${statusText}</span>
                </div>
                
                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted">Disciplina</small>
                        <p><strong><i class="fas fa-book" style="color: ${tarefa.disciplina_cor}"></i> ${tarefa.disciplina}</strong></p>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Professor</small>
                        <p><strong><i class="fas fa-user-chalk"></i> ${tarefa.professor}</strong></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted">Data de Entrega</small>
                        <p><strong><i class="fas fa-calendar-alt"></i> ${new Date(tarefa.data_entrega).toLocaleString('pt-BR')}</strong></p>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Pontuação Máxima</small>
                        <p><strong><i class="fas fa-star text-warning"></i> ${tarefa.max_pontos} pontos</strong></p>
                    </div>
                </div>
                
                ${tarefa.nota ? `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <strong>Nota obtida:</strong> ${tarefa.nota} / ${tarefa.max_pontos}
                </div>
                ` : ''}
                
                <div class="mb-3">
                    <small class="text-muted">Descrição</small>
                    <p class="border rounded p-2 bg-light">${tarefa.descricao || 'Sem descrição detalhada'}</p>
                </div>
                
                ${tarefa.material_apoio ? `
                <div class="mb-3">
                    <small class="text-muted">Material de Apoio</small>
                    <p><a href="${tarefa.material_apoio}" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-download"></i> Baixar Material
                    </a></p>
                </div>
                ` : ''}
            `;
            
            $('#modalBody').html(html);
            
            if (podeEntregar) {
                $('#btnEntregar').show().attr('href', `entregas_pendentes.php?tarefa=${tarefa.id}`);
            } else {
                $('#btnEntregar').hide();
            }
            
            // Mudar cor do header baseado no status
            let headerColor = '#006B3E';
            if (tarefa.status == 'atrasada') headerColor = '#dc3545';
            else if (tarefa.status == 'entregue') headerColor = '#ffc107';
            else if (tarefa.status == 'concluida') headerColor = '#28a745';
            
            $('#modalHeader').css('background', `linear-gradient(135deg, ${headerColor} 0%, ${headerColor}CC 100%)`);
            
            new bootstrap.Modal(document.getElementById('tarefaModal')).show();
        }
        
        // Exportar CSV
        function exportarCSV() {
            let dataAtual = calendar ? calendar.getDate() : new Date();
            let mes = dataAtual.getMonth() + 1;
            let ano = dataAtual.getFullYear();
            window.location.href = `calendario_tarefas.php?exportar=csv&mes=${mes}&ano=${ano}`;
        }
        
        // Exportar ICS (Google Calendar)
        function exportarICS() {
            let dataAtual = calendar ? calendar.getDate() : new Date();
            let mes = dataAtual.getMonth() + 1;
            let ano = dataAtual.getFullYear();
            window.location.href = `calendario_tarefas.php?exportar=ical&mes=${mes}&ano=${ano}`;
        }
        
        // Atualizar estatísticas ao mudar de mês
        function atualizarEstatisticas() {
            let dataAtual = calendar ? calendar.getDate() : new Date();
            let mes = dataAtual.getMonth() + 1;
            let ano = dataAtual.getFullYear();
            
            $.ajax({
                url: window.location.href,
                method: 'GET',
                data: {
                    ajax: 1,
                    mes: mes,
                    ano: ano
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let total = 0, concluidas = 0, entregues = 0, pendentes = 0, atrasadas = 0;
                        
                        for (let data in response.eventos) {
                            for (let evento of response.eventos[data]) {
                                total++;
                                if (evento.status == 'concluida') concluidas++;
                                else if (evento.status == 'entregue') entregues++;
                                else if (evento.status == 'atrasada') atrasadas++;
                                else if (evento.status == 'pendente') pendentes++;
                            }
                        }
                        
                        // Atualizar cards
                        $('.stat-card').eq(0).find('.stat-value').text(total);
                        $('.stat-card').eq(1).find('.stat-value').text(concluidas);
                        $('.stat-card').eq(2).find('.stat-value').text(pendentes);
                        $('.stat-card').eq(3).find('.stat-value').text(atrasadas);
                    }
                }
            });
        }
        
        // Atualizar ao mudar de mês (se disponível)
        if (typeof calendar !== 'undefined') {
            calendar.setOption('datesSet', function() {
                setTimeout(atualizarEstatisticas, 500);
            });
        }
    </script>
</body>
</html>