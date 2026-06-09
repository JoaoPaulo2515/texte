<?php
// escola/pedagogico/perfil.php - Perfil do Usuário Pedagógico com Gestão Financeira Completa

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
$funcionario_id = $funcionario['id'];

// ============================================
// VERIFICAR E CRIAR TABELAS FINANCEIRAS
// ============================================

// Tabela de salários
$sql_salarios = "
CREATE TABLE IF NOT EXISTS `funcionario_salarios` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `mes` INT NOT NULL,
    `ano` INT NOT NULL,
    `salario_base` DECIMAL(12,2) NOT NULL,
    `subsidio_alimentacao` DECIMAL(12,2) DEFAULT 0,
    `subsidio_transporte` DECIMAL(12,2) DEFAULT 0,
    `bonus` DECIMAL(12,2) DEFAULT 0,
    `descontos` DECIMAL(12,2) DEFAULT 0,
    `salario_liquido` DECIMAL(12,2) NOT NULL,
    `status` ENUM('pendente', 'pago', 'atrasado') DEFAULT 'pendente',
    `data_pagamento` DATE DEFAULT NULL,
    `comprovativo` VARCHAR(255) DEFAULT NULL,
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_funcionario_id` (`funcionario_id`),
    KEY `idx_mes_ano` (`mes`, `ano`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Tabela de vales solicitados
$sql_vales = "
CREATE TABLE IF NOT EXISTS `funcionario_vales` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `valor` DECIMAL(12,2) NOT NULL,
    `motivo` VARCHAR(255) NOT NULL,
    `data_solicitacao` DATE DEFAULT CURRENT_DATE,
    `data_prevista_devolucao` DATE DEFAULT NULL,
    `status` ENUM('pendente', 'aprovado', 'rejeitado', 'pago') DEFAULT 'pendente',
    `aprovado_por` INT DEFAULT NULL,
    `data_aprovacao` DATETIME DEFAULT NULL,
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_funcionario_id` (`funcionario_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Tabela de férias
$sql_ferias = "
CREATE TABLE IF NOT EXISTS `funcionario_ferias` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `ano_referente` INT NOT NULL,
    `dias_totais` INT DEFAULT 30,
    `dias_usados` INT DEFAULT 0,
    `dias_restantes` INT DEFAULT 30,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_funcionario_ano` (`funcionario_id`, `ano_referente`),
    KEY `idx_funcionario_id` (`funcionario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Tabela de solicitações de férias
$sql_solicitacoes_ferias = "
CREATE TABLE IF NOT EXISTS `funcionario_solicitacoes_ferias` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `data_inicio` DATE NOT NULL,
    `data_fim` DATE NOT NULL,
    `dias_solicitados` INT NOT NULL,
    `motivo` TEXT,
    `status` ENUM('pendente', 'aprovado', 'rejeitado', 'cancelado') DEFAULT 'pendente',
    `aprovado_por` INT DEFAULT NULL,
    `data_aprovacao` DATETIME DEFAULT NULL,
    `observacoes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_funcionario_id` (`funcionario_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Tabela de despesas
$sql_despesas = "
CREATE TABLE IF NOT EXISTS `funcionario_despesas` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `categoria` VARCHAR(50) NOT NULL,
    `descricao` VARCHAR(255) NOT NULL,
    `valor` DECIMAL(12,2) NOT NULL,
    `data_despesa` DATE DEFAULT CURRENT_DATE,
    `comprovativo` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pendente', 'reembolsado') DEFAULT 'pendente',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_funcionario_id` (`funcionario_id`),
    KEY `idx_categoria` (`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Tabela de rendimentos extras
$sql_rendimentos = "
CREATE TABLE IF NOT EXISTS `funcionario_rendimentos` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT NOT NULL,
    `tipo` VARCHAR(50) NOT NULL,
    `descricao` VARCHAR(255) NOT NULL,
    `valor` DECIMAL(12,2) NOT NULL,
    `data_rendimento` DATE DEFAULT CURRENT_DATE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_funcionario_id` (`funcionario_id`),
    KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Executar criação das tabelas
try {
    $conn->exec($sql_salarios);
    $conn->exec($sql_vales);
    $conn->exec($sql_ferias);
    $conn->exec($sql_solicitacoes_ferias);
    $conn->exec($sql_despesas);
    $conn->exec($sql_rendimentos);
} catch (PDOException $e) {
    // Tabelas já existem ou erro
}

// Buscar dados do funcionário
$sql_funcionario = "
    SELECT f.*, u.email, u.usuario, u.created_at as usuario_created_at,
           e.nome as escola_nome, e.logo as escola_logo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    INNER JOIN escolas e ON e.id = f.escola_id
    WHERE f.id = :funcionario_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':funcionario_id' => $funcionario_id]);
$dados = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR AÇÕES DO PERFIL
// ============================================

$mensagem = '';
$erro = '';

// Atualizar perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar_perfil') {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $telefone = trim($_POST['telefone']);
    $endereco = trim($_POST['endereco']);
    $data_nascimento = $_POST['data_nascimento'];
    $genero = $_POST['genero'];
    $bi = trim($_POST['bi']);
    $nif = trim($_POST['nif']);
    
    // Processar upload da foto
    $foto_path = $dados['foto'];
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif'];
        $arquivo_tmp = $_FILES['foto']['tmp_name'];
        $nome_arquivo = $_FILES['foto']['name'];
        $extensao = strtolower(pathinfo($nome_arquivo, PATHINFO_EXTENSION));
        
        if (in_array($extensao, $extensoes_permitidas)) {
            $novo_nome = 'funcionario_' . $funcionario_id . '_' . time() . '.' . $extensao;
            $upload_dir = __DIR__ . '/../uploads/funcionarios/';
            
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $caminho_completo = $upload_dir . $novo_nome;
            
            if (move_uploaded_file($arquivo_tmp, $caminho_completo)) {
                if ($dados['foto'] && file_exists($upload_dir . $dados['foto'])) {
                    unlink($upload_dir . $dados['foto']);
                }
                $foto_path = $novo_nome;
            }
        }
    }
    
    try {
        $sql_update_func = "
            UPDATE funcionarios SET 
                nome = :nome,
                telefone = :telefone,
                endereco = :endereco,
                data_nascimento = :data_nascimento,
                genero = :genero,
                bi = :bi,
                nif = :nif,
                foto = :foto,
                updated_at = NOW()
            WHERE id = :funcionario_id
        ";
        $stmt_func = $conn->prepare($sql_update_func);
        $stmt_func->execute([
            ':nome' => $nome,
            ':telefone' => $telefone,
            ':endereco' => $endereco,
            ':data_nascimento' => $data_nascimento,
            ':genero' => $genero,
            ':bi' => $bi,
            ':nif' => $nif,
            ':foto' => $foto_path,
            ':funcionario_id' => $funcionario_id
        ]);
        
        $sql_update_user = "UPDATE usuarios SET email = :email WHERE id = :usuario_id";
        $stmt_user = $conn->prepare($sql_update_user);
        $stmt_user->execute([
            ':email' => $email,
            ':usuario_id' => $dados['usuario_id']
        ]);
        
        $_SESSION['pedagogo_nome'] = $nome;
        $_SESSION['pedagogo_email'] = $email;
        $_SESSION['pedagogo_foto'] = $foto_path;
        
        $mensagem = "Perfil atualizado com sucesso!";
        
        $stmt_funcionario->execute([':funcionario_id' => $funcionario_id]);
        $dados = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $erro = "Erro ao atualizar perfil: " . $e->getMessage();
    }
}

// ============================================
// PROCESSAR AÇÕES FINANCEIRAS
// ============================================

// Solicitar vale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar_vale') {
    $valor = (float)str_replace(',', '.', $_POST['valor']);
    $motivo = trim($_POST['motivo']);
    $data_prevista_devolucao = $_POST['data_prevista_devolucao'];
    
    if ($valor <= 0) {
        $erro = "O valor do vale deve ser maior que zero.";
    } elseif (empty($motivo)) {
        $erro = "Informe o motivo da solicitação.";
    } else {
        $sql = "INSERT INTO funcionario_vales (funcionario_id, valor, motivo, data_prevista_devolucao, status) 
                VALUES (:funcionario_id, :valor, :motivo, :data_prevista_devolucao, 'pendente')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':funcionario_id' => $funcionario_id,
            ':valor' => $valor,
            ':motivo' => $motivo,
            ':data_prevista_devolucao' => $data_prevista_devolucao
        ]);
        $mensagem = "Vale solicitado com sucesso! Aguardando aprovação.";
    }
}

// Solicitar férias
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar_ferias') {
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    $motivo = trim($_POST['motivo']);
    
    $dias_solicitados = floor((strtotime($data_fim) - strtotime($data_inicio)) / (60 * 60 * 24)) + 1;
    $ano_atual = date('Y');
    
    $sql_ferias = "SELECT dias_restantes FROM funcionario_ferias WHERE funcionario_id = :funcionario_id AND ano_referente = :ano";
    $stmt_ferias = $conn->prepare($sql_ferias);
    $stmt_ferias->execute([':funcionario_id' => $funcionario_id, ':ano' => $ano_atual]);
    $ferias = $stmt_ferias->fetch(PDO::FETCH_ASSOC);
    
    if (!$ferias) {
        $sql_insert = "INSERT INTO funcionario_ferias (funcionario_id, ano_referente, dias_totais, dias_usados, dias_restantes) 
                       VALUES (:funcionario_id, :ano, 30, 0, 30)";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([':funcionario_id' => $funcionario_id, ':ano' => $ano_atual]);
        $dias_restantes = 30;
    } else {
        $dias_restantes = $ferias['dias_restantes'];
    }
    
    if ($dias_solicitados > $dias_restantes) {
        $erro = "Você não tem dias de férias suficientes. Disponível: $dias_restantes dias.";
    } else {
        $sql = "INSERT INTO funcionario_solicitacoes_ferias (funcionario_id, data_inicio, data_fim, dias_solicitados, motivo, status) 
                VALUES (:funcionario_id, :data_inicio, :data_fim, :dias_solicitados, :motivo, 'pendente')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':funcionario_id' => $funcionario_id,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim,
            ':dias_solicitados' => $dias_solicitados,
            ':motivo' => $motivo
        ]);
        $mensagem = "Solicitação de férias enviada com sucesso!";
    }
}

// Registrar despesa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'registrar_despesa') {
    $categoria = $_POST['categoria'];
    $descricao = trim($_POST['descricao']);
    $valor = (float)str_replace(',', '.', $_POST['valor']);
    $data_despesa = $_POST['data_despesa'];
    
    if ($valor <= 0) {
        $erro = "O valor da despesa deve ser maior que zero.";
    } else {
        $sql = "INSERT INTO funcionario_despesas (funcionario_id, categoria, descricao, valor, data_despesa) 
                VALUES (:funcionario_id, :categoria, :descricao, :valor, :data_despesa)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':funcionario_id' => $funcionario_id,
            ':categoria' => $categoria,
            ':descricao' => $descricao,
            ':valor' => $valor,
            ':data_despesa' => $data_despesa
        ]);
        $mensagem = "Despesa registrada com sucesso!";
    }
}

// Registrar rendimento extra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'registrar_rendimento') {
    $tipo = $_POST['tipo'];
    $descricao = trim($_POST['descricao_rendimento']);
    $valor = (float)str_replace(',', '.', $_POST['valor_rendimento']);
    $data_rendimento = $_POST['data_rendimento'];
    
    if ($valor <= 0) {
        $erro = "O valor do rendimento deve ser maior que zero.";
    } else {
        $sql = "INSERT INTO funcionario_rendimentos (funcionario_id, tipo, descricao, valor, data_rendimento) 
                VALUES (:funcionario_id, :tipo, :descricao, :valor, :data_rendimento)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':funcionario_id' => $funcionario_id,
            ':tipo' => $tipo,
            ':descricao' => $descricao,
            ':valor' => $valor,
            ':data_rendimento' => $data_rendimento
        ]);
        $mensagem = "Rendimento extra registrado com sucesso!";
    }
}

// ============================================
// BUSCAR DADOS FINANCEIROS
// ============================================

// Salários
$sql_salarios_lista = "SELECT * FROM funcionario_salarios WHERE funcionario_id = :funcionario_id ORDER BY ano DESC, mes DESC";
$stmt_salarios = $conn->prepare($sql_salarios_lista);
$stmt_salarios->execute([':funcionario_id' => $funcionario_id]);
$salarios = $stmt_salarios->fetchAll(PDO::FETCH_ASSOC);

// Vales
$sql_vales_lista = "SELECT * FROM funcionario_vales WHERE funcionario_id = :funcionario_id ORDER BY created_at DESC";
$stmt_vales = $conn->prepare($sql_vales_lista);
$stmt_vales->execute([':funcionario_id' => $funcionario_id]);
$vales = $stmt_vales->fetchAll(PDO::FETCH_ASSOC);

// Solicitações de férias
$sql_solicitacoes = "SELECT * FROM funcionario_solicitacoes_ferias WHERE funcionario_id = :funcionario_id ORDER BY created_at DESC";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([':funcionario_id' => $funcionario_id]);
$solicitacoes_ferias = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// Informações de férias do ano atual
$ano_atual = date('Y');
$sql_ferias_info = "SELECT * FROM funcionario_ferias WHERE funcionario_id = :funcionario_id AND ano_referente = :ano";
$stmt_ferias_info = $conn->prepare($sql_ferias_info);
$stmt_ferias_info->execute([':funcionario_id' => $funcionario_id, ':ano' => $ano_atual]);
$ferias_info = $stmt_ferias_info->fetch(PDO::FETCH_ASSOC);

if (!$ferias_info) {
    $ferias_info = ['dias_totais' => 30, 'dias_usados' => 0, 'dias_restantes' => 30];
}

// Despesas
$sql_despesas_lista = "SELECT * FROM funcionario_despesas WHERE funcionario_id = :funcionario_id ORDER BY data_despesa DESC LIMIT 10";
$stmt_despesas = $conn->prepare($sql_despesas_lista);
$stmt_despesas->execute([':funcionario_id' => $funcionario_id]);
$despesas = $stmt_despesas->fetchAll(PDO::FETCH_ASSOC);

// Total de despesas pendentes
$sql_total_despesas = "SELECT SUM(valor) as total FROM funcionario_despesas WHERE funcionario_id = :funcionario_id AND status = 'pendente'";
$stmt_total_despesas = $conn->prepare($sql_total_despesas);
$stmt_total_despesas->execute([':funcionario_id' => $funcionario_id]);
$total_despesas = $stmt_total_despesas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Rendimentos extras
$sql_rendimentos_lista = "SELECT * FROM funcionario_rendimentos WHERE funcionario_id = :funcionario_id ORDER BY data_rendimento DESC LIMIT 10";
$stmt_rendimentos = $conn->prepare($sql_rendimentos_lista);
$stmt_rendimentos->execute([':funcionario_id' => $funcionario_id]);
$rendimentos = $stmt_rendimentos->fetchAll(PDO::FETCH_ASSOC);

// Total de rendimentos no mês
$mes_atual = date('m');
$sql_total_rendimentos_mes = "SELECT SUM(valor) as total FROM funcionario_rendimentos WHERE funcionario_id = :funcionario_id AND MONTH(data_rendimento) = :mes";
$stmt_total_rendimentos = $conn->prepare($sql_total_rendimentos_mes);
$stmt_total_rendimentos->execute([':funcionario_id' => $funcionario_id, ':mes' => $mes_atual]);
$total_rendimentos_mes = $stmt_total_rendimentos->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Último salário
$sql_ultimo_salario = "SELECT * FROM funcionario_salarios WHERE funcionario_id = :funcionario_id ORDER BY ano DESC, mes DESC LIMIT 1";
$stmt_ultimo = $conn->prepare($sql_ultimo_salario);
$stmt_ultimo->execute([':funcionario_id' => $funcionario_id]);
$ultimo_salario = $stmt_ultimo->fetch(PDO::FETCH_ASSOC);

$nome_meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$foto_url = !empty($dados['foto']) ? '../uploads/funcionarios/' . $dados['foto'] : 'https://ui-avatars.com/api/?background=006B3E&color=fff&name=' . urlencode($dados['nome']);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); 
            padding: 20px; 
            min-height: 100vh; 
        }
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
        .stat-success .stat-number { color: #27ae60; }
        .stat-warning .stat-number { color: #e67e22; }
        
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .card:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-header i { margin-right: 10px; }
        .card-body { padding: 25px; }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 12px;
        }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #1e5799;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .profile-header {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-pendente { background: #ffc107; color: #856404; }
        .badge-aprovado { background: #28a745; color: white; }
        .badge-pago { background: #17a2b8; color: white; }
        .badge-rejeitado { background: #dc3545; color: white; }
        
        .ferias-progress {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }
        .ferias-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            border-radius: 5px;
            transition: width 0.3s;
        }
        
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
        
        .photo-upload { position: relative; display: inline-block; }
        .photo-overlay {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #1e5799;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
            transition: all 0.3s;
        }
        .photo-overlay:hover { background: #006B3E; transform: scale(1.1); }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
            text-transform: uppercase;
        }
        td { padding: 10px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; text-align: center; }
        tr:hover { background: #f8f9fa; }
        
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
            max-width: 500px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
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
        }
        .modal-custom-header.modal-success { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; }
        .modal-custom-header.modal-danger { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .modal-custom-header.modal-info { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        .modal-custom-header h3 { font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .close-modal:hover { background: rgba(255,255,255,0.2); }
        .modal-custom-body { padding: 25px; }
        .modal-custom-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn-cancelar-modal {
            background: #6c757d;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-confirmar-modal {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .header { flex-direction: column; text-align: center; }
            .profile-header { flex-direction: column; text-align: center; }
            th, td { font-size: 11px; padding: 8px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-user-circle"></i> Meu Perfil</h1>
            <p>Gerencie suas informações pessoais e financeiras</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <!-- Cards de Estatísticas Financeiras -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo number_format($ultimo_salario['salario_liquido'] ?? 0, 2); ?> Kz</div>
            <div class="stat-label">Último Salário</div>
            <small><?php echo isset($ultimo_salario['mes']) ? $nome_meses[$ultimo_salario['mes']] . '/' . $ultimo_salario['ano'] : '---'; ?></small>
        </div>
        <div class="stat-card stat-warning">
            <div class="stat-number"><?php echo number_format($total_rendimentos_mes, 2); ?> Kz</div>
            <div class="stat-label">Rendimentos Extras (Mês)</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $ferias_info['dias_restantes']; ?></div>
            <div class="stat-label">Dias de Férias</div>
            <small>Disponíveis</small>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($vales); ?></div>
            <div class="stat-label">Vales Solicitados</div>
        </div>
    </div>
    
    <div class="row">
        <!-- Coluna Esquerda - Informações Pessoais -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-id-card"></i> Informações Pessoais
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="atualizar_perfil">
                        
                        <div class="text-center mb-4">
                            <div class="photo-upload">
                                <img src="<?php echo $foto_url; ?>" alt="Foto de perfil" class="profile-avatar" id="avatarPreview">
                                <label class="photo-overlay" title="Alterar foto">
                                    <i class="fas fa-camera"></i>
                                    <input type="file" name="foto" id="fotoInput" accept="image/*" style="display: none;">
                                </label>
                            </div>
                            <h4 class="mt-3"><?php echo htmlspecialchars($dados['nome']); ?></h4>
                            <p class="text-muted"><?php echo htmlspecialchars($dados['cargo'] ?? 'Pedagogo'); ?></p>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">Nome Completo *</label>
                                    <input type="text" name="nome" class="form-control" required value="<?php echo htmlspecialchars($dados['nome']); ?>">
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">E-mail *</label>
                                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($dados['email']); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Telefone</label>
                                    <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($dados['telefone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Data de Nascimento</label>
                                    <input type="date" name="data_nascimento" class="form-control" value="<?php echo htmlspecialchars($dados['data_nascimento'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Gênero</label>
                                    <select name="genero" class="form-select">
                                        <option value="">Selecione</option>
                                        <option value="masculino" <?php echo ($dados['genero'] ?? '') == 'masculino' ? 'selected' : ''; ?>>Masculino</option>
                                        <option value="feminino" <?php echo ($dados['genero'] ?? '') == 'feminino' ? 'selected' : ''; ?>>Feminino</option>
                                        <option value="outro" <?php echo ($dados['genero'] ?? '') == 'outro' ? 'selected' : ''; ?>>Outro</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">BI / Documento</label>
                                    <input type="text" name="bi" class="form-control" value="<?php echo htmlspecialchars($dados['bi'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">Endereço</label>
                                    <textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($dados['endereco'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-end mt-3">
                            <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Salvar Alterações</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Coluna Direita - Financeiro -->
        <div class="col-md-7">
            <!-- Histórico de Salários -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-money-bill-wave"></i> Histórico de Salários
                </div>
                <div class="card-body">
                    <?php if (empty($salarios)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-chart-line fa-2x mb-2"></i>
                            <p>Nenhum registro de salário encontrado.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Mês/Ano</th><th>Salário Base</th><th>Subsídios</th><th>Bônus</th><th>Descontos</th><th>Líquido</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($salarios, 0, 5) as $s): ?>
                                        <tr>
                                            <td><?php echo $nome_meses[$s['mes']] . '/' . $s['ano']; ?></td>
                                            <td><?php echo number_format($s['salario_base'], 2); ?> Kz</td>
                                            <td><?php echo number_format($s['subsidio_alimentacao'] + $s['subsidio_transporte'], 2); ?> Kz</td>
                                            <td><?php echo number_format($s['bonus'], 2); ?> Kz</td>
                                            <td class="text-danger">- <?php echo number_format($s['descontos'], 2); ?> Kz</td>
                                            <td><strong><?php echo number_format($s['salario_liquido'], 2); ?> Kz</strong></td>
                                            <td><span class="badge-status <?php echo $s['status'] == 'pago' ? 'badge-aprovado' : 'badge-pendente'; ?>"><?php echo $s['status']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($salarios) > 5): ?>
                            <div class="text-center mt-2"><small class="text-muted">+ <?php echo count($salarios) - 5; ?> meses anteriores</small></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Vales e Férias -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-hand-holding-usd"></i> Vales
                            <button class="btn-primary-custom float-end" style="padding: 4px 12px; font-size: 11px;" onclick="abrirModal('modalVale')">+ Novo</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($vales)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-coins fa-2x mb-2"></i>
                                    <p>Nenhum vale solicitado.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($vales, 0, 3) as $v): ?>
                                    <div class="mb-2 pb-2 border-bottom">
                                        <div class="d-flex justify-content-between">
                                            <span><strong><?php echo number_format($v['valor'], 2); ?> Kz</strong></span>
                                            <span class="badge-status <?php echo $v['status'] == 'aprovado' ? 'badge-aprovado' : ($v['status'] == 'pendente' ? 'badge-pendente' : 'badge-rejeitado'); ?>"><?php echo $v['status']; ?></span>
                                        </div>
                                        <small class="text-muted"><?php echo date('d/m/Y', strtotime($v['data_solicicacao'] ?? $v['created_at'])); ?></small>
                                        <div><small><?php echo htmlspecialchars(substr($v['motivo'], 0, 40)); ?></small></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-umbrella-beach"></i> Férias
                            <button class="btn-primary-custom float-end" style="padding: 4px 12px; font-size: 11px;" onclick="abrirModal('modalFerias')">+ Solicitar</button>
                        </div>
                        <div class="card-body">
                            <div class="text-center">
                                <h4><?php echo $ferias_info['dias_restantes']; ?> / <?php echo $ferias_info['dias_totais']; ?> dias</h4>
                                <div class="ferias-progress">
                                    <div class="ferias-progress-bar" style="width: <?php echo ($ferias_info['dias_usados'] / $ferias_info['dias_totais']) * 100; ?>%"></div>
                                </div>
                                <small class="text-muted">Usados: <?php echo $ferias_info['dias_usados']; ?> dias</small>
                            </div>
                            <?php if (!empty($solicitacoes_ferias)): ?>
                                <div class="mt-3">
                                    <?php foreach (array_slice($solicitacoes_ferias, 0, 2) as $sf): ?>
                                        <div class="small text-muted"><?php echo date('d/m', strtotime($sf['data_inicio'])); ?> - <?php echo date('d/m', strtotime($sf['data_fim'])); ?>: <?php echo $sf['status']; ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Despesas e Rendimentos -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-receipt"></i> Despesas
                            <button class="btn-primary-custom float-end" style="padding: 4px 12px; font-size: 11px;" onclick="abrirModal('modalDespesa')">+ Nova</button>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info py-2 mb-2">
                                <small>Total pendente: <?php echo number_format($total_despesas, 2); ?> Kz</small>
                            </div>
                            <?php if (empty($despesas)): ?>
                                <div class="text-center text-muted py-2">
                                    <small>Nenhuma despesa registrada.</small>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($despesas, 0, 3) as $d): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                                        <span><?php echo htmlspecialchars(substr($d['descricao'], 0, 20)); ?></span>
                                        <span><?php echo number_format($d['valor'], 2); ?> Kz</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-chart-line"></i> Rendimentos Extras
                            <button class="btn-primary-custom float-end" style="padding: 4px 12px; font-size: 11px;" onclick="abrirModal('modalRendimento')">+ Novo</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($rendimentos)): ?>
                                <div class="text-center text-muted py-2">
                                    <small>Nenhum rendimento registrado.</small>
                                </div>
                            <?php else: ?>
                                <?php foreach (array_slice($rendimentos, 0, 3) as $r): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                                        <span><?php echo $r['tipo']; ?></span>
                                        <span class="text-success">+ <?php echo number_format($r['valor'], 2); ?> Kz</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Solicitar Vale -->
<div id="modalVale" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-info">
            <h3><i class="fas fa-hand-holding-usd"></i> Solicitar Vale</h3>
            <span class="close-modal" onclick="fecharModal('modalVale')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST">
                <input type="hidden" name="action" value="solicitar_vale">
                <div class="form-group">
                    <label class="form-label">Valor (Kz)</label>
                    <input type="text" name="valor" class="form-control" required placeholder="Ex: 50000,00">
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <textarea name="motivo" class="form-control" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Data prevista devolução</label>
                    <input type="date" name="data_prevista_devolucao" class="form-control">
                </div>
                <div class="text-end">
                    <button type="button" class="btn-cancelar-modal" onclick="fecharModal('modalVale')">Cancelar</button>
                    <button type="submit" class="btn-confirmar-modal">Solicitar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Solicitar Férias -->
<div id="modalFerias" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-info">
            <h3><i class="fas fa-umbrella-beach"></i> Solicitar Férias</h3>
            <span class="close-modal" onclick="fecharModal('modalFerias')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST">
                <input type="hidden" name="action" value="solicitar_ferias">
                <div class="form-group">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <textarea name="motivo" class="form-control" rows="2"></textarea>
                </div>
                <div class="alert alert-info py-2">
                    <small>Dias disponíveis: <?php echo $ferias_info['dias_restantes']; ?></small>
                </div>
                <div class="text-end">
                    <button type="button" class="btn-cancelar-modal" onclick="fecharModal('modalFerias')">Cancelar</button>
                    <button type="submit" class="btn-confirmar-modal">Solicitar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Registrar Despesa -->
<div id="modalDespesa" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-info">
            <h3><i class="fas fa-receipt"></i> Registrar Despesa</h3>
            <span class="close-modal" onclick="fecharModal('modalDespesa')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST">
                <input type="hidden" name="action" value="registrar_despesa">
                <div class="form-group">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select" required>
                        <option value="Alimentação">Alimentação</option>
                        <option value="Transporte">Transporte</option>
                        <option value="Material Didático">Material Didático</option>
                        <option value="Saúde">Saúde</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <input type="text" name="descricao" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Valor (Kz)</label>
                    <input type="text" name="valor" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Data da Despesa</label>
                    <input type="date" name="data_despesa" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="text-end">
                    <button type="button" class="btn-cancelar-modal" onclick="fecharModal('modalDespesa')">Cancelar</button>
                    <button type="submit" class="btn-confirmar-modal">Registrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Registrar Rendimento -->
<div id="modalRendimento" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-success">
            <h3><i class="fas fa-chart-line"></i> Registrar Rendimento</h3>
            <span class="close-modal" onclick="fecharModal('modalRendimento')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST">
                <input type="hidden" name="action" value="registrar_rendimento">
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" required>
                        <option value="Aula Extra">Aula Extra</option>
                        <option value="Curso">Curso</option>
                        <option value="Palestra">Palestra</option>
                        <option value="Formação">Formação</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <input type="text" name="descricao_rendimento" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Valor (Kz)</label>
                    <input type="text" name="valor_rendimento" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Data</label>
                    <input type="date" name="data_rendimento" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="text-end">
                    <button type="button" class="btn-cancelar-modal" onclick="fecharModal('modalRendimento')">Cancelar</button>
                    <button type="submit" class="btn-confirmar-modal">Registrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Mensagem -->
<div id="modalMensagem" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header" id="msgHeader">
            <h3><i class="fas fa-check-circle"></i> Sucesso!</h3>
            <span class="close-modal" onclick="fecharModal('modalMensagem')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="msgTexto"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-confirmar-modal" onclick="fecharModal('modalMensagem')">OK</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Preview da foto
    document.getElementById('fotoInput')?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                document.getElementById('avatarPreview').src = event.target.result;
            };
            reader.readAsDataURL(file);
        }
    });
    
    document.querySelector('.photo-overlay')?.addEventListener('click', function() {
        document.getElementById('fotoInput').click();
    });
    
    function abrirModal(modalId) {
        document.getElementById(modalId).style.display = 'block';
    }
    
    function fecharModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    function showMessage(message, isError = false) {
        const modal = document.getElementById('modalMensagem');
        const header = document.getElementById('msgHeader');
        const msgText = document.getElementById('msgTexto');
        
        if (isError) {
            header.className = 'modal-custom-header modal-danger';
            header.querySelector('h3').innerHTML = '<i class="fas fa-times-circle"></i> Erro!';
        } else {
            header.className = 'modal-custom-header modal-success';
            header.querySelector('h3').innerHTML = '<i class="fas fa-check-circle"></i> Sucesso!';
        }
        
        msgText.innerHTML = message;
        modal.style.display = 'block';
        
        setTimeout(() => {
            if (modal.style.display === 'block') {
                fecharModal('modalMensagem');
            }
        }, 3000);
    }
    
    window.onclick = function(event) {
        const modals = ['modalVale', 'modalFerias', 'modalDespesa', 'modalRendimento', 'modalMensagem'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target == modal) {
                fecharModal(modalId);
            }
        });
    }
    
    <?php if ($mensagem): ?>
    showMessage('<?php echo addslashes($mensagem); ?>', false);
    <?php endif; ?>
    
    <?php if ($erro): ?>
    showMessage('<?php echo addslashes($erro); ?>', true);
    <?php endif; ?>
</script>
</body>
</html>