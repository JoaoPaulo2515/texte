<?php
// escola/disciplinas/editar.php - Editar Disciplina
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

$id = $_GET['id'] ?? 0;

// Buscar dados da disciplina
$stmt = $conn->prepare("
    SELECT * FROM disciplinas 
    WHERE id = :id AND escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$disciplina = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    header('Location: index.php?error=Disciplina não encontrada');
    exit;
}

// Buscar professores associados a esta disciplina
$stmt = $conn->prepare("
    SELECT a.id, p.id as professor_id, u.nome as professor_nome, p.especialidade, a.ano_letivo
    FROM alocacoes a
    JOIN professores p ON p.id = a.professor_id
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE a.disciplina_id = :disciplina_id AND a.turma_id IS NULL
    ORDER BY u.nome
");
$stmt->execute([':disciplina_id' => $id]);
$professores_associados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas onde a disciplina está alocada
$stmt = $conn->prepare("
    SELECT a.id, t.id as turma_id, t.nome as turma_nome, t.ano, t.turno,
           u_titular.nome as professor_titular, u_auxiliar.nome as professor_auxiliar,
           a.ano_letivo
    FROM alocacoes a
    JOIN turmas t ON t.id = a.turma_id
    JOIN professores p_titular ON p_titular.id = a.professor_id
    JOIN usuarios u_titular ON u_titular.id = p_titular.usuario_id
    LEFT JOIN alocacoes a2 ON a2.disciplina_id = a.disciplina_id AND a2.turma_id = a.turma_id AND a2.id != a.id
    LEFT JOIN professores p_auxiliar ON p_auxiliar.id = a2.professor_id
    LEFT JOIN usuarios u_auxiliar ON u_auxiliar.id = p_auxiliar.usuario_id
    WHERE a.disciplina_id = :disciplina_id AND a.turma_id IS NOT NULL
    GROUP BY a.turma_id
    ORDER BY t.nome
");
$stmt->execute([':disciplina_id' => $id]);
$alocacoes_turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar todos os professores para selects
$professores = $conn->prepare("
    SELECT p.id, u.nome, p.especialidade 
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id AND p.status = 'ativo'
    ORDER BY u.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas para selects
$turmas = $conn->prepare("
    SELECT id, nome, ano, turno 
    FROM turmas 
    WHERE escola_id = :escola_id AND status = 'ativa' AND ano_letivo = :ano
    ORDER BY nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'editar';

// Processar edição da disciplina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar_disciplina') {
    $nome = $_POST['nome'] ?? '';
    $codigo = $_POST['codigo'] ?? '';
    $carga_horaria = $_POST['carga_horaria'] ?? 0;
    $descricao = $_POST['descricao'] ?? '';
    $status = $_POST['status'] ?? 'ativa';
    
    if (empty($nome)) {
        $error = "Preencha o nome da disciplina.";
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE disciplinas SET
                    nome = :nome,
                    codigo = :codigo,
                    carga_horaria = :carga_horaria,
                    descricao = :descricao,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':codigo' => $codigo ?: null,
                ':carga_horaria' => $carga_horaria ?: null,
                ':descricao' => $descricao ?: null,
                ':status' => $status
            ]);
            
            $success = "Disciplina atualizada com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'editar_disciplina', 'disciplinas', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            // Recarregar dados
            $stmt = $conn->prepare("SELECT * FROM disciplinas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $disciplina = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Processar associação de professor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'associar_professor') {
    $professor_id = $_POST['professor_id'] ?? 0;
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    
    if (!$professor_id) {
        $error = "Selecione o professor.";
    } else {
        try {
            // Verificar se já existe associação
            $stmt = $conn->prepare("
                SELECT id FROM alocacoes 
                WHERE disciplina_id = :disciplina_id AND professor_id = :professor_id AND turma_id IS NULL AND ano_letivo = :ano
            ");
            $stmt->execute([
                ':disciplina_id' => $id,
                ':professor_id' => $professor_id,
                ':ano' => $ano_letivo
            ]);
            
            if ($stmt->fetch()) {
                throw new Exception("Este professor já está associado a esta disciplina.");
            }
            
            // Verificar limite de 2 professores
            $stmt = $conn->prepare("
                SELECT COUNT(*) as total FROM alocacoes 
                WHERE disciplina_id = :disciplina_id AND turma_id IS NULL AND ano_letivo = :ano
            ");
            $stmt->execute([
                ':disciplina_id' => $id,
                ':ano' => $ano_letivo
            ]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total >= 2) {
                throw new Exception("Esta disciplina já possui 2 professores associados (máximo permitido).");
            }
            
            $stmt = $conn->prepare("
                INSERT INTO alocacoes (professor_id, disciplina_id, ano_letivo, created_at)
                VALUES (:professor_id, :disciplina_id, :ano, NOW())
            ");
            
            $stmt->execute([
                ':professor_id' => $professor_id,
                ':disciplina_id' => $id,
                ':ano' => $ano_letivo
            ]);
            
            $success = "Professor associado com sucesso!";
            $active_tab = 'professores';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Processar remoção de associação de professor
if (isset($_GET['remover_professor']) && isset($_GET['assoc_id'])) {
    $assoc_id = $_GET['assoc_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM alocacoes WHERE id = :id");
        $stmt->execute([':id' => $assoc_id]);
        $success = "Associação removida com sucesso!";
        $active_tab = 'professores';
        
        // Recarregar associações
        $stmt = $conn->prepare("
            SELECT a.id, p.id as professor_id, u.nome as professor_nome, p.especialidade, a.ano_letivo
            FROM alocacoes a
            JOIN professores p ON p.id = a.professor_id
            JOIN usuarios u ON u.id = p.usuario_id
            WHERE a.disciplina_id = :disciplina_id AND a.turma_id IS NULL
        ");
        $stmt->execute([':disciplina_id' => $id]);
        $professores_associados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Processar remoção de alocação de turma
if (isset($_GET['remover_turma']) && isset($_GET['aloc_id'])) {
    $aloc_id = $_GET['aloc_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM alocacoes WHERE id = :id");
        $stmt->execute([':id' => $aloc_id]);
        $success = "Alocação removida com sucesso!";
        $active_tab = 'turmas';
        
        // Recarregar alocações
        $stmt = $conn->prepare("
            SELECT a.id, t.id as turma_id, t.nome as turma_nome, t.ano, t.turno,
                   u_titular.nome as professor_titular,
                   a.ano_letivo
            FROM alocacoes a
            JOIN turmas t ON t.id = a.turma_id
            JOIN professores p_titular ON p_titular.id = a.professor_id
            JOIN usuarios u_titular ON u_titular.id = p_titular.usuario_id
            WHERE a.disciplina_id = :disciplina_id AND a.turma_id IS NOT NULL
            GROUP BY a.turma_id
        ");
        $stmt->execute([':disciplina_id' => $id]);
        $alocacoes_turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Disciplina | SIGE Angola</title>
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
        .badge-titular { background: #28a745; }
        .badge-auxiliar { background: #ffc107; color: #333; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Editar Disciplina: <?php echo htmlspecialchars($disciplina['nome']); ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="disciplinaTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'editar' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#editar" type="button" role="tab">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'professores' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#professores" type="button" role="tab">
                            <i class="fas fa-chalkboard-user"></i> Professores (<?php echo count($professores_associados); ?>/2)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab == 'turmas' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#turmas" type="button" role="tab">
                            <i class="fas fa-users-group"></i> Turmas
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
                    <!-- Aba Editar -->
                    <div class="tab-pane fade <?php echo $active_tab == 'editar' ? 'show active' : ''; ?>" id="editar" role="tabpanel">
                        <form method="POST">
                            <input type="hidden" name="acao" value="editar_disciplina">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Nome da Disciplina</label>
                                        <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($disciplina['nome']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Código</label>
                                        <input type="text" name="codigo" class="form-control" value="<?php echo htmlspecialchars($disciplina['codigo']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Carga Horária (h/semana)</label>
                                        <input type="number" name="carga_horaria" class="form-control" value="<?php echo $disciplina['carga_horaria']; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label>Descrição</label>
                                <textarea name="descricao" class="form-control" rows="3"><?php echo htmlspecialchars($disciplina['descricao']); ?></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Status</label>
                                        <select name="status" class="form-control">
                                            <option value="ativa" <?php echo $disciplina['status'] == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                                            <option value="inativa" <?php echo $disciplina['status'] == 'inativa' ? 'selected' : ''; ?>>Inativa</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary btn-lg px-5">
                                    <i class="fas fa-save"></i> Salvar Alterações
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Aba Professores Associados -->
                    <div class="tab-pane fade <?php echo $active_tab == 'professores' ? 'show active' : ''; ?>" id="professores" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Uma disciplina pode ter no máximo <strong>2 professores</strong> (Titular e Auxiliar).
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <form method="POST" class="row g-2">
                                    <input type="hidden" name="acao" value="associar_professor">
                                    <div class="col-md-6">
                                        <select name="professor_id" class="form-control" required>
                                            <option value="">Selecione o Professor</option>
                                            <?php 
                                            $professores_ids = array_column($professores_associados, 'professor_id');
                                            foreach ($professores as $prof): 
                                                if (!in_array($prof['id'], $professores_ids)):
                                            ?>
                                            <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?> - <?php echo htmlspecialchars($prof['especialidade'] ?? 'Professor'); ?></option>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="ano_letivo" class="form-control">
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">Associar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Professor</th><th>Especialidade</th><th>Ano Letivo</th><th>Ações</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($professores_associados as $assoc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($assoc['professor_nome']); ?></strong>                                        <td><?php echo htmlspecialchars($assoc['especialidade'] ?? '-'); ?></td>
                                        <td><?php echo $assoc['ano_letivo']; ?></td>
                                        <td>
                                            <a href="?id=<?php echo $id; ?>&remover_professor=1&assoc_id=<?php echo $assoc['id']; ?>&tab=professores" 
                                               class="btn btn-sm btn-danger" onclick="return confirm('Remover este professor da disciplina?')">
                                                <i class="fas fa-trash"></i> Remover
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($professores_associados)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">Nenhum professor associado a esta disciplina</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Aba Turmas -->
                    <div class="tab-pane fade <?php echo $active_tab == 'turmas' ? 'show active' : ''; ?>" id="turmas" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Turma</th>
                                        <th>Ano</th>
                                        <th>Turno</th>
                                        <th>Professor Titular</th>
                                        <th>Professor Auxiliar</th>
                                        <th>Ano Letivo</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alocacoes_turmas as $aloc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($aloc['turma_nome']); ?></strong></td>
                                        <td><?php echo $aloc['ano']; ?></td>
                                        <td><?php echo ucfirst($aloc['turno']); ?></td>
                                        <td><?php echo htmlspecialchars($aloc['professor_titular']); ?></td>
                                        <td><?php echo htmlspecialchars($aloc['professor_auxiliar'] ?? '-'); ?></td>
                                        <td><?php echo $aloc['ano_letivo']; ?></td>
                                        <td>
                                            <a href="?id=<?php echo $id; ?>&remover_turma=1&aloc_id=<?php echo $aloc['id']; ?>&tab=turmas" 
                                               class="btn btn-sm btn-danger" onclick="return confirm('Remover esta disciplina da turma?')">
                                                <i class="fas fa-trash"></i> Remover
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($alocacoes_turmas)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">Nenhuma turma associada a esta disciplina</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i> Para associar esta disciplina a uma turma, vá até o menu <strong>Turmas</strong> e adicione a disciplina.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Ativar a tab correta baseada no parâmetro GET
        var activeTab = '<?php echo $active_tab; ?>';
        if (activeTab === 'professores') {
            $('#disciplinaTabs button[data-bs-target="#professores"]').tab('show');
        } else if (activeTab === 'turmas') {
            $('#disciplinaTabs button[data-bs-target="#turmas"]').tab('show');
        } else {
            $('#disciplinaTabs button[data-bs-target="#editar"]').tab('show');
        }
    </script>
</body>
</html>