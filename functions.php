<?php
// functions.php - Adicionar função para atualizar hosts

function atualizarHostsFile() {
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    
    $hostsFile = 'C:\Windows\System32\drivers\etc\hosts';
    $backupFile = 'C:\Windows\System32\drivers\etc\hosts.backup';
    
    // Fazer backup
    if (!file_exists($backupFile)) {
        copy($hostsFile, $backupFile);
    }
    
    // Buscar escolas ativas
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $stmt = $conn->query("SELECT subdominio FROM escolas WHERE status = 'ativa'");
    $escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ler arquivo hosts atual
    $content = file($hostsFile);
    $newContent = [];
    $hasSigeEntries = false;
    
    foreach ($content as $line) {
        if (strpos($line, '.sige.ao') === false) {
            $newContent[] = trim($line);
        }
    }
    
    // Adicionar novas entradas
    $newContent[] = '';
    $newContent[] = '# Entradas geradas automaticamente pelo SIGE Angola';
    $newContent[] = '# Atualizado em: ' . date('Y-m-d H:i:s');
    
    foreach ($escolas as $escola) {
        $newContent[] = "127.0.0.1   {$escola['subdominio']}.sige.ao";
    }
    
    // Salvar arquivo
    file_put_contents($hostsFile, implode(PHP_EOL, $newContent));
    
    return true;
}

// Chamar a função após cadastrar uma nova escola
// Em escolas/cadastrar.php, após salvar a escola:
if ($success) {
    atualizarHostsFile();
}
?>