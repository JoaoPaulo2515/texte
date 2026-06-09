<?php
// escola/biblioteca/index.php - Biblioteca Digital (Visualização para todos)
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $usuario_tipo == 'secretaria');
$is_professor = ($usuario_tipo == 'professor');
$is_aluno = ($usuario_tipo == 'aluno');
$is_pai = ($usuario_tipo == 'pai');

$search = $_GET['search'] ?? '';
$categoria = $_GET['categoria'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Buscar categorias distintas
$sql_categorias = "SELECT DISTINCT categoria FROM livros WHERE escola_id = :escola_id AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_COLUMN);

// Query principal
$query = "
    SELECT l.* 
    FROM livros l
    WHERE l.escola_id = :escola_id AND l.status = 'disponivel'
";

$params = [':escola_id' => $escola_id];

if ($search) {
    $query .= " AND (l.titulo LIKE :search OR l.autor LIKE :search OR l.categoria LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($categoria) {
    $query .= " AND l.categoria = :categoria";
    $params[':categoria'] = $categoria;
}

$query .= " ORDER BY l.id DESC LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$livros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total de livros para paginação
$count_query = "
    SELECT COUNT(*) as total 
    FROM livros 
    WHERE escola_id = :escola_id AND status = 'disponivel'
";
$count_params = [':escola_id' => $escola_id];

if ($search) {
    $count_query .= " AND (titulo LIKE :search OR autor LIKE :search OR categoria LIKE :search)";
    $count_params[':search'] = "%{$search}%";
}
if ($categoria) {
    $count_query .= " AND categoria = :categoria";
    $count_params[':categoria'] = $categoria;
}

$stmt = $conn->prepare($count_query);
foreach ($count_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_livros = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_livros / $limit);

// Estatísticas
$stats = [];
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM livros WHERE escola_id = :escola_id AND status = 'disponivel'");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_livros'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->prepare("SELECT SUM(visualizacoes) as total FROM livros WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_visualizacoes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->prepare("SELECT SUM(downloads) as total FROM livros WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_downloads'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Função para formatar data
function formatarData($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca Digital | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; border-radius: 15px 15px 0 0; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .livro-card { transition: transform 0.3s; cursor: pointer; height: 100%; }
        .livro-card:hover { transform: translateY(-5px); }
        .livro-capa { height: 200px; object-fit: cover; border-radius: 10px 10px 0 0; width: 100%; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); display: flex; align-items: center; justify-content: center; }
        .livro-capa img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px 10px 0 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .badge-categoria { background: #17a2b8; color: white; padding: 5px 10px; border-radius: 20px; font-size: 11px; }
        .btn-visualizar { background: #17a2b8; color: white; border: none; }
        .btn-visualizar:hover { background: #138496; }
        .btn-download { background: #28a745; color: white; border: none; }
        .btn-download:hover { background: #1e7e34; }
        .modal-xl { max-width: 90%; }
        .document-viewer { width: 100%; height: 500px; border: none; }
        .btn-close-white { filter: invert(1); }
        .livro-icon { font-size: 4rem; color: white; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-book-open"></i> Biblioteca Digital</h2>
                <p>Livros, apostilas e materiais de apoio</p>
            </div>
            <?php if ($is_admin): ?>
            <a href="cadastrar.php" class="btn btn-primary"><i class="fas fa-plus"></i> Adicionar Livro</a>
            <?php endif; ?>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_livros']; ?></div>
                <div><i class="fas fa-book"></i> Livros Disponíveis</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total_visualizacoes']); ?></div>
                <div><i class="fas fa-eye"></i> Visualizações</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total_downloads']); ?></div>
                <div><i class="fas fa-download"></i> Downloads</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter"></i> Filtros de Pesquisa
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por título, autor ou categoria..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-5">
                        <select name="categoria" class="form-select">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $categoria == $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Buscar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Livros -->
        <div class="row" id="livrosContainer">
            <?php if (empty($livros)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                            <h4>Nenhum livro encontrado</h4>
                            <p>Não há livros disponíveis com os filtros selecionados.</p>
                            <?php if ($is_admin): ?>
                            <a href="cadastrar.php" class="btn btn-primary"><i class="fas fa-plus"></i> Adicionar Livro</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($livros as $livro): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card livro-card" onclick="visualizarLivro(<?php echo $livro['id']; ?>)">
                        <?php if ($livro['capa'] && file_exists('../../uploads/livros/capas/' . $livro['capa'])): ?>
                            <div class="livro-capa">
                                <img src="../../uploads/livros/capas/<?php echo $livro['capa']; ?>" alt="Capa">
                            </div>
                        <?php else: ?>
                            <div class="livro-capa">
                                <i class="fas fa-book livro-icon"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h6 class="card-title"><?php echo htmlspecialchars($livro['titulo']); ?></h6>
                            <p class="card-text small text-muted">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($livro['autor'] ?? 'Autor desconhecido'); ?>
                            </p>
                            <?php if ($livro['categoria']): ?>
                            <span class="badge-categoria"><?php echo htmlspecialchars($livro['categoria']); ?></span>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mt-2 small text-muted">
                                <span><i class="fas fa-eye"></i> <?php echo number_format($livro['visualizacoes'] ?? 0); ?></span>
                                <span><i class="fas fa-download"></i> <?php echo number_format($livro['downloads'] ?? 0); ?></span>
                                <span><i class="fas fa-calendar-alt"></i> <?php echo formatarData($livro['created_at']); ?></span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent text-center">
                            <button class="btn btn-sm btn-visualizar" onclick="event.stopPropagation(); visualizarLivro(<?php echo $livro['id']; ?>)">
                                <i class="fas fa-eye"></i> Visualizar
                            </button>
                            <button class="btn btn-sm btn-download" onclick="event.stopPropagation(); baixarLivro(<?php echo $livro['id']; ?>)">
                                <i class="fas fa-download"></i> Baixar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo urlencode($categoria); ?>">Anterior</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo urlencode($categoria); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo urlencode($categoria); ?>">Próxima</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Visualizar Livro -->
    <div class="modal fade" id="modalVisualizarLivro" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title" id="modalTitulo"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalConteudo">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando livro...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-download" id="modalDownloadBtn">
                        <i class="fas fa-download"></i> Baixar
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu toggle
        $('#menuToggle').click(function() { 
            $('.sidebar').toggleClass('active'); 
            $('.main-content').toggleClass('active'); 
        });
        
        function visualizarLivro(id) {
    $('#modalConteudo').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Carregando livro...</p></div>');
    $('#modalVisualizarLivro').modal('show');
    
    $.ajax({
        url: 'visualizar.php',
        method: 'POST',
        data: { id: id, acao: 'visualizar' },
        dataType: 'json',
        success: function(response) {
            if (response.error) {
                $('#modalConteudo').html('<div class="alert alert-danger">' + response.error + '</div>');
            } else if (response.success === false) {
                $('#modalConteudo').html('<div class="alert alert-danger">Erro ao carregar o livro. Tente novamente.</div>');
            } else {
                $('#modalTitulo').text(response.titulo);
                $('#modalConteudo').html(response.conteudo);
                $('#modalDownloadBtn').off('click').on('click', function() { baixarLivro(id); });
            }
        },
        error: function(xhr, status, error) {
            console.log('Erro AJAX:', error);
            console.log('Resposta:', xhr.responseText);
            $('#modalConteudo').html('<div class="alert alert-danger">Erro ao carregar o livro. Tente novamente.<br><small>' + error + '</small></div>');
        }
    });
}

function baixarLivro(id) {
    window.open('visualizar.php?id=' + id + '&acao=download', '_blank');
}
        
        function baixarLivro(id) {
            window.open('visualizar.php?id=' + id + '&acao=download', '_blank');
        }
    </script>
</body>
</html>