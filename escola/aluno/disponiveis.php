<?php
// escola/aluno/provas/disponiveis.php - Provas Disponíveis para o Aluno

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
$titulo_pagina = 'Provas Disponíveis';

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
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR PROVAS DISPONÍVEIS PARA O ALUNO
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.escola_id,
                    p.disciplina_id,
                    p.turma_id,
                    p.professor_id,
                    p.titulo,
                    p.descricao,
                    p.instrucoes,
                    p.tipo,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    d.nome as disciplina_nome,
                    prof.nome as professor_nome,
                    (SELECT COUNT(*) FROM online_provas_tentativas 
                     WHERE prova_id = p.id AND aluno_id = :aluno_id AND status = 'finalizada') as tentativas_realizadas,
                    (SELECT MAX(pontuacao_total) FROM online_provas_tentativas 
                     WHERE prova_id = p.id AND aluno_id = :aluno_id1 AND status = 'finalizada') as melhor_nota,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    CASE 
                        WHEN NOW() < p.data_inicio THEN 'pendente'
                        WHEN NOW() BETWEEN p.data_inicio AND p.data_fim THEN 'disponivel'
                        ELSE 'encerrada'
                    END as status_aluno,
                    CASE 
                        WHEN (SELECT COUNT(*) FROM online_provas_tentativas 
                              WHERE prova_id = p.id AND aluno_id = :aluno_id2 AND status = 'finalizada') >= p.tentativas_permitidas THEN 'limite_atingido'
                        WHEN NOW() > p.data_fim THEN 'expirada'
                        WHEN NOW() < p.data_inicio THEN 'aguarde'
                        ELSE 'liberada'
                    END as acao_permitida
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN funcionarios prof ON prof.id = p.professor_id
                WHERE p.escola_id = :escola_id
                AND (p.status = 'publicada' or p.status = 'agendada')
                AND p.turma_id = :turma_id
                AND p.data_fim >= NOW()";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR p.descricao LIKE :busca)";
}

$sql_provas .= " ORDER BY p.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':aluno_id' => $aluno_id,
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id
];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.escola_id = :escola_id 
                    AND p.turma_id = :turma_id
                AND (p.status = 'publicada' or p.status = 'agendada')
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id, ':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$provas_disponiveis = 0;
$provas_realizadas = 0;
$provas_pendentes = 0;

foreach ($provas as $prova) {
    if ($prova['status_aluno'] == 'disponivel') {
        $provas_disponiveis++;
    } elseif ($prova['tentativas_realizadas'] > 0) {
        $provas_realizadas++;
    } else {
        $provas_pendentes++;
    }
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusProvaBadge($status, $data_inicio, $data_fim) {
    if ($status == 'pendente') {
        return '<span class="badge bg-secondary"><i class="fas fa-clock"></i> Em breve</span>';
    } elseif ($status == 'disponivel') {
        return '<span class="badge bg-success"><i class="fas fa-play-circle"></i> Disponível</span>';
    } elseif ($status == 'encerrada') {
        return '<span class="badge bg-danger"><i class="fas fa-lock"></i> Encerrada</span>';
    }
    return '<span class="badge bg-secondary">' . $status . '</span>';
}

function getAcaoBotao($acao_permitida, $id) {
    if ($acao_permitida == 'liberada') {
        return '<a href="realizar_prova.php?id=' . $id . '" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Iniciar Prova</a>';
    } elseif ($acao_permitida == 'limite_atingido') {
        return '<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-ban"></i> Limite Atingido</button>';
    } elseif ($acao_permitida == 'expirada') {
        return '<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-hourglass-end"></i> Expirada</button>';
    } elseif ($acao_permitida == 'aguarde') {
        return '<button class="btn btn-warning btn-sm" disabled><i class="fas fa-hourglass-half"></i> Aguarde</button>';
    }
    return '<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-times"></i> Indisponível</button>';
}

function formatarDataProva($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function calcularTempoRestante($data_fim) {
    $agora = new DateTime();
    $fim = new DateTime($data_fim);
    if ($agora > $fim) {
        return '<span class="text-danger">Expirada</span>';
    }
    $diferenca = $agora->diff($fim);
    if ($diferenca->days > 0) {
        return '<span class="text-success">' . $diferenca->days . ' dias restantes</span>';
    } elseif ($diferenca->h > 0) {
        return '<span class="text-warning">' . $diferenca->h . ' horas restantes</span>';
    } else {
        return '<span class="text-danger">' . $diferenca->i . ' minutos restantes</span>';
    }
}

function getTentativasRestantes($permitidas, $realizadas) {
    $restantes = $permitidas - $realizadas;
    if ($restantes <= 0) {
        return '<span class="text-danger">Esgotadas</span>';
    }
    return '<span class="text-success">' . $restantes . ' restante(s)</span>';
}

function getCorPorcentagem($porcentagem) {
    if ($porcentagem >= 70) return 'text-success';
    if ($porcentagem >= 50) return 'text-warning';
    return 'text-danger';
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
        
        .prova-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .prova-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .prova-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .prova-body {
            padding: 20px;
        }
        .prova-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .disciplina-badge {
            background: #e8f5e9;
            color: #006B3E;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #555;
        }
        
        .nota-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
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
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno { display: none; }
        }
    </style>
</head>
<body>

 <?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Provas Disponíveis</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todas as provas online disponíveis para você realizar.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status das Provas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Disponível</span> - Prova liberada para realização<br>
                    <span class="badge bg-secondary">Em breve</span> - Prova agendada para futuro<br>
                    <span class="badge bg-danger">Encerrada</span> - Prazo já expirou
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Tentativas</div>
                <div class="ajuda-texto">Algumas provas permitem mais de uma tentativa. Fique atento ao limite!</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Pontuação</div>
                <div class="ajuda-texto">A melhor nota entre todas as suas tentativas será registrada no histórico.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-alt"></i> Provas Disponíveis</h4>
            <p class="text-muted mb-0">Selecione uma prova para iniciar</p>
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
                    <small class="text-muted"><i class="fas fa-file-alt"></i> Total Provas</small>
                    <h6 class="mb-0"><?php echo $total_provas; ?></h6>
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
                <div class="stat-value text-primary"><?php echo $provas_realizadas; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle text-primary"></i> Provas Realizadas</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $provas_pendentes; ?></div>
                <div class="stat-label"><i class="fas fa-clock text-warning"></i> Provas Pendentes</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="disponiveis.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Provas -->
    <?php if (empty($provas)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma prova disponível</h5>
            <p>Não foram encontradas provas disponíveis para você no momento.</p>
        </div>
    <?php else: ?>
        <div class="provas-list">
            <?php foreach ($provas as $prova): 
                $tempo_restante = calcularTempoRestante($prova['data_fim']);
                $status_badge = getStatusProvaBadge($prova['status_aluno'], $prova['data_inicio'], $prova['data_fim']);
                $botao_acao = getAcaoBotao($prova['acao_permitida'], $prova['id']);
                $tentativas_restantes = getTentativasRestantes($prova['tentativas_permitidas'], $prova['tentativas_realizadas']);
                
                $porcentagem_melhor_nota = $prova['melhor_nota'] ? ($prova['melhor_nota'] / $prova['nota_maxima']) * 100 : 0;
            ?>
            <div class="prova-card fade-in">
                <div class="prova-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2"><?php echo $status_badge; ?></span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Professor: <?php echo htmlspecialchars($prova['professor_nome']); ?></small>
                    </div>
                </div>
                
                <div class="prova-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'], 0, 150))) . (strlen($prova['descricao']) > 150 ? '...' : ''); ?></p>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-clock text-primary"></i>
                                        <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-question-circle text-primary"></i>
                                        <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-star text-warning"></i>
                                        <span>Nota Máxima: <strong><?php echo $prova['nota_maxima']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-repeat text-info"></i>
                                        <span>Tentativas: <strong><?php echo $prova['tentativas_permitidas']; ?></strong> (<?php echo $prova['tentativas_realizadas']; ?> realizadas)</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt text-success"></i>
                                        <span>Início: <strong><?php echo formatarDataProva($prova['data_inicio']); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-times text-danger"></i>
                                        <span>Término: <strong><?php echo formatarDataProva($prova['data_fim']); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="info-item">
                                        <i class="fas fa-hourglass-half"></i>
                                        <span>Prazo: <?php echo $tempo_restante; ?></span>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="info-item">
                                        <i class="fas fa-ticket-alt"></i>
                                        <span>Tentativas restantes: <?php echo $tentativas_restantes; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($prova['instrucoes']): ?>
                            <div class="mt-3 p-2 bg-light rounded">
                                <small><i class="fas fa-info-circle"></i> <strong>Instruções:</strong> <?php echo nl2br(htmlspecialchars(substr($prova['instrucoes'], 0, 100))); ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <?php if ($prova['melhor_nota'] !== null): ?>
                            <div class="mb-3">
                                <div class="nota-circle mx-auto <?php echo getCorPorcentagem($porcentagem_melhor_nota); ?> border" 
                                     style="border-color: <?php echo $porcentagem_melhor_nota >= 70 ? '#28a745' : ($porcentagem_melhor_nota >= 50 ? '#ffc107' : '#dc3545'); ?> !important;">
                                    <?php echo number_format($prova['melhor_nota'], 1); ?>
                                </div>
                                <small class="text-muted">Melhor nota</small>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar <?php echo $porcentagem_melhor_nota >= 70 ? 'bg-success' : ($porcentagem_melhor_nota >= 50 ? 'bg-warning' : 'bg-danger'); ?>" 
                                         role="progressbar" 
                                         style="width: <?php echo $porcentagem_melhor_nota; ?>%;"></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <div class="nota-circle mx-auto bg-light text-secondary border">
                                    <i class="fas fa-question"></i>
                                </div>
                                <small class="text-muted">Nenhuma tentativa</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="prova-footer">
                    <div>
                        <?php if ($prova['acao_permitida'] == 'liberada'): ?>
                            <span class="text-success"><i class="fas fa-check-circle"></i> Pronta para iniciar</span>
                        <?php elseif ($prova['acao_permitida'] == 'aguarde'): ?>
                            <span class="text-warning"><i class="fas fa-clock"></i> Aguardando data de início</span>
                        <?php elseif ($prova['acao_permitida'] == 'expirada'): ?>
                            <span class="text-danger"><i class="fas fa-times-circle"></i> Prazo encerrado</span>
                        <?php elseif ($prova['acao_permitida'] == 'limite_atingido'): ?>
                            <span class="text-danger"><i class="fas fa-ban"></i> Limite de tentativas atingido</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php echo $botao_acao; ?>
                        <a href="detalhes_prova.php?id=<?php echo $prova['id']; ?>" class="btn btn-outline-secondary btn-sm ms-2">
                            <i class="fas fa-info-circle"></i> Detalhes
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Legenda -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold"><i class="fas fa-info-circle"></i> Legenda</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Disponível</strong> - Pode iniciar a prova</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Em breve</strong> - Prova agendada</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-danger">&nbsp;&nbsp;&nbsp;&nbsp;</span>
                        <span><strong>Encerrada</strong> - Prazo expirado</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-star text-warning"></i>
                        <span><strong>Melhor nota</strong> - Sua melhor pontuação</span>
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
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>