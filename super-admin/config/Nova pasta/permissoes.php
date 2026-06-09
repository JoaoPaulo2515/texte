<?php
// super-admin/config/permissoes.php - Gestão de Permissões e Papéis
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Verificar se a tabela de permissões existe
$stmt = $conn->query("SHOW TABLES LIKE 'permissoes'");
if ($stmt->rowCount() == 0) {
    // Criar tabela de permissões
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `permissoes` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `nome` VARCHAR(50) NOT NULL,
            `descricao` TEXT NULL,
            `modulo` VARCHAR(50) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Criar tabela de papéis
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `papeis` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `nome` VARCHAR(50) NOT NULL,
            `descricao` TEXT NULL,
            `tipo` ENUM('super_admin', 'admin_escola', 'diretor', 'professor', 'secretaria', 'aluno', 'pai') NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Criar tabela de permissões por papel
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `papel_permissoes` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `papel_id` INT NOT NULL,
            `permissao_id` INT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (papel_id) REFERENCES papeis(id) ON DELETE CASCADE,
            FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE,
            UNIQUE KEY unique_papel_permissao (papel_id, permissao_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Inserir permissões padrão
    $permissoes_padrao = [
        // Dashboard
        ['dashboard_ver', 'Visualizar Dashboard', 'dashboard'],
        ['dashboard_estatisticas', 'Ver Estatísticas', 'dashboard'],
        
        // Escolas
        ['escolas_ver', 'Visualizar Escolas', 'escolas'],
        ['escolas_cadastrar', 'Cadastrar Escolas', 'escolas'],
        ['escolas_editar', 'Editar Escolas', 'escolas'],
        ['escolas_excluir', 'Excluir Escolas', 'escolas'],
        
        // Planos
        ['planos_ver', 'Visualizar Planos', 'planos'],
        ['planos_cadastrar', 'Cadastrar Planos', 'planos'],
        ['planos_editar', 'Editar Planos', 'planos'],
        ['planos_excluir', 'Excluir Planos', 'planos'],
        
        // Assinaturas
        ['assinaturas_ver', 'Visualizar Assinaturas', 'assinaturas'],
        ['assinaturas_renovar', 'Renovar Assinaturas', 'assinaturas'],
        ['assinaturas_cancelar', 'Cancelar Assinaturas', 'assinaturas'],
        
        // Pagamentos
        ['pagamentos_ver', 'Visualizar Pagamentos', 'pagamentos'],
        ['pagamentos_registrar', 'Registrar Pagamentos', 'pagamentos'],
        ['pagamentos_editar', 'Editar Pagamentos', 'pagamentos'],
        ['pagamentos_excluir', 'Excluir Pagamentos', 'pagamentos'],
        
        // Comunicação
        ['comunicacao_ver', 'Visualizar Comunicação', 'comunicacao'],
        ['comunicacao_enviar', 'Enviar Comunicados', 'comunicacao'],
        ['tickets_ver', 'Visualizar Tickets', 'tickets'],
        ['tickets_responder', 'Responder Tickets', 'tickets'],
        ['tickets_fechar', 'Fechar Tickets', 'tickets'],
        
        // Relatórios
        ['relatorios_ver', 'Visualizar Relatórios', 'relatorios'],
        ['relatorios_exportar', 'Exportar Relatórios', 'relatorios'],
        
        // Configurações
        ['config_ver', 'Visualizar Configurações', 'config'],
        ['config_editar', 'Editar Configurações', 'config'],
        ['permissoes_ver', 'Visualizar Permissões', 'config'],
        ['permissoes_editar', 'Editar Permissões', 'config'],
        
        // Usuários
        ['usuarios_ver', 'Visualizar Usuários', 'usuarios'],
        ['usuarios_cadastrar', 'Cadastrar Usuários', 'usuarios'],
        ['usuarios_editar', 'Editar Usuários', 'usuarios'],
        ['usuarios_excluir', 'Excluir Usuários', 'usuarios'],
        
        // Backup
        ['backup_ver', 'Visualizar Backups', 'backup'],
        ['backup_criar', 'Criar Backups', 'backup'],
        ['backup_restaurar', 'Restaurar Backups', 'backup'],
        ['backup_excluir', 'Excluir Backups', 'backup']
    ];
    
    foreach ($permissoes_padrao as $permissao) {
        $stmt = $conn->prepare("INSERT INTO permissoes (nome, descricao, modulo) VALUES (:nome, :descricao, :modulo)");
        $stmt->execute([':nome' => $permissao[0], ':descricao' => $permissao[1], ':modulo' => $permissao[2]]);
    }
    
    // Inserir papéis padrão
    $papeis_padrao = [
        ['Super Administrador', 'Acesso total ao sistema', 'super_admin'],
        ['Administrador Escola', 'Gerencia todas as funcionalidades da escola', 'admin_escola'],
        ['Diretor', 'Gerencia a escola e visualiza relatórios', 'diretor'],
        ['Professor', 'Lança notas e faz chamada', 'professor'],
        ['Secretaria', 'Gerencia matrículas e documentos', 'secretaria'],
        ['Aluno', 'Visualiza notas e frequência', 'aluno'],
        ['Pai/Encarregado', 'Acompanha o desempenho do aluno', 'pai']
    ];
    
    foreach ($papeis_padrao as $papel) {
        $stmt = $conn->prepare("INSERT INTO papeis (nome, descricao, tipo) VALUES (:nome, :descricao, :tipo)");
        $stmt->execute([':nome' => $papel[0], ':descricao' => $papel[1], ':tipo' => $papel[2]]);
    }
}

// Buscar todos os papéis
$papeis = $conn->query("SELECT * FROM papeis ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Buscar todas as permissões
$permissoes = $conn->query("SELECT * FROM permissoes ORDER BY modulo, nome")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar permissões por módulo
$permissoes_por_modulo = [];
foreach ($permissoes as $permissao) {
    $permissoes_por_modulo[$permissao['modulo']][] = $permissao;
}

$error = '';
$success = '';

// Processar atualização de permissões
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_permissoes'])) {
    $papel_id = $_POST['papel_id'] ?? 0;
    $permissoes_selecionadas = $_POST['permissoes'] ?? [];
    
    try {
        // Remover permissões existentes
        $stmt = $conn->prepare("DELETE FROM papel_permissoes WHERE papel_id = :papel_id");
        $stmt->execute([':papel_id' => $papel_id]);
        
        // Inserir novas permissões
        foreach ($permissoes_selecionadas as $permissao_id) {
            $stmt = $conn->prepare("INSERT INTO papel_permissoes (papel_id, permissao_id) VALUES (:papel_id, :permissao_id)");
            $stmt->execute([':papel_id' => $papel_id, ':permissao_id' => $permissao_id]);
        }
        
        $success = "Permissões atualizadas com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, ip, created_at)
            VALUES (:usuario_id, 'atualizar_permissoes', 'papel_permissoes', :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Buscar permissões atuais do papel selecionado
$papel_selecionado = $_GET['papel'] ?? ($papeis[0]['id'] ?? 0);
$permissoes_atuais = [];
if ($papel_selecionado) {
    $stmt = $conn->prepare("SELECT permissao_id FROM papel_permissoes WHERE papel_id = :papel_id");
    $stmt->execute([':papel_id' => $papel_selecionado]);
    $permissoes_atuais = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permissões | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .perm-group { margin-bottom: 30px; }
        .perm-group h5 { background: #f8f9fa; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #006B3E; }
        .form-check { margin-bottom: 8px; }
        .select-all { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px dashed #ddd; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><i class="fas fa-chalkboard-user"></i></div>
            <h3>SIGE Angola</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="../dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="../escolas/" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="../planos/" class="nav-link"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="../assinaturas/" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="../pagamentos/" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="../comunicacao/" class="nav-link"><i class="fas fa-headset"></i> Comunicação</a></li>
            <li class="nav-item"><a href="../relatorios/" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios</a></li>
            <li class="nav-item"><a href="sistema.php" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            <li class="nav-item"><a href="permissoes.php" class="nav-link active"><i class="fas fa-lock"></i> Permissões</a></li>
            <li class="nav-item"><a href="../../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-lock"></i> Gestão de Permissões</h2>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-users"></i> Papéis e Permissões</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="mb-4">
                    <div class="row">
                        <div class="col-md-4">
                            <label>Selecionar Papel</label>
                            <select name="papel" class="form-control" onchange="this.form.submit()">
                                <?php foreach ($papeis as $papel): ?>
                                <option value="<?php echo $papel['id']; ?>" <?php echo $papel_selecionado == $papel['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($papel['nome']); ?> (<?php echo ucfirst(str_replace('_', ' ', $papel['tipo'])); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label>&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">Carregar Permissões</button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <?php if ($papel_selecionado): ?>
                <form method="POST">
                    <input type="hidden" name="papel_id" value="<?php echo $papel_selecionado; ?>">
                    
                    <div class="select-all">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="selecionar_todos" onclick="toggleAll(this.checked)">
                            <label class="form-check-label fw-bold">Selecionar Todas as Permissões</label>
                        </div>
                    </div>
                    
                    <?php foreach ($permissoes_por_modulo as $modulo => $modulo_permissoes): ?>
                    <div class="perm-group">
                        <h5>
                            <i class="fas fa-folder-open"></i> 
                            <?php echo ucfirst($modulo); ?>
                            <small class="text-muted float-end">
                                <a href="#" onclick="selectModule('<?php echo $modulo; ?>', true); return false;">Selecionar</a> | 
                                <a href="#" onclick="selectModule('<?php echo $modulo; ?>', false); return false;">Desmarcar</a>
                            </small>
                        </h5>
                        <div class="row">
                            <?php foreach ($modulo_permissoes as $permissao): ?>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="checkbox" name="permissoes[]" value="<?php echo $permissao['id']; ?>" 
                                           class="form-check-input permissao-checkbox modulo-<?php echo $modulo; ?>"
                                           id="perm_<?php echo $permissao['id']; ?>"
                                           <?php echo in_array($permissao['id'], $permissoes_atuais) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="perm_<?php echo $permissao['id']; ?>">
                                        <?php echo htmlspecialchars($permissao['descricao']); ?>
                                        <br>
                                        <small class="text-muted"><?php echo $permissao['nome']; ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center mt-4">
                        <button type="submit" name="salvar_permissoes" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Salvar Permissões
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-info-circle"></i> Sobre Permissões</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle text-success"></i> Como funciona?</h6>
                        <p>As permissões controlam o acesso dos usuários às funcionalidades do sistema. Cada papel pode ter diferentes níveis de acesso.</p>
                        
                        <h6 class="mt-3"><i class="fas fa-tag text-info"></i> Tipos de Papéis</h6>
                        <ul>
                            <li><strong>Super Administrador</strong> - Acesso total a todas as funcionalidades</li>
                            <li><strong>Administrador Escola</strong> - Gerencia todas as funcionalidades da escola</li>
                            <li><strong>Diretor</strong> - Gerencia a escola e visualiza relatórios</li>
                            <li><strong>Professor</strong> - Lança notas e faz chamada</li>
                            <li><strong>Secretaria</strong> - Gerencia matrículas e documentos</li>
                            <li><strong>Aluno</strong> - Visualiza notas e frequência</li>
                            <li><strong>Pai/Encarregado</strong> - Acompanha o desempenho do aluno</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-shield-alt text-warning"></i> Dicas de Segurança</h6>
                        <ul>
                            <li>Conceda apenas as permissões necessárias para cada papel</li>
                            <li>Revise as permissões periodicamente</li>
                            <li>O papel de Super Administrador deve ter acesso total</li>
                            <li>Alunos e Pais devem ter acesso apenas a visualização</li>
                        </ul>
                        
                        <h6 class="mt-3"><i class="fas fa-chart-line text-primary"></i> Módulos Disponíveis</h6>
                        <ul>
                            <li><strong>Dashboard</strong> - Painel de controle e estatísticas</li>
                            <li><strong>Escolas</strong> - Gestão de escolas cadastradas</li>
                            <li><strong>Planos</strong> - Configuração de planos e preços</li>
                            <li><strong>Assinaturas</strong> - Controle de assinaturas</li>
                            <li><strong>Pagamentos</strong> - Gestão financeira</li>
                            <li><strong>Comunicação</strong> - Tickets e notificações</li>
                            <li><strong>Relatórios</strong> - Relatórios e estatísticas</li>
                            <li><strong>Configurações</strong> - Configurações do sistema</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleAll(checked) {
            $('.permissao-checkbox').prop('checked', checked);
        }
        
        function selectModule(module, checked) {
            $('.modulo-' + module).prop('checked', checked);
        }
        
        // Verificar se todas as permissões estão selecionadas
        function updateSelectAll() {
            var total = $('.permissao-checkbox').length;
            var checked = $('.permissao-checkbox:checked').length;
            $('#selecionar_todos').prop('checked', total === checked);
        }
        
        $('.permissao-checkbox').on('change', updateSelectAll);
    </script>
</body>
</html>