<?php
// escola/secretaria/inscricoes.php - Gestão de Inscrições
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar ações
$acao = $_GET['acao'] ?? '';
$id = $_GET['id'] ?? 0;

if ($acao == 'aprovar' && $id) {
    $stmt = $conn->prepare("UPDATE inscricoes SET status = 'aprovado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Inscrição aprovada com sucesso!";
    header('Location: inscricoes.php');
    exit;
}

if ($acao == 'rejeitar' && $id) {
    $stmt = $conn->prepare("UPDATE inscricoes SET status = 'rejeitado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Inscrição rejeitada!";
    header('Location: inscricoes.php');
    exit;
}

if ($acao == 'excluir' && $id) {
    $stmt = $conn->prepare("DELETE FROM inscricoes WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Inscrição excluída!";
    header('Location: inscricoes.php');
    exit;
}

// Processar nova inscrição
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'nova_inscricao') {
    $aluno_nome = $_POST['aluno_nome'];
    $data_nasc = $_POST['data_nasc'];
    $bi = $_POST['bi'];
    $escola_origem = $_POST['escola_origem'];
    $classe_pretendida = $_POST['classe_pretendida'];
    $nome_encarregado = $_POST['nome_encarregado'];
    $telefone_encarregado = $_POST['telefone_encarregado'];
    $ano_letivo = date('Y') . '/' . (date('Y') + 1);
    
    $stmt = $conn->prepare("
        INSERT INTO inscricoes (escola_id, aluno_nome, data_nasc, bi, escola_origem, classe_pretendida, 
        nome_encarregado, telefone_encarregado, ano_letivo, status, data_inscricao)
        VALUES (:escola_id, :aluno_nome, :data_nasc, :bi, :escola_origem, :classe_pretendida,
        :nome_encarregado, :telefone_encarregado, :ano_letivo, 'pendente', NOW())
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':aluno_nome' => $aluno_nome,
        ':data_nasc' => $data_nasc,
        ':bi' => $bi,
        ':escola_origem' => $escola_origem,
        ':classe_pretendida' => $classe_pretendida,
        ':nome_encarregado' => $nome_encarregado,
        ':telefone_encarregado' => $telefone_encarregado,
        ':ano_letivo' => $ano_letivo
    ]);
    $_SESSION['mensagem'] = "Inscrição registada com sucesso! Aguarde aprovação.";
    header('Location: inscricoes.php');
    exit;
}

// Buscar inscrições
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$query = "SELECT * FROM inscricoes WHERE escola_id = :escola_id";
$params = [':escola_id' => $escola_id];

if ($status_filter) {
    $query .= " AND status = :status";
    $params[':status'] = $status_filter;
}
if ($search) {
    $query .= " AND (aluno_nome LIKE :search OR bi LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$query .= " ORDER BY data_inscricao DESC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => count($inscricoes),
    'pendentes' => count(array_filter($inscricoes, fn($i) => $i['status'] == 'pendente')),
    'aprovados' => count(array_filter($inscricoes, fn($i) => $i['status'] == 'aprovado')),
    'rejeitados' => count(array_filter($inscricoes, fn($i) => $i['status'] == 'rejeitado'))
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscrições | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Mesmo estilo das páginas anteriores */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .modal-content { border-radius: 15px; }
        .status-badge-pendente { background: #ffc107; color: #000; padding: 4px 10px; border-radius: 20px; font-size: 0.75em; }
        .status-badge-aprovado { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75em; }
        .status-badge-rejeitado { background: #dc3545; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75em; }
        .stats-mini { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-mini-card { background: white; border-radius: 10px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-mini-value { font-size: 1.5em; font-weight: bold; color: #006B3E; }
    </style>
</head>
<body>
  
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-signature"></i> Inscrições</h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#novaInscricaoModal">
                <i class="fas fa-plus"></i> Nova Inscrição
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $mensagem; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-mini">
            <div class="stat-mini-card">
                <div class="stat-mini-value"><?php echo $stats['total']; ?></div>
                <div>Total Inscrições</div>
            </div>
            <div class="stat-mini-card">
                <div class="stat-mini-value"><?php echo $stats['pendentes']; ?></div>
                <div>Pendentes</div>
            </div>
            <div class="stat-mini-card">
                <div class="stat-mini-value"><?php echo $stats['aprovados']; ?></div>
                <div>Aprovados</div>
            </div>
            <div class="stat-mini-card">
                <div class="stat-mini-value"><?php echo $stats['rejeitados']; ?></div>
                <div>Rejeitados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por nome ou BI" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-control">
                            <option value="">Todos os status</option>
                            <option value="pendente" <?php echo $status_filter == 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="aprovado" <?php echo $status_filter == 'aprovado' ? 'selected' : ''; ?>>Aprovado</option>
                            <option value="rejeitado" <?php echo $status_filter == 'rejeitado' ? 'selected' : ''; ?>>Rejeitado</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Inscrições -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Lista de Inscrições
                <span class="badge bg-secondary ms-2"><?php echo count($inscricoes); ?> registros</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Nome</th>
                                <th>BI</th>
                                <th>Data Nasc.</th>
                                <th>Classe Pretendida</th>
                                <th>Escola Origem</th>
                                <th>Encarregado</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inscricoes as $inscricao): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($inscricao['data_inscricao'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($inscricao['aluno_nome']); ?></strong></td>
                                <td><?php echo $inscricao['bi'] ?? '-'; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($inscricao['data_nasc'])); ?></div>
                                <td><?php echo $inscricao['classe_pretendida']; ?></div>
                                <td><?php echo htmlspecialchars($inscricao['escola_origem'] ?? '-'); ?></div>
                                <td>
                                    <?php echo htmlspecialchars($inscricao['nome_encarregado']); ?><br>
                                    <small><?php echo $inscricao['telefone_encarregado']; ?></small>
                                 </div>
                                </div>
                                <td>
                                    <?php if ($inscricao['status'] == 'pendente'): ?>
                                        <span class="status-badge-pendente">Pendente</span>
                                    <?php elseif ($inscricao['status'] == 'aprovado'): ?>
                                        <span class="status-badge-aprovado">Aprovado</span>
                                    <?php else: ?>
                                        <span class="status-badge-rejeitado">Rejeitado</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($inscricao['status'] == 'pendente'): ?>
                                            <a href="?acao=aprovar&id=<?php echo $inscricao['id']; ?>" class="btn btn-success" onclick="return confirm('Aprovar esta inscrição?')">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="?acao=rejeitar&id=<?php echo $inscricao['id']; ?>" class="btn btn-danger" onclick="return confirm('Rejeitar esta inscrição?')">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($inscricao['status'] == 'aprovado'): ?>
                                            <a href="matricula.php?inscricao_id=<?php echo $inscricao['id']; ?>" class="btn btn-primary" title="Converter em Matrícula">
                                                <i class="fas fa-user-plus"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?acao=excluir&id=<?php echo $inscricao['id']; ?>" class="btn btn-secondary" onclick="return confirm('Excluir esta inscrição?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                 </div>
                                </div>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($inscricoes)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                    Nenhuma inscrição encontrada
                                 </div>
                                </div>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Inscrição -->
    <div class="modal fade" id="novaInscricaoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-file-signature"></i> Nova Inscrição</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="nova_inscricao">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nome Completo *</label>
                                <input type="text" name="aluno_nome" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Nascimento *</label>
                                <input type="date" name="data_nasc" class="form-control" required>
                                <small class="text-muted">Idade mínima: 6 anos para 1ª classe</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">BI / Certidão de Nascimento</label>
                                <input type="text" name="bi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Escola de Origem</label>
                                <input type="text" name="escola_origem" class="form-control" placeholder="Se aplicável">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Classe Pretendida *</label>
                                <select name="classe_pretendida" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <option value="Pré-escolar">Pré-escolar</option>
                                    <option value="1ª Classe">1ª Classe</option>
                                    <option value="2ª Classe">2ª Classe</option>
                                    <option value="3ª Classe">3ª Classe</option>
                                    <option value="4ª Classe">4ª Classe</option>
                                    <option value="5ª Classe">5ª Classe</option>
                                    <option value="6ª Classe">6ª Classe</option>
                                    <option value="7ª Classe">7ª Classe</option>
                                    <option value="8ª Classe">8ª Classe</option>
                                    <option value="9ª Classe">9ª Classe</option>
                                    <option value="10ª Classe">10ª Classe</option>
                                    <option value="11ª Classe">11ª Classe</option>
                                    <option value="12ª Classe">12ª Classe</option>
                                    <option value="13ª Classe">13ª Classe</option>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nome do Encarregado de Educação *</label>
                                <input type="text" name="nome_encarregado" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Telefone do Encarregado *</label>
                                <input type="tel" name="telefone_encarregado" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Registar Inscrição</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
    </script>
</body>
</html>