<?php
// escola/financeiro/parcelamentos/simular.php - Simulação de Parcelas
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$resultado_simulacao = null;

// Processar simulação
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $valor_total = str_replace(',', '', $_POST['valor_total']);
    $entrada = str_replace(',', '', $_POST['entrada'] ?? 0);
    $numero_parcelas = $_POST['numero_parcelas'];
    $taxa_juros = $_POST['taxa_juros'] ?? 0;
    $data_inicio = $_POST['data_inicio'];
    
    $valor_restante = $valor_total - $entrada;
    $valor_parcela = $valor_restante / $numero_parcelas;
    
    // Aplicar juros se houver
    if ($taxa_juros > 0) {
        $valor_parcela = $valor_parcela * (1 + ($taxa_juros / 100));
    }
    
    $parcelas = [];
    for ($i = 1; $i <= $numero_parcelas; $i++) {
        $data_vencimento = date('Y-m-d', strtotime("+$i month", strtotime($data_inicio)));
        $parcelas[] = [
            'numero' => $i,
            'valor' => $valor_parcela,
            'vencimento' => $data_vencimento
        ];
    }
    
    $resultado_simulacao = [
        'valor_total' => $valor_total,
        'entrada' => $entrada,
        'valor_restante' => $valor_restante,
        'numero_parcelas' => $numero_parcelas,
        'valor_parcela' => $valor_parcela,
        'parcelas' => $parcelas,
        'taxa_juros' => $taxa_juros
    ];
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simular Parcelamento | SIGE Angola</title>
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
        
        .simulacao-card { background: #e8f5e9; border-radius: 15px; padding: 20px; margin-top: 20px; }
        .resumo-valor { font-size: 1.2em; font-weight: bold; }
        
        .modal-ajuda { border-radius: 15px; }
        .modal-ajuda .modal-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        .help-icon { font-size: 0.9em; margin-left: 8px; cursor: pointer; color: #17a2b8; }
        .help-icon:hover { color: #006B3E; }
        
        .required:after { content: "*"; color: red; margin-left: 5px; }
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
                    <li class="nav-item"><a href="simular.php" class="nav-link active"><i class="fas fa-calculator"></i> Simular</a></li>
                    <li class="nav-item"><a href="acompanhar.php" class="nav-link"><i class="fas fa-chart-line"></i> Acompanhar</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-calculator"></i> Simulação de Parcelamento
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-sliders-h"></i> Parâmetros da Simulação
            </div>
            <div class="card-body">
                <form method="POST" id="formSimulacao">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="required">Valor Total (Kz)</label>
                            <input type="number" step="0.01" name="valor_total" id="valor_total" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Entrada (Kz)</label>
                            <input type="number" step="0.01" name="entrada" id="entrada" class="form-control" value="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="required">Número de Parcelas</label>
                            <input type="number" name="numero_parcelas" id="numero_parcelas" class="form-control" min="1" max="36" value="6" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Taxa de Juros (%)</label>
                            <input type="number" step="0.01" name="taxa_juros" id="taxa_juros" class="form-control" value="0">
                            <small class="text-muted">Juros mensais sobre o saldo devedor</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="required">Data da Primeira Parcela</label>
                            <input type="date" name="data_inicio" id="data_inicio" class="form-control" required>
                        </div>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-calculator"></i> Simular
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($resultado_simulacao): ?>
        <div class="simulacao-card">
            <h4><i class="fas fa-chart-line"></i> Resultado da Simulação</h4>
            <hr>
            <div class="row mb-4">
                <div class="col-md-3 text-center">
                    <div class="resumo-valor">Valor Total</div>
                    <h3 class="text-primary"><?php echo number_format($resultado_simulacao['valor_total'], 2, ',', '.'); ?> Kz</h3>
                </div>
                <div class="col-md-3 text-center">
                    <div class="resumo-valor">Entrada</div>
                    <h3 class="text-success"><?php echo number_format($resultado_simulacao['entrada'], 2, ',', '.'); ?> Kz</h3>
                </div>
                <div class="col-md-3 text-center">
                    <div class="resumo-valor">Saldo a Parcelar</div>
                    <h3 class="text-warning"><?php echo number_format($resultado_simulacao['valor_restante'], 2, ',', '.'); ?> Kz</h3>
                </div>
                <div class="col-md-3 text-center">
                    <div class="resumo-valor">Valor da Parcela</div>
                    <h3 class="text-info"><?php echo number_format($resultado_simulacao['valor_parcela'], 2, ',', '.'); ?> Kz</h3>
                </div>
            </div>
            
            <h6><i class="fas fa-list"></i> Cronograma de Parcelas</h6>
            <div class="table-responsive">
                <table class="table table-bordered" id="tabelaParcelas">
                    <thead class="table-light">
                        <tr>
                            <th>Parcela</th>
                            <th>Data de Vencimento</th>
                            <th>Valor (Kz)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado_simulacao['parcelas'] as $parcela): ?>
                        <tr>
                            <td><?php echo $parcela['numero']; ?>ª parcela</div>
                            <td><?php echo date('d/m/Y', strtotime($parcela['vencimento'])); ?></div>
                            <td><?php echo number_format($parcela['valor'], 2, ',', '.'); ?> Kz</div>
                            <td><span class="badge bg-warning">Pendente</span></div>
                         </div>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="2">TOTAL</th>
                            <th><?php echo number_format($resultado_simulacao['valor_parcela'] * $resultado_simulacao['numero_parcelas'], 2, ',', '.'); ?> Kz</th>
                            <th></th>
                         </div>
                    </tfoot>
                 </div>
            </div>
            
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i> 
                Esta é uma simulação. Para formalizar o acordo, clique em 
                <a href="novo.php" class="alert-link">Novo Acordo</a>.
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Simulação de Parcelamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> Como simular?</h6>
                    <p>Informe o valor total, entrada e número de parcelas para visualizar o cronograma de pagamentos.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Fórmulas utilizadas:</h6>
                    <ul>
                        <li><strong>Saldo a parcelar:</strong> Valor Total - Entrada</li>
                        <li><strong>Valor da parcela:</strong> Saldo a parcelar / Número de parcelas</li>
                        <li><strong>Com juros:</strong> Parcela = (Saldo / Parcelas) × (1 + Taxa/100)</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Use a simulação para encontrar o melhor parcelamento.</li>
                        <li>Compare diferentes números de parcelas.</li>
                        <li>Calcule o impacto dos juros no valor final.</li>
                        <li>Após a simulação, crie o acordo formal.</li>
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
        
        // Data da primeira parcela padrão (próximo mês)
        const hoje = new Date();
        const proximoMes = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 10);
        $('#data_inicio').val(proximoMes.toISOString().split('T')[0]);
        
        <?php if ($resultado_simulacao): ?>
        $('#tabelaParcelas').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' },
            pageLength: 25,
            ordering: false,
            responsive: true
        });
        <?php endif; ?>
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>