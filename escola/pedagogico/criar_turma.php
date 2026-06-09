<?php
// escola/pedagogico/cadastrar_turma.php - Cadastrar Nova Turma

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

// Processar formulário
$erro = '';
$sucesso = '';
$dados = [];

// Buscar cursos para o select
$sql_cursos = "SELECT id, nome FROM cursos WHERE status = 1 ORDER BY nome";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute();
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Buscar classes para o select
$sql_classes = "SELECT id, nome, nivel FROM classes WHERE status = 1 ORDER BY nivel, nome";
$stmt_classes = $conn->prepare($sql_classes);
$stmt_classes->execute();
$classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

// Buscar turnos para o select
$sql_turnos = "SELECT id, nome FROM turnos WHERE status = 1 ORDER BY id";
$stmt_turnos = $conn->prepare($sql_turnos);
$stmt_turnos->execute();
$turnos = $stmt_turnos->fetchAll(PDO::FETCH_ASSOC);

// Buscar salas para o select
$sql_salas = "SELECT id, nome, capacidade FROM salas WHERE escola_id = :escola_id AND status = 1 ORDER BY nome";
$stmt_salas = $conn->prepare($sql_salas);
$stmt_salas->execute([':escola_id' => $escola_id]);
$salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos para o select
$sql_anos_letivos = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos_letivos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Processar cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $curso_id = !empty($_POST['curso_id']) ? (int)$_POST['curso_id'] : null;
    $ano = (int)$_POST['ano'] ?? 0;
    $turno_id = (int)$_POST['turno_id'] ?? 0;
    $ano_letivo_id = (int)$_POST['ano_letivo_id'] ?? 0;
    $capacidade = (int)$_POST['capacidade'] ?? 0;
    $sala_id = !empty($_POST['sala_id']) ? (int)$_POST['sala_id'] : null;
    $classe_id = !empty($_POST['classe_id']) ? (int)$_POST['classe_id'] : null;
    $vagas_disponiveis = (int)$_POST['vagas_disponiveis'] ?? 0;
    $horario = trim($_POST['horario'] ?? '');
    $data_inicio = $_POST['data_inicio'] ?? null;
    $data_fim = $_POST['data_fim'] ?? null;
    $status = $_POST['status'] ?? 'ativa';
    
    $erros = [];
    
    if (empty($nome)) $erros[] = "Informe o nome da turma.";
    if ($ano <= 0) $erros[] = "Informe o ano da turma.";
    if ($turno_id <= 0) $erros[] = "Selecione o turno.";
    if ($ano_letivo_id <= 0) $erros[] = "Selecione o ano letivo.";
    
    if (empty($erros)) {
        try {
            // Verificar se já existe turma com mesmo nome no mesmo ano letivo
            $sql_check = "SELECT id FROM turmas WHERE nome = :nome AND escola_id = :escola_id AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':nome' => $nome,
                ':escola_id' => $escola_id,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erros[] = "Já existe uma turma com este nome no ano letivo selecionado.";
            } else {
                $sql_insert = "
                    INSERT INTO turmas (
                        escola_id, nome, curso_id, ano, turno_id, ano_letivo_id,
                        capacidade, sala_id, classe_id, vagas_disponiveis, numero_alunos,
                        horario, data_inicio, data_fim, status, created_at
                    ) VALUES (
                        :escola_id, :nome, :curso_id, :ano, :turno_id, :ano_letivo_id,
                        :capacidade, :sala_id, :classe_id, :vagas_disponiveis, 0,
                        :horario, :data_inicio, :data_fim, :status, NOW()
                    )
                ";
                
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':escola_id' => $escola_id,
                    ':nome' => $nome,
                    ':curso_id' => $curso_id,
                    ':ano' => $ano,
                    ':turno_id' => $turno_id,
                    ':ano_letivo_id' => $ano_letivo_id,
                    ':capacidade' => $capacidade,
                    ':sala_id' => $sala_id,
                    ':classe_id' => $classe_id,
                    ':vagas_disponiveis' => $vagas_disponiveis > 0 ? $vagas_disponiveis : $capacidade,
                    ':horario' => $horario,
                    ':data_inicio' => $data_inicio,
                    ':data_fim' => $data_fim,
                    ':status' => $status
                ]);
                
                $turma_id = $conn->lastInsertId();
                $sucesso = "Turma cadastrada com sucesso!";
                
                // Limpar dados do formulário
                $dados = [];
                
                // Redirecionar após 2 segundos
                header("refresh:2;url=listar_turmas.php");
            }
        } catch (PDOException $e) {
            $erros[] = "Erro ao cadastrar turma: " . $e->getMessage();
        }
    }
    
    if (!empty($erros)) {
        $erro = implode("<br>", $erros);
        // Manter dados preenchidos
        $dados = $_POST;
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Turma - SIGE Angola</title>
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
            min-height: 80px;
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
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
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
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>➕ Cadastrar Nova Turma</h1>
            <p>Preencha os dados abaixo para criar uma nova turma</p>
        </div>
        <div>
            <a href="listar_turmas.php" class="btn-voltar">
                ← Voltar para Lista
            </a>
        </div>
    </div>
    
    <?php if ($sucesso): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($sucesso); ?> Redirecionando...
        </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">
            ❌ <?php echo htmlspecialchars($erro); ?>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            📝 Formulário de Cadastro
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formTurma">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome da Turma <span class="required">*</span></label>
                        <input type="text" name="nome" class="form-control" 
                               placeholder="Ex: Turma A, 10ª Classe A, etc."
                               value="<?php echo htmlspecialchars($dados['nome'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Ano <span class="required">*</span></label>
                        <select name="ano" class="form-control" required>
                            <option value="">Selecione o ano</option>
                            <?php for ($i = 1; $i <= 13; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo (($dados['ano'] ?? '') == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>ª Classe
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Curso</label>
                        <select name="curso_id" class="form-control">
                            <option value="">Selecione o curso (opcional)</option>
                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id']; ?>" 
                                    <?php echo (($dados['curso_id'] ?? '') == $curso['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="info-text">Selecione apenas se for um curso específico</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Classe <span class="required">*</span></label>
                        <select name="classe_id" class="form-control" required>
                            <option value="">Selecione a classe</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>" 
                                    <?php echo (($dados['classe_id'] ?? '') == $classe['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($classe['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Turno <span class="required">*</span></label>
                        <select name="turno_id" class="form-control" required>
                            <option value="">Selecione o turno</option>
                            <?php foreach ($turnos as $turno): ?>
                                <option value="<?php echo $turno['id']; ?>" 
                                    <?php echo (($dados['turno_id'] ?? '') == $turno['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($turno['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Sala</label>
                        <select name="sala_id" class="form-control">
                            <option value="">Selecione a sala (opcional)</option>
                            <?php foreach ($salas as $sala): ?>
                                <option value="<?php echo $sala['id']; ?>" 
                                    <?php echo (($dados['sala_id'] ?? '') == $sala['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sala['nome']); ?> 
                                    (Capacidade: <?php echo $sala['capacidade']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Ano Letivo <span class="required">*</span></label>
                        <select name="ano_letivo_id" class="form-control" required>
                            <option value="">Selecione o ano letivo</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" 
                                    <?php echo (($dados['ano_letivo_id'] ?? '') == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                    <?php if ($ano['data_inicio']): ?>
                                        (<?php echo date('d/m/Y', strtotime($ano['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($ano['data_fim'])); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Capacidade Máxima</label>
                        <input type="number" name="capacidade" class="form-control" 
                               placeholder="Número máximo de alunos"
                               value="<?php echo htmlspecialchars($dados['capacidade'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Vagas Disponíveis</label>
                        <input type="number" name="vagas_disponiveis" class="form-control" 
                               placeholder="Quantidade de vagas disponíveis"
                               value="<?php echo htmlspecialchars($dados['vagas_disponiveis'] ?? ''); ?>">
                        <div class="info-text">Deixe em branco para usar a capacidade máxima</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Horário</label>
                        <input type="text" name="horario" class="form-control" 
                               placeholder="Ex: 07:30 - 12:30"
                               value="<?php echo htmlspecialchars($dados['horario'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data de Início</label>
                        <input type="date" name="data_inicio" class="form-control" 
                               value="<?php echo htmlspecialchars($dados['data_inicio'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Data de Término</label>
                        <input type="date" name="data_fim" class="form-control" 
                               value="<?php echo htmlspecialchars($dados['data_fim'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="ativa" <?php echo (($dados['status'] ?? 'ativa') == 'ativa') ? 'selected' : ''; ?>>Ativa</option>
                        <option value="inativa" <?php echo (($dados['status'] ?? '') == 'inativa') ? 'selected' : ''; ?>>Inativa</option>
                        <option value="concluida" <?php echo (($dados['status'] ?? '') == 'concluida') ? 'selected' : ''; ?>>Concluída</option>
                    </select>
                </div>
                
                <hr>
                
                <div class="alert alert-info">
                    ℹ️ <strong>Informações importantes:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>Os campos com <span class="required">*</span> são obrigatórios</li>
                        <li>O número de alunos será atualizado automaticamente quando houver matrículas</li>
                        <li>A capacidade máxima define o limite de alunos por turma</li>
                        <li>Turmas inativas não podem receber novas matrículas</li>
                    </ul>
                </div>
                
                <div class="btn-group">
                    <button type="button" onclick="window.location.href='listar_turmas.php'" class="btn btn-secondary">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        ✅ Cadastrar Turma
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Validar capacidade e vagas
    document.getElementById('formTurma').addEventListener('submit', function(e) {
        const capacidade = document.querySelector('input[name="capacidade"]').value;
        const vagas = document.querySelector('input[name="vagas_disponiveis"]').value;
        
        if (capacidade && vagas && parseInt(vagas) > parseInt(capacidade)) {
            e.preventDefault();
            alert('Atenção! As vagas disponíveis não podem ser maiores que a capacidade máxima da turma.');
        }
    });
    
    // Auto preencher vagas com capacidade se estiver vazio
    document.querySelector('input[name="capacidade"]').addEventListener('change', function() {
        const vagasInput = document.querySelector('input[name="vagas_disponiveis"]');
        if (!vagasInput.value && this.value) {
            vagasInput.value = this.value;
        }
    });
</script>
</body>
</html>