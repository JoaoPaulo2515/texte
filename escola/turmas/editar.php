<?php
// escola/turmas/editar.php - Editar Turma
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$id = $_GET['id'] ?? 0;

// Buscar dados da turma
$stmt = $conn->prepare("
    SELECT * FROM turmas 
    WHERE id = :id AND escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$turma = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: index.php?error=Turma não encontrada');
    exit;
}

// Buscar salas existentes para combobox
$salas = $conn->prepare("SELECT DISTINCT sala FROM turmas WHERE escola_id = :escola_id AND sala IS NOT NULL ORDER BY sala");
$salas->execute([':escola_id' => $escola_id]);
$salas = $salas->fetchAll(PDO::FETCH_COLUMN);

// Se não houver salas, adicionar opções padrão
if (empty($salas)) {
    $salas = ['Sala 1', 'Sala 2', 'Sala 3', 'Sala 4', 'Sala 5', 'Laboratório 1', 'Laboratório 2', 'Auditório'];
}

// Turnos disponíveis (Angola)
$turnos = [
    'manha' => 'Manhã (7h às 12h)',
    'tarde' => 'Tarde (13h às 18h)',
    'noite' => 'Noite (18h às 22h)'
];

// Anos/Classes escolares (Angola)
$anos_escolares = [
    '1º Ano' => '1º Ano (Ensino Primário)',
    '2º Ano' => '2º Ano (Ensino Primário)',
    '3º Ano' => '3º Ano (Ensino Primário)',
    '4º Ano' => '4º Ano (Ensino Primário)',
    '5º Ano' => '5º Ano (Ensino Primário)',
    '6º Ano' => '6º Ano (Ensino Primário)',
    '7º Ano' => '7º Ano (1º Ciclo)',
    '8º Ano' => '8º Ano (1º Ciclo)',
    '9º Ano' => '9º Ano (1º Ciclo)',
    '10ª Classe' => '10ª Classe (2º Ciclo)',
    '11ª Classe' => '11ª Classe (2º Ciclo)',
    '12ª Classe' => '12ª Classe (2º Ciclo)',
    '13ª Classe' => '13ª Classe (Pré-Universitário)'
];

$error = '';
$success = '';

// Processar adição de nova sala via AJAX
if (isset($_POST['acao']) && $_POST['acao'] == 'add_sala') {
    $nova_sala = $_POST['nova_sala'] ?? '';
    if ($nova_sala) {
        echo json_encode(['success' => true, 'sala' => $nova_sala]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['acao'])) {
    $nome = $_POST['nome'] ?? '';
    $ano = $_POST['ano'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    $capacidade = $_POST['capacidade'] ?? 30;
    $sala = $_POST['sala'] ?? '';
    $status = $_POST['status'] ?? 'ativa';
    
    if (empty($nome) || empty($ano) || empty($turno)) {
        $error = "Preencha todos os campos obrigatórios.";
    } else {
        try {
            // Verificar se já existe outra turma com mesmo nome no mesmo ano letivo
            $stmt = $conn->prepare("
                SELECT id FROM turmas 
                WHERE escola_id = :escola_id AND nome = :nome AND ano_letivo = :ano_letivo AND id != :id
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':nome' => $nome,
                ':ano_letivo' => $ano_letivo,
                ':id' => $id
            ]);
            
            if ($stmt->fetch()) {
                throw new Exception("Já existe uma turma com este nome no ano letivo {$ano_letivo}.");
            }
            
            $stmt = $conn->prepare("
                UPDATE turmas SET
                    nome = :nome,
                    ano = :ano,
                    turno = :turno,
                    ano_letivo = :ano_letivo,
                    capacidade = :capacidade,
                    sala = :sala,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':ano' => $ano,
                ':turno' => $turno,
                ':ano_letivo' => $ano_letivo,
                ':capacidade' => $capacidade,
                ':sala' => $sala ?: null,
                ':status' => $status
            ]);
            
            $success = "Turma atualizada com sucesso!";
            
            // Log
            $stmt = $conn->prepare("
                INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
                VALUES (:usuario_id, 'editar_turma', 'turmas', :registro_id, :ip, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $_SESSION['usuario_id'],
                ':registro_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            // Recarregar dados
            $stmt = $conn->prepare("SELECT * FROM turmas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $turma = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
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
    <title>Editar Turma | SIGE Angola</title>
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
        .btn-primary { background: #006B3E; border: none; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .modal-novo { z-index: 1050; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-edit"></i> Editar Turma: <?php echo htmlspecialchars($turma['nome']); ?></h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Dados da Turma</h3>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" id="formTurma">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="required">Nome da Turma</label>
                                <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($turma['nome']); ?>" required>
                                <small class="text-muted">Identificação única da turma</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="required">Ano / Classe</label>
                                <select name="ano" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($anos_escolares as $valor => $label): ?>
                                    <option value="<?php echo $valor; ?>" <?php echo $turma['ano'] == $valor ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="required">Turno</label>
                                <select name="turno" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turnos as $valor => $label): ?>
                                    <option value="<?php echo $valor; ?>" <?php echo $turma['turno'] == $valor ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="required">Ano Letivo</label>
                                <select name="ano_letivo" class="form-control" required>
                                    <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $turma['ano_letivo'] == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Sala</label>
                                <div class="input-group">
                                    <select name="sala" class="form-control">
                                        <option value="">Selecione ou adicione...</option>
                                        <?php foreach ($salas as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $turma['sala'] == $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaSala">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Sala de aula ou laboratório</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Capacidade</label>
                                <input type="number" name="capacidade" class="form-control" value="<?php echo $turma['capacidade']; ?>" min="1" max="100">
                                <small class="text-muted">Número máximo de alunos</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="ativa" <?php echo $turma['status'] == 'ativa' ? 'selected' : ''; ?>>Ativa</option>
                                    <option value="encerrada" <?php echo $turma['status'] == 'encerrada' ? 'selected' : ''; ?>>Encerrada</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Sala -->
    <div class="modal fade" id="modalNovaSala" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Nova Sala</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nome da Sala</label>
                        <input type="text" id="novaSala" class="form-control" placeholder="Ex: Sala 6, Laboratório de Química">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="salvarSalaBtn">Salvar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Salvar nova sala
        $('#salvarSalaBtn').click(function() {
            var novaSala = $('#novaSala').val();
            if (novaSala) {
                $('select[name="sala"]').append('<option value="' + novaSala + '" selected>' + novaSala + '</option>');
                $('#modalNovaSala').modal('hide');
                $('#novaSala').val('');
            } else {
                alert('Digite o nome da sala');
            }
        });
    </script>
</body>
</html>