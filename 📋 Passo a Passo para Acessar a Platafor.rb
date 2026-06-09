📋 Passo a Passo para Acessar a Plataforma como Escola

Depois de cadastrar a escola no Super Admin, siga estes passos para acessar o ambiente da escola:
🔧 Passo 1: Configurar o arquivo hosts (para testes locais)

Como você está usando XAMPP localmente, precisa configurar o arquivo hosts para reconhecer o subdomínio da escola.
1.1 Abra o arquivo hosts como Administrador
# Abra o PowerShell como Administrador e execute:
notepad C:\Windows\System32\drivers\etc\hosts
1.2 Adicione as seguintes linhas no final do arquivo:
127.0.0.1   sige.ao
127.0.0.1   admin.sige.ao
127.0.0.1   escolamodelo.sige.ao
127.0.0.1   *.sige.ao

Nota: Substitua escolamodelo pelo subdomínio que você cadastrou para a escola.

1.3 Salve o arquivo e feche
🔧 Passo 2: Configurar o Apache para aceitar subdomínios
2.1 Abra o arquivo de configuração do Apache:
    C:\xampp\apache\conf\extra\httpd-vhosts.conf

2.2 Adicione esta configuração no final do arquivo:
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

2.3 Reinicie o Apache

    Abra o XAMPP Control Panel

    Clique em Stop no Apache

    Clique em Start no Apache

    🔧 Passo 3: Limpar o cache DNS

    # No PowerShell como Administrador:
ipconfig /flushdns

🔧 Passo 4: Acessar a plataforma da escola
4.1 Abra o navegador e digite:

http://escolamodelo.sige.ao

    Substitua escolamodelo pelo subdomínio que você cadastrou

4.2 Faça login com as credenciais da escola

Credenciais padrão:

    E-mail: O e-mail do responsável que você cadastrou

    Senha: A senha temporária que foi gerada (apareceu na tela após cadastrar a escola)

Exemplo:

E-mail: diretor@escolamodelo.ao
Senha: Ab3$xY9!

🔧 Passo 5: Verificar se o cadastro foi bem-sucedido
5.1 No Super Admin, verifique os dados da escola:

    Acesse http://sige.ao (ou http://localhost/sige_Plataforma)

    Faça login como Super Admin

    Vá em Escolas → clique em Visualizar na escola criada

    Anote o subdomínio e o e-mail do responsável
        5.2 Verificar no banco de dados:
        -- Verificar se a escola foi criada
SELECT id, nome, subdominio, email, status FROM escolas ORDER BY id DESC LIMIT 1;

-- Verificar se o usuário admin foi criado
SELECT id, nome, email, tipo FROM usuarios WHERE tipo = 'admin_escola' ORDER BY id DESC LIMIT 1;

SELECT id, nome, email, tipo FROM usuarios WHERE tipo = 'admin_escola' ORDER BY id DESC LIMIT 1;
6.2 Testar funcionalidades básicas:

    ✅ Acessar Alunos → deve listar os alunos (se houver)

    ✅ Acessar Professores → deve listar os professores (se houver)

    ✅ Acessar Turmas → deve listar as turmas (se houver)

    ✅ Acessar Disciplinas → deve listar as disciplinas (se houver)

🐛 Solução de problemas comuns

Erro 1: "Página não encontrada" ao acessar o subdomínio

Causa: Apache não configurado ou hosts não configurado

Solução:

    Verifique se o arquivo hosts tem a entrada correta

    Verifique se o httpd-vhosts.conf está configurado

    Reinicie o Apache

Erro 2: "403 Forbidden"

Causa: Permissões do Apache
    Solução: No arquivo httpd-vhosts.conf, adicione:
    Require all granted
    Erro 3: "Login inválido"

Causa: Credenciais incorretas

Solução:

    Verifique o e-mail do responsável cadastrado

    Use a senha temporária que apareceu no cadastro

    Se não lembra, verifique no banco de dados
    Erro 4: O subdomínio não carrega, apenas localhost

Causa: hosts não configurado corretamente

Solução:
# Verificar se a entrada existe
Get-Content C:\Windows\System32\drivers\etc\hosts | Select-String "sige.ao"
📝 Script para facilitar (PowerShell como Administrador)

Crie um arquivo configurar_acesso.ps1:
# configurar_acesso.ps1
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Configurar Acesso SIGE Angola" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 1. Configurar hosts
Write-Host "📝 Configurando arquivo hosts..." -ForegroundColor Yellow
$hostsFile = "C:\Windows\System32\drivers\etc\hosts"
$entries = @"
127.0.0.1   sige.ao
127.0.0.1   admin.sige.ao
127.0.0.1   *.sige.ao
"@

Add-Content -Path $hostsFile -Value $entries -ErrorAction SilentlyContinue
Write-Host "✅ Hosts configurado!" -ForegroundColor Green

# 2. Limpar DNS
Write-Host "🔄 Limpando cache DNS..." -ForegroundColor Yellow
ipconfig /flushdns
Write-Host "✅ DNS limpo!" -ForegroundColor Green

# 3. Buscar escolas cadastradas
Write-Host "🏫 Buscando escolas cadastradas..." -ForegroundColor Yellow

$mysql = "C:\xampp\mysql\bin\mysql.exe"
$query = "SELECT id, nome, subdominio, email FROM sige_angola.escolas WHERE status = 'ativa'"

$escolas = & $mysql -u root -e "$query" -B -N 2>$null

if ($escolas) {
    Write-Host "`n📋 Escolas encontradas:" -ForegroundColor Cyan
    foreach ($escola in $escolas) {
        $dados = $escola -split "`t"
        Write-Host "   - Subdomínio: http://$($dados[2]).sige.ao" -ForegroundColor Green
        Write-Host "     E-mail: $($dados[3])" -ForegroundColor Gray
        Write-Host ""
    }
} else {
    Write-Host "❌ Nenhuma escola encontrada. Cadastre uma escola primeiro no Super Admin." -ForegroundColor Red
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "✅ Configuração concluída!" -ForegroundColor Green
Write-Host "Acesse sua escola em: http://[subdominio].sige.ao" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan
Execute como Administrador:

powershell -ExecutionPolicy Bypass -File configurar_acesso.ps1

✅ Resumo do Fluxo de Acesso
Passo	Ação
1	Cadastrar a escola no Super Admin
2	Anotar o subdomínio e a senha temporária
3	Configurar arquivo hosts
4	Configurar Apache (httpd-vhosts.conf)
5	Reiniciar Apache
6	Limpar cache DNS (ipconfig /flushdns)
7	Acessar http://[subdominio].sige.ao
8	Fazer login com e-mail do responsável e senha temporária