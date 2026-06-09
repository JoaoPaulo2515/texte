<?php
// escola/tesouraria/ver_fechamento_caixa.php - Visualizar Fechamento de Caixa

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$data = isset($_GET['data']) ? $_GET['data'] : '';

if ($id > 0) {
    $sql = "SELECT * FROM fechamento_caixa WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $fechamento = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($data)) {
    $sql = "SELECT * FROM fechamento_caixa WHERE data_fechamento = :data AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':data' => $data, ':escola_id' => $escola_id]);
    $fechamento = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$fechamento) {
    die('Fechamento não encontrado');
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fechamento de Caixa | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; padding: 20px; }
        .recibo { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        @media print { body { background: white; padding: 0; } .recibo { box-shadow: none; padding: 15px; } .btn-print { display: none; } }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .empresa-nome { font-size: 18pt; font-weight: bold; }
        .btn-print { position: fixed; bottom: 20px; right: 20px; padding: 10px 20px; background: #006B3E; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="recibo" id="recibo">
        <div class="header">
            <div class="empresa-nome">RELATÓRIO DE FECHAMENTO DE CAIXA</div>
            <div>Data: <?php echo date('d/m/Y', strtotime($fechamento['data_fechamento'])); ?></div>
            <div>Fechado por: <?php echo htmlspecialchars($fechamento['fechado_por_nome'] ?? 'Usuário'); ?></div>
            <div>Data/Hora: <?php echo date('d/m/Y H:i:s', strtotime($fechamento['created_at'])); ?></div>
        </div>
        
        <div class="row">
            <div class="col-md-4"><strong>Total de Entradas:</strong> <?php echo formatarMoeda($fechamento['total_entradas']); ?></div>
            <div class="col-md-4"><strong>Total de Saídas:</strong> <?php echo formatarMoeda($fechamento['total_saidas']); ?></div>
            <div class="col-md-4"><strong>Saldo Final:</strong> <?php echo formatarMoeda($fechamento['saldo_final']); ?></div>
        </div>
        
        <?php if (!empty($fechamento['observacoes'])): ?>
        <div class="alert alert-info mt-3">Observações: <?php echo htmlspecialchars($fechamento['observacoes']); ?></div>
        <?php endif; ?>
    </div>
    <button class="btn-print" onclick="window.print()">Imprimir</button>
</body>
</html>