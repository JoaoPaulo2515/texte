<?php
// escola/pedagogico/plano_ensino.php - Plano de Ensino

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// ============================================
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `planos_ensino` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `turma_id` INT NOT NULL,
    `disciplina_id` INT NOT NULL,
    `ano_letivo_id` INT NOT NULL,
    `titulo` VARCHAR(255) NOT NULL,
    `objetivos` TEXT,
    `conteudo` TEXT,
    `metodologia` TEXT,
    `recursos` TEXT,
    `avaliacao` TEXT,
    `bibliografia` TEXT,
    `carga_horaria` INT DEFAULT 60,
    `bimestre` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";
try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Inserir novo plano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $titulo = trim($_POST['titulo']);
    $objetivos = trim($_POST['objetivos']);
    $conteudo = trim($_POST['conteudo']);
    $metodologia = trim($_POST['metodologia']);
    $recursos = trim($_POST['recursos']);
    $avaliacao = trim($_POST['avaliacao']);
    $bibliografia = trim($_POST['bibliografia']);
    $carga_horaria = (int)$_POST['carga_horaria'];
    $bimestre = (int)$_POST['bimestre'];
    
    $sql = "INSERT INTO planos_ensino (escola_id, turma_id, disciplina_id, ano_letivo_id, titulo, objetivos, conteudo, metodologia, recursos, avaliacao, bibliografia, carga_horaria, bimestre, created_at) 
            VALUES (:escola_id, :turma_id, :disciplina_id, :ano_letivo_id, :titulo, :objetivos, :conteudo, :metodologia, :recursos, :avaliacao, :bibliografia, :carga_horaria, :bimestre, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id,
        ':titulo' => $titulo,
        ':objetivos' => $objetivos,
        ':conteudo' => $conteudo,
        ':metodologia' => $metodologia,
        ':recursos' => $recursos,
        ':avaliacao' => $avaliacao,
        ':bibliografia' => $bibliografia,
        ':carga_horaria' => $carga_horaria,
        ':bimestre' => $bimestre
    ]);
    
    $mensagem = "Plano de ensino cadastrado com sucesso!";
}

// Atualizar plano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $plano_id = (int)$_POST['plano_id'];
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $titulo = trim($_POST['titulo']);
    $objetivos = trim($_POST['objetivos']);
    $conteudo = trim($_POST['conteudo']);
    $metodologia = trim($_POST['metodologia']);
    $recursos = trim($_POST['recursos']);
    $avaliacao = trim($_POST['avaliacao']);
    $bibliografia = trim($_POST['bibliografia']);
    $carga_horaria = (int)$_POST['carga_horaria'];
    $bimestre = (int)$_POST['bimestre'];
    
    $sql = "UPDATE planos_ensino SET 
            turma_id = :turma_id, disciplina_id = :disciplina_id, ano_letivo_id = :ano_letivo_id,
            titulo = :titulo, objetivos = :objetivos, conteudo = :conteudo,
            metodologia = :metodologia, recursos = :recursos, avaliacao = :avaliacao,
            bibliografia = :bibliografia, carga_horaria = :carga_horaria, bimestre = :bimestre,
            updated_at = NOW()
            WHERE id = :plano_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id,
        ':titulo' => $titulo,
        ':objetivos' => $objetivos,
        ':conteudo' => $conteudo,
        ':metodologia' => $metodologia,
        ':recursos' => $recursos,
        ':avaliacao' => $avaliacao,
        ':bibliografia' => $bibliografia,
        ':carga_horaria' => $carga_horaria,
        ':bimestre' => $bimestre,
        ':plano_id' => $plano_id,
        ':escola_id' => $escola_id
    ]);
    
    $mensagem = "Plano de ensino atualizado com sucesso!";
}

// Excluir plano
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $plano_id = (int)$_GET['id'];
    $sql = "DELETE FROM planos_ensino WHERE id = :plano_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':plano_id' => $plano_id, ':escola_id' => $escola_id]);
    $mensagem = "Plano de ensino excluído com sucesso!";
}

// Buscar plano via AJAX para edição
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $plano_id = (int)$_GET['id'];
    $sql = "SELECT * FROM planos_ensino WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $plano_id, ':escola_id' => $escola_id]);
    $plano = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($plano) {
        echo json_encode(['success' => true, 'plano' => $plano]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Plano não encontrado']);
    }
    exit;
}

// ============================================
// BUSCAR DADOS PARA O FORMULÁRIO
// ============================================

// ANOS LETIVOS
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// TURMAS
$sql_turmas = "
    SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// DISCIPLINAS
$sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas ORDER BY nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute();
$disciplinas_lista = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// PARÂMETROS DE FILTRO
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR PLANOS DE ENSINO
// ============================================
$sql_planos = "
    SELECT p.*, 
           t.nome as turma_nome, t.ano as turma_ano,
           d.nome as disciplina_nome, d.codigo as disciplina_codigo,
           al.ano as ano_letivo_ano
    FROM planos_ensino p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    INNER JOIN ano_letivo al ON al.id = p.ano_letivo_id
    WHERE p.escola_id = :escola_id
";

if ($turma_id > 0) {
    $sql_planos .= " AND p.turma_id = :turma_id";
}
if ($disciplina_id > 0) {
    $sql_planos .= " AND p.disciplina_id = :disciplina_id";
}
if ($ano_letivo_id > 0) {
    $sql_planos .= " AND p.ano_letivo_id = :ano_letivo_id";
}
if ($bimestre_filtro > 0) {
    $sql_planos .= " AND p.bimestre = :bimestre";
}

$sql_planos .= " ORDER BY p.created_at DESC";

$stmt_planos = $conn->prepare($sql_planos);
$params = [':escola_id' => $escola_id];
if ($turma_id > 0) {
    $params[':turma_id'] = $turma_id;
}
if ($disciplina_id > 0) {
    $params[':disciplina_id'] = $disciplina_id;
}
if ($ano_letivo_id > 0) {
    $params[':ano_letivo_id'] = $ano_letivo_id;
}
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_planos->execute($params);
$planos = $stmt_planos->fetchAll(PDO::FETCH_ASSOC);

// Calcular ano letivo para exibição
$ano_letivo_ano = '';
if ($ano_letivo_id > 0) {
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plano de Ensino - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-body { padding: 25px; }
        
        .filtros-row { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end; }
        .filtro-group { flex: 1; min-width: 180px; }
        .filtro-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 12px; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; }
        .filtro-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .filtro-select:focus { border-color: #1e5799; outline: none; box-shadow: 0 0 0 3px rgba(30,87,153,0.1); }
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-filtrar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-novo {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        .btn-novo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(30,87,153,0.3); }
        
        .table-planos { width: 100%; border-collapse: collapse; }
        .table-planos th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-planos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-planos tr:hover { background: #f8f9fa; }
        
        .badge-bimestre {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-bim1 { background: #d4edda; color: #155724; }
        .badge-bim2 { background: #d1ecf1; color: #0c5460; }
        .badge-bim3 { background: #fff3cd; color: #856404; }
        .badge-bim4 { background: #f8d7da; color: #721c24; }
        
        .btn-acao {
            padding: 5px 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s ease;
            margin: 0 2px;
        }
        .btn-editar { background: #17a2b8; color: white; }
        .btn-editar:hover { background: #138496; transform: translateY(-2px); }
        .btn-excluir { background: #dc3545; color: white; }
        .btn-excluir:hover { background: #c82333; transform: translateY(-2px); }
        .btn-ver { background: #6c757d; color: white; }
        .btn-ver:hover { background: #5a6268; transform: translateY(-2px); }
        
        /* Modal Styles */
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-custom-content {
            background: white;
            margin: 2% auto;
            width: 90%;
            max-width: 900px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-custom-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
        }
        .modal-custom-header h3 { font-size: 20px; margin: 0; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        .close-modal:hover { color: #ddd; }
        .modal-custom-body { padding: 25px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { font-weight: 600; font-size: 13px; color: #2c3e50; margin-bottom: 8px; display: block; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1e5799;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30,87,153,0.1);
        }
        textarea.form-control { resize: vertical; min-height: 100px; }
        
        .btn-salvar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-salvar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-info { background: #d4e6f1; color: #1e5799; border-left: 4px solid #1e5799; }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-planos { font-size: 11px; }
            .table-planos th, .table-planos td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 5% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-book-open"></i> Plano de Ensino</h1>
            <p>Gestão dos planos de ensino por disciplina, turma e bimestre</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if (isset($mensagem)): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="card">
        <div class="card-header"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select">
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select">
                            <option value="0">Todas</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno_nome']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select">
                            <option value="0">Todas</option>
                            <?php foreach ($disciplinas_lista as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo ($disciplina_id == $d['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['nome']); ?> (<?php echo htmlspecialchars($d['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="0">Todos</option>
                            <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                            <option value="4" <?php echo ($bimestre_filtro == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar"><i class="fas fa-search"></i> Filtrar</button>
                        <button type="button" class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Plano</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Planos -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Planos de Ensino
            <span class="badge bg-light text-dark ms-2"><?php echo count($planos); ?> registros</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($planos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-book fa-3x mb-3"></i>
                    <p>Nenhum plano de ensino encontrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro plano</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-planos">
                        <thead>
                            <tr>
                                <th>Turma</th>
                                <th>Disciplina</th>
                                <th>Título</th>
                                <th>Bimestre</th>
                                <th>Ano Letivo</th>
                                <th>Carga Horária</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($planos as $plano): 
                                $bimestre_class = '';
                                if ($plano['bimestre'] == 1) $bimestre_class = 'badge-bim1';
                                elseif ($plano['bimestre'] == 2) $bimestre_class = 'badge-bim2';
                                elseif ($plano['bimestre'] == 3) $bimestre_class = 'badge-bim3';
                                else $bimestre_class = 'badge-bim4';
                            ?>
                                <tr>
                                    <td><?php echo $plano['turma_ano']; ?>ª - <?php echo htmlspecialchars($plano['turma_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($plano['disciplina_nome']); ?> <small>(<?php echo htmlspecialchars($plano['disciplina_codigo']); ?>)</small></td>
                                    <td><strong><?php echo htmlspecialchars($plano['titulo']); ?></strong></td>
                                    <td><span class="badge-bimestre <?php echo $bimestre_class; ?>"><?php echo $plano['bimestre']; ?>º Bimestre</span></td>
                                    <td><?php echo $plano['ano_letivo_ano']; ?></td>
                                    <td><?php echo $plano['carga_horaria']; ?>h</td>
                                    <td>
                                        <button class="btn-acao btn-ver" onclick="verPlano(<?php echo $plano['id']; ?>, '<?php echo addslashes($plano['titulo']); ?>', '<?php echo addslashes($plano['objetivos']); ?>', '<?php echo addslashes($plano['conteudo']); ?>', '<?php echo addslashes($plano['metodologia']); ?>', '<?php echo addslashes($plano['recursos']); ?>', '<?php echo addslashes($plano['avaliacao']); ?>', '<?php echo addslashes($plano['bibliografia']); ?>', <?php echo $plano['carga_horaria']; ?>, <?php echo $plano['bimestre']; ?>)">
                                            <i class="fas fa-eye"></i> Ver
                                        </button>
                                        <button class="btn-acao btn-editar" onclick="editarPlano(<?php echo $plano['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="excluirPlano(<?php echo $plano['id']; ?>)">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <tr>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Plano -->
<div id="modalPlano" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Plano de Ensino</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formPlano">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="plano_id" id="plano_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Ano Letivo *</label>
                            <select name="ano_letivo_id" class="form-select" required>
                                <?php foreach ($anos_letivos as $ano): ?>
                                    <option value="<?php echo $ano['id']; ?>"><?php echo htmlspecialchars($ano['ano']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Bimestre *</label>
                            <select name="bimestre" class="form-select" required>
                                <option value="1">1º Bimestre</option>
                                <option value="2">2º Bimestre</option>
                                <option value="3">3º Bimestre</option>
                                <option value="4">4º Bimestre</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Turma *</label>
                            <select name="turma_id" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno_nome']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Disciplina *</label>
                            <select name="disciplina_id" class="form-select" required>
                                <option value="">Selecione</option>
                                <?php foreach ($disciplinas_lista as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?> (<?php echo htmlspecialchars($d['codigo']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Título do Plano *</label>
                    <input type="text" name="titulo" class="form-control" required placeholder="Ex: Plano de Ensino - 1º Bimestre">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Objetivos</label>
                    <textarea name="objetivos" class="form-control" rows="3" placeholder="Descreva os objetivos da disciplina para este bimestre"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Conteúdo Programático</label>
                    <textarea name="conteudo" class="form-control" rows="4" placeholder="Liste os conteúdos a serem abordados"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Metodologia</label>
                    <textarea name="metodologia" class="form-control" rows="3" placeholder="Descreva a metodologia a ser utilizada"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Recursos Didáticos</label>
                    <textarea name="recursos" class="form-control" rows="2" placeholder="Materiais e recursos necessários"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Avaliação</label>
                    <textarea name="avaliacao" class="form-control" rows="3" placeholder="Critérios e instrumentos de avaliação"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bibliografia</label>
                    <textarea name="bibliografia" class="form-control" rows="3" placeholder="Referências bibliográficas"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Carga Horária (horas)</label>
                    <input type="number" name="carga_horaria" class="form-control" value="60" min="1" max="200">
                </div>
                
                <div class="text-end">
                    <button type="button" class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Visualizar Plano -->
<div id="modalVerPlano" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3><i class="fas fa-eye"></i> Visualizar Plano de Ensino</h3>
            <span class="close-modal" onclick="fecharModalVer()">&times;</span>
        </div>
        <div class="modal-custom-body" id="verPlanoBody">
            <!-- Conteúdo será preenchido via JavaScript -->
        </div>
    </div>
</div>

<script>
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Plano de Ensino';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('plano_id').value = '0';
        document.getElementById('formPlano').reset();
        document.getElementById('modalPlano').style.display = 'block';
    }
    
    function editarPlano(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Plano de Ensino';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('plano_id').value = id;
        
        // Buscar dados do plano via AJAX (mesmo arquivo)
        fetch(`plano_ensino.php?ajax=1&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const p = data.plano;
                    document.querySelector('select[name="ano_letivo_id"]').value = p.ano_letivo_id;
                    document.querySelector('select[name="bimestre"]').value = p.bimestre;
                    document.querySelector('select[name="turma_id"]').value = p.turma_id;
                    document.querySelector('select[name="disciplina_id"]').value = p.disciplina_id;
                    document.querySelector('input[name="titulo"]').value = p.titulo;
                    document.querySelector('textarea[name="objetivos"]').value = p.objetivos;
                    document.querySelector('textarea[name="conteudo"]').value = p.conteudo;
                    document.querySelector('textarea[name="metodologia"]').value = p.metodologia;
                    document.querySelector('textarea[name="recursos"]').value = p.recursos;
                    document.querySelector('textarea[name="avaliacao"]').value = p.avaliacao;
                    document.querySelector('textarea[name="bibliografia"]').value = p.bibliografia;
                    document.querySelector('input[name="carga_horaria"]').value = p.carga_horaria;
                    document.getElementById('modalPlano').style.display = 'block';
                } else {
                    showToast('Erro ao carregar dados do plano', true);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro de conexão', true);
            });
    }
    
    function verPlano(id, titulo, objetivos, conteudo, metodologia, recursos, avaliacao, bibliografia, carga_horaria, bimestre) {
        const modalBody = document.getElementById('verPlanoBody');
        
        // Formatar o texto (quebras de linha)
        objetivos = (objetivos || '').replace(/\n/g, '<br>');
        conteudo = (conteudo || '').replace(/\n/g, '<br>');
        metodologia = (metodologia || '').replace(/\n/g, '<br>');
        recursos = (recursos || '').replace(/\n/g, '<br>');
        avaliacao = (avaliacao || '').replace(/\n/g, '<br>');
        bibliografia = (bibliografia || '').replace(/\n/g, '<br>');
        
        modalBody.innerHTML = `
            <div class="mb-4">
                <h5 class="text-primary">📖 ${titulo}</h5>
                <hr>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="info-box p-3 bg-light rounded">
                        <strong><i class="fas fa-calendar-alt"></i> Bimestre:</strong> ${bimestre}º Bimestre
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-box p-3 bg-light rounded">
                        <strong><i class="fas fa-clock"></i> Carga Horária:</strong> ${carga_horaria} horas
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-bullseye"></i> Objetivos:</strong>
                <div class="p-3 bg-light rounded mt-2">${objetivos || '<em class="text-muted">Não informado</em>'}</div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-list"></i> Conteúdo Programático:</strong>
                <div class="p-3 bg-light rounded mt-2">${conteudo || '<em class="text-muted">Não informado</em>'}</div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-chalkboard-teacher"></i> Metodologia:</strong>
                <div class="p-3 bg-light rounded mt-2">${metodologia || '<em class="text-muted">Não informado</em>'}</div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-tools"></i> Recursos Didáticos:</strong>
                <div class="p-3 bg-light rounded mt-2">${recursos || '<em class="text-muted">Não informado</em>'}</div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-star"></i> Avaliação:</strong>
                <div class="p-3 bg-light rounded mt-2">${avaliacao || '<em class="text-muted">Não informado</em>'}</div>
            </div>
            
            <div class="mb-3">
                <strong><i class="fas fa-book"></i> Bibliografia:</strong>
                <div class="p-3 bg-light rounded mt-2">${bibliografia || '<em class="text-muted">Não informado</em>'}</div>
            </div>
        `;
        
        document.getElementById('modalVerPlano').style.display = 'block';
    }
    
    function excluirPlano(id) {
        if (confirm('Tem certeza que deseja excluir este plano de ensino? Esta ação não pode ser desfeita.')) {
            window.location.href = `?action=excluir&id=${id}&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>`;
        }
    }
    
    function fecharModal() {
        document.getElementById('modalPlano').style.display = 'none';
    }
    
    function fecharModalVer() {
        document.getElementById('modalVerPlano').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalPlano');
        const modalVer = document.getElementById('modalVerPlano');
        if (event.target == modal) {
            fecharModal();
        }
        if (event.target == modalVer) {
            fecharModalVer();
        }
    }
    
    // Auto-submit ao selecionar filtros
    document.querySelector('select[name="turma_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
    document.querySelector('select[name="disciplina_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
    document.querySelector('select[name="bimestre"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>