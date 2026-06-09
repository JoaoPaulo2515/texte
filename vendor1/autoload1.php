<?php
// vendor/autoload.php - Autoloader simplificado

/**
 * Autoloader para classes do PhpSpreadsheet
 * 
 * IMPORTANTE: Você precisa baixar a biblioteca PhpSpreadsheet manualmente
 * Baixe em: https://github.com/PHPOffice/PhpSpreadsheet/archive/master.zip
 * Extraia para: libs/phpspreadsheet/
 */

// Registrar autoloader
spl_autoload_register(function ($class) {
    // Mapeamento de namespaces para diretórios
    $prefixes = [
        'PhpOffice\\PhpSpreadsheet\\' => __DIR__ . '/../libs/phpspreadsheet/src/PhpSpreadsheet/',
        'Psr\\SimpleCache\\' => __DIR__ . '/../libs/psr/simple-cache/src/',
        'Psr\\Http\\Message\\' => __DIR__ . '/../libs/psr/http-message/src/',
        'Psr\\Http\\Client\\' => __DIR__ . '/../libs/psr/http-client/src/',
    ];
    
    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
            return true;
        }
    }
    
    return false;
});

echo "Autoload carregado com sucesso!<br>";