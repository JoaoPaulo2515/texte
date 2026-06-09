<?php
// escola/aluno/biblioteca/emprestimos.php - Meus Empréstimos da Biblioteca

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Meus Empréstimos';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR EMPRÉSTIMOS DO ALUNO
// ==============================================
$sql_emprestimos = "SELECT 
                        e.id,
                        e.data_emprestimo,
                        e.data_devolucao_prevista,
                        e.data_devolucao_real,
                        e.status,
                        e.renovacoes,
                        e.observacoes,
                        a.id as acervo_id,
                        a.titulo,
                        a.autor,
                        a.editora,
                        a.edicao,
                        a.ano_publicacao,
                        a.isbn,
                        a.codigo_barras,
                        a.sinopse,
                        a.capa,
                        a.arquivo_pdf,
                        a.localizacao,
                        a.categoria_id,
                        u.nome as bibliotecario_nome
                    FROM emprestimos e
                    INNER JOIN acervo_livros a ON a.id = e.acervo_id AND a.escola_id = e.escola_id
                    LEFT JOIN usuarios u ON u.id = e.bibliotecario_id
                    WHERE e.aluno_id = :aluno_id 
                    AND e.escola_id = :escola_id";

if ($status_filtro != 'todos') {
    $sql_emprestimos .= " AND e.status = :status";
}
if (!empty($busca)) {
    $sql_emprestimos .= " AND (a.titulo LIKE :busca OR a.autor LIKE :busca OR a.isbn LIKE :busca OR a.codigo_barras LIKE :busca)";
}

$sql_emprestimos .= " ORDER BY e.status = 'ativo' DESC, e.data_devolucao_prevista ASC, e.data_emprestimo DESC";

$stmt_emprestimos = $conn->prepare($sql_emprestimos);
$params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_emprestimos->execute($params);
$emprestimos = $stmt_emprestimos->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_emprestimos = count($emprestimos);
$total_ativos = 0;
$total_atrasados = 0;
$total_devolvidos = 0;
$total_renovacoes = 0;

foreach ($emprestimos as $emp) {
    if ($emp['status'] == 'ativo') {
        $total_ativos++;
        if (strtotime($emp['data_devolucao_prevista']) < time()) {
            $total_atrasados++;
        }
    } elseif ($emp['status'] == 'devolvido') {
        $total_devolvidos++;
    }
    $total_renovacoes += $emp['renovacoes'];
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusEmprestimoBadge($status, $data_prevista = null) {
    if ($status == 'devolvido') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Devolvido</span>';
    } elseif ($status == 'cancelado') {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelado</span>';
    } elseif ($status == 'ativo') {
        if ($data_prevista && strtotime($data_prevista) < time()) {
            return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
        }
        return '<span class="badge bg-warning text-dark"><i class="fas fa-book"></i> Em andamento</span>';
    }
    return '<span class="badge bg-secondary">' . $status . '</span>';
}

function getTipoMaterialIcone($tipo = 'livro') {
    // Como é acervo_livros, todos são livros
    return '<i class="fas fa-book"></i>';
}

function getTipoMaterialLabel($tipo = 'livro') {
    return 'Livro';
}

function formatarData($data, $formato = 'd/m/Y') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function calcularDiasRestantes($data_prevista) {
    if (empty($data_prevista)) return '-';
    $hoje = new DateTime();
    $prevista = new DateTime($data_prevista);
    $diferenca = $hoje->diff($prevista);
    
    if ($hoje > $prevista) {
        return '<span class="text-danger">Atrasado ' . $diferenca->days . ' dias</span>';
    } else {
        return '<span class="text-success">' . $diferenca->days . ' dias restantes</span>';
    }
}

function getCorDataPrevista($data_prevista) {
    if (empty($data_prevista)) return '';
    $dias = (strtotime($data_prevista) - time()) / 86400;
    if ($dias < 0) return 'text-danger fw-bold';
    if ($dias <= 3) return 'text-warning fw-bold';
    return 'text-success';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .emprestimo-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .emprestimo-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .emprestimo-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            cursor: pointer;
            background: #f8f9fa;
        }
        .emprestimo-body {
            padding: 20px;
            display: none;
        }
        .emprestimo-body.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        .emprestimo-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
        }
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
        
        .capa-material {
            width: 100px;
            height: 130px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .codigo-barras {
            font-family: monospace;
            font-size: 0.8rem;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 5px;
            display: inline-block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno { display: none; }
            .emprestimo-card { break-inside: avoid; page-break-inside: avoid; }
            .emprestimo-body { display: block !important; }
        }
        
        .btn-renovar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .btn-renovar:hover {
            background: #138496;
        }
        
        .multa-badge {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
        }
        
        .sinopse-resumo {
            max-height: 100px;
            overflow-y: auto;
            font-size: 0.85rem;
            color: #555;
            line-height: 1.4;
        }
    </style>
</head>
<body>
      <?php include '../includes/menu_aluno.php'; ?>
   
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Meus Empréstimos</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todos os seus empréstimos de livros da biblioteca.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status dos Empréstimos</div>
                <div class="ajuda-texto">
                    <span class="badge bg-warning text-dark">Em andamento</span> - Livro emprestado<br>
                    <span class="badge bg-danger">Atrasado</span> - Devolução fora do prazo<br>
                    <span class="badge bg-success">Devolvido</span> - Livro já devolvido
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Renovação</div>
                <div class="ajuda-texto">Você pode renovar o empréstimo até 3 vezes, desde que não esteja atrasado.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Multas</div>
                <div class="ajuda-texto">Empréstimos devolvidos com atraso estão sujeitos a multa conforme regimento da biblioteca.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-hand-holding"></i> Meus Empréstimos</h4>
            <p class="text-muted mb-0">Gerencie seus empréstimos de livros</p>
        </div>
        <div>
            <a href="acervo.php" class="btn btn-primary">
                <i class="fas fa-search"></i> Consultar Acervo
            </a>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-book"></i> Total Empréstimos</small>
                    <h6 class="mb-0"><?php echo $total_emprestimos; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_ativos; ?></div>
                <div class="stat-label"><i class="fas fa-book-open text-warning"></i> Em andamento</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_atrasados; ?></div>
                <div class="stat-label"><i class="fas fa-exclamation-triangle text-danger"></i> Atrasados</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_devolvidos; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle text-success"></i> Devolvidos</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $total_renovacoes; ?></div>
                <div class="stat-label"><i class="fas fa-sync-alt text-info"></i> Renovações</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Em andamento</option>
                        <option value="devolvido" <?php echo $status_filtro == 'devolvido' ? 'selected' : ''; ?>>Devolvidos</option>
                        <option value="cancelado" <?php echo $status_filtro == 'cancelado' ? 'selected' : ''; ?>>Cancelados</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título, autor, ISBN ou código de barras..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="emprestimos.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Empréstimos -->
    <?php if (empty($emprestimos)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhum empréstimo encontrado</h5>
            <p>Você ainda não realizou nenhum empréstimo na biblioteca.</p>
            <a href="acervo.php" class="btn btn-primary mt-2">
                <i class="fas fa-search"></i> Consultar Acervo
            </a>
        </div>
    <?php else: ?>
        <div class="emprestimos-list">
            <?php foreach ($emprestimos as $emp): 
                $is_atrasado = ($emp['status'] == 'ativo' && strtotime($emp['data_devolucao_prevista']) < time());
                $pode_renovar = ($emp['status'] == 'ativo' && $emp['renovacoes'] < 3 && !$is_atrasado);
            ?>
            <div class="emprestimo-card fade-in" id="emprestimo-<?php echo $emp['id']; ?>">
                <div class="emprestimo-header" onclick="toggleEmprestimo(<?php echo $emp['id']; ?>)">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-book fa-lg text-primary"></i>
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($emp['titulo']); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($emp['autor']); ?></small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <?php echo getStatusEmprestimoBadge($emp['status'], $emp['data_devolucao_prevista']); ?>
                        <i class="fas fa-chevron-down toggle-icon" id="toggle-icon-<?php echo $emp['id']; ?>"></i>
                    </div>
                </div>
                
                <div class="emprestimo-body" id="emprestimo-body-<?php echo $emp['id']; ?>">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <?php if (!empty($emp['capa'])): ?>
                                <img src="<?php echo $emp['capa']; ?>" class="capa-material" alt="Capa do Livro">
                            <?php else: ?>
                                <div class="capa-material mx-auto bg-light">
                                    <i class="fas fa-book fa-4x text-secondary"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="130"><strong>Título:</strong></td>
                                            <td><?php echo htmlspecialchars($emp['titulo']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Autor:</strong></td>
                                            <td><?php echo htmlspecialchars($emp['autor'] ?: 'Não informado'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Editora:</strong></td>
                                            <td><?php echo htmlspecialchars($emp['editora'] ?: 'Não informada'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Edição:</strong></td>
                                            <td><?php echo htmlspecialchars($emp['edicao'] ?: '1ª'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Ano:</strong></td>
                                            <td><?php echo $emp['ano_publicacao'] ?: '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>ISBN:</strong></td>
                                            <td><?php echo $emp['isbn'] ?: '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Código Barras:</strong></td>
                                            <td><span class="codigo-barras"><?php echo $emp['codigo_barras'] ?: '-'; ?></span></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="130"><strong>Data Empréstimo:</strong></td>
                                            <td><?php echo formatarData($emp['data_emprestimo']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Data Prevista:</strong></td>
                                            <td class="<?php echo getCorDataPrevista($emp['data_devolucao_prevista']); ?>">
                                                <?php echo formatarData($emp['data_devolucao_prevista']); ?>
                                                <small>(<?php echo calcularDiasRestantes($emp['data_devolucao_prevista']); ?>)</small>
                                            </td>
                                        </tr>
                                        <?php if ($emp['data_devolucao_real']): ?>
                                        <tr>
                                            <td><strong>Data Devolução:</strong></td>
                                            <td><?php echo formatarData($emp['data_devolucao_real']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <td><strong>Renovações:</strong></td>
                                            <td><?php echo $emp['renovacoes']; ?> / 3</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Localização:</strong></td>
                                            <td><?php echo $emp['localizacao'] ?: 'Biblioteca Central'; ?></td>
                                        </tr>
                                        <?php if ($emp['bibliotecario_nome']): ?>
                                        <tr>
                                            <td><strong>Bibliotecário:</strong></td>
                                            <td><?php echo htmlspecialchars($emp['bibliotecario_nome']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                            
                            <?php if (!empty($emp['sinopse'])): ?>
                            <div class="mt-3">
                                <strong>Sinopse:</strong>
                                <div class="sinopse-resumo mt-1">
                                    <?php echo nl2br(htmlspecialchars($emp['sinopse'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($emp['observacoes']): ?>
                            <div class="mt-3">
                                <strong>Observações:</strong>
                                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($emp['observacoes']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="emprestimo-footer">
                    <div>
                        <?php if ($is_atrasado): ?>
                            <span class="multa-badge"><i class="fas fa-money-bill-wave"></i> Multa aplicada</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($pode_renovar): ?>
                            <button class="btn-renovar" onclick="renovarEmprestimo(<?php echo $emp['id']; ?>)">
                                <i class="fas fa-sync-alt"></i> Renovar Empréstimo
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($emp['arquivo_pdf'])): ?>
                            <a href="<?php echo $emp['arquivo_pdf']; ?>" target="_blank" class="btn btn-sm btn-outline-info ms-2">
                                <i class="fas fa-file-pdf"></i> Ler Online
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Função para expandir/colapsar empréstimo
    function toggleEmprestimo(id) {
        const body = document.getElementById('emprestimo-body-' + id);
        const icon = document.getElementById('toggle-icon-' + id);
        
        body.classList.toggle('show');
        icon.classList.toggle('rotated');
    }
    
    // Função para renovar empréstimo
    function renovarEmprestimo(id) {
        if (confirm('Deseja renovar este empréstimo? A nova data de devolução será estendida em 7 dias.')) {
            $.ajax({
                url: 'renovar_emprestimo.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Empréstimo renovado com sucesso! Nova data: ' + response.nova_data);
                        location.reload();
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao renovar empréstimo. Tente novamente.');
                }
            });
        }
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
    
    // Expandir empréstimo específico via URL
    const urlParams = new URLSearchParams(window.location.search);
    const empId = urlParams.get('emp');
    if (empId) {
        const body = document.getElementById('emprestimo-body-' + empId);
        const icon = document.getElementById('toggle-icon-' + empId);
        if (body) {
            body.classList.add('show');
            icon.classList.add('rotated');
            document.getElementById('emprestimo-' + empId)?.scrollIntoView({ behavior: 'smooth' });
        }
    }
</script>
</body>
</html>