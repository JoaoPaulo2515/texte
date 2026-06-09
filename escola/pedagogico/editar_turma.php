<?php
// escola/pedagogico/editar_turma.php - Editar Turma

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
$turma_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;


// Se não tem ID, redirecionar para a lista
if ($turma_id <= 0) {
    header('Location: listar_turmas.php');
    exit;
}
// Buscar dados da turma
$sql_turma = "
    SELECT 
        t.*,
        c.nome as curso_nome,
        cl.nome as classe_nome,
        tr.nome as turno_nome,
        s.nome as sala_nome,
        al.ano as ano_letivo_ano
    FROM turmas t
    LEFT JOIN cursos c ON c.id = t.curso_id
    LEFT JOIN classes cl ON cl.id = t.classe_id
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

if (!$turma) {
    die('Turma não encontrada ou não pertence à sua escola.');
}

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

// Processar atualização
$erro = '';
$sucesso = '';

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
            // Verificar se já existe outra turma com mesmo nome no mesmo ano letivo
            $sql_check = "SELECT id FROM turmas WHERE nome = :nome AND escola_id = :escola_id AND ano_letivo_id = :ano_letivo_id AND id != :turma_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':nome' => $nome,
                ':escola_id' => $escola_id,
                ':ano_letivo_id' => $ano_letivo_id,
                ':turma_id' => $turma_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erros[] = "Já existe outra turma com este nome no ano letivo selecionado.";
            } else {
                $sql_update = "
                    UPDATE turmas SET
                        nome = :nome,
                        curso_id = :curso_id,
                        ano = :ano,
                        turno_id = :turno_id,
                        ano_letivo_id = :ano_letivo_id,
                        capacidade = :capacidade,
                        sala_id = :sala_id,
                        classe_id = :classe_id,
                        vagas_disponiveis = :vagas_disponiveis,
                        horario = :horario,
                        data_inicio = :data_inicio,
                        data_fim = :data_fim,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :turma_id
                ";
                
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
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
                    ':status' => $status,
                    ':turma_id' => $turma_id
                ]);
                
                $sucesso = "Turma atualizada com sucesso!";
                
                // Atualizar os dados da turma para exibir
                $turma['nome'] = $nome;
                $turma['curso_id'] = $curso_id;
                $turma['ano'] = $ano;
                $turma['turno_id'] = $turno_id;
                $turma['ano_letivo_id'] = $ano_letivo_id;
                $turma['capacidade'] = $capacidade;
                $turma['sala_id'] = $sala_id;
                $turma['classe_id'] = $classe_id;
                $turma['vagas_disponiveis'] = $vagas_disponiveis;
                $turma['horario'] = $horario;
                $turma['data_inicio'] = $data_inicio;
                $turma['data_fim'] = $data_fim;
                $turma['status'] = $status;
                
                // Redirecionar após 2 segundos
                header("refresh:2;url=listar_turmas.php");
            }
        } catch (PDOException $e) {
            $erros[] = "Erro ao atualizar turma: " . $e->getMessage();
        }
    }
    
    if (!empty($erros)) {
        $erro = implode("<br>", $erros);
    }
}

// Buscar número de alunos matriculados
$sql_alunos = "
    SELECT 
        COUNT(*) as total_alunos,
        SUM(CASE WHEN status = 'ativa' THEN 1 ELSE 0 END) as ativos
    FROM matriculas 
    WHERE turma_id = :turma_id
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos_info = $stmt_alunos->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Turma - SIGE Angola</title>
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
        
        .info-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
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
            <h1>✏️ Editar Turma</h1>
            <p>Atualize os dados da turma</p>
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
    
    <!-- Informações da Turma -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-number"><?php echo $alunos_info['total_alunos'] ?? 0; ?></div>
            <div class="stat-label">Total de Alunos</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $alunos_info['ativos'] ?? 0; ?></div>
            <div class="stat-label">Alunos Ativos</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $turma['vagas_disponiveis'] ?? 0; ?></div>
            <div class="stat-label">Vagas Disponíveis</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $turma['ano_letivo_ano'] ?? '-'; ?></div>
            <div class="stat-label">Ano Letivo</div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            📝 Editar Turma: <?php echo htmlspecialchars($turma['nome']); ?>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formTurma">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome da Turma <span class="required">*</span></label>
                        <input type="text" name="nome" class="form-control" 
                               placeholder="Ex: Turma A, 10ª Classe A, etc."
                               value="<?php echo htmlspecialchars($turma['nome']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Ano <span class="required">*</span></label>
                        <select name="ano" class="form-control" required>
                            <option value="">Selecione o ano</option>
                            <?php for ($i = 1; $i <= 13; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($turma['ano'] == $i) ? 'selected' : ''; ?>>
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
                                    <?php echo ($turma['curso_id'] == $curso['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Classe <span class="required">*</span></label>
                        <select name="classe_id" class="form-control" required>
                            <option value="">Selecione a classe</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>" 
                                    <?php echo ($turma['classe_id'] == $classe['id']) ? 'selected' : ''; ?>>
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
                                    <?php echo ($turma['turno_id'] == $turno['id']) ? 'selected' : ''; ?>>
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
                                    <?php echo ($turma['sala_id'] == $sala['id']) ? 'selected' : ''; ?>>
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
                                    <?php echo ($turma['ano_letivo_id'] == $ano['id']) ? 'selected' : ''; ?>>
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
                               value="<?php echo htmlspecialchars($turma['capacidade'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Vagas Disponíveis</label>
                        <input type="number" name="vagas_disponiveis" class="form-control" 
                               placeholder="Quantidade de vagas disponíveis"
                               value="<?php echo htmlspecialchars($turma['vagas_disponiveis'] ?? ''); ?>">
                        <div class="info-text">Deixe em branco para usar a capacidade máxima</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Horário</label>
                        <input type="text" name="horario" class="form-control" 
                               placeholder="Ex: 07:30 - 12:30"
                               value="<?php echo htmlspecialchars($turma['horario'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data de Início</label>
                        <input type="date" name="data_inicio" class="form-control" 
                               value="<?php echo htmlspecialchars($turma['data_inicio'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Data de Término</label>
                        <input type="date" name="data_fim" class="form-control" 
                               value="<?php echo htmlspecialchars($turma['data_fim'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="ativa" <?php echo ($turma['status'] == 'ativa') ? 'selected' : ''; ?>>Ativa</option>
                        <option value="inativa" <?php echo ($turma['status'] == 'inativa') ? 'selected' : ''; ?>>Inativa</option>
                        <option value="concluida" <?php echo ($turma['status'] == 'concluida') ? 'selected' : ''; ?>>Concluída</option>
                    </select>
                </div>
                
                <hr>
                
                <div class="alert alert-info">
                    ℹ️ <strong>Informações importantes:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>Os campos com <span class="required">*</span> são obrigatórios</li>
                        <li>Alterar o ano letivo pode afetar a visualização das matrículas</li>
                        <li>A capacidade máxima não pode ser menor que o número atual de alunos</li>
                        <li>Turmas inativas não podem receber novas matrículas</li>
                    </ul>
                </div>
                
                <?php if ($alunos_info['total_alunos'] > 0): ?>
                    <div class="alert alert-warning">
                        ⚠️ <strong>Atenção:</strong> Esta turma possui <?php echo $alunos_info['total_alunos']; ?> alunos matriculados. 
                        Alterar a capacidade para um valor menor que o número de alunos pode causar problemas.
                    </div>
                <?php endif; ?>
                
                <div class="btn-group">
                    <button type="button" onclick="window.location.href='excluir_turma.php?id=<?php echo $turma_id; ?>'" class="btn btn-danger">
                        🗑️ Excluir Turma
                    </button>
                    <button type="button" onclick="window.location.href='listar_turmas.php'" class="btn btn-secondary">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Alterações
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
        const alunosAtivos = <?php echo $alunos_info['ativos'] ?? 0; ?>;
        
        if (capacidade && parseInt(capacidade) < alunosAtivos) {
            e.preventDefault();
            alert(`Atenção! A capacidade máxima (${capacidade}) não pode ser menor que o número atual de alunos ativos (${alunosAtivos}).`);
        }
        
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