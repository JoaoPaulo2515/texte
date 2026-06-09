<?php
// escola/pedagogico/alunos_turma.php - Listar Alunos de uma Turma

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
        al.ano as ano_letivo_ano,
        t.capacidade,
        t.numero_alunos
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas_lista = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Parâmetros de filtro
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

// Se não tem turma selecionada e tem turmas disponíveis, pegar a primeira
if ($turma_id == 0 && !empty($turmas_lista)) {
    $turma_id = $turmas_lista[0]['id'];
    $ano_letivo_id = $turmas_lista[0]['ano_letivo_id'];
}

// Buscar dados da turma selecionada
$turma_atual = null;
if ($turma_id > 0) {
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.sala_id,
            s.nome as sala_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano,
            t.capacidade,
            t.numero_alunos,
            t.vagas_disponiveis
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
    $turma_atual = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma_atual && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma_atual['ano_letivo_id'];
    }
}

// Buscar anos letivos para o filtro
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Processar ações (remover aluno, transferir, etc)
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $current_turma_id = (int)$_POST['current_turma_id'] ?? $turma_id;
    $current_ano_letivo_id = (int)$_POST['current_ano_letivo_id'] ?? $ano_letivo_id;
    
    if ($action === 'remover') {
        $estudante_id = (int)$_POST['estudante_id'] ?? 0;
        $matricula_id = (int)$_POST['matricula_id'] ?? 0;
        
        if ($estudante_id > 0 && $matricula_id > 0) {
            try {
                // Atualizar status da matrícula para 'cancelada'
                $sql_update = "UPDATE matriculas SET status = 'cancelada', data_cancelamento = NOW() WHERE id = :matricula_id AND turma_id = :turma_id";
                $stmt = $conn->prepare($sql_update);
                $stmt->execute([
                    ':matricula_id' => $matricula_id,
                    ':turma_id' => $current_turma_id
                ]);
                
                // Atualizar número de alunos na turma
                $sql_count = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
                $stmt_count = $conn->prepare($sql_count);
                $stmt_count->execute([':turma_id' => $current_turma_id]);
                $total = $stmt_count->fetch(PDO::FETCH_ASSOC);
                
                $sql_update_turma = "UPDATE turmas SET numero_alunos = :total WHERE id = :turma_id";
                $stmt_update_turma = $conn->prepare($sql_update_turma);
                $stmt_update_turma->execute([
                    ':total' => $total['total'],
                    ':turma_id' => $current_turma_id
                ]);
                
                $mensagem = "Aluno removido da turma com sucesso!";
                
                // Atualizar dados da turma
                if ($turma_atual) {
                    $turma_atual['numero_alunos'] = $total['total'];
                }
                
            } catch (PDOException $e) {
                $erro = "Erro ao remover aluno: " . $e->getMessage();
            }
        } else {
            $erro = "Dados do aluno inválidos.";
        }
        
        // Redirecionar para evitar reenvio do formulário
        if (empty($erro)) {
            header("Location: alunos_turma.php?turma_id={$current_turma_id}&ano_letivo_id={$current_ano_letivo_id}");
            exit;
        }
    }
}

// Buscar alunos da turma
$alunos = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id as estudante_id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.telefone,
            e.email,
            e.endereco,
            e.encarregado_nome,
            e.encarregado_telefone,
            e.encarregado_email,
            m.id as matricula_id,
            m.data_matricula,
            m.status as matricula_status,
            m.numero_processo,
            COALESCE(AVG(n.mac), 0) as media_notas,
            COUNT(DISTINCT n.id) as total_notas
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        LEFT JOIN notas n ON n.estudante_id = e.id AND n.ano_letivo_id = m.ano_letivo
        WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id
        GROUP BY e.id
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// Calcular estatísticas da turma
$total_alunos = count($alunos);
$media_geral_turma = 0;
$total_aprovados = 0;
$total_reprovados = 0;
$total_recuperacao = 0;

foreach ($alunos as $aluno) {
    $media = $aluno['media_notas'];
    $media_geral_turma += $media;
    if ($media >= 14) {
        $total_aprovados++;
    } elseif ($media >= 10) {
        $total_aprovados++;
    } elseif ($media >= 7) {
        $total_recuperacao++;
    } else {
        $total_reprovados++;
    }
}
$media_geral_turma = $total_alunos > 0 ? round($media_geral_turma / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alunos da Turma - SIGE Angola</title>
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .stat-card.red { border-bottom: 4px solid #e74c3c; }
        
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
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
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
            font-size: 13px;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
            font-size: 13px;
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
        
        .badge-excelente {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .badge-aprovado {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .badge-recuperacao {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .badge-reprovado {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .btn-acoes {
            display: inline-flex;
            gap: 8px;
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
        
        .btn-view {
            color: #1e5799;
        }
        
        .btn-view:hover {
            background: #d4e6f1;
        }
        
        .btn-transfer {
            color: #f39c12;
        }
        
        .btn-transfer:hover {
            background: #fef9e7;
        }
        
        .btn-remove {
            color: #e74c3c;
        }
        
        .btn-remove:hover {
            background: #fadbd8;
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
        
        .alert-warning {
            background: #fef9e7;
            color: #f39c12;
            border-left: 4px solid #f39c12;
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
            max-width: 600px;
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
            max-height: 500px;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table {
                font-size: 11px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
            
            .btn-acoes {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>👨‍🎓 Alunos da Turma</h1>
            <p>Visualize e gerencie os alunos matriculados</p>
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
    
    <?php if ($turma_atual && $ano_letivo_id > 0): ?>
        <!-- Informações da Turma -->
        <div class="info-bar">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">📚 Turma</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma_atual['nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎓 Ano</div>
                    <div class="info-value"><?php echo $turma_atual['ano']; ?>ª Classe</div>
                </div>
                <div class="info-item">
                    <div class="info-label">🕐 Turno</div>
                    <div class="info-value"><?php echo ucfirst($turma_atual['turno_nome']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🏠 Sala</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma_atual['sala_nome'] ?? 'Não definida'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma_atual['ano_letivo_ano']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">📊 Capacidade</div>
                    <div class="info-value"><?php echo $turma_atual['numero_alunos']; ?> / <?php echo $turma_atual['capacidade'] ?? '∞'; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $total_alunos; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
            <div class="stat-card green">
                <div class="stat-number"><?php echo $total_aprovados; ?></div>
                <div class="stat-label">Aprovados (Média ≥ 10)</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-number"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Recuperação (Média 7-9)</div>
            </div>
            <div class="stat-card red">
                <div class="stat-number"><?php echo $total_reprovados; ?></div>
                <div class="stat-label">Reprovados (Média < 7)</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $percentual_aprovacao; ?>%</div>
                <div class="stat-label">Taxa de Aprovação</div>
            </div>
            <div class="stat-card green">
                <div class="stat-number"><?php echo $media_geral_turma; ?></div>
                <div class="stat-label">Média Geral da Turma</div>
            </div>
        </div>
        
        <!-- Lista de Alunos -->
        <div class="card">
            <div class="card-header">
                <span>📋 Lista de Alunos Matriculados</span>
                <a href="matricular_aluno.php?turma_id=<?php echo $turma_id; ?>" class="btn-novo">
                    ➕ Nova Matrícula
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($alunos)): ?>
                    <div style="text-align: center; padding: 50px;">
                        <p style="color: #7f8c8d;">Nenhum aluno matriculado nesta turma.</p>
                        <a href="matricular_aluno.php?turma_id=<?php echo $turma_id; ?>" class="btn-novo" style="margin-top: 15px; display: inline-block;">
                            ➕ Matricular Primeiro Aluno
                        </a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nome</th>
                                <th>Matrícula</th>
                                <th>BI</th>
                                <th>Contato</th>
                                <th>Encarregado</th>
                                <th>Média</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno): 
                                $media = round($aluno['media_notas'], 1);
                                if ($media >= 14) {
                                    $status = 'Excelente';
                                    $status_class = 'badge-excelente';
                                } elseif ($media >= 10) {
                                    $status = 'Aprovado';
                                    $status_class = 'badge-aprovado';
                                } elseif ($media >= 7) {
                                    $status = 'Recuperação';
                                    $status_class = 'badge-recuperacao';
                                } else {
                                    $status = 'Reprovado';
                                    $status_class = 'badge-reprovado';
                                }
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br>
                                        <small style="color: #7f8c8d;">📞 <?php echo htmlspecialchars($aluno['telefone'] ?? '---'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['bi'] ?? '---'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($aluno['email'] ?? '---'); ?><br>
                                        <small><?php echo htmlspecialchars($aluno['endereco'] ?? '---'); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aluno['encarregado_nome'] ?? '---'); ?></strong><br>
                                        <small>📞 <?php echo htmlspecialchars($aluno['encarregado_telefone'] ?? '---'); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo $media; ?></strong>
                                        <?php if ($aluno['total_notas'] > 0): ?>
                                            <br><small>(<?php echo $aluno['total_notas']; ?> notas)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                                    <td class="btn-acoes">
                                        <button class="btn-icon btn-view" onclick="verAluno(<?php echo $aluno['estudante_id']; ?>)" title="Ver Detalhes">
                                            👁️
                                        </button>
                                        <button class="btn-icon btn-transfer" onclick="transferirAluno(<?php echo $aluno['estudante_id']; ?>, '<?php echo addslashes($aluno['nome']); ?>')" title="Transferir Aluno">
                                            🔄
                                        </button>
                                        <button class="btn-icon btn-remove" onclick="removerAluno(<?php echo $aluno['estudante_id']; ?>, <?php echo $aluno['matricula_id']; ?>, '<?php echo addslashes($aluno['nome']); ?>')" title="Remover da Turma">
                                            🗑️
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    <?php elseif ($turma_id > 0 && !$turma_atual): ?>
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
        <div class="card-body" style="padding: 20px;">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li>Selecione uma turma e o ano letivo para visualizar os alunos matriculados.</li>
                <li>A média de notas é calculada com base nas avaliações lançadas no sistema (MAC).</li>
                <li><strong>Critério de avaliação:</strong> Aprovado (≥10), Recuperação (7-9), Reprovado (<7).</li>
                <li>Excelente é uma classificação especial para médias ≥ 14.</li>
                <li>Ao remover um aluno, a matrícula é cancelada, mas o histórico é mantido.</li>
                <li>Transferir o aluno redireciona para a página de transferência.</li>
            </ul>
        </div>
    </div>
</div>

<!-- Modal de Detalhes do Aluno -->
<div id="modalAluno" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Detalhes do Aluno</h3>
            <span class="close" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <p>Carregando...</p>
        </div>
        <div class="modal-footer">
            <button class="btn-icon btn-transfer" onclick="transferirFromModal()">🔄 Transferir Aluno</button>
            <button class="btn-icon btn-view" onclick="verHistorico()">📚 Ver Histórico</button>
            <button onclick="fecharModal()" style="background: #95a5a6; padding: 8px 15px; border-radius: 8px; border: none; color: white; cursor: pointer;">Fechar</button>
        </div>
    </div>
</div>

<script>
    let alunoAtualId = null;
    let alunoAtualNome = '';
    
    function verAluno(id) {
        alunoAtualId = id;
        const modal = document.getElementById('modalAluno');
        const modalBody = document.getElementById('modalBody');
        
        modal.style.display = 'block';
        modalBody.innerHTML = '<p>🔍 Carregando dados do aluno...</p>';
        
        fetch(`buscar_aluno.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    modalBody.innerHTML = `<p style="color: red;">❌ ${data.error}</p>`;
                    return;
                }
                
                alunoAtualNome = data.nome;
                
                let html = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #1e5799;">${escapeHtml(data.nome)}</h4>
                        <hr>
                    </div>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Matrícula:</strong></td><td style="padding: 8px;">${escapeHtml(data.matricula)}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>BI:</strong></td><td style="padding: 8px;">${escapeHtml(data.bi || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Data Nascimento:</strong></td><td style="padding: 8px;">${escapeHtml(data.data_nascimento || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Telefone:</strong></td><td style="padding: 8px;">${escapeHtml(data.telefone || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Email:</strong></td><td style="padding: 8px;">${escapeHtml(data.email || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Endereço:</strong></td><td style="padding: 8px;">${escapeHtml(data.endereco || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Encarregado:</strong></td><td style="padding: 8px;">${escapeHtml(data.encarregado_nome || '---')}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Telefone Encarregado:</strong></td><td style="padding: 8px;">${escapeHtml(data.encarregado_telefone || '---')}</td></tr>
                    ~
                `;
                
                modalBody.innerHTML = html;
            })
            .catch(error => {
                modalBody.innerHTML = `<p style="color: red;">❌ Erro ao carregar dados: ${error.message}</p>`;
            });
    }
    
    function transferirAluno(id, nome) {
        if (confirm(`Deseja transferir o aluno "${nome}" para outra turma ou escola?`)) {
            window.location.href = `transferir_aluno.php?estudante_id=${id}`;
        }
    }
    
    function transferirFromModal() {
        if (alunoAtualId) {
            window.location.href = `transferir_aluno.php?estudante_id=${alunoAtualId}`;
        }
    }
    
    function verHistorico() {
        if (alunoAtualId) {
            window.location.href = `historico_escolar.php?id=${alunoAtualId}`;
        }
    }
    
    function removerAluno(estudanteId, matriculaId, nome) {
        if (confirm(`Tem certeza que deseja remover o aluno "${nome}" desta turma?\n\nEsta ação cancelará a matrícula atual.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'action';
            inputAction.value = 'remover';
            
            const inputEstudante = document.createElement('input');
            inputEstudante.type = 'hidden';
            inputEstudante.name = 'estudante_id';
            inputEstudante.value = estudanteId;
            
            const inputMatricula = document.createElement('input');
            inputMatricula.type = 'hidden';
            inputMatricula.name = 'matricula_id';
            inputMatricula.value = matriculaId;
            
            const inputTurma = document.createElement('input');
            inputTurma.type = 'hidden';
            inputTurma.name = 'current_turma_id';
            inputTurma.value = '<?php echo $turma_id; ?>';
            
            const inputAno = document.createElement('input');
            inputAno.type = 'hidden';
            inputAno.name = 'current_ano_letivo_id';
            inputAno.value = '<?php echo $ano_letivo_id; ?>';
            
            form.appendChild(inputAction);
            form.appendChild(inputEstudante);
            form.appendChild(inputMatricula);
            form.appendChild(inputTurma);
            form.appendChild(inputAno);
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function fecharModal() {
        const modal = document.getElementById('modalAluno');
        modal.style.display = 'none';
        alunoAtualId = null;
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalAluno');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>