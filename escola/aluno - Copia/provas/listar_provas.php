<?php
// escola/aluno/provas/listar_provas.php - Listar Provas Disponíveis para o Aluno

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
// BUSCAR PROVAS DISPONÍVEIS PARA O ALUNO (VERSÃO SIMPLIFICADA)
// ==============================================

// Primeiro, buscar as provas disponíveis
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.instrucoes,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    d.nome as disciplina_nome,
                    d.cor as disciplina_cor
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                WHERE p.escola_id = :escola_id
                AND p.status = 'publicada'
                AND p.turma_id = :turma_id";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR d.nome LIKE :busca)";
}

$sql_provas .= " ORDER BY p.data_inicio ASC";

$stmt_provas = $conn->prepare($sql_provas);

$params = [
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

// Agora, para cada prova, buscar informações adicionais separadamente
foreach ($provas as $key => $prova) {
    // Buscar total de questões
    $sql_questoes = "SELECT COUNT(*) as total FROM online_provas_questoes WHERE prova_id = :prova_id";
    $stmt_questoes = $conn->prepare($sql_questoes);
    $stmt_questoes->execute([':prova_id' => $prova['id']]);
    $total_questoes = $stmt_questoes->fetch(PDO::FETCH_ASSOC);
    $provas[$key]['total_questoes'] = $total_questoes['total'] ?? 0;
    
    // Buscar tentativas realizadas
    $sql_tentativas = "SELECT COUNT(*) as total FROM online_provas_tentativas WHERE prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'finalizada'";
    $stmt_tentativas = $conn->prepare($sql_tentativas);
    $stmt_tentativas->execute([
        ':prova_id' => $prova['id'],
        ':aluno_id' => $aluno_id
    ]);
    $tentativas = $stmt_tentativas->fetch(PDO::FETCH_ASSOC);
    $provas[$key]['tentativas_realizadas'] = $tentativas['total'] ?? 0;
    
    // Buscar melhor nota
    $sql_melhor_nota = "SELECT MAX(pontuacao_total) as melhor FROM online_provas_tentativas WHERE prova_id = :prova_id AND aluno_id = :aluno_id AND status = 'finalizada'";
    $stmt_melhor_nota = $conn->prepare($sql_melhor_nota);
    $stmt_melhor_nota->execute([
        ':prova_id' => $prova['id'],
        ':aluno_id' => $aluno_id
    ]);
    $melhor_nota = $stmt_melhor_nota->fetch(PDO::FETCH_ASSOC);
    $provas[$key]['melhor_nota'] = $melhor_nota['melhor'] ?? null;
    
    // Determinar status da prova para o aluno
    $agora = new DateTime();
    $data_inicio = new DateTime($prova['data_inicio']);
    $data_fim = new DateTime($prova['data_fim']);
    
    if ($agora < $data_inicio) {
        $provas[$key]['status_aluno'] = 'pendente';
    } elseif ($agora >= $data_inicio && $agora <= $data_fim) {
        $provas[$key]['status_aluno'] = 'disponivel';
    } else {
        $provas[$key]['status_aluno'] = 'encerrada';
    }
}

// ==============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN online_provas p ON p.disciplina_id = d.id
                    WHERE p.escola_id = :escola_id 
                    AND p.turma_id = :turma_id
                    AND p.status = 'publicada'
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id
]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$provas_disponiveis = 0;
$provas_agendadas = 0;

foreach ($provas as $prova) {
    if ($prova['status_aluno'] == 'disponivel') {
        $provas_disponiveis++;
    } elseif ($prova['status_aluno'] == 'pendente') {
        $provas_agendadas++;
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
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
        }
        
        .prova-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
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
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        .badge-disponivel { background: #28a745; color: white; }
        .badge-pendente { background: #ffc107; color: #333; }
        .badge-encerrada { background: #6c757d; color: white; }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 8px;
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
        
        .btn-iniciar {
            background: #006B3E;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 500;
        }
        .btn-iniciar:hover {
            background: #004d2e;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

   <?php include '../includes/menu_aluno.php'; ?>
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
                    <span class="badge bg-warning">Em breve</span> - Prova agendada para futuro<br>
                    <span class="badge bg-secondary">Encerrada</span> - Prazo já expirou
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
                <div class="stat-label">Provas Disponíveis</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $provas_agendadas; ?></div>
                <div class="stat-label">Provas Agendadas</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_provas; ?></div>
                <div class="stat-label">Total de Provas</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
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
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="listar_provas.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
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
                $status_class = '';
                $status_texto = '';
                $pode_iniciar = false;
                
                if ($prova['status_aluno'] == 'disponivel') {
                    $status_class = 'badge-disponivel';
                    $status_texto = 'Disponível';
                    $pode_iniciar = true;
                } elseif ($prova['status_aluno'] == 'pendente') {
                    $status_class = 'badge-pendente';
                    $status_texto = 'Em breve';
                    $pode_iniciar = false;
                } else {
                    $status_class = 'badge-encerrada';
                    $status_texto = 'Encerrada';
                    $pode_iniciar = false;
                }
                
                $tentativas_restantes = $prova['tentativas_permitidas'] - ($prova['tentativas_realizadas'] ?? 0);
                $pode_iniciar = $pode_iniciar && $tentativas_restantes > 0;
            ?>
            <div class="prova-card fade-in">
                <div class="prova-header">
                    <div>
                        <span class="disciplina-badge">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                        </span>
                        <span class="ms-2 badge-status <?php echo $status_class; ?>"><?php echo $status_texto; ?></span>
                    </div>
                    <div>
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt"></i> Disponível: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?>
                        </small>
                    </div>
                </div>
                
                <div class="prova-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))) . (strlen($prova['descricao'] ?? '') > 150 ? '...' : ''); ?></p>
                            
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
                                        <span>Tentativas restantes: <strong><?php echo max(0, $tentativas_restantes); ?></strong></span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($prova['melhor_nota'] !== null && $prova['melhor_nota'] > 0): ?>
                            <div class="mt-2">
                                <span class="badge bg-info">Melhor nota: <?php echo number_format($prova['melhor_nota'], 1); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="mb-3">
                                <div class="display-4 text-primary">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                            <?php if ($pode_iniciar): ?>
                                <a href="realizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn-iniciar">
                                    <i class="fas fa-play"></i> Iniciar Prova
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary" disabled>
                                    <i class="fas fa-lock"></i> Indisponível
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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