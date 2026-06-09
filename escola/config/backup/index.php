<?php
// escola/config/backup/index.php - Sistema de Backup Automatizado
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

// Verificar se o usuário tem permissão de administrador
if (!in_array($_SESSION['usuario_tipo'], ['super_admin', 'admin_escola', 'diretor'])) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// TABELA DE CONFIGURAÇÃO DE BACKUP
// ============================================
$check = $conn->query("SHOW TABLES LIKE 'backup_config'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE backup_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            intervalo_minutos INT DEFAULT 60,
            ativo TINYINT DEFAULT 0,
            ultimo_backup DATETIME,
            proximo_backup DATETIME,
            diretorio VARCHAR(255),
            manter_backups INT DEFAULT 10,
            incluir_tabelas TEXT,
            excluir_tabelas TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
    
    // Inserir configuração padrão
    $conn->prepare("
        INSERT INTO backup_config (escola_id, intervalo_minutos, ativo, diretorio, manter_backups) 
        VALUES (:escola_id, 60, 0, 'uploads/backups/', 10)
    ")->execute([':escola_id' => $escola_id]);
}

// Tabela de histórico de backups
$check = $conn->query("SHOW TABLES LIKE 'backup_historico'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE backup_historico (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome_arquivo VARCHAR(255),
            tamanho INT,
            numero_tabelas INT,
            status VARCHAR(20) DEFAULT 'sucesso',
            mensagem TEXT,
            tipo VARCHAR(20) DEFAULT 'manual',
            data_backup DATETIME,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Carregar configuração atual
$config = $conn->prepare("SELECT * FROM backup_config WHERE escola_id = :escola_id LIMIT 1");
$config->execute([':escola_id' => $escola_id]);
$config = $config->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $conn->prepare("INSERT INTO backup_config (escola_id, intervalo_minutos, ativo, diretorio, manter_backups) VALUES (:escola_id, 60, 0, 'uploads/backups/', 10)")->execute([':escola_id' => $escola_id]);
    $config = ['intervalo_minutos' => 60, 'ativo' => 0, 'diretorio' => 'uploads/backups/', 'manter_backups' => 10];
}

// Salvar configuração
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_config'])) {
    $intervalo_minutos = (int)$_POST['intervalo_minutos'];
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $diretorio = $_POST['diretorio'];
    $manter_backups = (int)$_POST['manter_backups'];
    $incluir_tabelas = $_POST['incluir_tabelas'] ?? '';
    $excluir_tabelas = $_POST['excluir_tabelas'] ?? '';
    
    $stmt = $conn->prepare("
        UPDATE backup_config 
        SET intervalo_minutos = :intervalo, 
            ativo = :ativo, 
            diretorio = :diretorio, 
            manter_backups = :manter,
            incluir_tabelas = :incluir,
            excluir_tabelas = :excluir
        WHERE escola_id = :escola_id
    ");
    $stmt->execute([
        ':intervalo' => $intervalo_minutos,
        ':ativo' => $ativo,
        ':diretorio' => $diretorio,
        ':manter' => $manter_backups,
        ':incluir' => $incluir_tabelas,
        ':excluir' => $excluir_tabelas,
        ':escola_id' => $escola_id
    ]);
    
    $msg_sucesso = "Configuração salva com sucesso!";
    
    // Se ativado, calcular próximo backup
    if ($ativo) {
        $proximo = date('Y-m-d H:i:s', strtotime("+{$intervalo_minutos} minutes"));
        $conn->prepare("UPDATE backup_config SET proximo_backup = :proximo WHERE escola_id = :escola_id")->execute([':proximo' => $proximo, ':escola_id' => $escola_id]);
    }
    
    header("Location: index.php?msg=" . urlencode($msg_sucesso));
    exit;
}

// Realizar backup agora (manual)
if (isset($_GET['fazer_backup'])) {
    $resultado = realizarBackup($conn, $escola_id, $config);
    if ($resultado['success']) {
        header("Location: index.php?msg=Backup realizado com sucesso! Arquivo: " . $resultado['arquivo']);
    } else {
        header("Location: index.php?erro=" . urlencode($resultado['error']));
    }
    exit;
}

// Baixar backup
if (isset($_GET['download']) && isset($_GET['arquivo'])) {
    $arquivo = $_GET['arquivo'];
    $caminho = __DIR__ . '/../../../' . $config['diretorio'] . $arquivo;
    
    if (file_exists($caminho)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($arquivo) . '"');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    } else {
        header("Location: index.php?erro=Arquivo não encontrado");
        exit;
    }
}

// Excluir backup
if (isset($_GET['excluir']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $backup = $conn->prepare("SELECT nome_arquivo FROM backup_historico WHERE id = :id AND escola_id = :escola_id");
    $backup->execute([':id' => $id, ':escola_id' => $escola_id]);
    $backup = $backup->fetch(PDO::FETCH_ASSOC);
    
    if ($backup) {
        $caminho = __DIR__ . '/../../../' . $config['diretorio'] . $backup['nome_arquivo'];
        if (file_exists($caminho)) {
            unlink($caminho);
        }
        $conn->prepare("DELETE FROM backup_historico WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?msg=Backup excluído com sucesso");
        exit;
    }
}

// Verificar backups agendados (chamado via AJAX)
if (isset($_GET['verificar_agendado'])) {
    $config_atual = $conn->prepare("SELECT * FROM backup_config WHERE escola_id = :escola_id");
    $config_atual->execute([':escola_id' => $escola_id]);
    $cfg = $config_atual->fetch(PDO::FETCH_ASSOC);
    
    if ($cfg && $cfg['ativo'] && $cfg['proximo_backup'] && strtotime($cfg['proximo_backup']) <= time()) {
        realizarBackup($conn, $escola_id, $cfg);
        
        // Atualizar próximo backup
        $proximo = date('Y-m-d H:i:s', strtotime("+{$cfg['intervalo_minutos']} minutes"));
        $conn->prepare("UPDATE backup_config SET proximo_backup = :proximo, ultimo_backup = NOW() WHERE escola_id = :escola_id")->execute([':proximo' => $proximo, ':escola_id' => $escola_id]);
        
        echo json_encode(['success' => true, 'message' => 'Backup automático realizado']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nenhum backup necessário']);
    }
    exit;
}

// Buscar histórico de backups
$historico = $conn->prepare("
    SELECT * FROM backup_historico 
    WHERE escola_id = :escola_id 
    ORDER BY data_backup DESC 
    LIMIT 30
");
$historico->execute([':escola_id' => $escola_id]);
$historico = $historico->fetchAll(PDO::FETCH_ASSOC);

// Buscar todos os backups da pasta
$backup_dir = __DIR__ . '/../../../' . $config['diretorio'];
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

$arquivos_backup = glob($backup_dir . '*.sql');
$arquivos_backup = array_reverse($arquivos_backup);

// Mensagens
$msg = $_GET['msg'] ?? '';
$erro = $_GET['erro'] ?? '';

// ============================================
// FUNÇÃO DE BACKUP
// ============================================
function realizarBackup($conn, $escola_id, $config) {
    $backup_dir = __DIR__ . '/../../../' . $config['diretorio'];
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    $nome_arquivo = 'backup_' . date('Y-m-d_H-i-s') . '_escola_' . $escola_id . '.sql';
    $caminho_completo = $backup_dir . $nome_arquivo;
    
    try {
        // Buscar todas as tabelas do banco
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Excluir tabelas desnecessárias
        $excluir_tabelas = ['backup_config', 'backup_historico', 'sessoes_ativas', 'logs_usuarios'];
        $incluir_tabelas = $config['incluir_tabelas'] ? explode(',', $config['incluir_tabelas']) : [];
        
        if (!empty($incluir_tabelas)) {
            $tables = array_intersect($tables, $incluir_tabelas);
        }
        
        if (!empty($excluir_tabelas)) {
            $tables = array_diff($tables, $excluir_tabelas);
        }
        
        $conteudo = "-- SIGE ANGOLA BACKUP\n";
        $conteujo .= "-- Gerado em: " . date('Y-m-d H:i:s') . "\n";
        $conteudo .= "-- Escola ID: $escola_id\n\n";
        $conteudo .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        $numero_tabelas = 0;
        
        foreach ($tables as $table) {
            $result = $conn->query("SELECT * FROM `$table`");
            $num_fields = $result->columnCount();
            
            // Drop table
            $conteudo .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Create table
            $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $conteudo .= $create['Create Table'] . ";\n\n";
            
            // Insert data
            $rows = $conn->query("SELECT * FROM `$table`");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $conteudo .= "INSERT INTO `$table` VALUES(";
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = "NULL";
                    } else {
                        $values[] = "'" . addslashes($value) . "'";
                    }
                }
                $conteudo .= implode(',', $values) . ");\n";
            }
            $conteudo .= "\n";
            $numero_tabelas++;
        }
        
        $conteudo .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        
        // Salvar arquivo
        file_put_contents($caminho_completo, $conteudo);
        
        // Comprimir
        $conteudo_gz = gzencode($conteudo, 9);
        file_put_contents($caminho_completo . '.gz', $conteudo_gz);
        
        $tamanho = filesize($caminho_completo);
        
        // Registrar no histórico
        $stmt = $conn->prepare("
            INSERT INTO backup_historico (escola_id, nome_arquivo, tamanho, numero_tabelas, status, data_backup) 
            VALUES (:escola_id, :nome, :tamanho, :tabelas, 'sucesso', NOW())
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome_arquivo,
            ':tamanho' => $tamanho,
            ':tabelas' => $numero_tabelas
        ]);
        
        // Limpar backups antigos
        $manter = (int)$config['manter_backups'];
        $backups = glob($backup_dir . 'backup_*.sql');
        if (count($backups) > $manter) {
            $backups_to_delete = array_slice($backups, $manter);
            foreach ($backups_to_delete as $file) {
                unlink($file);
                if (file_exists($file . '.gz')) {
                    unlink($file . '.gz');
                }
            }
        }
        
        return ['success' => true, 'arquivo' => $nome_arquivo];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Automático | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 15px 15px 0 0;
        }
        
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .status-ativo {
            background: #d4edda;
            color: #155724;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .status-inativo {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .backup-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .backup-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .countdown {
            font-size: 2em;
            font-weight: bold;
            color: #006B3E;
            text-align: center;
        }
        
        .countdown-label {
            text-align: center;
            color: #666;
        }
        
        .form-range {
            width: 100%;
        }
        
        .alert-success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; }
            .menu-toggle { display: block; }
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Configuração do Sistema</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-database"></i> Backup</a></li>
            <li class="nav-item"><a href="../geral/index.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="../../index.php" class="nav-link"><i class="fas fa-arrow-left"></i> Voltar</a></li>
            <li class="nav-item"><a href="../../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-database"></i> Backup do Sistema</h2>
            <div>
                <a href="?fazer_backup=1" class="btn btn-primary" onclick="return confirm('Tem certeza que deseja realizar backup agora?')">
                    <i class="fas fa-play"></i> Backup Agora
                </a>
            </div>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($erro); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-5">
                <!-- Configuração de Backup -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-sliders-h"></i> Configuração de Backup Automático
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="ativo" id="ativo" value="1" <?php echo $config['ativo'] ? 'checked' : ''; ?> style="width: 50px; height: 25px;">
                                    <label class="form-check-label ms-2" for="ativo">
                                        <strong>Ativar Backup Automático</strong>
                                    </label>
                                </div>
                                <small class="text-muted">Quando ativado, o sistema realizará backups no intervalo definido</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-clock"></i> Intervalo entre backups</label>
                                <div class="row">
                                    <div class="col-8">
                                        <input type="range" class="form-range" name="intervalo_minutos" id="intervalo_range" min="1" max="1440" step="1" value="<?php echo $config['intervalo_minutos']; ?>">
                                    </div>
                                    <div class="col-4">
                                        <input type="number" name="intervalo_minutos" id="intervalo_numero" class="form-control" value="<?php echo $config['intervalo_minutos']; ?>" min="1" max="1440">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php 
                                        $horas = floor($config['intervalo_minutos'] / 60);
                                        $minutos = $config['intervalo_minutos'] % 60;
                                        if ($horas > 0) {
                                            echo "Equivalente a " . ($horas > 0 ? $horas . " hora(s)" : "") . ($minutos > 0 ? " e " . $minutos . " minuto(s)" : "");
                                        } else {
                                            echo "Equivalente a " . $minutos . " minuto(s)";
                                        }
                                    ?>
                                </small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-folder"></i> Diretório de Backup</label>
                                <input type="text" name="diretorio" class="form-control" value="<?php echo htmlspecialchars($config['diretorio']); ?>" required>
                                <small class="text-muted">Relativo à raiz do sistema</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-trash-alt"></i> Manter últimos backups</label>
                                <select name="manter_backups" class="form-select">
                                    <option value="5" <?php echo $config['manter_backups'] == 5 ? 'selected' : ''; ?>>5 backups</option>
                                    <option value="10" <?php echo $config['manter_backups'] == 10 ? 'selected' : ''; ?>>10 backups</option>
                                    <option value="15" <?php echo $config['manter_backups'] == 15 ? 'selected' : ''; ?>>15 backups</option>
                                    <option value="20" <?php echo $config['manter_backups'] == 20 ? 'selected' : ''; ?>>20 backups</option>
                                    <option value="30" <?php echo $config['manter_backups'] == 30 ? 'selected' : ''; ?>>30 backups</option>
                                    <option value="50" <?php echo $config['manter_backups'] == 50 ? 'selected' : ''; ?>>50 backups</option>
                                </select>
                                <small class="text-muted">Backups mais antigos serão automaticamente removidos</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-table"></i> Incluir apenas tabelas (opcional)</label>
                                <input type="text" name="incluir_tabelas" class="form-control" placeholder="tabela1,tabela2,tabela3" value="<?php echo htmlspecialchars($config['incluir_tabelas']); ?>">
                                <small class="text-muted">Deixe em branco para incluir todas as tabelas</small>
                            </div>
                            
                            <button type="submit" name="salvar_config" class="btn btn-primary w-100">
                                <i class="fas fa-save"></i> Salvar Configuração
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Status do Backup -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i> Status do Backup
                    </div>
                    <div class="card-body text-center">
                        <?php if ($config['ativo']): ?>
                            <div class="status-ativo d-inline-block mb-3">
                                <i class="fas fa-check-circle"></i> BACKUP AUTOMÁTICO ATIVO
                            </div>
                            <div class="countdown" id="countdown">--:--:--</div>
                            <div class="countdown-label">Próximo backup automático</div>
                            <hr>
                            <div><i class="fas fa-clock"></i> <strong>Intervalo:</strong> <?php echo $config['intervalo_minutos']; ?> minutos</div>
                            <?php if ($config['ultimo_backup']): ?>
                            <div><i class="fas fa-history"></i> <strong>Último backup:</strong> <?php echo date('d/m/Y H:i:s', strtotime($config['ultimo_backup'])); ?></div>
                            <?php endif; ?>
                            <?php if ($config['proximo_backup']): ?>
                            <div><i class="fas fa-calendar"></i> <strong>Próximo backup:</strong> <span id="proximo_backup_text"><?php echo date('d/m/Y H:i:s', strtotime($config['proximo_backup'])); ?></span></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="status-inativo d-inline-block mb-3">
                                <i class="fas fa-ban"></i> BACKUP AUTOMÁTICO INATIVO
                            </div>
                            <p class="text-muted">Ative o backup automático nas configurações acima para agendar backups periódicos.</p>
                            <a href="?fazer_backup=1" class="btn btn-primary">
                                <i class="fas fa-play"></i> Fazer Backup Agora
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Espaço em Disco -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-hdd"></i> Espaço em Disco
                    </div>
                    <div class="card-body">
                        <?php
                        $backup_dir_path = __DIR__ . '/../../../' . $config['diretorio'];
                        $total_size = 0;
                        $files = glob($backup_dir_path . '*.sql');
                        foreach ($files as $file) {
                            $total_size += filesize($file);
                        }
                        
                        $free_space = disk_free_space($backup_dir_path);
                        $total_space = disk_total_space($backup_dir_path);
                        $used_percent = ($total_size > 0) ? min(100, round(($total_size / $total_space) * 100, 2)) : 0;
                        ?>
                        
                        <div class="mb-3">
                            <small>Backups: <strong><?php echo count($files); ?></strong> arquivo(s)</small>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-success" style="width: <?php echo $used_percent; ?>%"></div>
                            </div>
                            <small class="text-muted">Espaço usado em backups: <?php echo round($total_size / 1024 / 1024, 2); ?> MB</small>
                        </div>
                        
                        <div class="mb-0">
                            <small>Disco disponível: <strong><?php echo round($free_space / 1024 / 1024 / 1024, 2); ?> GB</strong> </small>
                            <div class="progress mt-1">
                                <?php $disk_percent = 100 - round(($free_space / $total_space) * 100, 2); ?>
                                <div class="progress-bar bg-info" style="width: <?php echo $disk_percent; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-7">
                <!-- Histórico de Backups -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history"></i> Histórico de Backups
                    </div>
                    <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                        <?php if (empty($historico) && empty($arquivos_backup)): ?>
                            <p class="text-center text-muted">Nenhum backup realizado ainda.</p>
                        <?php else: ?>
                            <?php foreach ($arquivos_backup as $arquivo): 
                                $nome = basename($arquivo);
                                $data = filemtime($arquivo);
                                $tamanho = filesize($arquivo);
                                $tamanho_mb = round($tamanho / 1024 / 1024, 2);
                            ?>
                            <div class="backup-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-file-archive"></i> <?php echo $nome; ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i:s', $data); ?> |
                                            <i class="fas fa-database"></i> <?php echo $tamanho_mb; ?> MB
                                        </small>
                                    </div>
                                    <div>
                                        <a href="?download=1&arquivo=<?php echo urlencode($nome); ?>" class="btn btn-sm btn-success" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="?excluir=1&id=<?php echo $nome; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir este backup?')" title="Excluir">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (!empty($historico)): ?>
                                <hr>
                                <h6><i class="fas fa-list"></i> Registro de Atividades</h6>
                                <?php foreach ($historico as $h): ?>
                                <div class="backup-item" style="border-left-color: <?php echo $h['status'] == 'sucesso' ? '#28a745' : '#dc3545'; ?>">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?php echo htmlspecialchars($h['nome_arquivo']); ?></strong><br>
                                            <small>
                                                <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i:s', strtotime($h['data_backup'])); ?> |
                                                <i class="fas fa-table"></i> <?php echo $h['numero_tabelas']; ?> tabelas |
                                                <i class="fas fa-database"></i> <?php echo round($h['tamanho'] / 1024 / 1024, 2); ?> MB |
                                                <span class="badge bg-<?php echo $h['status'] == 'sucesso' ? 'success' : 'danger'; ?>"><?php echo $h['status']; ?></span>
                                            </small>
                                        </div>
                                        <div>
                                            <a href="?download=1&arquivo=<?php echo urlencode($h['nome_arquivo']); ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Informações de Agendamento -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-calendar-alt"></i> Sobre o Agendamento Automático
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Como funciona o backup automático?</strong>
                            <ul class="mb-0 mt-2">
                                <li>O sistema verifica a cada minuto se é hora de realizar um backup</li>
                                <li>O backup é realizado em background sem interferir no uso do sistema</li>
                                <li>Os arquivos são salvos no diretório configurado</li>
                                <li>Backups antigos são automaticamente removidos conforme configuração</li>
                                <li><strong>Intervalos sugeridos:</strong>
                                    <ul>
                                        <li>Muito crítico: 10 - 30 minutos</li>
                                        <li>Crítico: 30 - 60 minutos</li>
                                        <li>Normal: 60 - 360 minutos</li>
                                        <li>Baixa frequência: 360 - 1440 minutos (1 dia)</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Recomendações:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Mantenha pelo menos 10 backups para segurança</li>
                                <li>Armazene backups em local seguro (pode ser na nuvem)</li>
                                <li>Teste periodicamente a restauração dos backups</li>
                                <li>Verifique o espaço em disco disponível</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu toggle
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Sincronizar range e number
        const range = document.getElementById('intervalo_range');
        const number = document.getElementById('intervalo_numero');
        
        if (range && number) {
            range.addEventListener('input', function() { number.value = this.value; });
            number.addEventListener('input', function() { range.value = this.value; });
        }
        
        // Contagem regressiva para próximo backup
        let ativo = <?php echo $config['ativo'] ? 'true' : 'false'; ?>;
        let proximoBackup = '<?php echo $config['proximo_backup']; ?>';
        
        function atualizarCountdown() {
            if (!ativo) return;
            
            let agora = new Date();
            let proximo = new Date(proximoBackup);
            let diff = proximo - agora;
            
            if (diff <= 0) {
                // Verificar se precisa fazer backup
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    data: { verificar_agendado: 1 },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
                document.getElementById('countdown').innerHTML = '00:00:00';
                return;
            }
            
            let horas = Math.floor(diff / (1000 * 60 * 60));
            let minutos = Math.floor((diff % (3600000)) / (1000 * 60));
            let segundos = Math.floor((diff % (60000)) / 1000);
            
            document.getElementById('countdown').innerHTML = 
                String(horas).padStart(2, '0') + ':' + 
                String(minutos).padStart(2, '0') + ':' + 
                String(segundos).padStart(2, '0');
        }
        
        if (ativo) {
            setInterval(atualizarCountdown, 1000);
            atualizarCountdown();
        }
        
        // Função para verificar backups automaticamente a cada minuto
        setInterval(function() {
            if (ativo) {
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    data: { verificar_agendado: 1 },
                    dataType: 'json'
                });
            }
        }, 60000);
    </script>
</body>
</html>