<?php
// debug.php - Diagnóstico do sistema
echo "<h1>Diagnóstico SIGE Angola</h1>";

echo "<h2>1. Verificando arquivos:</h2>";
$files = [
    'index.php',
    'login.php',
    'logout.php',
    'config/database.php',
    'config/db_config.php',
    'config/installed.lock',
    'super-admin/dashboard.php',
    'escola/index.php'
];

foreach ($files as $file) {
    echo $file . ": " . (file_exists($file) ? "✅ Existe" : "❌ Não existe") . "<br>";
}

echo "<h2>2. Verificando pastas:</h2>";
$dirs = [
    'super-admin',
    'escola',
    'config',
    'assets',
    'uploads'
];

foreach ($dirs as $dir) {
    echo $dir . ": " . (is_dir($dir) ? "✅ Existe" : "❌ Não existe") . "<br>";
}

echo "<h2>3. Sessão atual:</h2>";
session_start();
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>4. Caminho atual:</h2>";
echo "Diretório: " . __DIR__ . "<br>";
echo "Arquivo: " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "URL: " . $_SERVER['REQUEST_URI'] . "<br>";