<?php
// escola/pedagogico/atribuir_disciplinas.php - Atribuir Disciplinas à Turma

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
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

if ($turma_id <= 0) {
    header('Location: listar_turmas.php');
    exit;
}

// Buscar dados da turma
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

if (!$turma) {
    header('Location: listar_turmas.php');
    exit;
}

// Buscar disciplinas já atribuídas à turma
$sql_disciplinas_atribuidas = "
    SELECT 
        dt.id,
        dt.disciplina_id,
        dt.ano_letivo_id,
        d.nome,
        d.codigo,
        d.carga_horaria
    FROM disciplina_turma dt
    INNER JOIN disciplinas d ON d.id = dt.disciplina_id
    WHERE dt.turma_id = :turma_id
    ORDER BY d.nome ASC
";
$stmt_atribuidas = $conn->prepare($sql_disciplinas_atribuidas);
$stmt_atribuidas->execute([':turma_id' => $turma_id]);
$disciplinas_atribuidas = $stmt_atribuidas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas disponíveis (não atribuídas à turma)
$sql_disciplinas_disponiveis = "
    SELECT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria,
        d.curso_id,
        c.nome as curso_nome
    FROM disciplinas d
    LEFT JOIN cursos c ON c.id = d.curso_id
    WHERE d.escola_id = :escola_id 
    AND d.status = 1
    AND d.id NOT IN (
        SELECT disciplina_id FROM disciplina_turma WHERE turma_id = :turma_id
    )
    ORDER BY d.nome ASC
";
$stmt_disponiveis = $conn->prepare($sql_disciplinas_disponiveis);
$stmt_disponiveis->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id
]);
$disciplinas_disponiveis = $stmt_disponiveis->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Processar formulário
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'atribuir') {
        $disciplina_id = (int)$_POST['disciplina_id'];
        $ano_letivo_id = (int)$_POST['ano_letivo_id'];
        
        if ($disciplina_id <= 0) {
            $erro = "Selecione uma disciplina.";
        } elseif ($ano_letivo_id <= 0) {
            $erro = "Selecione o ano letivo.";
        } else {
            // Verificar se já está atribuída
            $sql_check = "SELECT id FROM disciplina_turma WHERE turma_id = :turma_id AND disciplina_id = :disciplina_id AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erro = "Esta disciplina já está atribuída à turma no ano letivo selecionado.";
            } else {
                try {
                    $sql_insert = "
                        INSERT INTO disciplina_turma (turma_id, disciplina_id, ano_letivo_id, created_at)
                        VALUES (:turma_id, :disciplina_id, :ano_letivo_id, NOW())
                    ";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':turma_id' => $turma_id,
                        ':disciplina_id' => $disciplina_id,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    
                    $mensagem = "Disciplina atribuída à turma com sucesso!";
                    
                    // Recarregar listas
                    $stmt_atribuidas->execute([':turma_id' => $turma_id]);
                    $disciplinas_atribuidas = $stmt_atribuidas->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt_disponiveis->execute([
                        ':escola_id' => $escola_id,
                        ':turma_id' => $turma_id
                    ]);
                    $disciplinas_disponiveis = $stmt_disponiveis->fetchAll(PDO::FETCH_ASSOC);
                    
                } catch (PDOException $e) {
                    $erro = "Erro ao atribuir disciplina: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'remover') {
        $disciplina_turma_id = (int)$_POST['disciplina_turma_id'];
        
        try {
            $sql_delete = "DELETE FROM disciplina_turma WHERE id = :id AND turma_id = :turma_id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->execute([
                ':id' => $disciplina_turma_id,
                ':turma_id' => $turma_id
            ]);
            
            $mensagem = "Disciplina removida da turma com sucesso!";
            
            // Recarregar listas
            $stmt_atribuidas->execute([':turma_id' => $turma_id]);
            $disciplinas_atribuidas = $stmt_atribuidas->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt_disponiveis->execute([
                ':escola_id' => $escola_id,
                ':turma_id' => $turma_id
            ]);
            $disciplinas_disponiveis = $stmt_disponiveis->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $erro = "Erro ao remover disciplina: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atribuir Disciplinas - <?php echo htmlspecialchars($turma['nome']); ?> - SIGE Angola</title>
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
            max-width: 1200px;
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
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
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
        
        .badge-info {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .badge-success {
            background: #d5f4e6;
            color: #27ae60;
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
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
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
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📚 Atribuir Disciplinas</h1>
            <p>Gerencie as disciplinas da turma <?php echo htmlspecialchars($turma['nome']); ?></p>
        </div>
        <div>
            <a href="detalhes_turma.php?id=<?php echo $turma_id; ?>" class="btn-voltar">
                ← Voltar para Turma
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
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
            <div class="info-item">
                <div class="info-label">📖 Disciplinas</div>
                <div class="info-value"><?php echo count($disciplinas_atribuidas); ?></div>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <!-- Coluna 1: Disciplinas Disponíveis -->
        <div class="card">
            <div class="card-header">
                ➕ Disciplinas Disponíveis
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="atribuir">
                    
                    <div class="form-group">
                        <label>Selecione a Disciplina <span class="required">*</span></label>
                        <select name="disciplina_id" class="form-control" required>
                            <option value="">Selecione uma disciplina</option>
                            <?php foreach ($disciplinas_disponiveis as $disciplina): ?>
                                <option value="<?php echo $disciplina['id']; ?>">
                                    <?php echo htmlspecialchars($disciplina['nome']); ?> 
                                    (<?php echo htmlspecialchars($disciplina['codigo']); ?>)
                                    <?php if ($disciplina['curso_nome']): ?>
                                        - <?php echo htmlspecialchars($disciplina['curso_nome']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($disciplinas_disponiveis)): ?>
                            <div class="alert alert-warning" style="margin-top: 10px;">
                                ℹ️ Nenhuma disciplina disponível para atribuir.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Ano Letivo <span class="required">*</span></label>
                        <select name="ano_letivo_id" class="form-control" required>
                            <option value="">Selecione o ano letivo</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($turma['ano_letivo_id'] == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="btn-group" style="margin-top: 15px;">
                        <button type="submit" class="btn btn-success">✅ Atribuir Disciplina</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Coluna 2: Disciplinas Atribuídas -->
        <div class="card">
            <div class="card-header">
                📋 Disciplinas Atribuídas
            </div>
            <div class="card-body">
                <?php if (empty($disciplinas_atribuidas)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📖</div>
                        <p>Nenhuma disciplina atribuída a esta turma.</p>
                        <p style="font-size: 12px;">Utilize o formulário ao lado para atribuir disciplinas.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Disciplina</th>
                                    <th>Código</th>
                                    <th>Carga Horária</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($disciplinas_atribuidas as $disciplina): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($disciplina['codigo']); ?></td>
                                        <td>
                                            <?php echo $disciplina['carga_horaria'] ? $disciplina['carga_horaria'] . 'h' : '---'; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja remover esta disciplina da turma?')">
                                                <input type="hidden" name="action" value="remover">
                                                <input type="hidden" name="disciplina_turma_id" value="<?php echo $disciplina['id']; ?>">
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
    
    <!-- Informações Adicionais -->
    <div class="card">
        <div class="card-header">
            ℹ️ Informações Importantes
        </div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li>As disciplinas atribuídas à turma serão utilizadas para:</li>
                <ul style="margin-left: 30px;">
                    <li>Lançamento de notas e frequências</li>
                    <li>Composição do horário de aulas</li>
                    <li>Atribuição de professores</li>
                    <li>Geração de pautas e boletins</li>
                </ul>
                <li>Cada disciplina pode ser atribuída a múltiplas turmas.</li>
                <li>Remover uma disciplina não afeta o histórico de notas já lançadas.</li>
                <li>Certifique-se de que todas as disciplinas do currículo estão atribuídas.</li>
                <li>Após atribuir as disciplinas, você pode:</li>
                <ul style="margin-left: 30px;">
                    <li><a href="horario_turma.php?turma_id=<?php echo $turma_id; ?>">Definir o horário de aulas</a></li>
                    <li><a href="atribuir_professor.php?turma_id=<?php echo $turma_id; ?>">Atribuir professores às disciplinas</a></li>
                </ul>
            </ul>
        </div>
    </div>
</div>
</body>
</html>