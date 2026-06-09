<?php
// escola/pedagogico/atribuir_professor_disciplina.php - Atribuir Professor à Disciplina

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
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
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
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([
        ':turma_id' => $turma_id,
        ':escola_id' => $escola_id
    ]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma['ano_letivo_id'];
    }
}

// Buscar anos letivos para o filtro
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

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

// Buscar professores da escola
$sql_professores = "
    SELECT id, nome, email, telefone, cargo
    FROM funcionarios
    WHERE escola_id = :escola_id AND status = 1
    ORDER BY nome ASC
";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':escola_id' => $escola_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar atribuições existentes
$atribuicoes = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_atribuicoes = "
        SELECT 
            pdt.id,
            pdt.professor_id,
            pdt.disciplina_id,
            pdt.turma_id,
            pdt.dia_semana,
            pdt.horario_inicio,
            pdt.horario_fim,
            pdt.ano_letivo_id,
            pdt.carga_horaria,
            pdt.status,
            p.nome as professor_nome,
            p.email as professor_email,
            d.nome as disciplina_nome,
            d.codigo as disciplina_codigo
        FROM professor_disciplina_turma pdt
        INNER JOIN funcionarios p ON p.id = pdt.professor_id
        INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
        WHERE pdt.turma_id = :turma_id 
        AND pdt.ano_letivo_id = :ano_letivo_id
    ";
    $params = [
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ];
    
    if ($disciplina_id > 0) {
        $sql_atribuicoes .= " AND pdt.disciplina_id = :disciplina_id";
        $params[':disciplina_id'] = $disciplina_id;
    }
    
    $sql_atribuicoes .= " ORDER BY d.nome ASC";
    $stmt_atribuicoes = $conn->prepare($sql_atribuicoes);
    $stmt_atribuicoes->execute($params);
    $atribuicoes = $stmt_atribuicoes->fetchAll(PDO::FETCH_ASSOC);
}

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

// Processar formulário
$mensagem = '';
$erro = '';
$recarregar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $current_turma_id = (int)$_POST['current_turma_id'] ?? $turma_id;
    $current_ano_letivo_id = (int)$_POST['current_ano_letivo_id'] ?? $ano_letivo_id;
    
    if ($action === 'atribuir') {
        $professor_id = (int)$_POST['professor_id'];
        $disciplina_id_post = (int)$_POST['disciplina_id'];
        $dia_semana = !empty($_POST['dia_semana']) ? (int)$_POST['dia_semana'] : null;
        $horario_inicio = $_POST['horario_inicio'] ?? null;
        $horario_fim = $_POST['horario_fim'] ?? null;
        $carga_horaria = !empty($_POST['carga_horaria']) ? (int)$_POST['carga_horaria'] : null;
        $status = $_POST['status'] ?? 'ativo';
        
        $erros = [];
        
        if ($professor_id <= 0) $erros[] = "Selecione um professor.";
        if ($disciplina_id_post <= 0) $erros[] = "Selecione uma disciplina.";
        
        if (empty($erros)) {
            // Verificar se já existe atribuição
            $sql_check = "
                SELECT id FROM professor_disciplina_turma 
                WHERE turma_id = :turma_id 
                AND disciplina_id = :disciplina_id 
                AND ano_letivo_id = :ano_letivo_id
            ";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':turma_id' => $current_turma_id,
                ':disciplina_id' => $disciplina_id_post,
                ':ano_letivo_id' => $current_ano_letivo_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erro = "Já existe um professor atribuído a esta disciplina no ano letivo selecionado.";
            } else {
                // Verificar conflito de horário do professor
                if ($dia_semana && $horario_inicio && $horario_fim) {
                    $sql_check_horario = "
                        SELECT id FROM professor_disciplina_turma 
                        WHERE professor_id = :professor_id 
                        AND ano_letivo_id = :ano_letivo_id
                        AND dia_semana = :dia_semana 
                        AND (
                            (horario_inicio < :horario_fim AND horario_fim > :horario_inicio)
                        )
                    ";
                    $stmt_check_horario = $conn->prepare($sql_check_horario);
                    $stmt_check_horario->execute([
                        ':professor_id' => $professor_id,
                        ':ano_letivo_id' => $current_ano_letivo_id,
                        ':dia_semana' => $dia_semana,
                        ':horario_inicio' => $horario_inicio,
                        ':horario_fim' => $horario_fim
                    ]);
                    
                    if ($stmt_check_horario->fetch()) {
                        $erro = "O professor já possui outra aula neste horário.";
                    }
                }
                
                if (empty($erro)) {
                    try {
                        $sql_insert = "
                            INSERT INTO professor_disciplina_turma (
                                professor_id, disciplina_id, turma_id, 
                                dia_semana, horario_inicio, horario_fim, 
                                ano_letivo_id, carga_horaria, status, created_at
                            ) VALUES (
                                :professor_id, :disciplina_id, :turma_id,
                                :dia_semana, :horario_inicio, :horario_fim,
                                :ano_letivo_id, :carga_horaria, :status, NOW()
                            )
                        ";
                        $stmt_insert = $conn->prepare($sql_insert);
                        $stmt_insert->execute([
                            ':professor_id' => $professor_id,
                            ':disciplina_id' => $disciplina_id_post,
                            ':turma_id' => $current_turma_id,
                            ':dia_semana' => $dia_semana,
                            ':horario_inicio' => $horario_inicio,
                            ':horario_fim' => $horario_fim,
                            ':ano_letivo_id' => $current_ano_letivo_id,
                            ':carga_horaria' => $carga_horaria,
                            ':status' => $status
                        ]);
                        
                        $mensagem = "Professor atribuído à disciplina com sucesso!";
                        $recarregar = true;
                        
                    } catch (PDOException $e) {
                        $erro = "Erro ao atribuir professor: " . $e->getMessage();
                    }
                }
            }
        } else {
            $erro = implode("<br>", $erros);
        }
    } elseif ($action === 'remover') {
        $atribuicao_id = (int)$_POST['atribuicao_id'];
        
        try {
            $sql_delete = "DELETE FROM professor_disciplina_turma WHERE id = :id AND turma_id = :turma_id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->execute([
                ':id' => $atribuicao_id,
                ':turma_id' => $current_turma_id
            ]);
            
            $mensagem = "Atribuição removida com sucesso!";
            $recarregar = true;
            
        } catch (PDOException $e) {
            $erro = "Erro ao remover atribuição: " . $e->getMessage();
        }
    }
    
    // Recarregar atribuições
    if ($recarregar && empty($erro)) {
        header("Location: atribuir_professor_disciplina.php?turma_id={$current_turma_id}&ano_letivo_id={$current_ano_letivo_id}" . ($disciplina_id > 0 ? "&disciplina_id={$disciplina_id}" : ""));
        exit;
    }
}

// Contar estatísticas
$total_atribuicoes = count($atribuicoes);
$total_ativas = count(array_filter($atribuicoes, function($a) { return $a['status'] == 'ativo'; }));
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atribuir Professor - SIGE Angola</title>
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
        
        .btn-limpar {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-limpar:hover {
            background: #7f8c8d;
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
        }
        
        .stat-card.blue { border-bottom: 4px solid #1e5799; }
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
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
        
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
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
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
        }
        
        .table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-ativo {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .badge-inativo {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .badge-info {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-delete {
            color: #e74c3c;
        }
        
        .btn-delete:hover {
            background: #fadbd8;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .filtros-row {
                flex-direction: column;
            }
            
            .filtro-group {
                width: 100%;
            }
            
            .two-columns {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>👨‍🏫 Atribuir Professor à Disciplina</h1>
            <p>Gerencie os professores por disciplina e turma</p>
        </div>
        <div>
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
            🔍 Filtrar
        </div>
        <div class="filtros-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select" id="filtro_turma">
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
                        <select name="ano_letivo_id" class="filtro-select" id="filtro_ano">
                            <option value="">Todos os anos</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select" id="filtro_disciplina">
                            <option value="0">Todas as disciplinas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                                <option value="<?php echo $disc['id']; ?>" <?php echo ($disciplina_id == $disc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($disc['nome']); ?> (<?php echo htmlspecialchars($disc['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                        <a href="atribuir_professor_disciplina.php" class="btn-limpar">🗑️ Limpar</a>
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
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $total_atribuicoes; ?></div>
                <div class="stat-label">Total de Atribuições</div>
            </div>
            <div class="stat-card green">
                <div class="stat-number"><?php echo $total_ativas; ?></div>
                <div class="stat-label">Atribuições Ativas</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-number"><?php echo count($disciplinas); ?></div>
                <div class="stat-label">Disciplinas</div>
            </div>
        </div>
        
        <div class="two-columns">
            <!-- Coluna 1: Formulário de Atribuição -->
            <div class="card">
                <div class="card-header">
                    ➕ Nova Atribuição
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="atribuir">
                        <input type="hidden" name="current_turma_id" value="<?php echo $turma_id; ?>">
                        <input type="hidden" name="current_ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                        
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
                            <label>Professor <span class="required">*</span></label>
                            <select name="professor_id" class="form-control" required>
                                <option value="">Selecione o professor</option>
                                <?php foreach ($professores as $professor): ?>
                                    <option value="<?php echo $professor['id']; ?>">
                                        <?php echo htmlspecialchars($professor['nome']); ?>
                                        <?php if ($professor['cargo']): ?>
                                            (<?php echo htmlspecialchars($professor['cargo']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Dia da Semana</label>
                                <select name="dia_semana" class="form-control">
                                    <option value="">Selecione o dia</option>
                                    <?php foreach ($dias_semana as $num => $nome): ?>
                                        <option value="<?php echo $num; ?>"><?php echo $nome; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Horário Início</label>
                                <input type="time" name="horario_inicio" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label>Horário Fim</label>
                                <input type="time" name="horario_fim" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Carga Horária (horas)</label>
                                <input type="number" name="carga_horaria" class="form-control" placeholder="Ex: 96">
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="ativo">Ativo</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" class="btn btn-success">✅ Atribuir Professor</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Coluna 2: Lista de Atribuições -->
            <div class="card">
                <div class="card-header">
                    📋 Professores Atribuídos
                    <?php if ($disciplina_id > 0): ?>
                        <span style="font-size: 12px;">Filtrando por: <?php 
                            foreach ($disciplinas as $d) {
                                if ($d['id'] == $disciplina_id) {
                                    echo htmlspecialchars($d['nome']);
                                    break;
                                }
                            }
                        ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($atribuicoes)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">👨‍🏫</div>
                            <p>Nenhum professor atribuído a esta turma.</p>
                            <p style="font-size: 12px;">Utilize o formulário ao lado para atribuir professores às disciplinas.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Disciplina</th>
                                        <th>Professor</th>
                                        <th>Dia/Horário</th>
                                        <th>Carga Horária</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($atribuicoes as $atribuicao): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($atribuicao['disciplina_nome']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($atribuicao['disciplina_codigo']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($atribuicao['professor_nome']); ?><br>
                                                <small><?php echo htmlspecialchars($atribuicao['professor_email']); ?></small>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($atribuicao['dia_semana'] && $atribuicao['horario_inicio']) {
                                                    echo $atribuicao['dia_semana'] . '<br>';
                                                    echo date('H:i', strtotime($atribuicao['horario_inicio'])) . ' - ' . date('H:i', strtotime($atribuicao['horario_fim']));
                                                } else {
                                                    echo '---';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo $atribuicao['carga_horaria'] ? $atribuicao['carga_horaria'] . 'h' : '---'; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $atribuicao['status'] == '1' ? 'badge-ativo' : 'badge-inativo'; ?>">
                                                    <?php echo strtoupper($atribuicao['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja remover esta atribuição?')">
                                                    <input type="hidden" name="action" value="remover">
                                                    <input type="hidden" name="atribuicao_id" value="<?php echo $atribuicao['id']; ?>">
                                                    <input type="hidden" name="current_turma_id" value="<?php echo $turma_id; ?>">
                                                    <input type="hidden" name="current_ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                                                    <button type="submit" class="btn-icon btn-delete" title="Remover">
                                                        🗑️
                                                    </button>
                                                </form>
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
                <li>Selecione uma turma e o ano letivo para visualizar as atribuições.</li>
                <li>Cada disciplina pode ter apenas um professor principal por turma/ano letivo.</li>
                <li>O sistema verifica automaticamente conflitos de horário para o mesmo professor.</li>
                <li>Atribuições inativas não são consideradas para o horário da turma.</li>
                <li>Certifique-se de que as disciplinas estão atribuídas à turma antes de designar professores.</li>
            </ul>
        </div>
    </div>
</div>

<script>
    // Quando a turma mudar, atualizar a lista de disciplinas
    const filtroTurma = document.getElementById('filtro_turma');
    const filtroDisciplina = document.getElementById('filtro_disciplina');
    
    if (filtroTurma) {
        filtroTurma.addEventListener('change', function() {
            const turmaId = this.value;
            if (turmaId) {
                // Recarregar a página com a nova turma
                window.location.href = `atribuir_professor_disciplina.php?turma_id=${turmaId}`;
            }
        });
    }
</script>
</body>
</html>