<?php
// install.php - Instalador do Sistema SIGE SaaS

// ============================================
// CONFIGURAÇÕES INICIAIS
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Africa/Luanda');

// Criar diretórios necessários
$directories = ['config', 'logs', 'uploads', 'assets/cache', 'assets/images', 'lib', 'super-admin', 'escola', 'api'];
foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "✓ Diretório criado: {$dir}<br>";
    }
}

// Verificar se já está instalado
$lockFile = __DIR__ . '/config/installed.lock';

if (file_exists($lockFile) && !isset($_GET['force'])) {
    header('Location: index.php');
    exit;
}

// Se veio com force=1, permite reinstalação
if (isset($_GET['force']) && $_GET['force'] == 1) {
    if (file_exists($lockFile)) {
        rename($lockFile, $lockFile . '.bak.' . date('Ymd_His'));
        echo "<div class='alert alert-warning'>Modo de reinstalação ativado. Backup criado.</div>";
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Verificar requisitos
        $allOk = true;
        $requirements = checkRequirements();
        foreach ($requirements as $req) {
            if (!$req['status'] && strpos($req['name'], 'GD') === false) {
                $allOk = false;
                break;
            }
        }
        
        if ($allOk) {
            header('Location: install.php?step=2');
            exit;
        } else {
            $error = 'Por favor, corrija os requisitos acima.';
        }
    } elseif ($step == 2) {
        // Configurar banco de dados
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_name = $_POST['db_name'] ?? 'sige_saas';
        $db_user = $_POST['db_user'] ?? 'root';
        $db_pass = $_POST['db_pass'] ?? '';
        
        try {
            // Testar conexão
            $pdo = new PDO("mysql:host={$db_host}", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Criar banco de dados
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");
            
            // Salvar configuração
            saveDatabaseConfig($db_host, $db_name, $db_user, $db_pass);
            
            // Criar tabelas
            $sql = getInstallSQL();
            $pdo->exec($sql);
            
            $_SESSION['db_installed'] = true;
            header('Location: install.php?step=3');
            exit;
            
        } catch (PDOException $e) {
            $error = "Erro: " . $e->getMessage();
        }
    } elseif ($step == 3) {
        // Configurar admin e escola padrão
        $admin_name = $_POST['admin_name'] ?? '';
        $admin_email = $_POST['admin_email'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';
        
        $school_name = $_POST['school_name'] ?? '';
        $school_subdomain = $_POST['school_subdomain'] ?? '';
        
        if ($admin_password !== $admin_password_confirm) {
            $error = 'As senhas não coincidem.';
        } elseif (strlen($admin_password) < 6) {
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } else {
            try {
                require_once __DIR__ . '/config/database.php';
                // ERRADO - Causa o erro
               /* $db = new Database();
                $conn = $db->getConnection();*/
                
                // CORRETO - Usando Singleton
                    $db = Database::getInstance();
                    $conn = $db->getConnection();
                
                $conn->beginTransaction();
                
                // Criar super admin
                $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO usuarios (nome, email, senha, tipo, status, created_at)
                    VALUES (:nome, :email, :senha, 'super_admin', 'ativo', NOW())
                ");
                $stmt->execute([
                    ':nome' => $admin_name,
                    ':email' => $admin_email,
                    ':senha' => $hashed_password
                ]);
                
                // Criar escola padrão
                $stmt = $conn->prepare("
                    INSERT INTO escolas (nome, subdominio, email, status, created_at)
                    VALUES (:nome, :subdominio, :email, 'ativa', NOW())
                ");
                $stmt->execute([
                    ':nome' => $school_name,
                    ':subdominio' => $school_subdomain,
                    ':email' => $admin_email
                ]);
                
                $conn->commit();
                
                // Criar arquivo de lock
                $lockData = [
                    'installed_at' => date('Y-m-d H:i:s'),
                    'version' => '2.0.0',
                    'admin_email' => $admin_email,
                    'school_subdomain' => $school_subdomain
                ];
                file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT));
                
                header('Location: install.php?step=4');
                exit;
                
            } catch (Exception $e) {
                if (isset($conn)) $conn->rollBack();
                $error = "Erro: " . $e->getMessage();
            }
        }
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function checkRequirements() {
    $requirements = [];
    
    // PHP Version
    $requirements[] = [
        'name' => 'PHP >= 7.4',
        'status' => version_compare(PHP_VERSION, '7.4', '>='),
        'current' => PHP_VERSION
    ];
    
    // Extensões
    $extensions = ['pdo_mysql', 'json', 'mbstring', 'gd', 'openssl'];
    foreach ($extensions as $ext) {
        $requirements[] = [
            'name' => "Extensão: {$ext}",
            'status' => extension_loaded($ext),
            'current' => extension_loaded($ext) ? 'OK' : 'Faltando'
        ];
    }
    
    // Permissões de escrita
    $dirs = ['config', 'logs', 'uploads'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        $requirements[] = [
            'name' => "Permissão: /{$dir}",
            'status' => is_writable($path),
            'current' => is_writable($path) ? 'Gravável' : 'Sem permissão'
        ];
    }
    
    return $requirements;
}

function saveDatabaseConfig($host, $dbname, $user, $pass) {
    $config = "<?php\n";
    $config .= "// db_config.php - Configuração do banco de dados\n";
    $config .= "// Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "return [\n";
    $config .= "    'host' => '{$host}',\n";
    $config .= "    'db_name' => '{$dbname}',\n";
    $config .= "    'username' => '{$user}',\n";
    $config .= "    'password' => '{$pass}',\n";
    $config .= "    'charset' => 'utf8mb4'\n";
    $config .= "];\n";
    $config .= "?>";
    
    file_put_contents(__DIR__ . '/config/db_config.php', $config);
}

function getInstallSQL() {
    return "
    -- Tabela de planos
    CREATE TABLE IF NOT EXISTS `planos` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `descricao` TEXT,
        `preco_mensal` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `preco_anual` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `recursos` JSON,
        `status` ENUM('ativo', 'inativo') DEFAULT 'ativo',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Tabela de escolas
    CREATE TABLE IF NOT EXISTS `escolas` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `plano_id` INT,
        `nome` VARCHAR(200) NOT NULL,
        `subdominio` VARCHAR(100) UNIQUE NOT NULL,
        `dominio_personalizado` VARCHAR(200) UNIQUE,
        `email` VARCHAR(100) NOT NULL,
        `telefone` VARCHAR(20),
        `logo` VARCHAR(255),
        `status` ENUM('ativa', 'suspensa', 'inativa', 'trial') DEFAULT 'trial',
        `trial_ate` DATE,
        `config` JSON,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL,
        INDEX idx_subdominio (subdominio),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Tabela de usuários
    CREATE TABLE IF NOT EXISTS `usuarios` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `escola_id` INT NULL,
        `nome` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) UNIQUE NOT NULL,
        `senha` VARCHAR(255) NOT NULL,
        `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno') NOT NULL,
        `telefone` VARCHAR(20),
        `foto` VARCHAR(255),
        `status` ENUM('ativo', 'inativo', 'bloqueado') DEFAULT 'ativo',
        `ultimo_acesso` DATETIME,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
        INDEX idx_email (email),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Inserir planos padrão
    INSERT INTO `planos` (`nome`, `descricao`, `preco_mensal`, `preco_anual`, `status`) VALUES
    ('Básico', 'Plano ideal para pequenas escolas', 199.00, 1990.00, 'ativo'),
    ('Profissional', 'Plano completo para escolas em crescimento', 399.00, 3990.00, 'ativo'),
    ('Empresarial', 'Plano premium para grandes instituições', 799.00, 7990.00, 'ativo');
    ";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - SIGE SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
        }
        .install-container {
            max-width: 800px;
            margin: 50px auto;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 25px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step .circle {
            width: 40px;
            height: 40px;
            background: #e0e0e0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #666;
        }
        .step.active .circle {
            background: #667eea;
            color: white;
        }
        .step.completed .circle {
            background: #48bb78;
            color: white;
        }
        .step .label {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
        .step.active .label {
            color: #667eea;
            font-weight: bold;
        }
        .requirement-item {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
        }
        .requirement-ok {
            background: #d4edda;
            color: #155724;
        }
        .requirement-error {
            background: #f8d7da;
            color: #721c24;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="card">
            <div class="card-header text-center">
                <i class="fas fa-chalkboard-user fa-3x mb-3"></i>
                <h2 class="mb-0">SIGE SaaS</h2>
                <p class="mb-0 mt-2">Sistema Integrado de Gestão Escolar</p>
            </div>
            <div class="card-body">
                <div class="step-indicator">
                    <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 1 ? '<i class="fas fa-check"></i>' : '1'; ?></div>
                        <div class="label">Requisitos</div>
                    </div>
                    <div class="step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 2 ? '<i class="fas fa-check"></i>' : '2'; ?></div>
                        <div class="label">Banco de Dados</div>
                    </div>
                    <div class="step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">
                        <div class="circle"><?php echo $step > 3 ? '<i class="fas fa-check"></i>' : '3'; ?></div>
                        <div class="label">Admin</div>
                    </div>
                    <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                        <div class="circle">4</div>
                        <div class="label">Concluído</div>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                    <h4 class="mb-3">Verificação de Requisitos</h4>
                    <?php $requirements = checkRequirements(); ?>
                    <?php foreach ($requirements as $req): ?>
                        <div class="requirement-item <?php echo $req['status'] ? 'requirement-ok' : 'requirement-error'; ?>">
                            <span><?php echo $req['name']; ?></span>
                            <span><?php echo $req['status'] ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>'; ?> <?php echo $req['current']; ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <form method="POST" class="mt-4">
                        <button type="submit" class="btn btn-primary w-100">
                            Continuar <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 2): ?>
                    <h4 class="mb-3">Configuração do Banco de Dados</h4>
                    <form method="POST">
                        <div class="mb-3">
                            <label>Host</label>
                            <input type="text" name="db_host" class="form-control" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label>Nome do Banco</label>
                            <input type="text" name="db_name" class="form-control" value="sige_saas" required>
                        </div>
                        <div class="mb-3">
                            <label>Usuário</label>
                            <input type="text" name="db_user" class="form-control" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label>Senha</label>
                            <input type="password" name="db_pass" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            Testar e Instalar <i class="fas fa-database"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 3): ?>
                    <h4 class="mb-3">Configuração Inicial</h4>
                    <form method="POST">
                        <h5 class="mb-3">Administrador Principal</h5>
                        <div class="mb-3">
                            <label>Nome</label>
                            <input type="text" name="admin_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>E-mail</label>
                            <input type="email" name="admin_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Senha</label>
                            <input type="password" name="admin_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Confirmar Senha</label>
                            <input type="password" name="admin_password_confirm" class="form-control" required>
                        </div>
                        
                        <h5 class="mb-3 mt-4">Escola Padrão</h5>
                        <div class="mb-3">
                            <label>Nome da Escola</label>
                            <input type="text" name="school_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Subdomínio</label>
                            <div class="input-group">
                                <input type="text" name="school_subdomain" class="form-control" required>
                                <span class="input-group-text">.sige.com</span>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            Finalizar Instalação <i class="fas fa-check-circle"></i>
                        </button>
                    </form>
                    
                <?php elseif ($step == 4): ?>
                    <div class="text-center">
                        <i class="fas fa-check-circle fa-5x text-success mb-3"></i>
                        <h4>Instalação Concluída!</h4>
                        <p class="text-muted">O sistema foi instalado com sucesso.</p>
                        
                        <div class="alert alert-info mt-4">
                            <strong>Dados de Acesso:</strong><br>
                            E-mail: <?php echo $_POST['admin_email'] ?? 'admin@sige.com'; ?><br>
                            Senha: (a que você definiu)
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-shield-alt"></i> Recomendação de segurança:<br>
                            Renomeie ou remova o arquivo <strong>install.php</strong>
                        </div>
                        
                        <a href="index.php" class="btn btn-primary btn-lg mt-3">
                            <i class="fas fa-sign-in-alt"></i> Acessar o Sistema
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>