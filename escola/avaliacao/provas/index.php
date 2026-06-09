<?php
// escola/avaliacao/provas/index.php - Gestão de Provas e Avaliações

require_once __DIR__ . '/../../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// VARIÁVEIS DE FILTRO E AÇÃO
// ============================================
$acao = $_GET['acao'] ?? $_POST['acao'] ?? 'listar';
$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$status_filtro = $_GET['status'] ?? 'todos';
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'todos';

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno, sala FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROFESSORES (Funcionários)
// ============================================
$sql_professores = "SELECT id, nome, numero_processo FROM funcionarios WHERE escola_id = :escola_id AND status = 'ativo' AND tipo_funcionario = 'professor' ORDER BY nome";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':escola_id' => $escola_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS
// ============================================
$sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id ORDER BY nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TIPOS DE AVALIAÇÃO (AJAX)
// ============================================
if (isset($_GET['get_tipos_avaliacao'])) {
    $sql_tipos = "SELECT id, nome, codigo, categoria, peso_padrao, escala_maxima, cor, icone
                  FROM tipos_avaliacao 
                  WHERE escola_id = :escola_id 
                  AND status = 'ativo'
                  ORDER BY ordem ASC, nome ASC";
    $stmt_tipos = $conn->prepare($sql_tipos);
    $stmt_tipos->execute([':escola_id' => $escola_id]);
    $tipos_avaliacao = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($tipos_avaliacao);
    exit;
}

// ============================================
// BUSCAR DISCIPLINAS POR TURMA (AJAX)
// ============================================
if (isset($_GET['get_disciplinas']) && isset($_GET['turma_id'])) {
    $turma_id_ajax = (int)$_GET['turma_id'];
    $sql_disc = "SELECT DISTINCT d.id, d.nome, d.codigo
                 FROM disciplinas d
                 INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                 WHERE pdt.turma_id = :turma_id
                 ORDER BY d.nome";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':turma_id' => $turma_id_ajax]);
    $disciplinas_turma = $stmt_disc->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($disciplinas_turma);
    exit;
}

// ============================================
// TIPOS DE PROVA (Fallback se não houver no banco)
// ============================================
$tipos_prova = [
    'prova_mensal' => 'Prova Mensal',
    'prova_trimestral' => 'Prova Trimestral',
    'exame_normal' => 'Exame Normal',
    'exame_recorrencia' => 'Exame de Recorrência',
    'trabalho' => 'Trabalho',
    'teste' => 'Teste',
    'avaliacao_continua' => 'Avaliação Contínua',
    'recuperacao' => 'Prova de Recuperação'
];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Registrar nova prova
if ($acao == 'registrar' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $tipo_prova_id = (int)$_POST['tipo_prova_id']; // Agora é o ID do tipo
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'] ?? '';
    $data_prova = $_POST['data_prova'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fim = $_POST['hora_fim'];
    $periodo = $_POST['periodo'];
    $valor_total = (float)$_POST['valor_total'];
    $sala = $_POST['sala'] ?? '';
    $instrucoes = $_POST['instrucoes'] ?? '';
    $material_permitido = $_POST['material_permitido'] ?? '';
    $docente_responsavel = (int)$_POST['docente_responsavel'];
    
    $errors = [];
    if (empty($turma_id)) $errors[] = "Selecione uma turma";
    if (empty($disciplina_id)) $errors[] = "Selecione uma disciplina";
    if (empty($tipo_prova_id)) $errors[] = "Selecione um tipo de avaliação";
    if (empty($titulo)) $errors[] = "Informe o título da prova";
    if (empty($data_prova)) $errors[] = "Informe a data da prova";
    if (empty($valor_total) || $valor_total <= 0) $errors[] = "Informe um valor válido";
    
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO provas (turma_id, disciplina_id, tipo_prova, titulo, descricao, 
                    data_prova, hora_inicio, hora_fim, periodo, valor_total, sala, 
                    instrucoes, material_permitido, docente_responsavel, escola_id, ano_letivo_id, status, data_criacao)
                    VALUES (:turma_id, :disciplina_id, :tipo_prova, :titulo, :descricao, 
                    :data_prova, :hora_inicio, :hora_fim, :periodo, :valor_total, :sala, 
                    :instrucoes, :material_permitido, :docente_responsavel, :escola_id, :ano_letivo_id, 'agendada', NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':tipo_prova' => $tipo_prova_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':data_prova' => $data_prova,
                ':hora_inicio' => $hora_inicio,
                ':hora_fim' => $hora_fim,
                ':periodo' => $periodo,
                ':valor_total' => $valor_total,
                ':sala' => $sala,
                ':instrucoes' => $instrucoes,
                ':material_permitido' => $material_permitido,
                ':docente_responsavel' => $docente_responsavel,
                ':escola_id' => $escola_id,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            
            $mensagem_sucesso = "Prova agendada com sucesso!";
            $acao = 'listar';
        } catch (PDOException $e) {
            $erro = "Erro ao registrar prova: " . $e->getMessage();
        }
    } else {
        $erro = implode("<br>", $errors);
    }
}

// Publicar prova (mantido igual)
if ($acao == 'publicar' && $prova_id > 0) {
    try {
        $sql = "UPDATE provas SET status = 'publicada', data_publicacao = NOW() 
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $prova_id, ':escola_id' => $escola_id]);
        
        $mensagem_sucesso = "Prova publicada com sucesso!";
    } catch (PDOException $e) {
        $erro = "Erro ao publicar prova: " . $e->getMessage();
    }
}

// Cancelar prova (mantido igual)
if ($acao == 'cancelar' && $prova_id > 0) {
    try {
        $sql = "UPDATE provas SET status = 'cancelada' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $prova_id, ':escola_id' => $escola_id]);
        
        $mensagem_sucesso = "Prova cancelada!";
    } catch (PDOException $e) {
        $erro = "Erro ao cancelar prova: " . $e->getMessage();
    }
}

// Realizar prova (mantido igual)
if ($acao == 'realizar' && $prova_id > 0) {
    try {
        $sql = "UPDATE provas SET status = 'realizada' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $prova_id, ':escola_id' => $escola_id]);
        
        $mensagem_sucesso = "Prova marcada como realizada!";
    } catch (PDOException $e) {
        $erro = "Erro ao atualizar prova: " . $e->getMessage();
    }
}

// Excluir prova (mantido igual)
if ($acao == 'excluir' && $prova_id > 0) {
    try {
        $sql_check = "SELECT COUNT(*) as total FROM notas WHERE prova_id = :prova_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':prova_id' => $prova_id]);
        $tem_notas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($tem_notas > 0) {
            $erro = "Não é possível excluir esta prova pois já existem notas lançadas!";
        } else {
            $sql = "DELETE FROM provas WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $prova_id, ':escola_id' => $escola_id]);
            $mensagem_sucesso = "Prova excluída com sucesso!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao excluir prova: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR PROVAS
// ============================================
$sql_provas = "SELECT p.*, 
               t.nome as turma_nome, t.ano as turma_ano, t.turno, t.sala as turma_sala,
               d.nome as disciplina_nome, d.codigo as disciplina_codigo,
               f.nome as docente_nome,
               ta.nome as tipo_avaliacao_nome
               FROM provas p
               INNER JOIN turmas t ON t.id = p.turma_id
               INNER JOIN disciplinas d ON d.id = p.disciplina_id
               LEFT JOIN funcionarios f ON f.id = p.docente_responsavel
               LEFT JOIN tipos_avaliacao ta ON ta.id = p.tipo_prova
               WHERE p.escola_id = :escola_id
               AND p.ano_letivo_id = :ano_letivo_id";

$params = [':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id];

if ($status_filtro != 'todos') {
    $sql_provas .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if ($turma_id > 0) {
    $sql_provas .= " AND p.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

if ($disciplina_id > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}

if ($periodo != 'todos') {
    $sql_provas .= " AND p.periodo = :periodo";
    $params[':periodo'] = $periodo;
}

$sql_provas .= " ORDER BY p.data_prova DESC, p.data_criacao DESC";

$stmt_provas = $conn->prepare($sql_provas);
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total' => count($provas),
    'agendada' => 0,
    'publicada' => 0,
    'realizada' => 0,
    'cancelada' => 0,
    'provas_hoje' => 0,
    'provas_semana' => 0
];

$data_atual = new DateTime();
$data_hoje = $data_atual->format('Y-m-d');
$data_semana = clone $data_atual;
$data_semana->modify('+7 days');

foreach ($provas as $prova) {
    // Incrementar contadores baseados no status
    switch ($prova['status']) {
        case 'agendada':
            $estatisticas['agendada']++;
            break;
        case 'publicada':
            $estatisticas['publicada']++;
            break;
        case 'realizada':
            $estatisticas['realizada']++;
            break;
        case 'cancelada':
            $estatisticas['cancelada']++;
            break;
    }
    
    // Contar provas de hoje
    if ($prova['data_prova'] == $data_hoje) {
        $estatisticas['provas_hoje']++;
    }
    
    // Contar provas da semana
    if ($prova['data_prova'] >= $data_hoje && $prova['data_prova'] <= $data_semana->format('Y-m-d')) {
        $estatisticas['provas_semana']++;
    }
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Provas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        /* Estilos mantidos iguais ao original */
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); border-radius: 15px; padding: 25px 30px; margin-bottom: 25px; color: white; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .page-header h2 { margin: 0 0 5px 0; font-size: 28px; }
        .page-header p { margin: 0; opacity: 0.9; font-size: 14px; }
        .page-header .header-stats { display: flex; gap: 20px; margin-top: 15px; flex-wrap: wrap; }
        .header-stats .stat-item { background: rgba(255,255,255,0.15); border-radius: 10px; padding: 8px 15px; text-align: center; }
        .header-stats .stat-item .stat-value { font-size: 20px; font-weight: bold; }
        .header-stats .stat-item .stat-label { font-size: 11px; opacity: 0.8; }
        .filter-bar { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card { background: white; border-radius: 10px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; }
        .stat-number.agendadas { color: #ffc107; }
        .stat-number.publicadas { color: #17a2b8; }
        .stat-number.realizadas { color: #28a745; }
        .stat-number.canceladas { color: #dc3545; }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .status-agendada { background: #fff3cd; color: #856404; }
        .status-publicada { background: #d1ecf1; color: #0c5460; }
        .status-realizada { background: #d4edda; color: #155724; }
        .status-cancelada { background: #f8d7da; color: #721c24; }
        .prova-card { transition: all 0.3s; border-left: 4px solid #006B3E; margin-bottom: 20px; }
        .prova-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .btn-primary-custom { background: #006B3E; color: white; border-radius: 25px; padding: 10px 24px; }
        .btn-primary-custom:hover { background: #004d2d; color: white; }
        .loading-spinner { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; }
        @media print { .no-print { display: none !important; } .sidebar { display: none; } .main-content { margin-left: 0; padding: 10px; } .page-header { background: #006B3E; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Gestão de Provas</h2>
                    <p>Agende, publique e gerencie todas as avaliações da escola</p>
                </div>
                <div class="no-print">
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalRegistrarProva">
                        <i class="fas fa-plus-circle text-success"></i> Nova Prova
                    </button>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item"><div class="stat-value"><?php echo $estatisticas['total']; ?></div><div class="stat-label">Total Provas</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $estatisticas['provas_hoje']; ?></div><div class="stat-label">Hoje</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $estatisticas['provas_semana']; ?></div><div class="stat-label">Esta Semana</div></div>
            </div>
        </div>
        
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row mb-4 no-print">
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-calendar-alt fa-2x text-warning mb-2"></i><div class="stat-number agendadas"><?php echo $estatisticas['agendada']; ?></div><small>Agendadas</small></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-eye fa-2x text-info mb-2"></i><div class="stat-number publicadas"><?php echo $estatisticas['publicada']; ?></div><small>Publicadas</small></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-check-circle fa-2x text-success mb-2"></i><div class="stat-number realizadas"><?php echo $estatisticas['realizada']; ?></div><small>Realizadas</small></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-times-circle fa-2x text-danger mb-2"></i><div class="stat-number canceladas"><?php echo $estatisticas['cancelada']; ?></div><small>Canceladas</small></div></div>
        </div>
        
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3"><label class="form-label fw-bold">Status</label><select name="status" class="form-select" onchange="this.form.submit()"><option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option><option value="agendada" <?php echo $status_filtro == 'agendada' ? 'selected' : ''; ?>>Agendadas</option><option value="publicada" <?php echo $status_filtro == 'publicada' ? 'selected' : ''; ?>>Publicadas</option><option value="realizada" <?php echo $status_filtro == 'realizada' ? 'selected' : ''; ?>>Realizadas</option><option value="cancelada" <?php echo $status_filtro == 'cancelada' ? 'selected' : ''; ?>>Canceladas</option></select></div>
                <div class="col-md-3"><label class="form-label fw-bold">Turma</label><select name="turma_id" class="form-select" onchange="this.form.submit()"><option value="0">Todas</option><?php foreach ($turmas as $turma): ?><option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>><?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label fw-bold">Disciplina</label><select name="disciplina_id" class="form-select" onchange="this.form.submit()"><option value="0">Todas</option><?php foreach ($disciplinas as $disc): ?><option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_id == $disc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($disc['nome']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label fw-bold">Período</label><select name="periodo" class="form-select" onchange="this.form.submit()"><option value="todos" <?php echo $periodo == 'todos' ? 'selected' : ''; ?>>Todos</option><option value="1º Bimestre" <?php echo $periodo == '1º Bimestre' ? 'selected' : ''; ?>>1º Bimestre</option><option value="2º Bimestre" <?php echo $periodo == '2º Bimestre' ? 'selected' : ''; ?>>2º Bimestre</option><option value="3º Bimestre" <?php echo $periodo == '3º Bimestre' ? 'selected' : ''; ?>>3º Bimestre</option><option value="Exame" <?php echo $periodo == 'Exame' ? 'selected' : ''; ?>>Exame</option></select></div>
            </form>
        </div>
        
        <div class="row">
            <?php if (empty($provas)): ?>
                <div class="col-12"><div class="alert alert-info text-center"><i class="fas fa-info-circle"></i> Nenhuma prova encontrada com os filtros selecionados.</div></div>
            <?php else: ?>
                <?php foreach ($provas as $prova): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card prova-card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <span class="status-badge status-<?php echo $prova['status']; ?>"><?php echo ucfirst($prova['status']); ?></span>
                                <small class="text-muted"><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($prova['data_prova'])); ?></small>
                            </div>
                            <div class="card-body">
                                <h5 class="card-title text-primary"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                                <div class="mb-2">
                                    <span class="badge bg-secondary"><?php echo $prova['tipo_avaliacao_nome'] ?? $tipos_prova[$prova['tipo_prova']] ?? $prova['tipo_prova']; ?></span>
                                    <span class="badge bg-info"><?php echo $prova['periodo']; ?></span>
                                </div>
                                <p class="card-text small text-muted">
                                    <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . $prova['turma_nome']; ?><br>
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?><br>
                                    <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($prova['hora_inicio'])) . ' - ' . date('H:i', strtotime($prova['hora_fim'])); ?><br>
                                    <i class="fas fa-star"></i> Valor: <?php echo number_format($prova['valor_total'], 2, ',', '.'); ?> valores<br>
                                    <?php if ($prova['sala']): ?><i class="fas fa-door-open"></i> Sala: <?php echo $prova['sala']; ?><br><?php endif; ?>
                                    <?php if ($prova['docente_nome']): ?><i class="fas fa-chalkboard-user"></i> Docente: <?php echo htmlspecialchars($prova['docente_nome']); ?><?php endif; ?>
                                </p>
                                <?php if ($prova['descricao']): ?><p class="card-text small"><?php echo nl2br(htmlspecialchars(substr($prova['descricao'], 0, 100))); ?></p><?php endif; ?>
                            </div>
                            <div class="card-footer bg-white no-print">
                                <div class="btn-group w-100" role="group">
                                    <button type="button" class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $prova['id']; ?>, '<?php echo addslashes($prova['titulo']); ?>', '<?php echo $prova['data_prova']; ?>', '<?php echo $prova['hora_inicio']; ?>', '<?php echo $prova['hora_fim']; ?>', '<?php echo addslashes($prova['disciplina_nome']); ?>', '<?php echo addslashes($prova['descricao']); ?>', '<?php echo $prova['valor_total']; ?>', '<?php echo $prova['sala']; ?>', '<?php echo addslashes($prova['instrucoes']); ?>', '<?php echo addslashes($prova['material_permitido']); ?>', '<?php echo addslashes($prova['docente_nome']); ?>', '<?php echo $prova['periodo']; ?>')"><i class="fas fa-eye"></i> Ver</button>
                                    <?php if ($prova['status'] == 'agendada'): ?><a href="?acao=publicar&id=<?php echo $prova['id']; ?>&status=<?php echo $status_filtro; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&periodo=<?php echo $periodo; ?>" class="btn btn-sm btn-success" onclick="return confirm('Publicar esta prova para os alunos?')"><i class="fas fa-globe"></i> Publicar</a><?php endif; ?>
                                    <?php if ($prova['status'] == 'publicada'): ?><a href="lancar_notas.php?prova_id=<?php echo $prova['id']; ?>" class="btn btn-sm btn-warning"><i class="fas fa-chalkboard-teacher"></i> Notas</a><?php endif; ?>
                                    <?php if ($prova['status'] != 'realizada' && $prova['status'] != 'cancelada'): ?><a href="?acao=cancelar&id=<?php echo $prova['id']; ?>&status=<?php echo $status_filtro; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&periodo=<?php echo $periodo; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancelar esta prova?')"><i class="fas fa-times"></i> Cancelar</a><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal Registrar Prova -->
    <div class="modal fade no-print" id="modalRegistrarProva" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agendar Nova Prova</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAgendarProva">
                    <input type="hidden" name="acao" value="registrar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Turma *</label><select name="turma_id" id="turma_id_select" class="form-select" required><option value="">Selecione...</option><?php foreach ($turmas as $turma): ?><option value="<?php echo $turma['id']; ?>" data-sala="<?php echo $turma['sala']; ?>" data-turno="<?php echo $turma['turno']; ?>"><?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Disciplina *</label><select name="disciplina_id" id="disciplina_id_select" class="form-select" required><option value="">Primeiro selecione a turma...</option></select></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Tipo de Avaliação *</label><select name="tipo_prova_id" id="tipo_prova_select" class="form-select" required><option value="">Carregando tipos de avaliação...</option></select></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Período *</label><select name="periodo" class="form-select" required><option value="">Selecione...</option><option value="1º Bimestre">1º Bimestre</option><option value="2º Bimestre">2º Bimestre</option><option value="3º Bimestre">3º Bimestre</option><option value="Exame">Exame</option></select></div>
                            <div class="col-12 mb-3"><label class="form-label fw-bold">Título da Prova *</label><input type="text" name="titulo" class="form-control" required></div>
                            <div class="col-12 mb-3"><label class="form-label fw-bold">Descrição</label><textarea name="descricao" class="form-control" rows="2"></textarea></div>
                            <div class="col-md-3 mb-3"><label class="form-label fw-bold">Data da Prova *</label><input type="date" name="data_prova" class="form-control" required></div>
                            <div class="col-md-3 mb-3"><label class="form-label fw-bold">Hora Início *</label><input type="time" name="hora_inicio" class="form-control" required></div>
                            <div class="col-md-3 mb-3"><label class="form-label fw-bold">Hora Fim *</label><input type="time" name="hora_fim" class="form-control" required></div>
                            <div class="col-md-3 mb-3"><label class="form-label fw-bold">Valor Total *</label><input type="number" step="0.01" name="valor_total" id="valor_total" class="form-control" placeholder="Ex: 20.00" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Sala</label><input type="text" name="sala" id="sala_input" class="form-control" placeholder="Sala da turma"></div>
                            <div class="col-12 mb-3"><label class="form-label fw-bold">Instruções</label><textarea name="instrucoes" class="form-control" rows="2" placeholder="Instruções para os alunos..."></textarea></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Material Permitido</label><input type="text" name="material_permitido" class="form-control" placeholder="Ex: Calculadora, régua..."></div>
                            <div class="col-md-6 mb-3"><label class="form-label fw-bold">Docente Responsável *</label><select name="docente_responsavel" class="form-select" required><option value="">Selecione o docente...</option><?php foreach ($professores as $prof): ?><option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?> <?php echo $prof['numero_processo'] ? ' - ' . $prof['numero_processo'] : ''; ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Agendar Prova</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="loading-spinner"><div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;"><span class="visually-hidden">Carregando...</span></div></div>
    
    <!-- Modal Detalhes -->
    <div class="modal fade no-print" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background: #006B3E; color: white;"><h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes da Prova</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="detalhesConteudo"><div class="text-center"><div class="spinner-border text-primary"></div> Carregando...</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary" onclick="window.print()">Imprimir</button></div></div></div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function carregarTiposAvaliacao() {
            $('.loading-spinner').show();
            $.ajax({
                url: window.location.href,
                method: 'GET',
                data: { get_tipos_avaliacao: 1 },
                dataType: 'json',
                success: function(data) {
                    var $tipoSelect = $('#tipo_prova_select');
                    $tipoSelect.empty();
                    if (data.length > 0) {
                        $tipoSelect.append('<option value="">Selecione um tipo...</option>');
                        $.each(data, function(index, tipo) {
                            var pesoTexto = tipo.peso_padrao ? ' - Peso: ' + tipo.peso_padrao : '';
                            var escalaTexto = tipo.escala_maxima ? ' (0-' + tipo.escala_maxima + ')' : '';
                            $tipoSelect.append('<option value="' + tipo.id + '" data-peso="' + tipo.peso_padrao + '" data-escala="' + tipo.escala_maxima + '">' + tipo.nome + pesoTexto + escalaTexto + '</option>');
                        });
                    } else {
                        $tipoSelect.append('<option value="">Nenhum tipo de avaliação cadastrado</option>');
                    }
                    $('.loading-spinner').hide();
                },
                error: function() {
                    $('.loading-spinner').hide();
                    alert('Erro ao carregar tipos de avaliação');
                }
            });
        }
        
        $('#turma_id_select').on('change', function() {
            var turmaId = $(this).val();
            var selectedOption = $(this).find('option:selected');
            var sala = selectedOption.data('sala');
            $('#sala_input').val(sala || '');
            if (turmaId) {
                $('.loading-spinner').show();
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    data: { get_disciplinas: 1, turma_id: turmaId },
                    dataType: 'json',
                    success: function(data) {
                        var $disciplinaSelect = $('#disciplina_id_select');
                        $disciplinaSelect.empty();
                        $disciplinaSelect.append('<option value="">Selecione uma disciplina...</option>');
                        $.each(data, function(index, disciplina) {
                            $disciplinaSelect.append('<option value="' + disciplina.id + '">' + disciplina.nome + (disciplina.codigo ? ' (' + disciplina.codigo + ')' : '') + '</option>');
                        });
                        $('.loading-spinner').hide();
                    },
                    error: function() { $('.loading-spinner').hide(); alert('Erro ao carregar disciplinas'); }
                });
            } else {
                $('#disciplina_id_select').empty();
                $('#disciplina_id_select').append('<option value="">Primeiro selecione a turma...</option>');
            }
        });
        
        $('#tipo_prova_select').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var peso = selectedOption.data('peso');
            var escala = selectedOption.data('escala');
            if (peso) $('#valor_total').val(peso);
            if (escala) { $('#valor_total').attr('max', escala); $('#valor_total').attr('placeholder', 'Valor até ' + escala); }
            else { $('#valor_total').attr('max', 20); $('#valor_total').attr('placeholder', 'Ex: 20.00'); }
        });
        
        $(document).ready(function() { carregarTiposAvaliacao(); });
        
        function verDetalhes(id, titulo, data, horaInicio, horaFim, disciplina, descricao, valor, sala, instrucoes, material, docente, periodo) {
            const modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
            const dataFormatada = new Date(data).toLocaleDateString('pt-AO');
            let html = `<div class="mb-3"><strong>Título:</strong><p class="mt-1">${titulo}</p></div><hr><div class="row"><div class="col-6 mb-2"><strong>Data:</strong><br>${dataFormatada}</div><div class="col-6 mb-2"><strong>Horário:</strong><br>${horaInicio} - ${horaFim}</div><div class="col-6 mb-2"><strong>Disciplina:</strong><br>${disciplina}</div><div class="col-6 mb-2"><strong>Período:</strong><br>${periodo}</div><div class="col-6 mb-2"><strong>Valor:</strong><br>${parseFloat(valor).toLocaleString('pt-AO', {minimumFractionDigits: 2, maximumFractionDigits: 2})} valores</div>`;
            if (sala && sala !== '') html += `<div class="col-6 mb-2"><strong>Sala:</strong><br>${sala}</div>`;
            if (docente && docente !== '') html += `<div class="col-6 mb-2"><strong>Docente Responsável:</strong><br>${docente}</div>`;
            html += `</div>`;
            if (descricao && descricao !== '') html += `<hr><div class="mb-2"><strong>Descrição:</strong><p class="mt-1">${descricao.replace(/\n/g, '<br>')}</p></div>`;
            if (instrucoes && instrucoes !== '') html += `<hr><div class="mb-2"><strong>Instruções:</strong><p class="mt-1">${instrucoes.replace(/\n/g, '<br>')}</p></div>`;
            if (material && material !== '') html += `<hr><div class="mb-2"><strong>Material Permitido:</strong><p class="mt-1">${material}</p></div>`;
            html += `<hr><div class="alert alert-info mt-2"><i class="fas fa-info-circle"></i> <small>Esta prova foi agendada pelo sistema de gestão escolar.</small></div>`;
            document.getElementById('detalhesConteudo').innerHTML = html;
            modal.show();
        }
    </script>
</body>
</html>