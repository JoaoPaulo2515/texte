<?php
// escola/relatorios/professor.php - Relatório de Professores

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
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO E AÇÃO
// ============================================
$acao = $_GET['acao'] ?? 'listar';
$professor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status_filtro = $_GET['status'] ?? 'todos';
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$search = $_GET['search'] ?? '';

// ============================================
// BUSCAR DISCIPLINAS PARA FILTRO
// ============================================
$sql_disciplinas = "SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id ORDER BY nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Atualizar status do professor
if ($acao == 'ativar' && $professor_id > 0) {
    try {
        $sql = "UPDATE funcionarios SET status = 'ativo' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $professor_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Professor ativado com sucesso!";
    } catch (PDOException $e) {
        $erro = "Erro ao ativar professor: " . $e->getMessage();
    }
}

if ($acao == 'inativar' && $professor_id > 0) {
    try {
        $sql = "UPDATE funcionarios SET status = 'inativo' WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $professor_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Professor inativado com sucesso!";
    } catch (PDOException $e) {
        $erro = "Erro ao inativar professor: " . $e->getMessage();
    }
}

// Excluir professor
if ($acao == 'excluir' && $professor_id > 0) {
    try {
        // Verificar se o professor tem turmas atribuídas
        $sql_check = "SELECT COUNT(*) as total FROM professor_disciplina_turma WHERE professor_id = :professor_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':professor_id' => $professor_id]);
        $tem_turmas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($tem_turmas > 0) {
            $erro = "Não é possível excluir este professor pois ele possui turmas atribuídas!";
        } else {
            $sql = "DELETE FROM funcionarios WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $professor_id, ':escola_id' => $escola_id]);
            $mensagem_sucesso = "Professor excluído com sucesso!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao excluir professor: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR PROFESSORES COM FILTROS
// ============================================
$sql_professores = "SELECT p.*, 
                    COUNT(DISTINCT pdt.turma_id) as total_turmas,
                    COUNT(DISTINCT pdt.disciplina_id) as total_disciplinas,
                    GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas_nomes
                    FROM funcionarios p
                    LEFT JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                    LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
                    WHERE p.escola_id = :escola_id  and p.tipo_funcionario='professor'";

$params = [':escola_id' => $escola_id];

if ($status_filtro != 'todos') {
    $sql_professores .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if ($disciplina_id > 0) {
    $sql_professores .= " AND pdt.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}

if (!empty($search)) {
    $sql_professores .= " AND (p.nome LIKE :search_nome 
                            OR p.email LIKE :search_email 
                            OR p.telefone LIKE :search_telefone 
                            OR p.bi LIKE :search_bi)";
    $search_value = "%$search%";
    $params[':search_nome'] = $search_value;
    $params[':search_email'] = $search_value;
    $params[':search_telefone'] = $search_value;
    $params[':search_bi'] = $search_value;
}

$sql_professores .= " GROUP BY p.id ORDER BY p.nome";

$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute($params);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALCULAR ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total' => 0,
    'ativos' => 0,
    'inativos' => 0,
    'total_turmas' => 0,
    'total_disciplinas' => 0,
    'professores_por_disciplina' => []
];

foreach ($professores as $professor) {
    $estatisticas['total']++;
    if ($professor['status'] == 'ativo') {
        $estatisticas['ativos']++;
    } else {
        $estatisticas['inativos']++;
    }
    $estatisticas['total_turmas'] += $professor['total_turmas'];
    $estatisticas['total_disciplinas'] += $professor['total_disciplinas'];
}

// Contar professores por disciplina
$sql_disc_count = "SELECT d.nome, COUNT(DISTINCT pdt.professor_id) as total 
                   FROM disciplinas d
                   LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                   WHERE d.escola_id = :escola_id
                   GROUP BY d.id
                   ORDER BY total DESC";
$stmt_disc_count = $conn->prepare($sql_disc_count);
$stmt_disc_count->execute([':escola_id' => $escola_id]);
$estatisticas['professores_por_disciplina'] = $stmt_disc_count->fetchAll(PDO::FETCH_ASSOC);

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, nuit FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Professores | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }
        
        .stat-number.total { color: #006B3E; }
        .stat-number.ativos { color: #28a745; }
        .stat-number.inativos { color: #dc3545; }
        
        .stat-label {
            color: #666;
            font-size: 13px;
            margin-top: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-ativo { background: #d4edda; color: #155724; }
        .status-inativo { background: #f8d7da; color: #721c24; }
        
        .btn-action {
            padding: 4px 8px;
            margin: 2px;
            border-radius: 5px;
        }
        
        .profile-img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .table-professores th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-professores td {
            vertical-align: middle;
        }
        
        .btn-export {
            border-radius: 25px;
            padding: 10px 24px;
            margin: 5px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
        }
        
        .btn-pdf { background-color: #dc3545; color: white; border: none; }
        .btn-excel { background-color: #28a745; color: white; border: none; }
        .btn-print { background-color: #17a2b8; color: white; border: none; }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            .table-professores th {
                background: #ccc !important;
                color: black !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-user"></i> Relatório de Professores</h2>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
       <!-- Botões de Exportação -->
<div class="row mb-4 no-print">
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="mb-3"><i class="fas fa-download"></i> Exportar Relatório</h6>
                <div class="d-flex justify-content-center flex-wrap">
                    <a href="gerar_pdf_professores.php?status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>" 
                       class="btn-export btn-pdf" target="_blank">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="gerar_excel_professores.php?status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>" 
                       class="btn-export btn-excel">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <button type="button" class="btn-export btn-print" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-success mb-2"></i>
                    <div class="stat-number total"><?php echo $estatisticas['total']; ?></div>
                    <div class="stat-label">Total de Professores</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <i class="fas fa-user-check fa-2x text-primary mb-2"></i>
                    <div class="stat-number ativos"><?php echo $estatisticas['ativos']; ?></div>
                    <div class="stat-label">Professores Ativos</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <i class="fas fa-user-slash fa-2x text-danger mb-2"></i>
                    <div class="stat-number inativos"><?php echo $estatisticas['inativos']; ?></div>
                    <div class="stat-label">Professores Inativos</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Ativos</option>
                        <option value="inativo" <?php echo $status_filtro == 'inativo' ? 'selected' : ''; ?>>Inativos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Pesquisar</label>
                    <input type="text" name="search" class="form-control" placeholder="Nome, Email, Telefone, BI..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Tabela de Professores -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Professores</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-professores" id="tabelaProfessores">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="8%">Foto</th>
                                <th width="20%">Nome</th>
                                <th width="12%">BI</th>
                                <th width="15%">Email</th>
                                <th width="12%">Telefone</th>
                                <th width="15%">Disciplinas</th>
                                <th width="8%">Turmas</th>
                                <th width="5%">Status</th>
                                <th width="10%" class="no-print">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professores as $index => $professor): ?>
                            <tr>
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($professor['foto']) && file_exists('../../uploads/professores/' . $professor['foto'])): ?>
                                        <img src="../../uploads/professores/<?php echo $professor['foto']; ?>" class="profile-img">
                                    <?php else: ?>
                                        <div class="profile-img bg-secondary d-flex align-items-center justify-content-center text-white">
                                            <i class="fas fa-user fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($professor['nome']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($professor['bi'] ?: '---'); ?></td>
                                <td><?php echo htmlspecialchars($professor['email'] ?: '---'); ?></td>
                                <td><?php echo htmlspecialchars($professor['telefone'] ?: '---'); ?></td>
                                <td>
                                    <?php 
                                    $disciplinas_nomes = explode(', ', $professor['disciplinas_nomes']);
                                    $disciplinas_mostrar = array_slice($disciplinas_nomes, 0, 3);
                                    foreach ($disciplinas_mostrar as $disc): 
                                        if (trim($disc)): ?>
                                            <span class="badge bg-info mb-1"><?php echo htmlspecialchars($disc); ?></span>
                                        <?php endif;
                                    endforeach;
                                    if (count($disciplinas_nomes) > 3): ?>
                                        <span class="badge bg-secondary">+<?php echo count($disciplinas_nomes) - 3; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?php echo $professor['total_turmas']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge status-<?php echo $professor['status']; ?>">
                                        <?php echo ucfirst($professor['status']); ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $professor['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($professor['status'] == 'ativo'): ?>
                                        <a href="?acao=inativar&id=<?php echo $professor['id']; ?>&status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>" 
                                           class="btn btn-sm btn-warning" onclick="return confirm('Inativar este professor?')">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="?acao=ativar&id=<?php echo $professor['id']; ?>&status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>" 
                                           class="btn btn-sm btn-success" onclick="return confirm('Ativar este professor?')">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?acao=excluir&id=<?php echo $professor['id']; ?>&status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>" 
                                           class="btn btn-sm btn-danger" onclick="return confirm('Excluir este professor? Esta ação não pode ser desfeita!')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3 text-muted small">
                    <i class="fas fa-info-circle"></i> Total de registros: <?php echo count($professores); ?> professores
                    <br>
                    <i class="fas fa-chalkboard"></i> Total de turmas atribuídas: <?php echo $estatisticas['total_turmas']; ?>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Professores por Disciplina -->
        <?php if (!empty($estatisticas['professores_por_disciplina'])): ?>
        <div class="card mt-4">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Distribuição de Professores por Disciplina</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($estatisticas['professores_por_disciplina'] as $item): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><?php echo htmlspecialchars($item['nome']); ?></span>
                            <span class="fw-bold"><?php echo $item['total']; ?> professor(es)</span>
                        </div>
                        <div class="progress">
                            <?php 
                            $porcentagem = $estatisticas['total'] > 0 ? ($item['total'] / $estatisticas['total']) * 100 : 0;
                            ?>
                            <div class="progress-bar" style="width: <?php echo $porcentagem; ?>%; background-color: #006B3E;">
                                <?php echo round($porcentagem); ?>%
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Detalhes do Professor -->
    <div class="modal fade no-print" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-chalkboard-user"></i> Detalhes do Professor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesConteudo">
                    <div class="text-center">
                        <div class="spinner-border text-primary"></div>
                        Carregando...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // DataTable
        $('#tabelaProfessores').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[2, 'asc']],
            pageLength: 25,
            columnDefs: [
                { orderable: false, targets: [1, 8, 9] }
            ]
        });
        
        // Ver detalhes do professor
        function verDetalhes(id) {
            const modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
            
            // Buscar dados via AJAX
            $.ajax({
                url: 'ajax_professor_detalhes.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        const p = data.professor;
                        let html = `
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    ${p.foto ? `<img src="../../uploads/professores/${p.foto}" class="img-fluid rounded-circle" style="width: 150px; height: 150px; object-fit: cover;">` : 
                                    `<div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width: 150px; height: 150px;">
                                        <i class="fas fa-user fa-4x text-white"></i>
                                     </div>`}
                                    <h5 class="mt-3">${p.nome}</h5>
                                    <span class="status-badge status-${p.status}">${p.status.toUpperCase()}</span>
                                </div>
                                <div class="col-md-8">
                                    <table class="table table-sm">
                                        <tr>
                                            <th width="35%">BI:</th>
                                            <td>${p.bi || '---'}</td>
                                        </tr>
                                        <tr>
                                            <th>Email:</th>
                                            <td>${p.email || '---'}</td>
                                        </tr>
                                        <tr>
                                            <th>Telefone:</th>
                                            <td>${p.telefone || '---'}</td>
                                        </tr>
                                        <tr>
                                            <th>Endereço:</th>
                                            <td>${p.endereco || '---'}</td>
                                        </tr>
                                        <tr>
                                            <th>Data de Nascimento:</th>
                                            <td>${p.data_nascimento ? new Date(p.data_nascimento).toLocaleDateString('pt-AO') : '---'}</td>
                                        </tr>
                                        <tr>
                                            <th>Gênero:</th>
                                            <td>${p.sexo == 'masculino' ? 'Masculino' : (p.sexo == 'feminino' ? 'Feminino' : '---')}</td>
                                        </tr>
                                        <tr>
                                            <th>Data de Registro:</th>
                                            <td>${p.data_registro ? new Date(p.data_registro).toLocaleDateString('pt-AO') : '---'}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <hr>
                            <h6><i class="fas fa-book"></i> Disciplinas Ministradas</h6>
                            <div class="mb-3">
                                ${p.disciplinas ? p.disciplinas.split(',').map(d => `<span class="badge bg-info m-1">${d.trim()}</span>`).join('') : '<p class="text-muted">Nenhuma disciplina atribuída</p>'}
                            </div>
                            <h6><i class="fas fa-users"></i> Turmas Atribuídas</h6>
                            <div class="mb-3">
                                ${p.turmas ? p.turmas.split(',').map(t => `<span class="badge bg-primary m-1">${t.trim()}</span>`).join('') : '<p class="text-muted">Nenhuma turma atribuída</p>'}
                            </div>
                        `;
                        $('#detalhesConteudo').html(html);
                    } else {
                        $('#detalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar dados do professor</div>');
                    }
                },
                error: function() {
                    $('#detalhesConteudo').html('<div class="alert alert-danger">Erro ao carregar dados do professor</div>');
                }
            });
            
            modal.show();
        }
        
        // Exportar PDF
        function exportarPDF() {
            window.open('gerar_pdf_professores.php?status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>', '_blank');
        }
        
        // Exportar Excel
        function exportarExcel() {
            window.location.href = 'gerar_excel_professores.php?status=<?php echo $status_filtro; ?>&disciplina_id=<?php echo $disciplina_id; ?>&search=<?php echo urlencode($search); ?>';
        }
    </script>
</body>
</html>