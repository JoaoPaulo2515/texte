<?php
// escola/pedagogico/marcar_presenca.php - Marcar Presença dos Alunos

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

// Buscar turmas da escola
if ($funcionario['usuario_tipo'] == 'professor') {
    $sql_turmas = "
        SELECT DISTINCT
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        LEFT JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
        WHERE t.escola_id = :escola_id 
        AND t.status = 'ativa'
        AND pdt.professor_id = :professor_id
        GROUP BY t.id
        ORDER BY t.ano ASC, t.nome ASC
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([
        ':escola_id' => $escola_id,
        ':professor_id' => $professor_id
    ]);
} else {
    $sql_turmas = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.escola_id = :escola_id AND t.status = 'ativa'
        ORDER BY t.ano ASC, t.nome ASC
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':escola_id' => $escola_id]);
}
$turmas_lista = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Obter parâmetros de filtro
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$data_aula = isset($_GET['data_aula']) ? $_GET['data_aula'] : date('Y-m-d');

// Se não tem turma selecionada e tem turmas disponíveis, pegar a primeira
if ($turma_id == 0 && !empty($turmas_lista)) {
    $turma_id = $turmas_lista[0]['id'];
    $ano_letivo_id = $turmas_lista[0]['ano_letivo_id'];
}

// Buscar dados da turma
$turma = null;
if ($turma_id > 0) {
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':turma_id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma['ano_letivo_id'];
    }
}

// Buscar disciplinas da turma
$disciplinas = [];
if ($turma_id > 0) {
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria,
            d.cor
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar alunos da turma
$alunos = [];
if ($turma_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.telefone,
            e.email,
            m.id as matricula_id
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar presenças já registradas
$presencas_existentes = [];
if ($turma_id > 0 && $disciplina_id > 0 && $ano_letivo_id > 0 && !empty($data_aula)) {
    $sql_presencas = "
        SELECT 
            id,
            estudante_id,
            status,
            minutos_atraso,
            justificativa,
            observacao
        FROM chamada
        WHERE turma_id = :turma_id 
        AND disciplina_id = :disciplina_id
        AND ano_letivo_id = :ano_letivo_id
        AND data_aula = :data_aula
    ";
    $stmt_presencas = $conn->prepare($sql_presencas);
    $stmt_presencas->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id,
        ':data_aula' => $data_aula
    ]);
    $presencas_existentes = $stmt_presencas->fetchAll(PDO::FETCH_ASSOC);
    
    // Criar array associativo para fácil acesso
    $presencas_por_aluno = [];
    foreach ($presencas_existentes as $presenca) {
        $presencas_por_aluno[$presenca['estudante_id']] = $presenca;
    }
}

// Processar marcação de presença via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_ajax') {
    header('Content-Type: application/json');
    
    $estudante_id = (int)$_POST['estudante_id'];
    $status = $_POST['status'];
    $minutos_atraso = isset($_POST['minutos_atraso']) ? (int)$_POST['minutos_atraso'] : 0;
    $justificativa = trim($_POST['justificativa'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $ano_letivo_id_post = (int)$_POST['ano_letivo_id'];
    $data_aula_post = $_POST['data_aula'];
    $bimestre_post = (int)$_POST['bimestre'];
    $horario_inicio = $_POST['horario_inicio'] ?? null;
    $horario_fim = $_POST['horario_fim'] ?? null;
    
    try {
        // Verificar se já existe registro
        $sql_check = "
            SELECT id FROM chamada 
            WHERE estudante_id = :estudante_id 
            AND turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            AND data_aula = :data_aula
            AND ano_letivo_id = :ano_letivo_id
        ";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':estudante_id' => $estudante_id,
            ':turma_id' => $turma_id_post,
            ':disciplina_id' => $disciplina_id_post,
            ':data_aula' => $data_aula_post,
            ':ano_letivo_id' => $ano_letivo_id_post
        ]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // UPDATE
            $sql = "
                UPDATE chamada 
                SET status = :status,
                    minutos_atraso = :minutos_atraso,
                    justificativa = :justificativa,
                    observacao = :observacao,
                    horario_inicio = :horario_inicio,
                    horario_fim = :horario_fim,
                    updated_at = NOW()
                WHERE id = :id
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':minutos_atraso' => $minutos_atraso,
                ':justificativa' => $justificativa,
                ':observacao' => $observacao,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':id' => $existing['id']
            ]);
        } else {
            // INSERT
            $sql = "
                INSERT INTO chamada (
                    escola_id, ano_letivo_id, turma_id, disciplina_id,
                    professor_id, estudante_id, data_aula, horario_inicio, horario_fim,
                    status, minutos_atraso, justificativa, observacao,
                    bimestre, lancado_por, data_lancamento, created_at
                ) VALUES (
                    :escola_id, :ano_letivo_id, :turma_id, :disciplina_id,
                    :professor_id, :estudante_id, :data_aula, :horario_inicio, :horario_fim,
                    :status, :minutos_atraso, :justificativa, :observacao,
                    :bimestre, :lancado_por, NOW(), NOW()
                )
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':ano_letivo_id' => $ano_letivo_id_post,
                ':turma_id' => $turma_id_post,
                ':disciplina_id' => $disciplina_id_post,
                ':professor_id' => $professor_id ?: null,
                ':estudante_id' => $estudante_id,
                ':data_aula' => $data_aula_post,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':status' => $status,
                ':minutos_atraso' => $minutos_atraso,
                ':justificativa' => $justificativa,
                ':observacao' => $observacao,
                ':bimestre' => $bimestre_post,
                ':lancado_por' => $usuario['id']
            ]);
        }
        
        // Buscar estatísticas atualizadas - CORRIGIDO
        $stats_sql = "
            SELECT 
                COUNT(CASE WHEN status = 'presente' THEN 1 END) as presentes,
                COUNT(CASE WHEN status = 'falta' THEN 1 END) as faltas,
                COUNT(CASE WHEN status = 'atrasado' THEN 1 END) as atrasos
            FROM chamada
            WHERE turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            AND ano_letivo_id = :ano_letivo_id
            AND data_aula = :data_aula
        ";
        $stmt_stats = $conn->prepare($stats_sql);
        $stmt_stats->execute([
            ':turma_id' => $turma_id_post,
            ':disciplina_id' => $disciplina_id_post,
            ':ano_letivo_id' => $ano_letivo_id_post,
            ':data_aula' => $data_aula_post
        ]);
        $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
        
        $total_alunos_count = count($alunos);
        $presentes = (int)($stats['presentes'] ?? 0);
        $faltas = (int)($stats['faltas'] ?? 0);
        $atrasos = (int)($stats['atrasos'] ?? 0);
        $nao_registrados = $total_alunos_count - ($presentes + $faltas + $atrasos);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Presença salva com sucesso!',
            'stats' => [
                'presentes' => $presentes,
                'faltas' => $faltas,
                'atrasos' => $atrasos,
                'nao_registrados' => $nao_registrados,
                'total_alunos' => $total_alunos_count
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
    }
    exit;
}

// Calcular estatísticas
$total_alunos = count($alunos);
$total_presentes = 0;
$total_faltas = 0;
$total_atrasos = 0;

foreach ($presencas_existentes as $presenca) {
    if ($presenca['status'] == 'presente') {
        $total_presentes++;
    } elseif ($presenca['status'] == 'falta') {
        $total_faltas++;
    } elseif ($presenca['status'] == 'atrasado') {
        $total_atrasos++;
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marcar Presença - SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header-title p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }
        
        .filtros-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filtros-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .filtros-body {
            padding: 20px;
        }
        
        .filtros-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select, .filtro-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .info-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .info-grid {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        
        .info-item {
            text-align: center;
            flex: 1;
            min-width: 120px;
        }
        
        .info-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: bold;
            color: #1e5799;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        .stat-card.red { border-bottom: 4px solid #e74c3c; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.blue { border-bottom: 4px solid #1e5799; }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .horario-row {
            background: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .horario-group {
            flex: 1;
            min-width: 150px;
        }
        
        .horario-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .horario-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 20px;
            font-weight: bold;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
        }
        
        .table-presenca {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-presenca thead tr {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }
        
        .table-presenca th {
            padding: 15px 12px;
            text-align: center;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-presenca td {
            padding: 15px 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
            transition: background 0.3s ease;
        }
        
        .table-presenca tr:hover td {
            background: #f8f9fa;
        }
        
        .aluno-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .aluno-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .aluno-details {
            flex: 1;
        }
        
        .aluno-nome {
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .aluno-matricula {
            font-size: 11px;
            color: #7f8c8d;
        }
        
        .radio-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .radio-option {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 30px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .radio-option:hover {
            transform: scale(1.02);
        }
        
        .radio-option input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            margin: 0;
            accent-color: #1e5799;
        }
        
        .radio-option label {
            cursor: pointer;
            margin: 0;
            font-size: 12px;
            font-weight: 600;
        }
        
        .radio-presente { background: #d5f4e6; border: 1px solid #27ae60; }
        .radio-presente label { color: #27ae60; }
        
        .radio-falta { background: #fadbd8; border: 1px solid #e74c3c; }
        .radio-falta label { color: #c0392b; }
        
        .radio-atrasado { background: #fef9e7; border: 1px solid #f39c12; }
        .radio-atrasado label { color: #f39c12; }
        
        .atraso-input {
            width: 70px;
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .atraso-input:focus {
            outline: none;
            border-color: #f39c12;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }
        
        .justificativa-input, .observacao-input {
            width: 100%;
            min-width: 180px;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .justificativa-input:focus, .observacao-input:focus {
            outline: none;
            border-color: #1e5799;
            box-shadow: 0 0 0 3px rgba(30, 87, 153, 0.1);
        }
        
        .loading-icon {
            display: none;
            width: 24px;
            height: 24px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #27ae60;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        .saved-icon {
            display: none;
            color: #27ae60;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            display: none;
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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .info-grid { flex-direction: column; }
            .horario-row { flex-direction: column; }
            .table-presenca th, .table-presenca td { padding: 10px 8px; }
            .aluno-avatar { width: 32px; height: 32px; font-size: 12px; }
            .radio-group { flex-direction: column; align-items: center; }
            .radio-option { width: 100%; justify-content: center; }
            .atraso-input { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📝 Marcar Presença</h1>
            <p>Registre a presença dos alunos por disciplina e turma</p>
        </div>
        <div>
            <a href="lista_chamada.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar
        </div>
        <div class="filtros-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select" required>
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas_lista as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['ano']; ?>ª - <?php echo ucfirst($t['turno_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select" required>
                            <option value="">Selecione a disciplina</option>
                            <?php foreach ($disciplinas as $disc): ?>
                                <option value="<?php echo $disc['id']; ?>" <?php echo ($disciplina_id == $disc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($disc['nome']); ?> (<?php echo htmlspecialchars($disc['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Data da Aula</label>
                        <input type="date" name="data_aula" class="filtro-input" value="<?php echo $data_aula; ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="1" <?php echo ($bimestre == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                            <option value="4" <?php echo ($bimestre == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select">
                            <option value="">Todos os anos</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma && $disciplina_id > 0): ?>
        <!-- Informações da Turma -->
        <div class="info-bar">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">📚 Turma</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📖 Disciplina</div>
                    <div class="info-value">
                        <?php 
                        $disciplina_nome = '';
                        foreach ($disciplinas as $d) {
                            if ($d['id'] == $disciplina_id) {
                                $disciplina_nome = $d['nome'];
                                break;
                            }
                        }
                        echo htmlspecialchars($disciplina_nome);
                        ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Data</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($data_aula)); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Bimestre</div>
                    <div class="info-value"><?php echo $bimestre; ?>º Bimestre</div>
                </div>
                <div class="info-item">
                    <div class="info-label">👨‍🎓 Alunos</div>
                    <div class="info-value" id="total_alunos_count"><?php echo $total_alunos; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-number" id="total_presentes"><?php echo $total_presentes; ?></div>
                <div class="stat-label">✅ Presentes</div>
            </div>
            <div class="stat-card red">
                <div class="stat-number" id="total_faltas"><?php echo $total_faltas; ?></div>
                <div class="stat-label">❌ Faltas</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-number" id="total_atrasos"><?php echo $total_atrasos; ?></div>
                <div class="stat-label">⏰ Atrasos</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-number" id="total_nao_registrados"><?php echo $total_alunos - ($total_presentes + $total_faltas + $total_atrasos); ?></div>
                <div class="stat-label">📝 Pendentes</div>
            </div>
        </div>
        
        <!-- Horário da Aula -->
        <div class="horario-row">
            <div class="horario-group">
                <label>🕐 Horário de Início</label>
                <input type="time" id="horario_inicio" class="horario-input" value="<?php echo date('H:i'); ?>">
            </div>
            <div class="horario-group">
                <label>🕒 Horário de Término</label>
                <input type="time" id="horario_fim" class="horario-input" value="<?php echo date('H:i', strtotime('+45 minutes')); ?>">
            </div>
        </div>
        
        <!-- Tabela de Presença -->
        <div class="card">
            <div class="card-header">
                <span><i class="fas fa-users"></i> Lista de Alunos</span>
                <span class="badge-count"><?php echo $total_alunos; ?> alunos</span>
            </div>
            <div class="card-body">
                <table class="table-presenca">
                    <thead>
                        <tr>
                            <th>Aluno</th>
                            <th width="28%">Status</th>
                            <th width="8%">Atraso</th>
                            <th width="15%">Justificativa</th>
                            <th width="15%">Observação</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos as $aluno): 
                            $presenca = isset($presencas_por_aluno[$aluno['id']]) ? $presencas_por_aluno[$aluno['id']] : null;
                            $status = $presenca ? $presenca['status'] : '';
                            $minutos_atraso = $presenca ? $presenca['minutos_atraso'] : '';
                            $justificativa = $presenca ? $presenca['justificativa'] : '';
                            $observacao = $presenca ? $presenca['observacao'] : '';
                            $inicial = strtoupper(substr($aluno['nome'], 0, 1));
                        ?>
                            <tr>
                                <td>
                                    <div class="aluno-info">
                                        <div class="aluno-avatar"><?php echo $inicial; ?></div>
                                        <div class="aluno-details">
                                            <div class="aluno-nome"><?php echo htmlspecialchars($aluno['nome']); ?></div>
                                            <div class="aluno-matricula">📋 Mat: <?php echo htmlspecialchars($aluno['matricula']); ?></div>
                                        </div>
                                    </div>
                                 </div>
                                <td>
                                    <div class="radio-group">
                                        <label class="radio-option radio-presente">
                                            <input type="radio" name="status_<?php echo $aluno['id']; ?>" value="presente" 
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   <?php echo ($status == 'presente') ? 'checked' : ''; ?>>
                                            <span>✅ Presente</span>
                                        </label>
                                        <label class="radio-option radio-falta">
                                            <input type="radio" name="status_<?php echo $aluno['id']; ?>" value="falta" 
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   <?php echo ($status == 'falta') ? 'checked' : ''; ?>>
                                            <span>❌ Falta</span>
                                        </label>
                                        <label class="radio-option radio-atrasado">
                                            <input type="radio" name="status_<?php echo $aluno['id']; ?>" value="atrasado" 
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   <?php echo ($status == 'atrasado') ? 'checked' : ''; ?>>
                                            <span>⏰ Atrasado</span>
                                        </label>
                                    </div>
                                </div>
                                <td>
                                    <input type="number" id="atraso_<?php echo $aluno['id']; ?>" 
                                           class="atraso-input" value="<?php echo $minutos_atraso; ?>" 
                                           placeholder="min" min="0" max="180" 
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           <?php echo ($status != 'atrasado') ? 'disabled' : ''; ?>>
                                </div>
                                <td>
                                    <input type="text" id="justificativa_<?php echo $aluno['id']; ?>" 
                                           class="justificativa-input" value="<?php echo htmlspecialchars($justificativa); ?>" 
                                           placeholder="Justificativa..." data-aluno="<?php echo $aluno['id']; ?>">
                                </div>
                                <td>
                                    <input type="text" id="observacao_<?php echo $aluno['id']; ?>" 
                                           class="observacao-input" value="<?php echo htmlspecialchars($observacao); ?>" 
                                           placeholder="Observação..." data-aluno="<?php echo $aluno['id']; ?>">
                                </div>
                                <td>
                                    <div class="loading-icon" id="loading_<?php echo $aluno['id']; ?>"></div>
                                    <div class="saved-icon" id="saved_<?php echo $aluno['id']; ?>">✓</div>
                                </div>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php elseif ($turma_id > 0 && !$disciplina_id): ?>
        <div class="alert alert-info">
            ℹ️ Selecione uma disciplina para marcar presença.
        </div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">
            ℹ️ Nenhum aluno matriculado nesta turma.
        </div>
    <?php elseif (empty($turmas_lista)): ?>
        <div class="alert alert-info">
            ℹ️ Nenhuma turma disponível.
        </div>
    <?php endif; ?>
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
    
    async function salvarPresenca(alunoId) {
        const statusRadio = document.querySelector(`input[name="status_${alunoId}"]:checked`);
        if (!statusRadio) return;
        
        const status = statusRadio.value;
        const minutosAtraso = document.getElementById(`atraso_${alunoId}`).value;
        const justificativa = document.getElementById(`justificativa_${alunoId}`).value;
        const observacao = document.getElementById(`observacao_${alunoId}`).value;
        const horarioInicio = document.getElementById('horario_inicio')?.value || '';
        const horarioFim = document.getElementById('horario_fim')?.value || '';
        
        const loadingIcon = document.getElementById(`loading_${alunoId}`);
        const savedIcon = document.getElementById(`saved_${alunoId}`);
        
        loadingIcon.style.display = 'inline-block';
        savedIcon.style.display = 'none';
        
        const formData = new FormData();
        formData.append('action', 'salvar_ajax');
        formData.append('estudante_id', alunoId);
        formData.append('status', status);
        formData.append('minutos_atraso', minutosAtraso || 0);
        formData.append('justificativa', justificativa);
        formData.append('observacao', observacao);
        formData.append('turma_id', <?php echo $turma_id; ?>);
        formData.append('disciplina_id', <?php echo $disciplina_id; ?>);
        formData.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
        formData.append('data_aula', '<?php echo $data_aula; ?>');
        formData.append('bimestre', <?php echo $bimestre; ?>);
        formData.append('horario_inicio', horarioInicio);
        formData.append('horario_fim', horarioFim);
        
        try {
            const response = await fetch('marcar_presenca.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                savedIcon.style.display = 'block';
                setTimeout(() => {
                    savedIcon.style.display = 'none';
                }, 1500);
                
                // Atualizar estatísticas
                if (result.stats) {
                    document.getElementById('total_presentes').textContent = result.stats.presentes;
                    document.getElementById('total_faltas').textContent = result.stats.faltas;
                    document.getElementById('total_atrasos').textContent = result.stats.atrasos;
                    document.getElementById('total_nao_registrados').textContent = result.stats.nao_registrados;
                }
                
                showToast('✅ Presença salva com sucesso!');
            } else {
                showToast('❌ Erro: ' + result.message, true);
            }
        } catch (error) {
            console.error('Erro:', error);
            showToast('❌ Erro ao salvar presença', true);
        } finally {
            loadingIcon.style.display = 'none';
        }
    }
    
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const alunoId = this.getAttribute('data-aluno');
            const atrasoInput = document.getElementById(`atraso_${alunoId}`);
            
            if (this.value === 'atrasado') {
                atrasoInput.disabled = false;
            } else {
                atrasoInput.disabled = true;
                atrasoInput.value = '';
            }
            
            salvarPresenca(alunoId);
        });
    });
    
    document.querySelectorAll('.atraso-input, .justificativa-input, .observacao-input').forEach(input => {
        let timeout;
        input.addEventListener('input', function() {
            const alunoId = this.getAttribute('data-aluno');
            clearTimeout(timeout);
            timeout = setTimeout(() => salvarPresenca(alunoId), 800);
        });
    });
    
    document.getElementById('horario_inicio')?.addEventListener('change', () => {
        document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
            const alunoId = radio.getAttribute('data-aluno');
            salvarPresenca(alunoId);
        });
    });
    
    document.getElementById('horario_fim')?.addEventListener('change', () => {
        document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
            const alunoId = radio.getAttribute('data-aluno');
            salvarPresenca(alunoId);
        });
    });
</script>
</body>
</html>