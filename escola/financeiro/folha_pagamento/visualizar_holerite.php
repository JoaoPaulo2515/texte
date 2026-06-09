<?php
// escola/financeiro/folha_pagamento/visualizar_holerite.php - Visualizar Holerite
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$arquivo = $_GET['arquivo'] ?? '';
$processamento_id = $_GET['processamento_id'] ?? 0;

// Verificar se o arquivo foi informado
if (empty($arquivo)) {
    die("Arquivo não especificado.");
}

// Caminho do arquivo PDF
$caminho_pdf = __DIR__ . '/../../../uploads/holerites/' . $arquivo;

// Verificar se o arquivo existe
if (!file_exists($caminho_pdf)) {
    // Tentar buscar no banco de dados o caminho correto
    $stmt = $conn->prepare("
        SELECT caminho_pdf FROM folha_holerites 
        WHERE processamento_id = ? AND caminho_pdf LIKE ?
    ");
    $stmt->execute([$processamento_id, '%' . $arquivo]);
    $holerite = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($holerite) {
        $caminho_pdf = __DIR__ . '/../../../' . $holerite['caminho_pdf'];
    }
    
    if (!file_exists($caminho_pdf)) {
        die("Arquivo PDF não encontrado: " . htmlspecialchars($arquivo));
    }
}

// Buscar dados do holerite no banco para exibir informações adicionais
$stmt = $conn->prepare("
    SELECT h.*, f.nome, f.numero_processo, f.cargo
    FROM folha_holerites h
    JOIN funcionarios f ON h.funcionario_id = f.id
    WHERE h.caminho_pdf LIKE ? AND h.escola_id = ?
    LIMIT 1
");
$stmt->execute(['%' . $arquivo, $escola_id]);
$holerite_info = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizar Holerite | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        
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
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .pdf-container {
            background: #525659;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .pdf-viewer {
            width: 100%;
            height: 80vh;
            border: none;
            border-radius: 8px;
            background: white;
        }
        .info-holerite {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .info-label {
            font-weight: bold;
            width: 120px;
            display: inline-block;
            color: #006B3E;
        }
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
            <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
            <li class="nav-item"><a href="funcionarios.php" class="nav-link"><i class="fas fa-users"></i> Funcionários</a></li>
            <li class="nav-item"><a href="processar.php" class="nav-link"><i class="fas fa-calculator"></i> Processar</a></li>
            <li class="nav-item"><a href="holerites.php" class="nav-link"><i class="fas fa-receipt"></i> Holerites</a></li>
            <li class="nav-item"><a href="relatorios.php" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="configuracoes.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-file-pdf"></i> Visualizar Holerite</h2>
            <div>
                <a href="gerar_holerites_lote.php?processamento_id=<?php echo $processamento_id; ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
                <a href="../../<?php echo 'uploads/holerites/' . $arquivo; ?>" download class="btn btn-primary btn-sm ms-2">
                    <i class="fas fa-download"></i> Baixar
                </a>
                <button class="btn btn-info btn-sm ms-2" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <?php if ($holerite_info): ?>
        <div class="info-holerite">
            <div class="row">
                <div class="col-md-6">
                    <div><span class="info-label">Funcionário:</span> <?php echo htmlspecialchars($holerite_info['nome']); ?></div>
                    <div><span class="info-label">Nº Processo:</span> <?php echo htmlspecialchars($holerite_info['numero_processo']); ?></div>
                    <div><span class="info-label">Cargo:</span> <?php echo htmlspecialchars($holerite_info['cargo']); ?></div>
                </div>
                <div class="col-md-6">
                    <div><span class="info-label">Competência:</span> <?php echo $holerite_info['mes'] . '/' . $holerite_info['ano']; ?></div>
                    <div><span class="info-label">Salário Líquido:</span> <strong><?php echo number_format($holerite_info['salario_liquido'], 2); ?> Kz</strong></div>
                    <div><span class="info-label">Data Emissão:</span> <?php echo date('d/m/Y H:i', strtotime($holerite_info['data_emissao'])); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-file-pdf"></i> Documento
            </div>
            <div class="card-body pdf-container">
                <?php
                // Caminho relativo para o PDF
                $caminho_relativo = '../../../uploads/holerites/' . $arquivo;
                ?>
                <iframe src="<?php echo $caminho_relativo; ?>" class="pdf-viewer" frameborder="0">
                    Este navegador não suporta visualização de PDF. 
                    <a href="<?php echo $caminho_relativo; ?>">Clique aqui para baixar o arquivo</a>
                </iframe>
            </div>
        </div>
        
        <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle"></i> 
            <strong>Dica:</strong> Se o PDF não carregar, clique em "Baixar" para fazer o download do arquivo.
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    </script>
</body>
</html>