<?php
// escola/rh/avaliacao/periodos.php - Gestão de Períodos de Avaliação
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
            INSERT INTO avaliacao_periodos (escola_id, nome, data_inicio, data_fim, peso, status)
            VALUES (?, ?, ?, ?, ?, 'pendente')
        ");
        $stmt->execute([
            $escola_id,
            $_POST['nome'],
            $_POST['data_inicio'],
            $_POST['data_fim'],
            $_POST['peso']
        ]);
        $success = "Período de avaliação criado com sucesso!";
    }
    
    if ($acao == 'atualizar_status') {
        $stmt = $conn->prepare("UPDATE avaliacao_periodos SET status = ? WHERE id = ? AND escola_id = ?");
        $stmt->execute([$_POST['status'], $_POST['id'], $escola_id]);
        $success = "Status atualizado!";
    }
}

// Buscar períodos
$stmt = $conn->prepare("SELECT * FROM avaliacao_periodos WHERE escola_id = ? ORDER BY data_inicio DESC");
$stmt->execute([$escola_id]);
$periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar critérios
$stmt = $conn->prepare("SELECT * FROM avaliacao_criterios WHERE status = 'ativo' ORDER BY ordem");
$stmt->execute();
$criterios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Períodos de Avaliação | SIGE Angola</title>
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
        .criterio-item { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 10px; }
    </style>
</head>
<body>
     <?php include '../../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-calendar-alt"></i> Períodos de Avaliação</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPeriodo">
                <i class="fas fa-plus"></i> Novo Período
            </button>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- Critérios de Avaliação -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list-check"></i> Critérios de Avaliação
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($criterios as $c): ?>
                    <div class="col-md-4">
                        <div class="criterio-item">
                            <strong><?php echo htmlspecialchars($c['nome']); ?></strong>
                            <span class="badge bg-primary float-end">Peso: <?php echo $c['peso']; ?></span>
                            <p class="small text-muted mt-2"><?php echo htmlspecialchars($c['descricao']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Lista de Períodos -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-calendar"></i> Períodos Cadastrados
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaPeriodos">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Período</th>
                                <th>Peso</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periodos as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['nome']); ?>                                </td>
                                <td><?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($p['data_fim'])); ?></td>
                                <td><?php echo $p['peso']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $p['status'] == 'ativa' ? 'success' : ($p['status'] == 'encerrada' ? 'secondary' : 'warning'); ?>">
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="iniciarAvaliacao(<?php echo $p['id']; ?>)">
                                        <i class="fas fa-play"></i> Avaliar
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="alterarStatus(<?php echo $p['id']; ?>, '<?php echo $p['status']; ?>')">
                                        <i class="fas fa-toggle-on"></i>
                                    </button>
                                    <a href="relatorio_avaliacao.php?periodo_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-chart-line"></i> Relatório
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Novo Período -->
    <div class="modal fade" id="modalNovoPeriodo" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Período de Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="salvar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Nome do Período</label>
                            <input type="text" name="nome" class="form-control" required placeholder="Ex: Avaliação 1º Semestre 2024">
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
                        <div class="mb-3">
                            <label>Peso do Período</label>
                            <input type="number" name="peso" class="form-control" step="0.1" value="1.0" required>
                            <small class="text-muted">Peso para cálculo da média final</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Criar Período</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
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
        
        $(document).ready(function() {
            $('#tabelaPeriodos').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                },
                order: [[1, 'desc']]
            });
        });
        
        function iniciarAvaliacao(periodoId) {
            window.location.href = 'avaliar_funcionarios.php?periodo_id=' + periodoId;
        }
        
        function alterarStatus(id, statusAtual) {
            let novoStatus = '';
            if (statusAtual == 'pendente') novoStatus = 'ativa';
            else if (statusAtual == 'ativa') novoStatus = 'encerrada';
            else if (statusAtual == 'encerrada') novoStatus = 'pendente';
            
            if (confirm(`Deseja alterar o status para ${novoStatus.toUpperCase()}?`)) {
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