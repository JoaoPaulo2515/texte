# update_hosts.ps1 - Atualizar arquivo hosts automaticamente

$hostsFile = "C:\Windows\System32\drivers\etc\hosts"
$backupFile = "C:\Windows\System32\drivers\etc\hosts.backup"

# Fazer backup
Copy-Item $hostsFile $backupFile -Force

# Buscar escolas do banco de dados
$mysql = "C:\xampp\mysql\bin\mysql.exe"
$database = "sige_angola"
$user = "root"
$password = ""

$query = "SELECT subdominio FROM escolas WHERE status = 'ativa'"
$result = & $mysql -u $user -e "USE $database; $query" -B -N

# Limpar entradas antigas do SIGE
$content = Get-Content $hostsFile
$newContent = $content | Where-Object { $_ -notmatch "\.sige\.ao" }

# Adicionar novas entradas
$newContent += "# Entradas geradas automaticamente pelo SIGE Angola"
$newContent += "# Data: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"

foreach ($subdominio in $result) {
    if ($subdominio) {
        $newContent += "127.0.0.1   $subdominio.sige.ao"
        Write-Host "Adicionado: $subdominio.sige.ao" -ForegroundColor Green
    }
}

# Salvar arquivo
$newContent | Set-Content $hostsFile -Encoding ASCII

Write-Host "Arquivo hosts atualizado com sucesso!" -ForegroundColor Green