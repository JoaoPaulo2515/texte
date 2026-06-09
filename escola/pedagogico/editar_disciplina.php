<?php
// escola/pedagogico/editar_disciplina.php - Editar Disciplina

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
$disciplina_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($disciplina_id <= 0) {
    header('Location: listar_disciplinas.php');
    exit;
}

// Buscar dados da disciplina
$sql_disciplina = "
    SELECT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria,
        d.descricao,
        d.cor,
        d.status,
        d.curso_id,
        d.escola_id,
        c.nome as curso_nome
    FROM disciplinas d
    LEFT JOIN cursos c ON c.id = d.curso_id
    WHERE d.id = :disciplina_id AND d.escola_id = :escola_id
";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([
    ':disciplina_id' => $disciplina_id,
    ':escola_id' => $escola_id
]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    header('Location: listar_disciplinas.php');
    exit;
}

// Buscar cursos para o select
$sql_cursos = "SELECT id, nome FROM cursos WHERE status = 1 ORDER BY nome";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute();
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Processar atualização
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $codigo = trim($_POST['codigo'] ?? '');
    $curso_id = !empty($_POST['curso_id']) ? (int)$_POST['curso_id'] : null;
    $carga_horaria = (int)$_POST['carga_horaria'] ?? 0;
    $descricao = trim($_POST['descricao'] ?? '');
    $cor = trim($_POST['cor'] ?? '#1e5799');
    $status = $_POST['status'] ?? '1';
    
    $erros = [];
    
    if (empty($nome)) $erros[] = "Informe o nome da disciplina.";
    if (empty($codigo)) $erros[] = "Informe o código da disciplina.";
    
    if (empty($erros)) {
        try {
            // Verificar se já existe outra disciplina com mesmo código ou nome
            $sql_check = "
                SELECT id FROM disciplinas 
                WHERE (codigo = :codigo OR nome = :nome) 
                AND id != :disciplina_id 
                AND escola_id = :escola_id
            ";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':codigo' => $codigo,
                ':nome' => $nome,
                ':disciplina_id' => $disciplina_id,
                ':escola_id' => $escola_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erros[] = "Já existe uma disciplina com este código ou nome.";
            } else {
                $sql_update = "
                    UPDATE disciplinas 
                    SET nome = :nome,
                        codigo = :codigo,
                        curso_id = :curso_id,
                        carga_horaria = :carga_horaria,
                        descricao = :descricao,
                        cor = :cor,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :disciplina_id
                ";
                
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':nome' => $nome,
                    ':codigo' => $codigo,
                    ':curso_id' => $curso_id,
                    ':carga_horaria' => $carga_horaria,
                    ':descricao' => $descricao,
                    ':cor' => $cor,
                    ':status' => $status,
                    ':disciplina_id' => $disciplina_id
                ]);
                
                $mensagem = "Disciplina atualizada com sucesso!";
                
                // Atualizar os dados da disciplina
                $disciplina['nome'] = $nome;
                $disciplina['codigo'] = $codigo;
                $disciplina['curso_id'] = $curso_id;
                $disciplina['carga_horaria'] = $carga_horaria;
                $disciplina['descricao'] = $descricao;
                $disciplina['cor'] = $cor;
                $disciplina['status'] = $status;
                
                // Redirecionar após 2 segundos
                header("refresh:2;url=listar_disciplinas.php");
            }
        } catch (PDOException $e) {
            $erros[] = "Erro ao atualizar disciplina: " . $e->getMessage();
        }
    }
    
    if (!empty($erros)) {
        $erro = implode("<br>", $erros);
    }
}

// Buscar estatísticas de uso da disciplina
$sql_estatisticas = "
    SELECT 
        COUNT(DISTINCT dt.turma_id) as total_turmas,
        COUNT(DISTINCT pdt.professor_id) as total_professores,
        COUNT(DISTINCT n.id) as total_notas
    FROM disciplinas d
    LEFT JOIN disciplina_turma dt ON dt.disciplina_id = d.id
    LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
    LEFT JOIN notas n ON n.disciplina_id = d.id
    WHERE d.id = :disciplina_id
    GROUP BY d.id
";
$stmt_estatisticas = $conn->prepare($sql_estatisticas);
$stmt_estatisticas->execute([':disciplina_id' => $disciplina_id]);
$estatisticas = $stmt_estatisticas->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Disciplina - <?php echo htmlspecialchars($disciplina['nome']); ?> - SIGE Angola</title>
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
            max-width: 900px;
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
            padding: 15px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .form-group label .required {
            color: #e74c3c;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
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
        
        select.form-control {
            cursor: pointer;
            background: white;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 25px;
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
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            color: white;
        }
        
        .btn-danger:hover {
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
        
        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-box {
            flex: 1;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #1e5799;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1e5799;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .cor-preview {
            width: 50px;
            height: 36px;
            border-radius: 6px;
            border: 1px solid #ddd;
            margin-top: 5px;
        }
        
        .info-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #ecf0f1;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>✏️ Editar Disciplina</h1>
            <p>Atualize os dados da disciplina: <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong></p>
        </div>
        <div>
            <a href="listar_disciplinas.php" class="btn-voltar">
                ← Voltar para Lista
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($mensagem); ?> Redirecionando...
        </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">
            ❌ <?php echo htmlspecialchars($erro); ?>
        </div>
    <?php endif; ?>
    
    <!-- Estatísticas de Uso -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-number"><?php echo $estatisticas['total_turmas'] ?? 0; ?></div>
            <div class="stat-label">Turmas</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $estatisticas['total_professores'] ?? 0; ?></div>
            <div class="stat-label">Professores</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $estatisticas['total_notas'] ?? 0; ?></div>
            <div class="stat-label">Notas Lançadas</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $disciplina['codigo']; ?></div>
            <div class="stat-label">Código</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            📝 Formulário de Edição
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formDisciplina">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome da Disciplina <span class="required">*</span></label>
                        <input type="text" name="nome" class="form-control" 
                               placeholder="Ex: Matemática, Português, Física..."
                               value="<?php echo htmlspecialchars($disciplina['nome']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Código <span class="required">*</span></label>
                        <input type="text" name="codigo" class="form-control" 
                               placeholder="Ex: MAT, POR, FIS..."
                               value="<?php echo htmlspecialchars($disciplina['codigo']); ?>" required>
                        <div class="info-text">Código único da disciplina</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Curso</label>
                        <select name="curso_id" class="form-control">
                            <option value="">Selecione o curso (opcional)</option>
                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id']; ?>" 
                                    <?php echo ($disciplina['curso_id'] == $curso['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="info-text">Curso ao qual esta disciplina pertence</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Carga Horária (horas)</label>
                        <input type="number" name="carga_horaria" class="form-control" 
                               placeholder="Ex: 96"
                               value="<?php echo htmlspecialchars($disciplina['carga_horaria']); ?>">
                        <div class="info-text">Carga horária total da disciplina no ano letivo</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Cor da Disciplina</label>
                        <input type="color" name="cor" class="form-control" 
                               value="<?php echo htmlspecialchars($disciplina['cor'] ?? '#1e5799'); ?>">
                        <div class="info-text">Cor utilizada para identificação visual</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="ativo" <?php echo ($disciplina['status'] == '1') ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo ($disciplina['status'] == '2') ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="descricao" class="form-control" 
                              placeholder="Descreva a disciplina, seus objetivos e conteúdos programáticos..."><?php echo htmlspecialchars($disciplina['descricao'] ?? ''); ?></textarea>
                    <div class="info-text">Descrição detalhada da disciplina (opcional)</div>
                </div>
                
                <hr>
                
                <?php if (($estatisticas['total_turmas'] ?? 0) > 0 || ($estatisticas['total_professores'] ?? 0) > 0): ?>
                    <div class="alert alert-warning">
                        ⚠️ <strong>Atenção:</strong> Esta disciplina está sendo utilizada em:
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <?php if (($estatisticas['total_turmas'] ?? 0) > 0): ?>
                                <li><?php echo $estatisticas['total_turmas']; ?> turma(s)</li>
                            <?php endif; ?>
                            <?php if (($estatisticas['total_professores'] ?? 0) > 0): ?>
                                <li><?php echo $estatisticas['total_professores']; ?> professor(es)</li>
                            <?php endif; ?>
                            <?php if (($estatisticas['total_notas'] ?? 0) > 0): ?>
                                <li><?php echo $estatisticas['total_notas']; ?> nota(s) lançada(s)</li>
                            <?php endif; ?>
                        </ul>
                        Alterar o código ou desativar a disciplina pode afetar os registros existentes.
                    </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <button type="button" onclick="if(confirm('Tem certeza que deseja excluir esta disciplina?')) window.location.href='excluir_disciplina.php?id=<?php echo $disciplina_id; ?>'" class="btn btn-danger">
                        🗑️ Excluir Disciplina
                    </button>
                    <button type="button" onclick="window.location.href='listar_disciplinas.php'" class="btn btn-secondary">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Informações Adicionais -->
    <div class="card">
        <div class="card-header">
            ℹ️ Informações Importantes
        </div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li>O código da disciplina deve ser único na escola.</li>
                <li>Ao desativar uma disciplina, ela não poderá ser atribuída a novas turmas.</li>
                <li>Disciplinas já atribuídas a turmas mantêm seu histórico mesmo após desativação.</li>
                <li>A cor da disciplina é utilizada na interface para facilitar a identificação.</li>
                <li>A carga horária deve corresponder à carga total da disciplina no ano letivo.</li>
                <li>Após editar, você pode:</li>
                <ul style="margin-left: 30px;">
                    <li><a href="atribuir_disciplinas.php?turma_id=<?php echo $disciplina_id; ?>">Atribuir a turmas</a></li>
                    <li><a href="atribuir_professor.php?disciplina_id=<?php echo $disciplina_id; ?>">Atribuir professores</a></li>
                </ul>
            </ul>
        </div>
    </div>
</div>

<script>
    // Visualizar cor selecionada
    const corInput = document.querySelector('input[name="cor"]');
    if (corInput) {
        const preview = document.createElement('div');
        preview.className = 'cor-preview';
        preview.style.backgroundColor = corInput.value;
        corInput.parentNode.appendChild(preview);
        
        corInput.addEventListener('input', function() {
            preview.style.backgroundColor = this.value;
        });
    }
</script>
</body>
</html>