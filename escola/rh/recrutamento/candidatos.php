<?php
// escola/rh/recrutamento/candidatos.php - Gestão de Candidatos
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar atualização de status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'atualizar_status') {
    $stmt = $conn->prepare("UPDATE candidatos SET status = ?, observacoes = ? WHERE id = ?");
    $stmt->execute([$_POST['status'], $_POST['observacoes'], $_POST['id']]);
    $success = "Status do candidato atualizado!";
}

// Buscar vagas para o select
$stmt = $conn->prepare("SELECT id, titulo FROM vagas_emprego WHERE escola_id = ? AND status = 'aberta' ORDER BY data_abertura DESC");
$stmt->execute([$escola_id]);
$vagas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar candidatos
$vaga_id = $_GET['vaga_id'] ?? '';
$sql = "SELECT c.*, v.titulo as vaga_titulo FROM candidatos c JOIN vagas_emprego v ON c.vaga_id = v.id WHERE v.escola_id = ?";
$params = [$escola_id];

if ($vaga_id) {
    $sql .= " AND c.vaga_id = ?";
    $params[] = $vaga_id;
}

$sql .= " ORDER BY c.data_candidatura DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_options = ['pendente', 'analisado', 'entrevistado', 'aprovado', 'reprovado'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidatos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .candidato-card { transition: transform 0.3s; margin-bottom: 15px; }
        .candidato-card:hover { transform: translateY(-3px); }
    </style>
</head>
<body>
 
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users-viewfinder"></i> Candidatos</h2>
            <div>
                <select id="filtroVaga" class="form-select d-inline-block w-auto" onchange="filtrarPorVaga()">
                    <option value="">Todas as Vagas</option>
                    <?php foreach ($vagas as $v): ?>
                        <option value="<?php echo $v['id']; ?>" <?php echo $vaga_id == $v['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v['titulo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="novo_candidato.php" class="btn btn-primary ms-2">
                    <i class="fas fa-user-plus"></i> Novo Candidato
                </a>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <?php foreach ($candidatos as $candidato): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card candidato-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title"><?php echo htmlspecialchars($candidato['nome']); ?></h5>
                                <h6 class="card-subtitle mb-2 text-muted">
                                    <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($candidato['vaga_titulo']); ?>
                                </h6>
                            </div>
                            <span class="badge bg-<?php 
                                echo $candidato['status'] == 'aprovado' ? 'success' : 
                                    ($candidato['status'] == 'reprovado' ? 'danger' : 
                                    ($candidato['status'] == 'entrevistado' ? 'info' : 
                                    ($candidato['status'] == 'analisado' ? 'primary' : 'warning'))); 
                            ?>">
                                <?php echo ucfirst($candidato['status']); ?>
                            </span>
                        </div>
                        <hr>
                        <p><i class="fas fa-id-card"></i> BI: <?php echo htmlspecialchars($candidato['bi']); ?></p>
                        <p><i class="fas fa-phone"></i> Telefone: <?php echo htmlspecialchars($candidato['telefone']); ?></p>
                        <p><i class="fas fa-envelope"></i> Email: <?php echo htmlspecialchars($candidato['email']); ?></p>
                        <p><i class="fas fa-calendar"></i> Candidatura: <?php echo date('d/m/Y', strtotime($candidato['data_candidatura'])); ?></p>
                        
                        <?php if ($candidato['curriculo']): ?>
                            <a href="../../../<?php echo $candidato['curriculo']; ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                <i class="fas fa-file-pdf"></i> Ver Currículo
                            </a>
                        <?php endif; ?>
                        
                        <button class="btn btn-sm btn-outline-primary mt-2" data-bs-toggle="modal" data-bs-target="#modalStatus<?php echo $candidato['id']; ?>">
                            <i class="fas fa-edit"></i> Atualizar Status
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Modal Atualizar Status -->
            <div class="modal fade" id="modalStatus<?php echo $candidato['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title">Atualizar Status - <?php echo htmlspecialchars($candidato['nome']); ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="acao" value="atualizar_status">
                            <input type="hidden" name="id" value="<?php echo $candidato['id']; ?>">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label>Status</label>
                                    <select name="status" class="form-control" required>
                                        <?php foreach ($status_options as $opt): ?>
                                            <option value="<?php echo $opt; ?>" <?php echo $candidato['status'] == $opt ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Observações</label>
                                    <textarea name="observacoes" class="form-control" rows="3"><?php echo htmlspecialchars($candidato['observacoes']); ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Salvar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($candidatos) == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum candidato encontrado.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        function filtrarPorVaga() {
            const vagaId = document.getElementById('filtroVaga').value;
            if (vagaId) {
                window.location.href = 'candidatos.php?vaga_id=' + vagaId;
            } else {
                window.location.href = 'candidatos.php';
            }
        }
    </script>
</body>
</html>