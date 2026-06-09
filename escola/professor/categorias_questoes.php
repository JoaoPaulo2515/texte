<?php
// escola/professor/categorias_questoes.php - Gerenciar Categorias de Questões

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];
$professor_nome = $professor['professor_nome'] ?? 'Professor';

// ============================================
// VARIÁVEIS
// ============================================
$mensagem = '';
$tipo_mensagem = '';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$categoria_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Salvar categoria
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'salvar') {
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $cor = $_POST['cor'] ?? '#006B3E';
        $icone = $_POST['icone'] ?? 'fa-folder';
        
        if (empty($nome)) {
            $mensagem = 'Digite o nome da categoria.';
            $tipo_mensagem = 'danger';
        } else {
            try {
                // Verificar se já existe categoria com este nome
                $sql_check = "SELECT id FROM online_provas_categorias WHERE nome = :nome AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([':nome' => $nome, ':escola_id' => $escola_id]);
                
                if ($stmt_check->fetch()) {
                    $mensagem = 'Já existe uma categoria com este nome.';
                    $tipo_mensagem = 'danger';
                } else {
                    $sql = "INSERT INTO online_provas_categorias (nome, descricao, cor, icone, escola_id, professor_id, created_at) 
                            VALUES (:nome, :descricao, :cor, :icone, :escola_id, :professor_id, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':nome' => $nome,
                        ':descricao' => $descricao,
                        ':cor' => $cor,
                        ':icone' => $icone,
                        ':escola_id' => $escola_id,
                        ':professor_id' => $funcionario_id
                    ]);
                    
                    $mensagem = 'Categoria criada com sucesso!';
                    $tipo_mensagem = 'success';
                }
            } catch (PDOException $e) {
                $mensagem = 'Erro ao salvar: ' . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        }
    }
    
    // Atualizar categoria
    elseif ($acao == 'atualizar') {
        $categoria_id = (int)$_POST['categoria_id'];
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $cor = $_POST['cor'] ?? '#006B3E';
        $icone = $_POST['icone'] ?? 'fa-folder';
        
        if (empty($nome)) {
            $mensagem = 'Digite o nome da categoria.';
            $tipo_mensagem = 'danger';
        } else {
            try {
                // Verificar se já existe outra categoria com este nome
                $sql_check = "SELECT id FROM online_provas_categorias WHERE nome = :nome AND escola_id = :escola_id AND id != :id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([':nome' => $nome, ':escola_id' => $escola_id, ':id' => $categoria_id]);
                
                if ($stmt_check->fetch()) {
                    $mensagem = 'Já existe uma categoria com este nome.';
                    $tipo_mensagem = 'danger';
                } else {
                    $sql = "UPDATE online_provas_categorias 
                            SET nome = :nome, descricao = :descricao, cor = :cor, icone = :icone, updated_at = NOW()
                            WHERE id = :id AND escola_id = :escola_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':nome' => $nome,
                        ':descricao' => $descricao,
                        ':cor' => $cor,
                        ':icone' => $icone,
                        ':id' => $categoria_id,
                        ':escola_id' => $escola_id
                    ]);
                    
                    $mensagem = 'Categoria atualizada com sucesso!';
                    $tipo_mensagem = 'success';
                }
            } catch (PDOException $e) {
                $mensagem = 'Erro ao atualizar: ' . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        }
    }
    
    // Excluir categoria
    elseif ($acao == 'excluir') {
        $categoria_id = (int)$_POST['categoria_id'];
        
        try {
            // Verificar se existem questões com esta categoria
            $sql_check = "SELECT COUNT(*) as total FROM online_provas_questoes WHERE categoria_id = :categoria_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':categoria_id' => $categoria_id]);
            $total = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total > 0) {
                $mensagem = "Não é possível excluir esta categoria. Existem $total questão(ões) associadas a ela.";
                $tipo_mensagem = 'danger';
            } else {
                $sql = "DELETE FROM online_provas_categorias WHERE id = :id AND escola_id = :escola_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $categoria_id, ':escola_id' => $escola_id]);
                
                $mensagem = 'Categoria excluída com sucesso!';
                $tipo_mensagem = 'success';
            }
        } catch (PDOException $e) {
            $mensagem = 'Erro ao excluir: ' . $e->getMessage();
            $tipo_mensagem = 'danger';
        }
    }
}

// ============================================
// BUSCAR CATEGORIAS
// ============================================
$sql_categorias = "SELECT c.*,
                          (SELECT COUNT(*) FROM online_provas_questoes WHERE categoria_id = c.id) as total_questoes
                   FROM online_provas_categorias c
                   WHERE c.escola_id = :escola_id";

if (!empty($busca)) {
    $sql_categorias .= " AND (c.nome LIKE :busca OR c.descricao LIKE :busca)";
}

$sql_categorias .= " ORDER BY c.nome ASC";

$stmt_categorias = $conn->prepare($sql_categorias);
$params = [':escola_id' => $escola_id];
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_categorias->execute($params);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Buscar categoria para edição
$categoria_edit = null;
if ($categoria_id > 0) {
    $sql_edit = "SELECT * FROM online_provas_categorias WHERE id = :id AND escola_id = :escola_id";
    $stmt_edit = $conn->prepare($sql_edit);
    $stmt_edit->execute([':id' => $categoria_id, ':escola_id' => $escola_id]);
    $categoria_edit = $stmt_edit->fetch(PDO::FETCH_ASSOC);
}

// Estatísticas
$total_categorias = count($categorias);
$total_questoes = 0;
foreach ($categorias as $cat) {
    $total_questoes += $cat['total_questoes'];
}

// Ícones disponíveis
$icones_disponiveis = [
    'fa-folder' => 'Pasta',
    'fa-book' => 'Livro',
    'fa-graduation-cap' => 'Graduação',
    'fa-flask' => 'Ciências',
    'fa-calculator' => 'Calculadora',
    'fa-language' => 'Idiomas',
    'fa-history' => 'História',
    'fa-globe' => 'Geografia',
    'fa-music' => 'Música',
    'fa-paintbrush' => 'Artes',
    'fa-laptop-code' => 'Programação',
    'fa-database' => 'Banco de Dados',
    'fa-chart-line' => 'Estatística',
    'fa-brain' => 'Raciocínio',
    'fa-pen-fancy' => 'Redação'
];

// Cores disponíveis
$cores_disponiveis = [
    '#006B3E' => 'Verde Escuro',
    '#28a745' => 'Verde',
    '#17a2b8' => 'Azul',
    '#007bff' => 'Azul Escuro',
    '#6610f2' => 'Roxo',
    '#6f42c1' => 'Roxo Escuro',
    '#e83e8c' => 'Rosa',
    '#dc3545' => 'Vermelho',
    '#fd7e14' => 'Laranja',
    '#ffc107' => 'Amarelo',
    '#20c997' => 'Verde Água',
    '#6c757d' => 'Cinza'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Categorias de Questões | Professor | SIGE Angola</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
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
        
        .categoria-card { background: white; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); overflow: hidden; transition: all 0.3s ease; }
        .categoria-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .categoria-header { padding: 20px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .categoria-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .categoria-icon i { font-size: 24px; }
        .categoria-info { flex: 1; }
        .categoria-nome { font-size: 1.1rem; font-weight: 700; margin-bottom: 5px; }
        .categoria-descricao { color: #6c757d; font-size: 0.85rem; }
        .categoria-stats { display: flex; gap: 15px; margin-top: 10px; }
        .categoria-stats span { font-size: 0.75rem; color: #6c757d; }
        .categoria-stats i { margin-right: 5px; }
        .categoria-actions { display: flex; gap: 8px; }
        .btn-icon { padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; transition: all 0.3s ease; }
        .btn-edit { background: #17a2b8; color: white; border: none; }
        .btn-edit:hover { background: #138496; transform: translateY(-2px); }
        .btn-delete { background: #dc3545; color: white; border: none; }
        .btn-delete:hover { background: #c82333; transform: translateY(-2px); }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .color-option { width: 30px; height: 30px; border-radius: 8px; cursor: pointer; margin: 5px; display: inline-block; border: 2px solid transparent; transition: all 0.2s; }
        .color-option:hover { transform: scale(1.1); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .color-option.selected { border-color: #333; transform: scale(1.1); }
        .icon-option { display: inline-flex; align-items: center; justify-content: center; width: 45px; height: 45px; margin: 5px; border-radius: 10px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s; background: #f8f9fa; }
        .icon-option:hover { background: #e9ecef; transform: scale(1.05); }
        .icon-option.selected { border-color: #006B3E; background: #e8f5e9; }
        .icon-option i { font-size: 20px; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; } .categoria-header { flex-direction: column; align-items: flex-start; } }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-tags me-2"></i> Categorias de Questões</h4>
                        <p>Organize suas questões por categorias para facilitar a busca</p>
                    </div>
                    <div>
                        <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalCategoria" onclick="novaCategoria()">
                            <i class="fas fa-plus-circle"></i> Nova Categoria
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
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-tags"></i></div>
                    <div class="stat-value text-primary"><?php echo $total_categorias; ?></div>
                    <div class="stat-label">Total de Categorias</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-question-circle"></i></div>
                    <div class="stat-value text-success"><?php echo $total_questoes; ?></div>
                    <div class="stat-label">Questões Categorizadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-value text-info"><?php echo $total_categorias > 0 ? round($total_questoes / $total_categorias, 1) : 0; ?></div>
                    <div class="stat-label">Média por Categoria</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filter-card fade-in">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Buscar categoria</label>
                        <input type="text" name="busca" class="form-control" placeholder="Digite o nome da categoria..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Lista de Categorias -->
            <?php if (empty($categorias)): ?>
                <div class="text-center py-5 bg-white rounded-4 fade-in">
                    <i class="fas fa-tags fa-4x text-muted mb-3"></i>
                    <h5>Nenhuma categoria encontrada</h5>
                    <p class="text-muted">Clique em "Nova Categoria" para começar a organizar suas questões.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($categorias as $categoria): ?>
                    <div class="col-md-6 col-lg-4 fade-in">
                        <div class="categoria-card">
                            <div class="categoria-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="categoria-icon" style="background: <?php echo $categoria['cor']; ?>20; color: <?php echo $categoria['cor']; ?>;">
                                        <i class="fas <?php echo $categoria['icone']; ?>"></i>
                                    </div>
                                    <div class="categoria-info">
                                        <div class="categoria-nome"><?php echo htmlspecialchars($categoria['nome']); ?></div>
                                        <div class="categoria-descricao"><?php echo htmlspecialchars($categoria['descricao'] ?: 'Sem descrição'); ?></div>
                                        <div class="categoria-stats">
                                            <span><i class="fas fa-question-circle"></i> <?php echo $categoria['total_questoes']; ?> questões</span>
                                            <span><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($categoria['created_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="categoria-actions">
                                    <button class="btn-icon btn-edit" onclick="editarCategoria(<?php echo $categoria['id']; ?>)">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="excluirCategoria(<?php echo $categoria['id']; ?>, '<?php echo addslashes($categoria['nome']); ?>')">
                                        <i class="fas fa-trash"></i> Excluir
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Nova/Editar Categoria -->
    <div class="modal fade" id="modalCategoria" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalCategoriaTitle"><i class="fas fa-tag me-2"></i> Nova Categoria</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCategoria">
                    <input type="hidden" name="acao" id="acao" value="salvar">
                    <input type="hidden" name="categoria_id" id="categoria_id" value="0">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nome da Categoria *</label>
                            <input type="text" name="nome" id="nome" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="2" placeholder="Descreva o propósito desta categoria..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Cor</label>
                                <div id="cores-container" class="d-flex flex-wrap">
                                    <?php foreach ($cores_disponiveis as $cor => $nome_cor): ?>
                                    <div class="color-option" style="background: <?php echo $cor; ?>" data-cor="<?php echo $cor; ?>" onclick="selecionarCor(this, '<?php echo $cor; ?>')" title="<?php echo $nome_cor; ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="cor" id="cor" value="#006B3E">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ícone</label>
                                <div id="icones-container" class="d-flex flex-wrap">
                                    <?php foreach ($icones_disponiveis as $icone => $nome_icone): ?>
                                    <div class="icon-option" data-icone="<?php echo $icone; ?>" onclick="selecionarIcone(this, '<?php echo $icone; ?>')" title="<?php echo $nome_icone; ?>">
                                        <i class="fas <?php echo $icone; ?>"></i>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="icone" id="icone" value="fa-folder">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Categoria</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let corSelecionada = '#006B3E';
        let iconeSelecionado = 'fa-folder';
        
        function selecionarCor(elemento, cor) {
            document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
            elemento.classList.add('selected');
            corSelecionada = cor;
            document.getElementById('cor').value = cor;
        }
        
        function selecionarIcone(elemento, icone) {
            document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
            elemento.classList.add('selected');
            iconeSelecionado = icone;
            document.getElementById('icone').value = icone;
        }
        
        function novaCategoria() {
            document.getElementById('modalCategoriaTitle').innerHTML = '<i class="fas fa-tag me-2"></i> Nova Categoria';
            document.getElementById('acao').value = 'salvar';
            document.getElementById('categoria_id').value = 0;
            document.getElementById('formCategoria').reset();
            document.getElementById('nome').value = '';
            document.getElementById('descricao').value = '';
            
            // Resetar seleção de cor
            document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.color-option[style*="#006B3E"]').classList.add('selected');
            corSelecionada = '#006B3E';
            document.getElementById('cor').value = '#006B3E';
            
            // Resetar seleção de ícone
            document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.icon-option[data-icone="fa-folder"]').classList.add('selected');
            iconeSelecionado = 'fa-folder';
            document.getElementById('icone').value = 'fa-folder';
            
            $('#modalCategoria').modal('show');
        }
        
        function editarCategoria(id) {
            $.ajax({
                url: 'ajax_buscar_categoria.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        document.getElementById('modalCategoriaTitle').innerHTML = '<i class="fas fa-edit me-2"></i> Editar Categoria';
                        document.getElementById('acao').value = 'atualizar';
                        document.getElementById('categoria_id').value = data.id;
                        document.getElementById('nome').value = data.nome;
                        document.getElementById('descricao').value = data.descricao || '';
                        
                        // Selecionar cor
                        document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
                        let corElement = document.querySelector(`.color-option[data-cor="${data.cor}"]`);
                        if (corElement) {
                            corElement.classList.add('selected');
                            corSelecionada = data.cor;
                        } else {
                            document.querySelector('.color-option[style*="#006B3E"]').classList.add('selected');
                            corSelecionada = '#006B3E';
                        }
                        document.getElementById('cor').value = corSelecionada;
                        
                        // Selecionar ícone
                        document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
                        let iconeElement = document.querySelector(`.icon-option[data-icone="${data.icone}"]`);
                        if (iconeElement) {
                            iconeElement.classList.add('selected');
                            iconeSelecionado = data.icone;
                        } else {
                            document.querySelector('.icon-option[data-icone="fa-folder"]').classList.add('selected');
                            iconeSelecionado = 'fa-folder';
                        }
                        document.getElementById('icone').value = iconeSelecionado;
                        
                        $('#modalCategoria').modal('show');
                    } else {
                        alert('Erro ao carregar categoria: ' + data.message);
                    }
                },
                error: function() {
                    alert('Erro ao carregar categoria. Tente novamente.');
                }
            });
        }
        
        function excluirCategoria(id, nome) {
            if (confirm(`Tem certeza que deseja excluir a categoria "${nome}"?\n\nEsta ação não pode ser desfeita.`)) {
                $('<form method="POST"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="categoria_id" value="' + id + '"></form>').appendTo('body').submit();
            }
        }
        
        // Animações
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.stat-card, .categoria-card, .filter-card').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
        
        $(document).ready(function() {
            // Selecionar cor padrão
            document.querySelector('.color-option[style*="#006B3E"]').classList.add('selected');
            document.querySelector('.icon-option[data-icone="fa-folder"]').classList.add('selected');
        });
    </script>
</body>
</html>