<?php
// escola/pedagogico/parametros_avaliacao.php - Parâmetros de Avaliação (COM MODAIS)

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
// VERIFICAR E CRIAR TABELA SE NÃO EXISTIR
// ============================================
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `parametros_avaliacao` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `escola_id` INT NOT NULL,
    `tipo_ensino` VARCHAR(50) NOT NULL COMMENT 'fundamental, medio',
    `classe_inicio` INT NOT NULL,
    `classe_fim` INT NOT NULL,
    `escala_maxima` INT NOT NULL DEFAULT 20,
    `nota_minima_aprovacao` DECIMAL(5,2) NOT NULL DEFAULT 10,
    `nota_minima_recuperacao` DECIMAL(5,2) NOT NULL DEFAULT 7,
    `percentual_mac` INT NOT NULL DEFAULT 50,
    `percentual_npt` INT NOT NULL DEFAULT 50,
    `percentual_exame_normal` INT DEFAULT NULL,
    `percentual_exame_recurso` INT DEFAULT NULL,
    `percentual_exame_especial` INT DEFAULT NULL,
    `percentual_exame_oral` INT DEFAULT NULL,
    `percentual_exame_escrito` INT DEFAULT NULL,
    `bimestres_por_ano` INT NOT NULL DEFAULT 3,
    `media_anual_aprovacao` DECIMAL(5,2) DEFAULT NULL,
    `permite_exame_recurso` TINYINT DEFAULT 1,
    `permite_exame_especial` TINYINT DEFAULT 1,
    `permite_exame_oral` TINYINT DEFAULT 1,
    `permite_exame_escrito` TINYINT DEFAULT 1,
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

$mensagem = '';
$erro = '';

function getPostValue($key, $default = null) {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

// Inserir novo parâmetro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inserir') {
    $tipo_ensino = getPostValue('tipo_ensino', 'fundamental');
    $classe_inicio = (int)getPostValue('classe_inicio', 1);
    $classe_fim = (int)getPostValue('classe_fim', 6);
    $escala_maxima = (int)getPostValue('escala_maxima', 20);
    $nota_minima_aprovacao = (float)str_replace(',', '.', getPostValue('nota_minima_aprovacao', 10));
    $nota_minima_recuperacao = (float)str_replace(',', '.', getPostValue('nota_minima_recuperacao', 7));
    $percentual_mac = (int)getPostValue('percentual_mac', 50);
    $percentual_npt = (int)getPostValue('percentual_npt', 50);
    
    $percentual_exame_normal = getPostValue('percentual_exame_normal');
    $percentual_exame_normal = !empty($percentual_exame_normal) ? (int)$percentual_exame_normal : null;
    
    $percentual_exame_recurso = getPostValue('percentual_exame_recurso');
    $percentual_exame_recurso = !empty($percentual_exame_recurso) ? (int)$percentual_exame_recurso : null;
    
    $percentual_exame_especial = getPostValue('percentual_exame_especial');
    $percentual_exame_especial = !empty($percentual_exame_especial) ? (int)$percentual_exame_especial : null;
    
    $percentual_exame_oral = getPostValue('percentual_exame_oral');
    $percentual_exame_oral = !empty($percentual_exame_oral) ? (int)$percentual_exame_oral : null;
    
    $percentual_exame_escrito = getPostValue('percentual_exame_escrito');
    $percentual_exame_escrito = !empty($percentual_exame_escrito) ? (int)$percentual_exame_escrito : null;
    
    $bimestres_por_ano = (int)getPostValue('bimestres_por_ano', 3);
    
    $media_anual_aprovacao = getPostValue('media_anual_aprovacao');
    $media_anual_aprovacao = !empty($media_anual_aprovacao) ? (float)str_replace(',', '.', $media_anual_aprovacao) : null;
    
    $permite_exame_recurso = isset($_POST['permite_exame_recurso']) ? 1 : 0;
    $permite_exame_especial = isset($_POST['permite_exame_especial']) ? 1 : 0;
    $permite_exame_oral = isset($_POST['permite_exame_oral']) ? 1 : 0;
    $permite_exame_escrito = isset($_POST['permite_exame_escrito']) ? 1 : 0;
    
    $sql = "INSERT INTO parametros_avaliacao (escola_id, tipo_ensino, classe_inicio, classe_fim, escala_maxima, nota_minima_aprovacao, nota_minima_recuperacao, percentual_mac, percentual_npt, percentual_exame_normal, percentual_exame_recurso, percentual_exame_especial, percentual_exame_oral, percentual_exame_escrito, bimestres_por_ano, media_anual_aprovacao, permite_exame_recurso, permite_exame_especial, permite_exame_oral, permite_exame_escrito) 
            VALUES (:escola_id, :tipo_ensino, :classe_inicio, :classe_fim, :escala_maxima, :nota_minima_aprovacao, :nota_minima_recuperacao, :percentual_mac, :percentual_npt, :percentual_exame_normal, :percentual_exame_recurso, :percentual_exame_especial, :percentual_exame_oral, :percentual_exame_escrito, :bimestres_por_ano, :media_anual_aprovacao, :permite_exame_recurso, :permite_exame_especial, :permite_exame_oral, :permite_exame_escrito)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':tipo_ensino' => $tipo_ensino,
        ':classe_inicio' => $classe_inicio,
        ':classe_fim' => $classe_fim,
        ':escala_maxima' => $escala_maxima,
        ':nota_minima_aprovacao' => $nota_minima_aprovacao,
        ':nota_minima_recuperacao' => $nota_minima_recuperacao,
        ':percentual_mac' => $percentual_mac,
        ':percentual_npt' => $percentual_npt,
        ':percentual_exame_normal' => $percentual_exame_normal,
        ':percentual_exame_recurso' => $percentual_exame_recurso,
        ':percentual_exame_especial' => $percentual_exame_especial,
        ':percentual_exame_oral' => $percentual_exame_oral,
        ':percentual_exame_escrito' => $percentual_exame_escrito,
        ':bimestres_por_ano' => $bimestres_por_ano,
        ':media_anual_aprovacao' => $media_anual_aprovacao,
        ':permite_exame_recurso' => $permite_exame_recurso,
        ':permite_exame_especial' => $permite_exame_especial,
        ':permite_exame_oral' => $permite_exame_oral,
        ':permite_exame_escrito' => $permite_exame_escrito
    ]);
    
    $mensagem = "Parâmetros de avaliação cadastrados com sucesso!";
}

// Atualizar parâmetro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar') {
    $id = (int)$_POST['id'];
    $tipo_ensino = getPostValue('tipo_ensino', 'fundamental');
    $classe_inicio = (int)getPostValue('classe_inicio', 1);
    $classe_fim = (int)getPostValue('classe_fim', 6);
    $escala_maxima = (int)getPostValue('escala_maxima', 20);
    $nota_minima_aprovacao = (float)str_replace(',', '.', getPostValue('nota_minima_aprovacao', 10));
    $nota_minima_recuperacao = (float)str_replace(',', '.', getPostValue('nota_minima_recuperacao', 7));
    $percentual_mac = (int)getPostValue('percentual_mac', 50);
    $percentual_npt = (int)getPostValue('percentual_npt', 50);
    
    $percentual_exame_normal = getPostValue('percentual_exame_normal');
    $percentual_exame_normal = !empty($percentual_exame_normal) ? (int)$percentual_exame_normal : null;
    
    $percentual_exame_recurso = getPostValue('percentual_exame_recurso');
    $percentual_exame_recurso = !empty($percentual_exame_recurso) ? (int)$percentual_exame_recurso : null;
    
    $percentual_exame_especial = getPostValue('percentual_exame_especial');
    $percentual_exame_especial = !empty($percentual_exame_especial) ? (int)$percentual_exame_especial : null;
    
    $percentual_exame_oral = getPostValue('percentual_exame_oral');
    $percentual_exame_oral = !empty($percentual_exame_oral) ? (int)$percentual_exame_oral : null;
    
    $percentual_exame_escrito = getPostValue('percentual_exame_escrito');
    $percentual_exame_escrito = !empty($percentual_exame_escrito) ? (int)$percentual_exame_escrito : null;
    
    $bimestres_por_ano = (int)getPostValue('bimestres_por_ano', 3);
    
    $media_anual_aprovacao = getPostValue('media_anual_aprovacao');
    $media_anual_aprovacao = !empty($media_anual_aprovacao) ? (float)str_replace(',', '.', $media_anual_aprovacao) : null;
    
    $permite_exame_recurso = isset($_POST['permite_exame_recurso']) ? 1 : 0;
    $permite_exame_especial = isset($_POST['permite_exame_especial']) ? 1 : 0;
    $permite_exame_oral = isset($_POST['permite_exame_oral']) ? 1 : 0;
    $permite_exame_escrito = isset($_POST['permite_exame_escrito']) ? 1 : 0;
    
    $sql = "UPDATE parametros_avaliacao SET 
            tipo_ensino = :tipo_ensino,
            classe_inicio = :classe_inicio,
            classe_fim = :classe_fim,
            escala_maxima = :escala_maxima,
            nota_minima_aprovacao = :nota_minima_aprovacao,
            nota_minima_recuperacao = :nota_minima_recuperacao,
            percentual_mac = :percentual_mac,
            percentual_npt = :percentual_npt,
            percentual_exame_normal = :percentual_exame_normal,
            percentual_exame_recurso = :percentual_exame_recurso,
            percentual_exame_especial = :percentual_exame_especial,
            percentual_exame_oral = :percentual_exame_oral,
            percentual_exame_escrito = :percentual_exame_escrito,
            bimestres_por_ano = :bimestres_por_ano,
            media_anual_aprovacao = :media_anual_aprovacao,
            permite_exame_recurso = :permite_exame_recurso,
            permite_exame_especial = :permite_exame_especial,
            permite_exame_oral = :permite_exame_oral,
            permite_exame_escrito = :permite_exame_escrito,
            updated_at = NOW()
            WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':tipo_ensino' => $tipo_ensino,
        ':classe_inicio' => $classe_inicio,
        ':classe_fim' => $classe_fim,
        ':escala_maxima' => $escala_maxima,
        ':nota_minima_aprovacao' => $nota_minima_aprovacao,
        ':nota_minima_recuperacao' => $nota_minima_recuperacao,
        ':percentual_mac' => $percentual_mac,
        ':percentual_npt' => $percentual_npt,
        ':percentual_exame_normal' => $percentual_exame_normal,
        ':percentual_exame_recurso' => $percentual_exame_recurso,
        ':percentual_exame_especial' => $percentual_exame_especial,
        ':percentual_exame_oral' => $percentual_exame_oral,
        ':percentual_exame_escrito' => $percentual_exame_escrito,
        ':bimestres_por_ano' => $bimestres_por_ano,
        ':media_anual_aprovacao' => $media_anual_aprovacao,
        ':permite_exame_recurso' => $permite_exame_recurso,
        ':permite_exame_especial' => $permite_exame_especial,
        ':permite_exame_oral' => $permite_exame_oral,
        ':permite_exame_escrito' => $permite_exame_escrito,
        ':id' => $id,
        ':escola_id' => $escola_id
    ]);
    
    $mensagem = "Parâmetros de avaliação atualizados com sucesso!";
}

// Excluir parâmetro
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "DELETE FROM parametros_avaliacao WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $mensagem = "Parâmetros de avaliação excluídos com sucesso!";
}

// Buscar parâmetro via AJAX para edição
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $sql = "SELECT * FROM parametros_avaliacao WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $param = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($param) {
        echo json_encode(['success' => true, 'param' => $param]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Parâmetros não encontrados']);
    }
    exit;
}

// ============================================
// BUSCAR PARÂMETROS
// ============================================
$sql_params = "SELECT * FROM parametros_avaliacao WHERE escola_id = :escola_id ORDER BY tipo_ensino ASC, classe_inicio ASC";
$stmt_params = $conn->prepare($sql_params);
$stmt_params->execute([':escola_id' => $escola_id]);
$parametros = $stmt_params->fetchAll(PDO::FETCH_ASSOC);

$total_parametros = count($parametros);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parâmetros de Avaliação - SIGE Angola</title>
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
        
        .table-params { width: 100%; border-collapse: collapse; }
        .table-params th {
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
        .table-params td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        .table-params tr:hover { background: #f8f9fa; }
        
        .badge-tipo {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-fundamental { background: #d4edda; color: #155724; }
        .badge-medio { background: #d1ecf1; color: #0c5460; }
        
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
            margin: 2% auto;
            width: 90%;
            max-width: 800px;
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
        
        .row-cols-custom {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .col-custom {
            flex: 1;
            min-width: 200px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-params { font-size: 11px; }
            .table-params th, .table-params td { padding: 8px; }
            .modal-custom-content { width: 95%; margin: 5% auto; max-height: 85vh; }
            .row-cols-custom { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-sliders-h"></i> Parâmetros de Avaliação</h1>
            <p>Configuração dos critérios e regras de avaliação por nível de ensino</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo $total_parametros; ?></div>
            <div class="stat-label">Configurações</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php 
                $fundamental = 0;
                foreach ($parametros as $p) {
                    if ($p['tipo_ensino'] == 'fundamental') $fundamental++;
                }
                echo $fundamental;
            ?></div>
            <div class="stat-label">Ensino Fundamental</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php 
                $medio = 0;
                foreach ($parametros as $p) {
                    if ($p['tipo_ensino'] == 'medio') $medio++;
                }
                echo $medio;
            ?></div>
            <div class="stat-label">Ensino Médio</div>
        </div>
    </div>
    
    <!-- Lista de Parâmetros -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Parâmetros de Avaliação
            <span class="badge bg-light text-dark ms-2"><?php echo $total_parametros; ?> registros</span>
            <button class="btn-novo float-end" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Nova Configuração</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($parametros)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-sliders-h fa-3x mb-3"></i>
                    <p>Nenhum parâmetro de avaliação cadastrado.</p>
                    <button class="btn-novo" onclick="abrirModalNovo()"><i class="fas fa-plus"></i> Criar primeira configuração</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-params">
                        <thead>
                            <tr>
                                <th>Tipo Ensino</th>
                                <th>Classes</th>
                                <th>Escala</th>
                                <th>Nota Mínima</th>
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Exames</th>
                                <th>Bimestres</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parametros as $param): ?>
                                <tr>
                                    <td><span class="badge-tipo <?php echo $param['tipo_ensino'] == 'fundamental' ? 'badge-fundamental' : 'badge-medio'; ?>">
                                        <?php echo $param['tipo_ensino'] == 'fundamental' ? 'Ensino Fundamental' : 'Ensino Médio'; ?>
                                    </span></td>
                                    <td><?php echo $param['classe_inicio']; ?>ª - <?php echo $param['classe_fim']; ?>ª Classe</span></td>
                                    <td>0-<?php echo $param['escala_maxima']; ?><br><small>Aprovação: <?php echo $param['nota_minima_aprovacao']; ?></small></td>
                                    <td>Aprovação: <?php echo $param['nota_minima_aprovacao']; ?><br>
                                        <small>Recuperação: <?php echo $param['nota_minima_recuperacao']; ?></small></td>                                    <td><?php echo $param['percentual_mac']; ?>%</span></td>
                                    <td><?php echo $param['percentual_npt']; ?>%</span></td>
                                    <td>
                                        <?php 
                                        $exames = [];
                                        if ($param['permite_exame_recurso']) $exames[] = 'Recurso';
                                        if ($param['permite_exame_especial']) $exames[] = 'Especial';
                                        if ($param['permite_exame_oral']) $exames[] = 'Oral';
                                        if ($param['permite_exame_escrito']) $exames[] = 'Escrito';
                                        echo implode(', ', $exames) ?: 'Nenhum';
                                        ?>
                                    </td>
                                    <td><?php echo $param['bimestres_por_ano']; ?> bimestres</span></td>
                                    <td>
                                        <button class="btn-acao btn-editar" onclick="editarParametro(<?php echo $param['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" onclick="confirmarExclusao(<?php echo $param['id']; ?>)">
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
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModalErro()">OK</button>
        </div>
    </div>
</div>

<!-- Modal Novo/Editar Parâmetro -->
<div id="modalParametro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-info">
            <h3 id="modalTitulo"><i class="fas fa-plus"></i> Novo Parâmetro de Avaliação</h3>
            <span class="close-modal" onclick="fecharModalParametro()">&times;</span>
        </div>
        <div class="modal-custom-body">
            <form method="POST" action="" id="formParametro">
                <input type="hidden" name="action" id="formAction" value="inserir">
                <input type="hidden" name="id" id="param_id" value="0">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Tipo de Ensino *</label>
                            <select name="tipo_ensino" class="form-select" required>
                                <option value="fundamental">Ensino Fundamental (1ª à 6ª Classe)</option>
                                <option value="medio">Ensino Médio (7ª à 13ª Classe)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Classe Início *</label>
                            <select name="classe_inicio" class="form-select" required>
                                <?php for ($i = 1; $i <= 13; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?>ª Classe</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">Classe Fim *</label>
                            <select name="classe_fim" class="form-select" required>
                                <?php for ($i = 1; $i <= 13; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?>ª Classe</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Escala Máxima *</label>
                            <select name="escala_maxima" class="form-select" required>
                                <option value="10">0 a 10 pontos</option>
                                <option value="20" selected>0 a 20 pontos</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nota Mínima Aprovação *</label>
                            <input type="number" step="0.5" name="nota_minima_aprovacao" class="form-control" required value="10">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nota Mínima Recuperação *</label>
                            <input type="number" step="0.5" name="nota_minima_recuperacao" class="form-control" required value="7">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Percentual MAC (%) *</label>
                            <input type="number" name="percentual_mac" class="form-control" required value="50" min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Percentual NPT (%) *</label>
                            <input type="number" name="percentual_npt" class="form-control" required value="50" min="0" max="100">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">% Exame Normal</label>
                            <input type="number" name="percentual_exame_normal" class="form-control" placeholder="Opcional" min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">% Exame Recurso</label>
                            <input type="number" name="percentual_exame_recurso" class="form-control" placeholder="Opcional" min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">% Exame Especial</label>
                            <input type="number" name="percentual_exame_especial" class="form-control" placeholder="Opcional" min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label">% Exame Oral</label>
                            <input type="number" name="percentual_exame_oral" class="form-control" placeholder="Para línguas" min="0" max="100">
                        </div>
                    </div>
                </div>
                
                <input type="hidden" name="percentual_exame_escrito" value="">
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Bimestres por Ano *</label>
                            <select name="bimestres_por_ano" class="form-select" required>
                                <option value="3">3 Bimestres</option>
                                <option value="4">4 Bimestres</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Média Anual Aprovação</label>
                            <input type="number" step="0.5" name="media_anual_aprovacao" class="form-control" placeholder="Opcional">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <label class="form-label">Exames Permitidos</label>
                        <div class="row-cols-custom">
                            <div class="col-custom">
                                <label><input type="checkbox" name="permite_exame_recurso" value="1" checked> Exame de Recurso</label>
                            </div>
                            <div class="col-custom">
                                <label><input type="checkbox" name="permite_exame_especial" value="1" checked> Exame Especial</label>
                            </div>
                            <div class="col-custom">
                                <label><input type="checkbox" name="permite_exame_oral" value="1" checked> Exame Oral</label>
                            </div>
                            <div class="col-custom">
                                <label><input type="checkbox" name="permite_exame_escrito" value="1" checked> Exame Escrito</label>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-custom-footer">
            <button type="button" class="btn-cancelar-form" onclick="fecharModalParametro()">Cancelar</button>
            <button type="submit" class="btn-salvar" onclick="document.getElementById('formParametro').submit();"><i class="fas fa-save"></i> Salvar</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    var idParaExcluir = null;
    
    // ============================================
    // MODAL DE CONFIRMAÇÃO DE EXCLUSÃO
    // ============================================
    function confirmarExclusao(id) {
        idParaExcluir = id;
        document.getElementById('mensagemConfirmacao').innerHTML = 'Tem certeza que deseja excluir estes parâmetros de avaliação?';
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
    
    // ============================================
    // MODAL DE INFORMAÇÃO
    // ============================================
    function showModalInfo(mensagem, tipo = 'success') {
        var header = document.getElementById('modalInfoHeader');
        var titulo = header.querySelector('h3');
        
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
        
        // Auto-fechar após 3 segundos para sucesso
        if (tipo === 'success') {
            setTimeout(function() {
                if (document.getElementById('modalInfo').style.display === 'block') {
                    fecharModalInfo();
                }
            }, 3000);
        }
    }
    
    function fecharModalInfo() {
        document.getElementById('modalInfo').style.display = 'none';
    }
    
    // ============================================
    // MODAL DE ERRO
    // ============================================
    function showModalErro(mensagem) {
        document.getElementById('mensagemErro').innerHTML = mensagem;
        document.getElementById('modalErro').style.display = 'block';
    }
    
    function fecharModalErro() {
        document.getElementById('modalErro').style.display = 'none';
    }
    
    // ============================================
    // FUNÇÕES DO FORMULÁRIO
    // ============================================
    
    function abrirModalNovo() {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-plus"></i> Novo Parâmetro de Avaliação';
        document.getElementById('formAction').value = 'inserir';
        document.getElementById('param_id').value = '0';
        document.getElementById('formParametro').reset();
        
        // Valores padrão
        document.querySelector('select[name="escala_maxima"]').value = '20';
        document.querySelector('input[name="nota_minima_aprovacao"]').value = '10';
        document.querySelector('input[name="nota_minima_recuperacao"]').value = '7';
        document.querySelector('input[name="percentual_mac"]').value = '50';
        document.querySelector('input[name="percentual_npt"]').value = '50';
        document.querySelector('select[name="bimestres_por_ano"]').value = '3';
        
        // Checkboxes padrão
        document.querySelector('input[name="permite_exame_recurso"]').checked = true;
        document.querySelector('input[name="permite_exame_especial"]').checked = true;
        document.querySelector('input[name="permite_exame_oral"]').checked = true;
        document.querySelector('input[name="permite_exame_escrito"]').checked = true;
        
        document.getElementById('modalParametro').style.display = 'block';
    }
    
    function editarParametro(id) {
        document.getElementById('modalTitulo').innerHTML = '<i class="fas fa-edit"></i> Editar Parâmetros de Avaliação';
        document.getElementById('formAction').value = 'atualizar';
        document.getElementById('param_id').value = id;
        
        showModalInfo('Carregando dados...', 'info');
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { ajax: 1, id: id },
            dataType: 'json',
            success: function(data) {
                fecharModalInfo();
                if (data.success) {
                    const p = data.param;
                    document.querySelector('select[name="tipo_ensino"]').value = p.tipo_ensino;
                    document.querySelector('select[name="classe_inicio"]').value = p.classe_inicio;
                    document.querySelector('select[name="classe_fim"]').value = p.classe_fim;
                    document.querySelector('select[name="escala_maxima"]').value = p.escala_maxima;
                    document.querySelector('input[name="nota_minima_aprovacao"]').value = p.nota_minima_aprovacao;
                    document.querySelector('input[name="nota_minima_recuperacao"]').value = p.nota_minima_recuperacao;
                    document.querySelector('input[name="percentual_mac"]').value = p.percentual_mac;
                    document.querySelector('input[name="percentual_npt"]').value = p.percentual_npt;
                    document.querySelector('input[name="percentual_exame_normal"]').value = p.percentual_exame_normal || '';
                    document.querySelector('input[name="percentual_exame_recurso"]').value = p.percentual_exame_recurso || '';
                    document.querySelector('input[name="percentual_exame_especial"]').value = p.percentual_exame_especial || '';
                    document.querySelector('input[name="percentual_exame_oral"]').value = p.percentual_exame_oral || '';
                    document.querySelector('select[name="bimestres_por_ano"]').value = p.bimestres_por_ano;
                    document.querySelector('input[name="media_anual_aprovacao"]').value = p.media_anual_aprovacao || '';
                    document.querySelector('input[name="permite_exame_recurso"]').checked = p.permite_exame_recurso == 1;
                    document.querySelector('input[name="permite_exame_especial"]').checked = p.permite_exame_especial == 1;
                    document.querySelector('input[name="permite_exame_oral"]').checked = p.permite_exame_oral == 1;
                    document.querySelector('input[name="permite_exame_escrito"]').checked = p.permite_exame_escrito == 1;
                    
                    document.getElementById('modalParametro').style.display = 'block';
                } else {
                    showModalErro(data.message || 'Erro ao carregar dados');
                }
            },
            error: function(xhr, status, error) {
                fecharModalInfo();
                showModalErro('Erro ao carregar dados: ' + error);
            }
        });
    }
    
    function fecharModalParametro() {
        document.getElementById('modalParametro').style.display = 'none';
    }
    
    // Fechar modais ao clicar fora
    window.onclick = function(event) {
        var modalConfirmacao = document.getElementById('modalConfirmacao');
        var modalInfo = document.getElementById('modalInfo');
        var modalErro = document.getElementById('modalErro');
        var modalParametro = document.getElementById('modalParametro');
        
        if (event.target == modalConfirmacao) fecharModalConfirmacao();
        if (event.target == modalInfo) fecharModalInfo();
        if (event.target == modalErro) fecharModalErro();
        if (event.target == modalParametro) fecharModalParametro();
    }
    
    // Mostrar mensagens de sucesso/erro via modal
    <?php if ($mensagem): ?>
    showModalInfo('<?php echo addslashes($mensagem); ?>', 'success');
    <?php endif; ?>
    
    <?php if ($erro): ?>
    showModalErro('<?php echo addslashes($erro); ?>');
    <?php endif; ?>
</script>
</body>
</html>