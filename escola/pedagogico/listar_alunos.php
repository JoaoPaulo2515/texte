<?php
// escola/pedagogico/listar_alunos.php - Listar Alunos

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// ============================================
// PARÂMETROS DE FILTRO E PAGINAÇÃO
// ============================================
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "
    SELECT id, nome, ano, turno, sala 
    FROM turmas 
    WHERE escola_id = :escola_id 
    ORDER BY ano ASC, nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TOTAL DE ALUNOS PARA PAGINAÇÃO
// ============================================
$sql_count = "
    SELECT COUNT(DISTINCT e.id) as total
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    WHERE m.escola_id = :escola_id 
    AND m.ano_letivo = :ano_letivo_id
";
$params_count = [':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id];

if (!empty($busca)) {
    $sql_count .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
    $params_count[':busca'] = '%' . $busca . '%';
}

if ($turma_id > 0) {
    $sql_count .= " AND m.turma_id = :turma_id";
    $params_count[':turma_id'] = $turma_id;
}

if (!empty($status)) {
    $sql_count .= " AND e.status = :status";
    $params_count[':status'] = $status;
}

$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params_count);
$total_alunos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_alunos / $por_pagina);

// ============================================
// BUSCAR LISTA DE ALUNOS
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.foto,
        e.status as aluno_status,
        e.created_at,
        m.id as matricula_id,
        m.numero_processo,
        m.data_matricula,
        m.status as matricula_status,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno
    FROM estudantes e
    INNER JOIN matriculas m ON m.estudante_id = e.id
    INNER JOIN turmas t ON t.id = m.turma_id
    WHERE m.escola_id = :escola_id 
    AND m.ano_letivo = :ano_letivo_id
";
$params = [':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id];

if (!empty($busca)) {
    $sql_alunos .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
    $params[':busca'] = '%' . $busca . '%';
}

if ($turma_id > 0) {
    $sql_alunos .= " AND m.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}

if (!empty($status)) {
    $sql_alunos .= " AND e.status = :status";
    $params[':status'] = $status;
}

$sql_alunos .= " ORDER BY e.nome ASC LIMIT :offset, :por_pagina";

$stmt_alunos = $conn->prepare($sql_alunos);
foreach ($params as $key => $value) {
    if ($key == ':offset' || $key == ':por_pagina') {
        $stmt_alunos->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt_alunos->bindValue($key, $value);
    }
}
$stmt_alunos->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_alunos->bindValue(':por_pagina', $por_pagina, PDO::PARAM_INT);
$stmt_alunos->execute();
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'ativo':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>';
        case 'inativo':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Inativo</span>';
        case 'transferido':
            return '<span class="badge bg-warning"><i class="fas fa-exchange-alt"></i> Transferido</span>';
        case 'concluido':
            return '<span class="badge bg-info"><i class="fas fa-graduation-cap"></i> Concluído</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

function getMatriculaStatusBadge($status) {
    switch ($status) {
        case 'ativa':
            return '<span class="badge bg-success">Ativa</span>';
        case 'cancelada':
            return '<span class="badge bg-danger">Cancelada</span>';
        case 'trancada':
            return '<span class="badge bg-warning">Trancada</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function calcularIdade($data_nascimento) {
    if (empty($data_nascimento)) return '-';
    $data_nasc = new DateTime($data_nascimento);
    $hoje = new DateTime();
    return $data_nasc->diff($hoje)->y . ' anos';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Listar Alunos | Pedagógico | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '👨‍🎓';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .filter-card .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .table-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .aluno-table {
            width: 100%;
            border-collapse: collapse;
        }

        .aluno-table th {
            background: #f8f9fa;
            padding: 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .aluno-table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .aluno-table tr:hover {
            background: #f8f9fa;
        }

        .aluno-foto {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }

        .btn-acao {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            margin: 0 3px;
            transition: var(--transition);
        }

        .btn-acao:hover {
            color: var(--primary-green);
            transform: scale(1.1);
        }

        .btn-edit {
            color: #17a2b8;
        }

        .btn-delete {
            color: #dc3545;
        }

        .btn-view {
            color: #28a745;
        }

        .pagination {
            margin-top: 20px;
            justify-content: center;
        }

        .pagination .page-link {
            color: var(--primary-green);
            border-radius: 10px;
            margin: 0 3px;
            transition: var(--transition);
        }

        .pagination .page-link:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .pagination .active .page-link {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }

        @media (max-width: 768px) {
            .aluno-table {
                font-size: 0.75rem;
            }
            .aluno-table th, 
            .aluno-table td {
                padding: 8px;
            }
            .aluno-foto {
                width: 35px;
                height: 35px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-users me-2"></i> Listar Alunos</h2>
                    <p>Gerencie todos os alunos da escola</p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div>
                    <a href="index.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-search me-1"></i> Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Nome, Matrícula ou BI" value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-building me-1"></i> Turma</label>
                    <select name="turma_id" class="form-select">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano']; ?>ª - <?php echo htmlspecialchars($turma['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="fas fa-filter me-1"></i> Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="ativo" <?php echo $status == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        <option value="transferido" <?php echo $status == 'transferido' ? 'selected' : ''; ?>>Transferido</option>
                        <option value="concluido" <?php echo $status == 'concluido' ? 'selected' : ''; ?>>Concluído</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary-custom w-100">
                        <i class="fas fa-filter me-2"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabela de Alunos -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="aluno-table">
                    <thead>
                        <tr>
                            <th>Foto</th>
                            <th>Nome</th>
                            <th>Matrícula</th>
                            <th>Turma</th>
                            <th>Contacto</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($alunos)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="fas fa-user-graduate fa-3x mb-2"></i>
                                <p>Nenhum aluno encontrado</p>
                                <small>Tente ajustar os filtros de busca</small>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($alunos as $aluno): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="aluno-foto">
                                    <?php else: ?>
                                        <div class="aluno-foto">
                                            <i class="fas fa-user fa-2x text-secondary"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                    <small class="text-muted">
                                        <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?><br>
                                        <i class="fas fa-calendar"></i> <?php echo calcularIdade($aluno['data_nascimento']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($aluno['matricula']); ?><br>
                                    <small class="text-muted"><?php echo getMatriculaStatusBadge($aluno['matricula_status']); ?></small>
                                </td>
                                <td>
                                    <?php echo $aluno['turma_ano']; ?>ª<br>
                                    <small><?php echo htmlspecialchars($aluno['turma_nome']); ?></small><br>
                                    <small class="text-muted"><?php echo ucfirst($aluno['turno']); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($aluno['email'])): ?>
                                        <div><i class="fas fa-envelope text-muted"></i> <?php echo htmlspecialchars(substr($aluno['email'], 0, 20)); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($aluno['telefone'])): ?>
                                        <div><i class="fas fa-phone text-muted"></i> <?php echo htmlspecialchars($aluno['telefone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo getStatusBadge($aluno['aluno_status']); ?></td>
                                <td>
                                    <a href="visualizar_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn-acao btn-view" title="Visualizar">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="editar_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn-acao btn-edit" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="historico_escolar.php?id=<?php echo $aluno['id']; ?>" class="btn-acao" style="color:#6f42c1;" title="Histórico">
                                        <i class="fas fa-history"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
        <nav>
            <ul class="pagination">
                <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&turma_id=<?php echo $turma_id; ?>&status=<?php echo urlencode($status); ?>">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </a>
                </li>
                <?php
                $inicio = max(1, $pagina - 2);
                $fim = min($total_paginas, $pagina + 2);
                for ($i = $inicio; $i <= $fim; $i++):
                ?>
                <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                    <a class="page-link" href="?pagina=<?php echo $i; ?>&busca=<?php echo urlencode($busca); ?>&turma_id=<?php echo $turma_id; ?>&status=<?php echo urlencode($status); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&turma_id=<?php echo $turma_id; ?>&status=<?php echo urlencode($status); ?>">
                        Próximo <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>