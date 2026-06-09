<?php
// diagnostico.php - Coloque este arquivo na pasta C:\xampp\htdocs\sige_Plataforma\
echo "<h2>Diagnóstico do Erro PhpSpreadsheet</h2>";

// 1. Verificar se o arquivo autoload existe
$autoload_path = __DIR__ . '/vendor/autoload.php';
echo "<h3>1. Verificando autoload:</h3>";
echo "Caminho procurado: " . $autoload_path . "<br>";

if (file_exists($autoload_path)) {
    echo "✅ arquivo autoload.php ENCONTRADO!<br>";
    require_once $autoload_path;
    echo "✅ autoload carregado com sucesso!<br>";
    
    // 2. Verificar se a classe existe
    echo "<h3>2. Verificando classe PhpSpreadsheet:</h3>";
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        echo "✅ Classe PhpSpreadsheet ENCONTRADA!<br>";
        echo "Seu código deve funcionar normalmente.";
    } else {
        echo "❌ Classe PhpSpreadsheet NÃO ENCONTRADA!<br>";
        echo "A biblioteca não está instalada corretamente.<br>";
        echo "Solução: Execute no terminal: composer require phpoffice/phpspreadsheet";
    }
} else {
    echo "❌ arquivo autoload.php NÃO ENCONTRADO!<br>";
    echo "Caminho atual: " . __DIR__ . "<br>";
    echo "<h3>Soluções:</h3>";
    echo "<ul>";
    echo "<li><strong>Opção 1 (Recomendada):</strong> Instalar Composer e a biblioteca<br>
          <code>cd C:\\xampp\\htdocs\\sige_Plataforma<br>
          composer require phpoffice/phpspreadsheet</code></li>";
    echo "<li><strong>Opção 2:</strong> Baixar manualmente a biblioteca e criar autoload personalizado</li>";
    echo "<li><strong>Opção 3:</strong> Usar a versão CSV que não precisa da biblioteca</li>";
    echo "</ul>";
}

// 3. Verificar estrutura de pastas
echo "<h3>3. Estrutura de pastas atual:</h3>";
echo "<pre>";
echo "C:\\xampp\\htdocs\\sige_Plataforma\\\n";
echo "├── " . (is_dir('vendor') ? "📁 vendor/" : "❌ vendor/ (não existe)") . "\n";
echo "├── " . (is_dir('escola') ? "📁 escola/" : "❌ escola/") . "\n";
echo "└── " . (file_exists('composer.json') ? "📄 composer.json" : "❌ composer.json (não existe)") . "\n";
echo "</pre>";
?>