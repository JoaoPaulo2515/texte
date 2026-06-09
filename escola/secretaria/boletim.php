<?php
// escola/secretaria/boletim.php - Emissão de Boletins

require_once __DIR__ . '/../../config/database.php');
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$is_secretaria = ($_SESSION['usuario_tipo'] == 'secretaria' || ($_SESSION['papel'] ?? '') == 'secretaria');
$is_admin = ($_SESSION['usuario_tipo'] == 'super_admin' || $_SESSION['usuario_tipo'] == 'admin_escola' || $_SESSION['usuario_tipo'] == 'diretor' || ($_SESSION['papel'] ?? '') == 'admin');

if (!$is_secretaria && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// Buscar turmas
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar trimestres
$trimestres = [1, 2, 3];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletim Escolar | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Emissão de Boletim Escolar</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Turma</label>
                        <select name="turma_id" class="form-select" required>
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas as $turma): ?>
                            <option value="<?php echo $turma['id']; ?>"><?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Trimestre</label>
                        <select name="trimestre" class="form-select" required>
                            <?php foreach ($trimestres as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?>º Trimestre</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Gerar Boletim</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (isset($_GET['turma_id']) && isset($_GET['trimestre'])): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-print"></i> Boletim da Turma</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Funcionalidade em desenvolvimento. Em breve você poderá visualizar e imprimir boletins completos.
                </div>
                <button class="btn btn-success" onclick="alert('Funcionalidade em desenvolvimento')">
                    <i class="fas fa-print"></i> Imprimir Boletim
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>