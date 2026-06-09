<?php
// escola/professor/gerenciar_questoes.php - Gerenciar Questões por Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];

// ============================================
// VARIÁVEIS E FILTROS
// ============================================
$mensagem = '';
$tipo_mensagem = '';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$prova_id = isset($_GET['prova_id']) ? (int)$_GET['prova_id'] : 0;
$tipo_questao = isset($_GET['tipo']) ? $_GET['tipo'] : '';

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Salvar questão (para o banco)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'salvar_banco') {
        $enunciado = trim($_POST['enunciado']);
        $tipo = $_POST['tipo'];
        $pontuacao = (float)$_POST['pontuacao'];
        $disciplina_id_post = (int)$_POST['disciplina_id'];
        $dica = trim($_POST['dica'] ?? '');
        $imagem = $_POST['imagem'] ?? '';
        $video_url = $_POST['video_url'] ?? '';
        
        if (empty($enunciado)) {
            $mensagem = 'Digite o enunciado da questão.';
            $tipo_mensagem = 'danger';
        } elseif ($disciplina_id_post <= 0) {
            $mensagem = 'Selecione uma disciplina.';
            $tipo_mensagem = 'danger';
        } else {
            try {
                $sql = "INSERT INTO online_provas_questoes 
                        (prova_id, disciplina_id, enunciado, tipo, pontuacao, imagem, video_url, dica, ordem, created_at) 
                        VALUES (0, :disciplina_id, :enunciado, :tipo, :pontuacao, :imagem, :video_url, :dica, 0, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':disciplina_id' => $disciplina_id_post,
                    ':enunciado' => $enunciado,
                    ':tipo' => $tipo,
                    ':pontuacao' => $pontuacao,
                    ':imagem' => $imagem,
                    ':video_url' => $video_url,
                    ':dica' => $dica
                ]);
                
                $questao_id = $conn->lastInsertId();
                
                if ($tipo == 'multipla_escolha' || $tipo == 'verdadeiro_falso') {
                    $alternativas = $_POST['alternativas'] ?? [];
                    $correta = (int)$_POST['correta'];
                    
                    $sql_alt = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                                VALUES (:questao_id, :texto, :correta, :ordem)";
                    $stmt_alt = $conn->prepare($sql_alt);
                    
                    foreach ($alternativas as $idx => $texto) {
                        $texto = trim($texto);
                        if (!empty($texto)) {
                            $is_correta = ($correta == $idx) ? 1 : 0;
                            $stmt_alt->execute([
                                ':questao_id' => $questao_id,
                                ':texto' => $texto,
                                ':correta' => $is_correta,
                                ':ordem' => $idx
                            ]);
                        }
                    }
                }
                
                $mensagem = 'Questão salva no banco com sucesso!';
                $tipo_mensagem = 'success';
                
            } catch (PDOException $e) {
                $mensagem = 'Erro ao salvar: ' . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        }
    }
    
    // Excluir questão
    elseif ($acao == 'excluir') {
        $questao_id = (int)$_POST['questao_id'];
        
        try {
            $stmt_del = $conn->prepare("DELETE FROM online_provas_alternativas WHERE questao_id = :questao_id");
            $stmt_del->execute([':questao_id' => $questao_id]);
            
            $sql = "DELETE FROM online_provas_questoes WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $questao_id]);
            
            $mensagem = 'Questão excluída com sucesso!';
            $tipo_mensagem = 'success';
            
        } catch (PDOException $e) {
            $mensagem = 'Erro ao excluir: ' . $e->getMessage();
            $tipo_mensagem = 'danger';
        }
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar disciplinas da tabela online_provas (provas que o professor criou)
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                    FROM online_provas p
                    JOIN disciplinas d ON d.id = p.disciplina_id
                    WHERE p.professor_id = :professor_id
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $funcionario_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar provas do professor por disciplina
$provas_lista = [];
if ($disciplina_id > 0) {
    $sql_provas = "SELECT p.id, p.titulo, p.tipo, p.status, p.data_inicio, p.data_fim, p.created_at,
                          d.nome as disciplina_nome,
                          t.nome as turma_nome, t.ano as turma_ano
                   FROM online_provas p
                   JOIN disciplinas d ON d.id = p.disciplina_id
                   JOIN turmas t ON t.id = p.turma_id
                   WHERE p.professor_id = :professor_id 
                   AND p.disciplina_id = :disciplina_id
                   ORDER BY p.created_at DESC";
    $stmt_provas = $conn->prepare($sql_provas);
    $stmt_provas->execute([
        ':professor_id' => $funcionario_id,
        ':disciplina_id' => $disciplina_id
    ]);
    $provas_lista = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// CORREÇÃO: Buscar questões do banco geral (prova_id = 0)
// ============================================
$questoes_banco = [];
$sql_banco = "SELECT q.*, d.nome as disciplina_nome
              FROM online_provas q
              LEFT JOIN disciplinas d ON d.id = q.disciplina_id
              WHERE q.id = 0";  // ← CORRIGIDO: prova_id = 0 para questões do banco

if ($disciplina_id > 0) {
    $sql_banco .= " AND q.disciplina_id = :disciplina_id";
}
if (!empty($busca)) {
    $sql_banco .= " AND (q.enunciado LIKE :busca)";
}
if (!empty($tipo_questao)) {
    $sql_banco .= " AND q.tipo = :tipo";
}

$sql_banco .= " ORDER BY q.created_at DESC";

$stmt_banco = $conn->prepare($sql_banco);
$params_banco = [];
if ($disciplina_id > 0) $params_banco[':disciplina_id'] = $disciplina_id;
if (!empty($busca)) $params_banco[':busca'] = "%$busca%";
if (!empty($tipo_questao)) $params_banco[':tipo'] = $tipo_questao;
$stmt_banco->execute($params_banco);
$questoes_banco = $stmt_banco->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão do banco
foreach ($questoes_banco as &$questao) {
    $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
    $stmt_alt = $conn->prepare($sql_alt);
    $stmt_alt->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// Buscar questões de uma prova específica (prova_id > 0)
// ============================================
$questoes_prova = [];
$prova_selecionada = null;
if ($prova_id > 0) {
    // Buscar dados da prova
    $sql_prova_info = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
                       FROM online_provas p
                       JOIN disciplinas d ON d.id = p.disciplina_id
                       JOIN turmas t ON t.id = p.turma_id
                       WHERE p.id = :prova_id AND p.professor_id = :professor_id";
    $stmt_prova = $conn->prepare($sql_prova_info);
    $stmt_prova->execute([
        ':prova_id' => $prova_id,
        ':professor_id' => $funcionario_id
    ]);
    $prova_selecionada = $stmt_prova->fetch(PDO::FETCH_ASSOC);
    
    if ($prova_selecionada) {
        // Buscar questões da prova (prova_id específico)
        $sql_prova_questoes = "SELECT q.*, 
                                      (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = q.id) as num_alternativas
                               FROM online_provas_questoes q
                               WHERE q.prova_id = :prova_id
                               ORDER BY q.ordem ASC";
        $stmt_pq = $conn->prepare($sql_prova_questoes);
        $stmt_pq->execute([':prova_id' => $prova_id]);
        $questoes_prova = $stmt_pq->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar alternativas para cada questão da prova
        foreach ($questoes_prova as &$questao) {
            $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
            $stmt_alt = $conn->prepare($sql_alt);
            $stmt_alt->execute([':questao_id' => $questao['id']]);
            $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Estatísticas do banco
$total_banco = count($questoes_banco);
$total_multipla = 0;
$total_vf = 0;
$total_dissertativa = 0;

foreach ($questoes_banco as $q) {
    if ($q['tipo'] == 'multipla_escolha') $total_multipla++;
    elseif ($q['tipo'] == 'verdadeiro_falso') $total_vf++;
    elseif ($q['tipo'] == 'dissertativa') $total_dissertativa++;
}

// Total de provas
$total_provas = count($provas_lista);
?>

<!-- O resto do HTML permanece igual ao seu código original -->
<!-- ... -->

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Gerenciar Questões | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content { margin-left: 280px; margin-top: 60px; padding: 20px; min-height: calc(100vh - 60px); }
        @media (max-width: 768px) { .main-content { margin-left: 0; margin-top: 70px; padding: 15px; } }
        
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 20px; padding: 20px 25px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .page-header h4 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .page-header p { margin: 8px 0 0; opacity: 0.85; font-size: 0.9rem; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; transition: all 0.3s ease; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .stat-icon { width: 55px; height: 55px; background: rgba(0,107,62,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .stat-icon i { font-size: 24px; color: #006B3E; }
        .stat-value { font-size: 1.8rem; font-weight: 800; margin-bottom: 5px; }
        .stat-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        
        .filter-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .form-label { font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; margin-bottom: 8px; }
        .form-control, .form-select { border-radius: 12px; border: 2px solid #e9ecef; padding: 10px 15px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { border-color: #006B3E; box-shadow: 0 0 0 3px rgba(0,107,62,0.1); outline: none; }
        .btn-primary { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); border: none; border-radius: 12px; padding: 10px 20px; font-weight: 600; transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        
        .prova-card { background: white; border-radius: 20px; margin-bottom: 15px; padding: 15px 20px; border: 1px solid #e9ecef; transition: all 0.3s ease; cursor: pointer; }
        .prova-card:hover { transform: translateX(5px); border-color: #006B3E; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .prova-card.active { background: #e8f5e9; border-left: 4px solid #006B3E; }
        
        .questao-card { background: white; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); overflow: hidden; transition: all 0.3s ease; }
        .questao-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .questao-header { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .questao-badge { background: #006B3E; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
        .questao-tipo-badge { padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 600; }
        .tipo-multipla { background: #17a2b8; color: white; }
        .tipo-vf { background: #ffc107; color: #333; }
        .tipo-dissertativa { background: #6c757d; color: white; }
        .questao-body { padding: 20px; }
        .questao-enunciado { background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 15px; border-left: 3px solid #006B3E; }
        .alternativa-item { display: flex; align-items: center; gap: 10px; padding: 8px; margin: 5px 0; background: #f8f9fa; border-radius: 8px; }
        .alternativa-correta { background: #d4edda; border-left: 3px solid #28a745; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-add-alternativa { background: transparent; border: 2px dashed #006B3E; color: #006B3E; border-radius: 12px; padding: 8px; width: 100%; margin-top: 10px; transition: all 0.3s ease; }
        .btn-add-alternativa:hover { background: #006B3E; color: white; }
        
        .nav-tabs-custom { border-bottom: 2px solid #e9ecef; margin-bottom: 20px; }
        .nav-tabs-custom .nav-link { border: none; color: #6c757d; font-weight: 600; padding: 12px 25px; }
        .nav-tabs-custom .nav-link:hover { color: #006B3E; }
        .nav-tabs-custom .nav-link.active { color: #006B3E; border-bottom: 3px solid #006B3E; background: transparent; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; } .questao-header { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4><i class="fas fa-database me-2"></i> Gerenciar Questões</h4>
                    <p>Gerencie o banco de questões e as questões das provas</p>
                </div>
                <div>
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalQuestao" onclick="novaQuestao()">
                        <i class="fas fa-plus-circle"></i> Nova Questão
                    </button>
                </div>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $mensagem; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-database"></i></div>
                <div class="stat-value text-primary"><?php echo $total_banco; ?></div>
                <div class="stat-label">Questões no Banco</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-list-ul"></i></div>
                <div class="stat-value text-info"><?php echo $total_multipla; ?></div>
                <div class="stat-label">Múltipla Escolha</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-value text-warning"><?php echo $total_vf; ?></div>
                <div class="stat-label">Verdadeiro/Falso</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-pen"></i></div>
                <div class="stat-value text-secondary"><?php echo $total_dissertativa; ?></div>
                <div class="stat-label">Dissertativas</div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-book"></i> Disciplina (das provas)</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disc): ?>
                        <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_id == $disc['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disc['nome']); ?> (<?php echo $disc['codigo'] ?? ''; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Disciplinas que você tem provas criadas</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-tag"></i> Tipo de Questão</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <option value="multipla_escolha" <?php echo $tipo_questao == 'multipla_escolha' ? 'selected' : ''; ?>>Múltipla Escolha</option>
                        <option value="verdadeiro_falso" <?php echo $tipo_questao == 'verdadeiro_falso' ? 'selected' : ''; ?>>Verdadeiro/Falso</option>
                        <option value="dissertativa" <?php echo $tipo_questao == 'dissertativa' ? 'selected' : ''; ?>>Dissertativa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-search"></i> Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Pesquisar por enunciado..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                </div>
            </form>
        </div>

        <?php if ($disciplina_id > 0 && !empty($provas_lista)): ?>
        <!-- Lista de Provas da Disciplina -->
        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-list-alt text-primary me-2"></i> Provas da Disciplina (<?php echo $total_provas; ?> provas)</h5>
            <div class="row">
                <?php foreach ($provas_lista as $prova_item): ?>
                <div class="col-md-4 mb-3">
                    <div class="prova-card <?php echo $prova_id == $prova_item['id'] ? 'active' : ''; ?>" onclick="window.location.href='?disciplina_id=<?php echo $disciplina_id; ?>&prova_id=<?php echo $prova_item['id']; ?>&tipo=<?php echo $tipo_questao; ?>&busca=<?php echo urlencode($busca); ?>'">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><?php echo htmlspecialchars($prova_item['titulo']); ?></h6>
                                <small class="text-muted">
                                    <i class="fas fa-users"></i> <?php echo $prova_item['turma_ano'] . 'ª - ' . htmlspecialchars($prova_item['turma_nome']); ?>
                                </small>
                            </div>
                            <span class="badge bg-<?php 
                                echo $prova_item['status'] == 'publicada' ? 'success' : ($prova_item['status'] == 'agendada' ? 'warning' : 'secondary'); 
                            ?>">
                                <?php echo ucfirst($prova_item['status']); ?>
                            </span>
                        </div>
                        <div class="mt-2">
                            <small><i class="fas fa-calendar-alt"></i> Criada: <?php echo date('d/m/Y', strtotime($prova_item['created_at'])); ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif ($disciplina_id > 0 && empty($provas_lista)): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle"></i> Nenhuma prova encontrada para esta disciplina.
        </div>
        <?php endif; ?>

        <!-- Abas -->
        <ul class="nav nav-tabs-custom">
            <li class="nav-item">
                <a class="nav-link <?php echo $prova_id == 0 ? 'active' : ''; ?>" href="?disciplina_id=<?php echo $disciplina_id; ?>&tipo=<?php echo $tipo_questao; ?>&busca=<?php echo urlencode($busca); ?>">
                    <i class="fas fa-database"></i> Banco de Questões (<?php echo $total_banco; ?>)
                </a>
            </li>
            <?php if ($prova_id > 0 && $prova_selecionada): ?>
            <li class="nav-item">
                <a class="nav-link active" href="#">
                    <i class="fas fa-file-alt"></i> Questões da Prova: <?php echo htmlspecialchars(substr($prova_selecionada['titulo'], 0, 30)); ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <?php if ($prova_id > 0 && $prova_selecionada): ?>
            <!-- Informações da Prova Selecionada -->
            <div class="alert alert-info mb-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong><i class="fas fa-info-circle"></i> Prova:</strong> <?php echo htmlspecialchars($prova_selecionada['titulo']); ?><br>
                        <small><i class="fas fa-book"></i> <?php echo htmlspecialchars($prova_selecionada['disciplina_nome']); ?> | 
                               <i class="fas fa-users"></i> <?php echo $prova_selecionada['turma_ano'] . 'ª - ' . htmlspecialchars($prova_selecionada['turma_nome']); ?></small>
                    </div>
                    <div>
                        <span class="badge bg-<?php echo $prova_selecionada['status'] == 'publicada' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst($prova_selecionada['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Lista de Questões da Prova -->
            <?php if (empty($questoes_prova)): ?>
                <div class="text-center py-5 bg-white rounded-4">
                    <i class="fas fa-question-circle fa-4x text-muted mb-3"></i>
                    <h5>Nenhuma questão adicionada a esta prova</h5>
                    <p class="text-muted">Adicione questões do banco ou crie novas questões.</p>
                </div>
            <?php else: ?>
                <?php foreach ($questoes_prova as $index => $questao): ?>
                <div class="questao-card fade-in">
                    <div class="questao-header">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="questao-badge">Q<?php echo $index + 1; ?></span>
                            <span class="questao-tipo-badge tipo-<?php echo str_replace('_', '-', $questao['tipo']); ?>">
                                <?php 
                                    $tipos = ['multipla_escolha' => '📝 Múltipla Escolha', 'verdadeiro_falso' => '✅ Verdadeiro/Falso', 'dissertativa' => '✏️ Dissertativa'];
                                    echo $tipos[$questao['tipo']] ?? $questao['tipo'];
                                ?>
                            </span>
                            <span class="badge bg-secondary"><i class="fas fa-star"></i> <?php echo $questao['pontuacao']; ?> pts</span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-danger" onclick="excluirQuestao(<?php echo $questao['id']; ?>)">
                                <i class="fas fa-trash"></i> Remover
                            </button>
                        </div>
                    </div>
                    <div class="questao-body">
                        <div class="questao-enunciado">
                            <?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?>
                        </div>
                        
                        <?php if (!empty($questao['alternativas'])): ?>
                            <div class="mt-2">
                                <strong><i class="fas fa-list-ul"></i> Alternativas:</strong>
                                <?php foreach ($questao['alternativas'] as $alt): ?>
                                <div class="alternativa-item <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                                    <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $alt['correta'] ? '✓ Correta' : '○'; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($questao['dica']): ?>
                            <div class="mt-2 p-2 bg-light rounded">
                                <small><i class="fas fa-lightbulb text-warning"></i> <strong>Dica:</strong> <?php echo htmlspecialchars($questao['dica']); ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- Lista do Banco de Questões -->
            <?php if (empty($questoes_banco)): ?>
                <div class="text-center py-5 bg-white rounded-4">
                    <i class="fas fa-database fa-4x text-muted mb-3"></i>
                    <h5>Nenhuma questão encontrada</h5>
                    <p class="text-muted">Clique em "Nova Questão" para começar a criar seu banco de questões.</p>
                </div>
            <?php else: ?>
                <?php foreach ($questoes_banco as $questao): ?>
                <div class="questao-card fade-in">
                    <div class="questao-header">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="questao-badge">ID: <?php echo $questao['id']; ?></span>
                            <span class="questao-tipo-badge tipo-<?php echo str_replace('_', '-', $questao['tipo']); ?>">
                                <?php 
                                    $tipos = ['multipla_escolha' => '📝 Múltipla Escolha', 'verdadeiro_falso' => '✅ Verdadeiro/Falso', 'dissertativa' => '✏️ Dissertativa'];
                                    echo $tipos[$questao['tipo']] ?? $questao['tipo'];
                                ?>
                            </span>
                            <span class="badge bg-secondary"><i class="fas fa-star"></i> <?php echo $questao['pontuacao']; ?> pts</span>
                            <span class="badge bg-info"><i class="fas fa-book"></i> <?php echo htmlspecialchars($questao['disciplina_nome'] ?? 'Sem disciplina'); ?></span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" onclick="editarQuestao(<?php echo $questao['id']; ?>)">
                                <i class="fas fa-edit"></i> Editar
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="excluirQuestao(<?php echo $questao['id']; ?>)">
                                <i class="fas fa-trash"></i> Excluir
                            </button>
                        </div>
                    </div>
                    <div class="questao-body">
                        <div class="questao-enunciado">
                            <?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?>
                        </div>
                        
                        <?php if (!empty($questao['alternativas'])): ?>
                            <div class="mt-2">
                                <strong><i class="fas fa-list-ul"></i> Alternativas:</strong>
                                <?php foreach ($questao['alternativas'] as $alt): ?>
                                <div class="alternativa-item <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                                    <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $alt['correta'] ? '✓ Correta' : '○'; ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($questao['dica']): ?>
                            <div class="mt-2 p-2 bg-light rounded">
                                <small><i class="fas fa-lightbulb text-warning"></i> <strong>Dica:</strong> <?php echo htmlspecialchars($questao['dica']); ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-2 text-muted small">
                            <i class="fas fa-calendar-alt"></i> Criada em: <?php echo date('d/m/Y H:i', strtotime($questao['created_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Nova Questão (mesmo do código anterior) -->
    <div class="modal fade" id="modalQuestao" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalQuestaoTitle"><i class="fas fa-plus-circle me-2"></i> Nova Questão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formQuestao">
                    <input type="hidden" name="acao" id="acao" value="salvar_banco">
                    <input type="hidden" name="questao_id" id="questao_id" value="0">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Enunciado *</label>
                            <textarea name="enunciado" id="enunciado" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Disciplina *</label>
                                <select name="disciplina_id" id="disciplina_id" class="form-select" required>
                                    <option value="">Selecione</option>
                                    <?php foreach ($disciplinas as $disc): ?>
                                    <option value="<?php echo $disc['id']; ?>"><?php echo htmlspecialchars($disc['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="tipo" class="form-select" onchange="mudarTipo()">
                                    <option value="multipla_escolha">Múltipla Escolha</option>
                                    <option value="verdadeiro_falso">Verdadeiro ou Falso</option>
                                    <option value="dissertativa">Dissertativa</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Pontuação</label>
                                <input type="number" name="pontuacao" class="form-control" step="0.5" value="1.00">
                            </div>
                        </div>
                        
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label class="form-label">URL da Imagem</label>
                                <input type="text" name="imagem" class="form-control" placeholder="https://exemplo.com/imagem.jpg">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">URL do Vídeo</label>
                                <input type="text" name="video_url" class="form-control" placeholder="https://youtu.be/...">
                            </div>
                        </div>
                        
                        <div class="mt-2">
                            <label class="form-label">Dica (opcional)</label>
                            <textarea name="dica" class="form-control" rows="2" placeholder="Dica para ajudar o aluno..."></textarea>
                        </div>
                        
                        <div id="alternativas_div" class="mt-3">
                            <label class="form-label">Alternativas</label>
                            <div id="alternativas_container"></div>
                            <button type="button" class="btn-add-alternativa" onclick="adicionarAlternativa()">
                                <i class="fas fa-plus"></i> Adicionar Alternativa
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Questão</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let contadorAlternativas = 0;
        
        function mudarTipo() {
            let tipo = $('#tipo').val();
            let div = $('#alternativas_div');
            let container = $('#alternativas_container');
            
            if (tipo == 'multipla_escolha') {
                div.show();
                if (container.children().length === 0) {
                    for (let i = 0; i < 4; i++) adicionarAlternativa();
                }
            } else if (tipo == 'verdadeiro_falso') {
                div.show();
                container.html(`
                    <div class="alternativa-item d-flex align-items-center gap-2 mb-2">
                        <input type="radio" name="correta" value="0" checked>
                        <input type="text" name="alternativas[]" class="form-control" value="Verdadeiro" readonly style="background:#e9ecef">
                    </div>
                    <div class="alternativa-item d-flex align-items-center gap-2 mb-2">
                        <input type="radio" name="correta" value="1">
                        <input type="text" name="alternativas[]" class="form-control" value="Falso" readonly style="background:#e9ecef">
                    </div>
                `);
            } else {
                div.hide();
            }
        }
        
        function adicionarAlternativa() {
            let container = $('#alternativas_container');
            let idx = contadorAlternativas++;
            let div = $(`
                <div class="alternativa-item d-flex align-items-center gap-2 mb-2">
                    <input type="radio" name="correta" value="${idx}">
                    <input type="text" name="alternativas[]" class="form-control" placeholder="Digite a alternativa...">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>
                </div>
            `);
            container.append(div);
        }
        
        function removerAlternativa(btn) {
            $(btn).closest('.alternativa-item').remove();
        }
        
        function novaQuestao() {
            $('#modalQuestaoTitle').html('<i class="fas fa-plus-circle me-2"></i> Nova Questão');
            $('#acao').val('salvar_banco');
            $('#questao_id').val(0);
            $('#formQuestao')[0].reset();
            contadorAlternativas = 0;
            $('#alternativas_container').empty();
            $('#tipo').val('multipla_escolha');
            mudarTipo();
            $('#modalQuestao').modal('show');
        }
        
        function excluirQuestao(id) {
            if (confirm('Tem certeza que deseja excluir esta questão?')) {
                $('<form method="POST"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="questao_id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        $(document).ready(function() { mudarTipo(); });
    </script>
</body>
</html>