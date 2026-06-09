# auto_hosts.ps1 - Executar como Administrador
while ($true) {
    Clear-Host
    Write-Host "=== Atualizador Automático de Hosts - SIGE Angola ===" -ForegroundColor Cyan
    
    # Buscar escolas do banco
    $escolas = & mysql -u root -e "USE sige_angola; SELECT subdominio FROM escolas WHERE status = 'ativa'" -B -N
    
    $hostsFile = "C:\Windows\System32\drivers\etc\hosts"
    $content = Get-Content $hostsFile
    $newContent = @()
    
    foreach ($line in $content) {
        if ($line -notmatch "\.sige\.ao") {
            $newContent += $line
        }
    }
    
    $newContent += ""
    $newContent += "# SIGE Angola - Atualizado em $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    
    foreach ($escola in $escolas) {
        if ($escola) {
            $newContent += "127.0.0.1   $escola.sige.ao"
            Write-Host "✓ $escola.sige.ao" -ForegroundColor Green
        }
    }
    
    $newContent | Set-Content $hostsFile -Encoding ASCII
    Write-Host "`nArquivo hosts atualizado! Aguardando 60 segundos..." -ForegroundColor Yellow
    Start-Sleep -Seconds 60
}