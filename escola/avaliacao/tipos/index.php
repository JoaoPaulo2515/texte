<?php
// escola/avaliacao/tipos/index.php - Gestão de Tipos de Avaliação

require_once __DIR__ . '/../../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
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
$acao = $_GET['acao'] ?? $_POST['acao'] ?? 'listar';
$tipo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status_filtro = $_GET['status'] ?? 'todos';
$categoria_filtro = $_GET['categoria'] ?? 'todas';

// ============================================
// CATEGORIAS DE TIPOS DE AVALIAÇÃO
// ============================================
$categorias = [
    'prova' => 'Provas',
    'trabalho' => 'Trabalhos',
    'teste' => 'Testes',
    'exame' => 'Exames',
    'atividade' => 'Atividades'
];

// ============================================
// NÍVEIS DE ENSINO
// ============================================
$niveis_ensino = [
    '1ciclo' => '1º Ciclo (1ª - 4ª Classe)',
    '2ciclo' => '2º Ciclo (5ª - 6ª Classe)',
    '3ciclo' => '3º Ciclo (7ª - 9ª Classe)',
    'medio' => 'Ensino Médio (10ª - 12ª/13ª Classe)'
];

// ============================================
// FUNÇÃO PARA GERAR CÓDIGO AUTOMÁTICO
// ============================================
function gerarCodigo($categoria, $conn, $escola_id) {
    $prefixos = [
        'prova' => 'PROV',
        'trabalho' => 'TRAB',
        'teste' => 'TEST',
        'exame' => 'EXAM',
        'atividade' => 'ATIV'
    ];
    
    $prefixo = $prefixos[$categoria] ?? 'AVAL';
    
    $sql = "SELECT codigo FROM tipos_avaliacao 
            WHERE escola_id = :escola_id 
            AND codigo LIKE :prefixo 
            ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':prefixo' => $prefixo . '%'
    ]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo && preg_match('/(\d+)$/', $ultimo['codigo'], $matches)) {
        $numero = (int)$matches[1] + 1;
    } else {
        $numero = 1;
    }
    
    return $prefixo . str_pad($numero, 3, '0', STR_PAD_LEFT);
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Registrar novo tipo de avaliação
if ($acao == 'registrar' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = $_POST['nome'];
    $codigo = $_POST['codigo'] ?? '';
    $categoria = $_POST['categoria'];
    $nivel_ensino = $_POST['nivel_ensino'];
    $descricao = $_POST['descricao'] ?? '';
    $peso_padrao = (float)$_POST['peso_padrao'];
    $escala_maxima = (int)$_POST['escala_maxima'];
    $cor = $_POST['cor'] ?? '#006B3E';
    $icone = $_POST['icone'] ?? 'fa-file-alt';
    $ordem = (int)$_POST['ordem'];
    
    $errors = [];
    if (empty($nome)) $errors[] = "Informe o nome do tipo de avaliação";
    if (empty($categoria)) $errors[] = "Selecione uma categoria";
    if ($peso_padrao <= 0) $errors[] = "Informe um peso válido";
    
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO tipos_avaliacao (nome, codigo, categoria, nivel_ensino, descricao, peso_padrao, escala_maxima, cor, icone, ordem, escola_id, status, data_criacao)
                    VALUES (:nome, :codigo, :categoria, :nivel_ensino, :descricao, :peso_padrao, :escala_maxima, :cor, :icone, :ordem, :escola_id, 'ativo', NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':codigo' => $codigo,
                ':categoria' => $categoria,
                ':nivel_ensino' => $nivel_ensino,
                ':descricao' => $descricao,
                ':peso_padrao' => $peso_padrao,
                ':escala_maxima' => $escala_maxima,
                ':cor' => $cor,
                ':icone' => $icone,
                ':ordem' => $ordem,
                ':escola_id' => $escola_id
            ]);
            
            $mensagem_sucesso = "Tipo de avaliação registrado com sucesso!";
            $acao = 'listar';
        } catch (PDOException $e) {
            $erro = "Erro ao registrar: " . $e->getMessage();
        }
    } else {
        $erro = implode("<br>", $errors);
    }
}

// Atualizar tipo de avaliação
if ($acao == 'editar' && $_SERVER['REQUEST_METHOD'] == 'POST' && $tipo_id > 0) {
    $nome = $_POST['nome'];
    $codigo = $_POST['codigo'] ?? '';
    $categoria = $_POST['categoria'];
    $nivel_ensino = $_POST['nivel_ensino'];
    $descricao = $_POST['descricao'] ?? '';
    $peso_padrao = (float)$_POST['peso_padrao'];
    $escala_maxima = (int)$_POST['escala_maxima'];
    $cor = $_POST['cor'] ?? '#006B3E';
    $icone = $_POST['icone'] ?? 'fa-file-alt';
    $ordem = (int)$_POST['ordem'];
    
    try {
        $sql = "UPDATE tipos_avaliacao 
                SET nome = :nome, codigo = :codigo, categoria = :categoria, 
                    nivel_ensino = :nivel_ensino, descricao = :descricao, 
                    peso_padrao = :peso_padrao, escala_maxima = :escala_maxima,
                    cor = :cor, icone = :icone, ordem = :ordem,
                    data_atualizacao = NOW()
                WHERE id = :id AND escola_id = :escola_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':codigo' => $codigo,
            ':categoria' => $categoria,
            ':nivel_ensino' => $nivel_ensino,
            ':descricao' => $descricao,
            ':peso_padrao' => $peso_padrao,
            ':escala_maxima' => $escala_maxima,
            ':cor' => $cor,
            ':icone' => $icone,
            ':ordem' => $ordem,
            ':id' => $tipo_id,
            ':escola_id' => $escola_id
        ]);
        
        $mensagem_sucesso = "Tipo de avaliação atualizado com sucesso!";
        $acao = 'listar';
    } catch (PDOException $e) {
        $erro = "Erro ao atualizar: " . $e->getMessage();
    }
}

// Ativar/Inativar tipo
if ($acao == 'ativar' && $tipo_id > 0) {
    try {
        $sql = "UPDATE tipos_avaliacao SET status = 'ativo', data_atualizacao = NOW() 
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $tipo_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Tipo de avaliação ativado!";
    } catch (PDOException $e) {
        $erro = "Erro ao ativar: " . $e->getMessage();
    }
}

if ($acao == 'inativar' && $tipo_id > 0) {
    try {
        $sql = "UPDATE tipos_avaliacao SET status = 'inativo', data_atualizacao = NOW() 
                WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $tipo_id, ':escola_id' => $escola_id]);
        $mensagem_sucesso = "Tipo de avaliação inativado!";
    } catch (PDOException $e) {
        $erro = "Erro ao inativar: " . $e->getMessage();
    }
}

// Excluir tipo
if ($acao == 'excluir' && $tipo_id > 0) {
    try {
        $sql_check = "SELECT COUNT(*) as total FROM provas WHERE tipo_prova = (SELECT nome FROM tipos_avaliacao WHERE id = :id)";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':id' => $tipo_id]);
        $tem_uso = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($tem_uso > 0) {
            $erro = "Não é possível excluir este tipo pois já existem provas associadas!";
        } else {
            $sql = "DELETE FROM tipos_avaliacao WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $tipo_id, ':escola_id' => $escola_id]);
            $mensagem_sucesso = "Tipo de avaliação excluído!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao excluir: " . $e->getMessage();
    }
}

// ============================================
// BUSCAR TIPOS DE AVALIAÇÃO
// ============================================
$sql_tipos = "SELECT * FROM tipos_avaliacao WHERE escola_id = :escola_id";
$params = [':escola_id' => $escola_id];

if ($status_filtro != 'todos') {
    $sql_tipos .= " AND status = :status";
    $params[':status'] = $status_filtro;
}

if ($categoria_filtro != 'todas') {
    $sql_tipos .= " AND categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

$sql_tipos .= " ORDER BY ordem ASC, nome ASC";

$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute($params);
$tipos = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$estatisticas = [
    'total' => count($tipos),
    'ativos' => 0,
    'inativos' => 0,
    'por_categoria' => [],
    'por_nivel' => []
];

foreach ($categorias as $key => $cat) {
    $estatisticas['por_categoria'][$key] = 0;
}

foreach ($niveis_ensino as $key => $nivel) {
    $estatisticas['por_nivel'][$key] = 0;
}

foreach ($tipos as $tipo) {
    if ($tipo['status'] == 'ativo') {
        $estatisticas['ativos']++;
    } else {
        $estatisticas['inativos']++;
    }
    
    if (isset($estatisticas['por_categoria'][$tipo['categoria']])) {
        $estatisticas['por_categoria'][$tipo['categoria']]++;
    }
    
    if (isset($estatisticas['por_nivel'][$tipo['nivel_ensino']])) {
        $estatisticas['por_nivel'][$tipo['nivel_ensino']]++;
    }
}

// Buscar tipo para edição
$tipo_editar = null;
if ($acao == 'editar' && $tipo_id > 0) {
    $sql_edit = "SELECT * FROM tipos_avaliacao WHERE id = :id AND escola_id = :escola_id";
    $stmt_edit = $conn->prepare($sql_edit);
    $stmt_edit->execute([':id' => $tipo_id, ':escola_id' => $escola_id]);
    $tipo_editar = $stmt_edit->fetch(PDO::FETCH_ASSOC);
}

// Ícones disponíveis
$icones_disponiveis = [
    'fa-file-alt' => '📄 Documento',
    'fa-check-circle' => '✅ Check',
    'fa-star' => '⭐ Estrela',
    'fa-heart' => '❤️ Coração',
    'fa-flag' => '🏁 Bandeira',
    'fa-book' => '📚 Livro',
    'fa-pencil-alt' => '✏️ Lápis',
    'fa-chalkboard' => '📝 Quadro',
    'fa-tasks' => '📋 Tarefas',
    'fa-graduation-cap' => '🎓 Formatura',
    'fa-clock' => '⏰ Relógio',
    'fa-calendar' => '📅 Calendário',
    'fa-chart-line' => '📈 Gráfico',
    'fa-award' => '🏆 Prêmio'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tipos de Avaliação | SIGE Angola</title>
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
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .page-header h2 {
            margin: 0 0 5px 0;
            font-size: 28px;
        }
        
        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
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
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
        }
        
        .tipo-card {
            transition: all 0.3s;
            margin-bottom: 20px;
            border-left-width: 4px;
        }
        
        .tipo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-ativo {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
        }
        
        .btn-primary-custom {
            background: #006B3E;
            color: white;
            border-radius: 25px;
            padding: 10px 24px;
        }
        
        .btn-primary-custom:hover {
            background: #004d2d;
            color: white;
        }
        
        .icone-preview {
            font-size: 24px;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .info-card {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .escala-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .escala-10 { background: #e3f2fd; color: #1565c0; }
        .escala-20 { background: #fff3e0; color: #e65100; }
        
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
        }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-tags"></i> Tipos de Avaliação</h2>
                    <p>Gerencie os tipos de avaliação utilizados pela escola</p>
                </div>
                <div class="no-print">
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#modalRegistrarTipo">
                        <i class="fas fa-plus-circle text-success"></i> Novo Tipo
                    </button>
                    <a href="../dashboard.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
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
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-list fa-2x text-primary mb-2"></i>
                    <div class="stat-number"><?php echo $estatisticas['total']; ?></div>
                    <small>Total de Tipos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number text-success"><?php echo $estatisticas['ativos']; ?></div>
                    <small>Ativos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-ban fa-2x text-danger mb-2"></i>
                    <div class="stat-number text-danger"><?php echo $estatisticas['inativos']; ?></div>
                    <small>Inativos</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chart-pie fa-2x text-info mb-2"></i>
                    <div class="stat-number"><?php echo count(array_filter($estatisticas['por_categoria'])); ?></div>
                    <small>Categorias</small>
                </div>
            </div>
        </div>
        
        <!-- Distribuição por Categoria -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Distribuição por Categoria</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($categorias as $key => $categoria): 
                                $total = $estatisticas['por_categoria'][$key];
                                $percentual = $estatisticas['total'] > 0 ? round(($total / $estatisticas['total']) * 100, 1) : 0;
                            ?>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?php echo $categoria; ?></span>
                                    <span class="fw-bold"><?php echo $total; ?> tipos</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" style="width: <?php echo $percentual; ?>%; background-color: #006B3E;"></div>
                                </div>
                                <small class="text-muted"><?php echo $percentual; ?>%</small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Distribuição por Nível de Ensino -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Distribuição por Nível de Ensino</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($niveis_ensino as $key => $nivel): 
                                $total = $estatisticas['por_nivel'][$key];
                                $percentual = $estatisticas['total'] > 0 ? round(($total / $estatisticas['total']) * 100, 1) : 0;
                            ?>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?php echo $nivel; ?></span>
                                    <span class="fw-bold"><?php echo $total; ?> tipos</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" style="width: <?php echo $percentual; ?>%; background-color: #1A2A6C;"></div>
                                </div>
                                <small class="text-muted"><?php echo $percentual; ?>%</small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Ativos</option>
                        <option value="inativo" <?php echo $status_filtro == 'inativo' ? 'selected' : ''; ?>>Inativos</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Categoria</label>
                    <select name="categoria" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $categoria_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                        <?php foreach ($categorias as $key => $cat): ?>
                        <option value="<?php echo $key; ?>" <?php echo $categoria_filtro == $key ? 'selected' : ''; ?>>
                            <?php echo $cat; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Tipos -->
        <div class="row">
            <?php if (empty($tipos)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhum tipo de avaliação encontrado.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($tipos as $tipo): 
                    $escala_class = $tipo['escala_maxima'] == 10 ? 'escala-10' : 'escala-20';
                    $escala_texto = $tipo['escala_maxima'] == 10 ? '0-10' : '0-20';
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card tipo-card" style="border-left: 4px solid <?php echo $tipo['cor']; ?>;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center">
                                        <div class="icone-preview me-2" style="color: <?php echo $tipo['cor']; ?>;">
                                            <i class="fas <?php echo $tipo['icone']; ?> fa-2x"></i>
                                        </div>
                                        <div>
                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($tipo['nome']); ?></h5>
                                            <?php if ($tipo['codigo']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($tipo['codigo']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="status-badge status-<?php echo $tipo['status']; ?>">
                                        <?php echo $tipo['status'] == 'ativo' ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </div>
                                
                                <div class="mb-2">
                                    <span class="badge bg-secondary"><?php echo $categorias[$tipo['categoria']] ?? $tipo['categoria']; ?></span>
                                    <span class="badge bg-info">Peso: <?php echo number_format($tipo['peso_padrao'], 1, ',', '.'); ?></span>
                                    <span class="escala-badge <?php echo $escala_class; ?>">Escala <?php echo $escala_texto; ?></span>
                                    <span class="badge" style="background-color: <?php echo $tipo['cor']; ?>; color: white;">
                                        <i class="fas <?php echo $tipo['icone']; ?>"></i>
                                    </span>
                                </div>
                                
                                <?php if ($tipo['descricao']): ?>
                                    <p class="card-text small text-muted"><?php echo nl2br(htmlspecialchars(substr($tipo['descricao'], 0, 100))); ?></p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-sort-numeric-down"></i> Ordem: <?php echo $tipo['ordem']; ?>
                                    </small>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-info" onclick="verDetalhes(<?php echo $tipo['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="?acao=editar&id=<?php echo $tipo['id']; ?>&status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>" 
                                           class="btn btn-outline-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($tipo['status'] == 'ativo'): ?>
                                        <a href="?acao=inativar&id=<?php echo $tipo['id']; ?>&status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>" 
                                           class="btn btn-outline-danger" onclick="return confirm('Inativar este tipo?')">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="?acao=ativar&id=<?php echo $tipo['id']; ?>&status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>" 
                                           class="btn btn-outline-success" onclick="return confirm('Ativar este tipo?')">
                                            <i class="fas fa-check-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?acao=excluir&id=<?php echo $tipo['id']; ?>&status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>" 
                                           class="btn btn-outline-danger" onclick="return confirm('Excluir este tipo? Esta ação não pode ser desfeita!')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal Registrar Tipo MELHORADO -->
    <div class="modal fade no-print" id="modalRegistrarTipo" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Novo Tipo de Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formRegistrarTipo">
                    <input type="hidden" name="acao" value="registrar">
                    <div class="modal-body">
                        <!-- Informação sobre escala -->
                        <div class="info-card">
                            <i class="fas fa-info-circle text-success"></i>
                            <strong>Informação sobre a Escala de Valores:</strong>
                            <ul class="mb-0 mt-1">
                                <li><strong>1º ao 6º Ano:</strong> Escala de <strong>0 a 10 valores</strong></li>
                                <li><strong>Acima do 6º Ano:</strong> Escala de <strong>0 a 20 valores</strong></li>
                                <li>O peso máximo será ajustado automaticamente conforme o nível de ensino selecionado</li>
                            </ul>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nome *</label>
                                <input type="text" name="nome" class="form-control" required>
                                <small class="text-muted">Ex: Prova Trimestral, Trabalho de Grupo</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Código</label>
                                <div class="input-group">
                                    <input type="text" name="codigo" id="codigo_auto" class="form-control" readonly style="background-color: #e9ecef;">
                                    <button type="button" class="btn btn-outline-secondary" onclick="gerarCodigoManual()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Gerado automaticamente com base na categoria</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria *</label>
                                <select name="categoria" id="categoria_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($categorias as $key => $cat): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nível de Ensino *</label>
                                <select name="nivel_ensino" id="nivel_ensino_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($niveis_ensino as $key => $nivel): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $nivel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Peso Padrão *</label>
                                <input type="number" step="0.5" name="peso_padrao" id="peso_padrao" class="form-control" value="10.0" required>
                                <small class="text-muted" id="peso_info">Valor da avaliação (0-10 para 1º-6º ano / 0-20 para +6º ano)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Escala Máxima</label>
                                <input type="number" name="escala_maxima" id="escala_maxima" class="form-control" value="20" readonly style="background-color: #e9ecef;">
                                <small class="text-muted">Definido automaticamente pelo nível de ensino</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Cor</label>
                                <div class="input-group">
                                    <input type="color" name="cor" id="cor_picker" class="form-control form-control-color" value="#006B3E" style="width: 60px;">
                                    <input type="text" id="cor_texto" class="form-control" value="#006B3E" readonly>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ícone</label>
                                <select name="icone" class="form-select">
                                    <?php foreach ($icones_disponiveis as $icone => $label): ?>
                                    <option value="<?php echo $icone; ?>"><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ordem</label>
                                <input type="number" name="ordem" class="form-control" value="0">
                                <small class="text-muted">Ordem de exibição (menor primeiro)</small>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Descrição</label>
                                <textarea name="descricao" class="form-control" rows="3" placeholder="Descrição detalhada deste tipo de avaliação"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar Tipo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Tipo -->
    <?php if ($acao == 'editar' && $tipo_editar): ?>
    <div class="modal fade no-print show" id="modalEditarTipo" tabindex="-1" style="display: block;">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Tipo de Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="window.location.href='?status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>'"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="editar">
                    <input type="hidden" name="id" value="<?php echo $tipo_editar['id']; ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nome *</label>
                                <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($tipo_editar['nome']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Código</label>
                                <input type="text" name="codigo" class="form-control" value="<?php echo htmlspecialchars($tipo_editar['codigo']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Categoria *</label>
                                <select name="categoria" class="form-select" required>
                                    <?php foreach ($categorias as $key => $cat): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $tipo_editar['categoria'] == $key ? 'selected' : ''; ?>>
                                        <?php echo $cat; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nível de Ensino *</label>
                                <select name="nivel_ensino" class="form-select" required>
                                    <?php foreach ($niveis_ensino as $key => $nivel): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $tipo_editar['nivel_ensino'] == $key ? 'selected' : ''; ?>>
                                        <?php echo $nivel; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Peso Padrão *</label>
                                <input type="number" step="0.5" name="peso_padrao" class="form-control" value="<?php echo $tipo_editar['peso_padrao']; ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Escala Máxima</label>
                                <input type="number" name="escala_maxima" class="form-control" value="<?php echo $tipo_editar['escala_maxima']; ?>" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Cor</label>
                                <div class="input-group">
                                    <input type="color" name="cor" class="form-control form-control-color" value="<?php echo $tipo_editar['cor']; ?>" style="width: 60px;">
                                    <input type="text" class="form-control" value="<?php echo $tipo_editar['cor']; ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ícone</label>
                                <select name="icone" class="form-select">
                                    <?php foreach ($icones_disponiveis as $icone => $label): ?>
                                    <option value="<?php echo $icone; ?>" <?php echo $tipo_editar['icone'] == $icone ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Ordem</label>
                                <input type="number" name="ordem" class="form-control" value="<?php echo $tipo_editar['ordem']; ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Descrição</label>
                                <textarea name="descricao" class="form-control" rows="3"><?php echo htmlspecialchars($tipo_editar['descricao']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='?status=<?php echo $status_filtro; ?>&categoria=<?php echo $categoria_filtro; ?>'">Cancelar</button>
                        <button type="submit" class="btn btn-success">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    <?php endif; ?>
    
    <!-- Modal Detalhes -->
    <div class="modal fade no-print" id="modalDetalhes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalhes do Tipo de Avaliação</h5>
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
    <script>
        // Função para gerar código automático via AJAX
        function gerarCodigoAutomatico() {
            var categoria = $('#categoria_select').val();
            if (categoria) {
                $.ajax({
                    url: 'ajax_gerar_codigo.php',
                    method: 'POST',
                    data: { categoria: categoria },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#codigo_auto').val(response.codigo);
                        }
                    },
                    error: function() {
                        gerarCodigoManualFallback();
                    }
                });
            }
        }
        
        // Função para gerar código manual (fallback)
        function gerarCodigoManual() {
            var categoria = $('#categoria_select').val();
            if (categoria) {
                gerarCodigoManualFallback();
            } else {
                alert('Selecione uma categoria primeiro');
            }
        }
        
        function gerarCodigoManualFallback() {
            var categoria = $('#categoria_select').val();
            var prefixos = {
                'prova': 'PROV',
                'trabalho': 'TRAB',
                'teste': 'TEST',
                'exame': 'EXAM',
                'atividade': 'ATIV'
            };
            var prefixo = prefixos[categoria] || 'AVAL';
            var numero = Math.floor(Math.random() * 900) + 100;
            $('#codigo_auto').val(prefixo + numero);
        }
        
        // Atualizar escala e peso baseado no nível de ensino
        function atualizarPorNivelEnsino() {
            var nivel = $('#nivel_ensino_select').val();
            var $pesoInput = $('#peso_padrao');
            var $escalaInput = $('#escala_maxima');
            var $pesoInfo = $('#peso_info');
            
            if (nivel === '1ciclo' || nivel === '2ciclo') {
                // 1º ao 6º ano - escala 0-10
                $escalaInput.val(10);
                $pesoInput.attr({
                    'max': 10,
                    'min': 0,
                    'step': 0.5
                });
                if (parseFloat($pesoInput.val()) > 10) {
                    $pesoInput.val(10);
                }
                $pesoInfo.html('<i class="fas fa-info-circle"></i> Escala 0-10 valores (1º ao 6º ano)');
                $pesoInfo.css('color', '#1565c0');
            } else {
                // Acima do 6º ano - escala 0-20
                $escalaInput.val(20);
                $pesoInput.attr({
                    'max': 20,
                    'min': 0,
                    'step': 0.5
                });
                $pesoInfo.html('<i class="fas fa-info-circle"></i> Escala 0-20 valores (ensino médio)');
                $pesoInfo.css('color', '#e65100');
            }
        }
        
        // Ver detalhes do tipo
        function verDetalhes(id) {
            const modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
            document.getElementById('detalhesConteudo').innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><br>Carregando...</div>';
            modal.show();
            
            $.ajax({
                url: 'ajax_tipo_detalhes.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let html = `
                            <table class="table table-bordered">
                                <tr><th width="35%">Nome:</th><td>${response.nome || '-'}</td></tr>
                                <tr><th>Código:</th><td>${response.codigo || '-'}</td></tr>
                                <tr><th>Categoria:</th><td>${response.categoria || '-'}</td></tr>
                                <tr><th>Nível de Ensino:</th><td>${response.nivel_ensino || '-'}</td></tr>
                                <tr><th>Peso Padrão:</th><td>${response.peso_padrao || '-'} valores</td></tr>
                                <tr><th>Escala Máxima:</th><td>${response.escala_maxima || '-'} valores</td></tr>
                                <td><th>Ordem:</th><td>${response.ordem || '-'}</td></tr>
                                <tr><th>Status:</th><td><span class="status-badge status-${response.status}">${response.status == 'ativo' ? 'Ativo' : 'Inativo'}</span></td></tr>
                                <tr><th>Descrição:</th><td>${response.descricao || '-'}</td></tr>
                            </table>
                        `;
                        document.getElementById('detalhesConteudo').innerHTML = html;
                    } else {
                        document.getElementById('detalhesConteudo').innerHTML = '<div class="alert alert-danger">Erro ao carregar detalhes: ' + (response.message || 'Erro desconhecido') + '</div>';
                    }
                },
                error: function() {
                    document.getElementById('detalhesConteudo').innerHTML = '<div class="alert alert-danger">Erro de conexão com o servidor</div>';
                }
            });
        }
        
        // Eventos
        $('#categoria_select').on('change', function() {
            gerarCodigoAutomatico();
        });
        
        $('#nivel_ensino_select').on('change', function() {
            atualizarPorNivelEnsino();
        });
        
        $('#cor_picker').on('change', function() {
            $('#cor_texto').val($(this).val());
        });
        
        // Inicializar
        $(document).ready(function() {
            atualizarPorNivelEnsino();
        });
    </script>
</body>
</html>