<?php
// escola/pedagogico/turnos.php - Gestão de Turnos (com Modal de Confirmação e Botões Fixos)

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
// PROCESSAR AJAX PARA BUSCAR TURNO
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM turnos WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $turno = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($turno) {
            echo json_encode(['success' => true, 'turno' => $turno]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Turno não encontrado']);
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
CREATE TABLE IF NOT EXISTS `turnos` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(50) NOT NULL,
    `sigla` VARCHAR(10) NOT NULL,
    `horario_inicio` TIME NOT NULL,
    `horario_fim` TIME NOT NULL,
    `duracao_aula` INT DEFAULT 45,
    `intervalo_inicio` TIME DEFAULT NULL,
    `intervalo_fim` TIME DEFAULT NULL,
    `dias_semana` VARCHAR(100) DEFAULT NULL,
    `escola_id` INT NOT NULL,
    `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_sigla_escola` (`escola_id`, `sigla`),
    UNIQUE KEY `unique_nome_escola` (`escola_id`, `nome`),
    KEY `idx_escola_id` (`escola_id`),
    KEY `idx_status` (`status`)
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

function gerarSiglaAutomatica($nome) {
    $mapa_siglas = [
        'manha' => 'M',
        'manhã' => 'M',
        'matutino' => 'MAT',
        'tarde' => 'T',
        'vespertino' => 'VESP',
        'noite' => 'N',
        'noturno' => 'NOT',
        'integral' => 'INT',
        'integral manha' => 'INTM',
        'integral tarde' => 'INTT',
        'integral noite' => 'INTN',
        'diurno' => 'D',
        'intermediario' => 'INTM',
        'especial' => 'ESP'
    ];
    
    $nome_lower = strtolower(trim($nome));
    
    if (isset($mapa_siglas[$nome_lower])) {
        return $mapa_siglas[$nome_lower];
    }
    
    $palavras = explode(' ', $nome_lower);
    $sigla = '';
    
    foreach ($palavras as $palavra) {
        if (!empty($palavra)) {
            $sigla .= strtoupper(substr($palavra, 0, 1));
        }
    }
    
    if (strlen($sigla) > 5) {
        $sigla = substr($sigla, 0, 5);
    }
    
    return $sigla;
}

function gerarSiglaUnica($conn, $escola_id, $sigla_base, $nome) {
    $sigla = $sigla_base;
    $contador = 1;
    
    $sql_check = "SELECT id FROM turnos WHERE escola_id = :escola_id AND sigla = :sigla";
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

// Inserir novo turno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $nome = trim($_POST['nome']);
    $horario_inicio = $_POST['horario_inicio'];
    $horario_fim = $_POST['horario_fim'];
    $duracao_aula = !empty($_POST['duracao_aula']) ? (int)$_POST['duracao_aula'] : 45;
    $intervalo_inicio = !empty($_POST['intervalo_inicio']) ? $_POST['intervalo_inicio'] : null;
    $intervalo_fim = !empty($_POST['intervalo_fim']) ? $_POST['intervalo_fim'] : null;
    $dias_semana = !empty($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
    $status = $_POST['status'];
    
    if (empty($nome)) {
        $erro = "O nome do turno é obrigatório.";
    } elseif (empty($horario_inicio)) {
        $erro = "O horário de início é obrigatório.";
    } elseif (empty($horario_fim)) {
        $erro = "O horário de fim é obrigatório.";
    } else {
        if (strtotime($horario_fim) <= strtotime($horario_inicio)) {
            $erro = "O horário de fim deve ser maior que o horário de início.";
        } elseif ($intervalo_inicio && $intervalo_fim && strtotime($intervalo_fim) <= strtotime($intervalo_inicio)) {
            $erro = "O horário de fim do intervalo deve ser maior que o horário de início.";
        } else {
            $sql_check = "SELECT id FROM turnos WHERE escola_id = :escola_id AND nome = :nome";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':escola_id' => $escola_id, ':nome' => $nome]);
            
            if ($stmt_check->fetch()) {
                $erro = "Já existe um turno cadastrado com este nome.";
            } else {
                $sigla_base = gerarSiglaAutomatica($nome);
                $sigla = gerarSiglaUnica($conn, $escola_id, $sigla_base, $nome);
                
                $sql = "INSERT INTO turnos (escola_id, nome, sigla, horario_inicio, horario_fim, duracao_aula, intervalo_inicio, intervalo_fim, dias_semana, status) 
                        VALUES (:escola_id, :nome, :sigla, :horario_inicio, :horario_fim, :duracao_aula, :intervalo_inicio, :intervalo_fim, :dias_semana, :status)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':nome' => $nome,
                    ':sigla' => $sigla,
                    ':horario_inicio' => $horario_inicio,
                    ':horario_fim' => $horario_fim,
                    ':duracao_aula' => $duracao_aula,
                    ':intervalo_inicio' => $intervalo_inicio,
                    ':intervalo_fim' => $intervalo_fim,
                    ':dias_semana' => $dias_semana,
                    ':status' => $status
                ]);
                
                $mensagem = "Turno cadastrado com sucesso! Sigla gerada: $sigla";
            }
        }
    }
}

// Atualizar turno
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $nome = trim($_POST['nome']);
    $sigla = trim($_POST['sigla']);
    $horario_inicio = $_POST['horario_inicio'];
    $horario_fim = $_POST['horario_fim'];
    $duracao_aula = !empty($_POST['duracao_aula']) ? (int)$_POST['duracao_aula'] : 45;
    $intervalo_inicio = !empty($_POST['intervalo_inicio']) ? $_POST['intervalo_inicio'] : null;
    $intervalo_fim = !empty($_POST['intervalo_fim']) ? $_POST['intervalo_fim'] : null;
    $dias_semana = !empty($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : null;
    $status = $_POST['status'];
    
    if (empty($nome)) {
        $erro = "O nome do turno é obrigatório.";
    } elseif (empty($horario_inicio)) {
        $erro = "O horário de início é obrigatório.";
    } elseif (empty($horario_fim)) {
        $erro = "O horário de fim é obrigatório.";
    } else {
        if (strtotime($horario_fim) <= strtotime($horario_inicio)) {
            $erro = "O horário de fim deve ser maior que o horário de início.";
        } elseif ($intervalo_inicio && $intervalo_fim && strtotime($intervalo_fim) <= strtotime($intervalo_inicio)) {
            $erro = "O horário de fim do intervalo deve ser maior que o horário de início.";
        } else {
            $sql_check = "SELECT id FROM turnos WHERE escola_id = :escola_id AND nome = :nome AND id != :id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':escola_id' => $escola_id, ':nome' => $nome, ':id' => $id]);
            
            if ($stmt_check->fetch()) {
                $erro = "Já existe outro turno cadastrado com este nome.";
            } else {
                $sql = "UPDATE turnos SET 
                        nome = :nome,
                        sigla = :sigla,
                        horario_inicio = :horario_inicio,
                        horario_fim = :horario_fim,
                        duracao_aula = :duracao_aula,
                        intervalo_inicio = :intervalo_inicio,
                        intervalo_fim = :intervalo_fim,
                        dias_semana = :dias_semana,
                        status = :status
                        WHERE id = :id AND escola_id = :escola_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':nome' => $nome,
                    ':sigla' => $sigla,
                    ':horario_inicio' => $horario_inicio,
                    ':horario_fim' => $horario_fim,
                    ':duracao_aula' => $duracao_aula,
                    ':intervalo_inicio' => $intervalo_inicio,
                    ':intervalo_fim' => $intervalo_fim,
                    ':dias_semana' => $dias_semana,
                    ':status' => $status,
                    ':id' => $id,
                    ':escola_id' => $escola_id
                ]);
                
                $mensagem = "Turno atualizado com sucesso!";
            }
        }
    }
}

// Excluir turno
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $sql_check = "SELECT COUNT(*) as total FROM turmas WHERE turno_id = :turno_id";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([':turno_id' => $id]);
    $total_turmas = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_turmas > 0) {
        $erro = "Não é possível excluir este turno pois existem $total_turmas turmas associadas.";
    } else {
        $sql = "DELETE FROM turnos WHERE id = :id AND escola_id = :escola_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
        $mensagem = "Turno excluído com sucesso!";
    }
}

// ============================================
// BUSCAR TURNOS
// ============================================
$sql_turnos = "SELECT * FROM turnos WHERE escola_id = :escola_id ORDER BY horario_inicio ASC";
$stmt_turnos = $conn->prepare($sql_turnos);
$stmt_turnos->execute([':escola_id' => $escola_id]);
$turnos = $stmt_turnos->fetchAll(PDO::FETCH_ASSOC);

$total_turnos = count($turnos);
$turnos_ativos = 0;
foreach ($turnos as $t) {
    if ($t['status'] == 'ativo') $turnos_ativos++;
}

$dias_semana_lista = [
    'segunda' => 'Segunda-feira',
    'terca' => 'Terça-feira',
    'quarta' => 'Quarta-feira',
    'quinta' => 'Quinta-feira',
    'sexta' => 'Sexta-feira',
    'sabado' => 'Sábado',
    'domingo' => 'Domingo'
];
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turnos - SIGE Angola</title>
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
        
        .table-turnos { width: 100%; border-collapse: collapse; }
        .table-turnos th {
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
        .table-turnos td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-turnos tr:hover { background: #f8f9fa; }
        
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        
        .horario-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e8f4f8;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: #1e5799;
        }
        
        .dias-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #fef3c7;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            color: #d97706;
            margin: 2px;
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
        
        /* Modais Globais */
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
            max-width: 600px;
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
        .modal-custom-header.modal-warning { background: linear-gradient(135deg, #ffc107, #e0a800); color: #333; }
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
        .modal-custom-body .modal-message { margin-bottom: 15px; }
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
        
        /* Modal Formulário */
        .modal-form-content {
            max-width: 700px !important;
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .checkbox-item input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-size: 13px;
        }
        
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
        
        .duracao-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-turnos { font-size: 11px; }
            .table-turnos th, .table-turnos td { padding: 8px; }
            .modal-custom-content { margin: 10% auto; width: 95%; max-height: 85vh; }
            .checkbox-group { gap: 10px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-clock"></i> Turnos</h1>
            <p>Gestão dos turnos de funcionamento da escola</p>
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
            <div class="stat-number"><?php echo $total_turnos; ?></div>
            <div class="stat-label">Total de Turnos</div>
        </div>
        <div class="stat-card stat-ativos">
            <div class="stat-number"><?php echo $turnos_ativos; ?></div>
            <div class="stat-label">Turnos Ativos</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_turnos - $turnos_ativos; ?></div>
            <div class="stat-label">Turnos Inativos</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Turnos Cadastrados
            <span class="badge bg-light text-dark ms-2"><?php echo $total_turnos; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Novo Turno</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($turnos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-clock fa-3x mb-3"></i>
                    <p>Nenhum turno cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeiro turno</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-turnos">
                        <thead>
                            <tr>
                                <th>Sigla</th>
                                <th>Nome</th>
                                <th>Horário</th>
                                <th>Duração Aula</th>
                                <th>Intervalo</th>
                                <th>Dias</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($turnos as $turno): ?>
                                <tr>
                                    <td class="text-center"><strong><?php echo htmlspecialchars($turno['sigla']); ?></strong></td>
                                    <td class="text-center"><strong><?php echo htmlspecialchars($turno['nome']); ?></strong></td>
                                    <td class="text-center">
                                        <span class="horario-badge">
                                            <i class="fas fa-sun"></i> <?php echo date('H:i', strtotime($turno['horario_inicio'])); ?> 
                                            <i class="fas fa-arrow-right"></i> 
                                            <i class="fas fa-moon"></i> <?php echo date('H:i', strtotime($turno['horario_fim'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo $turno['duracao_aula']; ?> min</td>
                                    <td class="text-center">
                                        <?php if ($turno['intervalo_inicio'] && $turno['intervalo_fim']): ?>
                                            <?php echo date('H:i', strtotime($turno['intervalo_inicio'])); ?> - 
                                            <?php echo date('H:i', strtotime($turno['intervalo_fim'])); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($turno['dias_semana']): 
                                            $dias = explode(',', $turno['dias_semana']);
                                            foreach ($dias as $dia): ?>
                                                <span class="dias-badge"><?php echo ucfirst(substr($dia, 0, 3)); ?></span>
                                            <?php endforeach;
                                        else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($turno['status'] == 'Ativo'): ?>
                                            <span class="badge-status badge-ativo">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-inativo">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-acao btn-editar" onclick="editarTurno(<?php echo $turno['id']; ?>)"><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn-acao btn-excluir" onclick="confirmarExclusao(<?php echo $turno['id']; ?>, '<?php echo htmlspecialchars(addslashes($turno['nome'])); ?>')"><i class="fas fa-trash"></i> Excluir</button>
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

<!-- ============================================ -->
<!-- MODAL DE CONFIRMAÇÃO DE EXCLUSÃO -->
<!-- ============================================ -->
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

<!-- ============================================ -->
<!-- MODAL DE INFORMAÇÃO/SUCESSO -->
<!-- ============================================ -->
<div id="modalInfo" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-success" id="modalInfoHeader">
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

<!-- ============================================ -->
<!-- MODAL DE ERRO -->
<!-- ============================================ -->
<div id="modalErro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-times-circle"></i> Erro!</h3>
            <span class="close-modal" onclick="fecharModalErro()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemErro"></p>
            <div id="detalhesErro" class="modal-details" style="display: none;"></div>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalErro()">OK</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL NOVO/EDITAR TURNO -->
<!-- ============================================ -->
<div id="modalTurno" class="modal-custom">
    <div class="modal-custom-content modal-form-content">
        <div class="modal-custom-header modal-info">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Turno</h3>
            <span class="close-modal" onclick="fecharModalTurno()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formTurno">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="turno_id" value="0">
                <input type="hidden" name="sigla" id="campo_sigla_hidden" value="">
                
                <div class="form-group">
                    <label class="form-label">Nome do Turno *</label>
                    <input type="text" name="nome" id="campo_nome" class="form-control" required placeholder="Ex: Manhã, Tarde, Noite" onkeyup="gerarSiglaPreview()" onchange="gerarSiglaPreview()">
                    <div id="siglaPreview" class="sigla-preview" style="display: none;">
                        <i class="fas fa-magic"></i> Sigla sugerida: <span id="siglaSugerida"></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Horário de Início *</label>
                            <input type="time" name="horario_inicio" id="campo_inicio" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Horário de Fim *</label>
                            <input type="time" name="horario_fim" id="campo_fim" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Duração da Aula (minutos)</label>
                            <input type="number" name="duracao_aula" id="campo_duracao_aula" class="form-control" value="45" min="30" max="120" step="5">
                            <div class="duracao-info" id="duracao_info"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" id="campo_status" class="form-select">
                                <option value="Ativo">Ativo</option>
                                <option value="Inativo">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Início do Intervalo</label>
                            <input type="time" name="intervalo_inicio" id="campo_intervalo_inicio" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Fim do Intervalo</label>
                            <input type="time" name="intervalo_fim" id="campo_intervalo_fim" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Dias da Semana</label>
                    <div class="checkbox-group" id="dias_semana_group">
                        <?php foreach ($dias_semana_lista as $valor => $nome): ?>
                            <div class="checkbox-item">
                                <input type="checkbox" name="dias_semana[]" value="<?php echo $valor; ?>" id="dia_<?php echo $valor; ?>">
                                <label for="dia_<?php echo $valor; ?>"><?php echo $nome; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Selecione os dias em que este turno funciona</small>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> <strong>Informações:</strong>
                    <ul class="mb-0 mt-2">
                        <li>✨ A <strong>sigla</strong> é gerada automaticamente baseada no nome do turno</li>
                        <li>⏱️ A duração total do turno e número de aulas são calculados automaticamente</li>
                        <li>📅 Turnos inativos não aparecem nos formulários</li>
                        <li>🔑 A sigla gerada será única por escola</li>
                    </ul>
                </div>
            </form>
        </div>
        <div class="modal-custom-footer">
            <button type="button" class="btn-cancelar-form" onclick="fecharModalTurno()">Cancelar</button>
            <button type="submit" class="btn-salvar" onclick="document.getElementById('formTurno').submit();"><i class="fas fa-save"></i> Salvar</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    var idParaExcluir = null;
    var nomeParaExcluir = null;
    
    // ============================================
    // MODAL DE CONFIRMAÇÃO DE EXCLUSÃO
    // ============================================
    function confirmarExclusao(id, nome) {
        idParaExcluir = id;
        nomeParaExcluir = nome;
        
        document.getElementById('mensagemConfirmacao').innerHTML = 'Tem certeza que deseja excluir o turno <strong>"' + nome + '"</strong>?';
        document.getElementById('detalhesConfirmacao').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita.';
        document.getElementById('modalConfirmacao').style.display = 'block';
    }
    
    function fecharModalConfirmacao() {
        document.getElementById('modalConfirmacao').style.display = 'none';
        idParaExcluir = null;
        nomeParaExcluir = null;
    }
    
    document.getElementById('btnConfirmarExclusao').onclick = function() {
        if (idParaExcluir) {
            window.location.href = '?action=excluir&id=' + idParaExcluir;
        }
        fecharModalConfirmacao();
    };
    
    // ============================================
    // MODAL DE INFORMAÇÃO
    // ============================================
    function showModalInfo(mensagem, tipo = 'success') {
        var header = document.getElementById('modalInfoHeader');
        var titulo = document.getElementById('modalInfoHeader').querySelector('h3');
        
        if (tipo === 'success') {
            header.className = 'modal-custom-header modal-success';
            titulo.innerHTML = '<i class="fas fa-check-circle"></i> Sucesso!';
        } else if (tipo === 'warning') {
            header.className = 'modal-custom-header modal-warning';
            titulo.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Atenção!';
        } else {
            header.className = 'modal-custom-header modal-info';
            titulo.innerHTML = '<i class="fas fa-info-circle"></i> Informação';
        }
        
        document.getElementById('mensagemInfo').innerHTML = mensagem;
        document.getElementById('modalInfo').style.display = 'block';
    }
    
    function fecharModalInfo() {
        document.getElementById('modalInfo').style.display = 'none';
    }
    
    // ============================================
    // MODAL DE ERRO
    // ============================================
    function showModalErro(mensagem, detalhes = null) {
        document.getElementById('mensagemErro').innerHTML = mensagem;
        
        var detalhesDiv = document.getElementById('detalhesErro');
        if (detalhes) {
            detalhesDiv.innerHTML = detalhes;
            detalhesDiv.style.display = 'block';
        } else {
            detalhesDiv.style.display = 'none';
        }
        
        document.getElementById('modalErro').style.display = 'block';
    }
    
    function fecharModalErro() {
        document.getElementById('modalErro').style.display = 'none';
    }
    
    // ============================================
    // FUNÇÕES DE GERENCIAMENTO DE TURNOS
    // ============================================
    
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
            'manha': 'M', 'manhã': 'M', 'matutino': 'MAT',
            'tarde': 'T', 'vespertino': 'VESP', 'noite': 'N',
            'noturno': 'NOT', 'integral': 'INT', 'diurno': 'D'
        };
        
        if (mapaSiglas[nomeLower]) {
            return mapaSiglas[nomeLower];
        }
        
        var palavras = nome.split(' ');
        var sigla = '';
        for (var i = 0; i < palavras.length; i++) {
            if (palavras[i].length > 0) {
                sigla += palavras[i].charAt(0).toUpperCase();
            }
        }
        
        if (sigla.length > 5) {
            sigla = sigla.substring(0, 5);
        }
        
        return sigla;
    }
    
    function calcularDuracaoTotal() {
        var inicio = document.getElementById('campo_inicio').value;
        var fim = document.getElementById('campo_fim').value;
        var duracaoAula = parseInt(document.getElementById('campo_duracao_aula').value) || 45;
        
        if (inicio && fim) {
            var inicioDate = new Date('2000-01-01T' + inicio + ':00');
            var fimDate = new Date('2000-01-01T' + fim + ':00');
            
            if (fimDate < inicioDate) {
                fimDate.setDate(fimDate.getDate() + 1);
            }
            
            var diffMinutos = (fimDate - inicioDate) / 60000;
            var numeroAulas = Math.floor(diffMinutos / duracaoAula);
            
            var infoDiv = document.getElementById('duracao_info');
            infoDiv.innerHTML = '⏱️ Duração total: ' + Math.floor(diffMinutos / 60) + 'h ' + (diffMinutos % 60) + 'min | ' + numeroAulas + ' aula(s) de ' + duracaoAula + 'min';
        }
    }
    
    document.getElementById('campo_inicio')?.addEventListener('change', calcularDuracaoTotal);
    document.getElementById('campo_fim')?.addEventListener('change', calcularDuracaoTotal);
    document.getElementById('campo_duracao_aula')?.addEventListener('change', calcularDuracaoTotal);
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Turno';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('turno_id').value = '0';
        document.getElementById('formTurno').reset();
        document.getElementById('campo_duracao_aula').value = '45';
        document.getElementById('campo_status').value = 'ativo';
        document.getElementById('siglaPreview').style.display = 'none';
        document.getElementById('campo_sigla_hidden').value = '';
        
        document.querySelectorAll('input[name="dias_semana[]"]').forEach(function(cb) {
            cb.checked = false;
        });
        
        calcularDuracaoTotal();
        document.getElementById('modalTurno').style.display = 'block';
    }
    
    function editarTurno(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Turno';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('turno_id').value = id;
        
        showModalInfo('Carregando dados do turno...', 'info');
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { ajax: 1, id: id },
            dataType: 'json',
            success: function(data) {
                fecharModalInfo();
                if (data.success) {
                    var t = data.turno;
                    document.getElementById('campo_nome').value = t.nome || '';
                    document.getElementById('campo_inicio').value = t.horario_inicio || '';
                    document.getElementById('campo_fim').value = t.horario_fim || '';
                    document.getElementById('campo_duracao_aula').value = t.duracao_aula || '45';
                    document.getElementById('campo_intervalo_inicio').value = t.intervalo_inicio || '';
                    document.getElementById('campo_intervalo_fim').value = t.intervalo_fim || '';
                    document.getElementById('campo_status').value = t.status || 'ativo';
                    document.getElementById('campo_sigla_hidden').value = t.sigla || '';
                    
                    document.getElementById('siglaPreview').style.display = 'none';
                    
                    document.querySelectorAll('input[name="dias_semana[]"]').forEach(function(cb) {
                        cb.checked = false;
                    });
                    
                    if (t.dias_semana) {
                        var dias = t.dias_semana.split(',');
                        dias.forEach(function(dia) {
                            var cb = document.getElementById('dia_' + dia);
                            if (cb) cb.checked = true;
                        });
                    }
                    
                    calcularDuracaoTotal();
                    document.getElementById('modalTurno').style.display = 'block';
                } else {
                    showModalErro(data.message || 'Turno não encontrado');
                }
            },
            error: function(xhr, status, error) {
                fecharModalInfo();
                showModalErro('Erro ao carregar dados do turno: ' + error);
            }
        });
    }
    
    function fecharModalTurno() {
        document.getElementById('modalTurno').style.display = 'none';
    }
    
    window.onclick = function(event) {
        var modalConfirmacao = document.getElementById('modalConfirmacao');
        var modalInfo = document.getElementById('modalInfo');
        var modalErro = document.getElementById('modalErro');
        var modalTurno = document.getElementById('modalTurno');
        
        if (event.target == modalConfirmacao) fecharModalConfirmacao();
        if (event.target == modalInfo) fecharModalInfo();
        if (event.target == modalErro) fecharModalErro();
        if (event.target == modalTurno) fecharModalTurno();
    }
</script>
</body>
</html>