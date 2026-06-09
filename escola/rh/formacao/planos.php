<?php
// escola/rh/formacao/planos.php - Planos de Formação
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'salvar') {
        $stmt = $conn->prepare("
            INSERT INTO planos_formacao (escola_id, titulo, descricao, objetivos, data_inicio, data_fim, carga_horaria, local, formador, custo, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planejado')
        ");
        $stmt->execute([
            $escola_id,
            $_POST['titulo'],
            $_POST['descricao'],
            $_POST['objetivos'],
            $_POST['data_inicio'],
            $_POST['data_fim'],
            $_POST['carga_horaria'],
            $_POST['local'],
            $_POST['formador'],
            $_POST['custo']
        ]);
        $success = "Plano de formação criado com sucesso!";
    }
    
    if ($acao == 'atualizar_status') {
        $stmt = $conn->prepare("UPDATE planos_formacao SET status = ? WHERE id = ? AND escola_id = ?");
        $stmt->execute([$_POST['status'], $_POST['id'], $escola_id]);
        $success = "Status atualizado!";
    }
}

// Buscar planos
$stmt = $conn->prepare("SELECT * FROM planos_formacao WHERE escola_id = ? ORDER BY data_inicio DESC");
$stmt->execute([$escola_id]);
$planos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar funcionários para inscrição
$stmt = $conn->prepare("SELECT id, nome, cargo FROM funcionarios WHERE escola_id = ? AND status = 'ativo' ORDER BY nome");
$stmt->execute([$escola_id]);
$funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planos de Formação | SIGE Angola</title>
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
        .plano-card { transition: transform 0.3s; margin-bottom: 20px; cursor: pointer; }
        .plano-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-graduation-cap"></i> Planos de Formação</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPlano">
                <i class="fas fa-plus"></i> Novo Plano
            </button>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <?php foreach ($planos as $plano): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card plano-card" onclick="verDetalhes(<?php echo $plano['id']; ?>)">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h5 class="card-title"><?php echo htmlspecialchars($plano['titulo']); ?></h5>
                            <span class="badge bg-<?php 
                                echo $plano['status'] == 'concluido' ? 'success' : 
                                    ($plano['status'] == 'em_andamento' ? 'primary' : 
                                    ($plano['status'] == 'planejado' ? 'warning' : 'secondary')); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $plano['status'])); ?>
                            </span>
                        </div>
                        <p class="card-text small mt-2"><?php echo substr(htmlspecialchars($plano['descricao']), 0, 100); ?>...</p>
                        <hr>
                        <div class="row small">
                            <div class="col-6">
                                <i class="fas fa-calendar"></i> Início: <?php echo date('d/m/Y', strtotime($plano['data_inicio'])); ?>
                            </div>
                            <div class="col-6">
                                <i class="fas fa-calendar-check"></i> Fim: <?php echo date('d/m/Y', strtotime($plano['data_fim'])); ?>
                            </div>
                        </div>
                        <div class="row small mt-2">
                            <div class="col-6">
                                <i class="fas fa-clock"></i> Carga: <?php echo $plano['carga_horaria']; ?>h
                            </div>
                            <div class="col-6">
                                <i class="fas fa-map-marker-alt"></i> Local: <?php echo htmlspecialchars($plano['local']); ?>
                            </div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-chalkboard-user"></i> <?php echo htmlspecialchars($plano['formador']); ?></span>
                            <span><i class="fas fa-money-bill"></i> <?php echo number_format($plano['custo'], 2); ?> Kz</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($planos) == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum plano de formação cadastrado.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Novo Plano -->
    <div class="modal fade" id="modalNovoPlano" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Plano de Formação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="salvar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título da Formação</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Data Início</label>
                                    <input type="date" name="data_inicio" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Data Fim</label>
                                    <input type="date" name="data_fim" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Carga Horária (horas)</label>
                                    <input type="number" name="carga_horaria" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Custo (Kz)</label>
                                    <input type="number" name="custo" class="form-control" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Local</label>
                                    <input type="text" name="local" class="form-control" placeholder="Ex: Auditório da Escola">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Formador</label>
                                    <input type="text" name="formador" class="form-control" placeholder="Nome do formador">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Objetivos</label>
                            <textarea name="objetivos" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Criar Plano</button>
                    </div>
                </form>
            </div>
        </div>
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
        
        function verDetalhes(id) {
            window.location.href = 'detalhes_plano.php?id=' + id;
        }
    </script>
</body>
</html>