<?php
// escola/rh/configurar.php - Configurações do RH
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Processar configurações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $configs = [
        'inss_aliquota' => $_POST['inss_aliquota'] ?? '3,6,9,12',
        'inss_limites' => $_POST['inss_limites'] ?? '100000,200000,350000,999999999',
        'irrf_tabela' => $_POST['irrf_tabela'] ?? '0,10,15,20,25',
        'irrf_limites' => $_POST['irrf_limites'] ?? '100000,200000,350000,500000,999999999',
        'salario_minimo' => $_POST['salario_minimo'] ?? '100000',
        'subsidio_transporte' => $_POST['subsidio_transporte'] ?? '5000',
        'subsidio_alimentacao' => $_POST['subsidio_alimentacao'] ?? '2500',
        'dias_ferias' => $_POST['dias_ferias'] ?? '22',
        'decimo_terceiro' => isset($_POST['decimo_terceiro']) ? 'sim' : 'nao',
        'ferias_proporcionais' => isset($_POST['ferias_proporcionais']) ? 'sim' : 'nao'
    ];
    
    foreach ($configs as $chave => $valor) {
        $stmt = $conn->prepare("
            INSERT INTO rh_configuracoes (escola_id, parametro, valor)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valor = ?
        ");
        $stmt->execute([$escola_id, $chave, $valor, $valor]);
    }
    
    $success = "Configurações salvas com sucesso!";
}

// Buscar configurações atuais
$configs_padrao = [
    'inss_aliquota' => '3,6,9,12',
    'inss_limites' => '100000,200000,350000,999999999',
    'irrf_tabela' => '0,10,15,20,25',
    'irrf_limites' => '100000,200000,350000,500000,999999999',
    'salario_minimo' => '100000',
    'subsidio_transporte' => '5000',
    'subsidio_alimentacao' => '2500',
    'dias_ferias' => '22',
    'decimo_terceiro' => 'sim',
    'ferias_proporcionais' => 'sim'
];

$configs = [];
foreach ($configs_padrao as $chave => $valor_padrao) {
    $stmt = $conn->prepare("SELECT valor FROM rh_configuracoes WHERE escola_id = ? AND parametro = ?");
    $stmt->execute([$escola_id, $chave]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $configs[$chave] = $row['valor'] ?? $valor_padrao;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações RH | SIGE Angola</title>
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
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-legislacao { background: #e8f5e9; border-left: 4px solid #006B3E; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-cog"></i> Configurações de RH</h2>
            <span class="badge bg-primary">Angola</span>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Tabelas INSS e IRRF (Angola)
                </div>
                <div class="card-body">
                    <div class="alert alert-info info-legislacao">
                        <i class="fas fa-gavel"></i> <strong>Base Legal:</strong> Lei Geral do Trabalho (Lei 7/15, de 15 de Junho) e Código Fiscal Angolano.
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tabela INSS - Alíquotas (%)</label>
                                <input type="text" name="inss_aliquota" class="form-control" value="<?php echo $configs['inss_aliquota']; ?>">
                                <small class="text-muted">Separar por vírgula. Ex: 3,6,9,12</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tabela INSS - Limites (Kz)</label>
                                <input type="text" name="inss_limites" class="form-control" value="<?php echo $configs['inss_limites']; ?>">
                                <small class="text-muted">Separar por vírgula. Ex: 100000,200000,350000,999999999</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tabela IRRF - Alíquotas (%)</label>
                                <input type="text" name="irrf_tabela" class="form-control" value="<?php echo $configs['irrf_tabela']; ?>">
                                <small class="text-muted">Separar por vírgula. Ex: 0,10,15,20,25</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Tabela IRRF - Limites (Kz)</label>
                                <input type="text" name="irrf_limites" class="form-control" value="<?php echo $configs['irrf_limites']; ?>">
                                <small class="text-muted">Separar por vírgula. Ex: 100000,200000,350000,500000,999999999</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header">
                    <i class="fas fa-money-bill"></i> Parâmetros Salariais
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Salário Mínimo Nacional (Kz)</label>
                                <input type="number" name="salario_minimo" class="form-control" value="<?php echo $configs['salario_minimo']; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Subsídio de Transporte (Kz)</label>
                                <input type="number" name="subsidio_transporte" class="form-control" value="<?php echo $configs['subsidio_transporte']; ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Subsídio de Alimentação (Kz/dia)</label>
                                <input type="number" name="subsidio_alimentacao" class="form-control" value="<?php echo $configs['subsidio_alimentacao']; ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header">
                    <i class="fas fa-umbrella-beach"></i> Férias e 13º Salário
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Dias de Férias por Ano</label>
                                <input type="number" name="dias_ferias" class="form-control" value="<?php echo $configs['dias_ferias']; ?>">
                                <small class="text-muted">Lei Geral do Trabalho: 22 dias úteis</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="decimo_terceiro" class="form-check-input" id="decimo_terceiro" value="sim" <?php echo $configs['decimo_terceiro'] == 'sim' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="decimo_terceiro">Calcular 13º Salário</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="ferias_proporcionais" class="form-check-input" id="ferias_proporcionais" value="sim" <?php echo $configs['ferias_proporcionais'] == 'sim' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ferias_proporcionais">Calcular Férias Proporcionais</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
                <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
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
    </script>
</body>
</html>