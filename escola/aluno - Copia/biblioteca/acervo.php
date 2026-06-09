<?php
// aluno/biblioteca/acervo.php - Acervo da Biblioteca

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, tur.nome as turma_nome, tur.ano as turma_ano
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Reservar livro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reservar') {
    $livro_id = (int)$_POST['livro_id'];
    $data_reserva = date('Y-m-d');
    $data_devolucao_prevista = date('Y-m-d', strtotime('+7 days'));
    
    // Verificar se o livro está disponível
    $sql_check = "SELECT quantidade_disponivel FROM acervo_livros 
                  WHERE id = :id AND escola_id = :escola_id AND status = 'ativo'";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([
        ':id' => $livro_id,
        ':escola_id' => $escola_id
    ]);
    $livro = $stmt_check->fetch();
    
    if ($livro && $livro['quantidade_disponivel'] > 0) {
        // Verificar se aluno já tem reserva ativa deste livro
        $sql_verifica = "SELECT id FROM acervo_emprestimos 
                         WHERE livro_id = :livro_id AND aluno_id = :aluno_id 
                         AND status IN ('reservado', 'emprestado')
                         AND data_devolucao_real IS NULL";
        $stmt_verifica = $conn->prepare($sql_verifica);
        $stmt_verifica->execute([
            ':livro_id' => $livro_id,
            ':aluno_id' => $aluno_id
        ]);
        
        if ($stmt_verifica->fetch()) {
            $mensagem_erro = "Você já possui uma reserva ou empréstimo ativo deste livro.";
        } else {
            // Criar reserva
            $sql_reserva = "INSERT INTO acervo_emprestimos 
                           (escola_id, livro_id, aluno_id, data_emprestimo, data_devolucao_prevista, status)
                           VALUES (:escola_id, :livro_id, :aluno_id, :data_reserva, :data_devolucao, 'reservado')";
            $stmt_reserva = $conn->prepare($sql_reserva);
            $result = $stmt_reserva->execute([
                ':escola_id' => $escola_id,
                ':livro_id' => $livro_id,
                ':aluno_id' => $aluno_id,
                ':data_reserva' => $data_reserva,
                ':data_devolucao' => $data_devolucao_prevista
            ]);
            
            if ($result) {
                // Atualizar quantidade disponível
                $sql_update = "UPDATE acervo_livros 
                              SET quantidade_disponivel = quantidade_disponivel - 1,
                                  quantidade_emprestada = quantidade_emprestada + 1
                              WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':id' => $livro_id]);
                
                $mensagem_sucesso = "Livro reservado com sucesso! Retire-o na biblioteca até " . date('d/m/Y', strtotime($data_devolucao_prevista));
            } else {
                $mensagem_erro = "Erro ao reservar livro. Tente novamente.";
            }
        }
    } else {
        $mensagem_erro = "Livro não disponível para empréstimo.";
    }
}

// ============================================
// FILTROS
// ============================================
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$busca_filtro = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$disponibilidade_filtro = isset($_GET['disponibilidade']) ? $_GET['disponibilidade'] : 'todos';

// Buscar categorias
$sql_categorias = "SELECT id, nome, cor, icone FROM acervo_categorias 
                   WHERE escola_id = :escola_id AND status = 'ativo'
                   ORDER BY nome";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Buscar livros do acervo
$sql_livros = "SELECT l.*, 
                      c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone,
                      CASE 
                          WHEN l.quantidade_disponivel > 0 THEN 'disponivel'
                          WHEN l.quantidade_disponivel = 0 AND l.quantidade_total > 0 THEN 'indisponivel'
                          ELSE 'esgotado'
                      END as status_livro
               FROM acervo_livros l
               LEFT JOIN acervo_categorias c ON c.id = l.categoria_id
               WHERE l.escola_id = :escola_id AND l.status = 'ativo'";

if ($categoria_filtro > 0) {
    $sql_livros .= " AND l.categoria_id = :categoria_id";
}

if (!empty($busca_filtro)) {
    $sql_livros .= " AND (l.titulo LIKE :busca OR l.autor LIKE :busca OR l.isbn LIKE :busca OR l.editora LIKE :busca)";
}

if ($disponibilidade_filtro == 'disponivel') {
    $sql_livros .= " AND l.quantidade_disponivel > 0";
} elseif ($disponibilidade_filtro == 'indisponivel') {
    $sql_livros .= " AND l.quantidade_disponivel = 0 AND l.quantidade_total > 0";
}

$sql_livros .= " ORDER BY l.titulo ASC";

$stmt_livros = $conn->prepare($sql_livros);
$params = [':escola_id' => $escola_id];
if ($categoria_filtro > 0) {
    $params[':categoria_id'] = $categoria_filtro;
}
if (!empty($busca_filtro)) {
    $params[':busca'] = "%$busca_filtro%";
}
$stmt_livros->execute($params);
$livros = $stmt_livros->fetchAll(PDO::FETCH_ASSOC);

// Buscar empréstimos ativos do aluno
$sql_emprestimos = "SELECT e.*, l.titulo as livro_titulo, l.autor as livro_autor, l.capa as livro_capa,
                           DATEDIFF(e.data_devolucao_prevista, CURDATE()) as dias_restantes
                    FROM acervo_emprestimos e
                    JOIN acervo_livros l ON l.id = e.livro_id
                    WHERE e.aluno_id = :aluno_id 
                    AND e.status IN ('reservado', 'emprestado')
                    ORDER BY e.data_devolucao_prevista ASC";
$stmt_emprestimos = $conn->prepare($sql_emprestimos);
$stmt_emprestimos->execute([':aluno_id' => $aluno_id]);
$emprestimos = $stmt_emprestimos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_livros = count($livros);
$total_disponiveis = count(array_filter($livros, function($l) { return $l['quantidade_disponivel'] > 0; }));
$total_emprestimos = count($emprestimos);
$emprestimos_vencidos = count(array_filter($emprestimos, function($e) { return $e['dias_restantes'] < 0; }));

include '../includes/menu_aluno.php';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acervo da Biblioteca | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); transition: transform 0.3s; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .livro-card {
            transition: all 0.3s;
            cursor: pointer;
            height: 100%;
        }
        .livro-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .livro-capa {
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            width: 100%;
        }
        
        .categoria-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-disponivel { background: #28a745; color: white; }
        .status-indisponivel { background: #dc3545; color: white; }
        .status-esgotado { background: #6c757d; color: white; }
        
        .emprestimo-card {
            transition: all 0.3s;
        }
        .emprestimo-card:hover {
            background: #f8f9fa;
        }
        .dias-positivo { color: #28a745; }
        .dias-negativo { color: #dc3545; font-weight: bold; }
        
        .btn-reservar, .btn-pdf {
            transition: all 0.3s;
        }
        .btn-reservar:hover, .btn-pdf:hover {
            transform: scale(1.05);
        }
        
        .btn-pdf {
            background: #dc3545;
            color: white;
            border: none;
        }
        .btn-pdf:hover {
            background: #c82333;
        }
        
        .acoes-livro {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        
        @media print {
            .no-print { display: none; }
            .livro-card { break-inside: avoid; }
        }
        
        /* Modal do Leitor PDF */
        .pdf-modal .modal-dialog {
            max-width: 95%;
            width: 95%;
            height: 90%;
            margin: 2% auto;
        }
        .pdf-modal .modal-content {
            height: 100%;
            border-radius: 15px;
        }
        .pdf-modal .modal-body {
            height: calc(100% - 60px);
            padding: 0;
        }
        .pdf-iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0 0 15px 15px;
        }
    </style>
</head>
<body>
    <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-book"></i> Acervo da Biblioteca</h2>
                <p class="text-muted">Consulte, leia e reserve livros disponíveis na biblioteca</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($mensagem_erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $mensagem_erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_livros; ?></div>
                    <div class="stat-label"><i class="fas fa-book"></i> Total de Livros</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo $total_disponiveis; ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle"></i> Disponíveis</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_emprestimos; ?></div>
                    <div class="stat-label"><i class="fas fa-hand-holding"></i> Seus Empréstimos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-danger"><?php echo $emprestimos_vencidos; ?></div>
                    <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Em atraso</div>
                </div>
            </div>
        </div>
        
        <!-- Meus Empréstimos Ativos -->
        <?php if (!empty($emprestimos)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-hand-holding"></i> Meus Empréstimos Ativos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Livro</th>
                                <th>Autor</th>
                                <th>Data Empréstimo</th>
                                <th>Devolução Prevista</th>
                                <th>Status</th>
                                <th>Dias Restantes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emprestimos as $emp): ?>
                            <tr class="emprestimo-card">
                                <td>
                                    <strong><?php echo htmlspecialchars($emp['livro_titulo']); ?></strong>
                                 </td>
                                 <td><?php echo htmlspecialchars($emp['livro_autor']); ?></td>
                                 <td><?php echo date('d/m/Y', strtotime($emp['data_emprestimo'])); ?></td>
                                 <td><?php echo date('d/m/Y', strtotime($emp['data_devolucao_prevista'])); ?></td>
                                 <td>
                                    <?php if ($emp['status'] == 'reservado'): ?>
                                        <span class="badge bg-info">Reservado</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Emprestado</span>
                                    <?php endif; ?>
                                 </td>
                                <td class="<?php echo $emp['dias_restantes'] >= 0 ? 'dias-positivo' : 'dias-negativo'; ?>">
                                    <?php if ($emp['dias_restantes'] >= 0): ?>
                                        <i class="fas fa-clock"></i> <?php echo $emp['dias_restantes']; ?> dias
                                    <?php else: ?>
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo abs($emp['dias_restantes']); ?> dias de atraso
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Categoria</label>
                        <select name="categoria" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoria_filtro == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Disponibilidade</label>
                        <select name="disponibilidade" class="form-select">
                            <option value="todos" <?php echo $disponibilidade_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="disponivel" <?php echo $disponibilidade_filtro == 'disponivel' ? 'selected' : ''; ?>>Disponíveis</option>
                            <option value="indisponivel" <?php echo $disponibilidade_filtro == 'indisponivel' ? 'selected' : ''; ?>>Indisponíveis</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título, autor, ISBN..." value="<?php echo htmlspecialchars($busca_filtro); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($categoria_filtro > 0 || !empty($busca_filtro) || $disponibilidade_filtro != 'todos'): ?>
                        <a href="acervo.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Livros -->
        <div class="row">
            <?php if (empty($livros)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
                            <h5>Nenhum livro encontrado</h5>
                            <p class="text-muted">Não há livros disponíveis com os filtros selecionados.</p>
                            <a href="acervo.php" class="btn btn-primary mt-2">Limpar filtros</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($livros as $livro): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card livro-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="categoria-badge" style="background: <?php echo $livro['categoria_cor'] ?? '#006B3E'; ?>20; color: <?php echo $livro['categoria_cor'] ?? '#006B3E'; ?>">
                                    <i class="fas <?php echo $livro['categoria_icone'] ?? 'fa-book'; ?>"></i>
                                    <?php echo htmlspecialchars($livro['categoria_nome'] ?? 'Geral'); ?>
                                </span>
                                <span class="status-<?php echo $livro['status_livro']; ?> status-disponivel badge">
                                    <?php if ($livro['status_livro'] == 'disponivel'): ?>
                                        <i class="fas fa-check-circle"></i> Disponível
                                    <?php elseif ($livro['status_livro'] == 'indisponivel'): ?>
                                        <i class="fas fa-times-circle"></i> Indisponível
                                    <?php else: ?>
                                        <i class="fas fa-ban"></i> Esgotado
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="text-center mb-3">
                                <?php if (!empty($livro['capa'])): ?>
                                <img src="<?php echo $livro['capa']; ?>" class="livro-capa img-fluid" alt="<?php echo htmlspecialchars($livro['titulo']); ?>" style="height: 200px; object-fit: cover;">
                                <?php else: ?>
                                <div class="livro-capa bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                    <i class="fas fa-book fa-4x text-secondary"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <h6 class="card-title"><?php echo htmlspecialchars($livro['titulo']); ?></h6>
                            <p class="card-text small text-muted">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($livro['autor']); ?>
                                <?php if ($livro['edicao']): ?>
                                <br><i class="fas fa-tag"></i> <?php echo $livro['edicao']; ?>ª Edição
                                <?php endif; ?>
                                <?php if ($livro['editora']): ?>
                                <br><i class="fas fa-building"></i> <?php echo htmlspecialchars($livro['editora']); ?>
                                <?php endif; ?>
                                <?php if ($livro['ano_publicacao']): ?>
                                <br><i class="fas fa-calendar-alt"></i> <?php echo $livro['ano_publicacao']; ?>
                                <?php endif; ?>
                            </p>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div>
                                    <small class="text-muted">
                                        <i class="fas fa-copy"></i> Total: <?php echo $livro['quantidade_total']; ?>
                                        <i class="fas fa-hand-holding ms-2"></i> Disp.: <?php echo $livro['quantidade_disponivel']; ?>
                                    </small>
                                </div>
                                <div class="acoes-livro">
                                    <?php if (!empty($livro['arquivo_pdf'])): ?>
                                    <button class="btn btn-sm btn-pdf" onclick="abrirPDF('<?php echo $livro['arquivo_pdf']; ?>', '<?php echo htmlspecialchars($livro['titulo']); ?>')" title="Ler livro online">
                                        <i class="fas fa-file-pdf"></i> Ler PDF
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($livro['status_livro'] == 'disponivel'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="reservar">
                                        <input type="hidden" name="livro_id" value="<?php echo $livro['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success btn-reservar" onclick="return confirm('Deseja reservar este livro?')">
                                            <i class="fas fa-shopping-cart"></i> Reservar
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled>
                                        <i class="fas fa-ban"></i> Indisponível
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Regras da Biblioteca -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-gavel"></i> Regras da Biblioteca</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-calendar-week fa-2x text-primary me-3"></i>
                            <div>
                                <strong>Prazo de empréstimo</strong>
                                <p class="mb-0 small">7 dias para livros didáticos</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-book fa-2x text-warning me-3"></i>
                            <div>
                                <strong>Limite de livros</strong>
                                <p class="mb-0 small">Máximo 3 livros por vez</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-clock fa-2x text-danger me-3"></i>
                            <div>
                                <strong>Multa por atraso</strong>
                                <p class="mb-0 small">100 Kz por dia de atraso</p>
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Horário de funcionamento:</strong> Segunda a Sexta, das 8h às 17h | Sábado, das 8h às 12h
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal do Leitor PDF -->
    <div class="modal fade pdf-modal" id="pdfModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="pdfModalTitle">
                        <i class="fas fa-file-pdf"></i> Leitor de PDF
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="pdfIframe" class="pdf-iframe" src=""></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="#" id="pdfDownloadLink" class="btn btn-danger" target="_blank">
                        <i class="fas fa-download"></i> Baixar PDF
                    </a>
                    <a href="#" id="pdfFullscreenLink" class="btn btn-primary" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Abrir em Nova Janela
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Abrir PDF no modal
        function abrirPDF(pdfPath, titulo) {
            // Atualizar título do modal
            document.getElementById('pdfModalTitle').innerHTML = '<i class="fas fa-file-pdf"></i> ' + titulo;
            
            // Configurar iframe
            const iframe = document.getElementById('pdfIframe');
            iframe.src = pdfPath;
            
            // Configurar link de download
            const downloadLink = document.getElementById('pdfDownloadLink');
            downloadLink.href = pdfPath;
            downloadLink.download = titulo + '.pdf';
            
            // Configurar link para abrir em nova janela
            const fullscreenLink = document.getElementById('pdfFullscreenLink');
            fullscreenLink.href = pdfPath;
            
            // Abrir modal
            new bootstrap.Modal(document.getElementById('pdfModal')).show();
        }
        
        // Abrir PDF em nova janela (função alternativa)
        function abrirPDFNovaJanela(pdfPath, titulo) {
            window.open(pdfPath, '_blank');
        }
        
        // Limpar iframe ao fechar modal
        document.getElementById('pdfModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('pdfIframe').src = '';
        });
    </script>
</body>
</html>