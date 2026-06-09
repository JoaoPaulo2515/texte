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
            return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Ativo</span>';
        case 'inativo':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Inativo</span>';
        case 'transferido':
            return '<span class="badge bg-warning"><i class="fas fa-exchange-alt me-1"></i>Transferido</span>';
        case 'concluido':
            return '<span class="badge bg-info"><i class="fas fa-graduation-cap me-1"></i>Concluído</span>';
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
    <title>Meus Alunos | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
            background: #f5f7fb;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
                margin-top: 70px;
            }
        }

        /* ============================================
           CABEÇALHO
        ============================================ */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1A2A6C;
        }

        .btn-voltar {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
            color: white;
        }

        /* ============================================
           CARDS DE TURMAS
        ============================================ */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border: none;
        }

        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }

        /* ============================================
           LISTA DE TURMAS
        ============================================ */
        .list-group-item {
            border: none;
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
            transition: all 0.3s ease;
        }

        .list-group-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }

        .list-group-item.active {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #006B3E;
            border-left: 4px solid #006B3E;
        }

        .list-group-item.active .text-muted {
            color: #006B3E !important;
        }

        /* ============================================
           FILTROS
        ============================================ */
        .filter-bar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }

        .filter-bar .form-label {
            font-weight: 600;
            color: #1A2A6C;
            margin-bottom: 8px;
        }

        .filter-bar .form-control,
        .filter-bar .form-select {
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 0.2rem rgba(0, 107, 62, 0.25);
        }

        /* ============================================
           INFO TURMA
        ============================================ */
        .info-turma {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 20px;
            color: white;
        }

        .info-turma h5 {
            margin: 0;
            font-weight: 600;
        }

        .info-turma .btn {
            border-radius: 40px;
            padding: 8px 20px;
            font-weight: 600;
            margin-left: 10px;
        }

        .info-turma .btn-primary {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
        }

        .info-turma .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .info-turma .btn-info {
            background: rgba(23, 162, 184, 0.8);
            border: none;
            color: white;
        }

        .info-turma .btn-info:hover {
            background: #17a2b8;
            transform: translateY(-2px);
        }

        /* ============================================
           TABELA DE ALUNOS
        ============================================ */
        .table-container {
            overflow-x: auto;
        }

        .table-alunos {
            width: 100%;
            border-collapse: collapse;
        }

        .table-alunos thead th {
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

        .table-alunos tbody td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .table-alunos tbody tr {
            transition: all 0.3s ease;
        }

        .table-alunos tbody tr:hover {
            background: #f8f9fa;
            transform: scale(1.01);
        }

        /* Foto do Aluno */
        .foto-aluno {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #006B3E;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .foto-aluno:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        /* Nome do Aluno */
        .aluno-nome {
            font-weight: 600;
            color: #1A2A6C;
        }

        .aluno-info {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
        }

        /* Badges */
        .badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.7rem;
        }

        /* Botões de Ação */
        .btn-acao {
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 3px;
            transition: all 0.3s ease;
        }

        .btn-acao:hover {
            transform: translateY(-2px);
        }

        /* ============================================
           PAGINAÇÃO
        ============================================ */
        .pagination {
            gap: 5px;
        }

        .pagination .page-item .page-link {
            border-radius: 12px;
            color: #006B3E;
            border: 1px solid #dee2e6;
            padding: 8px 15px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .pagination .page-item .page-link:hover {
            background: #006B3E;
            color: white;
            transform: translateY(-2px);
        }

        .pagination .active .page-link {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-color: #006B3E;
            color: white;
        }

        /* ============================================
           MODAL DE IMAGEM
        ============================================ */
        .modal-img {
            max-width: 100%;
            max-height: 500px;
            object-fit: contain;
            border-radius: 15px;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            border: none;
        }

        .alert-info-custom {
            background: linear-gradient(135deg, #cfe2ff 0%, #b8d4ff 100%);
            color: #084298;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .info-turma .text-md-end {
                text-align: left !important;
                margin-top: 15px;
            }
            
            .info-turma .btn {
                width: 100%;
                margin: 5px 0;
            }
            
            .table-alunos thead th,
            .table-alunos tbody td {
                padding: 10px;
                font-size: 0.7rem;
            }
            
            .foto-aluno {
                width: 35px;
                height: 35px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
        </br></br>
    <div class="main-content">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3 fade-in">
            <div>
                <h2><i class="fas fa-users me-2"></i> Meus Alunos</h2>
                <p class="text-muted">Visualize e gerencie os alunos das suas turmas</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>
        
        <div class="row">
            <!-- Sidebar com Turmas -->
            <div class="col-md-3 fade-in">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-building me-2"></i> Minhas Turmas</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($turmas as $turma): ?>
                        <a href="?turma_id=<?php echo $turma['id']; ?>" 
                           class="list-group-item list-group-item-action <?php echo $turma_id == $turma['id'] ? 'active' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno']); ?>
                                        <?php if (!empty($turma['sala'])): ?>
                                        | <i class="fas fa-door-open"></i> Sala <?php echo $turma['sala']; ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        
                        <?php if (empty($turmas)): ?>
                        <div class="list-group-item text-center text-muted">
                            <i class="fas fa-info-circle me-2"></i> Nenhuma turma associada
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Resumo da Turma -->
                <?php if ($turma_atual): ?>
                <div class="card mt-3 fade-in">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-simple me-2"></i> Resumo da Turma</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h3><?php echo $turma_atual['ano'] . 'ª ' . htmlspecialchars($turma_atual['nome']); ?></h3>
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
                                    <small class="text-muted">Matriculados</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <h4 class="text-success mb-0"><?php echo $total_alunos; ?></h4>
                                    <small class="text-muted">Ativos</small>
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
                <div class="filter-bar fade-in">
                    <form method="GET" class="row align-items-end">
                        <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                        <div class="col-md-5">
                            <label class="form-label"><i class="fas fa-search me-1"></i> Buscar Aluno</label>
                            <input type="text" name="busca" class="form-control" placeholder="Nome, Matrícula ou BI" value="<?php echo htmlspecialchars($busca); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-filter me-1"></i> Status</label>
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
                                <i class="fas fa-search me-2"></i> Filtrar
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Info da Turma -->
                <div class="info-turma fade-in">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="mb-0">
                                <i class="fas fa-users me-2"></i> 
                                Alunos da Turma <?php echo $turma_atual['ano'] . 'ª ' . htmlspecialchars($turma_atual['nome']); ?>
                            </h5>
                            <small class="opacity-75">Total de <?php echo $total_alunos; ?> alunos encontrados</small>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-pen-alt me-1"></i> Lançar Notas
                            </a>
                            <a href="registrar_chamada.php?turma_id=<?php echo $turma_id; ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-clipboard-list me-1"></i> Chamada
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Tabela de Alunos -->
                <div class="card fade-in">
                    <div class="card-body p-0">
                        <div class="table-container">
                            <table class="table table-alunos mb-0">
                                <thead>
                                    <tr>
                                        <th width="8%">Foto</th>
                                        <th width="25%">Nome</th>
                                        <th width="12%">Matrícula</th>
                                        <th width="10%">BI</th>
                                        <th width="15%">Contacto</th>
                                        <th width="10%">Status</th>
                                        <th width="12%">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($alunos)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            <i class="fas fa-user-graduate fa-3x mb-3 d-block"></i>
                                            <h5>Nenhum aluno encontrado</h5>
                                            <p class="mb-0">Tente ajustar os filtros de busca</p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($alunos as $aluno): ?>
                                        <tr>
                                            <td class="text-center">
                                                <img src="<?php echo (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])) ? '../../uploads/alunos/fotos/' . $aluno['foto'] : '../../assets/images/avatar-padrao.png'; ?>" 
                                                     class="foto-aluno" 
                                                     onclick="abrirModalImagem(this.src, '<?php echo htmlspecialchars($aluno['nome']); ?>')"
                                                     style="cursor: pointer;">
                                            </td>
                                            <td class="text-start">
                                                <div class="aluno-nome"><?php echo htmlspecialchars($aluno['nome']); ?></div>
                                                <div class="aluno-info">
                                                    <i class="fas fa-calendar-alt"></i> Nasc: <?php echo formatarData($aluno['data_nascimento']); ?>
                                                    | <i class="fas fa-venus-mars"></i> <?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : 'N/E'); ?>
                                                    | <i class="fas fa-birthday-cake"></i> <?php echo calcularIdade($aluno['data_nascimento']); ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php echo htmlspecialchars($aluno['matricula']); ?>
                                                <div class="aluno-info"><?php echo getMatriculaStatusBadge($aluno['matricula_status']); ?></div>
                                            </td>
                                            <td class="text-center"><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                                            <td class="text-start">
                                                <?php if (!empty($aluno['email'])): ?>
                                                    <div><i class="fas fa-envelope text-muted me-1"></i> <small><?php echo htmlspecialchars(substr($aluno['email'], 0, 20)); ?></small></div>
                                                <?php endif; ?>
                                                <?php if (!empty($aluno['telefone'])): ?>
                                                    <div><i class="fas fa-phone text-muted me-1"></i> <small><?php echo htmlspecialchars($aluno['telefone']); ?></small></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php echo getStatusBadge($aluno['aluno_status']); ?></td>
                                            <td class="text-center">
                                                <a href="ver_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-info btn-acao" title="Ver detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>&aluno_id=<?php echo $aluno['id']; ?>" class="btn btn-primary btn-acao" title="Lançar notas">
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
                    <div class="alert alert-danger text-center fade-in">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>
                        <h5>Turma não encontrada</h5>
                        <p class="mb-0">Você não tem acesso a esta turma ou ela não existe.</p>
                    </div>
                <?php else: ?>
                    <div class="alert-custom alert-info-custom fade-in">
                        <i class="fas fa-info-circle fa-3x mb-3"></i>
                        <h5>Selecione uma turma</h5>
                        <p class="mb-0">Clique em uma turma no menu ao lado para visualizar os alunos.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal para Ampliar Imagem -->
    <div class="modal fade" id="modalImagem" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i> Foto do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImagemSrc" src="" alt="Foto do Aluno" class="modal-img">
                    <p id="modalImagemNome" class="mt-3 fw-bold"></p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Função para abrir modal de imagem
        function abrirModalImagem(src, nome) {
            document.getElementById('modalImagemSrc').src = src;
            document.getElementById('modalImagemNome').innerHTML = '<i class="fas fa-user me-2"></i>' + nome;
            new bootstrap.Modal(document.getElementById('modalImagem')).show();
        }
        
        // Animações ao scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.fade-in').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>