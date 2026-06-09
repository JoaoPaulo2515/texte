<?php
// escola/pedagogico/editar_chamada.php - Editar Chamada

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

// Obter parâmetros
$chamada_id = isset($_POST['chamada_id']) ? (int)$_POST['chamada_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$status = isset($_POST['status']) ? $_POST['status'] : '';
$minutos_atraso = isset($_POST['minutos_atraso']) ? (int)$_POST['minutos_atraso'] : 0;
$justificativa = isset($_POST['justificativa']) ? trim($_POST['justificativa']) : '';
$observacao = isset($_POST['observacao']) ? trim($_POST['observacao']) : '';

// Se veio via GET, buscar os dados para exibir no formulário
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $chamada_id > 0) {
    // Buscar dados da chamada para edição
    $sql = "
        SELECT 
            c.*,
            e.nome as estudante_nome,
            e.matricula,
            d.nome as disciplina_nome,
            t.nome as turma_nome,
            t.ano as turma_ano
        FROM chamada c
        INNER JOIN estudantes e ON e.id = c.estudante_id
        INNER JOIN disciplinas d ON d.id = c.disciplina_id
        INNER JOIN turmas t ON t.id = c.turma_id
        WHERE c.id = :id AND c.escola_id = :escola_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $chamada_id, ':escola_id' => $escola_id]);
    $chamada = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$chamada) {
        die('Chamada não encontrada.');
    }
    
    // Buscar turmas para o select
    $sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':escola_id' => $escola_id]);
    $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas para o select
    $sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':escola_id' => $escola_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar alunos para o select
    $sql_alunos = "
        SELECT e.id, e.nome, e.matricula 
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $chamada['turma_id']]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar anos letivos
    $sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
    $stmt_anos = $conn->prepare($sql_anos);
    $stmt_anos->execute();
    $anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);
    
    $status_texto = [
        'presente' => 'Presente',
        'falta' => 'Falta',
        'atrasado' => 'Atrasado'
    ];
    ?>
    
    <!DOCTYPE html>
    <html lang="pt">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Editar Chamada - SIGE Angola</title>
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
                max-width: 800px;
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
            
            .btn-success {
                background: linear-gradient(135deg, #27ae60, #2ecc71);
                color: white;
            }
            
            .btn-success:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
            
            .info-text {
                font-size: 12px;
                color: #7f8c8d;
                margin-top: 5px;
            }
            
            .badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: bold;
            }
            
            .badge-presente {
                background: #d5f4e6;
                color: #27ae60;
            }
            
            .badge-falta {
                background: #fadbd8;
                color: #c0392b;
            }
            
            .badge-atrasado {
                background: #fef9e7;
                color: #f39c12;
            }
            
            .info-bar {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .info-row {
                display: flex;
                margin-bottom: 8px;
            }
            
            .info-label {
                width: 120px;
                font-weight: 600;
                color: #7f8c8d;
            }
            
            .info-value {
                flex: 1;
                color: #2c3e50;
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
                <h1>✏️ Editar Chamada</h1>
                <p>Altere os dados da chamada</p>
            </div>
            <div>
                <a href="lista_chamada.php" class="btn-voltar">
                    ← Voltar para Lista
                </a>
            </div>
        </div>
        
        <!-- Informações Atuais -->
        <div class="info-bar">
            <div class="info-row">
                <div class="info-label">Aluno:</div>
                <div class="info-value"><?php echo htmlspecialchars($chamada['estudante_nome']); ?> (<?php echo htmlspecialchars($chamada['matricula']); ?>)</div>
            </div>
            <div class="info-row">
                <div class="info-label">Disciplina:</div>
                <div class="info-value"><?php echo htmlspecialchars($chamada['disciplina_nome']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Turma:</div>
                <div class="info-value"><?php echo htmlspecialchars($chamada['turma_nome']); ?> - <?php echo $chamada['turma_ano']; ?>ª</div>
            </div>
            <div class="info-row">
                <div class="info-label">Data da Aula:</div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($chamada['data_aula'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status Atual:</div>
                <div class="info-value">
                    <span class="badge badge-<?php echo $chamada['status']; ?>">
                        <?php echo $status_texto[$chamada['status']]; ?>
                    </span>
                </div>
            </div>
            <?php if ($chamada['minutos_atraso'] > 0): ?>
            <div class="info-row">
                <div class="info-label">Atraso:</div>
                <div class="info-value"><?php echo $chamada['minutos_atraso']; ?> minutos</div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <div class="card-header">
                📝 Formulário de Edição
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="chamada_id" value="<?php echo $chamada_id; ?>">
                    
                    <div class="form-group">
                        <label>Status <span class="required">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="presente" <?php echo ($chamada['status'] == 'presente') ? 'selected' : ''; ?>>✅ Presente</option>
                            <option value="falta" <?php echo ($chamada['status'] == 'falta') ? 'selected' : ''; ?>>❌ Falta</option>
                            <option value="atrasado" <?php echo ($chamada['status'] == 'atrasado') ? 'selected' : ''; ?>>⏰ Atrasado</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="grupo_atraso" style="<?php echo ($chamada['status'] != 'atrasado') ? 'display: none;' : ''; ?>">
                        <label>Minutos de Atraso</label>
                        <input type="number" name="minutos_atraso" class="form-control" value="<?php echo $chamada['minutos_atraso']; ?>" min="0" max="180">
                        <div class="info-text">Número de minutos de atraso do aluno</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Justificativa</label>
                        <textarea name="justificativa" class="form-control" rows="3" placeholder="Justificativa da falta/atraso..."><?php echo htmlspecialchars($chamada['justificativa'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Observação</label>
                        <textarea name="observacao" class="form-control" rows="2" placeholder="Observações adicionais..."><?php echo htmlspecialchars($chamada['observacao'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="btn-group">
                        <button type="button" onclick="window.location.href='lista_chamada.php'" class="btn btn-secondary">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-success">
                            💾 Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Mostrar/esconder campo de atraso conforme o status selecionado
        const statusSelect = document.querySelector('select[name="status"]');
        const grupoAtraso = document.getElementById('grupo_atraso');
        
        statusSelect.addEventListener('change', function() {
            if (this.value === 'atrasado') {
                grupoAtraso.style.display = 'block';
            } else {
                grupoAtraso.style.display = 'none';
                document.querySelector('input[name="minutos_atraso"]').value = 0;
            }
        });
    </script>
    </body>
    </html>
    
    <?php
    exit;
}

// Processar atualização via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chamada_id = (int)$_POST['chamada_id'];
    $status = $_POST['status'];
    $minutos_atraso = isset($_POST['minutos_atraso']) ? (int)$_POST['minutos_atraso'] : 0;
    $justificativa = isset($_POST['justificativa']) ? trim($_POST['justificativa']) : '';
    $observacao = isset($_POST['observacao']) ? trim($_POST['observacao']) : '';
    
    $erro = '';
    $sucesso = '';
    
    if ($chamada_id <= 0) {
        $erro = "ID da chamada inválido.";
    } elseif (!in_array($status, ['presente', 'falta', 'atrasado'])) {
        $erro = "Status inválido.";
    } else {
        try {
            // Se não for atrasado, zerar minutos de atraso
            if ($status != 'atrasado') {
                $minutos_atraso = 0;
            }
            
            $sql = "
                UPDATE chamada 
                SET status = :status,
                    minutos_atraso = :minutos_atraso,
                    justificativa = :justificativa,
                    observacao = :observacao,
                    updated_at = NOW()
                WHERE id = :id AND escola_id = :escola_id
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':minutos_atraso' => $minutos_atraso,
                ':justificativa' => $justificativa,
                ':observacao' => $observacao,
                ':id' => $chamada_id,
                ':escola_id' => $escola_id
            ]);
            
            $sucesso = "Chamada atualizada com sucesso!";
            
            // Redirecionar com mensagem de sucesso
            header("Location: lista_chamada.php?msg=sucesso");
            exit;
            
        } catch (PDOException $e) {
            $erro = "Erro ao atualizar chamada: " . $e->getMessage();
        }
    }
    
    // Se houve erro, redirecionar de volta com mensagem de erro
    if ($erro) {
        header("Location: lista_chamada.php?msg=erro&error=" . urlencode($erro));
        exit;
    }
}
?>