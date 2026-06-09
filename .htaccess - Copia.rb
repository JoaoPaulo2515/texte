# .htaccess - Configurações do Servidor Apache

# Ativar rewrite engine
RewriteEngine On

# Definir charset padrão
AddDefaultCharset UTF-8

# Proteger arquivos sensíveis
<FilesMatch "^\\.(htaccess|htpasswd|ini|log|sh|sql|bak|config|lock)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Proteger diretórios importantes
<IfModule mod_authz_core.c>
    <Directory "config">
        Require all denied
    </Directory>
    <Directory "logs">
        Require all denied
    </Directory>
    <Directory "lib">
        Require all denied
    </Directory>
    <Directory "uploads">
        Require all denied
    </Directory>
</IfModule>

# Prevenir listagem de diretórios
Options -Indexes

# URL Amigável - Redirecionar todas as requisições para index.php
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    
    # Se o arquivo ou diretório não existe, redireciona para index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php?url=$1 [QSA,L]
</IfModule>

# Compressão GZIP
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>

# Cache de arquivos estáticos
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>

# Segurança - Prevenir clickjacking
Header always append X-Frame-Options SAMEORIGIN

# Segurança - Prevenir XSS
Header set X-XSS-Protection "1; mode=block"

# Segurança - Prevenir MIME type sniffing
Header set X-Content-Type-Options "nosniff"

# Bloquear acesso a arquivos ocultos
RedirectMatch 403 /\..*$

# Forçar HTTPS (descomente quando tiver SSL)
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]