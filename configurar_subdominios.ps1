# configurar_subdominios.ps1 - Executar como Administrador

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Configurar Subdomínios SIGE Angola" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# 1. Configurar hosts
Write-Host "📝 Configurando arquivo hosts..." -ForegroundColor Yellow

$hostsFile = "C:\Windows\System32\drivers\etc\hosts"
$backupFile = "C:\Windows\System32\drivers\etc\hosts.backup"

# Backup
if (-not (Test-Path $backupFile)) {
    Copy-Item $hostsFile $backupFile
    Write-Host "✅ Backup criado" -ForegroundColor Green
}

# Remover entradas antigas do SIGE
$content = Get-Content $hostsFile
$newContent = @()
foreach ($line in $content) {
    if ($line -notmatch "sige\.ao") {
        $newContent += $line
    }
}

# Adicionar novas entradas
$newContent += ""
$newContent += "# SIGE Angola - Configurações Locais"
$newContent += "127.0.0.1   sige.ao"
$newContent += "127.0.0.1   admin.sige.ao"
$newContent += "127.0.0.1   *.sige.ao"

# Buscar escolas do banco de dados
Write-Host "`n🏫 Buscando escolas cadastradas..." -ForegroundColor Yellow

$mysql = "C:\xampp\mysql\bin\mysql.exe"
$query = "SELECT subdominio FROM sige_angola.escolas WHERE status IN ('ativa', 'trial')"

$escolas = & $mysql -u root -e "$query" -B -N 2>$null

if ($escolas) {
    foreach ($escola in $escolas) {
        if ($escola -and $escola.Trim()) {
            $subdominio = $escola.Trim()
            $newContent += "127.0.0.1   $subdominio.sige.ao"
            Write-Host "✅ Adicionado: $subdominio.sige.ao" -ForegroundColor Green
        }
    }
} else {
    Write-Host "⚠️ Nenhuma escola encontrada. Adicione manualmente." -ForegroundColor Yellow
}

$newContent | Set-Content $hostsFile -Encoding ASCII

# 2. Configurar Apache
Write-Host "`n🔧 Configurando Apache..." -ForegroundColor Yellow

$vhostsFile = "C:\xampp\apache\conf\extra\httpd-vhosts.conf"

# Verificar se já existe configuração do SIGE
$vhostsContent = Get-Content $vhostsFile -Raw
if ($vhostsContent -notmatch "sige.ao") {
    $vhostsConfig = @"

# SIGE Angola - Virtual Hosts
<VirtualHost *:80>
    ServerName sige.ao
    ServerAlias *.sige.ao
    DocumentRoot "C:/xampp/htdocs/sige_Plataforma"
    
    <Directory "C:/xampp/htdocs/sige_Plataforma">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog "logs/sige.ao-error.log"
    CustomLog "logs/sige.ao-access.log" common
</VirtualHost>
"@
    Add-Content -Path $vhostsFile -Value $vhostsConfig
    Write-Host "✅ Virtual Host configurado" -ForegroundColor Green
} else {
    Write-Host "✅ Virtual Host já configurado" -ForegroundColor Green
}

# 3. Limpar DNS
Write-Host "`n🔄 Limpando cache DNS..." -ForegroundColor Yellow
ipconfig /flushdns
Write-Host "✅ DNS limpo!" -ForegroundColor Green

# 4. Mostrar URLs de acesso
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "   URLs de Acesso" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "🔐 Super Admin:" -ForegroundColor Magenta
Write-Host "   http://sige.ao" -ForegroundColor Green
Write-Host "   http://admin.sige.ao`n" -ForegroundColor Green

if ($escolas) {
    Write-Host "🏫 Escolas:" -ForegroundColor Magenta
    foreach ($escola in $escolas) {
        if ($escola -and $escola.Trim()) {
            Write-Host "   http://$($escola.Trim()).sige.ao" -ForegroundColor Green
        }
    }
} else {
    Write-Host "⚠️ Nenhuma escola cadastrada." -ForegroundColor Yellow
    Write-Host "   Cadastre uma escola no Super Admin primeiro." -ForegroundColor Yellow
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "✅ Configuração concluída!" -ForegroundColor Green
Write-Host "Reinicie o navegador e acesse as URLs acima." -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan