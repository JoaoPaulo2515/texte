# auto_hosts.ps1 - Script para atualizar automaticamente o arquivo hosts
# Coloque este arquivo em: C:\xampp\htdocs\sige_Plataforma\auto_hosts.ps1

# Configurações
$hostsFile = "C:\Windows\System32\drivers\etc\hosts"
$backupFile = "C:\Windows\System32\drivers\etc\hosts.backup"
$mysqlPath = "C:\xampp\mysql\bin\mysql.exe"
$database = "sige_angola"
$mysqlUser = "root"
$mysqlPass = ""

Write-Host "=== Atualizador Automático de Hosts - SIGE Angola ===" -ForegroundColor Cyan
Write-Host ""

# Verificar se o MySQL está acessível
if (-not (Test-Path $mysqlPath)) {
    Write-Host "ERRO: MySQL não encontrado em $mysqlPath" -ForegroundColor Red
    Write-Host "Verifique o caminho do MySQL no script" -ForegroundColor Yellow
    exit 1
}

# Buscar escolas do banco de dados
try {
    $query = "SELECT subdominio FROM escolas WHERE status = 'ativa' OR status = 'trial'"
    $escolas = & $mysqlPath -u $mysqlUser -e "USE $database; $query" -B -N 2>$null
    
    if (-not $escolas) {
        Write-Host "Nenhuma escola encontrada no banco de dados" -ForegroundColor Yellow
        $escolas = @()
    }
} catch {
    Write-Host "ERRO: Não foi possível conectar ao banco de dados" -ForegroundColor Red
    Write-Host "Certifique-se que o MySQL está rodando" -ForegroundColor Yellow
    exit 1
}

# Fazer backup do arquivo hosts original (apenas na primeira vez)
if (-not (Test-Path $backupFile)) {
    Copy-Item $hostsFile $backupFile -Force
    Write-Host "Backup do arquivo hosts criado em: $backupFile" -ForegroundColor Green
}

# Ler arquivo hosts atual
$content = Get-Content $hostsFile -ErrorAction SilentlyContinue
if (-not $content) {
    $content = @()
}

# Filtrar removendo entradas antigas do SIGE
$newContent = @()
foreach ($line in $content) {
    if ($line -notmatch "\.sige\.ao" -and $line -notmatch "# SIGE Angola") {
        $newContent += $line
    }
}

# Adicionar novo cabeçalho
$newContent += ""
$newContent += "# ============================================"
$newContent += "# SIGE Angola - Entradas geradas automaticamente"
$newContent += "# Atualizado em: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
$newContent += "# ============================================"

# Adicionar entrada principal
$newContent += "127.0.0.1   sige.ao"
$newContent += "127.0.0.1   www.sige.ao"
$newContent += "127.0.0.1   admin.sige.ao"

# Adicionar cada escola
$count = 0
foreach ($escola in $escolas) {
    if ($escola -and $escola.Trim()) {
        $subdominio = $escola.Trim()
        $newContent += "127.0.0.1   $subdominio.sige.ao"
        Write-Host "✓ Adicionado: $subdominio.sige.ao" -ForegroundColor Green
        $count++
    }
}

# Adicionar linha em branco no final
$newContent += ""

# Salvar arquivo hosts
try {
    $newContent | Set-Content $hostsFile -Encoding ASCII -Force
    Write-Host ""
    Write-Host "✅ Arquivo hosts atualizado com sucesso!" -ForegroundColor Green
    Write-Host "📊 Total de subdomínios configurados: $count" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "🔄 Para aplicar as alterações, execute: ipconfig /flushdns" -ForegroundColor Yellow
} catch {
    Write-Host "ERRO: Não foi possível escrever no arquivo hosts" -ForegroundColor Red
    Write-Host "Execute o PowerShell como Administrador" -ForegroundColor Yellow
    exit 1
}