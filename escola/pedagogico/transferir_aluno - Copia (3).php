<?php
// escola/pedagogico/transferir_aluno.php - Transferência de Aluno

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

// Buscar listas para os selects
$sql_turmas = "SELECT id, nome, ano, turno, sala FROM turmas WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

$sql_anos_letivos = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos_letivos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

$sql_motivos = "SELECT id, nome, descricao FROM motivos_transferencia WHERE ativo = 1 ORDER BY nome";
$stmt_motivos = $conn->prepare($sql_motivos);
$stmt_motivos->execute();
$motivos_transferencia = $stmt_motivos->fetchAll(PDO::FETCH_ASSOC);

// Buscar aluno se ID foi fornecido
$aluno_selecionado = null;
$matricula_ativa = null;
$estudante_id = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : (isset($_POST['estudante_id']) ? (int)$_POST['estudante_id'] : 0);

if ($estudante_id > 0) {
    $sql_aluno = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.telefone,
            e.email,
            e.endereco,
            e.naturalidade,
            e.nacionalidade,
            e.pai_nome,
            e.mae_nome,
            e.encarregado_nome,
            e.encarregado_telefone,
            e.encarregado_email,
            m.id as matricula_id,
            m.numero_processo,
            m.data_matricula,
            m.ano_letivo as ano_letivo_id,
            al.ano as ano_letivo_ano,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano,
            t.turno,
            t.sala
        FROM estudantes e
        LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
        LEFT JOIN ano_letivo al ON al.id = m.ano_letivo
        LEFT JOIN turmas t ON t.id = m.turma_id
        WHERE e.id = :estudante_id AND e.escola_id = :escola_id
    ";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([':estudante_id' => $estudante_id, ':escola_id' => $escola_id]);
    $aluno_selecionado = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_selecionado && $aluno_selecionado['matricula_id']) {
        $matricula_ativa = $aluno_selecionado;
    }
}

// Processar transferência
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transferir') {
    $estudante_id = (int)$_POST['estudante_id'];
    $matricula_id = (int)$_POST['matricula_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $motivo_id = (int)$_POST['motivo_id'];
    $data_transferencia = $_POST['data_transferencia'];
    $turma_destino_id = !empty($_POST['turma_destino_id']) ? (int)$_POST['turma_destino_id'] : null;
    $turma_destino_nome = $_POST['turma_destino_nome'] ?? null;
    $escola_destino = trim($_POST['escola_destino']);
    $escola_destino_endereco = trim($_POST['escola_destino_endereco'] ?? '');
    $escola_destino_telefone = trim($_POST['escola_destino_telefone'] ?? '');
    $escola_destino_email = trim($_POST['escola_destino_email'] ?? '');
    $observacoes = trim($_POST['observacoes']);
    
    $erros = [];
    
    if ($estudante_id <= 0) $erros[] = "Aluno não selecionado.";
    if ($matricula_id <= 0) $erros[] = "Matrícula ativa não encontrada.";
    if ($ano_letivo_id <= 0) $erros[] = "Selecione o ano letivo.";
    if ($motivo_id <= 0) $erros[] = "Selecione o motivo da transferência.";
    if (empty($data_transferencia)) $erros[] = "Informe a data da transferência.";
    if (empty($escola_destino)) $erros[] = "Informe o nome da escola de destino.";
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            // Gerar número de processo de transferência
            $ano_atual = date('Y');
            $sql_num = "SELECT COUNT(*) as total FROM transferencias WHERE YEAR(data_transferencia) = :ano";
            $stmt_num = $conn->prepare($sql_num);
            $stmt_num->execute([':ano' => $ano_atual]);
            $total = $stmt_num->fetch(PDO::FETCH_ASSOC);
            $num_processo = 'TRF-' . $ano_atual . '-' . str_pad($total['total'] + 1, 4, '0', STR_PAD_LEFT);
            
            // Inserir transferência
            $sql_insert = "
                INSERT INTO transferencias (
                    estudante_id, matricula_id, ano_letivo_id, motivo_id, data_transferencia, 
                    escola_destino, escola_destino_endereco, escola_destino_telefone, escola_destino_email,
                    turma_destino_id, turma_destino_nome, observacoes, 
                    numero_processo, status, created_by, created_at
                ) VALUES (
                    :estudante_id, :matricula_id, :ano_letivo_id, :motivo_id, :data_transferencia,
                    :escola_destino, :escola_destino_endereco, :escola_destino_telefone, :escola_destino_email,
                    :turma_destino_id, :turma_destino_nome, :observacoes,
                    :numero_processo, 'pendente', :created_by, NOW()
                )
            ";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->execute([
                ':estudante_id' => $estudante_id,
                ':matricula_id' => $matricula_id,
                ':ano_letivo_id' => $ano_letivo_id,
                ':motivo_id' => $motivo_id,
                ':data_transferencia' => $data_transferencia,
                ':escola_destino' => $escola_destino,
                ':escola_destino_endereco' => $escola_destino_endereco,
                ':escola_destino_telefone' => $escola_destino_telefone,
                ':escola_destino_email' => $escola_destino_email,
                ':turma_destino_id' => $turma_destino_id,
                ':turma_destino_nome' => $turma_destino_nome,
                ':observacoes' => $observacoes,
                ':numero_processo' => $num_processo,
                ':created_by' => $usuario['id']
            ]);
            
            $transferencia_id = $conn->lastInsertId();
            
            // Atualizar status da matrícula para 'transferida'
            $sql_update = "UPDATE matriculas SET status = 'transferido', data_cancelamento = NOW() WHERE id = :matricula_id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':matricula_id' => $matricula_id]);
            
            $conn->commit();
            
            $sucesso = "Transferência registrada com sucesso! Nº Processo: " . $num_processo;
            
            // Limpar aluno selecionado
            $aluno_selecionado = null;
            $matricula_ativa = null;
            $estudante_id = 0;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $erro = "Erro ao registrar transferência: " . $e->getMessage();
        }
    } else {
        $erro = implode("<br>", $erros);
    }
}

// Buscar transferências recentes
$sql_transferencias = "
    SELECT 
        t.id,
        t.numero_processo,
        t.data_transferencia,
        t.escola_destino,
        t.status,
        t.observacoes,
        e.nome as estudante_nome,
        e.matricula as estudante_matricula,
        mt.nome as motivo_nome,
        al.ano as ano_letivo
    FROM transferencias t
    INNER JOIN estudantes e ON e.id = t.estudante_id
    INNER JOIN motivos_transferencia mt ON mt.id = t.motivo_id
    INNER JOIN anos_letivos al ON al.id = t.ano_letivo_id
    WHERE e.escola_id = :escola_id
    ORDER BY t.created_at DESC
    LIMIT 20
";
$stmt_transferencias = $conn->prepare($sql_transferencias);
$stmt_transferencias->execute([':escola_id' => $escola_id]);
$transferencias = $stmt_transferencias->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferência de Aluno - SIGE Angola</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
        }
        
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        
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
        
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 15px; }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .form-group label .required { color: #e74c3c; }
        
        .form-control {
            width: 100%;
            padding: 10px;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .btn-primary { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
        
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success { background: #d5f4e6; color: #27ae60; border-left: 4px solid #27ae60; }
        .alert-danger { background: #fadbd8; color: #c0392b; border-left: 4px solid #c0392b; }
        .alert-info { background: #d4e6f1; color: #1e5799; border-left: 4px solid #1e5799; }
        .alert-warning { background: #fef9e7; color: #f39c12; border-left: 4px solid #f39c12; }
        
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
        }
        
        .table tr:hover { background: #f8f9fa; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-pendente { background: #fef9e7; color: #f39c12; }
        .status-aprovado { background: #d5f4e6; color: #27ae60; }
        .status-concluido { background: #d4e6f1; color: #1e5799; }
        .status-rejeitado { background: #fadbd8; color: #c0392b; }
        
        .aluno-info {
            background: linear-gradient(135deg, #e8f4f8, #d4e6f1);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #b8d4e3;
        }
        
        .aluno-info h3 {
            color: #1e5799;
            margin-bottom: 15px;
            border-bottom: 2px solid #1e5799;
            padding-bottom: 8px;
        }
        
        .aluno-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }
        
        .aluno-detail-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            border-left: 3px solid #1e5799;
        }
        
        .aluno-detail-item strong {
            color: #1e5799;
            display: block;
            margin-bottom: 5px;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .aluno-detail-item span {
            font-size: 13px;
            color: #2c3e50;
        }
        
        .search-box { margin-bottom: 20px; }
        
        .search-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .aluno-item {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .aluno-item:hover {
            background: #f8f9fa;
            border-color: #1e5799;
            transform: translateX(5px);
        }
        
        .aluno-nome { font-weight: bold; color: #2c3e50; }
        .aluno-matricula { font-size: 12px; color: #7f8c8d; }
        
        .required-field::after {
            content: " *";
            color: #e74c3c;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; justify-content: center; }
            .form-row { grid-template-columns: 1fr; }
            .table { font-size: 12px; }
            .table th, .table td { padding: 8px; }
            .aluno-details { grid-template-columns: 1fr; }
        }
        
        @media print {
            .btn-group, .search-box, .card:last-child { display: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📋 Transferência de Aluno</h1>
        <p>Registre a transferência de alunos para outras instituições de ensino</p>
    </div>
    
    <?php if ($sucesso): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($sucesso); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <!-- Formulário de Transferência -->
    <div class="card">
        <div class="card-header">
            📝 Registrar Nova Transferência
        </div>
        <div class="card-body">
            <form method="GET" action="" id="formBuscar">
                <div class="search-box">
                    <label for="searchAluno">🔍 Buscar Aluno</label>
                    <input type="text" id="searchAluno" class="search-input" 
                           placeholder="Digite o nome, matrícula ou BI do aluno..." autocomplete="off">
                    <input type="hidden" name="estudante_id" id="estudante_id" value="<?php echo $estudante_id; ?>">
                    <div id="resultadosBusca" style="margin-top: 10px;"></div>
                </div>
            </form>
            
            <?php if ($aluno_selecionado): ?>
                <div class="aluno-info">
                    <h3>🎓 DADOS DO ALUNO SELECIONADO</h3>
                    <div class="aluno-details">
                        <div class="aluno-detail-item">
                            <strong>📌 Identificação</strong>
                            <span><strong>Nome:</strong> <?php echo strtoupper(htmlspecialchars($aluno_selecionado['nome'])); ?></span>
                            <span><strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno_selecionado['matricula']); ?></span>
                            <span><strong>BI:</strong> <?php echo htmlspecialchars($aluno_selecionado['bi'] ?? '---'); ?></span>
                        </div>
                        <div class="aluno-detail-item">
                            <strong>🎂 Dados Pessoais</strong>
                            <span><strong>Data Nasc.:</strong> <?php echo date('d/m/Y', strtotime($aluno_selecionado['data_nascimento'])); ?></span>
                            <span><strong>Naturalidade:</strong> <?php echo htmlspecialchars($aluno_selecionado['naturalidade'] ?? '---'); ?></span>
                            <span><strong>Nacionalidade:</strong> <?php echo htmlspecialchars($aluno_selecionado['nacionalidade'] ?? '---'); ?></span>
                        </div>
                        <div class="aluno-detail-item">
                            <strong>📞 Contato</strong>
                            <span><strong>Telefone:</strong> <?php echo htmlspecialchars($aluno_selecionado['telefone'] ?? '---'); ?></span>
                            <span><strong>Email:</strong> <?php echo htmlspecialchars($aluno_selecionado['email'] ?? '---'); ?></span>
                            <span><strong>Endereço:</strong> <?php echo htmlspecialchars($aluno_selecionado['endereco'] ?? '---'); ?></span>
                        </div>
                        <?php if ($matricula_ativa): ?>
                        <div class="aluno-detail-item">
                            <strong>🏫 Dados da Matrícula Atual</strong>
                            <span><strong>Turma:</strong> <?php echo ($matricula_ativa['turma_ano'] ? $matricula_ativa['turma_ano'] . 'ª ' : '') . htmlspecialchars($matricula_ativa['turma_nome']); ?></span>
                            <span><strong>Turno:</strong> <?php echo ucfirst($matricula_ativa['turno']); ?></span>
                            <span><strong>Sala:</strong> <?php echo $matricula_ativa['sala']; ?></span>
                            <span><strong>Ano Letivo:</strong> <?php echo htmlspecialchars($matricula_ativa['ano_letivo_ano']); ?></span>
                            <span><strong>Data Matrícula:</strong> <?php echo date('d/m/Y', strtotime($matricula_ativa['data_matricula'])); ?></span>
                        </div>
                        <div class="aluno-detail-item">
                            <strong>👨‍👩‍👧 Encarregado</strong>
                            <span><strong>Nome:</strong> <?php echo htmlspecialchars($aluno_selecionado['encarregado_nome'] ?? '---'); ?></span>
                            <span><strong>Telefone:</strong> <?php echo htmlspecialchars($aluno_selecionado['encarregado_telefone'] ?? '---'); ?></span>
                            <span><strong>Email:</strong> <?php echo htmlspecialchars($aluno_selecionado['encarregado_email'] ?? '---'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <form method="POST" action="" id="formTransferencia">
                    <input type="hidden" name="action" value="transferir">
                    <input type="hidden" name="estudante_id" value="<?php echo $aluno_selecionado['id']; ?>">
                    <input type="hidden" name="matricula_id" value="<?php echo $matricula_ativa['matricula_id']; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Ano Letivo <span class="required">*</span></label>
                            <select name="ano_letivo_id" class="form-control" required>
                                <option value="">Selecione o ano letivo</option>
                                <?php foreach ($anos_letivos as $ano): ?>
                                    <option value="<?php echo $ano['id']; ?>" 
                                        <?php echo ($matricula_ativa && $matricula_ativa['ano_letivo_id'] == $ano['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ano['ano']); ?>
                                        <?php if ($ano['data_inicio'] && $ano['data_fim']): ?>
                                            (<?php echo date('d/m/Y', strtotime($ano['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($ano['data_fim'])); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="required-field">Data da Transferência <span class="required">*</span></label>
                            <input type="date" name="data_transferencia" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Motivo da Transferência <span class="required">*</span></label>
                            <select name="motivo_id" class="form-control" required>
                                <option value="">Selecione o motivo</option>
                                <?php foreach ($motivos_transferencia as $motivo): ?>
                                    <option value="<?php echo $motivo['id']; ?>"><?php echo htmlspecialchars($motivo['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Turma de Destino (opcional)</label>
                            <select name="turma_destino_id" class="form-control" id="turma_destino_id">
                                <option value="">Selecione a turma de destino (opcional)</option>
                                <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>">
                                        <?php echo ($turma['ano'] ? $turma['ano'] . 'ª ' : '') . htmlspecialchars($turma['nome']) . ' - ' . ucfirst($turma['turno']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="turma_destino_nome" id="turma_destino_nome">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="required-field">Escola de Destino <span class="required">*</span></label>
                        <input type="text" name="escola_destino" class="form-control" 
                               placeholder="Nome da escola para onde o aluno está transferindo" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Endereço da Escola Destino</label>
                            <input type="text" name="escola_destino_endereco" class="form-control" 
                                   placeholder="Endereço da escola destino">
                        </div>
                        <div class="form-group">
                            <label>Telefone da Escola Destino</label>
                            <input type="text" name="escola_destino_telefone" class="form-control" 
                                   placeholder="Telefone da escola destino">
                        </div>
                        <div class="form-group">
                            <label>Email da Escola Destino</label>
                            <input type="email" name="escola_destino_email" class="form-control" 
                                   placeholder="Email da escola destino">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="3" 
                                  placeholder="Informações adicionais sobre a transferência..."></textarea>
                    </div>
                    
                    <div class="btn-group">
                        <button type="button" onclick="window.location.href='transferir_aluno.php'" class="btn btn-secondary">
                            🔄 Cancelar
                        </button>
                        <button type="submit" class="btn btn-success">
                            ✅ Registrar Transferência
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Lista de Transferências Recentes -->
    <div class="card">
        <div class="card-header">
            📋 Transferências Recentes
        </div>
        <div class="card-body">
            <?php if (empty($transferencias)): ?>
                <div class="alert alert-info">
                    ℹ️ Nenhuma transferência registrada ainda.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nº Processo</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Ano Letivo</th>
                                <th>Data</th>
                                <th>Escola Destino</th>
                                <th>Motivo</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transferencias as $transf): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($transf['numero_processo']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($transf['estudante_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($transf['estudante_matricula']); ?></td>
                                    <td><?php echo htmlspecialchars($transf['ano_letivo']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($transf['data_transferencia'])); ?></td>
                                    <td><?php echo htmlspecialchars($transf['escola_destino']); ?></td>
                                    <td><?php echo htmlspecialchars($transf['motivo_nome']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $transf['status']; ?>">
                                            <?php 
                                                $status_text = [
                                                    'pendente' => 'PENDENTE',
                                                    'aprovado' => 'APROVADO',
                                                    'concluido' => 'CONCLUÍDO',
                                                    'rejeitado' => 'REJEITADO'
                                                ];
                                                echo $status_text[$transf['status']] ?? strtoupper($transf['status']);
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Informações Adicionais -->
    <div class="card">
        <div class="card-header">
            ℹ️ Informações Importantes
        </div>
        <div class="card-body">
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li><strong>📌 A transferência encerra automaticamente a matrícula ativa do aluno nesta instituição.</strong></li>
                <li><strong>📚 Todo o histórico acadêmico do aluno é mantido no sistema para consulta futura.</strong></li>
                <li><strong>📄 Um documento de transferência é gerado automaticamente após o registro.</strong></li>
                <li><strong>⚠️ O aluno só poderá ser transferido se possuir matrícula ativa.</strong></li>
                <li><strong>🚫 Após a transferência, o aluno não poderá mais ser vinculado a turmas nesta escola.</strong></li>
                <li><strong>📅 O ano letivo deve corresponder ao ano da matrícula que está sendo transferida.</strong></li>
                <li><strong>🔐 O código de autenticação gerado serve para validação do documento de transferência.</strong></li>
            </ul>
        </div>
    </div>
</div>

<script>
    // Busca de alunos
    const searchInput = document.getElementById('searchAluno');
    const resultadosDiv = document.getElementById('resultadosBusca');
    const estudanteIdField = document.getElementById('estudante_id');
    
    let timeoutId;
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(timeoutId);
            const termo = this.value.trim();
            
            if (termo.length < 2) {
                resultadosDiv.innerHTML = '';
                return;
            }
            
            resultadosDiv.innerHTML = '<div class="alert alert-info">🔍 Buscando alunos...</div>';
            
            timeoutId = setTimeout(() => {
                fetch(`buscar_alunos.php?termo=${encodeURIComponent(termo)}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            resultadosDiv.innerHTML = `<div class="alert alert-danger">❌ ${data.error}</div>`;
                            return;
                        }
                        
                        if (!data || data.length === 0) {
                            resultadosDiv.innerHTML = '<div class="alert alert-warning">⚠️ Nenhum aluno encontrado.</div>';
                            return;
                        }
                        
                        let html = '<div style="max-height: 400px; overflow-y: auto;">';
                        data.forEach(aluno => {
                            const temMatriculaAtiva = aluno.matricula_id && aluno.matricula_status === 'ativa';
                            const statusIcon = temMatriculaAtiva ? '✅' : '❌';
                            const statusText = temMatriculaAtiva ? 'Matrícula Ativa' : 'Sem Matrícula Ativa';
                            
                            html += `
                                <div class="aluno-item" onclick="selecionarAluno(${aluno.id})" style="${!temMatriculaAtiva ? 'opacity: 0.6;' : ''}">
                                    <div class="aluno-nome">${statusIcon} ${escapeHtml(aluno.nome)}</div>
                                    <div class="aluno-matricula">
                                        Matrícula: ${escapeHtml(aluno.matricula || '---')} | 
                                        BI: ${escapeHtml(aluno.bi || '---')}<br>
                                        <small>${statusText}</small>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        resultadosDiv.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        resultadosDiv.innerHTML = `<div class="alert alert-danger">❌ Erro ao buscar alunos: ${error.message}</div>`;
                    });
            }, 500);
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function selecionarAluno(id) {
        window.location.href = `transferir_aluno.php?estudante_id=${id}`;
    }
    
    // Atualizar nome da turma destino
    const turmaSelect = document.getElementById('turma_destino_id');
    if (turmaSelect) {
        turmaSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const turmaNomeInput = document.getElementById('turma_destino_nome');
            if (turmaNomeInput) {
                if (selectedOption.value) {
                    turmaNomeInput.value = selectedOption.text;
                } else {
                    turmaNomeInput.value = '';
                }
            }
        });
    }
    
    // Se já tem aluno selecionado, mostrar nos resultados
    <?php if ($estudante_id > 0 && $aluno_selecionado): ?>
    if (searchInput) {
        searchInput.value = '<?php echo addslashes($aluno_selecionado['nome']); ?>';
    }
    <?php endif; ?>
</script>
</body>
</html>