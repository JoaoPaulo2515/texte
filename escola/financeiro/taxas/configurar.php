<?php
// escola/financeiro/taxas/configurar.php - Configuração de Taxas
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
$taxa = null;

// Buscar taxa para edição
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM escola_taxas WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $taxa = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $tipo = $_POST['tipo'];
    $aplicacao = $_POST['aplicacao'];
    $valor = str_replace(',', '', $_POST['valor']);
    $percentual = str_replace(',', '.', $_POST['percentual']);
    $periodo = $_POST['periodo'];
    $data_inicio = $_POST['data_inicio'] ?: null;
    $data_fim = $_POST['data_fim'] ?: null;
    
    if ($id) {
        // Atualizar
        $stmt = $conn->prepare("
            UPDATE escola_taxas 
            SET nome = :nome, descricao = :descricao, tipo = :tipo, 
                aplicacao = :aplicacao, valor = :valor, percentual = :percentual,
                periodo = :periodo, data_inicio = :data_inicio, data_fim = :data_fim
            WHERE id = :id AND escola_id = :escola_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':aplicacao' => $aplicacao,
            ':valor' => $valor,
            ':percentual' => $percentual,
            ':periodo' => $periodo,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        $_SESSION['mensagem'] = "Taxa atualizada com sucesso!";
    } else {
        // Inserir
        $stmt = $conn->prepare("
            INSERT INTO escola_taxas (escola_id, nome, descricao, tipo, aplicacao, valor, percentual, periodo, data_inicio, data_fim, status)
            VALUES (:escola_id, :nome, :descricao, :tipo, :aplicacao, :valor, :percentual, :periodo, :data_inicio, :data_fim, 'ativo')
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':tipo' => $tipo,
            ':aplicacao' => $aplicacao,
            ':valor' => $valor,
            ':percentual' => $percentual,
            ':periodo' => $periodo,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        $_SESSION['mensagem'] = "Taxa criada com sucesso!";
    }
    
    header("Location: index.php");
    exit;
}

$tipos = [
    'matricula' => 'Matrícula',
    'mensalidade' => 'Mensalidade',
    'taxa_escolar' => 'Taxa Escolar',
    'multa' => 'Multa',
    'juros' => 'Juros',
    'outros' => 'Outros'
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $id ? 'Editar' : 'Nova'; ?> Taxa | SIGE Angola</title>
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
                </ul>
            </li>
            <li class="nav-item"><a href="../../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>
                <i class="fas fa-percent"></i> <?php echo $id ? 'Editar' : 'Nova'; ?> Taxa
                <i class="fas fa-question-circle help-icon" data-bs-toggle="modal" data-bs-target="#modalAjuda"></i>
            </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-edit"></i> Configuração da Taxa
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="required">Nome da Taxa</label>
                            <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($taxa['nome'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="required">Tipo</label>
                            <select name="tipo" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tipos as $key => $tipo): ?>
                                <option value="<?php echo $key; ?>" <?php echo (isset($taxa) && $taxa['tipo'] == $key) ? 'selected' : ''; ?>><?php echo $tipo; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Descrição</label>
                        <textarea name="descricao" class="form-control" rows="2"><?php echo htmlspecialchars($taxa['descricao'] ?? ''); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="required">Aplicação</label>
                            <select name="aplicacao" id="aplicacao" class="form-control" required onchange="toggleValorPercentual()">
                                <option value="fixo" <?php echo (isset($taxa) && $taxa['aplicacao'] == 'fixo') ? 'selected' : ''; ?>>Valor Fixo (Kz)</option>
                                <option value="percentual" <?php echo (isset($taxa) && $taxa['aplicacao'] == 'percentual') ? 'selected' : ''; ?>>Percentual (%)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="div_valor">
                            <label>Valor (Kz)</label>
                            <input type="number" step="0.01" name="valor" class="form-control" value="<?php echo $taxa['valor'] ?? 0; ?>">
                        </div>
                        <div class="col-md-6 mb-3" id="div_percentual" style="display: none;">
                            <label>Percentual (%)</label>
                            <input type="number" step="0.01" name="percentual" class="form-control" value="<?php echo $taxa['percentual'] ?? 0; ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Período de Aplicação</label>
                        <input type="text" name="periodo" class="form-control" placeholder="Ex: 1º Bimestre, Anual" value="<?php echo htmlspecialchars($taxa['periodo'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Data de Início</label>
                            <input type="date" name="data_inicio" class="form-control" value="<?php echo $taxa['data_inicio'] ?? ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Data de Término</label>
                            <input type="date" name="data_fim" class="form-control" value="<?php echo $taxa['data_fim'] ?? ''; ?>">
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Se não informar data de início/término, a taxa será válida permanentemente.
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                        <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
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
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Configurando Taxas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle text-primary"></i> Como configurar uma taxa?</h6>
                    <p>Preencha os campos abaixo para criar ou editar uma taxa/multa:</p>
                    
                    <h6><i class="fas fa-calculator text-warning"></i> Campos importantes:</h6>
                    <ul>
                        <li><strong>Nome:</strong> Identificação da taxa (ex: "Multa por Atraso").</li>
                        <li><strong>Tipo:</strong> Categoria da taxa (Matrícula, Mensalidade, Multa, Juros).</li>
                        <li><strong>Aplicação:</strong> Valor fixo em Kz ou percentual sobre o valor original.</li>
                        <li><strong>Período:</strong> Quando a taxa deve ser aplicada (ex: "1º Bimestre").</li>
                        <li><strong>Vigência:</strong> Período de validade da taxa.</li>
                    </ul>
                    
                    <h6><i class="fas fa-lightbulb text-info"></i> Dicas:</h6>
                    <ul>
                        <li>Multas e juros são aplicados automaticamente em pagamentos em atraso.</li>
                        <li>Configure taxas com antecedência para o próximo ano letivo.</li>
                        <li>Use "Valor Fixo" para multas e "Percentual" para juros.</li>
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
        
        function toggleValorPercentual() {
            const aplicacao = $('#aplicacao').val();
            if (aplicacao === 'fixo') {
                $('#div_valor').show();
                $('#div_percentual').hide();
            } else {
                $('#div_valor').hide();
                $('#div_percentual').show();
            }
        }
        
        $(document).ready(function() {
            toggleValorPercentual();
        });
        
        if (window.location.pathname.includes('financeiro')) {
            $('#menuFinanceiro').addClass('open');
            $('#submenuFinanceiro').addClass('show');
        }
    </script>
</body>
</html>