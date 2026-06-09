<?php
// escola/financeiro/parcelamentos/acompanhar.php - Acompanhamento de Parcelas
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

$id = $_GET['id'] ?? 0;

// Buscar acordo
$stmt = $conn->prepare("
    SELECT a.*, u.nome as aluno_nome, e.matricula
    FROM escola_acordos_parcelamento a
    JOIN estudantes e ON e.id = a.aluno_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE a.id = :id AND a.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$acordo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$acordo) {
    $_SESSION['erro'] = "Acordo não encontrado!";
    header("Location: index.php");
    exit;
}

// Buscar parcelas
$stmt = $conn->prepare("
    SELECT p.*, f.nome as forma_pagamento_nome
    FROM escola_parcelas_acordo p
    LEFT JOIN escola_formas_pagamento f ON f.id = p.forma_pagamento_id
    WHERE p.acordo_id = :acordo_id
    ORDER BY p.numero_parcela ASC
");
$stmt->execute([':acordo_id' => $id]);
$parcelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_pago = 0;
$total_pendente = 0;
$parcelas_pagas = 0;
$parcelas_pendentes = 0;

foreach ($parcelas as $p) {
    if ($p['status'] == 'pago') {
        $total_pago += $p['valor'];
        $parcelas_pagas++;
    } else {
        $total_pendente += ($p['valor'] - $p['valor_pago']);
        $parcelas_pendentes++;
    }
}

$progresso = $acordo['numero_parcelas'] > 0 ? round(($parcelas_pagas / $acordo['numero_parcelas']) * 100) : 0;

// Processar pagamento de parcela
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'pagar_parcela') {
    $parcela_id = $_POST['parcela_id'];
    $valor_pago = str_replace(',', '', $_POST['valor_pago']);
    $data_pagamento = $_POST['data_pagamento'];
    $forma_pagamento_id = $_POST['forma_pagamento_id'] ?: null;
    
    // Buscar parcela
    $stmt = $conn->prepare("SELECT * FROM escola_parcelas_acordo WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $parcela_id, ':escola_id' => $escola_id]);
    $parcela = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parcela) {
        $novo_valor_pago = $parcela['valor_pago'] + $valor_pago;
        $novo_status = ($novo_valor_pago >= $parcela['valor']) ? 'pago' : 'parcial';
        
        $stmt = $conn->prepare("
            UPDATE escola_parcelas_acordo 
            SET valor_pago = :valor_pago, data_pagamento = :data_pagamento, 
                forma_pagamento_id = :forma_pagamento_id, status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':valor_pago' => $novo_valor_pago,
            ':data_pagamento' => $data_pagamento,
            ':forma_pagamento_id' => $forma_pagamento_id,
            ':status' => $novo_status,
            ':id' => $parcela_id
        ]);
        
        // Verificar se todas as parcelas estão pagas
        $stmt = $conn->prepare("
            SELECT COUNT(*) as pendentes FROM escola_parcelas_acordo 
            WHERE acordo_id = :acordo_id AND status != 'pago'
        ");
        $stmt->execute([':acordo_id' => $id]);
        $pendentes = $stmt->fetch(PDO::FETCH_ASSOC)['pendentes'];
        
        if ($pendentes == 0) {
            $stmt = $conn->prepare("UPDATE escola_acordos_parcelamento SET status = 'concluido' WHERE id = :id");
            $stmt->execute([':id' => $id]);
        }
        
        $_SESSION['mensagem'] = "Pagamento registrado com sucesso!";
        header("Location: acompanhar.php?id=$id");
        exit;
    }
}

$mensagem = $_SESSION['mensagem'] ?? '';
$erro = $_SESSION['erro'] ?? '';
unset($_SESSION['mensagem'], $_SESSION['erro']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acompanhar Parcelamento | SIGE Angola</title>
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
        
        .info-aluno { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .progress-circle { width: 100px; height: 100px; border-radius: 50%; background: #e9ecef; display: flex; align-items: center; justify-content: center; margin: 0 auto; }
        .progress-value { font-size: 1.5em; font-weight: bold; }
        
        .badge-pendente { background: #ffc107; color: #000; }
        .badge-pago { background: #28a745; color: white; }
        .badge-vencido { background: #dc3545; color: white; }
        .badge-parcial { background: #17a2b8; color: white; }
        
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
                    <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
                    <li class="nav-item"><a href="novo.php" class="nav-link"><i class="fas fa-plus"></i> Novo Acordo</a></li>
                    <li class="nav-item"><a href="simular.php" class="nav-link"><i class="fas fa-calculator"></i> Simular</a></li>
                    <li class="nav-item"><a href="acompanhar.php" class="nav-link active"><i class="fas fa-chart-line"></i> Acompanhar</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-chart-line"></i> Acompanhamento do Acordo
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($acordo['aluno_nome']); ?></h5>
                    <p class="mb-0">Matrícula: <?php echo $acordo['matricula']; ?></p>
                    <p class="mb-0">Acordo: <?php echo htmlspecialchars($acordo['titulo']); ?></p>
                    <p class="mb-0">Data do Acordo: <?php echo date('d/m/Y', strtotime($acordo['data_acordo'])); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p class="mb-0"><strong>Status:</strong> 
                        <span class="badge badge-<?php echo $acordo['status']; ?>">
                            <?php echo $acordo['status'] == 'ativo' ? 'Ativo' : ($acordo['status'] == 'concluido' ? 'Concluído' : 'Cancelado'); ?>
                        </span>
                    </p>
                    <p class="mb-0"><strong>Valor Total:</strong> <?php echo number_format($acordo['valor_total'], 2, ',', '.'); ?> Kz</p>
                    <p class="mb-0"><strong>Valor Pago:</strong> <?php echo number_format($total_pago, 2, ',', '.'); ?> Kz</p>
                    <p class="mb-0"><strong>Saldo Devedor:</strong> <?php echo number_format($total_pendente, 2, ',', '.'); ?> Kz</p>
                </div>
            </div>
        </div>
        
        <!-- Progresso -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-chart-simple"></i> Progresso do Parcelamento</div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="progress-circle">
                            <div class="progress-value"><?php echo $progresso; ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="progress mb-2" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $progresso; ?>%"><?php echo $progresso; ?>%</div>
                        </div>
                        <div class="row text-center">
                            <div class="col-4">
                                <h5><?php echo $parcelas_pagas; ?></h5>
                                <small>Parcelas Pagas</small>
                            </div>
                            <div class="col-4">
                                <h5><?php echo $parcelas_pendentes; ?></h5>
                                <small>Parcelas Pendentes</small>
                            </div>
                            <div class="col-4">
                                <h5><?php echo $acordo['numero_parcelas']; ?></h5>
                                <small>Total de Parcelas</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Lista de Parcelas -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Cronograma de Pagamento</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="tabelaParcelas">
                        <thead class="table-light">
                            <tr>
                                <th>Parcela</th>
                                <th>Vencimento</th>
                                <th>Valor (Kz)</th>
                                <th>Valor Pago (Kz)</th>
                                <th>Saldo (Kz)</th>
                                <th>Status</th>
                                <th>Data Pagamento</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parcelas as $parcela): 
                                $saldo = $parcela['valor'] - $parcela['valor_pago'];
                                $hoje = date('Y-m-d');
                                $vencida = ($parcela['data_vencimento'] < $hoje && $parcela['status'] != 'pago');
                            ?>
                            <tr>
                                <td><?php echo $parcela['numero_parcela'] == 0 ? 'Entrada' : $parcela['numero_parcela'] . 'ª parcela'; ?></td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($parcela['data_vencimento'])); ?>
                                    <?php if ($vencida): ?>
                                        <br><span class="badge bg-danger">Vencida</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td><?php echo number_format($parcela['valor'], 2, ',', '.'); ?> Kz</div>
                                <td><?php echo number_format($parcela['valor_pago'], 2, ',', '.'); ?> Kz</div>
                                <td>
                                    <?php if ($saldo > 0): ?>
                                        <span class="text-danger"><?php echo number_format($saldo, 2, ',', '.'); ?> Kz</span>
                                    <?php else: ?>
                                        <span class="text-success">Quitado</span>
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td>
                                    <span class="badge badge-<?php echo $parcela['status']; ?>">
                                        <?php echo ucfirst($parcela['status']); ?>
                                    </span>
                                 </div>
                                </div>
                                <td>
                                    <?php if ($parcela['data_pagamento']): ?>
                                        <?php echo date('d/m/Y', strtotime($parcela['data_pagamento'])); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                 </div>
                                </div>
                                <td>
                                    <?php if ($acordo['status'] == 'ativo' && $parcela['status'] != 'pago' && $parcela['numero_parcela'] != 0): ?>
                                    <button class="btn btn-sm btn-success" onclick="registrarPagamento(<?php echo $parcela['id']; ?>, <?php echo $saldo; ?>)">
                                        <i class="fas fa-money-bill"></i> Pagar
                                    </button>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-info" onclick="verDetalhes(<?php echo $parcela['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php endif; ?>
                                 </div>
                                </div>
                             </div>
                            <?php endforeach; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
        
        <!-- Descrição do Acordo -->
        <?php if ($acordo['descricao']): ?>
        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-file-alt"></i> Descrição do Acordo</div>
            <div class="card-body">
                <p><?php echo nl2br(htmlspecialchars($acordo['descricao'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Registrar Pagamento -->
    <div class="modal fade" id="modalRegistrarPagamento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill"></i> Registrar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="pagar_parcela">
                    <input type="hidden" name="parcela_id" id="pagamento_parcela_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Parcela:</strong> <span id="pagamento_parcela_numero"></span><br>
                            <strong>Saldo Devedor:</strong> <span id="pagamento_saldo" class="text-danger fw-bold"></span>
                        </div>
                        <div class="mb-3">
                            <label>Valor a Pagar (Kz)</label>
                            <input type="number" step="0.01" name="valor_pago" id="pagamento_valor" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Data do Pagamento</label>
                            <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Forma de Pagamento</label>
                            <select name="forma_pagamento_id" class="form-control">
                                <option value="">Selecione...</option>
                                <?php foreach ($formas_pagamento as $fp): ?>
                                <option value="<?php echo $fp['id']; ?>"><?php echo htmlspecialchars($fp['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Registrar Pagamento</button>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Acompanhamento de Parcelas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> O que é o Acompanhamento?</h6>
                    <p>Visualize o status de cada parcela do acordo, registre pagamentos e acompanhe o progresso.</p>
                    
                    <h6><i class="fas fa-chart-line text-success"></i> Funcionalidades:</h6>
                    <ul>
                        <li><strong>Progresso:</strong> Gráfico mostrando o percentual concluído.</li>
                        <li><strong>Parcelas:</strong> Lista completa com status individual.</li>
                        <li><strong>Registrar Pagamento:</strong> Atualize o status da parcela.</li>
                        <li><strong>Histórico:</strong> Datas de pagamento e formas utilizadas.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Registre os pagamentos imediatamente.</li>
                        <li>Parcelas vencidas são destacadas em vermelho.</li>
                        <li>O acordo é automaticamente concluído quando todas as parcelas são pagas.</li>
                        <li>Use o botão "Pagar" para registrar parcelas parciais.</li>
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
        
        function registrarPagamento(id, saldo) {
            $('#pagamento_parcela_id').val(id);
            $('#pagamento_valor').val(saldo);
            $('#pagamento_saldo').text(parseFloat(saldo).toLocaleString('pt-AO', {minimumFractionDigits: 2}) + ' Kz');
            $('#modalRegistrarPagamento').modal('show');
        }
        
        function verDetalhes(id) {
            alert('Detalhes da parcela ID: ' + id);
        }
        
        $('#tabelaParcelas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            order: [[0, 'asc']],
            responsive: true
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>