<?php
// escola/professor/atividades.php - Gestão de Atividades e Trabalhos

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 10;
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
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

// Adicionar atividade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $tipo = $_POST['tipo'] ?? 'trabalho';
    $valor_maximo = (float)$_POST['valor_maximo'] ?? 10;
    $data_entrega = $_POST['data_entrega'] ?? '';
    $status = isset($_POST['status']) ? 'ativo' : 'inativo';
    
    if ($titulo && $turma_id_post && $disciplina_id_post) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO atividades (
                    titulo, descricao, turma_id, disciplina_id, professor_id,
                    tipo, valor_maximo, data_entrega, status, escola_id, created_at
                ) VALUES (
                    :titulo, :descricao, :turma_id, :disciplina_id, :professor_id,
                    :tipo, :valor_maximo, :data_entrega, :status, :escola_id, NOW()
                )
            ");
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':turma_id' => $turma_id_post,
                ':disciplina_id' => $disciplina_id_post,
                ':professor_id' => $professor_id,
                ':tipo' => $tipo,
                ':valor_maximo' => $valor_maximo,
                ':data_entrega' => $data_entrega,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $success = "Atividade adicionada com sucesso!";
        } catch (PDOException $e) {
            $error = "Erro ao adicionar atividade: " . $e->getMessage();
        }
    } else {
        $error = "Preencha os campos obrigatórios.";
    }
}

// Editar atividade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = (int)$_POST['id'];
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $tipo = $_POST['tipo'] ?? 'trabalho';
    $valor_maximo = (float)$_POST['valor_maximo'] ?? 10;
    $data_entrega = $_POST['data_entrega'] ?? '';
    $status = isset($_POST['status']) ? 'ativo' : 'inativo';
    
    if ($id && $titulo) {
        try {
            $stmt = $conn->prepare("
                UPDATE atividades SET 
                    titulo = :titulo,
                    descricao = :descricao,
                    tipo = :tipo,
                    valor_maximo = :valor_maximo,
                    data_entrega = :data_entrega,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND professor_id = :professor_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':tipo' => $tipo,
                ':valor_maximo' => $valor_maximo,
                ':data_entrega' => $data_entrega,
                ':status' => $status,
                ':professor_id' => $professor_id
            ]);
            $success = "Atividade atualizada com sucesso!";
        } catch (PDOException $e) {
            $error = "Erro ao atualizar atividade: " . $e->getMessage();
        }
    }
}

// Excluir atividade
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM atividades WHERE id = :id AND professor_id = :professor_id");
        $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
        $success = "Atividade excluída com sucesso!";
    } catch (PDOException $e) {
        $error = "Erro ao excluir atividade: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR ATIVIDADES
// ============================================
$sql_atividades = "
    SELECT a.*, 
           t.nome as turma_nome, t.ano as turma_ano,
           d.nome as disciplina_nome,
           COUNT(l.id) as total_lancamentos
    FROM atividades a
    INNER JOIN turmas t ON t.id = a.turma_id
    INNER JOIN disciplinas d ON d.id = a.disciplina_id
    LEFT JOIN lancamentos_notas l ON l.atividade_id = a.id
    WHERE a.professor_id = :professor_id
";

$params = [':professor_id' => $professor_id];

if ($turma_id > 0) {
    $sql_atividades .= " AND a.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}
if ($disciplina_id > 0) {
    $sql_atividades .= " AND a.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}
if (!empty($status_filtro)) {
    $sql_atividades .= " AND a.status = :status";
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $sql_atividades .= " AND (a.titulo LIKE :busca OR a.descricao LIKE :busca)";
    $params[':busca'] = '%' . $busca . '%';
}

$sql_atividades .= " GROUP BY a.id ORDER BY a.data_entrega ASC LIMIT :offset, :por_pagina";

$stmt_atividades = $conn->prepare($sql_atividades);
foreach ($params as $key => $value) {
    if ($key == ':offset' || $key == ':por_pagina') {
        $stmt_atividades->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt_atividades->bindValue($key, $value);
    }
}
$stmt_atividades->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_atividades->bindValue(':por_pagina', $por_pagina, PDO::PARAM_INT);
$stmt_atividades->execute();
$atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);

// Contar total de atividades
$sql_count = "
    SELECT COUNT(*) as total FROM atividades a
    WHERE a.professor_id = :professor_id
";
if ($turma_id > 0) $sql_count .= " AND a.turma_id = :turma_id";
if ($disciplina_id > 0) $sql_count .= " AND a.disciplina_id = :disciplina_id";
if (!empty($status_filtro)) $sql_count .= " AND a.status = :status";
if (!empty($busca)) $sql_count .= " AND (a.titulo LIKE :busca OR a.descricao LIKE :busca)";

$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Buscar atividade para edição
$atividade_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("
        SELECT * FROM atividades 
        WHERE id = :id AND professor_id = :professor_id
    ");
    $stmt_editar->execute([':id' => $id_editar, ':professor_id' => $professor_id]);
    $atividade_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// Buscar alunos para lançar notas
$alunos_atividade = [];
if (isset($_GET['lancar']) && isset($_GET['id'])) {
    $id_atividade = (int)$_GET['id'];
    $stmt_atv = $conn->prepare("SELECT turma_id, disciplina_id FROM atividades WHERE id = :id AND professor_id = :professor_id");
    $stmt_atv->execute([':id' => $id_atividade, ':professor_id' => $professor_id]);
    $atv = $stmt_atv->fetch(PDO::FETCH_ASSOC);
    
    if ($atv) {
        $stmt_alunos = $conn->prepare("
            SELECT e.id, e.nome, e.matricula
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa'
            ORDER BY e.nome
        ");
        $stmt_alunos->execute([':turma_id' => $atv['turma_id']]);
        $alunos_atividade = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar notas já lançadas
        $stmt_notas = $conn->prepare("SELECT aluno_id, nota FROM lancamentos_notas WHERE atividade_id = :atividade_id");
        $stmt_notas->execute([':atividade_id' => $id_atividade]);
        $notas_lancadas = [];
        while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
            $notas_lancadas[$row['aluno_id']] = $row['nota'];
        }
    }
}

// Processar lançamento de notas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'lancar_notas') {
    $atividade_id = (int)$_POST['atividade_id'];
    
    try {
        $conn->beginTransaction();
        
        // Remover lançamentos anteriores
        $stmt_del = $conn->prepare("DELETE FROM lancamentos_notas WHERE atividade_id = :atividade_id");
        $stmt_del->execute([':atividade_id' => $atividade_id]);
        
        // Inserir novos lançamentos
        $stmt_insert = $conn->prepare("
            INSERT INTO lancamentos_notas (atividade_id, aluno_id, nota, lancado_por, data_lancamento)
            VALUES (:atividade_id, :aluno_id, :nota, :lancado_por, NOW())
        ");
        
        foreach ($_POST['nota'] as $aluno_id => $nota) {
            if ($nota !== '') {
                $stmt_insert->execute([
                    ':atividade_id' => $atividade_id,
                    ':aluno_id' => $aluno_id,
                    ':nota' => $nota,
                    ':lancado_por' => $professor_id
                ]);
            }
        }
        
        $conn->commit();
        $success = "Notas lançadas com sucesso!";
        
        // Redirecionar para limpar o GET
        header("Location: atividades.php?success=1");
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao lançar notas: " . $e->getMessage();
    }
}

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success">Ativo</span>';
    } else {
        return '<span class="badge bg-secondary">Inativo</span>';
    }
}

function getTipoBadge($tipo) {
    $tipos = [
        'trabalho' => '<span class="badge bg-primary">Trabalho</span>',
        'prova' => '<span class="badge bg-danger">Prova</span>',
        'exercicio' => '<span class="badge bg-info">Exercício</span>',
        'outro' => '<span class="badge bg-secondary">Outro</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge bg-secondary">' . $tipo . '</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atividades | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .atividade-card {
            transition: transform 0.2s;
            border-left: 4px solid #006B3E;
        }
        .atividade-card:hover {
            transform: translateY(-3px);
        }
        .pagination .page-link {
            color: #006B3E;
        }
        .pagination .active .page-link {
            background-color: #006B3E;
            border-color: #006B3E;
            color: white;
        }
        .data-vencida {
            color: #dc3545;
            font-weight: bold;
        }
        .data-proxima {
            color: #ffc107;
        }
        .data-normal {
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-tasks"></i> Atividades e Trabalhos</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAtividade" onclick="resetForm()">
                <i class="fas fa-plus"></i> Nova Atividade
            </button>
        </div>
        
        <?php if (isset($_GET['success']) || $success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success ?: "Operação realizada com sucesso!"; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filtro == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Título ou descrição" value="<?php echo htmlspecialchars($busca); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Lista de Atividades -->
        <?php if (empty($atividades)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhuma atividade encontrada.
                <button type="button" class="btn btn-sm btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#modalAtividade">
                    <i class="fas fa-plus"></i> Criar primeira atividade
                </button>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($atividades as $atividade): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card atividade-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0"><?php echo htmlspecialchars($atividade['titulo']); ?></h6>
                                <?php echo getStatusBadge($atividade['status']); ?>
                            </div>
                            <p class="card-text small text-muted mb-2">
                                <?php echo htmlspecialchars(substr($atividade['descricao'] ?? '', 0, 100)); ?>
                                <?php if (strlen($atividade['descricao'] ?? '') > 100) echo '...'; ?>
                            </p>
                            <div class="mb-2">
                                <?php echo getTipoBadge($atividade['tipo']); ?>
                                <span class="badge bg-secondary"><?php echo $atividade['valor_maximo']; ?> pts</span>
                            </div>
                            <div class="small">
                                <div><i class="fas fa-users"></i> Turma: <?php echo $atividade['turma_ano'] . 'ª ' . $atividade['turma_nome']; ?></div>
                                <div><i class="fas fa-book"></i> Disciplina: <?php echo htmlspecialchars($atividade['disciplina_nome']); ?></div>
                                <div>
                                    <i class="fas fa-calendar-alt"></i> Entrega: 
                                    <?php if ($atividade['data_entrega']): ?>
                                        <span class="<?php 
                                            echo strtotime($atividade['data_entrega']) < time() ? 'data-vencida' : 
                                                (strtotime($atividade['data_entrega']) < strtotime('+7 days') ? 'data-proxima' : 'data-normal'); 
                                        ?>">
                                            <?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?>
                                        </span>
                                    <?php else: ?>
                                        Sem data definida
                                    <?php endif; ?>
                                </div>
                                <div><i class="fas fa-check-circle"></i> Lançamentos: <?php echo $atividade['total_lancamentos']; ?></div>
                            </div>
                        </div>
                        <div class="card-footer bg-white">
                            <div class="btn-group w-100">
                                <a href="?lancar=1&id=<?php echo $atividade['id']; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-pen-alt"></i> Lançar Notas
                                </a>
                                <a href="?editar=1&id=<?php echo $atividade['id']; ?>" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?acao=excluir&id=<?php echo $atividade['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza que deseja excluir esta atividade?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
            <nav aria-label="Paginação" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    </li>
                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fim = min($total_paginas, $pagina + 2);
                    for ($i = $inicio; $i <= $fim; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $i; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            Próximo <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal Adicionar/Editar Atividade -->
    <div class="modal fade" id="modalAtividade" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-tasks"></i> Adicionar Atividade</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAtividade">
                    <input type="hidden" name="id" id="atividade_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Título</label>
                            <input type="text" name="titulo" id="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Turma</label>
                                <select name="turma_id" id="turma_id_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>">
                                        <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Disciplina</label>
                                <select name="disciplina_id" id="disciplina_id_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                    <option value="<?php echo $disciplina['id']; ?>">
                                        <?php echo htmlspecialchars($disciplina['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="tipo" class="form-select">
                                    <option value="trabalho">Trabalho</option>
                                    <option value="prova">Prova</option>
                                    <option value="exercicio">Exercício</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Valor Máximo</label>
                                <input type="number" name="valor_maximo" id="valor_maximo" class="form-control" step="0.5" value="10">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Entrega</label>
                                <input type="date" name="data_entrega" id="data_entrega" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                    <label class="form-check-label" for="status">
                                        Ativo
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Atividade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Lançar Notas -->
    <?php if (isset($_GET['lancar']) && isset($_GET['id']) && !empty($alunos_atividade)): ?>
    <div class="modal fade show" id="modalLancarNotas" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-pen-alt"></i> Lançar Notas da Atividade</h5>
                    <a href="atividades.php" class="btn-close btn-close-white"></a>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="lancar_notas">
                    <input type="hidden" name="atividade_id" value="<?php echo $_GET['id']; ?>">
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Aluno</th>
                                        <th>Matrícula</th>
                                        <th>Nota (0-<?php echo $atv['valor_maximo'] ?? 10; ?>)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alunos_atividade as $index => $aluno): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        <td>
                                            <input type="number" step="0.5" min="0" max="<?php echo $atv['valor_maximo'] ?? 10; ?>" 
                                                   name="nota[<?php echo $aluno['id']; ?>]" class="form-control" style="width: 100px"
                                                   value="<?php echo $notas_lancadas[$aluno['id']] ?? ''; ?>">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="atividades.php" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">Salvar Notas</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetForm() {
            $('#formAtividade')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-tasks"></i> Adicionar Atividade');
            $('#atividade_id').val('');
            $('#status').prop('checked', true);
        }
        
        <?php if ($atividade_editar): ?>
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Atividade');
            $('#acao').val('editar');
            $('#atividade_id').val('<?php echo $atividade_editar['id']; ?>');
            $('#titulo').val('<?php echo addslashes($atividade_editar['titulo']); ?>');
            $('#descricao').val('<?php echo addslashes($atividade_editar['descricao']); ?>');
            $('#turma_id_select').val('<?php echo $atividade_editar['turma_id']; ?>');
            $('#disciplina_id_select').val('<?php echo $atividade_editar['disciplina_id']; ?>');
            $('#tipo').val('<?php echo $atividade_editar['tipo']; ?>');
            $('#valor_maximo').val('<?php echo $atividade_editar['valor_maximo']; ?>');
            $('#data_entrega').val('<?php echo $atividade_editar['data_entrega']; ?>');
            $('#status').prop('checked', <?php echo $atividade_editar['status'] == 'ativo' ? 'true' : 'false'; ?>);
            
            $('#modalAtividade').modal('show');
        });
        <?php endif; ?>
    </script>
</body>
</html>