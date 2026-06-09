<?php
// escola/professor/alunos.php - Lista de Alunos por Turma

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 15;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA SELECIONADA
// ============================================
$alunos = [];
$total_alunos = 0;
$total_paginas = 0;
$turma_atual = null;

if ($turma_id > 0) {
    // Buscar dados da turma
    $sql_turma_atual = "
        SELECT t.*, 
               COUNT(DISTINCT m.estudante_id) as total_matriculados
        FROM turmas t
        LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
        GROUP BY t.id
    ";
    $stmt_turma_atual = $conn->prepare($sql_turma_atual);
    $stmt_turma_atual->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $turma_atual = $stmt_turma_atual->fetch(PDO::FETCH_ASSOC);
    
    // Buscar total de alunos para paginação
    $sql_count = "
        SELECT COUNT(DISTINCT e.id) as total
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ";
    
    $params_count = [':turma_id' => $turma_id];
    
    if (!empty($busca)) {
        $sql_count .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
        $params_count[':busca'] = '%' . $busca . '%';
    }
    
    if (!empty($status_filtro)) {
        $sql_count .= " AND e.status = :status";
        $params_count[':status'] = $status_filtro;
    }
    
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->execute($params_count);
    $total_alunos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_alunos / $por_pagina);
    
    // Buscar alunos com paginação
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
            m.numero_processo,
            m.data_matricula,
            m.status as matricula_status
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ";
    
    $params = [':turma_id' => $turma_id];
    
    if (!empty($busca)) {
        $sql_alunos .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
        $params[':busca'] = '%' . $busca . '%';
    }
    
    if (!empty($status_filtro)) {
        $sql_alunos .= " AND e.status = :status";
        $params[':status'] = $status_filtro;
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
}

// ============================================
// FUNÇÕES AUXILIARES
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
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? '') . '</span>';
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
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? '') . '</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Alunos | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .foto-aluno {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 50%;
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .turma-card {
            transition: all 0.3s;
            cursor: pointer;
            border: 1px solid #dee2e6;
        }
        .turma-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .turma-card.active {
            border-left: 4px solid #006B3E;
            background: #f8f9fa;
        }
        .table-alunos th {
            background: #f8f9fa;
        }
        .pagination .page-link {
            color: #006B3E;
        }
        .pagination .active .page-link {
            background-color: #006B3E;
            border-color: #006B3E;
            color: white;
        }
        .info-turma {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-users"></i> Meus Alunos</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
        
        <div class="row">
            <!-- Sidebar com Turmas -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-building"></i> Minhas Turmas</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($turmas as $turma): ?>
                            <a href="?turma_id=<?php echo $turma['id']; ?>" 
                               class="list-group-item list-group-item-action <?php echo $turma_id == $turma['id'] ? 'active' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno']); ?>
                                            <?php if (!empty($turma['sala'])): ?>
                                            | <i class="fas fa-door-open"></i> <?php echo $turma['sala']; ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            </a>
                            <?php endforeach; ?>
                            
                            <?php if (empty($turmas)): ?>
                            <div class="list-group-item text-center text-muted">
                                <i class="fas fa-info-circle"></i> Nenhuma turma associada
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Resumo da Turma -->
                <?php if ($turma_atual): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-simple"></i> Resumo da Turma</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h3><?php echo $turma_atual['ano'] . 'ª ' . $turma_atual['nome']; ?></h3>
                            <p class="text-muted">
                                <i class="fas fa-clock"></i> <?php echo ucfirst($turma_atual['turno']); ?>
                                <?php if (!empty($turma_atual['sala'])): ?>
                                | <i class="fas fa-door-open"></i> Sala: <?php echo $turma_atual['sala']; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <h4 class="text-primary mb-0"><?php echo $turma_atual['total_matriculados'] ?? 0; ?></h4>
                                    <small>Matriculados</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <h4 class="text-success mb-0"><?php echo $total_alunos; ?></h4>
                                    <small>Ativos</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Lista de Alunos -->
            <div class="col-md-9">
                <?php if ($turma_id > 0 && $turma_atual): ?>
                
                <!-- Filtros -->
                <div class="filter-bar">
                    <form method="GET" class="row align-items-end">
                        <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                        <div class="col-md-5">
                            <label class="form-label">Buscar Aluno</label>
                            <input type="text" name="busca" class="form-control" placeholder="Nome, Matrícula ou BI" value="<?php echo htmlspecialchars($busca); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">Todos</option>
                                <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inativo" <?php echo $status_filtro == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                                <option value="transferido" <?php echo $status_filtro == 'transferido' ? 'selected' : ''; ?>>Transferido</option>
                                <option value="concluido" <?php echo $status_filtro == 'concluido' ? 'selected' : ''; ?>>Concluído</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Info da Turma -->
                <div class="info-turma">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="mb-0">
                                <i class="fas fa-users text-primary"></i> 
                                Alunos da Turma <?php echo $turma_atual['ano'] . 'ª ' . $turma_atual['nome']; ?>
                            </h5>
                            <small class="text-muted">Total de <?php echo $total_alunos; ?> alunos encontrados</small>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-pen-alt"></i> Lançar Notas
                            </a>
                            <a href="registrar_chamada.php?turma_id=<?php echo $turma_id; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-clipboard-list"></i> Chamada
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Tabela de Alunos -->
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-alunos mb-0">
                                <thead>
                                    <tr>
                                        <th>Foto</th>
                                        <th>Nome</th>
                                        <th>Matrícula</th>
                                        <th>BI</th>
                                        <th>Contacto</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($alunos)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="fas fa-user-graduate fa-2x mb-2 d-block"></i>
                                            Nenhum aluno encontrado nesta turma.
                                        </td>
                                    </tr>
                                    <?php else: ?>
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
                                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar"></i> Nasc: <?php echo formatarData($aluno['data_nascimento']); ?>
                                                    | <i class="fas fa-venus-mars"></i> <?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : 'N/E'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($aluno['matricula']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo getMatriculaStatusBadge($aluno['matricula_status']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($aluno['email'])): ?>
                                                    <i class="fas fa-envelope text-muted"></i> <?php echo htmlspecialchars($aluno['email']); ?><br>
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
                                                <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>&aluno_id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-primary" title="Lançar notas">
                                                    <i class="fas fa-pen-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                <nav aria-label="Paginação" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?turma_id=<?php echo $turma_id; ?>&pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        </li>
                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fim = min($total_paginas, $pagina + 2);
                        for ($i = $inicio; $i <= $fim; $i++):
                        ?>
                        <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="?turma_id=<?php echo $turma_id; ?>&pagina=<?php echo $i; ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?turma_id=<?php echo $turma_id; ?>&pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($status_filtro); ?>">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <?php elseif ($turma_id > 0 && !$turma_atual): ?>
                    <div class="alert alert-danger text-center">
                        <i class="fas fa-exclamation-triangle"></i> Turma não encontrada ou você não tem acesso a ela.
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Selecione uma turma no menu ao lado para visualizar os alunos.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>