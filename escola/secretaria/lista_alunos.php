<?php
// escola/secretaria/lista_alunos.php - Lista de Alunos

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// FILTROS
// ============================================
$filtro_turma = $_GET['turma'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$filtro_busca = $_GET['busca'] ?? '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// BUSCAR TURMAS PARA FILTRO
// ============================================
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CONSULTA PRINCIPAL COM FILTROS - CORRIGIDA
// ============================================
$sql = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.email,
        e.telefone,
        e.data_nascimento,
        e.genero,
        e.foto,
        e.status as aluno_status,
        e.created_at,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno as turma_turno,
        m.id as matricula_id,
        m.ano_letivo as matricula_ano,
        m.status as matricula_status
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE e.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

// Aplicar filtro de turma
if (!empty($filtro_turma)) {
    $sql .= " AND m.turma_id = :turma_id";
    $params[':turma_id'] = $filtro_turma;
}

// Aplicar filtro de status
if (!empty($filtro_status)) {
    $sql .= " AND e.status = :status";
    $params[':status'] = $filtro_status;
}

// Aplicar filtro de busca (nome, matrícula ou BI)
if (!empty($filtro_busca)) {
    $sql .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
    $params[':busca'] = '%' . $filtro_busca . '%';
}

// Ordenação
$sql .= " ORDER BY e.nome ASC";

// ============================================
// CONTAR TOTAL DE REGISTROS - CORRIGIDO
// ============================================
$sql_count = "
    SELECT COUNT(DISTINCT e.id) as total
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE e.escola_id = :escola_id
";

// Adicionar os mesmos filtros ao COUNT
if (!empty($filtro_turma)) {
    $sql_count .= " AND m.turma_id = :turma_id";
}
if (!empty($filtro_status)) {
    $sql_count .= " AND e.status = :status";
}
if (!empty($filtro_busca)) {
    $sql_count .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
}

$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// ============================================
// ADICIONAR LIMITE PARA PAGINAÇÃO
// ============================================
$sql .= " LIMIT :offset, :por_pagina";

// Preparar a consulta principal
$stmt = $conn->prepare($sql);

// Bind dos parâmetros (tratamento especial para LIMIT)
foreach ($params as $key => $value) {
    if ($key == ':offset' || $key == ':por_pagina') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}

// Bind dos parâmetros de paginação
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':por_pagina', $por_pagina, PDO::PARAM_INT);

$stmt->execute();
$alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÃO PARA EXIBIR STATUS
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'ativo':
            return '<span class="badge bg-success">Ativo</span>';
        case 'inativo':
            return '<span class="badge bg-danger">Inativo</span>';
        case 'transferido':
            return '<span class="badge bg-warning">Transferido</span>';
        case 'concluido':
            return '<span class="badge bg-info">Concluído</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Alunos | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-sm { border-radius: 8px; }
        .table th { background: #f8f9fa; }
        .foto-aluno { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .pagination .page-link { color: #006B3E; }
        .pagination .active .page-link { background-color: #006B3E; border-color: #006B3E; color: white; }
        .filtros { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-list"></i> Lista de Alunos</h2>
            <a href="matricula.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Nova Matrícula</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Turma</label>
                        <select name="turma" class="form-select">
                            <option value="">Todas as turmas</option>
                            <?php foreach ($turmas as $turma): ?>
                            <option value="<?php echo $turma['id']; ?>" <?php echo $filtro_turma == $turma['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($turma['nome'] . ' - ' . $turma['ano'] . 'ª'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="ativo" <?php echo $filtro_status == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo $filtro_status == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                            <option value="transferido" <?php echo $filtro_status == 'transferido' ? 'selected' : ''; ?>>Transferido</option>
                            <option value="concluido" <?php echo $filtro_status == 'concluido' ? 'selected' : ''; ?>>Concluído</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome, Matrícula ou BI" value="<?php echo htmlspecialchars($filtro_busca); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users"></i> Alunos Cadastrados</h5>
                <span class="badge bg-light text-dark">Total: <?php echo $total_registros; ?> alunos</span>
            </div>
            <div class="card-body">
                <?php if (empty($alunos)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum aluno encontrado.
                        <a href="matricula.php" class="alert-link">Clique aqui para cadastrar</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Nome</th>
                                    <th>Matrícula</th>
                                    <th>BI</th>
                                    <th>Turma</th>
                                    <th>Contacto</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $aluno): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                            <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-aluno">
                                        <?php else: ?>
                                            <img src="../../assets/images/avatar-padrao.png" class="foto-aluno">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aluno['nome'] ?? '');  ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : 'N/E'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                                    <td>
                                        <?php if (!empty($aluno['turma_nome'])): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($aluno['turma_nome']); ?></span>
                                            <br>
                                            <small><?php echo $aluno['turma_ano'] . 'ª - ' . ucfirst($aluno['turma_turno']); ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Sem turma</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($aluno['email'])): ?>
                                            <i class="fas fa-envelope text-muted"></i> <?php echo htmlspecialchars($aluno['email'] ?? '');  ?><br>
                                        <?php endif; ?>
                                        <?php if (!empty($aluno['telefone'])): ?>
                                            <i class="fas fa-phone text-muted"></i> <?php echo htmlspecialchars($aluno['telefone']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatusBadge($aluno['aluno_status']); ?></td>
                                    <td>
                                        <a href="ver_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-info" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="historico_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-secondary" title="Histórico">
                                            <i class="fas fa-history"></i>
                                        </a>
                                        <?php if ($aluno['aluno_status'] == 'ativo'): ?>
                                        <a href="emitir_certificado.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-success" title="Emitir Certificado">
                                            <i class="fas fa-certificate"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginação -->
                    <?php if ($total_paginas > 1): ?>
                    <nav aria-label="Paginação" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&turma=<?php echo urlencode($filtro_turma); ?>&status=<?php echo urlencode($filtro_status); ?>&busca=<?php echo urlencode($filtro_busca); ?>">
                                    <i class="fas fa-chevron-left"></i> Anterior
                                </a>
                            </li>
                            
                            <?php
                            $inicio = max(1, $pagina - 2);
                            $fim = min($total_paginas, $pagina + 2);
                            for ($i = $inicio; $i <= $fim; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $i; ?>&turma=<?php echo urlencode($filtro_turma); ?>&status=<?php echo urlencode($filtro_status); ?>&busca=<?php echo urlencode($filtro_busca); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&turma=<?php echo urlencode($filtro_turma); ?>&status=<?php echo urlencode($filtro_status); ?>&busca=<?php echo urlencode($filtro_busca); ?>">
                                    Próximo <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
    </script>
</body>
</html>