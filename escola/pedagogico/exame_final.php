<?php
// escola/pedagogico/exame_final.php - Gerenciar Exames Finais

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
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

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

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para exame final (reprovados ou ainda em recuperação)
$alunos_exame_final = [];
if ($turma_id > 0 && $disciplina_id > 0 && $ano_letivo_id > 0) {
    $sql_exame_final = "
        SELECT 
            n.id as nota_id,
            n.estudante_id,
            n.disciplina_id,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.media_parcial,
            n.media_final,
            n.status,
            e.nome as estudante_nome,
            e.matricula,
            e.bi,
            d.nome as disciplina_nome,
            d.codigo as disciplina_codigo
        FROM notas n
        INNER JOIN estudantes e ON e.id = n.estudante_id
        INNER JOIN disciplinas d ON d.id = n.disciplina_id
        WHERE n.turma_id = :turma_id 
        AND n.disciplina_id = :disciplina_id
        AND n.ano_letivo_id = :ano_letivo_id
        AND (n.status = 'reprovado' OR n.status = 'recuperacao')
        ORDER BY e.nome ASC
    ";
    $stmt_exame_final = $conn->prepare($sql_exame_final);
    $stmt_exame_final->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_exame_final = $stmt_exame_final->fetchAll(PDO::FETCH_ASSOC);
}

// Processar lançamento de notas do exame final
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar') {
    $salvos = 0;
    $erros = 0;
    
    foreach ($_POST['notas'] as $nota_id => $nota_data) {
        $exame_especial = !empty($nota_data['exame_especial']) ? (float)str_replace(',', '.', $nota_data['exame_especial']) : null;
        
        if ($exame_especial !== null) {
            try {
                // Calcular nova média final (nota do exame especial)
                $nova_media_final = $exame_especial;
                
                // Determinar novo status
                $novo_status = 'reprovado';
                if ($nova_media_final >= 10) {
                    $novo_status = 'aprovado';
                } elseif ($nova_media_final >= 7) {
                    $novo_status = 'recuperacao';
                } else {
                    $novo_status = 'reprovado';
                }
                
                // Atualizar a nota
                $sql_update = "
                    UPDATE notas 
                    SET exame_especial = :exame_especial,
                        media_final = :media_final,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':exame_especial' => $exame_especial,
                    ':media_final' => $nova_media_final,
                    ':status' => $novo_status,
                    ':id' => $nota_id
                ]);
                $salvos++;
            } catch (PDOException $e) {
                $erros++;
            }
        }
    }
    
    if ($erros == 0) {
        $mensagem = "✅ Notas do exame final salvas com sucesso! ($salvos registros)";
        // Recarregar lista
        $stmt_exame_final->execute([
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $alunos_exame_final = $stmt_exame_final->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $erro = "⚠️ Erro ao salvar notas. $salvos salvos, $erros erros.";
    }
}

// Calcular estatísticas
$total_exame_final = count($alunos_exame_final);
$total_reprovados = count(array_filter($alunos_exame_final, function($a) { return $a['status'] == 'reprovado'; }));
$total_recuperacao = count(array_filter($alunos_exame_final, function($a) { return $a['status'] == 'recuperacao'; }));
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exame Final - SIGE Angola</title>
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
            min-width: 180px;
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
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.red { border-bottom: 4px solid #e74c3c; }
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        
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
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
        }
        
        .table-exame {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-exame th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e74c3c;
            font-size: 12px;
        }
        
        .table-exame td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-exame tr:hover {
            background: #f8f9fa;
        }
        
        .aluno-nome {
            text-align: left;
            font-weight: 600;
        }
        
        .nota-input {
            width: 100px;
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
        }
        
        .nota-input:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.1);
        }
        
        .nota-original {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .nota-baixa {
            color: #c0392b;
            background: #fadbd8;
        }
        
        .nota-media {
            color: #f39c12;
            background: #fef9e7;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-reprovado {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .status-recuperacao {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .status-aprovado {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .btn-salvar {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-salvar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
        
        .form-actions {
            text-align: right;
        }
        
        .disciplina-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #7f8c8d;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
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
            
            .table-exame {
                font-size: 11px;
            }
            
            .table-exame th, .table-exame td {
                padding: 6px;
            }
            
            .nota-input {
                width: 70px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📝 Exame Final</h1>
            <p>Gerenciar exames finais para alunos reprovados ou em recuperação</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success"><?php echo $mensagem; ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger"><?php echo $erro; ?></div>
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
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-number"><?php echo $total_exame_final; ?></div>
                <div class="stat-label">Total para Exame Final</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-number"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Em Recuperação</div>
            </div>
            <div class="stat-card red">
                <div class="stat-number"><?php echo $total_reprovados; ?></div>
                <div class="stat-label">Reprovados</div>
            </div>
            <div class="stat-card green">
                <div class="stat-number">
                    <?php 
                    $aprovados_exame = count(array_filter($alunos_exame_final, function($a) { 
                        return isset($a['exame_especial']) && $a['exame_especial'] >= 10; 
                    }));
                    echo $aprovados_exame;
                    ?>
                </div>
                <div class="stat-label">Aprovados no Exame</div>
            </div>
        </div>
        
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
                        $disciplina_cor = '#1e5799';
                        foreach ($disciplinas as $d) {
                            if ($d['id'] == $disciplina_id) {
                                $disciplina_nome = $d['nome'];
                                $disciplina_cor = $d['cor'] ?? '#1e5799';
                                break;
                            }
                        }
                        echo htmlspecialchars($disciplina_nome);
                        ?>
                        <span class="disciplina-badge" style="background: <?php echo $disciplina_cor; ?>20; color: <?php echo $disciplina_cor; ?>; border: 1px solid <?php echo $disciplina_cor; ?>;">
                            <?php echo htmlspecialchars($disciplina_id); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">🎯 Alunos</div>
                    <div class="info-value"><?php echo $total_exame_final; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Exame Final -->
        <div class="card">
            <div class="card-header">
                📝 Lançar Notas do Exame Final (Exame Especial)
            </div>
            <div class="card-body">
                <?php if (empty($alunos_exame_final)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🎓</div>
                        <p>Nenhum aluno para exame final nesta disciplina/turma.</p>
                        <p style="font-size: 12px; margin-top: 10px;">
                            Todos os alunos estão aprovados ou já realizaram o exame final.
                        </p>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="formExameFinal">
                        <input type="hidden" name="action" value="salvar">
                        
                        <table class="table-exame">
                            <thead>
                                <tr>
                                    <th width="25%">Aluno</th>
                                    <th width="10%">Matrícula</th>
                                    <th width="12%">Média Original</th>
                                    <th width="12%">Status Original</th>
                                    <th width="15%">
                                        Nota Exame Final (Especial)
                                        <div class="nota-info">(0-20)</div>
                                    </th>
                                    <th width="12%">Nova Média</th>
                                    <th width="12%">Novo Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos_exame_final as $aluno): 
                                    $media_original = $aluno['media_final'];
                                    $status_original = $aluno['status'];
                                    $media_parcial = $aluno['media_parcial'];
                                    $exame_especial_atual = $aluno['exame_especial'];
                                    
                                    // Determinar classe da nota original
                                    if ($media_original >= 7 && $media_original < 10) {
                                        $nota_class = 'nota-media';
                                    } else {
                                        $nota_class = 'nota-baixa';
                                    }
                                    
                                    // Status original
                                    $status_icon_orig = $status_original == 'recuperacao' ? '⚠️' : '❌';
                                    $status_text_orig = $status_original == 'recuperacao' ? 'Recuperação' : 'Reprovado';
                                    $status_class_orig = $status_original == 'recuperacao' ? 'status-recuperacao' : 'status-reprovado';
                                ?>
                                    <tr>
                                        <td class="aluno-nome">
                                            👨‍🎓 <?php echo htmlspecialchars($aluno['estudante_nome']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        <td>
                                            <span class="nota-original <?php echo $nota_class; ?>">
                                                <?php echo number_format($media_original, 1); ?>
                                            </span>
                                            <?php if ($media_parcial): ?>
                                                <br><small>Parcial: <?php echo number_format($media_parcial, 1); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class_orig; ?>">
                                                <?php echo $status_icon_orig . ' ' . $status_text_orig; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="number" step="0.1" 
                                                   min="0" max="20" 
                                                   name="notas[<?php echo $aluno['nota_id']; ?>][exame_especial]" 
                                                   class="nota-input" 
                                                   value="<?php echo $exame_especial_atual ? number_format($exame_especial_atual, 1) : ''; ?>"
                                                   placeholder="0-20"
                                                   onchange="calcularNovaMedia(this, <?php echo $aluno['nota_id']; ?>)">
                                        </td>
                                        <td class="media-cell" id="nova_media_<?php echo $aluno['nota_id']; ?>">
                                            <?php echo $exame_especial_atual ? number_format($exame_especial_atual, 1) : '-'; ?>
                                        </td>
                                        <td id="novo_status_<?php echo $aluno['nota_id']; ?>">
                                            <?php if ($exame_especial_atual): ?>
                                                <?php 
                                                if ($exame_especial_atual >= 10) {
                                                    echo '<span class="status-badge status-aprovado">✅ Aprovado</span>';
                                                } elseif ($exame_especial_atual >= 7) {
                                                    echo '<span class="status-badge status-recuperacao">⚠️ Recuperação</span>';
                                                } else {
                                                    echo '<span class="status-badge status-reprovado">❌ Reprovado</span>';
                                                }
                                                ?>
                                            <?php else: ?>
                                                <span class="status-badge status-reprovado">❌ Reprovado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn-salvar">
                                💾 Salvar Notas do Exame Final
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Informações sobre o Exame Final -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                ℹ️ Informações sobre o Exame Final
            </div>
            <div class="card-body" style="padding: 20px;">
                <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                    <li><strong>📌 O que é o Exame Final?</strong> É a última oportunidade para alunos que não conseguiram aprovação durante o ano letivo.</li>
                    <li><strong>👨‍🎓 Quem pode fazer?</strong> Alunos com status "Recuperação" ou "Reprovado" após todas as etapas anteriores.</li>
                    <li><strong>📝 Como funciona?</strong> O aluno realiza um exame final (especial) que substitui todas as notas anteriores.</li>
                    <li><strong>✅ Critério de Aprovação no Exame Final:</strong>
                        <ul style="margin-left: 30px; margin-top: 5px;">
                            <li>Nota ≥ 10 → Aprovado 🎉</li>
                            <li>Nota 7-9 → Permanece em Recuperação (última chance)</li>
                            <li>Nota < 7 → Reprovado ❌</li>
                        </ul>
                    </li>
                    <li><strong>⚠️ Atenção:</strong> A nota do exame final substitui completamente a média final do aluno.</li>
                    <li><strong>📊 Importante:</strong> Após o exame final, o status do aluno é definitivo para o ano letivo.</li>
                </ul>
            </div>
        </div>
        
    <?php elseif ($turma_id > 0 && !$disciplina_id): ?>
        <div class="alert alert-warning">
            ⚠️ Selecione uma disciplina para visualizar os alunos para exame final.
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
</div>

<script>
    function calcularNovaMedia(input, notaId) {
        const notaExame = parseFloat(input.value) || 0;
        const novaMediaCell = document.getElementById(`nova_media_${notaId}`);
        const novoStatusCell = document.getElementById(`novo_status_${notaId}`);
        
        let novaMedia = notaExame;
        let statusText = '';
        let statusClass = '';
        let statusIcon = '';
        
        if (novaMedia >= 10) {
            statusIcon = '✅';
            statusText = 'Aprovado';
            statusClass = 'status-aprovado';
        } else if (novaMedia >= 7) {
            statusIcon = '⚠️';
            statusText = 'Recuperação';
            statusClass = 'status-recuperacao';
        } else if (novaMedia > 0) {
            statusIcon = '❌';
            statusText = 'Reprovado';
            statusClass = 'status-reprovado';
        } else {
            novaMedia = '-';
            statusIcon = '❌';
            statusText = 'Reprovado';
            statusClass = 'status-reprovado';
        }
        
        if (novaMedia !== '-') {
            novaMediaCell.innerHTML = `<strong>${novaMedia.toFixed(1)}</strong>`;
            novaMediaCell.style.background = '#ecf0f1';
            novaMediaCell.style.padding = '4px 8px';
            novaMediaCell.style.borderRadius = '20px';
            novaMediaCell.style.display = 'inline-block';
            
            if (novaMedia >= 10) {
                novaMediaCell.style.color = '#27ae60';
                novaMediaCell.style.background = '#d5f4e6';
            } else if (novaMedia >= 7) {
                novaMediaCell.style.color = '#f39c12';
                novaMediaCell.style.background = '#fef9e7';
            } else {
                novaMediaCell.style.color = '#c0392b';
                novaMediaCell.style.background = '#fadbd8';
            }
        } else {
            novaMediaCell.innerHTML = '-';
        }
        
        novoStatusCell.innerHTML = `
            <span class="status-badge ${statusClass}">
                ${statusIcon} ${statusText}
            </span>
        `;
        
        // Efeito visual no input
        if (notaExame > 0) {
            input.style.borderColor = '#27ae60';
            input.style.backgroundColor = '#d5f4e6';
        } else {
            input.style.borderColor = '#e74c3c';
            input.style.backgroundColor = '#fadbd8';
        }
    }
</script>
</body>
</html>