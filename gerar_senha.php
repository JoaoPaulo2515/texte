<?php
// gerar_senha.php - Gerador de Senhas Criptografadas

// Verificar se o formulário foi enviado
$senha_original = '';
$resultados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_original = $_POST['senha'] ?? '';
    $metodos_selecionados = $_POST['metodos'] ?? [];
    
    if (!empty($senha_original)) {
        // Funções de criptografia
        $metodos = [
            'md5' => [
                'nome' => 'MD5',
                'funcao' => function($senha) { return md5($senha); },
                'descricao' => 'Hash de 32 caracteres (não seguro para senhas)',
                'cor' => '#dc3545'
            ],
            'sha1' => [
                'nome' => 'SHA-1',
                'funcao' => function($senha) { return sha1($senha); },
                'descricao' => 'Hash de 40 caracteres (não seguro para senhas)',
                'cor' => '#dc3545'
            ],
            'sha256' => [
                'nome' => 'SHA-256',
                'funcao' => function($senha) { return hash('sha256', $senha); },
                'descricao' => 'Hash de 64 caracteres (segurança média)',
                'cor' => '#ffc107'
            ],
            'sha512' => [
                'nome' => 'SHA-512',
                'funcao' => function($senha) { return hash('sha512', $senha); },
                'descricao' => 'Hash de 128 caracteres (segurança alta)',
                'cor' => '#17a2b8'
            ],
            'password_hash' => [
                'nome' => 'PASSWORD_HASH (BCrypt)',
                'funcao' => function($senha) { return password_hash($senha, PASSWORD_DEFAULT); },
                'descricao' => 'Hash seguro com salt automático (RECOMENDADO para produção)',
                'cor' => '#28a745'
            ],
            'bcrypt' => [
                'nome' => 'BCrypt (Cost 10)',
                'funcao' => function($senha) { 
                    $options = ['cost' => 10];
                    return password_hash($senha, PASSWORD_BCRYPT, $options); 
                },
                'descricao' => 'BCrypt com cost 10 (Muito seguro)',
                'cor' => '#28a745'
            ],
            'bcrypt_cost_12' => [
                'nome' => 'BCrypt (Cost 12)',
                'funcao' => function($senha) { 
                    $options = ['cost' => 12];
                    return password_hash($senha, PASSWORD_BCRYPT, $options); 
                },
                'descricao' => 'BCrypt com cost 12 (Mais lento, mais seguro)',
                'cor' => '#20c997'
            ],
            'argon2i' => [
                'nome' => 'Argon2i',
                'funcao' => function($senha) { 
                    return password_hash($senha, PASSWORD_ARGON2I); 
                },
                'descricao' => 'Algoritmo moderno e seguro (RECOMENDADO)',
                'cor' => '#28a745'
            ],
            'argon2id' => [
                'nome' => 'Argon2id',
                'funcao' => function($senha) { 
                    return password_hash($senha, PASSWORD_ARGON2ID); 
                },
                'descricao' => 'Melhor algoritmo disponível (MAIS SEGURO)',
                'cor' => '#28a745'
            ],
            'base64' => [
                'nome' => 'Base64',
                'funcao' => function($senha) { return base64_encode($senha); },
                'descricao' => 'Codificação (não é criptografia, apenas codificação)',
                'cor' => '#6c757d'
            ],
            'md5_salt' => [
                'nome' => 'MD5 com Salt',
                'funcao' => function($senha) { 
                    $salt = 'sige_angola_2024_seguro';
                    return md5($senha . $salt); 
                },
                'descricao' => 'MD5 com salt fixo (um pouco mais seguro)',
                'cor' => '#fd7e14'
            ],
            'hash_hmac' => [
                'nome' => 'HMAC SHA256',
                'funcao' => function($senha) { 
                    $key = 'sige_chave_secreta_2024';
                    return hash_hmac('sha256', $senha, $key); 
                },
                'descricao' => 'HMAC com SHA256 (usado para autenticação)',
                'cor' => '#17a2b8'
            ]
        ];
        
        // Gerar resultados apenas para os métodos selecionados
        foreach ($metodos_selecionados as $metodo) {
            if (isset($metodos[$metodo])) {
                $resultados[$metodo] = [
                    'nome' => $metodos[$metodo]['nome'],
                    'hash' => $metodos[$metodo]['funcao']($senha_original),
                    'descricao' => $metodos[$metodo]['descricao'],
                    'cor' => $metodos[$metodo]['cor'],
                    'tamanho' => strlen($metodos[$metodo]['funcao']($senha_original))
                ];
            }
        }
        
        // Se nenhum método foi selecionado, gerar os principais
        if (empty($resultados)) {
            $principais = ['md5', 'sha256', 'password_hash', 'bcrypt', 'argon2id'];
            foreach ($principais as $key) {
                if (isset($metodos[$key])) {
                    $resultados[$key] = [
                        'nome' => $metodos[$key]['nome'],
                        'hash' => $metodos[$key]['funcao']($senha_original),
                        'descricao' => $metodos[$key]['descricao'],
                        'cor' => $metodos[$key]['cor'],
                        'tamanho' => strlen($metodos[$key]['funcao']($senha_original))
                    ];
                }
            }
        }
    }
}

// Função para gerar senha aleatória
function gerarSenhaAleatoria($tamanho = 12, $maiusculas = true, $minusculas = true, $numeros = true, $especiais = true) {
    $caracteres = '';
    if ($maiusculas) $caracteres .= 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    if ($minusculas) $caracteres .= 'abcdefghijkmnopqrstuvwxyz';
    if ($numeros) $caracteres .= '23456789';
    if ($especiais) $caracteres .= '!@#$%&*?';
    
    $senha = '';
    $max = strlen($caracteres) - 1;
    
    for ($i = 0; $i < $tamanho; $i++) {
        $senha .= $caracteres[random_int(0, $max)];
    }
    
    return $senha;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerador de Senhas Criptografadas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            padding: 30px 20px;
        }
        
        .card {
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            margin-bottom: 30px;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 20px 25px;
            font-weight: 600;
            border: none;
        }
        
        .card-header i {
            font-size: 1.5rem;
            margin-right: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            padding: 12px 25px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
        }
        
        .hash-card {
            border-left: 4px solid;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        
        .hash-card:hover {
            transform: translateX(5px);
        }
        
        .hash-value {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            word-break: break-all;
            margin-top: 8px;
        }
        
        .copy-btn {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .copy-btn:hover {
            transform: scale(1.1);
        }
        
        .senha-gerada {
            background: #e8f5e9;
            border: 2px solid #006B3E;
            border-radius: 10px;
            padding: 15px;
            font-family: monospace;
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
            letter-spacing: 1px;
        }
        
        .alert-custom {
            border-radius: 15px;
            border-left: 4px solid;
        }
        
        .metodo-check {
            margin-bottom: 10px;
        }
        
        .metodo-check label {
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.2s;
            width: 100%;
        }
        
        .metodo-check input:checked + label {
            background: #e8f5e9;
            border-left: 3px solid #006B3E;
        }
        
        .badge-tamanho {
            font-family: monospace;
            font-size: 10px;
        }
        
        .recomendado {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-left: 4px solid #28a745;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .result-card {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Cabeçalho -->
                <div class="text-center mb-4">
                    <h1 class="text-white">
                        <i class="fas fa-key"></i> Gerador de Senhas Criptografadas
                    </h1>
                    <p class="text-white-50">Ferramenta para gerar hashes de senhas em diferentes algoritmos</p>
                </div>
                
                <!-- Card Principal -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-lock"></i> Gerar Hash da Senha
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formGerador">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-bold">Digite a Senha Original</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                                        <input type="text" name="senha" id="senha" class="form-control form-control-lg" 
                                               placeholder="Digite a senha para criptografar" 
                                               value="<?php echo htmlspecialchars($senha_original); ?>" required>
                                    </div>
                                    <small class="text-muted">Digite a senha que deseja criptografar</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Senha Aleatória</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-random"></i></span>
                                        <input type="text" id="senha_aleatoria" class="form-control form-control-lg" readonly>
                                        <button type="button" class="btn btn-secondary" onclick="gerarAleatoria()">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Clique no botão para gerar uma senha aleatória</small>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">Selecione os Métodos de Criptografia</label>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_md5" name="metodos[]" value="md5" checked>
                                                <label class="form-check-label" for="check_md5">
                                                    <i class="fas fa-hashtag"></i> MD5
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_sha1" name="metodos[]" value="sha1" checked>
                                                <label class="form-check-label" for="check_sha1">
                                                    <i class="fas fa-hashtag"></i> SHA-1
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_sha256" name="metodos[]" value="sha256" checked>
                                                <label class="form-check-label" for="check_sha256">
                                                    <i class="fas fa-hashtag"></i> SHA-256
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_sha512" name="metodos[]" value="sha512" checked>
                                                <label class="form-check-label" for="check_sha512">
                                                    <i class="fas fa-hashtag"></i> SHA-512
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="metodo-check recomendado">
                                                <input type="checkbox" class="form-check-input" id="check_password_hash" name="metodos[]" value="password_hash" checked>
                                                <label class="form-check-label" for="check_password_hash">
                                                    <i class="fas fa-star-of-life"></i> <strong>PASSWORD_HASH</strong> ⭐ RECOMENDADO
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_bcrypt" name="metodos[]" value="bcrypt" checked>
                                                <label class="form-check-label" for="check_bcrypt">
                                                    <i class="fas fa-shield-alt"></i> BCrypt (Cost 10)
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_bcrypt_cost12" name="metodos[]" value="bcrypt_cost_12">
                                                <label class="form-check-label" for="check_bcrypt_cost12">
                                                    <i class="fas fa-shield-alt"></i> BCrypt (Cost 12)
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_argon2i" name="metodos[]" value="argon2i">
                                                <label class="form-check-label" for="check_argon2i">
                                                    <i class="fas fa-shield-alt"></i> Argon2i
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_argon2id" name="metodos[]" value="argon2id">
                                                <label class="form-check-label" for="check_argon2id">
                                                    <i class="fas fa-shield-alt"></i> Argon2id (Mais Seguro)
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_base64" name="metodos[]" value="base64">
                                                <label class="form-check-label" for="check_base64">
                                                    <i class="fas fa-code"></i> Base64
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_md5_salt" name="metodos[]" value="md5_salt">
                                                <label class="form-check-label" for="check_md5_salt">
                                                    <i class="fas fa-salt-shaker"></i> MD5 com Salt
                                                </label>
                                            </div>
                                            <div class="metodo-check">
                                                <input type="checkbox" class="form-check-input" id="check_hmac" name="metodos[]" value="hash_hmac">
                                                <label class="form-check-label" for="check_hmac">
                                                    <i class="fas fa-key"></i> HMAC SHA256
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selecionarTodos()">
                                                <i class="fas fa-check-double"></i> Selecionar Todos
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="desmarcarTodos()">
                                                <i class="fas fa-times"></i> Desmarcar Todos
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-1" onclick="selecionarRecomendados()">
                                                <i class="fas fa-star"></i> Recomendados
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary btn-lg px-5">
                                    <i class="fas fa-sync-alt"></i> Gerar Hashes
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-lg px-5 ms-2" onclick="limparForm()">
                                    <i class="fas fa-trash-alt"></i> Limpar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Resultados -->
                <?php if (!empty($resultados)): ?>
                <div class="card result-card">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i> Resultados da Criptografia
                        <small class="float-end">Senha original: <strong><?php echo htmlspecialchars($senha_original); ?></strong></small>
                    </div>
                    <div class="card-body">
                        <?php foreach ($resultados as $key => $resultado): ?>
                        <div class="hash-card" style="border-left-color: <?php echo $resultado['cor']; ?>;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><i class="fas fa-code"></i> <?php echo $resultado['nome']; ?></strong>
                                    <span class="badge bg-secondary badge-tamanho ms-2"><?php echo $resultado['tamanho']; ?> caracteres</span>
                                    <?php if (strpos($resultado['nome'], 'PASSWORD_HASH') !== false || strpos($resultado['nome'], 'RECOMENDADO') !== false): ?>
                                    <span class="badge bg-success ms-2"><i class="fas fa-star"></i> RECOMENDADO</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted"><?php echo $resultado['descricao']; ?></small>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-outline-success copy-btn" onclick="copiarHash('hash_<?php echo $key; ?>')">
                                        <i class="fas fa-copy"></i> Copiar
                                    </button>
                                </div>
                            </div>
                            <div class="hash-value" id="hash_<?php echo $key; ?>">
                                <?php echo $resultado['hash']; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle"></i>
                            <strong>Recomendação de Segurança:</strong>
                            Para armazenar senhas em produção, utilize <strong>PASSWORD_HASH</strong> (BCrypt) ou <strong>Argon2id</strong>.
                            MD5 e SHA-1 não são seguros para senhas.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Tabela Comparativa -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-table"></i> Tabela Comparativa de Algoritmos
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Algoritmo</th>
                                        <th>Tamanho</th>
                                        <th>Segurança</th>
                                        <th>Velocidade</th>
                                        <th>Uso em Produção</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>MD5</td><td>32</td><td><span class="badge bg-danger">❌ Inseguro</span></td><td>Muito Rápido</td><td>❌ Não usar</td></tr>
                                    <tr><td>SHA-1</td><td>40</td><td><span class="badge bg-danger">❌ Inseguro</span></td><td>Rápido</td><td>❌ Não usar</td></tr>
                                    <tr><td>SHA-256</td><td>64</td><td><span class="badge bg-warning text-dark">⚠️ Médio</span></td><td>Rápido</td><td>⚠️ Uso limitado</td></tr>
                                    <tr><td>SHA-512</td><td>128</td><td><span class="badge bg-warning text-dark">⚠️ Médio</span></td><td>Rápido</td><td>⚠️ Uso limitado</td></tr>
                                    <tr class="table-success">
                                        <td><strong>PASSWORD_HASH</strong></td>
                                        <td>60</td>
                                        <td><span class="badge bg-success">✅ Muito Alto</span></td>
                                        <td>Lento</td>
                                        <td><strong>✅ RECOMENDADO</strong></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td><strong>BCrypt (Cost 10-12)</strong></td>
                                        <td>60</td>
                                        <td><span class="badge bg-success">✅ Alto</span></td>
                                        <td>Lento</td>
                                        <td><strong>✅ RECOMENDADO</strong></td>
                                    </tr>
                                    <tr class="table-success">
                                        <td><strong>Argon2id</strong></td>
                                        <td>Variável</td>
                                        <td><span class="badge bg-success">✅ Muito Alto</span></td>
                                        <td>Lento</td>
                                        <td><strong>✅ MAIS SEGURO</strong></td>
                                    </tr>
                                    <tr class="table-secondary">
                                        <td>MD5 com Salt</td>
                                        <td>32</td>
                                        <td><span class="badge bg-secondary">⚠️ Baixo</span></td>
                                        <td>Rápido</td>
                                        <td>❌ Não recomendado</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Exemplo de Uso -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-code"></i> Exemplo de Uso em PHP
                    </div>
                    <div class="card-body">
                        <pre class="bg-dark text-light p-3 rounded" style="font-family: monospace; font-size: 13px;">
&lt;?php
// Exemplo de como usar password_hash no seu sistema

// 1. CADASTRAR USUÁRIO (criptografar a senha)
$senha_digitada = $_POST['senha'];
$senha_hash = password_hash($senha_digitada, PASSWORD_DEFAULT);
// Salvar $senha_hash no banco de dados

// 2. LOGIN (verificar a senha)
$senha_digitada = $_POST['senha'];
$senha_hash_banco = $usuario['senha']; // Buscar do banco

if (password_verify($senha_digitada, $senha_hash_banco)) {
    echo "Login bem-sucedido!";
} else {
    echo "Senha incorreta!";
}

// 3. ALTERAR SENHA
$nova_senha = $_POST['nova_senha'];
$novo_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
// Atualizar no banco

// 4. VERIFICAR FORÇA DA SENHA
if (strlen($senha) >= 8 && 
    preg_match('/[A-Z]/', $senha) && 
    preg_match('/[a-z]/', $senha) && 
    preg_match('/[0-9]/', $senha)) {
    echo "Senha forte!";
}
?&gt;
                        </pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Gerar senha aleatória
        function gerarAleatoria() {
            const caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*?';
            let senha = '';
            for (let i = 0; i < 12; i++) {
                senha += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
            }
            document.getElementById('senha_aleatoria').value = senha;
            document.getElementById('senha').value = senha;
        }
        
        // Copiar hash
        function copiarHash(elementId) {
            const hashText = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(hashText).then(() => {
                const btn = event.target.closest('.copy-btn');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                }, 2000);
            });
        }
        
        // Selecionar todos os métodos
        function selecionarTodos() {
            document.querySelectorAll('input[name="metodos[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        // Desmarcar todos os métodos
        function desmarcarTodos() {
            document.querySelectorAll('input[name="metodos[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // Selecionar apenas os métodos recomendados
        function selecionarRecomendados() {
            desmarcarTodos();
            document.getElementById('check_password_hash').checked = true;
            document.getElementById('check_bcrypt').checked = true;
            document.getElementById('check_argon2id').checked = true;
        }
        
        // Limpar formulário
        function limparForm() {
            window.location.href = 'gerar_senha.php';
        }
        
        // Se houver senha no campo, focar no botão
        document.addEventListener('DOMContentLoaded', function() {
            const senhaInput = document.getElementById('senha');
            if (senhaInput.value) {
                senhaInput.focus();
            }
        });
    </script>
</body>
</html>