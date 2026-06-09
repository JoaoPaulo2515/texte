<?php
// escola/financeiro/parcelamentos/novo.php - Novo Parcelamento
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

// Buscar alunos
$alunos = $conn->prepare("
    SELECT e.id, u.nome, e.matricula 
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.escola_id = :escola_id
    ORDER BY u.nome ASC
");
$alunos->execute([':escola_id' => $escola_id]);
$alunos = $alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar formas de pagamento
$formas_pagamento = $conn->prepare("SELECT id, nome FROM escola_formas_pagamento WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome");
$formas_pagamento->execute([':escola_id' => $escola_id]);
$formas_pagamento = $formas_pagamento->fetchAll(PDO::FETCH_ASSOC);

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'novo_acordo') {
    $aluno_id = $_POST['aluno_id'];
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $valor_total = str_replace(',', '', $_POST['valor_total']);
    $entrada = str_replace(',', '', $_POST['entrada'] ?? 0);
    $numero_parcelas = $_POST['numero_parcelas'];
    $data_acordo = $_POST['data_acordo'];
    $data_primeira_parcela = $_POST['data_primeira_parcela'];
    
    // Calcular valor da parcela
    $valor_restante = $valor_total - $entrada;
    $valor_parcela = round($valor_restante / $numero_parcelas, 2);
    
    try {
        $conn->beginTransaction();
        
        // Inserir acordo
        $stmt = $conn->prepare("
            INSERT INTO escola_acordos_parcelamento 
            (escola_id, aluno_id, titulo, descricao, valor_total, numero_parcelas, valor_parcela, entrada, data_acordo, status)
            VALUES 
            (:escola_id, :aluno_id, :titulo, :descricao, :valor_total, :numero_parcelas, :valor_parcela, :entrada, :data_acordo, 'ativo')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':titulo' => $titulo,
            ':descricao' => $descricao,
            ':valor_total' => $valor_total,
            ':numero_parcelas' => $numero_parcelas,
            ':valor_parcela' => $valor_parcela,
            ':entrada' => $entrada,
            ':data_acordo' => $data_acordo
        ]);
        
        $acordo_id = $conn->lastInsertId();
        
        // Gerar parcelas
        for ($i = 1; $i <= $numero_parcelas; $i++) {
            $data_vencimento = date('Y-m-d', strtotime("+$i month", strtotime($data_primeira_parcela)));
            
            $stmt = $conn->prepare("
                INSERT INTO escola_parcelas_acordo 
                (escola_id, acordo_id, numero_parcela, valor, data_vencimento, status)
                VALUES 
                (:escola_id, :acordo_id, :numero, :valor, :data_vencimento, 'pendente')
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':acordo_id' => $acordo_id,
                ':numero' => $i,
                ':valor' => $valor_parcela,
                ':data_vencimento' => $data_vencimento
            ]);
        }
        
        // Registrar entrada se houver
        if ($entrada > 0) {
            $stmt = $conn->prepare("
                INSERT INTO escola_parcelas_acordo 
                (escola_id, acordo_id, numero_parcela, valor, valor_pago, data_vencimento, data_pagamento, status)
                VALUES 
                (:escola_id, :acordo_id, 0, :valor, :valor, :data_pagamento, :data_pagamento, 'pago')
            ");
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':acordo_id' => $acordo_id,
                ':valor' => $entrada,
                ':data_pagamento' => $data_acordo
            ]);
        }
        
        $conn->commit();
        
        $_SESSION['mensagem'] = "Acordo de parcelamento criado com sucesso!";
        header("Location: index.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao criar acordo: " . $e->getMessage();
    }
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Parcelamento | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .preview-parcela { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-top: 20px; display: none; }
        
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
                    <li class="nav-item"><a href="novo.php" class="nav-link active"><i class="fas fa-plus"></i> Novo Acordo</a></li>
                    <li class="nav-item"><a href="simular.php" class="nav-link"><i class="fas fa-calculator"></i> Simular</a></li>
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
                <i class="fas fa-plus"></i> Novo Acordo de Parcelamento
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-edit"></i> Dados do Parcelamento
            </div>
            <div class="card-body">
                <form method="POST" id="formAcordo">
                    <input type="hidden" name="acao" value="novo_acordo">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="required">Aluno</label>
                            <select name="aluno_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($alunos as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="required">Título do Acordo</label>
                            <input type="text" name="titulo" class="form-control" required placeholder="Ex: Parcelamento Mensalidades 2024">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Descrição</label>
                        <textarea name="descricao" class="form-control" rows="2" placeholder="Detalhes do acordo..."></textarea>
                    </div>
                    
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
                            <input type="number" name="numero_parcelas" id="numero_parcelas" class="form-control" min="1" max="24" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="required">Data do Acordo</label>
                            <input type="date" name="data_acordo" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="required">Data da Primeira Parcela</label>
                            <input type="date" name="data_primeira_parcela" id="data_primeira_parcela" class="form-control" required>
                        </div>
                    </div>
                    
                    <!-- Preview das Parcelas -->
                    <div class="preview-parcela" id="previewParcelas">
                        <h6><i class="fas fa-calculator"></i> Simulação das Parcelas</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Valor da Parcela:</strong> <span id="preview_valor_parcela">0,00</span> Kz</p>
                                <p><strong>Total com Entrada:</strong> <span id="preview_valor_entrada">0,00</span> Kz</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Número de Parcelas:</strong> <span id="preview_numero_parcelas">0</span></p>
                                <p><strong>Valor Total:</strong> <span id="preview_valor_total">0,00</span> Kz</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção!</strong> Ao criar o acordo, as parcelas serão geradas automaticamente. 
                        Certifique-se dos valores antes de confirmar.
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Confirmar a criação deste acordo de parcelamento?')">
                            <i class="fas fa-save"></i> Criar Acordo
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg"><i class="fas fa-times"></i> Cancelar</a>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Criando um Acordo de Parcelamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> Como criar um acordo?</h6>
                    <p>Preencha os dados do aluno, o valor total da dívida e defina o número de parcelas.</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Cálculo das Parcelas:</h6>
                    <ul>
                        <li>Valor da parcela = (Valor Total - Entrada) / Número de Parcelas</li>
                        <li>As parcelas são geradas automaticamente com vencimentos mensais</li>
                        <li>A primeira parcela vence na data selecionada</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Recomendações:</h6>
                    <ul>
                        <li>Limite máximo de 24 parcelas para controle financeiro.</li>
                        <li>Registre a entrada no momento da formalização.</li>
                        <li>Documente o acordo com o aluno.</li>
                        <li>Acompanhe as parcelas mensalmente.</li>
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
        
        function calcularParcelas() {
            const valorTotal = parseFloat($('#valor_total').val()) || 0;
            const entrada = parseFloat($('#entrada').val()) || 0;
            const numParcelas = parseInt($('#numero_parcelas').val()) || 0;
            
            if (valorTotal > 0 && numParcelas > 0) {
                const valorRestante = valorTotal - entrada;
                const valorParcela = valorRestante / numParcelas;
                
                $('#preview_valor_parcela').text(valorParcela.toLocaleString('pt-AO', {minimumFractionDigits: 2}));
                $('#preview_valor_entrada').text(entrada.toLocaleString('pt-AO', {minimumFractionDigits: 2}));
                $('#preview_numero_parcelas').text(numParcelas);
                $('#preview_valor_total').text(valorTotal.toLocaleString('pt-AO', {minimumFractionDigits: 2}));
                $('#previewParcelas').show();
            } else {
                $('#previewParcelas').hide();
            }
        }
        
        $('#valor_total, #entrada, #numero_parcelas').on('input', calcularParcelas);
        
        // Data da primeira parcela padrão (próximo mês)
        const hoje = new Date();
        const proximoMes = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 10);
        $('#data_primeira_parcela').val(proximoMes.toISOString().split('T')[0]);
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>