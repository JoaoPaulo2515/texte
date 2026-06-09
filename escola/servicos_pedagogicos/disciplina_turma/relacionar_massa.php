<?php
// escola/servicos_pedagogicos/disciplina_turma/relacionar_massa.php - Relacionamento em Massa
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// INCLUIR MENU
// ============================================
//include __DIR__ . '/../../menu_escola.php';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo_atual = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_valor = $ano_letivo_atual['ano'] ?? date('Y') . '/' . (date('Y') + 1);
$ano_letivo_id = $ano_letivo_atual['id'] ?? 1;

// Buscar todos os anos letivos para o select
$sql_anos_letivos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$anos_letivos = $conn->query($sql_anos_letivos)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR RELACIONAMENTO EM MASSA (AJAX)
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    $response = ['success' => false, 'message' => '', 'count' => 0, 'conflicts' => [], 'type' => ''];
    
    // Relacionar várias disciplinas a uma turma
    if ($acao == 'relacionar_disciplinas_turma') {
        $turma_id = (int)$_POST['turma_id'];
        $disciplinas = $_POST['disciplinas'] ?? [];
        $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
        $carga_horaria = (int)$_POST['carga_horaria'];
        $ano_letivo_input = $_POST['ano_letivo'] ?? $ano_letivo_valor;
        
        $count = 0;
        $conflitos = [];
        
        if (empty($disciplinas)) {
            $response = ['success' => false, 'message' => 'Selecione pelo menos uma disciplina!', 'count' => 0];
            echo json_encode($response);
            exit;
        }
        
        foreach ($disciplinas as $disciplina_id) {
            $disciplina_id = (int)$disciplina_id;
            try {
                // Verificar se a disciplina já está relacionada a outro professor
                $check_professor = $conn->prepare("
                    SELECT pdt.id, pdt.professor_id, f.nome as professor_nome
                    FROM professor_disciplina_turma pdt
                    INNER JOIN funcionarios f ON f.id = pdt.professor_id
                    WHERE pdt.disciplina_id = :disciplina_id 
                    AND pdt.turma_id = :turma_id 
                    AND pdt.ano_letivo_id = :ano_letivo_id
                ");
                $check_professor->execute([
                    ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                $professor_existente = $check_professor->fetch(PDO::FETCH_ASSOC);
                
                if ($professor_existente && $professor_existente['professor_id'] != $professor_id) {
                    $stmt_disc = $conn->prepare("SELECT nome FROM disciplinas WHERE id = :id");
                    $stmt_disc->execute([':id' => $disciplina_id]);
                    $disciplina_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC)['nome'];
                    
                    $conflitos[] = [
                        'disciplina_id' => $disciplina_id,
                        'disciplina_nome' => $disciplina_nome,
                        'professor_atual' => $professor_existente['professor_nome']
                    ];
                    continue;
                }
                
                // Verificar se já existe relação com o mesmo professor
                $check = $conn->prepare("
                    SELECT id FROM professor_disciplina_turma 
                    WHERE professor_id = :professor_id 
                    AND disciplina_id = :disciplina_id 
                    AND turma_id = :turma_id 
                    AND ano_letivo_id = :ano_letivo_id
                ");
                $check->execute([
                    ':professor_id' => $professor_id,
                    ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                if ($check->rowCount() == 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO professor_disciplina_turma (professor_id, disciplina_id, turma_id, ano_letivo_id, carga_horaria, created_at)
                        VALUES (:professor_id, :disciplina_id, :turma_id, :ano_letivo_id, :carga_horaria, NOW())
                    ");
                    $stmt->execute([
                        ':professor_id' => $professor_id,
                        ':disciplina_id' => $disciplina_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':carga_horaria' => $carga_horaria
                    ]);
                    $count++;
                }
            } catch (PDOException $e) {
                $response['message'] = "Erro: " . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        
        if (!empty($conflitos)) {
            $response = [
                'success' => false, 
                'message' => 'Algumas disciplinas já estão relacionadas a outro professor!', 
                'count' => $count,
                'conflicts' => $conflitos,
                'type' => 'conflict'
            ];
        } else {
            $response = ['success' => true, 'message' => "$count disciplina(s) relacionada(s) à turma com sucesso!", 'count' => $count];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Relacionar várias turmas a uma disciplina
    if ($acao == 'relacionar_turmas_disciplina') {
        $disciplina_id = (int)$_POST['disciplina_id'];
        $turmas = $_POST['turmas'] ?? [];
        $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
        $carga_horaria = (int)$_POST['carga_horaria'];
        $ano_letivo_input = $_POST['ano_letivo'] ??         $ano_letivo_input = $_POST['ano_letivo'] ?? $ano_letivo_valor;
        
        $count = 0;
        $conflitos = [];
        
        if (empty($turmas)) {
            $response = ['success' => false, 'message' => 'Selecione pelo menos uma turma!', 'count' => 0];
            echo json_encode($response);
            exit;
        }
        
        foreach ($turmas as $turma_id) {
            $turma_id = (int)$turma_id;
            try {
                // Verificar se a disciplina já está relacionada a outro professor nesta turma
                $check_professor = $conn->prepare("
                    SELECT pdt.id, pdt.professor_id, f.nome as professor_nome, t.nome as turma_nome
                    FROM professor_disciplina_turma pdt
                    INNER JOIN funcionarios f ON f.id = pdt.professor_id
                    INNER JOIN turmas t ON t.id = pdt.turma_id
                    WHERE pdt.disciplina_id = :disciplina_id 
                    AND pdt.turma_id = :turma_id 
                    AND pdt.ano_letivo_id = :ano_letivo_id
                ");
                $check_professor->execute([
                    ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                $professor_existente = $check_professor->fetch(PDO::FETCH_ASSOC);
                
                if ($professor_existente && $professor_existente['professor_id'] != $professor_id) {
                    $conflitos[] = [
                        'turma_id' => $turma_id,
                        'turma_nome' => $professor_existente['turma_nome'],
                        'professor_atual' => $professor_existente['professor_nome']
                    ];
                    continue;
                }
                
                // Verificar se já existe relação
                $check = $conn->prepare("
                    SELECT id FROM professor_disciplina_turma 
                    WHERE professor_id = :professor_id 
                    AND disciplina_id = :disciplina_id 
                    AND turma_id = :turma_id 
                    AND ano_letivo_id = :ano_letivo_id
                ");
                $check->execute([
                    ':professor_id' => $professor_id,
                    ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                if ($check->rowCount() == 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO professor_disciplina_turma (professor_id, disciplina_id, turma_id, ano_letivo_id, carga_horaria, created_at)
                        VALUES (:professor_id, :disciplina_id, :turma_id, :ano_letivo_id, :carga_horaria, NOW())
                    ");
                    $stmt->execute([
                        ':professor_id' => $professor_id,
                        ':disciplina_id' => $disciplina_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':carga_horaria' => $carga_horaria
                    ]);
                    $count++;
                }
            } catch (PDOException $e) {
                $response['message'] = "Erro: " . $e->getMessage();
                echo json_encode($response);
                exit;
            }
        }
        
        if (!empty($conflitos)) {
            $response = [
                'success' => false, 
                'message' => 'Algumas turmas já têm esta disciplina atribuída a outro professor!', 
                'count' => $count,
                'conflicts' => $conflitos,
                'type' => 'conflict'
            ];
        } else {
            $response = ['success' => true, 'message' => "$count turma(s) relacionada(s) à disciplina com sucesso!", 'count' => $count];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Relacionamento cruzado
    if ($acao == 'relacionar_cruzado') {
        $disciplinas = $_POST['disciplinas'] ?? [];
        $turmas = $_POST['turmas'] ?? [];
        $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
        $carga_horaria = (int)$_POST['carga_horaria'];
        $ano_letivo_input = $_POST['ano_letivo'] ?? $ano_letivo_valor;
        
        $count = 0;
        $conflitos = [];
        
        if (empty($disciplinas)) {
            $response = ['success' => false, 'message' => 'Selecione pelo menos uma disciplina!', 'count' => 0];
            echo json_encode($response);
            exit;
        }
        
        if (empty($turmas)) {
            $response = ['success' => false, 'message' => 'Selecione pelo menos uma turma!', 'count' => 0];
            echo json_encode($response);
            exit;
        }
        
        foreach ($disciplinas as $disciplina_id) {
            $disciplina_id = (int)$disciplina_id;
            $stmt_disc = $conn->prepare("SELECT nome FROM disciplinas WHERE id = :id");
            $stmt_disc->execute([':id' => $disciplina_id]);
            $disciplina_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC)['nome'];
            
            foreach ($turmas as $turma_id) {
                $turma_id = (int)$turma_id;
                try {
                    // Verificar se a disciplina já está relacionada a outro professor nesta turma
                    $check_professor = $conn->prepare("
                        SELECT pdt.id, pdt.professor_id, f.nome as professor_nome, t.nome as turma_nome
                        FROM professor_disciplina_turma pdt
                        INNER JOIN funcionarios f ON f.id = pdt.professor_id
                        INNER JOIN turmas t ON t.id = pdt.turma_id
                        WHERE pdt.disciplina_id = :disciplina_id 
                        AND pdt.turma_id = :turma_id 
                        AND pdt.ano_letivo_id = :ano_letivo_id
                    ");
                    $check_professor->execute([
                        ':disciplina_id' => $disciplina_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    
                    $professor_existente = $check_professor->fetch(PDO::FETCH_ASSOC);
                    
                    if ($professor_existente && $professor_existente['professor_id'] != $professor_id) {
                        $conflitos[] = [
                            'disciplina_id' => $disciplina_id,
                            'disciplina_nome' => $disciplina_nome,
                            'turma_id' => $turma_id,
                            'turma_nome' => $professor_existente['turma_nome'],
                            'professor_atual' => $professor_existente['professor_nome']
                        ];
                        continue;
                    }
                    
                    // Verificar se já existe relação
                    $check = $conn->prepare("
                        SELECT id FROM professor_disciplina_turma 
                        WHERE professor_id = :professor_id 
                        AND disciplina_id = :disciplina_id 
                        AND turma_id = :turma_id 
                        AND ano_letivo_id = :ano_letivo_id
                    ");
                    $check->execute([
                        ':professor_id' => $professor_id,
                        ':disciplina_id' => $disciplina_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    
                    if ($check->rowCount() == 0) {
                        $stmt = $conn->prepare("
                            INSERT INTO professor_disciplina_turma (professor_id, disciplina_id, turma_id, ano_letivo_id, carga_horaria, created_at)
                            VALUES (:professor_id, :disciplina_id, :turma_id, :ano_letivo_id, :carga_horaria, NOW())
                        ");
                        $stmt->execute([
                            ':professor_id' => $professor_id,
                            ':disciplina_id' => $disciplina_id,
                            ':turma_id' => $turma_id,
                            ':ano_letivo_id' => $ano_letivo_id,
                            ':carga_horaria' => $carga_horaria
                        ]);
                        $count++;
                    }
                } catch (PDOException $e) {
                    $response['message'] = "Erro: " . $e->getMessage();
                    echo json_encode($response);
                    exit;
                }
            }
        }
        
        if (!empty($conflitos)) {
            $response = [
                'success' => false, 
                'message' => 'Algumas relações já estão atribuídas a outro professor!', 
                'count' => $count,
                'conflicts' => $conflitos,
                'type' => 'conflict'
            ];
        } else {
            $response = ['success' => true, 'message' => "$count relação(ões) criada(s) com sucesso!", 'count' => $count];
        }
        
        echo json_encode($response);
        exit;
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar disciplinas
$disciplinas = $conn->prepare("
    SELECT id, nome, codigo, carga_horaria as ch_padrao 
    FROM disciplinas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY nome
");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$turmas = $conn->prepare("
    SELECT id, nome, ano, turno 
    FROM turmas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY ano, nome
");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores da tabela funcionarios
$professores = $conn->prepare("
    SELECT f.id, f.nome 
    FROM funcionarios f
    WHERE f.escola_id = :escola_id 
    AND (f.cargo = 'Professor' OR f.tipo_funcionario = 'professor')
    AND f.status = 'ativo'
    ORDER BY f.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relacionar em Massa | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 15px 15px 0 0;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .btn-primary:hover {
            background: #004d2d;
        }
        
        .checkbox-group {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            margin-bottom: 8px;
            padding: 5px;
            border-radius: 5px;
            transition: background 0.2s;
        }
        
        .checkbox-item:hover {
            background: #f8f9fa;
        }
        
        .checkbox-item input {
            margin-right: 10px;
        }
        
        .select-all {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .nav-tabs .nav-link {
            color: #006B3E;
        }
        
        .nav-tabs .nav-link.active {
            background-color: #006B3E;
            color: white;
            border-color: #006B3E;
        }
        
        .modal-conflict .modal-header {
            background: #fd7e14;
            color: white;
        }
        
        .conflict-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .conflict-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .conflict-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <!-- O menu_escola.php já inclui o sidebar -->
      <?php include __DIR__ . '/../../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-layer-group"></i> Relacionamento em Massa</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="massaTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#disciplinasParaTurma">
                            <i class="fas fa-book"></i> Disciplinas → Turma
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#turmasParaDisciplina">
                            <i class="fas fa-users-group"></i> Turmas → Disciplina
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#relacionamentoCruzado">
                            <i class="fas fa-link"></i> Relacionamento Cruzado
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    
                    <!-- Tab 1: Disciplinas para uma Turma -->
                    <div class="tab-pane fade show active" id="disciplinasParaTurma">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Selecione uma turma e várias disciplinas para relacionar.
                        </div>
                        <form id="formDisciplinasTurma" class="relacionamento-form">
                            <input type="hidden" name="acao" value="relacionar_disciplinas_turma">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Selecione a Turma *</label>
                                    <select name="turma_id" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($turmas as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Professor (opcional)</label>
                                    <select name="professor_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($professores as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ano Letivo</label>
                                    <select name="ano_letivo" class="form-select" required>
                                        <?php foreach ($anos_letivos as $ano): ?>
                                        <option value="<?php echo $ano['ano']; ?>" <?php echo $ano['ano'] == $ano_letivo_valor ? 'selected' : ''; ?>>
                                            <?php echo $ano['ano']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Período</label>
                                <select name="periodo" class="form-select" required>
                                    <option value="1º Bimestre">1º Bimestre</option>
                                    <option value="2º Bimestre">2º Bimestre</option>
                                    <option value="3º Bimestre">3º Bimestre</option>
                                    <option value="4º Bimestre">4º Bimestre</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Selecione as Disciplinas *</label>
                                <div class="select-all">
                                    <input type="checkbox" class="select-all-checkbox" data-group="disciplinasTurma">
                                    <label>Selecionar Todas as Disciplinas</label>
                                </div>
                                <div class="checkbox-group" id="disciplinasTurmaGroup">
                                    <?php foreach ($disciplinas as $d): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="disciplinas[]" value="<?php echo $d['id']; ?>" id="disc_turma_<?php echo $d['id']; ?>">
                                        <label for="disc_turma_<?php echo $d['id']; ?>">
                                            <strong><?php echo htmlspecialchars($d['codigo']); ?></strong> - <?php echo htmlspecialchars($d['nome']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="button" class="btn btn-primary btn-lg" onclick="confirmarRelacionamento('formDisciplinasTurma')">
                                    <i class="fas fa-link"></i> Relacionar Disciplinas à Turma
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab 2: Turmas para uma Disciplina -->
                    <div class="tab-pane fade" id="turmasParaDisciplina">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Selecione uma disciplina e várias turmas para relacionar.
                        </div>
                        <form id="formTurmasDisciplina" class="relacionamento-form">
                            <input type="hidden" name="acao" value="relacionar_turmas_disciplina">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Selecione a Disciplina *</label>
                                    <select name="disciplina_id" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($disciplinas as $d): ?>
                                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['codigo']); ?> - <?php echo htmlspecialchars($d['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Professor (opcional)</label>
                                    <select name="professor_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($professores as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ano Letivo</label>
                                    <select name="ano_letivo" class="form-select" required>
                                        <?php foreach ($anos_letivos as $ano): ?>
                                        <option value="<?php echo $ano['ano']; ?>" <?php echo $ano['ano'] == $ano_letivo_valor ? 'selected' : ''; ?>>
                                            <?php echo $ano['ano']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Período</label>
                                <select name="periodo" class="form-select" required>
                                    <option value="1º Bimestre">1º Bimestre</option>
                                    <option value="2º Bimestre">2º Bimestre</option>
                                    <option value="3º Bimestre">3º Bimestre</option>
                                    <option value="4º Bimestre">4º Bimestre</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Selecione as Turmas *</label>
                                <div class="select-all">
                                    <input type="checkbox" class="select-all-checkbox" data-group="turmasDisciplina">
                                    <label>Selecionar Todas as Turmas</label>
                                </div>
                                <div class="checkbox-group" id="turmasDisciplinaGroup">
                                    <?php foreach ($turmas as $t): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="turmas[]" value="<?php echo $t['id']; ?>" id="turma_disc_<?php echo $t['id']; ?>">
                                        <label for="turma_disc_<?php echo $t['id']; ?>">
                                            <?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno']); ?>)
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="button" class="btn btn-primary btn-lg" onclick="confirmarRelacionamento('formTurmasDisciplina')">
                                    <i class="fas fa-link"></i> Relacionar Turmas à Disciplina
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab 3: Relacionamento Cruzado -->
                    <div class="tab-pane fade" id="relacionamentoCruzado">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Atenção:</strong> Esta opção irá relacionar TODAS as disciplinas selecionadas com TODAS as turmas selecionadas.
                            Isso pode gerar muitas relações. Use com cuidado!
                        </div>
                        <form id="formCruzado" class="relacionamento-form">
                            <input type="hidden" name="acao" value="relacionar_cruzado">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Professor (opcional - será usado para todas as relações)</label>
                                    <select name="professor_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($professores as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ano Letivo</label>
                                    <select name="ano_letivo" class="form-select" required>
                                        <?php foreach ($anos_letivos as $ano): ?>
                                        <option value="<?php echo $ano['ano']; ?>" <?php echo $ano['ano'] == $ano_letivo_valor ? 'selected' : ''; ?>>
                                            <?php echo $ano['ano']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Período</label>
                                    <select name="periodo" class="form-select" required>
                                        <option value="1º Bimestre">1º Bimestre</option>
                                        <option value="2º Bimestre">2º Bimestre</option>
                                        <option value="3º Bimestre">3º Bimestre</option>
                                        <option value="4º Bimestre">4º Bimestre</option>
                                        <option value="Anual">Anual</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Selecione as Disciplinas *</label>
                                    <div class="select-all">
                                        <input type="checkbox" class="select-all-checkbox" data-group="disciplinasCruzado">
                                        <label>Selecionar Todas as Disciplinas</label>
                                    </div>
                                    <div class="checkbox-group" id="disciplinasCruzadoGroup">
                                        <?php foreach ($disciplinas as $d): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="disciplinas[]" value="<?php echo $d['id']; ?>" id="disc_cruz_<?php echo $d['id']; ?>">
                                            <label for="disc_cruz_<?php echo $d['id']; ?>">
                                                <strong><?php echo htmlspecialchars($d['codigo']); ?></strong> - <?php echo htmlspecialchars($d['nome']); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Selecione as Turmas *</label>
                                    <div class="select-all">
                                        <input type="checkbox" class="select-all-checkbox" data-group="turmasCruzado">
                                        <label>Selecionar Todas as Turmas</label>
                                    </div>
                                    <div class="checkbox-group" id="turmasCruzadoGroup">
                                        <?php foreach ($turmas as $t): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="turmas[]" value="<?php echo $t['id']; ?>" id="turma_cruz_<?php echo $t['id']; ?>">
                                            <label for="turma_cruz_<?php echo $t['id']; ?>">
                                                <?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno']); ?>)
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="button" class="btn btn-danger btn-lg" onclick="confirmarRelacionamento('formCruzado')">
                                    <i class="fas fa-link"></i> Relacionamento Cruzado
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirmar Ação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="confirmModalBody">
                    Tem certeza que deseja realizar esta operação?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirmar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Conflito -->
    <div class="modal fade" id="modalConflito" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-conflict">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Atenção - Conflito de Professor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>As seguintes disciplinas já estão relacionadas a outro professor nesta turma:</p>
                    <div class="conflict-list" id="conflictList"></div>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>O que fazer?</strong><br>
                        Uma disciplina só pode ter um professor por turma. Para relacionar esta disciplina com o novo professor, 
                        remova primeiro a relação existente ou escolha outro professor.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Resultado -->
    <div class="modal fade" id="modalResultado" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="resultModalHeader">
                    <h5 class="modal-title" id="resultModalTitle">Resultado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="resultModalBody">
                    Operação realizada com sucesso!
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Função para selecionar/deselecionar todos os checkboxes de um grupo
            $('.select-all-checkbox').on('change', function() {
                var group = $(this).data('group');
                var isChecked = $(this).prop('checked');
                
                if (group === 'disciplinasTurma') {
                    $('#disciplinasTurmaGroup input[type="checkbox"]').prop('checked', isChecked);
                } else if (group === 'turmasDisciplina') {
                    $('#turmasDisciplinaGroup input[type="checkbox"]').prop('checked', isChecked);
                } else if (group === 'disciplinasCruzado') {
                    $('#disciplinasCruzadoGroup input[type="checkbox"]').prop('checked', isChecked);
                } else if (group === 'turmasCruzado') {
                    $('#turmasCruzadoGroup input[type="checkbox"]').prop('checked', isChecked);
                }
            });
            
            // Quando um checkbox individual mudar, atualizar o "Selecionar Todos"
            $('#disciplinasTurmaGroup input[type="checkbox"]').on('change', function() {
                var total = $('#disciplinasTurmaGroup input[type="checkbox"]').length;
                var checked = $('#disciplinasTurmaGroup input[type="checkbox"]:checked').length;
                $('.select-all-checkbox[data-group="disciplinasTurma"]').prop('checked', total === checked);
            });
            
            $('#turmasDisciplinaGroup input[type="checkbox"]').on('change', function() {
                var total = $('#turmasDisciplinaGroup input[type="checkbox"]').length;
                var checked = $('#turmasDisciplinaGroup input[type="checkbox"]:checked').length;
                $('.select-all-checkbox[data-group="turmasDisciplina"]').prop('checked', total === checked);
            });
            
            $('#disciplinasCruzadoGroup input[type="checkbox"]').on('change', function() {
                var total = $('#disciplinasCruzadoGroup input[type="checkbox"]').length;
                var checked = $('#disciplinasCruzadoGroup input[type="checkbox"]:checked').length;
                $('.select-all-checkbox[data-group="disciplinasCruzado"]').prop('checked', total === checked);
            });
            
            $('#turmasCruzadoGroup input[type="checkbox"]').on('change', function() {
                var total = $('#turmasCruzadoGroup input[type="checkbox"]').length;
                var checked = $('#turmasCruzadoGroup input[type="checkbox"]:checked').length;
                $('.select-all-checkbox[data-group="turmasCruzado"]').prop('checked', total === checked);
            });
        });
        
        var pendingFormId = null;
        var pendingFormData = null;
        
        function confirmarRelacionamento(formId) {
            var $form = $('#' + formId);
            
            if (!$form.length) {
                alert('Erro: Formulário não encontrado!');
                return false;
            }
            
            var acao = $form.find('input[name="acao"]').val();
            var disciplinasCount = $form.find('input[name="disciplinas[]"]:checked').length;
            var turmasCount = $form.find('input[name="turmas[]"]:checked').length;
            var mensagemConfirmacao = '';
            
            if (acao === 'relacionar_disciplinas_turma') {
                var turmaId = $form.find('select[name="turma_id"]').val();
                if (!turmaId) {
                    alert('Selecione uma turma!');
                    return false;
                }
                if (disciplinasCount === 0) {
                    alert('Selecione pelo menos uma disciplina!');
                    return false;
                }
                mensagemConfirmacao = 'Tem certeza que deseja relacionar ' + disciplinasCount + ' disciplina(s) à turma selecionada?';
            } 
            else if (acao === 'relacionar_turmas_disciplina') {
                var disciplinaId = $form.find('select[name="disciplina_id"]').val();
                if (!disciplinaId) {
                    alert('Selecione uma disciplina!');
                    return false;
                }
                if (turmasCount === 0) {
                    alert('Selecione pelo menos uma turma!');
                    return false;
                }
                mensagemConfirmacao = 'Tem certeza que deseja relacionar ' + turmasCount + ' turma(s) à disciplina selecionada?';
            } 
            else if (acao === 'relacionar_cruzado') {
                if (disciplinasCount === 0) {
                    alert('Selecione pelo menos uma disciplina!');
                    return false;
                }
                if (turmasCount === 0) {
                    alert('Selecione pelo menos uma turma!');
                    return false;
                }
                var totalRelacoes = disciplinasCount * turmasCount;
                mensagemConfirmacao = 'Tem certeza que deseja criar ' + totalRelacoes + ' relação(ões)? (' + disciplinasCount + ' disciplinas × ' + turmasCount + ' turmas)';
            }
            
            pendingFormId = formId;
            pendingFormData = $form.serialize();
            
            $('#confirmModalBody').html(mensagemConfirmacao);
            var modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacao'));
            modalConfirm.show();
        }
        
        $('#confirmModalBtn').on('click', function() {
            var modalConfirm = bootstrap.Modal.getInstance(document.getElementById('modalConfirmacao'));
            if (modalConfirm) modalConfirm.hide();
            
            if (pendingFormId && pendingFormData) {
                var $form = $('#' + pendingFormId);
                var $btn = $form.find('button[onclick*="confirmarRelacionamento"]');
                var textoOriginal = $btn.html();
                
                $btn.html('<i class="fas fa-spinner fa-spin"></i> Processando...');
                $btn.prop('disabled', true);
                
                $.ajax({
                    url: 'relacionar_massa.php',
                    method: 'POST',
                    data: pendingFormData,
                    dataType: 'json',
                    success: function(response) {
                        $btn.html(textoOriginal);
                        $btn.prop('disabled', false);
                        
                        if (response.type === 'conflict') {
                            var conflictHtml = '<ul class="list-group">';
                            if (response.conflicts && response.conflicts.length > 0) {
                                for (var i = 0; i < response.conflicts.length; i++) {
                                    var c = response.conflicts[i];
                                    conflictHtml += '<li class="list-group-item">';
                                    if (c.disciplina_nome) {
                                        conflictHtml += '<strong>' + c.disciplina_nome + '</strong><br>';
                                        conflictHtml += '<small class="text-muted">Já atribuída a: <strong>' + c.professor_atual + '</strong></small>';
                                    } else if (c.turma_nome) {
                                        conflictHtml += '<strong>Turma: ' + c.turma_nome + '</strong><br>';
                                        conflictHtml += '<small class="text-muted">Já atribuída ao professor: <strong>' + c.professor_atual + '</strong></small>';
                                    }
                                    conflictHtml += '</li>';
                                }
                            }
                            conflictHtml += '</ul>';
                            $('#conflictList').html(conflictHtml);
                            var modalConflito = new bootstrap.Modal(document.getElementById('modalConflito'));
                            modalConflito.show();
                        } else if (response.success) {
                            $('#resultModalHeader').removeClass('bg-danger bg-warning').addClass('bg-success');
                            $('#resultModalTitle').text('Sucesso!');
                            $('#resultModalBody').html('<i class="fas fa-check-circle"></i> ' + response.message);
                            var modalResultado = new bootstrap.Modal(document.getElementById('modalResultado'));
                            modalResultado.show();
                            
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#resultModalHeader').removeClass('bg-success bg-warning').addClass('bg-danger');
                            $('#resultModalTitle').text('Erro!');
                            $('#resultModalBody').html('<i class="fas fa-exclamation-triangle"></i> ' + response.message);
                            var modalResultado = new bootstrap.Modal(document.getElementById('modalResultado'));
                            modalResultado.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $btn.html(textoOriginal);
                        $btn.prop('disabled', false);
                        $('#resultModalHeader').removeClass('bg-success bg-warning').addClass('bg-danger');
                        $('#resultModalTitle').text('Erro!');
                        $('#resultModalBody').html('<i class="fas fa-exclamation-triangle"></i> Ocorreu um erro: ' + error);
                        var modalResultado = new bootstrap.Modal(document.getElementById('modalResultado'));
                        modalResultado.show();
                    }
                });
                
                pendingFormId = null;
                pendingFormData = null;
            }
        });
    </script>
</body>
</html>