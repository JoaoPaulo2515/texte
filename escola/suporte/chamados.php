<?php
// escola/suporte/chamados.php - Sistema de Chamados de Suporte

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_email = $_SESSION['usuario_email'] ?? '';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// Buscar funcionário
$funcionario_id = null;
$sql_funcionario = "SELECT id FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['id'] ?? null;

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';
$prioridade_filtro = isset($_GET['prioridade']) ? $_GET['prioridade'] : 'todas';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Criar chamado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_chamado'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = $_POST['categoria'] ?? 'outro';
    $prioridade = $_POST['prioridade'] ?? 'media';
    
    if (empty($titulo)) {
        $error = "O título do chamado é obrigatório.";
    } elseif (empty($descricao)) {
        $error = "A descrição do chamado é obrigatória.";
    } else {
        try {
            $numero_chamado = 'CHAM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $sql = "INSERT INTO chamados_suporte (numero_chamado, escola_id, funcionario_id, usuario_id, titulo, descricao, categoria, prioridade, status, data_abertura, created_at) VALUES (:numero, :escola_id, :funcionario_id, :usuario_id, :titulo, :descricao, :categoria, :prioridade, 'aberto', NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':numero' => $numero_chamado,
                ':escola_id' => $escola_id,
                ':funcionario_id' => $funcionario_id,
                ':usuario_id' => $usuario_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':categoria' => $categoria,
                ':prioridade' => $prioridade
            ]);
            $success = "Chamado #$numero_chamado criado com sucesso!";
        } catch (Exception $e) {
            $error = "Erro ao criar chamado: " . $e->getMessage();
        }
    }
}

// Responder chamado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['responder_chamado'])) {
    $chamado_id = (int)$_POST['chamado_id'];
    $mensagem = trim($_POST['mensagem'] ?? '');
    $anexo = null;
    
    if (empty($mensagem)) {
        $error = "A mensagem de resposta é obrigatória.";
    } else {
        try {
            if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] == 0) {
                $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'txt'];
                $extensao = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                if (in_array($extensao, $extensoes_permitidas)) {
                    $diretorio = __DIR__ . '/../../uploads/chamados/';
                    if (!file_exists($diretorio)) mkdir($diretorio, 0777, true);
                    $nome_arquivo = 'chamado_' . $chamado_id . '_' . time() . '.' . $extensao;
                    if (move_uploaded_file($_FILES['anexo']['tmp_name'], $diretorio . $nome_arquivo)) {
                        $anexo = $nome_arquivo;
                    }
                }
            }
            
            $sql = "INSERT INTO chamados_respostas (chamado_id, usuario_id, mensagem, anexo, data_resposta) VALUES (:chamado_id, :usuario_id, :mensagem, :anexo, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':chamado_id' => $chamado_id, ':usuario_id' => $usuario_id, ':mensagem' => $mensagem, ':anexo' => $anexo]);
            
            if (isset($_POST['fechar_chamado']) && $_POST['fechar_chamado'] == '1') {
                $sql_update = "UPDATE chamados_suporte SET status = 'fechado', data_fechamento = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $chamado_id]);
                $success = "Chamado respondido e fechado com sucesso!";
            } else {
                $sql_update = "UPDATE chamados_suporte SET status = 'em_andamento', updated_at = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $chamado_id]);
                $success = "Resposta adicionada com sucesso!";
            }
        } catch (Exception $e) {
            $error = "Erro ao responder chamado: " . $e->getMessage();
        }
    }
}

// Buscar chamados
$where_conditions = [];
$params = [];

if (!$is_admin && !$is_suporte) {
    $where_conditions[] = "c.funcionario_id = :funcionario_id";
    $params[':funcionario_id'] = $funcionario_id;
}

if ($status_filtro != 'todos') { $where_conditions[] = "c.status = :status"; $params[':status'] = $status_filtro; }
if ($categoria_filtro != 'todas') { $where_conditions[] = "c.categoria = :categoria"; $params[':categoria'] = $categoria_filtro; }
if ($prioridade_filtro != 'todas') { $where_conditions[] = "c.prioridade = :prioridade"; $params[':prioridade'] = $prioridade_filtro; }
if (!empty($busca)) { $where_conditions[] = "(c.titulo LIKE :busca OR c.descricao LIKE :busca OR c.numero_chamado LIKE :busca)"; $params[':busca'] = "%$busca%"; }

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_chamados = "SELECT c.*, u.nome as usuario_nome, (SELECT COUNT(*) FROM chamados_respostas WHERE chamado_id = c.id) as total_respostas FROM chamados_suporte c LEFT JOIN usuarios u ON u.id = c.usuario_id $where_sql ORDER BY CASE c.prioridade WHEN 'alta' THEN 1 WHEN 'media' THEN 2 WHEN 'baixa' THEN 3 END, c.data_abertura DESC";
$stmt_chamados = $conn->prepare($sql_chamados);
$stmt_chamados->execute($params);
$chamados = $stmt_chamados->fetchAll(PDO::FETCH_ASSOC);

// Detalhe do chamado
$chamado_detalhe = null;
$respostas = [];
$chamado_id_detalhe = isset($_GET['ver']) ? (int)$_GET['ver'] : (isset($_POST['chamado_id']) ? (int)$_POST['chamado_id'] : 0);

if ($chamado_id_detalhe > 0) {
    $sql_detalhe = "SELECT c.*, u.nome as usuario_nome FROM chamados_suporte c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = :id";
    $stmt_detalhe = $conn->prepare($sql_detalhe);
    $stmt_detalhe->execute([':id' => $chamado_id_detalhe]);
    $chamado_detalhe = $stmt_detalhe->fetch(PDO::FETCH_ASSOC);
    if ($chamado_detalhe) {
        $sql_respostas = "SELECT r.*, u.nome as usuario_nome FROM chamados_respostas r LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.chamado_id = :chamado_id ORDER BY r.data_resposta ASC";
        $stmt_respostas = $conn->prepare($sql_respostas);
        $stmt_respostas->execute([':chamado_id' => $chamado_id_detalhe]);
        $respostas = $stmt_respostas->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getStatusChamado($status) {
    switch($status){
        case 'aberto': return '<span class="badge bg-danger"><i class="fas fa-circle"></i> Aberto</span>';
        case 'em_andamento': return '<span class="badge bg-warning text-dark"><i class="fas fa-spinner"></i> Em Andamento</span>';
        case 'respondido': return '<span class="badge bg-info"><i class="fas fa-reply"></i> Respondido</span>';
        case 'fechado': return '<span class="badge bg-success"><i class="fas fa-check"></i> Fechado</span>';
        default: return '<span class="badge bg-secondary">'.$status.'</span>';
    }
}

function getPrioridadeBadge($prioridade) {
    switch($prioridade){
        case 'alta': return '<span class="badge bg-danger"><i class="fas fa-arrow-up"></i> Alta</span>';
        case 'media': return '<span class="badge bg-warning text-dark"><i class="fas fa-minus"></i> Média</span>';
        case 'baixa': return '<span class="badge bg-success"><i class="fas fa-arrow-down"></i> Baixa</span>';
        default: return '<span class="badge bg-secondary">'.$prioridade.'</span>';
    }
}

function getCategoriaIcone($categoria) {
    switch($categoria){
        case 'tecnico': return '<i class="fas fa-laptop-code"></i>';
        case 'administrativo': return '<i class="fas fa-file-alt"></i>';
        case 'financeiro': return '<i class="fas fa-money-bill"></i>';
        case 'academico': return '<i class="fas fa-graduation-cap"></i>';
        default: return '<i class="fas fa-question-circle"></i>';
    }
}

function getCategoriaTexto($categoria) {
    switch($categoria){
        case 'tecnico': return 'Técnico';
        case 'administrativo': return 'Administrativo';
        case 'financeiro': return 'Financeiro';
        case 'academico': return 'Acadêmico';
        default: return 'Outro';
    }
}

function formatarDataHora($data) { return $data ? date('d/m/Y H:i', strtotime($data)) : '-'; }
function timeAgo($datetime) { $diff = time() - strtotime($datetime); if($diff<60) return 'agora'; if($diff<3600) return round($diff/60).' min'; if($diff<86400) return round($diff/3600).'h'; if($diff<604800) return round($diff/86400).'d'; return date('d/m/Y', strtotime($datetime)); }
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamados de Suporte | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary-custom { background: #006B3E; border: none; }
        .btn-primary-custom:hover { background: #004d2d; }
        .chamado-card { transition: all 0.3s ease; cursor: pointer; }
        .chamado-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .chamado-card.alta { border-left: 4px solid #dc3545; }
        .chamado-card.media { border-left: 4px solid #ffc107; }
        .chamado-card.baixa { border-left: 4px solid #28a745; }
        .resposta-item { border-left: 3px solid #006B3E; margin-bottom: 15px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul, .ajuda-section ol { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div><h2><i class="fas fa-headset"></i> Chamados de Suporte</h2><p>Solicite assistência técnica e administrativa</p></div>
                <div><button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="fas fa-plus"></i> Novo Chamado</button><a href="../dashboard.php" class="btn-voltar btn ms-2"><i class="fas fa-arrow-left"></i> Voltar</a></div>
            </div>
        </div>
        
        <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <!-- Filtros -->
        <div class="card"><div class="card-body"><form method="GET" class="row g-3"><div class="col-md-3"><label class="filter-label">Status</label><select name="status" class="form-select" onchange="this.form.submit()"><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option><option value="aberto" <?php echo $status_filtro=='aberto'?'selected':''; ?>>Aberto</option><option value="em_andamento" <?php echo $status_filtro=='em_andamento'?'selected':''; ?>>Em Andamento</option><option value="respondido" <?php echo $status_filtro=='respondido'?'selected':''; ?>>Respondido</option><option value="fechado" <?php echo $status_filtro=='fechado'?'selected':''; ?>>Fechado</option></select></div><div class="col-md-3"><label class="filter-label">Categoria</label><select name="categoria" class="form-select" onchange="this.form.submit()"><option value="todas" <?php echo $categoria_filtro=='todas'?'selected':''; ?>>Todas</option><option value="tecnico" <?php echo $categoria_filtro=='tecnico'?'selected':''; ?>>Técnico</option><option value="administrativo" <?php echo $categoria_filtro=='administrativo'?'selected':''; ?>>Administrativo</option><option value="financeiro" <?php echo $categoria_filtro=='financeiro'?'selected':''; ?>>Financeiro</option><option value="academico" <?php echo $categoria_filtro=='academico'?'selected':''; ?>>Acadêmico</option></select></div><div class="col-md-3"><label class="filter-label">Prioridade</label><select name="prioridade" class="form-select" onchange="this.form.submit()"><option value="todas" <?php echo $prioridade_filtro=='todas'?'selected':''; ?>>Todas</option><option value="alta" <?php echo $prioridade_filtro=='alta'?'selected':''; ?>>Alta</option><option value="media" <?php echo $prioridade_filtro=='media'?'selected':''; ?>>Média</option><option value="baixa" <?php echo $prioridade_filtro=='baixa'?'selected':''; ?>>Baixa</option></select></div><div class="col-md-3"><label class="filter-label">Buscar</label><div class="input-group"><input type="text" name="busca" class="form-control" placeholder="Título..." value="<?php echo htmlspecialchars($busca); ?>"><button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i></button></div></div></form></div></div>
        
        <!-- Lista de Chamados -->
        <div class="row">
            <?php if (empty($chamados)): ?>
                <div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i><h4>Nenhum chamado encontrado</h4><button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovoChamado"><i class="fas fa-plus"></i> Abrir Chamado</button></div></div></div>
            <?php else: ?>
                <?php foreach ($chamados as $chamado): ?>
                <div class="col-md-6 col-lg-4 mb-3"><div class="card chamado-card <?php echo $chamado['prioridade']; ?>" onclick="verChamado(<?php echo $chamado['id']; ?>)"><div class="card-body"><div class="d-flex justify-content-between align-items-start mb-2"><div><?php echo getCategoriaIcone($chamado['categoria']); ?> <strong class="ms-1"><?php echo getCategoriaTexto($chamado['categoria']); ?></strong></div><?php echo getPrioridadeBadge($chamado['prioridade']); ?></div><h6 class="mb-1"><?php echo htmlspecialchars($chamado['titulo']); ?></h6><small class="text-muted">#<?php echo htmlspecialchars($chamado['numero_chamado']); ?></small><p class="small text-muted mt-2 mb-2"><?php echo htmlspecialchars(substr($chamado['descricao'], 0, 80)) . '...'; ?></p><div class="d-flex justify-content-between align-items-center mt-2"><div><?php echo getStatusChamado($chamado['status']); ?></div><div class="text-end"><small class="text-muted"><i class="fas fa-clock"></i> <?php echo timeAgo($chamado['data_abertura']); ?></small></div></div></div></div></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal Novo Chamado -->
    <div class="modal fade" id="modalNovoChamado" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> Abrir Novo Chamado</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><div class="mb-3"><label class="form-label">Título</label><input type="text" name="titulo" class="form-control" required></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Categoria</label><select name="categoria" class="form-select"><option value="tecnico">🖥️ Técnico</option><option value="administrativo">📄 Administrativo</option><option value="financeiro">💰 Financeiro</option><option value="academico">🎓 Acadêmico</option><option value="outro">❓ Outro</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Prioridade</label><select name="prioridade" class="form-select"><option value="baixa">🟢 Baixa</option><option value="media" selected>🟡 Média</option><option value="alta">🔴 Alta</option></select></div></div><div class="mb-3"><label class="form-label">Descrição</label><textarea name="descricao" class="form-control" rows="5" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="criar_chamado" class="btn btn-primary-custom"><i class="fas fa-paper-plane"></i> Enviar Chamado</button></div></form></div></div></div>
    
    <!-- Modal Visualizar Chamado -->
    <?php if ($chamado_detalhe): ?>
    <div class="modal fade" id="modalVerChamado" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title">Chamado #<?php echo htmlspecialchars($chamado_detalhe['numero_chamado']); ?> - <?php echo htmlspecialchars($chamado_detalhe['titulo']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="fecharModal()"></button></div><div class="modal-body"><div class="row mb-4"><div class="col-md-3"><strong>Status:</strong><br><?php echo getStatusChamado($chamado_detalhe['status']); ?></div><div class="col-md-3"><strong>Prioridade:</strong><br><?php echo getPrioridadeBadge($chamado_detalhe['prioridade']); ?></div><div class="col-md-3"><strong>Categoria:</strong><br><?php echo getCategoriaIcone($chamado_detalhe['categoria']); ?> <?php echo getCategoriaTexto($chamado_detalhe['categoria']); ?></div><div class="col-md-3"><strong>Aberto em:</strong><br><?php echo formatarDataHora($chamado_detalhe['data_abertura']); ?></div></div><div class="card mb-4"><div class="card-header"><strong>Descrição do Problema</strong></div><div class="card-body"><p><?php echo nl2br(htmlspecialchars($chamado_detalhe['descricao'])); ?></p><small class="text-muted">Aberto por: <?php echo htmlspecialchars($chamado_detalhe['usuario_nome'] ?? 'Usuário'); ?></small></div></div><div class="card"><div class="card-header"><strong>Conversa</strong> <small>(<?php echo count($respostas); ?> respostas)</small></div><div class="card-body" style="max-height:400px;overflow-y:auto;"><?php if(empty($respostas)): ?><div class="text-center text-muted py-4"><i class="fas fa-comment-slash fa-2x mb-2"></i><p>Nenhuma resposta ainda.</p></div><?php else: foreach($respostas as $resp): ?><div class="resposta-item"><div class="d-flex justify-content-between mb-2"><strong><?php echo $resp['usuario_id']==$usuario_id ? '<i class="fas fa-user-circle"></i> Você' : '<i class="fas fa-headset"></i> Suporte'; ?></strong><small><?php echo formatarDataHora($resp['data_resposta']); ?></small></div><p><?php echo nl2br(htmlspecialchars($resp['mensagem'])); ?></p><?php if($resp['anexo']): ?><div><a href="../../uploads/chamados/<?php echo $resp['anexo']; ?>" target="_blank"><i class="fas fa-paperclip"></i> Anexo</a></div><?php endif; ?></div><?php endforeach; endif; ?></div></div><?php if($chamado_detalhe['status']!='fechado'): ?><div class="card mt-3"><div class="card-header"><strong>Responder</strong></div><div class="card-body"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="chamado_id" value="<?php echo $chamado_detalhe['id']; ?>"><textarea name="mensagem" class="form-control" rows="4" required></textarea><div class="mt-3"><label>Anexar arquivo</label><input type="file" name="anexo" class="form-control"></div><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="fechar_chamado" value="1" id="fechar"><label class="form-check-label" for="fechar">Marcar como resolvido</label></div><button type="submit" name="responder_chamado" class="btn btn-primary-custom mt-3"><i class="fas fa-paper-plane"></i> Enviar Resposta</button></form></div></div><?php else: ?><div class="alert alert-success mt-3">Este chamado está fechado.</div><?php endif; ?></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="fecharModal()">Fechar</button></div></div></div></div>
    <script>document.addEventListener('DOMContentLoaded',function(){<?php if($chamado_id_detalhe>0): ?>var modal=new bootstrap.Modal(document.getElementById('modalVerChamado'));modal.show();<?php endif; ?>});function fecharModal(){var url=new URL(window.location.href);url.searchParams.delete('ver');window.history.pushState({},'',url);}</script>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Chamados de Suporte</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-ticket-alt"></i> Como abrir um chamado</h5><ol><li>Clique no botão "Novo Chamado"</li><li>Informe um título claro do problema</li><li>Selecione a categoria</li><li>Defina a prioridade</li><li>Descreva detalhadamente o problema</li><li>Clique em "Enviar Chamado"</li></ol></div><div class="ajuda-section"><h5><i class="fas fa-clock"></i> Tempo de resposta</h5><ul><li><span class="badge bg-danger">Prioridade Alta</span> - até 4 horas úteis</li><li><span class="badge bg-warning text-dark">Prioridade Média</span> - até 24 horas úteis</li><li><span class="badge bg-success">Prioridade Baixa</span> - até 48 horas úteis</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-comments"></i> Acompanhamento</h5><ul><li>Você recebe notificações por email</li><li>Pode anexar arquivos às respostas</li><li>Marque como resolvido quando o problema for solucionado</li></ul></div><div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> <strong>Não encontrou solução?</strong> Ligue para nossa central: <strong>+244 923 456 789</strong></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        function verChamado(id) { window.location.href = 'chamados.php?ver=' + id; }
    </script>
</body>
</html>