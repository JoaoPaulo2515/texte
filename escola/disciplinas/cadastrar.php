<?php
// escola/disciplinas/cadastrar.php - Cadastro de Disciplinas com Associações
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y');

$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'cadastro';

// Buscar professores para associação
$professores = $conn->prepare("
    SELECT p.id, u.nome, p.especialidade 
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id AND p.status = 'ativo'
    ORDER BY u.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas para associação
$turmas = $conn->prepare("
    SELECT id, nome, ano, turno 
    FROM turmas 
    WHERE escola_id = :escola_id AND status = 'ativa' AND ano_letivo = :ano
    ORDER BY nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Processar cadastro de nova disciplina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'cadastrar_disciplina') {
    $nome = $_POST['nome'] ?? '';
    $codigo = $_POST['codigo'] ?? '';
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    $descricao = $_POST['descricao'] ?? '';
    
    if (empty($nome)) {
        $error = "Preencha o nome da disciplina.";
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO disciplinas (escola_id, nome, codigo, carga_horaria, descricao, status, created_at)
                VALUES (:escola_id, :nome, :codigo, :carga_horaria, :descricao, 'ativa', NOW())
            ");
            
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':codigo' => $codigo ?: null,
                ':carga_horaria' => $carga_horaria ?: null,
                ':descricao' => $descricao ?: null
            ]);
            
            $disciplina_id = $conn->lastInsertId();
            $success = "Disciplina cadastrada com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'cadastrar_disciplina', 'disciplinas', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $disciplina_id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            $active_tab = 'associacoes';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Processar associação de professor a disciplina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'associar_professor') {
    $disciplina_id = $_POST['disciplina_id'] ?? 0;
    $professor_id = $_POST['professor_id'] ?? 0;
    $tipo = $_POST['tipo'] ?? 'titular';
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    
    if (!$disciplina_id || !$professor_id) {
        $error = "Selecione a disciplina e o professor.";
    } else {
        try {
            // Verificar quantos professores já estão associados a esta disciplina
            $stmt = $conn->prepare("
                SELECT COUNT(*) as total, tipo 
                FROM alocacoes 
                WHERE disciplina_id = :disciplina_id AND turma_id IS NULL AND ano_letivo = :ano
            ");
            $stmt->execute([':disciplina_id' => $disciplina_id, ':ano' => $ano_letivo]);
            $associacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_associacoes = count($associacoes);
            
            if ($total_associacoes >= 2) {
                throw new Exception("Esta disciplina já possui 2 professores associados (máximo permitido).");
            }
            
            // Verificar se o professor já está associado a esta disciplina
            $stmt = $conn->prepare("
                SELECT id FROM alocacoes 
                WHERE disciplina_id = :disciplina_id AND professor_id = :professor_id AND turma_id IS NULL AND ano_letivo = :ano
            ");
            $stmt->execute([
                ':disciplina_id' => $disciplina_id,
                ':professor_id' => $professor_id,
                ':ano' => $ano_letivo
            ]);
            
            if ($stmt->fetch()) {
                throw new Exception("Este professor já está associado a esta disciplina.");
            }
            
            $stmt = $conn->prepare("
                INSERT INTO alocacoes (professor_id, disciplina_id, ano_letivo, created_at)
                VALUES (:professor_id, :disciplina_id, :ano, NOW())
            ");
            
            $stmt->execute([
                ':professor_id' => $professor_id,
                ':disciplina_id' => $disciplina_id,
                ':ano' => $ano_letivo
            ]);
            
            $success = "Professor associado à disciplina com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'associar_professor_disciplina', 'alocacoes', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $conn->lastInsertId(),
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Processar associação de turma (alocação completa)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'associar_turma') {
    $disciplina_id = $_POST['disciplina_id'] ?? 0;
    $turma_id = $_POST['turma_id'] ?? 0;
    $professor_titular_id = $_POST['professor_titular_id'] ?? 0;
    $professor_auxiliar_id = $_POST['professor_auxiliar_id'] ?? 0;
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    
    if (!$disciplina_id || !$turma_id || !$professor_titular_id) {
        $error = "Selecione a disciplina, turma e o professor titular.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Verificar se já existe alocação para esta turma e disciplina
            $stmt = $conn->prepare("
                SELECT id FROM alocacoes 
                WHERE disciplina_id = :disciplina_id AND turma_id = :turma_id AND ano_letivo = :ano
            ");
            $stmt->execute([
                ':disciplina_id' => $disciplina_id,
                ':turma_id' => $turma_id,
                ':ano' => $ano_letivo
            ]);
            
            if ($stmt->fetch()) {
                throw new Exception("Esta disciplina já está alocada para esta turma.");
            }
            
            // Associar professor titular
            $stmt = $conn->prepare("
                INSERT INTO alocacoes (professor_id, disciplina_id, turma_id, ano_letivo, created_at)
                VALUES (:professor_id, :disciplina_id, :turma_id, :ano, NOW())
            ");
            $stmt->execute([
                ':professor_id' => $professor_titular_id,
                ':disciplina_id' => $disciplina_id,
                ':turma_id' => $turma_id,
                ':ano' => $ano_letivo
            ]);
            
            // Associar professor auxiliar se selecionado
            if ($professor_auxiliar_id && $professor_auxiliar_id != $professor_titular_id) {
                $stmt = $conn->prepare("
                    INSERT INTO alocacoes (professor_id, disciplina_id, turma_id, ano_letivo, created_at)
                    VALUES (:professor_id, :disciplina_id, :turma_id, :ano, NOW())
                ");
                $stmt->execute([
                    ':professor_id' => $professor_auxiliar_id,
                    ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id,
                    ':ano' => $ano_letivo
                ]);
            }
            
            $conn->commit();
            $success = "Disciplina alocada à turma com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'associar_turma_disciplina', 'alocacoes', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $conn->lastInsertId(),
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Buscar disciplinas para os selects
$disciplinas = $conn->prepare("
    SELECT id, nome, codigo FROM disciplinas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY nome
");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar associações existentes
$associacoes_professores = $conn->prepare("
    SELECT a.id, d.nome as disciplina_nome, u.nome as professor_nome, p.especialidade, a.ano_letivo
    FROM alocacoes a
    JOIN disciplinas d ON d.id = a.disciplina_id
    JOIN professores p ON p.id = a.professor_id
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE d.escola_id = :escola_id AND a.turma_id IS NULL
    ORDER BY d.nome, u.nome
");
$associacoes_professores->execute([':escola_id' => $escola_id]);
$associacoes_professores = $associacoes_professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar alocações por turma
$alocacoes_turmas = $conn->prepare("
    SELECT a.id, d.nome as disciplina_nome, t.nome as turma_nome, 
           u_titular.nome as professor_titular,
           u_auxiliar.nome as professor_auxiliar,
           a.ano_letivo
    FROM alocacoes a
    JOIN disciplinas d ON d.id = a.disciplina_id
    JOIN turmas t ON t.id = a.turma_id
    JOIN professores p_titular ON p_titular.id = a.professor_id
    JOIN usuarios u_titular ON u_titular.id = p_titular.usuario_id
    LEFT JOIN alocacoes a2 ON a2.disciplina_id = a.disciplina_id AND a2.turma_id = a.turma_id AND a2.id != a.id
    LEFT JOIN professores p_auxiliar ON p_auxiliar.id = a2.professor_id
    LEFT JOIN usuarios u_auxiliar ON u_auxiliar.id = p_auxiliar.usuario_id
    WHERE d.escola_id = :escola_id AND a.turma_id IS NOT NULL
    GROUP BY a.disciplina_id, a.turma_id
    ORDER BY t.nome, d.nome
");
$alocacoes_turmas->execute([':escola_id' => $escola_id]);
$alocacoes_turmas = $alocacoes_turmas->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disciplinas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .table-associacoes { font-size: 14px; }
        .badge-titular { background: #28a745; }
        .badge-auxiliar { background: #ffc107; color: #333; }
        .wizard-step { display: none; }
        .wizard-step.active { display: block; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .step { flex: 1; text-align: center; position: relative; }
        .step .circle { width: 40px; height: 40px; background: #e0e0e0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; color: #666; }
        .step.active .circle { background: #006B3E; color: white; }
        .step.completed .circle { background: #28a745; color: white; }
        .step .label { margin-top: 10px; font-size: 12px; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-book"></i> Disciplinas</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="disciplinaTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'cadastro' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#cadastro" type="button" role="tab">
                            <i class="fas fa-plus"></i> Cadastrar Disciplina
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'associacoes' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#associacoes" type="button" role="tab">
                            <i class="fas fa-chalkboard-user"></i> Professores por Disciplina
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'turmas' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#turmas" type="button" role="tab">
                            <i class="fas fa-users-group"></i> Disciplinas por Turma
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <div class="tab-content">
                    <!-- Aba 1: Cadastrar Disciplina -->
                    <div class="tab-pane fade <?php echo $active_tab == 'cadastro' ? 'show active' : ''; ?>" id="cadastro" role="tabpanel">
                        <div class="step-indicator" id="stepIndicator">
                            <div class="step active" id="step1">
                                <div class="circle">1</div>
                                <div class="label">Dados da Disciplina</div>
                            </div>
                            <div class="step" id="step2">
                                <div class="circle">2</div>
                                <div class="label">Associar Professores</div>
                            </div>
                            <div class="step" id="step3">
                                <div class="circle">3</div>
                                <div class="label">Alocar às Turmas</div>
                            </div>
                        </div>
                        
                        <form method="POST" id="wizardForm">
                            <input type="hidden" name="acao" value="cadastrar_disciplina">
                            <input type="hidden" name="disciplina_id" id="disciplina_id" value="">
                            
                            <!-- Passo 1: Dados da Disciplina -->
                            <div id="step1Content" class="wizard-step active">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="required">Nome da Disciplina</label>
                                            <input type="text" name="nome" id="nome_disciplina" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label>Código</label>
                                            <input type="text" name="codigo" class="form-control" placeholder="Ex: MAT101">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label>Carga Horária (h/semana)</label>
                                            <input type="number" name="carga_horaria" class="form-control" value="4">
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Descrição</label>
                                    <textarea name="descricao" class="form-control" rows="3" placeholder="Ementa da disciplina..."></textarea>
                                </div>
                                <div class="text-end">
                                    <button type="button" class="btn btn-primary" onclick="nextStep()">Próximo <i class="fas fa-arrow-right"></i></button>
                                </div>
                            </div>
                            
                            <!-- Passo 2: Associar Professores -->
                            <div id="step2Content" class="wizard-step">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Uma disciplina pode ter no máximo 2 professores (Titular e Auxiliar).
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Professor Titular</label>
                                            <select name="professor_titular" id="professor_titular" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($professores as $prof): ?>
                                                <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?> - <?php echo htmlspecialchars($prof['especialidade'] ?? 'Professor'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Professor Auxiliar (Opcional)</label>
                                            <select name="professor_auxiliar" id="professor_auxiliar" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($professores as $prof): ?>
                                                <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?> - <?php echo htmlspecialchars($prof['especialidade'] ?? 'Professor'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end mt-3">
                                    <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Anterior</button>
                                    <button type="button" class="btn btn-primary" onclick="nextStep()">Próximo <i class="fas fa-arrow-right"></i></button>
                                </div>
                            </div>
                            
                            <!-- Passo 3: Alocar às Turmas -->
                            <div id="step3Content" class="wizard-step">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Selecione as turmas que receberão esta disciplina.
                                </div>
                                <div class="mb-3">
                                    <label>Ano Letivo</label>
                                    <select name="ano_letivo" id="ano_letivo_alocacao" class="form-control">
                                        <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Turmas</label>
                                    <div class="row" id="turmas_list">
                                        <?php foreach ($turmas as $turma): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input type="checkbox" name="turmas[]" value="<?php echo $turma['id']; ?>" class="form-check-input" id="turma_<?php echo $turma['id']; ?>">
                                                <label class="form-check-label" for="turma_<?php echo $turma['id']; ?>">
                                                    <?php echo htmlspecialchars($turma['nome']); ?> (<?php echo $turma['ano']; ?> - <?php echo ucfirst($turma['turno']); ?>)
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="text-end mt-3">
                                    <button type="button" class="btn btn-secondary" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Anterior</button>
                                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Finalizar Cadastro</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Aba 2: Professores por Disciplina -->
                    <div class="tab-pane fade <?php echo $active_tab == 'associacoes' ? 'show active' : ''; ?>" id="associacoes" role="tabpanel">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <form method="POST" class="row g-2">
                                    <input type="hidden" name="acao" value="associar_professor">
                                    <div class="col-md-5">
                                        <select name="disciplina_id" class="form-control" required>
                                            <option value="">Selecione a Disciplina</option>
                                            <?php foreach ($disciplinas as $disc): ?>
                                            <option value="<?php echo $disc['id']; ?>"><?php echo htmlspecialchars($disc['nome']); ?> (<?php echo $disc['codigo'] ?? 'Sem código'; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="professor_id" class="form-control" required>
                                            <option value="">Selecione o Professor</option>
                                            <?php foreach ($professores as $prof): ?>
                                            <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-primary w-100">Associar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover table-associacoes">
                                <thead>
                                    <tr><th>Disciplina</th><th>Professor</th><th>Especialidade</th><th>Ano Letivo</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($associacoes_professores as $assoc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($assoc['disciplina_nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($assoc['professor_nome']); ?></td>
                                        <td><?php echo htmlspecialchars($assoc['especialidade'] ?? '-'); ?></td>
                                        <td><?php echo $assoc['ano_letivo']; ?></td>
                                        <td>
                                            <a href="excluir_associacao.php?id=<?php echo $assoc['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remover associação?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                         </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($associacoes_professores)): ?>
                                    <tr><td colspan="5" class="text-center">Nenhuma associação encontrada</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Aba 3: Disciplinas por Turma -->
                    <div class="tab-pane fade <?php echo $active_tab == 'turmas' ? 'show active' : ''; ?>" id="turmas" role="tabpanel">
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <form method="POST" class="row g-2">
                                    <input type="hidden" name="acao" value="associar_turma">
                                    <div class="col-md-3">
                                        <select name="disciplina_id" class="form-control" required>
                                            <option value="">Disciplina</option>
                                            <?php foreach ($disciplinas as $disc): ?>
                                            <option value="<?php echo $disc['id']; ?>"><?php echo htmlspecialchars($disc['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="turma_id" class="form-control" required>
                                            <option value="">Turma</option>
                                            <?php foreach ($turmas as $turma): ?>
                                            <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="professor_titular_id" class="form-control" required>
                                            <option value="">Professor Titular</option>
                                            <?php foreach ($professores as $prof): ?>
                                            <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="professor_auxiliar_id" class="form-control">
                                            <option value="">Professor Auxiliar</option>
                                            <?php foreach ($professores as $prof): ?>
                                            <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-12 mt-2">
                                        <select name="ano_letivo" class="form-control d-inline-block w-auto">
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary">Alocar Disciplina à Turma</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Disciplina</th><th>Turma</th><th>Professor Titular</th><th>Professor Auxiliar</th><th>Ano Letivo</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alocacoes_turmas as $aloc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($aloc['disciplina_nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($aloc['turma_nome']); ?></td>
                                        <td><?php echo htmlspecialchars($aloc['professor_titular']); ?></td>
                                        <td><?php echo htmlspecialchars($aloc['professor_auxiliar'] ?? '-'); ?></td>
                                        <td><?php echo $aloc['ano_letivo']; ?></td>
                                        <td>
                                            <a href="excluir_alocacao.php?id=<?php echo $aloc['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remover alocação?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                         </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($alocacoes_turmas)): ?>
                                    <tr><td colspan="6" class="text-center">Nenhuma alocação encontrada</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        let currentStep = 1;
        const totalSteps = 3;
        
        function updateSteps() {
            for (let i = 1; i <= totalSteps; i++) {
                const stepDiv = document.getElementById(`step${i}`);
                const contentDiv = document.getElementById(`step${i}Content`);
                if (i < currentStep) {
                    stepDiv.classList.add('completed');
                    stepDiv.classList.remove('active');
                } else if (i === currentStep) {
                    stepDiv.classList.add('active');
                    stepDiv.classList.remove('completed');
                    contentDiv.classList.add('active');
                } else {
                    stepDiv.classList.remove('active', 'completed');
                    contentDiv.classList.remove('active');
                }
            }
        }
        
        function nextStep() {
            if (currentStep === 1) {
                const nome = document.getElementById('nome_disciplina').value;
                if (!nome) {
                    alert('Digite o nome da disciplina');
                    return;
                }
                // Salvar disciplina via AJAX
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: {
                        acao: 'cadastrar_disciplina_ajax',
                        nome: nome,
                        codigo: $('input[name="codigo"]').val(),
                        carga_horaria: $('input[name="carga_horaria"]').val(),
                        descricao: $('textarea[name="descricao"]').val()
                    },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            $('#disciplina_id').val(data.id);
                            currentStep++;
                            updateSteps();
                        } else {
                            alert(data.error);
                        }
                    }
                });
            } else if (currentStep === 2) {
                const titular = $('#professor_titular').val();
                if (!titular) {
                    alert('Selecione pelo menos o professor titular');
                    return;
                }
                // Salvar associações de professores via AJAX
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: {
                        acao: 'associar_professores_ajax',
                        disciplina_id: $('#disciplina_id').val(),
                        professor_titular: titular,
                        professor_auxiliar: $('#professor_auxiliar').val()
                    },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            currentStep++;
                            updateSteps();
                        } else {
                            alert(data.error);
                        }
                    }
                });
            } else if (currentStep === 3) {
                // Submit final
                $('#wizardForm').submit();
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateSteps();
            }
        }
        
        updateSteps();
    </script>
</body>
</html>