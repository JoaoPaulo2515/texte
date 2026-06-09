<?php
// escola/financeiro/taxas/isencoes.php - Isenções e Descontos Especiais
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Adicionar isenção
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_isencao') {
    $aluno_id = $_POST['aluno_id'];
    $tipo = $_POST['tipo'];
    $percentual_desconto = $_POST['percentual_desconto'];
    $motivo = $_POST['motivo'];
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'];
    
    $stmt = $conn->prepare("
        INSERT INTO escola_isencoes (escola_id, aluno_id, tipo, percentual_desconto, motivo, data_inicio, data_fim, status, usuario_id)
        VALUES (:escola_id, :aluno_id, :tipo, :percentual_desconto, :motivo, :data_inicio, :data_fim, 'ativo', :usuario_id)
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':aluno_id' => $aluno_id,
        ':tipo' => $tipo,
        ':percentual_desconto' => $percentual_desconto,
        ':motivo' => $motivo,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim,
        ':usuario_id' => $usuario_id
    ]);
    
    $_SESSION['mensagem'] = "Isenção/Desconto concedido com sucesso!";
    header("Location: isencoes.php");
    exit;
}

// Cancelar isenção
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE escola_isencoes SET status = 'cancelado' WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Isenção cancelada!";
    header("Location: isencoes.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

$status_filter = $_GET['status'] ?? '';

$sql = "
    SELECT i.*, u.nome as aluno_nome, e.matricula
    FROM escola_isencoes i
    JOIN estudantes e ON e.id = i.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE i.escola_id = :escola_id
";
$params = [':escola_id' => $escola_id];

if ($status_filter) {
    $sql .= " AND i.status = :status";
    $params[':status'] = $status_filter;
}

$sql .= " ORDER BY i.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$isencoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para select
$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => count($isencoes),
    'ativas' => 0,
    'bolsas' => 0,
    'descontos' => 0
];

foreach ($isencoes as $i) {
    if ($i['status'] == 'ativo') $stats['ativas']++;
    if ($i['tipo'] == 'bolsa') $stats['bolsas']++;
    if ($i['tipo'] == 'desconto') $stats['descontos']++;
}

$tipos = [
    'bolsa' => 'Bolsa de Estudos',
    'desconto' => 'Desconto Especial',
    'isencao_total' => 'Isenção Total',
    'isencao_parcial' => 'Isenção Parcial'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Isenções e Descontos | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-ajuda { background: #17a2b8; color: white; border: none; }
        .btn-ajuda:hover { background: #138496; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.5em; font-weight: bold; }
        .stat-label { color: #666; font-size: 0.85em; margin-top: 5px; }
        
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .table-responsive { overflow-x: auto; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-expirado { background: #f8d7da; color: #721c24; }
        .badge-cancelado { background: #6c757d; color: white; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p><?php echo $_SESSION['escola_nome'] ?? 'Escola'; ?></p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item has-submenu open" id="menuFinanceiro">
                <a href="#" class="nav-link active" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> <span>Financeiro</span>
                </a>
                <ul class="nav-submenu show" id="submenuFinanceiro">
                    <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-percent"></i> Taxas</a></li>
                    <li class="nav-item"><a href="aplicar.php" class="nav-link"><i class="fas fa-play"></i> Aplicar Taxas</a></li>
                    <li class="nav-item"><a href="isencoes.php" class="nav-link active"><i class="fas fa-gift"></i> Isenções</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-gift"></i> Isenções e Descontos Especiais
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaIsencao">
                <i class="fas fa-plus"></i> Nova Isenção
            </button>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total de Isenções</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $stats['ativas']; ?></div>
                <div class="stat-label">Isenções Ativas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['bolsas']; ?></div>
                <div class="stat-label">Bolsas Concedidas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['descontos']; ?></div>
                <div class="stat-label">Descontos Especiais</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <select name="status" class="form-control">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $status_filter == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="expirado" <?php echo $status_filter == 'expirado' ? 'selected' : ''; ?>>Expirado</option>
                        <option value="cancelado" <?php echo $status_filter == 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        
        <!-- Lista de Isenções -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Isenções e Descontos Concedidos</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaIsencoes">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Tipo</th>
                                <th>Desconto</th>
                                <th>Período</th>
                                <th>Motivo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($isencoes as $isencao): ?>
                            <tr>
                                <td><?php echo $isencao['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($isencao['aluno_nome']); ?></strong></td>
                                <td><?php echo $isencao['matricula']; ?></td>
                                <td><?php echo $tipos[$isencao['tipo']]; ?></td>
                                <td>
                                    <?php if ($isencao['tipo'] == 'isencao_total'): ?>
                                        100%
                                    <?php elseif ($isencao['tipo'] == 'isencao_parcial'): ?>
                                        <?php echo $isencao['percentual_desconto']; ?>%
                                    <?php elseif ($isencao['tipo'] == 'desconto'): ?>
                                        <?php echo $isencao['percentual_desconto']; ?>%
                                    <?php else: ?>
                                        Bolsa
                                    <?php endif; ?>
                                 </div>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($isencao['data_inicio'])); ?> 
                                    até <?php echo date('d/m/Y', strtotime($isencao['data_fim'])); ?>
                                 </div>
                                <td><?php echo htmlspecialchars($isencao['motivo']); ?></div>
                                <td>
                                    <span class="badge <?php 
                                        echo $isencao['status'] == 'ativo' ? 'badge-ativo' : 
                                            ($isencao['status'] == 'expirado' ? 'badge-expirado' : 'badge-cancelado'); 
                                    ?>">
                                        <?php echo ucfirst($isencao['status']); ?>
                                    </span>
                                 </div>
                                <td>
                                    <?php if ($isencao['status'] == 'ativo'): ?>
                                    <a href="?cancelar=1&id=<?php echo $isencao['id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Cancelar esta isenção?')">
                                        <i class="fas fa-times"></i> Cancelar
                                    </a>
                                    <?php endif; ?>
                                 </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Isenção -->
    <div class="modal fade" id="modalNovaIsencao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-gift"></i> Conceder Isenção/Desconto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_isencao">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="required">Aluno</label>
                            <select name="aluno_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($alunos as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="required">Tipo de Benefício</label>
                            <select name="tipo" id="tipo_beneficio" class="form-control" required onchange="togglePercentual()">
                                <option value="bolsa">Bolsa de Estudos</option>
                                <option value="desconto">Desconto Especial</option>
                                <option value="isencao_total">Isenção Total</option>
                                <option value="isencao_parcial">Isenção Parcial</option>
                            </select>
                        </div>
                        <div class="mb-3" id="div_percentual">
                            <label>Percentual de Desconto (%)</label>
                            <input type="number" step="0.01" name="percentual_desconto" class="form-control" value="0" min="0" max="100">
                        </div>
                        <div class="mb-3">
                            <label class="required">Motivo</label>
                            <textarea name="motivo" class="form-control" rows="2" required placeholder="Ex: Bolsa de mérito acadêmico, Situação socioeconômica, etc."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="required">Data de Início</label>
                                <input type="date" name="data_inicio" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="required">Data de Término</label>
                                <input type="date" name="data_fim" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Isenções e descontos são aplicados automaticamente no cálculo das mensalidades.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Conceder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Isenções e Descontos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que são Isenções e Descontos?</h6>
                    <p>Benefícios concedidos a alunos que reduzem ou isentam o valor das mensalidades.</p>
                    
                    <h6><i class="fas fa-tag text-success"></i> Tipos de Benefício:</h6>
                    <ul>
                        <li><strong>Bolsa de Estudos:</strong> Benefício integral ou parcial para alunos de destaque.</li>
                        <li><strong>Desconto Especial:</strong> Redução percentual temporária ou permanente.</li>
                        <li><strong>Isenção Total:</strong> Dispensa completa do pagamento.</li>
                        <li><strong>Isenção Parcial:</strong> Dispensa de parte do valor.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Documente o motivo da concessão.</li>
                        <li>Defina prazos claros para o benefício.</li>
                        <li>Revise periodicamente as isenções ativas.</li>
                        <li>Mantenha registro histórico das concessões.</li>
                    </ul>
                    
                    <hr>
                    <p class="text-muted small mb-0"><i class="fas fa-clock"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function togglePercentual() {
            const tipo = $('#tipo_beneficio').val();
            if (tipo === 'isencao_total') {
                $('#div_percentual').hide();
            } else {
                $('#div_percentual').show();
            }
        }
        
        $(document).ready(function() {
            togglePercentual();
        });
        
        $('#tabelaIsencoes').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'desc']]
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>