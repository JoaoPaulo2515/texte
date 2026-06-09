<?php
// escola/aluno/provas/calendario_provas.php - Calendário de Provas

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
$titulo_pagina = 'Calendário de Provas';

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
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : date('n');
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;

// ==============================================
// BUSCAR PROVAS DO MÊS/ANO SELECIONADO
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.data_inicio,
                    p.data_fim,
                    p.duracao_minutos,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.tentativas_permitidas,
                    d.id as disciplina_id,
                    d.nome as disciplina_nome,
                    d.cor as disciplina_cor,
                    prof.nome as professor_nome,
                    CASE 
                        WHEN NOW() < p.data_inicio THEN 'pendente'
                        WHEN NOW() BETWEEN p.data_inicio AND p.data_fim THEN 'disponivel'
                        ELSE 'encerrada'
                    END as status,
                    (SELECT COUNT(*) FROM online_provas_tentativas 
                     WHERE prova_id = p.id AND aluno_id = :aluno_id1 AND status = 'finalizada') as tentativas_realizadas
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN funcionarios prof ON prof.id = p.professor_id
                WHERE p.escola_id = :escola_id
                AND p.status = 'agendada'
                AND p.turma_id = :turma_id
                AND MONTH(p.data_inicio) = :mes
                AND YEAR(p.data_inicio) = :ano";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}

$sql_provas .= " ORDER BY p.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':aluno_id1' => $aluno_id,
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id,
    ':mes' => $mes_filtro,
    ':ano' => $ano_filtro
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
$stmt_provas->execute($params);
$provas_mes = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR PROVAS DO MÊS SEGUINTE (PARA VISUALIZAÇÃO)
// ==============================================
$mes_seguinte = $mes_filtro == 12 ? 1 : $mes_filtro + 1;
$ano_seguinte = $mes_filtro == 12 ? $ano_filtro + 1 : $ano_filtro;
$mes_anterior = $mes_filtro == 1 ? 12 : $mes_filtro - 1;
$ano_anterior = $mes_filtro == 1 ? $ano_filtro - 1 : $ano_filtro;

$sql_prox_mes = "SELECT COUNT(*) as total FROM online_provas p
                 WHERE p.escola_id = :escola_id
                 AND p.status = 'agendada'
                 AND p.turma_id = :turma_id
                 AND MONTH(p.data_inicio) = :mes
                 AND YEAR(p.data_inicio) = :ano";
$stmt_prox_mes = $conn->prepare($sql_prox_mes);
$stmt_prox_mes->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id,
    ':mes' => $mes_seguinte,
    ':ano' => $ano_seguinte
]);
$total_prox_mes = $stmt_prox_mes->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt_mes_anterior = $conn->prepare($sql_prox_mes);
$stmt_mes_anterior->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id,
    ':mes' => $mes_anterior,
    ':ano' => $ano_anterior
]);
$total_mes_anterior = $stmt_mes_anterior->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.cor 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.escola_id = :escola_id 
                    AND p.turma_id = :turma_id
                    AND p.status = 'agendada'
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id, ':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS DO MÊS
// ==============================================
$total_provas_mes = count($provas_mes);
$provas_disponiveis = 0;
$provas_pendentes = 0;

foreach ($provas_mes as $prova) {
    if ($prova['status'] == 'disponivel') {
        $provas_disponiveis++;
    } else {
        $provas_pendentes++;
    }
}

// ==============================================
// DIAS DO MÊS PARA CALENDÁRIO
// ==============================================
$primeiro_dia = mktime(0, 0, 0, $mes_filtro, 1, $ano_filtro);
$dias_no_mes = date('t', $primeiro_dia);
$primeira_semana = date('w', $primeiro_dia);
$primeira_semana = ($primeira_semana == 0) ? 6 : $primeira_semana - 1; // Ajustar para segunda como primeiro dia

// Agrupar provas por dia
$provas_por_dia = [];
foreach ($provas_mes as $prova) {
    $dia = (int)date('j', strtotime($prova['data_inicio']));
    if (!isset($provas_por_dia[$dia])) {
        $provas_por_dia[$dia] = [];
    }
    $provas_por_dia[$dia][] = $prova;
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusCalendarioBadge($status) {
    if ($status == 'disponivel') {
        return '<span class="badge bg-success" style="font-size: 0.7rem;"><i class="fas fa-play-circle"></i> Disponível</span>';
    } elseif ($status == 'pendente') {
        return '<span class="badge bg-secondary" style="font-size: 0.7rem;"><i class="fas fa-clock"></i> Em breve</span>';
    }
    return '<span class="badge bg-danger" style="font-size: 0.7rem;">Encerrada</span>';
}

function getCorDisciplina($cor) {
    return !empty($cor) ? $cor : '#006B3E';
}

function getNomeMes($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes];
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
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        /* Calendário */
        .calendario-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .calendario-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .calendario-header h4 {
            margin: 0;
        }
        .nav-mes {
            display: flex;
            gap: 10px;
        }
        .nav-mes-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .nav-mes-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        .calendario-semana {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .dia-semana {
            padding: 12px;
            text-align: center;
            font-weight: bold;
            color: #555;
            border-right: 1px solid #e0e0e0;
        }
        .dia-semana:last-child {
            border-right: none;
        }
        .calendario-dias {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .dia {
            min-height: 100px;
            border-right: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
            padding: 8px;
            transition: all 0.3s;
            background: white;
        }
        .dia:nth-child(7n) {
            border-right: none;
        }
        .dia:hover {
            background: #f0f7ff;
        }
        .dia-outro-mes {
            background: #fafafa;
            color: #ccc;
        }
        .dia-atual {
            background: #e8f5e9;
        }
        .dia-numero {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .evento-prova {
            background: #006B3E;
            color: white;
            padding: 3px 6px;
            border-radius: 5px;
            font-size: 0.7rem;
            margin-bottom: 3px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .evento-prova:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        .evento-prova.disponivel {
            background: #28a745;
        }
        .evento-prova.pendente {
            background: #6c757d;
        }
        .evento-prova.encerrada {
            background: #dc3545;
        }
        .mais-eventos {
            font-size: 0.65rem;
            color: #006B3E;
            cursor: pointer;
            text-align: center;
            margin-top: 3px;
        }
        
        .lista-provas-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid;
            transition: all 0.3s;
        }
        .lista-provas-card:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno, .nav-mes { display: none; }
        }
        
        @media (max-width: 768px) {
            .dia { min-height: 80px; font-size: 0.8rem; }
            .evento-prova { font-size: 0.6rem; white-space: normal; }
        }
        
        .modal-prova {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-prova.show {
            display: flex;
        }
        .modal-prova-content {
            background: white;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-prova-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-prova-body {
            padding: 20px;
        }
        .modal-prova-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .btn-iniciar-prova {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Calendário de Provas</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Visualize todas as suas provas agendadas em formato de calendário.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Cores das Provas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Verde</span> - Prova disponível para realização<br>
                    <span class="badge bg-secondary">Cinza</span> - Prova agendada (em breve)<br>
                    <span class="badge bg-danger">Vermelho</span> - Prova encerrada
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Navegação</div>
                <div class="ajuda-texto">Use os botões "Anterior" e "Próximo" para navegar entre os meses.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Detalhes</div>
                <div class="ajuda-texto">Clique em qualquer prova para ver mais detalhes e iniciar.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-calendar-alt"></i> Calendário de Provas</h4>
            <p class="text-muted mb-0">Visualize todas as suas provas agendadas</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
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
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-calendar"></i> Provas no mês</small>
                    <h6 class="mb-0"><?php echo $total_provas_mes; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $provas_disponiveis; ?></div>
                <div class="stat-label"><i class="fas fa-play-circle text-success"></i> Provas Disponíveis</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?php echo $provas_pendentes; ?></div>
                <div class="stat-label"><i class="fas fa-clock text-secondary"></i> Provas Pendentes</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_provas_mes; ?></div>
                <div class="stat-label"><i class="fas fa-calendar-alt text-primary"></i> Total no Mês</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="formFiltros">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="document.getElementById('formFiltros').submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Mês</label>
                    <select name="mes" class="form-select" onchange="document.getElementById('formFiltros').submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $mes_filtro == $m ? 'selected' : ''; ?>>
                            <?php echo getNomeMes($m); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="document.getElementById('formFiltros').submit()">
                        <?php for ($a = date('Y') - 1; $a <= date('Y') + 1; $a++): ?>
                        <option value="<?php echo $a; ?>" <?php echo $ano_filtro == $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Calendário -->
    <div class="calendario-container fade-in">
        <div class="calendario-header">
            <div>
                <h4><i class="fas fa-calendar-alt"></i> <?php echo getNomeMes($mes_filtro); ?> / <?php echo $ano_filtro; ?></h4>
                <?php if ($total_mes_anterior > 0 || $total_prox_mes > 0): ?>
                <small>Mês anterior: <?php echo $total_mes_anterior; ?> prova(s) | Próximo mês: <?php echo $total_prox_mes; ?> prova(s)</small>
                <?php endif; ?>
            </div>
            <div class="nav-mes">
                <a href="?mes=<?php echo $mes_anterior; ?>&ano=<?php echo $ano_anterior; ?>&disciplina_id=<?php echo $disciplina_filtro; ?>" class="nav-mes-btn">
                    <i class="fas fa-chevron-left"></i> Anterior
                </a>
                <a href="?mes=<?php echo $mes_seguinte; ?>&ano=<?php echo $ano_seguinte; ?>&disciplina_id=<?php echo $disciplina_filtro; ?>" class="nav-mes-btn">
                    Próximo <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        
        <div class="calendario-semana">
            <div class="dia-semana">Segunda</div>
            <div class="dia-semana">Terça</div>
            <div class="dia-semana">Quarta</div>
            <div class="dia-semana">Quinta</div>
            <div class="dia-semana">Sexta</div>
            <div class="dia-semana">Sábado</div>
            <div class="dia-semana">Domingo</div>
        </div>
        
        <div class="calendario-dias">
            <?php
            $dia_atual = date('j');
            $mes_atual = date('n');
            $ano_atual = date('Y');
            $dia_num = 1;
            
            // Dias vazios no início
            for ($i = 0; $i < $primeira_semana; $i++) {
                echo '<div class="dia dia-outro-mes"></div>';
            }
            
            // Dias do mês
            for ($d = 1; $d <= $dias_no_mes; $d++) {
                $is_hoje = ($d == $dia_atual && $mes_filtro == $mes_atual && $ano_filtro == $ano_atual);
                $classe_hoje = $is_hoje ? 'dia-atual' : '';
                $eventos = isset($provas_por_dia[$d]) ? $provas_por_dia[$d] : [];
                $total_eventos = count($eventos);
                $mostrar = array_slice($eventos, 0, 2);
                $tem_mais = $total_eventos > 2;
                
                echo '<div class="dia ' . $classe_hoje . '">';
                echo '<div class="dia-numero">' . $d . ($is_hoje ? ' <span class="badge bg-primary" style="font-size: 0.6rem;">Hoje</span>' : '') . '</div>';
                
                foreach ($mostrar as $evento) {
                    $status_class = $evento['status'];
                    $cor_disciplina = getCorDisciplina($evento['disciplina_cor']);
                    echo '<div class="evento-prova ' . $status_class . '" style="background: ' . $cor_disciplina . ';" onclick="verDetalhesProva(' . $evento['id'] . ', \'' . addslashes($evento['titulo']) . '\', \'' . addslashes($evento['descricao']) . '\', \'' . $evento['data_inicio'] . '\', \'' . $evento['data_fim'] . '\', ' . $evento['duracao_minutos'] . ', ' . $evento['nota_maxima'] . ', ' . $evento['tentativas_permitidas'] . ', ' . $evento['tentativas_realizadas'] . ', \'' . $evento['status'] . '\', \'' . $evento['disciplina_nome'] . '\', \'' . $evento['professor_nome'] . '\', ' . $evento['id'] . ')">
                        <i class="fas fa-file-alt"></i> ' . htmlspecialchars(substr($evento['titulo'], 0, 20)) . '
                    </div>';
                }
                
                if ($tem_mais) {
                    echo '<div class="mais-eventos" onclick="verMaisEventos(' . $d . ')">+' . ($total_eventos - 2) . ' mais...</div>';
                }
                
                echo '</div>';
                $dia_num++;
            }
            
            // Dias vazios no final
            $dias_restantes = 42 - ($primeira_semana + $dias_no_mes);
            for ($i = 0; $i < $dias_restantes; $i++) {
                echo '<div class="dia dia-outro-mes"></div>';
            }
            ?>
        </div>
    </div>
    
    <!-- Lista de Provas do Mês -->
    <?php if (!empty($provas_mes)): ?>
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-list"></i> Provas de <?php echo getNomeMes($mes_filtro); ?>/<?php echo $ano_filtro; ?>
        </div>
        <div class="card-body">
            <?php foreach ($provas_mes as $prova): 
                $status_class = $prova['status'];
                $tentativas_restantes = $prova['tentativas_permitidas'] - $prova['tentativas_realizadas'];
            ?>
            <div class="lista-provas-card" style="border-left-color: <?php echo getCorDisciplina($prova['disciplina_cor']); ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1"><?php echo htmlspecialchars($prova['titulo']); ?></h6>
                        <small class="text-muted">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?> | 
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($prova['professor_nome']); ?>
                        </small>
                    </div>
                    <div>
                        <?php echo getStatusCalendarioBadge($prova['status']); ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-6">
                        <small><i class="fas fa-calendar-alt"></i> Início: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></small><br>
                        <small><i class="fas fa-calendar-times"></i> Término: <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></small>
                    </div>
                    <div class="col-md-6">
                        <small><i class="fas fa-hourglass-half"></i> Duração: <?php echo $prova['duracao_minutos']; ?> minutos</small><br>
                        <small><i class="fas fa-star"></i> Nota máxima: <?php echo $prova['nota_maxima']; ?></small>
                    </div>
                </div>
                <?php if ($prova['status'] == 'disponivel' && $tentativas_restantes > 0): ?>
                <div class="mt-2">
                    <a href="realizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-play"></i> Iniciar Prova
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes da Prova -->
<div class="modal-prova" id="modalProva">
    <div class="modal-prova-content">
        <div class="modal-prova-header">
            <h5 class="mb-0" id="modalTitulo">Detalhes da Prova</h5>
            <button class="modal-prova-close" onclick="fecharModalProva()">&times;</button>
        </div>
        <div class="modal-prova-body" id="modalConteudo">
            <div id="detalhesProva"></div>
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
    
    // Funções do Modal de Prova
    function verDetalhesProva(id, titulo, descricao, dataInicio, dataFim, duracao, notaMaxima, tentativasPermitidas, tentativasRealizadas, status, disciplina, professor, provaId) {
        const dataInicioFormatada = new Date(dataInicio).toLocaleString('pt-AO');
        const dataFimFormatada = new Date(dataFim).toLocaleString('pt-AO');
        const tentativasRestantes = tentativasPermitidas - tentativasRealizadas;
        
        let statusHtml = '';
        if (status == 'disponivel') {
            statusHtml = '<span class="badge bg-success">Disponível</span>';
        } else if (status == 'pendente') {
            statusHtml = '<span class="badge bg-secondary">Em breve</span>';
        } else {
            statusHtml = '<span class="badge bg-danger">Encerrada</span>';
        }
        
        let botoes = '';
        if (status == 'disponivel' && tentativasRestantes > 0) {
            botoes = '<a href="realizar_prova.php?id=' + provaId + '" class="btn-iniciar-prova mt-3"><i class="fas fa-play"></i> Iniciar Prova</a>';
        } else if (tentativasRestantes <= 0) {
            botoes = '<button class="btn btn-secondary mt-3" disabled><i class="fas fa-ban"></i> Limite de tentativas atingido</button>';
        }
        
        const html = `
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <h6>${titulo}</h6>
                    ${statusHtml}
                </div>
                <p class="text-muted small">${disciplina} | ${professor}</p>
                <p>${descricao || 'Sem descrição'}</p>
                <hr>
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted">📅 Início:</small><br>
                        <strong>${dataInicioFormatada}</strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">⏰ Término:</small><br>
                        <strong>${dataFimFormatada}</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <small class="text-muted">⏱️ Duração:</small><br>
                        <strong>${duracao} minutos</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <small class="text-muted">⭐ Nota máxima:</small><br>
                        <strong>${notaMaxima}</strong>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted">📝 Tentativas:</small><br>
                        <strong>${tentativasRealizadas} / ${tentativasPermitidas} realizadas</strong>
                        <span class="text-${tentativasRestantes > 0 ? 'success' : 'danger'}">(${tentativasRestantes} restantes)</span>
                    </div>
                </div>
                <div class="text-center">
                    ${botoes}
                </div>
            </div>
        `;
        
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-file-alt"></i> ' + titulo;
        document.getElementById('detalhesProva').innerHTML = html;
        document.getElementById('modalProva').classList.add('show');
    }
    
    function fecharModalProva() {
        document.getElementById('modalProva').classList.remove('show');
    }
    
    function verMaisEventos(dia) {
        // Rolar para a seção de lista de provas
        document.querySelector('.lista-provas-card')?.scrollIntoView({ behavior: 'smooth' });
    }
    
    // Fechar modal ao clicar fora
    document.getElementById('modalProva').addEventListener('click', function(e) {
        if (e.target === this) {
            fecharModalProva();
        }
    });
</script>
</body>
</html>