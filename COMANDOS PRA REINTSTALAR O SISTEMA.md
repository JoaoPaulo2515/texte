1. Impedir reinstalação acidental

// No install.php
if (file_exists(__DIR__ . '/config/installed.lock')) {
    header('Location: index.php');  // Redireciona para o sistema
    exit;
}


2. Conteúdo do arquivo 
//no installed.lock

{
    "installed_at": "2026-01-15 10:30:00",
    "version": "2.0.0",
    "admin_email": "admin@sige.com",
    "school_subdomain": "escola",
    "db_version": "1.0.0"
}

3. Verificação de instalação

// No index.php
if (!file_exists(__DIR__ . '/config/installed.lock') && basename($_SERVER['PHP_SELF']) !== 'install.php') {
    header('Location: install.php');
    exit;
}


🛠️ Como gerenciar o arquivo
Verificar se o arquivo existe:

    # No Windows (PowerShell)
Test-Path "C:\xampp\htdocs\sige_Plataforma\config\installed.lock"

# No Linux/Mac
ls -la /var/www/html/sige_Plataforma/config/installed.lock


Visualizar conteúdo:

# No Windows (PowerShell)
Get-Content "C:\xampp\htdocs\sige_Plataforma\config\installed.lock"

# No Linux/Mac
cat /var/www/html/sige_Plataforma/config/installed.lock

Remover para reinstalar (apenas se necessário):

# No Windows (PowerShell)
Remove-Item "C:\xampp\htdocs\sige_Plataforma\config\installed.lock"

# No Linux/Mac
rm /var/www/html/sige_Plataforma/config/installed.lock


⚠️ Importante

    NUNCA remova este arquivo a menos que queira reinstalar o sistema do zero

    Faça backup do banco de dados antes de remover o arquivo

    Proteja este arquivo contra acesso público (o .htaccess já faz isso)

🔒 Segurança

O arquivo está protegido pelo .htaccess:
# No .htaccess
<FilesMatch "^\\.(htaccess|htpasswd|ini|log|sh|sql|bak|config|lock)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

📝 Código que cria o arquivo (no install.php)

// Criar arquivo de lock
$lockData = [
    'installed_at' => date('Y-m-d H:i:s'),
    'version' => '2.0.0',
    'admin_email' => $admin_email,
    'school_subdomain' => $school_subdomain,
    'db_version' => '1.0.0'
];
file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT));


🚨 Solução para erro "Sistema já instalado"

Se você está tentando reinstalar e aparece o erro, use:

http://localhost/sige_Plataforma/install.php?force=1

Isso força a reinstalação mesmo com o arquivo existente (faz backup automático).