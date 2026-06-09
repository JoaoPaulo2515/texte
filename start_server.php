<?php
// start_server.php - Iniciar servidor com suporte a subdomínios

$host = '0.0.0.0';
$port = 80;

echo "Iniciando servidor SIGE Angola...\n";
echo "Acesse: http://sige.ao\n";
echo "Acesse: http://escola1.sige.ao\n";
echo "Acesse: http://escola2.sige.ao\n";

exec("php -S {$host}:{$port} -t " . __DIR__);