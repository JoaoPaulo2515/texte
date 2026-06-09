<?php
// escola/pedagogico/horario_turma.php - Horário de Aulas da Turma

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

// Buscar turmas da escola para o filtro
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
$turmas_lista = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Obter parâmetros de filtro
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

// Se não tem turma selecionada e tem turmas disponíveis, pegar a primeira
if ($turma_id == 0 && !empty($turmas_lista)) {
    $turma_id = $turmas_lista[0]['id'];
    $ano_letivo_id = $turmas_lista[0]['ano_letivo_id'];
}

// Buscar dados da turma selecionada
$turma = null;
if ($turma_id > 0) {
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            t.turno_id,
            tr.nome as turno_nome,
            t.sala_id,
            s.nome as sala_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN salas s ON s.id = t.sala_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([
        ':turma_id' => $turma_id,
        ':escola_id' => $escola_id
    ]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    // Se a turma foi encontrada, usar seu ano_letivo_id se não foi especificado
    if ($turma && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma['ano_letivo_id'];
    }
}

// Buscar anos letivos para o filtro
$sql_anos = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar horários existentes com base nos filtros
$horarios = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_horarios = "
        SELECT 
            h.*,
            d.nome as disciplina_nome,
            d.codigo as disciplina_codigo,
            p.nome as professor_nome,
            p.email as professor_email,
            s.nome as sala_nome
        FROM horarios h
        INNER JOIN disciplinas d ON d.id = h.disciplina_id
        LEFT JOIN funcionarios p ON p.id = h.professor_id
        LEFT JOIN salas s ON s.id = h.sala_id
        WHERE h.turma_id = :turma_id AND h.ano_letivo_id = :ano_letivo_id
        ORDER BY h.dia_semana, h.horario_inicio
    ";
    $stmt_horarios = $conn->prepare($sql_horarios);
    $stmt_horarios->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar disciplinas da turma (se houver turma selecionada)
$disciplinas = [];
if ($turma_id > 0) {
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar professores
$sql_professores = "
    SELECT id, nome, email, telefone
    FROM funcionarios
    WHERE escola_id = :escola_id AND status = 1
    ORDER BY nome ASC
";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':escola_id' => $escola_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar salas
$sql_salas = "
    SELECT id, nome, capacidade, tipo
    FROM salas
    WHERE escola_id = :escola_id AND status = 1
    ORDER BY nome ASC
";
$stmt_salas = $conn->prepare($sql_salas);
$stmt_salas->execute([':escola_id' => $escola_id]);
$salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

// Dias da semana
$dias_semana = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    7 => 'Domingo'
];

// Processar formulário de adicionar/editar/remover
$mensagem = '';
$erro = '';
$recarregar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $current_turma_id = (int)$_POST['current_turma_id'] ?? $turma_id;
    $current_ano_letivo_id = (int)$_POST['current_ano_letivo_id'] ?? $ano_letivo_id;
    
    if ($action === 'adicionar') {
        $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
        $disciplina_id = (int)$_POST['disciplina_id'];
        $dia_semana = (int)$_POST['dia_semana'];
        $horario_inicio = $_POST['horario_inicio'];
        $horario_fim = $_POST['horario_fim'];
        $sala_id = !empty($_POST['sala_id']) ? (int)$_POST['sala_id'] : null;
        $status = $_POST['status'] ?? 'ativo';
        
        $erros = [];
        
        if ($disciplina_id <= 0) $erros[] = "Selecione uma disciplina.";
        if ($dia_semana <= 0 || $dia_semana > 7) $erros[] = "Selecione um dia da semana válido.";
        if (empty($horario_inicio)) $erros[] = "Informe o horário de início.";
        if (empty($horario_fim)) $erros[] = "Informe o horário de término.";
        
        if (empty($erros)) {
            // Verificar conflito de horário para a mesma turma
            $sql_check = "
                SELECT id FROM horarios 
                WHERE turma_id = :turma_id 
                AND ano_letivo_id = :ano_letivo_id
                AND dia_semana = :dia_semana 
                AND (
                    (horario_inicio < :horario_fim AND horario_fim > :horario_inicio)
                )
            ";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':turma_id' => $current_turma_id,
                ':ano_letivo_id' => $current_ano_letivo_id,
                ':dia_semana' => $dia_semana,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim
            ]);
            
            if ($stmt_check->fetch()) {
                $erro = "Já existe uma aula agendada neste horário para esta turma.";
            } else {
                // Verificar conflito de horário do professor
                if ($professor_id) {
                    $sql_check_prof = "
                        SELECT id FROM horarios 
                        WHERE professor_id = :professor_id 
                        AND ano_letivo_id = :ano_letivo_id
                        AND dia_semana = :dia_semana 
                        AND (
                            (horario_inicio < :horario_fim AND horario_fim > :horario_inicio)
                        )
                    ";
                    $stmt_check_prof = $conn->prepare($sql_check_prof);
                    $stmt_check_prof->execute([
                        ':professor_id' => $professor_id,
                        ':ano_letivo_id' => $current_ano_letivo_id,
                        ':dia_semana' => $dia_semana,
                        ':horario_inicio' => $horario_inicio,
                        ':horario_fim' => $horario_fim
                    ]);
                    
                    if ($stmt_check_prof->fetch()) {
                        $erro = "O professor já possui outra aula neste horário.";
                    }
                }
                
                if (empty($erro)) {
                    try {
                        $sql_insert = "
                            INSERT INTO horarios (
                                professor_id, disciplina_id, turma_id, escola_id, 
                                ano_letivo_id, dia_semana, horario_inicio, horario_fim, 
                                sala_id, status, created_at
                            ) VALUES (
                                :professor_id, :disciplina_id, :turma_id, :escola_id,
                                :ano_letivo_id, :dia_semana, :horario_inicio, :horario_fim,
                                :sala_id, :status, NOW()
                            )
                        ";
                        $stmt_insert = $conn->prepare($sql_insert);
                        $stmt_insert->execute([
                            ':professor_id' => $professor_id,
                            ':disciplina_id' => $disciplina_id,
                            ':turma_id' => $current_turma_id,
                            ':escola_id' => $escola_id,
                            ':ano_letivo_id' => $current_ano_letivo_id,
                            ':dia_semana' => $dia_semana,
                            ':horario_inicio' => $horario_inicio,
                            ':horario_fim' => $horario_fim,
                            ':sala_id' => $sala_id,
                            ':status' => $status
                        ]);
                        
                        $mensagem = "Aula adicionada ao horário com sucesso!";
                        $recarregar = true;
                        
                    } catch (PDOException $e) {
                        $erro = "Erro ao adicionar aula: " . $e->getMessage();
                    }
                }
            }
        } else {
            $erro = implode("<br>", $erros);
        }
    } elseif ($action === 'remover') {
        $horario_id = (int)$_POST['horario_id'];
        
        try {
            $sql_delete = "DELETE FROM horarios WHERE id = :id AND turma_id = :turma_id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->execute([
                ':id' => $horario_id,
                ':turma_id' => $current_turma_id
            ]);
            
            $mensagem = "Aula removida do horário com sucesso!";
            $recarregar = true;
            
        } catch (PDOException $e) {
            $erro = "Erro ao remover aula: " . $e->getMessage();
        }
    } elseif ($action === 'editar') {
        $horario_id = (int)$_POST['horario_id'];
        $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
        $sala_id = !empty($_POST['sala_id']) ? (int)$_POST['sala_id'] : null;
        $status = $_POST['status'] ?? 'ativo';
        
        try {
            $sql_update = "
                UPDATE horarios 
                SET professor_id = :professor_id, 
                    sala_id = :sala_id, 
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND turma_id = :turma_id
            ";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':professor_id' => $professor_id,
                ':sala_id' => $sala_id,
                ':status' => $status,
                ':id' => $horario_id,
                ':turma_id' => $current_turma_id
            ]);
            
            $mensagem = "Horário atualizado com sucesso!";
            $recarregar = true;
            
        } catch (PDOException $e) {
            $erro = "Erro ao atualizar horário: " . $e->getMessage();
        }
    }
    
    // Recarregar horários após ação
    if ($recarregar && empty($erro)) {
        $stmt_horarios = $conn->prepare($sql_horarios);
        $stmt_horarios->execute([
            ':turma_id' => $current_turma_id,
            ':ano_letivo_id' => $current_ano_letivo_id
        ]);
        $horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Organizar horários por dia e hora
$horarios_organizados = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    $hora = $horario['horario_inicio'];
    if (!isset($horarios_organizados[$dia])) {
        $horarios_organizados[$dia] = [];
    }
    if (!isset($horarios_organizados[$dia][$hora])) {
        $horarios_organizados[$dia][$hora] = [];
    }
    $horarios_organizados[$dia][$hora][] = $horario;
}

// Coletar todos os horários únicos
$horarios_unicos = [];
foreach ($horarios as $h) {
    $key = $h['horario_inicio'] . ' - ' . $h['horario_fim'];
    if (!isset($horarios_unicos[$key])) {
        $horarios_unicos[$key] = [
            'inicio' => $h['horario_inicio'],
            'fim' => $h['horario_fim']
        ];
    }
}

// Ordenar horários
usort($horarios_unicos, function($a, $b) {
    return strcmp($a['inicio'], $b['inicio']);
});
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horário de Aulas - SIGE Angola</title>
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
        
        .btn-imprimir {
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
            margin-left: 10px;
        }
        
        .btn-imprimir:hover {
            background: rgba(255,255,255,0.3);
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
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
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
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
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
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .form-group label .required {
            color: #e74c3c;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1e5799;
            box-shadow: 0 0 0 3px rgba(30, 87, 153, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            color: white;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-danger {
            background: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
        }
        
        .horario-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .horario-table th {
            background: #1e5799;
            color: white;
            padding: 12px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #2c3e50;
        }
        
        .horario-table td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
            min-height: 80px;
            background: white;
        }
        
        .horario-table .time-col {
            background: #f8f9fa;
            font-weight: bold;
            text-align: center;
            width: 80px;
        }
        
        .aula-item {
            background: #ecf0f1;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        
        .aula-item:last-child {
            margin-bottom: 0;
        }
        
        .aula-disciplina {
            font-weight: bold;
            color: #1e5799;
            font-size: 13px;
        }
        
        .aula-professor {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 3px;
        }
        
        .aula-sala {
            font-size: 10px;
            color: #27ae60;
            margin-top: 2px;
        }
        
        .aula-status {
            font-size: 9px;
            margin-top: 3px;
        }
        
        .aula-actions {
            margin-top: 5px;
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        
        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            padding: 3px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            color: #f39c12;
        }
        
        .btn-edit:hover {
            background: #fef9e7;
        }
        
        .btn-delete {
            color: #e74c3c;
        }
        
        .btn-delete:hover {
            background: #fadbd8;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }
        
        .status-ativo {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .status-inativo {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 500px;
            border-radius: 12px;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 18px;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            opacity: 0.8;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .info-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .filtros-row {
                flex-direction: column;
            }
            
            .filtro-group {
                width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .horario-table {
                font-size: 11px;
            }
            
            .horario-table th, .horario-table td {
                padding: 5px;
            }
            
            .time-col {
                width: 50px;
            }
        }
        
        @media print {
            .btn-voltar, .btn-imprimir, .filtros-card, .card:first-of-type, .btn-success, .btn-actions, .aula-actions {
                display: none;
            }
            
            body {
                background: white;
                padding: 0;
            }
            
            .card {
                box-shadow: none;
            }
            
            .horario-table th {
                background: #1e5799;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📅 Horário de Aulas</h1>
            <p>Visualize e gerencie os horários das turmas</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn-imprimir">
                🖨️ Imprimir Horário
            </button>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar Horário
        </div>
        <div class="filtros-body">
            <form method="GET" action="">
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
                        <button type="submit" class="btn-filtrar">
                            🔍 Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma && $ano_letivo_id > 0): ?>
        <!-- Informações da Turma -->
        <div class="info-bar">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">📚 Turma</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎓 Ano</div>
                    <div class="info-value"><?php echo $turma['ano']; ?>ª Classe</div>
                </div>
                <div class="info-item">
                    <div class="info-label">🕐 Turno</div>
                    <div class="info-value"><?php echo ucfirst($turma['turno_nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🏠 Sala</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['sala_nome'] ?? 'Não definida'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Formulário para Adicionar Aula -->
        <div class="card">
            <div class="card-header">
                ➕ Adicionar Aula ao Horário
            </div>
            <div class="card-body">
                <form method="POST" action="" id="formAdicionar">
                    <input type="hidden" name="action" value="adicionar">
                    <input type="hidden" name="current_turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="current_ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Dia da Semana <span class="required">*</span></label>
                            <select name="dia_semana" class="form-control" required>
                                <option value="">Selecione o dia</option>
                                <?php foreach ($dias_semana as $num => $nome): ?>
                                    <option value="<?php echo $num; ?>"><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Horário de Início <span class="required">*</span></label>
                            <input type="time" name="horario_inicio" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Horário de Término <span class="required">*</span></label>
                            <input type="time" name="horario_fim" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Disciplina <span class="required">*</span></label>
                            <select name="disciplina_id" class="form-control" required>
                                <option value="">Selecione a disciplina</option>
                                <?php foreach ($disciplinas as $disciplina): ?>
                                    <option value="<?php echo $disciplina['id']; ?>">
                                        <?php echo htmlspecialchars($disciplina['nome']); ?> (<?php echo htmlspecialchars($disciplina['codigo']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($disciplinas)): ?>
                                <div class="info-text" style="color: #e74c3c;">
                                    ⚠️ Nenhuma disciplina associada a esta turma. 
                                    <a href="atribuir_disciplinas.php?turma_id=<?php echo $turma_id; ?>">Clique aqui para atribuir disciplinas</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label>Professor</label>
                            <select name="professor_id" class="form-control">
                                <option value="">Selecione o professor (opcional)</option>
                                <?php foreach ($professores as $professor): ?>
                                    <option value="<?php echo $professor['id']; ?>">
                                        <?php echo htmlspecialchars($professor['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Sala</label>
                            <select name="sala_id" class="form-control">
                                <option value="">Selecione a sala (opcional)</option>
                                <?php foreach ($salas as $sala): ?>
                                    <option value="<?php echo $sala['id']; ?>">
                                        <?php echo htmlspecialchars($sala['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="btn-group" style="margin-top: 15px;">
                        <button type="submit" class="btn btn-success">✅ Adicionar Aula</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Visualização do Horário -->
        <div class="card">
            <div class="card-header">
                📋 Grade de Horários - <?php echo htmlspecialchars($turma['ano_letivo_ano']); ?>
            </div>
            <div class="card-body" style="overflow-x: auto;">
                <?php if (empty($horarios_unicos)): ?>
                    <div style="text-align: center; padding: 50px;">
                        <p style="color: #7f8c8d;">Nenhum horário cadastrado para esta turma.</p>
                        <p style="color: #7f8c8d; font-size: 12px;">Use o formulário acima para adicionar aulas ao horário.</p>
                    </div>
                <?php else: ?>
                    <table class="horario-table">
                        <thead>
                            <tr>
                                <th class="time-col">Horário</th>
                                <?php foreach ($dias_semana as $num => $nome): ?>
                                    <th><?php echo $nome; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($horarios_unicos as $horario_key => $horario_times): ?>
                                <tr>
                                    <td class="time-col">
                                        <?php echo date('H:i', strtotime($horario_times['inicio'])); ?> - 
                                        <?php echo date('H:i', strtotime($horario_times['fim'])); ?>
                                    </td>
                                    <?php foreach ($dias_semana as $num => $nome): ?>
                                        <td>
                                            <?php
                                            $aulas = isset($horarios_organizados[$num][$horario_times['inicio']]) 
                                                ? $horarios_organizados[$num][$horario_times['inicio']] 
                                                : [];
                                            ?>
                                            <?php foreach ($aulas as $aula): ?>
                                                <div class="aula-item">
                                                    <div class="aula-disciplina">
                                                        <?php echo htmlspecialchars($aula['disciplina_nome']); ?>
                                                        <?php if ($aula['status'] == 'inativo'): ?>
                                                            <span class="status-badge status-inativo">INATIVO</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($aula['professor_nome']): ?>
                                                        <div class="aula-professor">
                                                            👨‍🏫 <?php echo htmlspecialchars($aula['professor_nome']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($aula['sala_nome']): ?>
                                                        <div class="aula-sala">
                                                            🏠 <?php echo htmlspecialchars($aula['sala_nome']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="aula-actions">
                                                        <button class="btn-icon btn-edit" onclick="editarHorario(<?php echo $aula['id']; ?>, <?php echo $aula['professor_id'] ?: 'null'; ?>, <?php echo $aula['sala_id'] ?: 'null'; ?>, '<?php echo $aula['status']; ?>')" title="Editar">
                                                            ✏️
                                                        </button>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja remover esta aula?')">
                                                            <input type="hidden" name="action" value="remover">
                                                            <input type="hidden" name="horario_id" value="<?php echo $aula['id']; ?>">
                                                            <input type="hidden" name="current_turma_id" value="<?php echo $turma_id; ?>">
                                                            <input type="hidden" name="current_ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                                                            <button type="submit" class="btn-icon btn-delete" title="Remover">
                                                                🗑️
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($aulas)): ?>
                                                <div style="height: 60px;"></div>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($turma_id > 0 && !$turma): ?>
        <div class="alert alert-danger">
            ❌ Turma não encontrada ou não pertence à sua escola.
        </div>
    <?php elseif (empty($turmas_lista)): ?>
        <div class="alert alert-info">
            ℹ️ Nenhuma turma cadastrada. <a href="cadastrar_turma.php">Clique aqui para cadastrar uma turma</a>.
        </div>
    <?php endif; ?>
    
    <!-- Informações Adicionais -->
    <div class="card">
        <div class="card-header">
            ℹ️ Informações Importantes
        </div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li>Selecione uma turma e o ano letivo para visualizar o horário.</li>
                <li>O sistema verifica automaticamente conflitos de horário para a mesma turma e professor.</li>
                <li>É possível desativar uma aula sem removê-la (status "Inativo").</li>
                <li>O horário pode ser impresso para distribuição aos alunos.</li>
                <li>Certifique-se de que as disciplinas estão atribuídas à turma antes de adicionar horários.</li>
            </ul>
        </div>
    </div>
</div>

<!-- Modal de Edição -->
<div id="modalEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>✏️ Editar Aula</h3>
            <span class="close" onclick="fecharModalEditar()">&times;</span>
        </div>
        <form method="POST" action="" id="formEditar">
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="horario_id" id="edit_horario_id">
            <input type="hidden" name="current_turma_id" value="<?php echo $turma_id; ?>">
            <input type="hidden" name="current_ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label>Professor</label>
                    <select name="professor_id" id="edit_professor_id" class="form-control">
                        <option value="">Selecione o professor</option>
                        <?php foreach ($professores as $professor): ?>
                            <option value="<?php echo $professor['id']; ?>">
                                <?php echo htmlspecialchars($professor['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sala</label>
                    <select name="sala_id" id="edit_sala_id" class="form-control">
                        <option value="">Selecione a sala</option>
                        <?php foreach ($salas as $sala): ?>
                            <option value="<?php echo $sala['id']; ?>">
                                <?php echo htmlspecialchars($sala['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fecharModalEditar()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">💾 Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editarHorario(id, professorId, salaId, status) {
        const modal = document.getElementById('modalEditar');
        document.getElementById('edit_horario_id').value = id;
        document.getElementById('edit_professor_id').value = professorId || '';
        document.getElementById('edit_sala_id').value = salaId || '';
        document.getElementById('edit_status').value = status || 'ativo';
        modal.style.display = 'block';
    }
    
    function fecharModalEditar() {
        const modal = document.getElementById('modalEditar');
        modal.style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalEditar');
        if (event.target == modal) {
            fecharModalEditar();
        }
    }
    
    // Recarregar a página após submit do formulário para mostrar os dados atualizados
    document.getElementById('formAdicionar')?.addEventListener('submit', function() {
        setTimeout(function() {
            location.reload();
        }, 500);
    });
</script>
</body>
</html>