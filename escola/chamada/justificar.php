<?php
// escola/chamada/justificar.php - Justificar faltas do aluno
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];

// Verificar permissão (apenas admin, diretor ou professor)
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor');
$is_professor = ($usuario_tipo == 'professor');

if (!$is_admin && !$is_professor) {
    header('Location: index.php?error=Acesso negado');
    exit;
}

$aluno_id = $_GET['aluno_id'] ?? 0;
$data = $_GET['data'] ?? '';
$message = '';
$error = '';

// Buscar dados do aluno
$stmt = $conn->prepare("
    SELECT e.id, u.nome, e.matricula, e.encarregado_nome, e.encarregado_telefone, e.encarregado_email
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.id = :id AND e.escola_id = :escola_id
");
$stmt->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: index.php?error=Aluno não encontrado');
    exit;
}

// Buscar faltas do aluno
$query = "
    SELECT p.*, m.id as matricula_id
    FROM presencas p
    JOIN matriculas m ON m.id = p.matricula_id
    WHERE m.estudante_id = :aluno_id AND p.presente = 0
";

$params = [':aluno_id' => $aluno_id];

if ($data) {
    $query .= " AND p.data = :data";
    $params[':data'] = $data;
}

$query .= " ORDER BY p.data DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$faltas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Processar justificativa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $falta_id = $_POST['falta_id'] ?? 0;
    $justificativa = $_POST['justificativa'] ?? '';
    $enviar_notificacao = isset($_POST['enviar_notificacao']);
    
    if (empty($justificativa)) {
        $error = "Digite a justificativa para a falta.";
    } else {
        try {
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("
                UPDATE presencas SET 
                    justificativa = :justificativa,
                    tipo_falta = 'justificada',
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':justificativa' => $justificativa,
                ':id' => $falta_id
            ]);
            
            if ($enviar_notificacao && !empty($aluno['encarregado_telefone'])) {
                $stmt_notif = $conn->prepare("
                    INSERT INTO notificacoes (escola_id, titulo, mensagem, tipo, created_at)
                    VALUES (:escola_id, :titulo, :mensagem, 'info', NOW())
                ");
                $stmt_notif->execute([
                    ':escola_id' => $escola_id,
                    ':titulo' => 'Falta Justificada',
                    ':mensagem' => "A falta do aluno {$aluno['nome']} foi justificada: {$justificativa}"
                ]);
            }
            
            $conn->commit();
            $message = "Falta justificada com sucesso!";
            
            // Recarregar faltas
            $stmt = $conn->prepare("
                SELECT p.*, m.id as matricula_id
                FROM presencas p
                JOIN matriculas m ON m.id = p.matricula_id
                WHERE m.estudante_id = :aluno_id AND p.presente = 0
                ORDER BY p.data DESC
            ");
            $stmt->execute([':aluno_id' => $aluno_id]);
            $faltas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
    <title>Justificar Falta | SIGE Angola</title>
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
        .falta-card { border-left: 4px solid #dc3545; transition: all 0.3s; }
        .falta-card:hover { transform: translateX(5px); }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-notes-medical"></i> Justificar Falta</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">Aluno: <?php echo htmlspecialchars($aluno['nome']); ?></h3>
                <small>Matrícula: <?php echo $aluno['matricula']; ?></small>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label>Filtrar por Data</label>
                            <input type="date" class="form-control w-auto d-inline-block" id="filterData" value="<?php echo $data; ?>" 
                                   onchange="location.href='?aluno_id=<?php echo $aluno_id; ?>&data='+this.value">
                        </div>
                    </div>
                </div>
                
                <?php if (empty($faltas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Este aluno não possui faltas não justificadas.
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($faltas as $falta): ?>
                    <div class="col-md-6 mb-3">
                        <div class="card falta-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">
                                            <i class="fas fa-calendar-alt text-danger"></i>
                                            <?php echo date('d/m/Y', strtotime($falta['data'])); ?>
                                        </h5>
                                        <p class="card-text">
                                            <strong>Status:</strong> 
                                            <?php if ($falta['tipo_falta'] == 'justificada'): ?>
                                                <span class="badge bg-success">Justificada</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Não Justificada</span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($falta['justificativa']): ?>
                                            <p class="card-text">
                                                <strong>Justificativa:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($falta['justificativa'])); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($falta['tipo_falta'] != 'justificada'): ?>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalJustificar" 
                                            data-id="<?php echo $falta['id']; ?>" data-data="<?php echo date('d/m/Y', strtotime($falta['data'])); ?>">
                                        <i class="fas fa-edit"></i> Justificar
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Justificar -->
    <div class="modal fade" id="modalJustificar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Justificar Falta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="falta_id" id="falta_id" value="">
                    <div class="modal-body">
                        <p><strong>Aluno:</strong> <?php echo htmlspecialchars($aluno['nome']); ?></p>
                        <p><strong>Data:</strong> <span id="falta_data"></span></p>
                        <div class="mb-3">
                            <label>Justificativa</label>
                            <textarea name="justificativa" class="form-control" rows="4" required placeholder="Digite o motivo da falta..."></textarea>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="enviar_notificacao" class="form-check-input" value="1">
                            <label class="form-check-label">
                                <i class="fas fa-bell"></i> Notificar encarregado
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Justificativa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        $('#modalJustificar').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            var faltaId = button.data('id');
            var faltaData = button.data('data');
            
            var modal = $(this);
            modal.find('#falta_id').val(faltaId);
            modal.find('#falta_data').text(faltaData);
        });
    </script>
</body>
</html>