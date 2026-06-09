<?php
// escola/financeiro/folha_pagamento/zip_holerites.php - Baixar todos os holerites em ZIP
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Verificar se a extensão ZipArchive está disponível
if (!class_exists('ZipArchive')) {
    // Alternativa: redirecionar para página com instruções
    ?>
    <!DOCTYPE html>
    <html lang="pt-AO">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro - ZIP não disponível | SIGE Angola</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
            .container { max-width: 800px; margin: 100px auto; }
            .card { border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .card-header { background: linear-gradient(135deg, #dc3545 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Extensão ZIP não disponível</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle"></i> 
                        <strong>Erro:</strong> A extensão ZIP do PHP não está habilitada.
                    </div>
                    
                    <h5>Como resolver:</h5>
                    <ol>
                        <li>Abra o arquivo <code>C:\xampp\php\php.ini</code></li>
                        <li>Procure pela linha <code>;extension=zip</code></li>
                        <li>Remova o ponto e vírgula <code>;</code> no início da linha</li>
                        <li>Salve o arquivo e reinicie o Apache no XAMPP</li>
                    </ol>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Alternativa:</strong> Você pode baixar os holerites individualmente na página anterior.
                    </div>
                    
                    <div class="text-center mt-4">
                        <a href="gerar_holerites_lote.php?processamento_id=<?php echo $_GET['processamento_id'] ?? 0; ?>" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$processamento_id = $_GET['processamento_id'] ?? 0;

// Buscar holerites do processamento
$stmt = $conn->prepare("
    SELECT * FROM folha_holerites 
    WHERE processamento_id = ? AND escola_id = ?
");
$stmt->execute([$processamento_id, $escola_id]);
$holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($holerites)) {
    die("Nenhum holerite encontrado para este processamento.");
}

// Criar arquivo ZIP
$zip = new ZipArchive();
$zip_filename = "holerites_processamento_{$processamento_id}.zip";
$zip_path = sys_get_temp_dir() . '/' . $zip_filename;

if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
    die("Erro ao criar arquivo ZIP.");
}

// Adicionar cada holerite ao ZIP
foreach ($holerites as $hol) {
    $file_path = __DIR__ . '/../../../' . $hol['caminho_pdf'];
    if (file_exists($file_path)) {
        $zip->addFile($file_path, basename($hol['caminho_pdf']));
    }
}

$zip->close();

// Enviar arquivo para download
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($zip_path));
readfile($zip_path);

// Limpar arquivo temporário
unlink($zip_path);
exit;
?>