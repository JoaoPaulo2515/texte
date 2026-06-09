<?php
// escola/pedagogico/projetos.php - Gestão de Projetos

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
$professor_id = $funcionario['id'] ?? 0;

// ============================================
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `projetos` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `titulo` VARCHAR(255) NOT NULL,
    `descricao` TEXT,
    `objetivos` TEXT,
    `publico_alvo` VARCHAR(255),
    `data_inicio` DATE NOT NULL,
    `data_fim` DATE NOT NULL,
    `coordenador_id` INT,
    `equipe` TEXT,
    `recursos` TEXT,
    `orçamento` DECIMAL(12,2) DEFAULT 0,
    `status` ENUM('planejado', 'em_andamento', 'concluido', 'cancelado') DEFAULT 'planejado',
    `bimestre` INT,
    `ano_letivo_id` INT NOT NULL,
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

// Tabela de atividades do projeto
$sql_create_atividades = "
CREATE TABLE IF NOT EXISTS `projetos_atividades` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `projeto_id` INT NOT NULL,
    `titulo` VARCHAR(255) NOT NULL,
    `descricao` TEXT,
    `data_prevista` DATE,
    `data_realizada` DATE,
    `status` ENUM('pendente', 'em_andamento', 'concluida', 'cancelada') DEFAULT 'pendente',
    `responsavel_id` INT,
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`projeto_id`) REFERENCES `projetos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";
try {
    $conn->exec($sql_create_atividades);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

// Inserir novo projeto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $objetivos = trim($_POST['objetivos']);
    $publico_alvo = trim($_POST['publico_alvo']);
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $coordenador_id = (int)$_POST['coordenador_id'];
    $equipe = trim($_POST['equipe']);
    $recursos = trim($_POST['recursos']);
    $orcamento = (float)str_replace(',', '.', $_POST['orcamento']);
    $status = $_POST['status'];
    $bimestre = (int)$_POST['bimestre'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    
    $sql = "INSERT INTO projetos (escola_id, titulo, descricao, objetivos, publico_alvo, data_inicio, data_fim, coordenador_id, equipe, recursos, orcamento, status, bimestre, ano_letivo_id, created_at) 
            VALUES (:escola_id, :titulo, :descricao, :objetivos, :publico_alvo, :data_inicio, :data_fim, :coordenador_id, :equipe, :recursos, :orcamento, :status, :bimestre, :ano_letivo_id, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':objetivos' => $objetivos,
        ':publico_alvo' => $publico_alvo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':coordenador_id' => $coordenador_id,
        ':equipe' => $equipe,
        ':recursos' => $recursos,
        ':orcamento' => $orcamento,
        ':status' => $status,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    
    $mensagem = "Projeto cadastrado com sucesso!";
}

// Atualizar projeto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $projeto_id = (int)$_POST['projeto_id'];
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $objetivos = trim($_POST['objetivos']);
    $publico_alvo = trim($_POST['publico_alvo']);
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $coordenador_id = (int)$_POST['coordenador_id'];
    $equipe = trim($_POST['equipe']);
    $recursos = trim($_POST['recursos']);
    $orcamento = (float)str_replace(',', '.', $_POST['orcamento']);
    $status = $_POST['status'];
    $bimestre = (int)$_POST['bimestre'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    
    $sql = "UPDATE projetos SET 
            titulo = :titulo, descricao = :descricao, objetivos = :objetivos,
            publico_alvo = :publico_alvo, data_inicio = :data_inicio, data_fim = :data_fim,
            coordenador_id = :coordenador_id, equipe = :equipe, recursos = :recursos,
            orcamento = :orcamento, status = :status, bimestre = :bimestre, ano_letivo_id = :ano_letivo_id,
            updated_at = NOW()
            WHERE id = :projeto_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':objetivos' => $objetivos,
        ':publico_alvo' => $publico_alvo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':coordenador_id' => $coordenador_id,
        ':equipe' => $equipe,
        ':recursos' => $recursos,
        ':orcamento' => $orcamento,
        ':status' => $status,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id,
        ':projeto_id' => $projeto_id,
        ':escola_id' => $escola_id
    ]);
    
    $mensagem = "Projeto atualizado com sucesso!";
}

// Excluir projeto
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $projeto_id = (int)$_GET['id'];
    $sql = "DELETE FROM projetos WHERE id = :projeto_id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':projeto_id' => $projeto_id, ':escola_id' => $escola_id]);
    $mensagem = "Projeto excluído com sucesso!";
}

// Adicionar atividade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_atividade') {
    $projeto_id = (int)$_POST['projeto_id'];
    $titulo = trim($_POST['atividade_titulo']);
    $descricao = trim($_POST['atividade_descricao']);
    $data_prevista = $_POST['atividade_data_prevista'];
    $responsavel_id = (int)$_POST['atividade_responsavel_id'];
    
    $sql = "INSERT INTO projetos_atividades (projeto_id, titulo, descricao, data_prevista, responsavel_id, created_at) 
            VALUES (:projeto_id, :titulo, :descricao, :data_prevista, :responsavel_id, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':projeto_id' => $projeto_id,
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':data_prevista' => $data_prevista,
        ':responsavel_id' => $responsavel_id
    ]);
    
    $mensagem = "Atividade adicionada com sucesso!";
}

// Atualizar status da atividade
if (isset($_POST['action']) && $_POST['action'] === 'atualizar_atividade_status') {
    header('Content-Type: application/json');
    $atividade_id = (int)$_POST['atividade_id'];
    $status = $_POST['status'];
    $data_realizada = $_POST['data_realizada'] ?? date('Y-m-d');
    
    $sql = "UPDATE projetos_atividades SET status = :status, data_realizada = :data_realizada, updated_at = NOW() WHERE id = :atividade_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':status' => $status, ':data_realizada' => $data_realizada, ':atividade_id' => $atividade_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Excluir atividade
if (isset($_POST['action']) && $_POST['action'] === 'excluir_atividade') {
    header('Content-Type: application/json');
    $atividade_id = (int)$_POST['atividade_id'];
    
    $sql = "DELETE FROM projetos_atividades WHERE id = :atividade_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':atividade_id' => $atividade_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Buscar projeto via AJAX para edição
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projeto_id = (int)$_GET['id'];
    $sql = "SELECT * FROM projetos WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $projeto_id, ':escola_id' => $escola_id]);
    $projeto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($projeto) {
        // Buscar atividades do projeto
        $sql_atividades = "SELECT * FROM projetos_atividades WHERE projeto_id = :projeto_id ORDER BY data_prevista ASC";
        $stmt_atividades = $conn->prepare($sql_atividades);
        $stmt_atividades->execute([':projeto_id' => $projeto_id]);
        $atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'projeto' => $projeto, 'atividades' => $atividades]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Projeto não encontrado']);
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

// FUNCIONÁRIOS (para coordenador)
$sql_funcionarios = "
    SELECT f.id, f.nome, u.tipo as cargo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.escola_id = :escola_id AND f.status = 'ativo'
    ORDER BY f.nome ASC
";
$stmt_funcionarios = $conn->prepare($sql_funcionarios);
$stmt_funcionarios->execute([':escola_id' => $escola_id]);
$funcionarios_lista = $stmt_funcionarios->fetchAll(PDO::FETCH_ASSOC);

// PARÂMETROS DE FILTRO
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR PROJETOS
// ============================================
$sql_projetos = "
    SELECT p.*, 
           al.ano as ano_letivo_ano,
           f.nome as coordenador_nome
    FROM projetos p
    INNER JOIN ano_letivo al ON al.id = p.ano_letivo_id
    LEFT JOIN funcionarios f ON f.id = p.coordenador_id
    WHERE p.escola_id = :escola_id
";

if ($ano_letivo_id > 0) {
    $sql_projetos .= " AND p.ano_letivo_id = :ano_letivo_id";
}
if (!empty($status_filtro)) {
    $sql_projetos .= " AND p.status = :status";
}
if ($bimestre_filtro > 0) {
    $sql_projetos .= " AND p.bimestre = :bimestre";
}

$sql_projetos .= " ORDER BY p.data_inicio DESC, p.created_at DESC";

$stmt_projetos = $conn->prepare($sql_projetos);
$params = [':escola_id' => $escola_id];
if ($ano_letivo_id > 0) {
    $params[':ano_letivo_id'] = $ano_letivo_id;
}
if (!empty($status_filtro)) {
    $params[':status'] = $status_filtro;
}
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt_projetos->execute($params);
$projetos = $stmt_projetos->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_projetos = count($projetos);
$total_planejados = 0;
$total_andamento = 0;
$total_concluidos = 0;
$total_cancelados = 0;
$orcamento_total = 0;

foreach ($projetos as $p) {
    if ($p['status'] == 'planejado') $total_planejados++;
    elseif ($p['status'] == 'em_andamento') $total_andamento++;
    elseif ($p['status'] == 'concluido') $total_concluidos++;
    elseif ($p['status'] == 'cancelado') $total_cancelados++;
    $orcamento_total += $p['orcamento'];
}

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
    <title>Projetos - SIGE Angola</title>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 1px; }
        .stat-planejado .stat-number { color: #17a2b8; }
        .stat-andamento .stat-number { color: #f39c12; }
        .stat-concluido .stat-number { color: #27ae60; }
        .stat-cancelado .stat-number { color: #e74c3c; }
        .stat-orcamento .stat-number { color: #1e5799; }
        
        .filtros-row { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end; }
        .filtro-group { flex: 1; min-width: 160px; }
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
        
        .table-projetos { width: 100%; border-collapse: collapse; }
        .table-projetos th {
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
        .table-projetos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-projetos tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .status-planejado { background: #d1ecf1; color: #0c5460; }
        .status-em_andamento { background: #fff3cd; color: #856404; }
        .status-concluido { background: #d4edda; color: #155724; }
        .status-cancelado { background: #f8d7da; color: #721c24; }
        
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
        .btn-atividades { background: #fd7e14; color: white; }
        .btn-atividades:hover { background: #e67e22; transform: translateY(-2px); }
        
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        
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
        
        /* Atividades section */
        .atividades-section {
            margin-top: 20px;
            border-top: 1px solid #e9ecef;
            padding-top: 20px;
        }
        .atividade-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-left: 4px solid #1e5799;
        }
        .atividade-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        .atividade-pendente { background: #fef9e7; color: #f39c12; }
        .atividade-andamento { background: #d1ecf1; color: #0c5460; }
        .atividade-concluida { background: #d4edda; color: #27ae60; }
        .atividade-cancelada { background: #f8d7da; color: #e74c3c; }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-projetos { font-size: 11px; }
            .table-projetos th, .table-projetos td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 5% auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-project-diagram"></i> Projetos</h1>
            <p>Gestão de projetos e atividades da escola</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if (isset($mensagem)): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-planejado">
            <div class="stat-number"><?php echo $total_planejados; ?></div>
            <div class="stat-label">Planejados</div>
        </div>
        <div class="stat-card stat-andamento">
            <div class="stat-number"><?php echo $total_andamento; ?></div>
            <div class="stat-label">Em Andamento</div>
        </div>
        <div class="stat-card stat-concluido">
            <div class="stat-number"><?php echo $total_concluidos; ?></div>
            <div class="stat-label">Concluídos</div>
        </div>
        <div class="stat-card stat-cancelado">
            <div class="stat-number"><?php echo $total_cancelados; ?></div>
            <div class="stat-label">Cancelados</div>
        </div>
        <div class="stat-card stat-orcamento">
            <div class="stat-number"><?php echo number_format($orcamento_total, 2); ?> Kz</div>
            <div class="stat-label">Orçamento Total</div>
        </div>
    </div>
    
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
                        <label>Status</label>
                        <select name="status" class="filtro-select">
                            <option value="">Todos</option>
                            <option value="planejado" <?php echo ($status_filtro == 'planejado') ? 'selected' : ''; ?>>Planejado</option>
                            <option value="em_andamento" <?php echo ($status_filtro == 'em_andamento') ? 'selected' : ''; ?>>Em Andamento</option>
                            <option value="concluido" <?php echo ($status_filtro == 'concluido') ? 'selected' : ''; ?>>Concluído</option>
                            <option value="cancelado" <?php echo ($status_filtro == 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar"><i class="fas fa-search"></i> Filtrar</button>
                        <button type="button" class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Projeto</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Projetos -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Projetos
            <span class="badge bg-light text-dark ms-2"><?php echo count($projetos); ?> registros</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($projetos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-project-diagram fa-3x mb-3"></i>
                    <p>Nenhum projeto encontrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro projeto</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-projetos">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Período</th>
                                <th>Coordenador</th>
                                <th>Bimestre</th>
                                <th>Orçamento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projetos as $projeto): 
                                $bimestre_class = '';
                                if ($projeto['bimestre'] == 1) $bimestre_class = 'badge-bim1';
                                elseif ($projeto['bimestre'] == 2) $bimestre_class = 'badge-bim2';
                                elseif ($projeto['bimestre'] == 3) $bimestre_class = 'badge-bim3';
                                else $bimestre_class = 'badge-bim4';
                                
                                $status_class = '';
                                if ($projeto['status'] == 'planejado') $status_class = 'status-planejado';
                                elseif ($projeto['status'] == 'em_andamento') $status_class = 'status-em_andamento';
                                elseif ($projeto['status'] == 'concluido') $status_class = 'status-concluido';
                                else $status_class = 'status-cancelado';
                                
                                $status_text = '';
                                if ($projeto['status'] == 'planejado') $status_text = 'Planejado';
                                elseif ($projeto['status'] == 'em_andamento') $status_text = 'Em Andamento';
                                elseif ($projeto['status'] == 'concluido') $status_text = 'Concluído';
                                else $status_text = 'Cancelado';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($projeto['titulo']); ?></strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($projeto['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($projeto['data_fim'])); ?></td>
                                    <td><?php echo htmlspecialchars($projeto['coordenador_nome'] ?? 'N/A'); ?></td>
                                    <td><span class="badge-bimestre <?php echo $bimestre_class; ?>"><?php echo $projeto['bimestre']; ?>º Bim</span></td>
                                    <td><?php echo number_format($projeto['orcamento'], 2); ?> Kz</td>
                                    <td><span class="badge-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    <td>
                                        <button class="btn-acao btn-ver" onclick="verProjeto(<?php echo $projeto['id']; ?>)">
                                            <i class="fas fa-eye"></i> Ver
                                        </button>
                                        <button class="btn-acao btn-editar" onclick="editarProjeto(<?php echo $projeto['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="excluirProjeto(<?php echo $projeto['id']; ?>)">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                     </td>
                                 </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Projeto -->
<div id="modalProjeto" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Projeto</h3>
            <span class="close-modal" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formProjeto">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="projeto_id" id="projeto_id" value="0">
                
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
                            <label class="form-label">Data Início *</label>
                            <input type="date" name="data_inicio" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Data Fim *</label>
                            <input type="date" name="data_fim" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Título do Projeto *</label>
                    <input type="text" name="titulo" class="form-control" required placeholder="Ex: Projeto de Leitura">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="3" placeholder="Descrição do projeto"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Objetivos</label>
                    <textarea name="objetivos" class="form-control" rows="3" placeholder="Objetivos do projeto"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Público Alvo</label>
                    <input type="text" name="publico_alvo" class="form-control" placeholder="Ex: Alunos do 1º ciclo">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Coordenador</label>
                            <select name="coordenador_id" class="form-select">
                                <option value="0">Selecione</option>
                                <?php foreach ($funcionarios_lista as $f): ?>
                                    <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['nome']); ?> (<?php echo htmlspecialchars($f['cargo']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Orçamento (Kz)</label>
                            <input type="text" name="orcamento" class="form-control" placeholder="0,00">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Equipe</label>
                    <textarea name="equipe" class="form-control" rows="2" placeholder="Membros da equipe"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Recursos Necessários</label>
                    <textarea name="recursos" class="form-control" rows="2" placeholder="Recursos materiais e financeiros"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="planejado">Planejado</option>
                        <option value="em_andamento">Em Andamento</option>
                        <option value="concluido">Concluído</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                
                <div class="text-end">
                    <button type="button" class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn-salvar"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Visualizar Projeto -->
<div id="modalVerProjeto" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h3><i class="fas fa-eye"></i> Visualizar Projeto</h3>
            <span class="close-modal" onclick="fecharModalVer()">&times;</span>
        </div>
        <div class="modal-custom-body" id="verProjetoBody">
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
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Projeto';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('projeto_id').value = '0';
        document.getElementById('formProjeto').reset();
        document.getElementById('modalProjeto').style.display = 'block';
    }
    
    function editarProjeto(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Projeto';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('projeto_id').value = id;
        
        fetch(`projetos.php?ajax=1&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const p = data.projeto;
                    document.querySelector('select[name="ano_letivo_id"]').value = p.ano_letivo_id;
                    document.querySelector('select[name="bimestre"]').value = p.bimestre;
                    document.querySelector('input[name="data_inicio"]').value = p.data_inicio;
                    document.querySelector('input[name="data_fim"]').value = p.data_fim;
                    document.querySelector('input[name="titulo"]').value = p.titulo;
                    document.querySelector('textarea[name="descricao"]').value = p.descricao;
                    document.querySelector('textarea[name="objetivos"]').value = p.objetivos;
                    document.querySelector('input[name="publico_alvo"]').value = p.publico_alvo;
                    document.querySelector('select[name="coordenador_id"]').value = p.coordenador_id;
                    document.querySelector('input[name="orcamento"]').value = p.orcamento;
                    document.querySelector('textarea[name="equipe"]').value = p.equipe;
                    document.querySelector('textarea[name="recursos"]').value = p.recursos;
                    document.querySelector('select[name="status"]').value = p.status;
                    document.getElementById('modalProjeto').style.display = 'block';
                } else {
                    showToast('Erro ao carregar dados do projeto', true);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showToast('Erro de conexão', true);
            });
    }
    
    function verProjeto(id) {
        const modalBody = document.getElementById('verProjetoBody');
        modalBody.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Carregando...</p></div>';
        
        fetch(`projetos.php?ajax=1&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const p = data.projeto;
                    const atividades = data.atividades || [];
                    
                    const statusClass = p.status == 'planejado' ? 'status-planejado' : (p.status == 'em_andamento' ? 'status-em_andamento' : (p.status == 'concluido' ? 'status-concluido' : 'status-cancelado'));
                    const statusText = p.status == 'planejado' ? 'Planejado' : (p.status == 'em_andamento' ? 'Em Andamento' : (p.status == 'concluido' ? 'Concluído' : 'Cancelado'));
                    const bimestreClass = p.bimestre == 1 ? 'badge-bim1' : (p.bimestre == 2 ? 'badge-bim2' : (p.bimestre == 3 ? 'badge-bim3' : 'badge-bim4'));
                    
                    let atividadesHtml = '';
                    if (atividades.length > 0) {
                        atividades.forEach(atv => {
                            const atvStatusClass = atv.status == 'pendente' ? 'atividade-pendente' : (atv.status == 'em_andamento' ? 'atividade-andamento' : (atv.status == 'concluida' ? 'atividade-concluida' : 'atividade-cancelada'));
                            const atvStatusText = atv.status == 'pendente' ? 'Pendente' : (atv.status == 'em_andamento' ? 'Em Andamento' : (atv.status == 'concluida' ? 'Concluída' : 'Cancelada'));
                            atividadesHtml += `
                                <div class="atividade-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>${atv.titulo}</strong>
                                            <div class="small text-muted">${atv.descricao || ''}</div>
                                            <div class="small">📅 Prevista: ${new Date(atv.data_prevista).toLocaleDateString('pt-BR')}</div>
                                        </div>
                                        <div>
                                            <span class="atividade-status ${atvStatusClass}">${atvStatusText}</span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        atividadesHtml = '<p class="text-muted text-center">Nenhuma atividade cadastrada.</p>';
                    }
                    
                    modalBody.innerHTML = `
                        <div class="mb-4">
                            <h5 class="text-primary">📋 ${p.titulo}</h5>
                            <hr>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="info-box p-3 bg-light rounded">
                                    <strong><i class="fas fa-calendar-alt"></i> Período:</strong><br>
                                    ${new Date(p.data_inicio).toLocaleDateString('pt-BR')} - ${new Date(p.data_fim).toLocaleDateString('pt-BR')}
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box p-3 bg-light rounded">
                                    <strong><i class="fas fa-layer-group"></i> Bimestre:</strong><br>
                                    <span class="badge-bimestre ${bimestreClass}">${p.bimestre}º Bimestre</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box p-3 bg-light rounded">
                                    <strong><i class="fas fa-flag-checkered"></i> Status:</strong><br>
                                    <span class="badge-status ${statusClass}">${statusText}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="info-box p-3 bg-light rounded">
                                    <strong><i class="fas fa-user"></i> Coordenador:</strong><br>
                                    ${p.coordenador_nome || 'Não definido'}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-box p-3 bg-light rounded">
                                    <strong><i class="fas fa-money-bill"></i> Orçamento:</strong><br>
                                    ${parseFloat(p.orcamento).toLocaleString('pt-BR', {style: 'currency', currency: 'AOA'})}
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-align-left"></i> Descrição:</strong>
                            <div class="p-3 bg-light rounded mt-2">${p.descricao || '<em class="text-muted">Não informado</em>'}</div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-bullseye"></i> Objetivos:</strong>
                            <div class="p-3 bg-light rounded mt-2">${p.objetivos || '<em class="text-muted">Não informado</em>'}</div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-users"></i> Público Alvo:</strong>
                            <div class="p-3 bg-light rounded mt-2">${p.publico_alvo || '<em class="text-muted">Não informado</em>'}</div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-users"></i> Equipe:</strong>
                            <div class="p-3 bg-light rounded mt-2">${p.equipe || '<em class="text-muted">Não informado</em>'}</div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-tools"></i> Recursos:</strong>
                            <div class="p-3 bg-light rounded mt-2">${p.recursos || '<em class="text-muted">Não informado</em>'}</div>
                        </div>
                        
                        <div class="atividades-section">
                            <h6><i class="fas fa-tasks"></i> Atividades do Projeto</h6>
                            ${atividadesHtml}
                        </div>
                    `;
                    document.getElementById('modalVerProjeto').style.display = 'block';
                } else {
                    modalBody.innerHTML = `<div class="alert alert-danger">Erro ao carregar projeto</div>`;
                }
            })
            .catch(error => {
                modalBody.innerHTML = `<div class="alert alert-danger">Erro de conexão</div>`;
            });
    }
    
    function excluirProjeto(id) {
        if (confirm('Tem certeza que deseja excluir este projeto? Todas as atividades associadas também serão excluídas.')) {
            window.location.href = `?action=excluir&id=${id}&ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>&status=<?php echo $status_filtro; ?>`;
        }
    }
    
    function fecharModal() {
        document.getElementById('modalProjeto').style.display = 'none';
    }
    
    function fecharModalVer() {
        document.getElementById('modalVerProjeto').style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalProjeto');
        const modalVer = document.getElementById('modalVerProjeto');
        if (event.target == modal) {
            fecharModal();
        }
        if (event.target == modalVer) {
            fecharModalVer();
        }
    }
    
    // Auto-submit ao selecionar filtros
    document.querySelector('select[name="ano_letivo_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
    document.querySelector('select[name="bimestre"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
    document.querySelector('select[name="status"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>