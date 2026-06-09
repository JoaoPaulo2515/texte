<?php
// escola/pedagogico/ciclos_ensino.php - Gestão de Ciclos de Ensino (com Ordem Automática)

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// ============================================
// PROCESSAR AJAX PARA BUSCAR CICLO
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM ciclos_ensino WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $ciclo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ciclo) {
            echo json_encode(['success' => true, 'ciclo' => $ciclo]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ciclo de ensino não encontrado']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro no banco de dados: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `ciclos_ensino` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(100) NOT NULL,
    `sigla` VARCHAR(20) NOT NULL,
    `descricao` TEXT,
    `ordem` INT DEFAULT 0,
    `ano_inicio` INT DEFAULT NULL,
    `ano_fim` INT DEFAULT NULL,
    `duracao_anos` INT DEFAULT NULL,
    `nivel_id` INT NULL,
    `escola_id` INT NOT NULL,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_sigla_escola` (`escola_id`, `sigla`),
    UNIQUE KEY `unique_nome_escola` (`escola_id`, `nome`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_ordem` (`ordem`),
    KEY `idx_status` (`status`),
    KEY `idx_nivel_id` (`nivel_id`),
    FOREIGN KEY (`nivel_id`) REFERENCES `niveis`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $conn->exec($sql_create_table);
} catch (PDOException $e) {
    // Tabela já existe ou erro
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarProximaOrdem($conn, $escola_id) {
    $sql = "SELECT MAX(ordem) as max_ordem FROM ciclos_ensino WHERE escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $proxima_ordem = ($result['max_ordem'] ?? 0) + 1;
    return $proxima_ordem;
}

function gerarSiglaAutomatica($nome) {
    $mapa_siglas = [
        'educacao infantil' => 'EI',
        'educação infantil' => 'EI',
        'ensino fundamental i' => 'EF I',
        'ensino fundamental ii' => 'EF II',
        'ensino fundamental 1' => 'EF I',
        'ensino fundamental 2' => 'EF II',
        'ensino médio' => 'EM',
        'ensino medio' => 'EM',
        'educacao de jovens e adultos' => 'EJA',
        'educação de jovens e adultos' => 'EJA',
        'ensino tecnico' => 'ET',
        'ensino técnico' => 'ET',
        'tecnico' => 'TEC',
        'técnico' => 'TEC',
        'superior' => 'SUP',
        'pos-graduacao' => 'POS',
        'pós-graduação' => 'POS',
        'mestrado' => 'MEST',
        'doutorado' => 'DOUT',
        '1º ciclo' => '1CIC',
        'primeiro ciclo' => '1CIC',
        '2º ciclo' => '2CIC',
        'segundo ciclo' => '2CIC',
        '3º ciclo' => '3CIC',
        'terceiro ciclo' => '3CIC',
        'ciclo basico' => 'CB',
        'ciclo básico' => 'CB',
        'ciclo medio' => 'CM',
        'ciclo médio' => 'CM'
    ];
    
    $nome_lower = strtolower(trim($nome));
    
    if (isset($mapa_siglas[$nome_lower])) {
        return $mapa_siglas[$nome_lower];
    }
    
    $palavras = explode(' ', $nome_lower);
    $sigla = '';
    foreach ($palavras as $palavra) {
        if (!empty($palavra)) {
            if (is_numeric($palavra)) {
                $sigla .= $palavra;
            } else {
                $sigla .= strtoupper(substr($palavra, 0, 1));
            }
        }
    }
    
    if (strlen($sigla) > 10) {
        $sigla = substr($sigla, 0, 10);
    }
    
    return $sigla;
}

function gerarSiglaUnica($conn, $escola_id, $sigla_base, $nome) {
    $sigla = $sigla_base;
    $contador = 1;
    
    $sql_check = "SELECT id FROM ciclos_ensino WHERE escola_id = :escola_id AND sigla = :sigla";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':escola_id' => $escola_id, ':sigla' => $sigla]);
    
    while ($stmt_check->fetch()) {
        $sigla = $sigla_base . $contador;
        $contador++;
        $stmt_check->execute([':escola_id' => $escola_id, ':sigla' => $sigla]);
    }
    
    return $sigla;
}

// ============================================
// PROCESSAR AÇÕES (CRUD)
// ============================================

$mensagem = '';
$erro = '';

// Inserir novo ciclo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $descricao = trim($_POST['descricao']);
    $ano_inicio = !empty($_POST['ano_inicio']) ? (int)$_POST['ano_inicio'] : null;
    $ano_fim = !empty($_POST['ano_fim']) ? (int)$_POST['ano_fim'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $status = $_POST['status'];
    
    if (empty($nome)) {
        $erro = "O nome do ciclo é obrigatório.";
    } else {
        // Verificar se já existe ciclo com este nome
        $sql_check = "SELECT id FROM ciclos_ensino WHERE escola_id = :escola_id AND nome = :nome";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':escola_id' => $escola_id, ':nome' => $nome]);
        
        if ($stmt_check->fetch()) {
            $erro = "Já existe um ciclo cadastrado com este nome.";
        } else {
            // Gerar ordem automaticamente
            $ordem = gerarProximaOrdem($conn, $escola_id);
            
            // Gerar sigla automaticamente
            $sigla_base = gerarSiglaAutomatica($nome);
            $sigla = gerarSiglaUnica($conn, $escola_id, $sigla_base, $nome);
            
            // Calcular duração se não foi informada
            if ($ano_inicio && $ano_fim && !$duracao_anos) {
                $duracao_anos = $ano_fim - $ano_inicio + 1;
            }
            
            $sql = "INSERT INTO ciclos_ensino (escola_id, nome, sigla, descricao, ordem, ano_inicio, ano_fim, duracao_anos, nivel_id, status) 
                    VALUES (:escola_id, :nome, :sigla, :descricao, :ordem, :ano_inicio, :ano_fim, :duracao_anos, :nivel_id, :status)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':sigla' => $sigla,
                ':descricao' => $descricao,
                ':ordem' => $ordem,
                ':ano_inicio' => $ano_inicio,
                ':ano_fim' => $ano_fim,
                ':duracao_anos' => $duracao_anos,
                ':nivel_id' => $nivel_id,
                ':status' => $status
            ]);
            
            $mensagem = "Ciclo de ensino cadastrado com sucesso! Ordem: $ordem | Sigla: $sigla";
        }
    }
}

// Atualizar ciclo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $descricao = trim($_POST['descricao']);
    $ano_inicio = !empty($_POST['ano_inicio']) ? (int)$_POST['ano_inicio'] : null;
    $ano_fim = !empty($_POST['ano_fim']) ? (int)$_POST['ano_fim'] : null;
    $duracao_anos = !empty($_POST['duracao_anos']) ? (int)$_POST['duracao_anos'] : null;
    $nivel_id = !empty($_POST['nivel_id']) ? (int)$_POST['nivel_id'] : null;
    $status = $_POST['status'];
    $ordem = isset($_POST['ordem']) ? (int)$_POST['ordem'] : 0;
    
    if (empty($nome)) {
        $erro = "O nome do ciclo é obrigatório.";
    } else {
        // Verificar se já existe outro ciclo com este nome
        $sql_check = "SELECT id FROM ciclos_ensino WHERE escola_id = :escola_id AND nome = :nome AND id != :id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':escola_id' => $escola_id, ':nome' => $nome, ':id' => $id]);
        
        if ($stmt_check->fetch()) {
            $erro = "Já existe outro ciclo cadastrado com este nome.";
        } else {
            // Calcular duração se não foi informada
            if ($ano_inicio && $ano_fim && !$duracao_anos) {
                $duracao_anos = $ano_fim - $ano_inicio + 1;
            }
            
            $sql = "UPDATE ciclos_ensino SET 
                    nome = :nome,
                    sigla = :sigla,
                    descricao = :descricao,
                    ordem = :ordem,
                    ano_inicio = :ano_inicio,
                    ano_fim = :ano_fim,
                    duracao_anos = :duracao_anos,
                    nivel_id = :nivel_id,
                    status = :status
                    WHERE id = :id AND escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':sigla' => $sigla,
                ':descricao' => $descricao,
                ':ordem' => $ordem,
                ':ano_inicio' => $ano_inicio,
                ':ano_fim' => $ano_fim,
                ':duracao_anos' => $duracao_anos,
                ':nivel_id' => $nivel_id,
                ':status' => $status,
                ':id' => $id,
                ':escola_id' => $escola_id
            ]);
            
            $mensagem = "Ciclo de ensino atualizado com sucesso!";
        }
    }
}

// Reordenar ciclos (AJAX)
if (isset($_POST['action']) && $_POST['action'] === 'reordenar') {
    ob_clean();
    header('Content-Type: application/json');
    
    $ordenacao = json_decode($_POST['ordenacao'], true);
    
    if ($ordenacao) {
        try {
            foreach ($ordenacao as $item) {
                $sql = "UPDATE ciclos_ensino SET ordem = :ordem WHERE id = :id AND escola_id = :escola_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':ordem' => $item['ordem'],
                    ':id' => $item['id'],
                    ':escola_id' => $escola_id
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Ordenação atualizada com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao reordenar: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Nenhum dado recebido']);
    }
    exit;
}

// Excluir ciclo
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $sql_check = "SELECT COUNT(*) as total FROM turmas WHERE ciclo_id = :ciclo_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':ciclo_id' => $id]);
    $total_turmas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_turmas > 0) {
        $erro = "Não é possível excluir este ciclo pois existem $total_turmas turmas associadas.";
    } else {
        $sql = "DELETE FROM ciclos_ensino WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Ciclo de ensino excluído com sucesso!";
    }
}

// ============================================
// BUSCAR NÍVEIS DE ENSINO
// ============================================
$sql_niveis = "SELECT id, nome, sigla, ordem FROM niveis WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY ordem ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR CICLOS DE ENSINO
// ============================================
$sql_ciclos = "SELECT c.*, n.nome as nivel_nome, n.sigla as nivel_sigla 
               FROM ciclos_ensino c
               LEFT JOIN niveis n ON n.id = c.nivel_id
               WHERE c.escola_id = :escola_id 
               ORDER BY c.ordem ASC, c.ano_inicio ASC";
$stmt_ciclos = $conn->prepare($sql_ciclos);
$stmt_ciclos->execute([':escola_id' => $escola_id]);
$ciclos = $stmt_ciclos->fetchAll(PDO::FETCH_ASSOC);

$total_ciclos = count($ciclos);
$ciclos_ativos = 0;
foreach ($ciclos as $c) {
    if ($c['status'] == 'ativo') $ciclos_ativos++;
}

// Gerar próxima ordem para novo cadastro
$proxima_ordem = gerarProximaOrdem($conn, $escola_id);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ciclos de Ensino - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .stat-total .stat-number { color: #1e5799; }
        .stat-ativos .stat-number { color: #27ae60; }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        .btn-novo:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .btn-reordenar {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        .btn-reordenar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(23,162,184,0.3); }
        
        .table-ciclos { width: 100%; border-collapse: collapse; }
        .table-ciclos th {
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
        .table-ciclos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-ciclos tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
        .ordem-badge {
            display: inline-block;
            width: 35px;
            height: 35px;
            background: #1e5799;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 35px;
            font-weight: bold;
            cursor: move;
            transition: all 0.3s ease;
        }
        .ordem-badge:hover { transform: scale(1.1); }
        
        .badge-nivel {
            display: inline-block;
            padding: 4px 10px;
            background: #e8f4f8;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            color: #1e5799;
        }
        
        .ordem-preview {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, #e8f4f8, #d4edda);
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            color: #1e5799;
            margin-top: 5px;
        }
        
        .sigla-preview {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, #e8f4f8, #d4edda);
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: #1e5799;
            margin-top: 5px;
        }
        
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
        
        /* Modais */
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 700px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-custom-header {
            padding: 20px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-custom-header.modal-danger { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .modal-custom-header.modal-success { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; }
        .modal-custom-header.modal-info { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        
        .modal-custom-header h3 { font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .close-modal:hover { background: rgba(255,255,255,0.2); }
        
        .modal-custom-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-custom-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
            background: white;
            border-radius: 0 0 20px 20px;
        }
        
        .modal-custom-body p { font-size: 15px; line-height: 1.5; color: #333; margin-bottom: 0; }
        .modal-custom-body .modal-details { 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 12px; 
            font-size: 13px; 
            color: #666;
            border-left: 3px solid #dc3545;
            margin-top: 15px;
        }
        
        .btn-modal-cancelar {
            background: #6c757d;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-cancelar:hover { background: #5a6268; transform: translateY(-1px); }
        
        .btn-modal-confirmar {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-confirmar:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(220,53,69,0.3); }
        
        .btn-modal-ok {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            padding: 8px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-ok:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(40,167,69,0.3); }
        
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
        .btn-cancelar-form {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        .btn-cancelar-form:hover { transform: translateY(-2px); background: #5a6268; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .info-box {
            background: #e8f4f8;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .table-ciclos { font-size: 11px; }
            .table-ciclos th, .table-ciclos td { padding: 8px; }
            .modal-custom-content { margin: 10% auto; width: 95%; max-height: 85vh; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-chart-line"></i> Ciclos de Ensino</h1>
            <p>Gestão dos ciclos de ensino da escola (Ex: 1º Ciclo, 2º Ciclo, etc.)</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_ciclos; ?></div>
            <div class="stat-label">Total de Ciclos</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $ciclos_ativos; ?></div>
            <div class="stat-label">Ciclos Ativos</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($niveis); ?></div>
            <div class="stat-label">Níveis Disponíveis</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Ciclos de Ensino Cadastrados
            <span class="badge bg-light text-dark ms-2"><?php echo $total_ciclos; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Ciclo</button>
            <?php if ($total_ciclos > 1): ?>
            <button class="btn-reordenar float-end me-2" id="btnReordenar" onclick="ativarReordenacao()"><i class="fas fa-arrows-alt"></i> Reordenar</button>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($ciclos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                    <p>Nenhum ciclo de ensino cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro ciclo</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-ciclos" id="tabelaCiclos">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Ordem</th>
                                <th>Sigla</th>
                                <th>Nome</th>
                                <th>Nível</th>
                                <th>Anos</th>
                                <th>Duração</th>
                                <th>Status</th>
                                <th style="width: 120px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tabelaCiclosBody">
                            <?php foreach ($ciclos as $ciclo): ?>
                                <tr data-id="<?php echo $ciclo['id']; ?>" data-ordem="<?php echo $ciclo['ordem']; ?>">
                                    <td class="text-center">
                                        <span class="ordem-badge handle" style="cursor: move;"><?php echo $ciclo['ordem']; ?></span>
                                    </td>
                                    <td class="text-center"><strong><?php echo htmlspecialchars($ciclo['sigla']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($ciclo['nome']); ?></strong>
                                        <?php if ($ciclo['descricao']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($ciclo['descricao'], 0, 50)) . (strlen($ciclo['descricao']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($ciclo['nivel_id'] && $ciclo['nivel_nome']): ?>
                                            <span class="badge-nivel"><?php echo htmlspecialchars($ciclo['nivel_nome']); ?> (<?php echo htmlspecialchars($ciclo['nivel_sigla']); ?>)</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($ciclo['ano_inicio'] && $ciclo['ano_fim']): ?>
                                            <?php echo $ciclo['ano_inicio']; ?>º - <?php echo $ciclo['ano_fim']; ?>º ano
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($ciclo['duracao_anos']): ?>
                                            <?php echo $ciclo['duracao_anos']; ?> ano(s)
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($ciclo['status'] == 'ativo'): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarCiclo(<?php echo $ciclo['id']; ?>)"><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn-acao btn-excluir" onclick="confirmarExclusao(<?php echo $ciclo['id']; ?>, '<?php echo htmlspecialchars(addslashes($ciclo['nome'])); ?>')"><i class="fas fa-trash"></i> Excluir</button>
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

<!-- Modal de Confirmação de Exclusão -->
<div id="modalConfirmacao" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h3>
            <span class="close-modal" onclick="fecharModalConfirmacao()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemConfirmacao" class="modal-message"></p>
            <div id="detalhesConfirmacao" class="modal-details"></div>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-cancelar" onclick="fecharModalConfirmacao()">Cancelar</button>
            <button class="btn-modal-confirmar" id="btnConfirmarExclusao">Confirmar Exclusão</button>
        </div>
    </div>
</div>

<!-- Modal de Informação -->
<div id="modalInfo" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-success">
            <h3><i class="fas fa-check-circle"></i> Sucesso!</h3>
            <span class="close-modal" onclick="fecharModalInfo()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemInfo"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalInfo()">OK</button>
        </div>
    </div>
</div>

<!-- Modal de Erro -->
<div id="modalErro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-times-circle"></i> Erro!</h3>
            <span class="close-modal" onclick="fecharModalErro()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemErro"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalErro()">OK</button>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Ciclo -->
<div id="modalCiclo" class="modal-custom">
    <div class="modal-custom-content modal-form-content">
        <div class="modal-custom-header modal-info">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Ciclo de Ensino</h3>
            <span class="close-modal" onclick="fecharModalCiclo()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formCiclo">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="ciclo_id" value="0">
                <input type="hidden" name="sigla" id="campo_sigla_hidden" value="">
                <input type="hidden" name="ordem" id="campo_ordem_hidden" value="">
                
                <div class="form-group">
                    <label class="form-label">Ordem * <span class="text-muted">(Gerada automaticamente)</span></label>
                    <div class="ordem-preview">
                        <i class="fas fa-sort-numeric-down"></i> Próxima ordem: <span id="ordemDisplay"><?php echo $proxima_ordem; ?></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome do Ciclo *</label>
                    <input type="text" name="nome" id="campo_nome" class="form-control" required placeholder="Ex: 1º Ciclo, 2º Ciclo, Ciclo Básico" onkeyup="gerarSiglaPreview()" onchange="gerarSiglaPreview()">
                    <div id="siglaPreview" class="sigla-preview" style="display: none;">
                        <i class="fas fa-magic"></i> Sigla sugerida: <span id="siglaSugerida"></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Nível de Ensino</label>
                            <select name="nivel_id" id="campo_nivel" class="form-select">
                                <option value="">Selecione um nível</option>
                                <?php foreach ($niveis as $nivel): ?>
                                    <option value="<?php echo $nivel['id']; ?>"><?php echo htmlspecialchars($nivel['nome']); ?> (<?php echo htmlspecialchars($nivel['sigla']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Ano Início</label>
                            <input type="number" name="ano_inicio" id="campo_ano_inicio" class="form-control" placeholder="Ex: 1" min="1" max="12" onchange="calcularDuracao()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Ano Fim</label>
                            <input type="number" name="ano_fim" id="campo_ano_fim" class="form-control" placeholder="Ex: 5" min="1" max="12" onchange="calcularDuracao()">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Duração (anos)</label>
                            <input type="number" name="duracao_anos" id="campo_duracao" class="form-control" readonly placeholder="Calculado automaticamente" style="background:#f8f9fa;">
                            <small class="text-muted">Calculado automaticamente com base nos anos</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" id="campo_status" class="form-select">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" id="campo_descricao" class="form-control" rows="3" placeholder="Descreva as características deste ciclo"></textarea>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informações importantes:</strong>
                    <ul class="mb-0 mt-2">
                        <li>✨ A <strong>ordem</strong> é gerada automaticamente (sequencial)</li>
                        <li>✨ A <strong>sigla</strong> é gerada automaticamente baseada no nome do ciclo</li>
                        <li>📚 O nível de ensino é opcional e pode ser definido posteriormente</li>
                        <li>⏱️ A duração é calculada automaticamente com base nos anos de início e fim</li>
                        <li>🔀 Você pode reordenar os ciclos arrastando as linhas da tabela</li>
                    </ul>
                </div>
            </form>
        </div>
        <div class="modal-custom-footer">
            <button type="button" class="btn-cancelar-form" onclick="fecharModalCiclo()">Cancelar</button>
            <button type="submit" class="btn-salvar" onclick="document.getElementById('formCiclo').submit();"><i class="fas fa-save"></i> Salvar</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
    var idParaExcluir = null;
    var sortableInstance = null;
    var modoReordenacao = false;
    
    // ============================================
    // MODAIS
    // ============================================
    function confirmarExclusao(id, nome) {
        idParaExcluir = id;
        document.getElementById('mensagemConfirmacao').innerHTML = 'Tem certeza que deseja excluir o ciclo <strong>"' + nome + '"</strong>?';
        document.getElementById('detalhesConfirmacao').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita.';
        document.getElementById('modalConfirmacao').style.display = 'block';
    }
    
    function fecharModalConfirmacao() {
        document.getElementById('modalConfirmacao').style.display = 'none';
        idParaExcluir = null;
    }
    
    document.getElementById('btnConfirmarExclusao').onclick = function() {
        if (idParaExcluir) {
            window.location.href = '?action=excluir&id=' + idParaExcluir;
        }
        fecharModalConfirmacao();
    };
    
    function showModalInfo(mensagem) {
        document.getElementById('mensagemInfo').innerHTML = mensagem;
        document.getElementById('modalInfo').style.display = 'block';
    }
    
    function fecharModalInfo() {
        document.getElementById('modalInfo').style.display = 'none';
    }
    
    function showModalErro(mensagem) {
        document.getElementById('mensagemErro').innerHTML = mensagem;
        document.getElementById('modalErro').style.display = 'block';
    }
    
    function fecharModalErro() {
        document.getElementById('modalErro').style.display = 'none';
    }
    
    function fecharModalCiclo() {
        document.getElementById('modalCiclo').style.display = 'none';
    }
    
    // ============================================
    // FUNÇÕES DE GERENCIAMENTO
    // ============================================
    
    function calcularDuracao() {
        var anoInicio = parseInt(document.getElementById('campo_ano_inicio').value) || 0;
        var anoFim = parseInt(document.getElementById('campo_ano_fim').value) || 0;
        
        if (anoInicio > 0 && anoFim > 0 && anoFim >= anoInicio) {
            var duracao = anoFim - anoInicio + 1;
            document.getElementById('campo_duracao').value = duracao;
        } else {
            document.getElementById('campo_duracao').value = '';
        }
    }
    
    function gerarSiglaPreview() {
        var nome = document.getElementById('campo_nome').value;
        var siglaPreview = document.getElementById('siglaPreview');
        var siglaSugeridaSpan = document.getElementById('siglaSugerida');
        var siglaHidden = document.getElementById('campo_sigla_hidden');
        
        if (nome && nome.trim() !== '') {
            var sigla = gerarSigla(nome);
            siglaSugeridaSpan.innerHTML = sigla;
            siglaPreview.style.display = 'block';
            siglaHidden.value = sigla;
        } else {
            siglaPreview.style.display = 'none';
            siglaHidden.value = '';
        }
    }
    
    function gerarSigla(nome) {
        var nomeLower = nome.toLowerCase().trim();
        
        var mapaSiglas = {
            '1º ciclo': '1CIC',
            'primeiro ciclo': '1CIC',
            '2º ciclo': '2CIC',
            'segundo ciclo': '2CIC',
            '3º ciclo': '3CIC',
            'terceiro ciclo': '3CIC',
            'ciclo basico': 'CB',
            'ciclo básico': 'CB',
            'ciclo medio': 'CM',
            'ciclo médio': 'CM'
        };
        
        if (mapaSiglas[nomeLower]) {
            return mapaSiglas[nomeLower];
        }
        
        var palavras = nome.split(' ');
        var sigla = '';
        for (var i = 0; i < palavras.length; i++) {
            if (palavras[i].length > 0 && !isNaN(parseInt(palavras[i]))) {
                sigla += palavras[i];
            } else if (palavras[i].length > 0) {
                sigla += palavras[i].charAt(0).toUpperCase();
            }
        }
        
        if (sigla.length > 6) {
            sigla = sigla.substring(0, 6);
        }
        
        return sigla;
    }
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Ciclo de Ensino';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('ciclo_id').value = '0';
        document.getElementById('formCiclo').reset();
        document.getElementById('campo_status').value = 'ativo';
        document.getElementById('siglaPreview').style.display = 'none';
        document.getElementById('campo_sigla_hidden').value = '';
        document.getElementById('campo_duracao').value = '';
        document.getElementById('ordemDisplay').innerHTML = '<?php echo $proxima_ordem; ?>';
        document.getElementById('campo_ordem_hidden').value = '<?php echo $proxima_ordem; ?>';
        document.getElementById('modalCiclo').style.display = 'block';
    }
    
    function editarCiclo(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Ciclo de Ensino';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('ciclo_id').value = id;
        
        showModalInfo('Carregando dados do ciclo...');
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { ajax: 1, id: id },
            dataType: 'json',
            success: function(data) {
                fecharModalInfo();
                if (data.success) {
                    var c = data.ciclo;
                    document.getElementById('campo_nome').value = c.nome || '';
                    document.getElementById('campo_nivel').value = c.nivel_id || '';
                    document.getElementById('campo_ano_inicio').value = c.ano_inicio || '';
                    document.getElementById('campo_ano_fim').value = c.ano_fim || '';
                    document.getElementById('campo_duracao').value = c.duracao_anos || '';
                    document.getElementById('campo_descricao').value = c.descricao || '';
                    document.getElementById('campo_status').value = c.status || 'ativo';
                    document.getElementById('campo_sigla_hidden').value = c.sigla || '';
                    document.getElementById('siglaPreview').style.display = 'none';
                    
                    // Na edição, mostrar a ordem atual (mas não permite editar)
                    document.getElementById('ordemDisplay').innerHTML = c.ordem || '0';
                    document.getElementById('campo_ordem_hidden').value = c.ordem || '0';
                    
                    document.getElementById('modalCiclo').style.display = 'block';
                } else {
                    showModalErro(data.message || 'Ciclo não encontrado');
                }
            },
            error: function(xhr, status, error) {
                fecharModalInfo();
                showModalErro('Erro ao carregar dados: ' + error);
            }
        });
    }
    
    function ativarReordenacao() {
        const tbody = document.getElementById('tabelaCiclosBody');
        const btnReordenar = document.getElementById('btnReordenar');
        
        if (!modoReordenacao) {
            modoReordenacao = true;
            btnReordenar.innerHTML = '<i class="fas fa-save"></i> Salvar Ordem';
            btnReordenar.classList.remove('btn-reordenar');
            btnReordenar.classList.add('btn-success');
            showModalInfo('Modo de reordenação ativado! Arraste as linhas para reordenar.');
            setTimeout(fecharModalInfo, 2000);
            
            sortableInstance = new Sortable(tbody, {
                animation: 300,
                handle: '.handle',
                onEnd: function() {
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach((row, index) => {
                        const ordemBadge = row.querySelector('.ordem-badge');
                        if (ordemBadge) {
                            ordemBadge.textContent = index + 1;
                        }
                    });
                }
            });
        } else {
            salvarNovaOrdem();
        }
    }
    
    function salvarNovaOrdem() {
        const rows = document.querySelectorAll('#tabelaCiclosBody tr');
        const ordenacao = [];
        
        rows.forEach((row, index) => {
            const id = row.getAttribute('data-id');
            ordenacao.push({ id: id, ordem: index + 1 });
        });
        
        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            data: { action: 'reordenar', ordenacao: JSON.stringify(ordenacao) },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showModalInfo(data.message);
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showModalErro(data.message);
                }
            },
            error: function() {
                showModalErro('Erro ao salvar ordenação');
            }
        });
        
        const btnReordenar = document.getElementById('btnReordenar');
        modoReordenacao = false;
        btnReordenar.innerHTML = '<i class="fas fa-arrows-alt"></i> Reordenar';
        btnReordenar.classList.remove('btn-success');
        btnReordenar.classList.add('btn-reordenar');
        
        if (sortableInstance) {
            sortableInstance.destroy();
            sortableInstance = null;
        }
    }
    
    window.onclick = function(event) {
        var modalConfirmacao = document.getElementById('modalConfirmacao');
        var modalInfo = document.getElementById('modalInfo');
        var modalErro = document.getElementById('modalErro');
        var modalCiclo = document.getElementById('modalCiclo');
        
        if (event.target == modalConfirmacao) fecharModalConfirmacao();
        if (event.target == modalInfo) fecharModalInfo();
        if (event.target == modalErro) fecharModalErro();
        if (event.target == modalCiclo) fecharModalCiclo();
    }
    
    window.addEventListener('beforeunload', function(e) {
        if (modoReordenacao) {
            e.preventDefault();
            e.returnValue = 'Você tem alterações não salvas na ordenação. Deseja realmente sair?';
            return e.returnValue;
        }
    });
</script>
</body>
</html>