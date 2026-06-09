<?php
// escola/pedagogico/recuperacao.php - Gerenciar Alunos em Recuperação

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

// Buscar alunos em recuperação
$alunos_recuperacao = [];
if ($turma_id > 0 && $disciplina_id > 0 && $ano_letivo_id > 0) {
    $sql_recuperacao = "
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
        AND n.status = 'recuperacao'
        ORDER BY e.nome ASC
    ";
    $stmt_recuperacao = $conn->prepare($sql_recuperacao);
    $stmt_recuperacao->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_recuperacao = $stmt_recuperacao->fetchAll(PDO::FETCH_ASSOC);
}

// Processar lançamento de notas de recuperação
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar') {
    $salvos = 0;
    $erros = 0;
    
    foreach ($_POST['notas'] as $nota_id => $nota_data) {
        $exame_recurso = !empty($nota_data['exame_recurso']) ? (float)str_replace(',', '.', $nota_data['exame_recurso']) : null;
        
        if ($exame_recurso !== null) {
            try {
                // Buscar a nota atual
                $sql_busca = "SELECT media_parcial, media_final FROM notas WHERE id = :id";
                $stmt_busca = $conn->prepare($sql_busca);
                $stmt_busca->execute([':id' => $nota_id]);
                $nota_atual = $stmt_busca->fetch(PDO::FETCH_ASSOC);
                
                // Calcular nova média final (considerando a nota de recuperação)
                $media_parcial = $nota_atual['media_parcial'];
                $nova_media_final = $exame_recurso;
                
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
                    SET exame_recurso = :exame_recurso,
                        media_final = :media_final,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':exame_recurso' => $exame_recurso,
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
        $mensagem = "✅ Notas de recuperação salvas com sucesso! ($salvos registros)";
        // Recarregar lista
        $stmt_recuperacao->execute([
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $alunos_recuperacao = $stmt_recuperacao->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $erro = "⚠️ Erro ao salvar notas. $salvos salvos, $erros erros.";
    }
}

// Calcular estatísticas
$total_recuperacao = count($alunos_recuperacao);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação - SIGE Angola</title>
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
        
        .table-recuperacao {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-recuperacao th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #f39c12;
            font-size: 12px;
        }
        
        .table-recuperacao td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-recuperacao tr:hover {
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
            border-color: #f39c12;
            box-shadow: 0 0 0 2px rgba(243, 156, 18, 0.1);
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
        
        .status-recuperacao {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .status-aprovado {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .status-reprovado {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .btn-salvar {
            background: linear-gradient(135deg, #f39c12, #e67e22);
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
            
            .table-recuperacao {
                font-size: 11px;
            }
            
            .table-recuperacao th, .table-recuperacao td {
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
            <h1>📝 Recuperação de Alunos</h1>
            <p>Gerencie as notas de exame de recuperação</p>
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
        <!-- Informações -->
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
                            Cód: <?php echo $disciplina_id; ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">📅 Ano Letivo</div>
                    <div class="info-value"><?php echo htmlspecialchars($turma['ano_letivo_ano']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">⚠️ Alunos em Recuperação</div>
                    <div class="info-value"><?php echo $total_recuperacao; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Recuperação -->
        <div class="card">
            <div class="card-header">
                📝 Lançar Notas de Exame de Recuperação
            </div>
            <div class="card-body">
                <?php if (empty($alunos_recuperacao)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <p>Nenhum aluno em recuperação para esta disciplina/turma.</p>
                        <p style="font-size: 12px; margin-top: 10px;">
                            Alunos com média final entre 7 e 9 aparecerão aqui automaticamente.
                        </p>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="formRecuperacao">
                        <input type="hidden" name="action" value="salvar">
                        
                        <table class="table-recuperacao">
                            <thead>
                                <tr>
                                    <th width="25%">Aluno</th>
                                    <th width="10%">Matrícula</th>
                                    <th width="12%">Média Original</th>
                                    <th width="15%">
                                        Nota Exame Recuperação
                                        <div class="nota-info">(0-20)</div>
                                    </th>
                                    <th width="12%">Nova Média</th>
                                    <th width="12%">Novo Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos_recuperacao as $aluno): 
                                    $media_original = $aluno['media_final'];
                                    $media_parcial = $aluno['media_parcial'];
                                    
                                    // Determinar classe da nota original
                                    if ($media_original >= 7 && $media_original < 10) {
                                        $nota_class = 'nota-media';
                                    } else {
                                        $nota_class = 'nota-baixa';
                                    }
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
                                            <input type="number" step="0.1" 
                                                   min="0" max="20" 
                                                   name="notas[<?php echo $aluno['nota_id']; ?>][exame_recurso]" 
                                                   class="nota-input" 
                                                   value=""
                                                   placeholder="0-20"
                                                   onchange="calcularNovaMedia(this, <?php echo $aluno['nota_id']; ?>, <?php echo $media_original; ?>)">
                                        </td>
                                        <td class="media-cell" id="nova_media_<?php echo $aluno['nota_id']; ?>">
                                            -
                                        </td>
                                        <td id="novo_status_<?php echo $aluno['nota_id']; ?>">
                                            <span class="status-badge status-recuperacao">⚠️ Recuperação</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn-salvar">
                                💾 Salvar Notas de Recuperação
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Informações sobre a Recuperação -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                ℹ️ Informações sobre Recuperação
            </div>
            <div class="card-body" style="padding: 20px;">
                <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                    <li><strong>📌 O que é Recuperação?</strong> Alunos com média final entre 7 e 9 têm direito a exame de recuperação.</li>
                    <li><strong>📝 Como funciona?</strong> O aluno faz um exame de recuperação que substitui a média final.</li>
                    <li><strong>✅ Critério de Aprovação na Recuperação:</strong>
                        <ul style="margin-left: 30px; margin-top: 5px;">
                            <li>Nota ≥ 10 → Aprovado</li>
                            <li>Nota 7-9 → Permanece em Recuperação (nova chance)</li>
                            <li>Nota < 7 → Reprovado</li>
                        </ul>
                    </li>
                    <li><strong>⚠️ Atenção:</strong> A nota do exame de recuperação substitui completamente a média final do aluno.</li>
                    <li><strong>📊 Cálculo:</strong> Nova Média Final = Nota do Exame de Recuperação.</li>
                </ul>
            </div>
        </div>
        
    <?php elseif ($turma_id > 0 && !$disciplina_id): ?>
        <div class="alert alert-warning">
            ⚠️ Selecione uma disciplina para visualizar os alunos em recuperação.
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
    function calcularNovaMedia(input, notaId, mediaOriginal) {
        const notaRecuperacao = parseFloat(input.value) || 0;
        const novaMediaCell = document.getElementById(`nova_media_${notaId}`);
        const novoStatusCell = document.getElementById(`novo_status_${notaId}`);
        
        let novaMedia = notaRecuperacao;
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
            statusIcon = '⚠️';
            statusText = 'Recuperação';
            statusClass = 'status-recuperacao';
        }
        
        if (novaMedia !== '-') {
            novaMediaCell.innerHTML = `<strong>${novaMedia.toFixed(1)}</strong>`;
            novaMediaCell.style.background = '#ecf0f1';
            novaMediaCell.style.padding = '4px 8px';
            novaMediaCell.style.borderRadius = '20px';
            
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
        if (notaRecuperacao > 0) {
            input.style.borderColor = '#27ae60';
            input.style.backgroundColor = '#d5f4e6';
        } else {
            input.style.borderColor = '#ddd';
            input.style.backgroundColor = 'white';
        }
    }
</script>
</body>
</html>