<?php
// escola/rh/recrutamento/vagas.php - Gestão de Vagas de Emprego
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
            INSERT INTO vagas_emprego (escola_id, titulo, descricao, requisitos, tipo_contrato, cargo, quantidade, data_abertura, data_fecho, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $escola_id,
            $_POST['titulo'],
            $_POST['descricao'],
            $_POST['requisitos'],
            $_POST['tipo_contrato'],
            $_POST['cargo'],
            $_POST['quantidade'],
            $_POST['data_abertura'],
            $_POST['data_fecho'],
            'aberta'
        ]);
        $success = "Vaga criada com sucesso!";
    }
    
    if ($acao == 'atualizar_status') {
        $stmt = $conn->prepare("UPDATE vagas_emprego SET status = ? WHERE id = ? AND escola_id = ?");
        $stmt->execute([$_POST['status'], $_POST['id'], $escola_id]);
        $success = "Status atualizado!";
    }
}

// Buscar vagas
$stmt = $conn->prepare("SELECT * FROM vagas_emprego WHERE escola_id = ? ORDER BY data_abertura DESC");
$stmt->execute([$escola_id]);
$vagas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipos_contrato = ['Efetivo', 'Contratado', 'Estágio Profissional', 'Temporário', 'Prestador de Serviços'];
$cargos = ['Professor', 'Coordenador', 'Secretário', 'Assistente Administrativo', 'Auxiliar', 'Segurança', 'Motorista'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vagas de Emprego | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .vaga-card { transition: transform 0.3s; cursor: pointer; }
        .vaga-card:hover { transform: translateY(-5px); }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
    </style>
</head>
<body>
  
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-bullhorn"></i> Vagas de Emprego</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaVaga">
                <i class="fas fa-plus"></i> Nova Vaga
            </button>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <?php foreach ($vagas as $vaga): ?>
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card vaga-card">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($vaga['titulo']); ?></h5>
                        <h6 class="card-subtitle mb-2 text-muted">
                            <i class="fas fa-briefcase"></i> <?php echo $vaga['cargo']; ?> | 
                            <i class="fas fa-file-contract"></i> <?php echo $vaga['tipo_contrato']; ?>
                        </h6>
                        <p class="card-text small"><?php echo substr(htmlspecialchars($vaga['descricao']), 0, 100); ?>...</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-<?php echo $vaga['status'] == 'aberta' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst($vaga['status']); ?>
                                </span>
                                <small class="text-muted">
                                    <i class="fas fa-users"></i> <?php echo $vaga['quantidade']; ?> vagas
                                </small>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="verDetalhes(<?php echo $vaga['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="alterarStatus(<?php echo $vaga['id']; ?>, '<?php echo $vaga['status']; ?>')">
                                    <i class="fas fa-toggle-on"></i>
                                </button>
                            </div>
                        </div>
                        <hr>
                        <small class="text-muted">
                            <i class="fas fa-calendar"></i> Abertura: <?php echo date('d/m/Y', strtotime($vaga['data_abertura'])); ?>
                            <?php if ($vaga['data_fecho']): ?>
                                | Fecho: <?php echo date('d/m/Y', strtotime($vaga['data_fecho'])); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Modal Nova Vaga -->
    <div class="modal fade" id="modalNovaVaga" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Vaga de Emprego</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="salvar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Título da Vaga</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Cargo</label>
                                    <select name="cargo" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($cargos as $c): ?>
                                            <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Tipo de Contrato</label>
                                    <select name="tipo_contrato" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($tipos_contrato as $tc): ?>
                                            <option value="<?php echo $tc; ?>"><?php echo $tc; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Data de Abertura</label>
                                    <input type="date" name="data_abertura" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Data de Fecho</label>
                                    <input type="date" name="data_fecho" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Quantidade de Vagas</label>
                            <input type="number" name="quantidade" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label>Descrição da Vaga</label>
                            <textarea name="descricao" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Requisitos</label>
                            <textarea name="requisitos" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Publicar Vaga</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
            window.location.href = 'detalhes_vaga.php?id=' + id;
        }
        
        function alterarStatus(id, statusAtual) {
            const novoStatus = statusAtual == 'aberta' ? 'fechada' : 'aberta';
            if (confirm(`Deseja ${novoStatus == 'aberta' ? 'reabrir' : 'fechar'} esta vaga?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="acao" value="atualizar_status">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="status" value="${novoStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>