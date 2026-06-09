<?php
// escola/suporte/faq.php - FAQ (Perguntas Frequentes)

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
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_suporte = ($usuario_tipo == 'suporte' || $papel == 'suporte');

// Filtros
$categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$success = '';
$error = '';

// Admin: Adicionar FAQ
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_faq'])) {
    $pergunta = trim($_POST['pergunta'] ?? '');
    $resposta = trim($_POST['resposta'] ?? '');
    $categoria = $_POST['categoria'] ?? 'geral';
    $ordem = (int)($_POST['ordem'] ?? 0);
    
    if (empty($pergunta)) $error = "A pergunta é obrigatória.";
    elseif (empty($resposta)) $error = "A resposta é obrigatória.";
    else {
        try {
            $sql = "INSERT INTO faq (pergunta, resposta, categoria, ordem, escola_id, ativo, created_at) VALUES (:pergunta, :resposta, :categoria, :ordem, :escola_id, 1, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':pergunta' => $pergunta, ':resposta' => $resposta, ':categoria' => $categoria, ':ordem' => $ordem, ':escola_id' => $escola_id]);
            $success = "FAQ adicionado com sucesso!";
        } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
    }
}

// Admin: Editar FAQ
if (($is_admin || $is_suporte) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_faq'])) {
    $faq_id = (int)$_POST['faq_id'];
    $pergunta = trim($_POST['pergunta'] ?? '');
    $resposta = trim($_POST['resposta'] ?? '');
    $categoria = $_POST['categoria'] ?? 'geral';
    $ordem = (int)($_POST['ordem'] ?? 0);
    
    if (empty($pergunta)) $error = "A pergunta é obrigatória.";
    elseif (empty($resposta)) $error = "A resposta é obrigatória.";
    else {
        try {
            $sql = "UPDATE faq SET pergunta = :pergunta, resposta = :resposta, categoria = :categoria, ordem = :ordem WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':pergunta' => $pergunta, ':resposta' => $resposta, ':categoria' => $categoria, ':ordem' => $ordem, ':id' => $faq_id, ':escola_id' => $escola_id]);
            $success = "FAQ atualizado com sucesso!";
        } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
    }
}

// Admin: Excluir FAQ
if (($is_admin || $is_suporte) && isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $faq_id = (int)$_GET['excluir'];
    try {
        $sql = "DELETE FROM faq WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $faq_id, ':escola_id' => $escola_id]);
        $success = "FAQ excluído com sucesso!";
    } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
}

// Admin: Alternar status
if (($is_admin || $is_suporte) && isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $faq_id = (int)$_GET['toggle'];
    try {
        $sql = "UPDATE faq SET ativo = NOT ativo WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $faq_id, ':escola_id' => $escola_id]);
        $success = "Status alterado!";
    } catch (Exception $e) { $error = "Erro: " . $e->getMessage(); }
}

// Buscar FAQs
$where_conditions = [];
$params = [':escola_id' => $escola_id];

if (!$is_admin && !$is_suporte) $where_conditions[] = "ativo = 1";
if ($categoria_filtro != 'todas') { $where_conditions[] = "categoria = :categoria"; $params[':categoria'] = $categoria_filtro; }
if (!empty($busca)) { $where_conditions[] = "(pergunta LIKE :busca OR resposta LIKE :busca)"; $params[':busca'] = "%$busca%"; }
$where_conditions[] = "escola_id = :escola_id";

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql_faq = "SELECT * FROM faq $where_sql ORDER BY ordem ASC, id ASC";
$stmt_faq = $conn->prepare($sql_faq);
$stmt_faq->execute($params);
$faqs = $stmt_faq->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$faqs_por_categoria = [];
foreach ($faqs as $faq) { $cat = $faq['categoria']; if (!isset($faqs_por_categoria[$cat])) $faqs_por_categoria[$cat] = []; $faqs_por_categoria[$cat][] = $faq; }

$categorias_disponiveis = [
    'geral' => ['nome' => 'Geral', 'icone' => 'fas fa-globe'],
    'sistema' => ['nome' => 'Sistema', 'icone' => 'fas fa-desktop'],
    'notas' => ['nome' => 'Notas', 'icone' => 'fas fa-edit'],
    'matricula' => ['nome' => 'Matrículas', 'icone' => 'fas fa-user-graduate'],
    'financeiro' => ['nome' => 'Financeiro', 'icone' => 'fas fa-money-bill'],
    'tecnico' => ['nome' => 'Suporte Técnico', 'icone' => 'fas fa-laptop-code'],
    'academico' => ['nome' => 'Acadêmico', 'icone' => 'fas fa-book']
];

function getCategoriaInfo($categoria) { global $categorias_disponiveis; return $categorias_disponiveis[$categoria] ?? $categorias_disponiveis['geral']; }
function getStatusBadge($ativo) { return $ativo == 1 ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>'; }
function formatarData($data) { return $data ? date('d/m/Y', strtotime($data)) : '-'; }
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ | SIGE Angola</title>
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
        .faq-item { border-bottom: 1px solid #e9ecef; }
        .faq-question { padding: 20px; cursor: pointer; transition: background 0.3s ease; }
        .faq-question:hover { background: #f8f9fa; }
        .faq-answer { display: none; padding: 0 20px 20px 20px; background: #f8f9fa; border-radius: 0 0 10px 10px; }
        .faq-answer.show { display: block; }
        .categoria-card { transition: all 0.3s ease; cursor: pointer; text-align: center; padding: 15px; border-radius: 12px; }
        .categoria-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .categoria-card.active { background: #006B3E; color: white; }
        .categoria-card.active a { color: white; }
        .categoria-icon { font-size: 2rem; margin-bottom: 10px; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .search-box { max-width: 400px; margin: 0 auto; }
        
        .btn-ajuda { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 1000; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .btn-ajuda:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text { position: absolute; right: 70px; background: #333; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; white-space: nowrap; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) { .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; } .btn-ajuda i { font-size: 24px; } }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .admin-actions { position: absolute; right: 15px; top: 15px; }
        .faq-item { position: relative; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div><h2><i class="fas fa-question-circle"></i> Perguntas Frequentes (FAQ)</h2><p>Encontre respostas para as dúvidas mais comuns</p></div>
                <div><a href="../dashboard.php" class="btn-voltar btn"><i class="fas fa-arrow-left"></i> Voltar</a><?php if($is_admin||$is_suporte): ?><button class="btn btn-light ms-2" data-bs-toggle="modal" data-bs-target="#modalAdicionarFAQ"><i class="fas fa-plus"></i> Adicionar FAQ</button><?php endif; ?></div>
            </div>
        </div>
        
        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div><?php endif; ?>
        
        <!-- Busca -->
        <div class="card"><div class="card-body"><form method="GET" class="search-box"><div class="input-group"><input type="text" name="busca" class="form-control" placeholder="Buscar perguntas..." value="<?php echo htmlspecialchars($busca); ?>"><input type="hidden" name="categoria" value="<?php echo $categoria_filtro; ?>"><button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i> Buscar</button><?php if(!empty($busca)||$categoria_filtro!='todas'): ?><a href="faq.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i> Limpar</a><?php endif; ?></div></form></div></div>
        
        <!-- Categorias -->
        <div class="row g-2 mb-4">
            <div class="col-md-3 col-6"><div class="categoria-card <?php echo $categoria_filtro=='todas'?'active':''; ?>" onclick="filtrarCategoria('todas')"><div class="categoria-icon"><i class="fas fa-list-ul"></i></div><div>Todas</div><small>(<?php echo count($faqs); ?>)</small></div></div>
            <?php foreach($categorias_disponiveis as $key=>$cat): $count = isset($faqs_por_categoria[$key]) ? count($faqs_por_categoria[$key]) : 0; if($count>0||($is_admin||$is_suporte)): ?><div class="col-md-3 col-6"><div class="categoria-card <?php echo $categoria_filtro==$key?'active':''; ?>" onclick="filtrarCategoria('<?php echo $key; ?>')"><div class="categoria-icon"><i class="<?php echo $cat['icone']; ?>"></i></div><div><?php echo $cat['nome']; ?></div><small>(<?php echo $count; ?>)</small></div></div><?php endif; endforeach; ?>
        </div>
        
        <!-- FAQs -->
        <?php if(empty($faqs)): ?>
            <div class="card"><div class="card-body text-center py-5"><i class="fas fa-question-circle fa-3x text-muted mb-3"></i><h4>Nenhuma pergunta encontrada</h4><?php if($is_admin||$is_suporte): ?><button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAdicionarFAQ"><i class="fas fa-plus"></i> Adicionar FAQ</button><?php endif; ?></div></div>
        <?php else: ?>
            <?php $display_faqs = ($categoria_filtro!='todas' && isset($faqs_por_categoria[$categoria_filtro])) ? [$categoria_filtro=>$faqs_por_categoria[$categoria_filtro]] : $faqs_por_categoria; ?>
            <?php foreach($display_faqs as $cat_key=>$faqs_lista): $cat_info = getCategoriaInfo($cat_key); ?>
            <div class="card mb-4"><div class="card-header"><h5 class="mb-0"><i class="<?php echo $cat_info['icone']; ?> me-2"></i><?php echo $cat_info['nome']; ?> <span class="badge bg-light text-dark ms-2"><?php echo count($faqs_lista); ?></span></h5></div><div class="card-body p-0">
                <?php foreach($faqs_lista as $faq): ?>
                <div class="faq-item" id="faq-<?php echo $faq['id']; ?>"><div class="faq-question" onclick="toggleResposta(this)"><div class="d-flex justify-content-between align-items-center"><div><i class="fas fa-chevron-right me-2 text-muted"></i><h5 class="d-inline"><?php echo htmlspecialchars($faq['pergunta']); ?></h5><?php if(($is_admin||$is_suporte) && $faq['ativo']==0): ?> <?php echo getStatusBadge($faq['ativo']); ?><?php endif; ?></div><?php if($is_admin||$is_suporte): ?><div class="admin-actions"><button class="btn btn-sm btn-outline-primary me-1" onclick="event.stopPropagation(); editarFAQ(<?php echo $faq['id']; ?>, '<?php echo addslashes($faq['pergunta']); ?>', '<?php echo addslashes($faq['resposta']); ?>', '<?php echo $faq['categoria']; ?>', <?php echo $faq['ordem']; ?>)"><i class="fas fa-edit"></i></button><a href="?excluir=<?php echo $faq['id']; ?>" class="btn btn-sm btn-outline-danger me-1" onclick="return confirm('Tem certeza?')"><i class="fas fa-trash"></i></a><a href="?toggle=<?php echo $faq['id']; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-<?php echo $faq['ativo']==1?'eye-slash':'eye'; ?>"></i></a></div><?php endif; ?></div></div><div class="faq-answer"><div class="border-top pt-3"><?php echo nl2br(htmlspecialchars($faq['resposta'])); ?><div class="mt-3 text-muted small"><i class="fas fa-clock"></i> Atualizado: <?php echo formatarData($faq['updated_at']??$faq['created_at']); ?></div><button class="btn btn-sm btn-link mt-2" onclick="copiarLink(<?php echo $faq['id']; ?>)"><i class="fas fa-link"></i> Copiar link</button></div></div></div>
                <?php endforeach; ?>
            </div></div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="card"><div class="card-body text-center py-4"><i class="fas fa-headset fa-2x text-primary mb-3"></i><h5>Não encontrou o que procurava?</h5><p>Entre em contato com nossa equipe de suporte.</p><a href="chamados.php" class="btn btn-primary-custom"><i class="fas fa-ticket-alt"></i> Abrir Chamado</a></div></div>
    </div>
    
    <!-- Modais Admin -->
    <?php if($is_admin||$is_suporte): ?>
    <div class="modal fade" id="modalAdicionarFAQ" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> Adicionar FAQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><div class="mb-3"><label class="form-label">Pergunta</label><input type="text" name="pergunta" class="form-control" required></div><div class="mb-3"><label class="form-label">Resposta</label><textarea name="resposta" class="form-control" rows="5" required></textarea></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Categoria</label><select name="categoria" class="form-select"><option value="geral">Geral</option><option value="sistema">Sistema</option><option value="notas">Notas</option><option value="matricula">Matrículas</option><option value="financeiro">Financeiro</option><option value="tecnico">Suporte Técnico</option><option value="academico">Acadêmico</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Ordem</label><input type="number" name="ordem" class="form-control" value="0"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="adicionar_faq" class="btn btn-primary-custom">Salvar</button></div></form></div></div></div>
    
    <div class="modal fade" id="modalEditarFAQ" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-edit"></i> Editar FAQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><input type="hidden" name="faq_id" id="edit_faq_id"><div class="modal-body"><div class="mb-3"><label class="form-label">Pergunta</label><input type="text" name="pergunta" id="edit_pergunta" class="form-control" required></div><div class="mb-3"><label class="form-label">Resposta</label><textarea name="resposta" id="edit_resposta" class="form-control" rows="5" required></textarea></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Categoria</label><select name="categoria" id="edit_categoria" class="form-select"><option value="geral">Geral</option><option value="sistema">Sistema</option><option value="notas">Notas</option><option value="matricula">Matrículas</option><option value="financeiro">Financeiro</option><option value="tecnico">Suporte Técnico</option><option value="academico">Acadêmico</option></select></div><div class="col-md-6 mb-3"><label class="form-label">Ordem</label><input type="number" name="ordem" id="edit_ordem" class="form-control" value="0"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="editar_faq" class="btn btn-primary-custom">Atualizar</button></div></form></div></div></div>
    <?php endif; ?>
    
    <!-- Botão de Ajuda -->
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question"></i><span class="tooltip-text">Precisa de ajuda?</span></button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header modal-header-custom"><h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - FAQ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="ajuda-section"><h5><i class="fas fa-search"></i> Como encontrar respostas</h5><ul><li><strong>Busca:</strong> Digite palavras-chave para encontrar perguntas específicas</li><li><strong>Categorias:</strong> Filtre por tema</li><li><strong>Clique na pergunta:</strong> Expande para ver a resposta completa</li></ul></div><div class="ajuda-section"><h5><i class="fas fa-link"></i> Compartilhar respostas</h5><p>Clique em "Copiar link" para compartilhar uma FAQ específica.</p></div><div class="ajuda-section"><h5><i class="fas fa-shield-alt"></i> Para Administradores</h5><ul><li>Adicionar, editar e excluir FAQs</li><li>Ativar/desativar perguntas</li><li>Controlar ordem de exibição</li></ul></div><div class="alert alert-info mt-3"><i class="fas fa-lightbulb"></i> <strong>Não encontrou?</strong> <a href="chamados.php" class="alert-link">Abra um chamado de suporte</a></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><a href="chamados.php" class="btn btn-primary-custom"><i class="fas fa-headset"></i> Abrir Chamado</a></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() { document.querySelector('.sidebar')?.classList.toggle('active'); document.querySelector('.main-content')?.classList.toggle('active'); });
        document.getElementById('btnAjuda')?.addEventListener('click', function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
        
        function toggleResposta(element) { const answer = element.nextElementSibling; const icon = element.querySelector('.fa-chevron-right'); if(answer.classList.contains('show')){ answer.classList.remove('show'); icon.style.transform = 'rotate(0deg)'; }else{ document.querySelectorAll('.faq-answer.show').forEach(a=>{a.classList.remove('show'); if(a.previousElementSibling?.querySelector('.fa-chevron-right')) a.previousElementSibling.querySelector('.fa-chevron-right').style.transform = 'rotate(0deg)'; }); answer.classList.add('show'); icon.style.transform = 'rotate(90deg)'; } }
        function filtrarCategoria(categoria){ const url = new URL(window.location.href); if(categoria==='todas') url.searchParams.delete('categoria'); else url.searchParams.set('categoria', categoria); window.location.href = url.toString(); }
        function copiarLink(faqId){ const url = window.location.href.split('#')[0] + '#faq-' + faqId; navigator.clipboard.writeText(url).then(()=>alert('Link copiado!')); }
        <?php if($is_admin||$is_suporte): ?>
        function editarFAQ(id, pergunta, resposta, categoria, ordem){ document.getElementById('edit_faq_id').value=id; document.getElementById('edit_pergunta').value=pergunta; document.getElementById('edit_resposta').value=resposta; document.getElementById('edit_categoria').value=categoria; document.getElementById('edit_ordem').value=ordem; new bootstrap.Modal(document.getElementById('modalEditarFAQ')).show(); }
        <?php endif; ?>
        if(window.location.hash){ const faqId = window.location.hash.replace('#faq-', ''); const faqElement = document.getElementById(`faq-${faqId}`); if(faqElement){ const questionDiv = faqElement.querySelector('.faq-question'); if(questionDiv) toggleResposta(questionDiv); faqElement.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
    </script>
</body>
</html>