<?php
// escola/turmas/associar_disciplina.php - Associar disciplina a uma turma
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

$turma_id = $_GET['turma_id'] ?? 0;

// Buscar dados da turma
$stmt = $conn->prepare("
    SELECT * FROM turmas 
    WHERE id = :id AND escola_id = :escola_id
");
$stmt->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: index.php?error=Turma não encontrada');
    exit;
}

// Buscar disciplinas disponíveis (não associadas a esta turma)
$stmt = $conn->prepare("
    SELECT d.id, d.nome, d.codigo, d.carga_horaria
    FROM disciplinas d
    WHERE d.escola_id = :escola_id 
      AND d.status = 'ativa'
      AND d.id NOT IN (
          SELECT disciplina_id FROM alocacoes 
          WHERE turma_id = :turma_id AND ano_letivo = :ano
      )
    ORDER BY d.nome
");
$stmt->execute([
    ':escola_id' => $escola_id,
    ':turma_id' => $turma_id,
    ':ano' => $ano_letivo
]);
$disciplinas_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores
$professores = $conn->prepare("
    SELECT p.id, u.nome, p.especialidade 
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id AND p.status = 'ativo'
    ORDER BY u.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disciplina_id = $_POST['disciplina_id'] ?? 0;
    $professor_titular_id = $_POST['professor_titular_id'] ?? 0;
    $professor_auxiliar_id = $_POST['professor_auxiliar_id'] ?? 0;
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    
    if (!$disciplina_id || !$professor_titular_id) {
        $error = "Selecione a disciplina e o professor titular.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Verificar se já existe alocação
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
            $success = "Disciplina associada à turma com sucesso!";
            
            // Redirecionar após 2 segundos
            header("refresh:2;url=visualizar.php?id={$turma_id}");
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associar Disciplina | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-plus"></i> Associar Disciplina à Turma: <?php echo htmlspecialchars($turma['nome']); ?></h2>
            <a href="visualizar.php?id=<?php echo $turma_id; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Dados da Associação</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Disciplina *</label>
                                <select name="disciplina_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas_disponiveis as $disc): ?>
                                    <option value="<?php echo $disc['id']; ?>">
                                        <?php echo htmlspecialchars($disc['nome']); ?> 
                                        (<?php echo $disc['codigo'] ?? 'Sem código'; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($disciplinas_disponiveis)): ?>
                                <small class="text-danger">Nenhuma disciplina disponível para associação.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Ano Letivo *</label>
                                <select name="ano_letivo" class="form-control" required>
                                    <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Professor Titular *</label>
                                <select name="professor_titular_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($professores as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>">
                                        <?php echo htmlspecialchars($prof['nome']); ?> 
                                        (<?php echo htmlspecialchars($prof['especialidade'] ?? 'Professor'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Professor Auxiliar (Opcional)</label>
                                <select name="professor_auxiliar_id" class="form-control">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($professores as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>">
                                        <?php echo htmlspecialchars($prof['nome']); ?> 
                                        (<?php echo htmlspecialchars($prof['especialidade'] ?? 'Professor'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>Informação:</strong> Uma disciplina pode ter no máximo 2 professores (Titular e Auxiliar).
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Associar Disciplina
                        </button>
                        <a href="visualizar.php?id=<?php echo $turma_id; ?>" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>