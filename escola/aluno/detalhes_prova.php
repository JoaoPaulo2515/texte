<?php
// escola/aluno/provas/detalhes_prova.php - Detalhes da Prova Online

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
$titulo_pagina = 'Detalhes da Prova';

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$prova_id) {
    header('Location: disponiveis.php');
    exit;
}

// ==============================================
// BUSCAR DADOS DA PROVA
// ==============================================
$sql_prova = "SELECT p.*, 
                     d.nome as disciplina_nome,
                     d.cor as disciplina_cor,
                     prof.nome as professor_nome,
                     (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                     (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND aluno_id = :aluno_id1 AND status = 'finalizada') as tentativas_realizadas,
                     (SELECT MAX(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND aluno_id = :aluno_id2 AND status = 'finalizada') as melhor_nota,
                     (SELECT AVG(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as media_turma,
                     (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_realizaram
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN funcionarios prof ON prof.id = p.professor_id
              WHERE p.id = :prova_id 
              AND p.escola_id = :escola_id";

$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([
    ':prova_id' => $prova_id,
    ':escola_id' => $escola_id,
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id
]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    header('Location: disponiveis.php');
    exit;
}

// ==============================================
// VERIFICAR SE O ALUNO PODE REALIZAR A PROVA
// ==============================================
$pode_realizar = false;
$mensagem_restricao = '';

$status_aluno = '';
$agora = new DateTime();
$data_inicio = new DateTime($prova['data_inicio']);
$data_fim = new DateTime($prova['data_fim']);

if ($agora < $data_inicio) {
    $status_aluno = 'pendente';
    $mensagem_restricao = 'A prova ainda não está disponível. Início: ' . $data_inicio->format('d/m/Y H:i');
} elseif ($agora > $data_fim) {
    $status_aluno = 'encerrada';
    $mensagem_restricao = 'O prazo para realização desta prova já expirou.';
} else {
    $status_aluno = 'disponivel';
    if ($prova['tentativas_realizadas'] >= $prova['tentativas_permitidas']) {
        $mensagem_restricao = 'Você já utilizou todas as tentativas permitidas para esta prova.';
    } else {
        $pode_realizar = true;
    }
}

// ==============================================
// BUSCAR QUESTÕES DA PROVA
// ==============================================
$sql_questoes = "SELECT q.*,
                        (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = q.id) as total_alternativas
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC, q.id ASC";

$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão
foreach ($questoes as &$questao) {
    $sql_alternativas = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem ASC";
    $stmt_alternativas = $conn->prepare($sql_alternativas);
    $stmt_alternativas->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt_alternativas->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================
// BUSCAR TENTATIVAS ANTERIORES DO ALUNO
// ==============================================
$sql_tentativas = "SELECT t.*,
                          ROUND(t.pontuacao_total, 1) as nota_formatada,
                          DATE_FORMAT(t.data_inicio, '%d/%m/%Y %H:%i') as data_inicio_formatada,
                          DATE_FORMAT(t.data_fim, '%d/%m/%Y %H:%i') as data_fim_formatada,
                          FLOOR(t.tempo_gasto_segundos / 60) as minutos,
                          t.tempo_gasto_segundos % 60 as segundos
                   FROM online_provas_tentativas t
                   WHERE t.prova_id = :prova_id AND t.aluno_id = :aluno_id
                   ORDER BY t.tentativa_numero DESC";

$stmt_tentativas = $conn->prepare($sql_tentativas);
$stmt_tentativas->execute([
    ':prova_id' => $prova_id,
    ':aluno_id' => $aluno_id
]);
$tentativas = $stmt_tentativas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'disponivel':
            return '<span class="badge bg-success"><i class="fas fa-play-circle"></i> Disponível</span>';
        case 'pendente':
            return '<span class="badge bg-secondary"><i class="fas fa-clock"></i> Em breve</span>';
        case 'encerrada':
            return '<span class="badge bg-danger"><i class="fas fa-lock"></i> Encerrada</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoProvaLabel($tipo) {
    $tipos = [
        'prova' => 'Prova Oficial',
        'teste' => 'Teste de Conhecimento',
        'quiz' => 'Quiz Rápido',
        'simulado' => 'Simulado'
    ];
    return $tipos[$tipo] ?? ucfirst($tipo);
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function getNotaClass($nota, $max_nota = 20) {
    if ($nota === null) return 'text-secondary';
    $percentual = ($nota / $max_nota) * 100;
    if ($percentual >= 70) return 'text-success fw-bold';
    if ($percentual >= 50) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

// Função para exibir o enunciado em HTML puro
function exibirEnunciado($enunciado) {
    return $enunciado;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $titulo_pagina; ?> - <?php echo htmlspecialchars($prova['titulo']); ?> | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; }
        
        .main-content-aluno { margin-left: 280px; margin-top: 60px; padding: 30px; min-height: calc(100vh - 60px); }
        @media (max-width: 768px) { .main-content-aluno { margin-left: 0; margin-top: 70px; padding: 20px; } }
        
        .card { border: none; border-radius: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); transition: all 0.3s ease; overflow: hidden; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; padding: 15px 25px; font-weight: 600; }
        .card-header i { margin-right: 10px; }
        
        .info-item { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #555; margin-bottom: 12px; padding: 8px 12px; background: #f8f9fa; border-radius: 12px; }
        .info-item i { width: 25px; color: #006B3E; }
        
        .stat-card { background: white; border-radius: 20px; padding: 25px; text-align: center; box-shadow: 0 2px 15px rgba(0,0,0,0.05); transition: all 0.3s ease; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .stat-value { font-size: 2rem; font-weight: 800; margin-bottom: 5px; }
        .stat-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        
        .questao-card { background: white; border-radius: 16px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e9ecef; overflow: hidden; transition: all 0.3s ease; }
        .questao-card:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .questao-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .questao-body { padding: 20px; }
        
        /* Estilos para o enunciado em HTML */
        .questao-enunciado { font-size: 1rem; line-height: 1.7; color: #2c3e50; margin-bottom: 20px; background: #fafbfc; padding: 20px; border-radius: 16px; border-left: 3px solid #006B3E; }
        .questao-enunciado p { margin-bottom: 12px; }
        .questao-enunciado strong, .questao-enunciado b { font-weight: 700; color: #1A2A6C; }
        .questao-enunciado em, .questao-enunciado i { font-style: italic; }
        .questao-enunciado ul, .questao-enunciado ol { margin: 10px 0; padding-left: 25px; }
        .questao-enunciado table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .questao-enunciado th, .questao-enunciado td { border: 1px solid #ddd; padding: 8px; }
        .questao-enunciado img { max-width: 100%; height: auto; border-radius: 8px; }
        
        .alternativas-container { display: flex; flex-direction: column; gap: 10px; margin-top: 15px; }
        .alternativa-item { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 12px; display: flex; align-items: center; gap: 12px; background: white; transition: all 0.3s ease; }
        .alternativa-item:hover { border-color: #006B3E; background: #f8f9fa; }
        .alternativa-correta { background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%); border-color: #28a745; }
        .alternativa-correta .alternativa-badge { background: #28a745; }
        .alternativa-badge { width: 90px; padding: 5px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; text-align: center; }
        .alternativa-badge-correta { background: #28a745; color: white; }
        .alternativa-badge-normal { background: #6c757d; color: white; }
        .alternativa-texto { flex: 1; font-size: 0.9rem; color: #495057; }
        
        .btn-iniciar { background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; padding: 12px 35px; border-radius: 40px; font-weight: 700; transition: all 0.3s ease; }
        .btn-iniciar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,0.3); }
        
        .tentativa-card { background: #f8f9fa; border-radius: 16px; padding: 15px 20px; margin-bottom: 15px; border-left: 4px solid; transition: all 0.3s ease; }
        .tentativa-card:hover { transform: translateX(5px); }
        .tentativa-aprovada { border-left-color: #28a745; }
        .tentativa-reprovada { border-left-color: #dc3545; }
        
        .progress-bar-custom { height: 8px; background: #e9ecef; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; transition: width 0.3s ease; }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 55px; height: 55px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        
        .modal-ajuda { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .modal-ajuda.show { display: flex; }
        .modal-ajuda-content { background: white; border-radius: 24px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; animation: fadeInUp 0.3s ease; }
        .modal-ajuda-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 15px 20px; border-radius: 24px 24px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-ajuda-body { padding: 20px; }
        .modal-ajuda-close { background: none; border: none; color: white; font-size: 28px; cursor: pointer; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        
        @media (max-width: 768px) { .stat-card { margin-bottom: 15px; } }
        @media print { .btn-ajuda, .btn-iniciar, .menu-aluno { display: none; } }
    </style>
</head>
<body>
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>
    <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="modal-ajuda" id="modalAjuda">
        <div class="modal-ajuda-content">
            <div class="modal-ajuda-header">
                <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Detalhes da Prova</h5>
                <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
            </div>
            <div class="modal-ajuda-body">
                <div class="ajuda-item">
                    <div class="ajuda-titulo">Sobre esta página</div>
                    <div class="ajuda-texto">Esta página exibe todos os detalhes da prova selecionada.</div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">Informações da Prova</div>
                    <div class="ajuda-texto">Aqui você encontra título, descrição, disciplina, professor, datas e duração.</div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">Questões</div>
                    <div class="ajuda-texto">Visualize todas as questões da prova em formato HTML.</div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">Tentativas</div>
                    <div class="ajuda-texto">Histórico das suas tentativas anteriores e resultados.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-content-aluno">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-file-alt"></i> Detalhes da Prova</h4>
                <p class="text-muted mb-0">Informações completas sobre a prova</p>
            </div>
            <div>
                <a href="disponiveis.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Informações da Prova -->
        <div class="card border-0 shadow-sm mb-4 fade-in">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> Informações da Prova
                <span class="ms-2"><?php echo getStatusBadge($status_aluno); ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h4><?php echo htmlspecialchars($prova['titulo']); ?></h4>
                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($prova['descricao'])); ?></p>
                        
                        <?php if (!empty($prova['instrucoes'])): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong>Instruções:</strong><br>
                            <?php echo nl2br(htmlspecialchars($prova['instrucoes'])); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-item"><i class="fas fa-book"></i> <span><strong>Disciplina:</strong> <?php echo htmlspecialchars($prova['disciplina_nome']); ?></span></div>
                                <div class="info-item"><i class="fas fa-chalkboard-user"></i> <span><strong>Professor:</strong> <?php echo htmlspecialchars($prova['professor_nome']); ?></span></div>
                                <div class="info-item"><i class="fas fa-tag"></i> <span><strong>Tipo:</strong> <?php echo getTipoProvaLabel($prova['tipo']); ?></span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item"><i class="fas fa-calendar-alt"></i> <span><strong>Data de Início:</strong> <?php echo formatarData($prova['data_inicio']); ?></span></div>
                                <div class="info-item"><i class="fas fa-calendar-times"></i> <span><strong>Data de Término:</strong> <?php echo formatarData($prova['data_fim']); ?></span></div>
                                <div class="info-item"><i class="fas fa-hourglass-half"></i> <span><strong>Duração:</strong> <?php echo $prova['duracao_minutos']; ?> minutos</span></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="bg-light p-4 rounded-4">
                            <i class="fas fa-question-circle fa-3x text-primary mb-2"></i>
                            <h3 class="mb-2"><?php echo $prova['total_questoes']; ?> Questões</h3>
                            <h4 class="text-warning mb-2"><?php echo $prova['nota_maxima']; ?> pontos</h4>
                            <p>Tentativas: <?php echo $prova['tentativas_realizadas']; ?> / <?php echo $prova['tentativas_permitidas']; ?></p>
                            <?php if ($prova['melhor_nota'] !== null): ?>
                            <span class="badge bg-success">Melhor nota: <?php echo number_format($prova['melhor_nota'], 1); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($pode_realizar): ?>
                <div class="text-center mt-4">
                    <a href="realizar_prova.php?id=<?php echo $prova['id']; ?>" class="btn-iniciar">
                        <i class="fas fa-play"></i> Iniciar Prova Agora
                    </a>
                </div>
                <?php elseif ($mensagem_restricao): ?>
                <div class="alert alert-warning text-center mt-3">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $mensagem_restricao; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Estatísticas da Prova -->
        <div class="row g-3 mb-4 fade-in">
            <div class="col-md-4">
                <div class="stat-card"><div class="stat-value text-primary"><?php echo $prova['total_questoes']; ?></div><div class="stat-label">Total de Questões</div></div>
            </div>
            <div class="col-md-4">
                <div class="stat-card"><div class="stat-value text-success"><?php echo $prova['tentativas_realizadas']; ?> / <?php echo $prova['tentativas_permitidas']; ?></div><div class="stat-label">Tentativas Realizadas</div></div>
            </div>
            <div class="col-md-4">
                <div class="stat-card"><div class="stat-value text-info"><?php echo $prova['total_realizaram']; ?></div><div class="stat-label">Alunos Realizaram</div></div>
            </div>
        </div>
        
        <!-- Lista de Questões -->
        <div class="card border-0 shadow-sm mb-4 fade-in">
            <div class="card-header">
                <i class="fas fa-question-circle"></i> Questões da Prova
                <span class="badge bg-light text-dark ms-2"><?php echo $prova['total_questoes']; ?> questões</span>
            </div>
            <div class="card-body">
                <?php if (empty($questoes)): ?>
                    <div class="alert alert-info text-center py-4">
                        <i class="fas fa-info-circle fa-3x mb-3"></i>
                        <p>Nenhuma questão cadastrada para esta prova.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($questoes as $index => $questao): ?>
                    <div class="questao-card">
                        <div class="questao-header">
                            <div class="d-flex gap-2 align-items-center">
                                <strong><i class="fas fa-hashtag"></i> Questão <?php echo $index + 1; ?></strong>
                                <span class="badge bg-secondary"><?php echo ucfirst(str_replace('_', ' ', $questao['tipo'])); ?></span>
                            </div>
                            <span class="badge bg-primary"><?php echo $questao['pontuacao']; ?> pontos</span>
                        </div>
                        <div class="questao-body">
                            <!-- Exibir enunciado em HTML puro -->
                            <div class="questao-enunciado">
                                <?php echo $questao['enunciado']; ?>
                            </div>
                            
                            <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                                <div class="alternativas-container">
                                    <strong><i class="fas fa-list-ul"></i> Alternativas:</strong>
                                    <?php foreach ($questao['alternativas'] as $alt): ?>
                                    <div class="alternativa-item <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                                        <span class="alternativa-badge <?php echo $alt['correta'] ? 'alternativa-badge-correta' : 'alternativa-badge-normal'; ?>">
                                            <?php echo $alt['correta'] ? '<i class="fas fa-check-circle"></i> Correta' : '<i class="fas fa-circle"></i> Alternativa'; ?>
                                        </span>
                                        <span class="alternativa-texto"><?php echo htmlspecialchars($alt['texto']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($questao['tipo'] == 'verdadeiro_falso'): ?>
                                <div class="alternativas-container">
                                    <strong><i class="fas fa-check-double"></i> Opções:</strong>
                                    <div class="alternativa-item <?php echo (!empty($questao['alternativas']) && $questao['alternativas'][0]['correta']) ? 'alternativa-correta' : ''; ?>">
                                        <span class="alternativa-badge <?php echo (!empty($questao['alternativas']) && $questao['alternativas'][0]['correta']) ? 'alternativa-badge-correta' : 'alternativa-badge-normal'; ?>">
                                            <?php echo (!empty($questao['alternativas']) && $questao['alternativas'][0]['correta']) ? '<i class="fas fa-check-circle"></i> Correta' : '<i class="fas fa-circle"></i> Opção'; ?>
                                        </span>
                                        <span class="alternativa-texto">Verdadeiro</span>
                                    </div>
                                    <div class="alternativa-item <?php echo (!empty($questao['alternativas']) && count($questao['alternativas']) > 1 && $questao['alternativas'][1]['correta']) ? 'alternativa-correta' : ''; ?>">
                                        <span class="alternativa-badge <?php echo (!empty($questao['alternativas']) && count($questao['alternativas']) > 1 && $questao['alternativas'][1]['correta']) ? 'alternativa-badge-correta' : 'alternativa-badge-normal'; ?>">
                                            <?php echo (!empty($questao['alternativas']) && count($questao['alternativas']) > 1 && $questao['alternativas'][1]['correta']) ? '<i class="fas fa-check-circle"></i> Correta' : '<i class="fas fa-circle"></i> Opção'; ?>
                                        </span>
                                        <span class="alternativa-texto">Falso</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($questao['tipo'] == 'dissertativa'): ?>
                                <div class="mt-3 alert alert-secondary">
                                    <i class="fas fa-pen-alt"></i> <strong>Questão Dissertativa</strong><br>
                                    <small>Resposta em texto livre, será corrigida pelo professor.</small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($questao['dica']): ?>
                            <div class="mt-3 p-3 bg-light rounded-3">
                                <small><i class="fas fa-lightbulb text-warning"></i> <strong>Dica:</strong> <?php echo htmlspecialchars($questao['dica']); ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Histórico de Tentativas -->
        <?php if (!empty($tentativas)): ?>
        <div class="card border-0 shadow-sm fade-in">
            <div class="card-header">
                <i class="fas fa-history"></i> Histórico de Tentativas
            </div>
            <div class="card-body">
                <?php foreach ($tentativas as $tentativa): 
                    $classe_tentativa = $tentativa['aprovado'] == 1 ? 'tentativa-aprovada' : 'tentativa-reprovada';
                    $nota_class = getNotaClass($tentativa['pontuacao_total'], $prova['nota_maxima']);
                ?>
                <div class="tentativa-card <?php echo $classe_tentativa; ?>">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <strong><i class="fas fa-redo-alt"></i> Tentativa #<?php echo $tentativa['tentativa_numero']; ?></strong>
                            <br><small class="text-muted"><?php echo $tentativa['data_inicio_formatada']; ?></small>
                        </div>
                        <div class="col-md-3">
                            <span class="badge bg-secondary"><i class="fas fa-clock"></i> Duração: <?php echo $tentativa['minutos']; ?>min <?php echo $tentativa['segundos']; ?>s</span>
                        </div>
                        <div class="col-md-3">
                            <div class="<?php echo $nota_class; ?>">
                                <i class="fas fa-star"></i> Nota: <?php echo number_format($tentativa['pontuacao_total'], 1); ?> / <?php echo $prova['nota_maxima']; ?>
                            </div>
                            <div class="progress-bar-custom mt-1">
                                <div class="progress-fill" style="width: <?php echo ($tentativa['pontuacao_total'] / $prova['nota_maxima']) * 100; ?>%; background: <?php echo $tentativa['aprovado'] == 1 ? '#28a745' : '#dc3545'; ?>"></div>
                            </div>
                        </div>
                        <div class="col-md-3 text-end">
                            <?php if ($tentativa['aprovado'] == 1): ?>
                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>
                            <?php endif; ?>
                            <br>
                            <a href="resultado_prova.php?id=<?php echo $tentativa['id']; ?>" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="fas fa-chart-line"></i> Ver Detalhes
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const btnAjuda = document.getElementById('btnAjuda');
        const modalAjuda = document.getElementById('modalAjuda');
        const closeAjuda = document.getElementById('closeAjuda');
        
        btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
        closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
        modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
        
        // Animações ao scroll
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.card, .stat-card, .questao-card').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>