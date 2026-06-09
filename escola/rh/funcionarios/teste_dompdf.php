<?php
// escola/rh/funcionarios/teste_dompdf.php
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

echo "<h1>Teste DOMPDF</h1>";

if (class_exists('Dompdf\Dompdf')) {
    echo "<p style='color:green'>✓ DOMPDF instalado com sucesso!</p>";
    
    try {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        
        $dompdf = new Dompdf($options);
        $html = '<h1 style="color:#006B3E">Teste de Geração de PDF</h1>
                 <p>Este é um teste para verificar se o DOMPDF está funcionando corretamente.</p>
                 <p>Data: ' . date('d/m/Y H:i:s') . '</p>';
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        echo "<p style='color:green'>✓ Teste de renderização concluído!</p>";
        echo "<p><a href='teste_dompdf.php?gerar=1' class='btn btn-primary'>Gerar PDF de Teste</a></p>";
        
        if (isset($_GET['gerar'])) {
            $dompdf->stream('teste.pdf', array('Attachment' => false));
            exit;
        }
        
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Erro: " . $e->getMessage() . "</p>";
    }
    
    echo "<p><strong>Versão DOMPDF:</strong> " . Dompdf\Dompdf::VERSION . "</p>";
    echo "<p><strong>Diretório de instalação:</strong> " . __DIR__ . "/../../../vendor/dompdf/</p>";
    
} else {
    echo "<p style='color:red'>✗ DOMPDF NÃO está instalado!</p>";
    echo "<p>Execute o comando: <code>composer require dompdf/dompdf</code></p>";
}
?>