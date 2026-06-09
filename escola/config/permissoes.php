<?php
// escola/config/permissoes.php - Sistema de Gestão de Permissões
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Verificar se o usuário tem permissão de administrador
$tipos_permitidos = ['super_admin', 'admin_escola', 'administrador', 'diretor'];
if (!in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// TABELAS DE PERMISSÕES
// ============================================

// Tabela de módulos
$check = $conn->query("SHOW TABLES LIKE 'modulos_sistema'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE modulos_sistema (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            icone VARCHAR(50),
            ordem INT DEFAULT 0,
            ativo TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Inserir módulos padrão
    $modulos = [
        ['nome' => 'Dashboard', 'descricao' => 'Painel principal do sistema', 'icone' => 'tachometer-alt', 'ordem' => 1],
        ['nome' => 'Académico', 'descricao' => 'Gestão de alunos, turmas, disciplinas', 'icone' => 'graduation-cap', 'ordem' => 2],
        ['nome' => 'Notas e Avaliações', 'descricao' => 'Lançamento e consulta de notas', 'icone' => 'edit', 'ordem' => 3],
        ['nome' => 'Frequência', 'descricao' => 'Registro de chamada e presenças', 'icone' => 'calendar-check', 'ordem' => 4],
        ['nome' => 'Biblioteca', 'descricao' => 'Gestão de acervo e empréstimos', 'icone' => 'book', 'ordem' => 5],
        ['nome' => 'Financeiro', 'descricao' => 'Mensalidades, recibos, contas', 'icone' => 'coins', 'ordem' => 6],
        ['nome' => 'Recursos Humanos', 'descricao' => 'Gestão de funcionários', 'icone' => 'users', 'ordem' => 7],
        ['nome' => 'Secretaria', 'descricao' => 'Matrículas, documentos, certificados', 'icone' => 'building', 'ordem' => 8],
        ['nome' => 'Comunicação', 'descricao' => 'Comunicados, notificações', 'icone' => 'envelope', 'ordem' => 9],
        ['nome' => 'Relatórios', 'descricao' => 'Geração de relatórios', 'icone' => 'chart-line', 'ordem' => 10],
        ['nome' => 'Configurações', 'descricao' => 'Configurações do sistema', 'icone' => 'cogs', 'ordem' => 11]
    ];
    
    foreach ($modulos as $modulo) {
        $conn->prepare("INSERT INTO modulos_sistema (nome, descricao, icone, ordem) VALUES (:nome, :descricao, :icone, :ordem)")
            ->execute($modulo);
    }
}

// Tabela de permissões por tipo de usuário
$check = $conn->query("SHOW TABLES LIKE 'permissoes_padrao'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE permissoes_padrao (
            id INT PRIMARY KEY AUTO_INCREMENT,
            tipo_usuario VARCHAR(50) NOT NULL,
            modulo_id INT NOT NULL,
            permissao VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (modulo_id) REFERENCES modulos_sistema(id) ON DELETE CASCADE,
            UNIQUE KEY unique_permissao (tipo_usuario, modulo_id, permissao)
        )
    ");
}

// Tabela de permissões específicas por usuário
$check = $conn->query("SHOW TABLES LIKE 'permissoes_usuario'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE permissoes_usuario (
            id INT PRIMARY KEY AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            modulo_id INT NOT NULL,
            permissao VARCHAR(50) NOT NULL,
            concedido TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (modulo_id) REFERENCES modulos_sistema(id) ON DELETE CASCADE,
            UNIQUE KEY unique_usuario_permissao (usuario_id, modulo_id, permissao)
        )
    ");
}

// Tabela de cargos/funções
$check = $conn->query("SHOW TABLES LIKE 'cargos_sistema'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE cargos_sistema (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            nivel INT DEFAULT 1,
            ativo TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
    
    // Inserir cargos padrão
    $cargos = [
        ['nome' => 'Diretor Geral', 'descricao' => 'Acesso total ao sistema', 'nivel' => 10],
        ['nome' => 'Coordenador Pedagógico', 'descricao' => 'Gestão pedagógica completa', 'nivel' => 8],
        ['nome' => 'Secretário', 'descricao' => 'Gestão administrativa', 'nivel' => 7],
        ['nome' => 'Financeiro', 'descricao' => 'Gestão financeira', 'nivel' => 7],
        ['nome' => 'Professor', 'descricao' => 'Acesso restrito a turmas', 'nivel' => 5],
        ['nome' => 'Bibliotecário', 'descricao' => 'Gestão da biblioteca', 'nivel' => 5],
        ['nome' => 'Encarregado', 'descricao' => 'Acompanhamento do aluno', 'nivel' => 3],
        ['nome' => 'Aluno', 'descricao' => 'Acesso restrito', 'nivel' => 2]
    ];
    
    foreach ($cargos as $cargo) {
        $conn->prepare("INSERT INTO cargos_sistema (escola_id, nome, descricao, nivel) VALUES (:escola_id, :nome, :descricao, :nivel)")
            ->execute([':escola_id' => $escola_id, ':nome' => $cargo['nome'], ':descricao' => $cargo['descricao'], ':nivel' => $cargo['nivel']]);
    }
}

// ============================================
// TIPOS DE PERMISSÕES POR MÓDULO
// ============================================
$tipos_permissoes = [
    'visualizar' => 'Visualizar',
    'criar' => 'Criar',
    'editar' => 'Editar',
    'excluir' => 'Excluir',
    'exportar' => 'Exportar',
    'imprimir' => 'Imprimir'
];

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Salvar permissões em massa para um tipo de usuário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_permissoes_tipo'])) {
    $tipo_usuario = $_POST['tipo_usuario'];
    $permissoes = $_POST['permissoes'] ?? [];
    
    // Limpar permissões antigas
    $conn->prepare("DELETE FROM permissoes_padrao WHERE tipo_usuario = :tipo_usuario")->execute([':tipo_usuario' => $tipo_usuario]);
    
    // Inserir novas permissões
    foreach ($permissoes as $modulo_id => $perms) {
        foreach ($perms as $permissao) {
            $conn->prepare("INSERT INTO permissoes_padrao (tipo_usuario, modulo_id, permissao) VALUES (:tipo, :modulo, :permissao)")
                ->execute([':tipo' => $tipo_usuario, ':modulo' => $modulo_id, ':permissao' => $permissao]);
        }
    }
    
    $msg_sucesso = "Permissões salvas com sucesso para " . ucfirst($tipo_usuario);
    header("Location: permissoes.php?tab=tipo&msg=" . urlencode($msg_sucesso));
    exit;
}

// Salvar permissões para um usuário específico
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_permissoes_usuario'])) {
    $usuario_id = (int)$_POST['usuario_id'];
    $permissoes = $_POST['permissoes'] ?? [];
    
    // Limpar permissões antigas do usuário
    $conn->prepare("DELETE FROM permissoes_usuario WHERE usuario_id = :usuario_id")->execute([':usuario_id' => $usuario_id]);
    
    // Inserir novas permissões
    foreach ($permissoes as $modulo_id => $perms) {
        foreach ($perms as $permissao) {
            $conn->prepare("INSERT INTO permissoes_usuario (usuario_id, modulo_id, permissao, concedido) VALUES (:usuario, :modulo, :permissao, 1)")
                ->execute([':usuario' => $usuario_id, ':modulo' => $modulo_id, ':permissao' => $permissao]);
        }
    }
    
    $msg_sucesso = "Permissões do usuário atualizadas com sucesso!";
    header("Location: permissoes.php?tab=usuario&usuario_id=" . $usuario_id . "&msg=" . urlencode($msg_sucesso));
    exit;
}

// Remover permissão específica
if (isset($_GET['remover_permissao']) && isset($_GET['id']) && isset($_GET['tipo'])) {
    $id = (int)$_GET['id'];
    if ($_GET['tipo'] == 'usuario') {
        $conn->prepare("DELETE FROM permissoes_usuario WHERE id = :id")->execute([':id' => $id]);
    } else {
        $conn->prepare("DELETE FROM permissoes_padrao WHERE id = :id")->execute([':id' => $id]);
    }
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar módulos
$modulos = $conn->query("SELECT * FROM modulos_sistema WHERE ativo = 1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);

// Buscar tipos de usuário disponíveis
$tipos_usuarios = ['admin', 'diretor', 'coordenador', 'secretario', 'professor', 'aluno', 'encarregado', 'financeiro', 'bibliotecario'];

// Buscar permissões padrão por tipo
$permissoes_padrao = [];
foreach ($tipos_usuarios as $tipo) {
    $stmt = $conn->prepare("SELECT * FROM permissoes_padrao WHERE tipo_usuario = :tipo");
    $stmt->execute([':tipo' => $tipo]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $permissoes_padrao[$tipo] = [];
    foreach ($perms as $p) {
        $permissoes_padrao[$tipo][$p['modulo_id']][] = $p['permissao'];
    }
}

// Buscar usuários
$usuarios = $conn->prepare("
    SELECT u.id, u.nome, u.email, u.tipo, u.status, 
           c.nome as cargo_nome
    FROM usuarios u
    LEFT JOIN cargos_sistema c ON c.id = u.id
    WHERE u.escola_id = :escola_id
    ORDER BY u.nome
");
$usuarios->execute([':escola_id' => $escola_id]);
$usuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);

// Buscar permissões específicas por usuário
$permissoes_usuario = [];
if (isset($_GET['usuario_id'])) {
    $usuario_sel_id = (int)$_GET['usuario_id'];
    $stmt = $conn->prepare("SELECT * FROM permissoes_usuario WHERE usuario_id = :usuario_id");
    $stmt->execute([':usuario_id' => $usuario_sel_id]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($perms as $p) {
        $permissoes_usuario[$p['modulo_id']][] = $p['permissao'];
    }
}

// Buscar cargos
$cargos = $conn->prepare("SELECT * FROM cargos_sistema WHERE escola_id = :escola_id AND ativo = 1 ORDER BY nivel DESC");
$cargos->execute([':escola_id' => $escola_id]);
$cargos = $cargos->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET['msg'] ?? '';
$erro = $_GET['erro'] ?? '';
$tab = $_GET['tab'] ?? 'tipo';
$usuario_sel_id = $_GET['usuario_id'] ?? 0;

// Incluir menu
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Permissões | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        
        .nav-tabs .nav-link {
            color: #006B3E;
            border: none;
            padding: 10px 20px;
        }
        
        .nav-tabs .nav-link.active {
            background: #006B3E;
            color: white;
            border-radius: 25px;
        }
        
        .modulo-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        
        .modulo-card:hover {
            background: #f8f9fa;
            border-color: #006B3E;
        }
        
        .modulo-nome {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
        }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .table-permissoes th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        
        .table-permissoes td {
            vertical-align: middle;
            text-align: center;
        }
        
        .permissao-check {
            transform: scale(1.2);
            cursor: pointer;
        }
        
        .select-all {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .badge-permissao {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
        }
        
        .usuario-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .usuario-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .usuario-item.active {
            background: #e8f5e9;
            border-left-color: #28a745;
        }
        
        .btn-massa {
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
        }
        
        .btn-massa:hover {
            background: #138496;
        }
    </style>
</head>
<body>
    
<?php include __DIR__ . '/../menu_escola.php';?>
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-lock"></i> Gestão de Permissões</h2>
            <div>
                <button class="btn btn-info btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda
                </button>
            </div>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="permissoesTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tab == 'tipo' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#porTipo">
                            <i class="fas fa-users"></i> Por Tipo de Usuário
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tab == 'usuario' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#porUsuario">
                            <i class="fas fa-user"></i> Por Usuário Específico
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cargos">
                            <i class="fas fa-briefcase"></i> Cargos e Funções
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#emMassa">
                            <i class="fas fa-layer-group"></i> Configuração em Massa
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    
                    <!-- Tab: Por Tipo de Usuário -->
                    <div class="tab-pane fade <?php echo $tab == 'tipo' ? 'show active' : ''; ?>" id="porTipo">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Selecione o Tipo de Usuário</label>
                                    <select name="tipo_usuario" id="tipo_usuario_select" class="form-select" onchange="carregarPermissoesTipo(this.value)">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($tipos_usuarios as $tipo): ?>
                                        <option value="<?php echo $tipo; ?>"><?php echo ucfirst($tipo); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div id="permissoes_tipo_container">
                                <div class="alert alert-info">Selecione um tipo de usuário para configurar as permissões.</div>
                            </div>
                            
                            <div class="text-center mt-3" id="btn_salvar_tipo" style="display: none;">
                                <button type="submit" name="salvar_permissoes_tipo" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Salvar Permissões
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab: Por Usuário Específico -->
                    <div class="tab-pane fade <?php echo $tab == 'usuario' ? 'show active' : ''; ?>" id="porUsuario">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Usuários do Sistema</label>
                                <div class="list-group" style="max-height: 500px; overflow-y: auto;">
                                    <?php foreach ($usuarios as $usuario): ?>
                                    <a href="?tab=usuario&usuario_id=<?php echo $usuario['id']; ?>" class="list-group-item list-group-item-action <?php echo ($usuario_sel_id == $usuario['id']) ? 'active' : ''; ?>">
                                        <div>
                                            <strong><?php echo htmlspecialchars($usuario['nome']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo $usuario['email']; ?> | <?php echo ucfirst($usuario['tipo']); ?></small>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <?php if ($usuario_sel_id > 0): ?>
                                <?php
                                    $usuario_sel = array_filter($usuarios, function($u) use ($usuario_sel_id) { return $u['id'] == $usuario_sel_id; });
                                    $usuario_sel = reset($usuario_sel);
                                ?>
                                <div class="alert alert-info mb-3">
                                    <strong><i class="fas fa-user"></i> <?php echo htmlspecialchars($usuario_sel['nome']); ?></strong><br>
                                    <small>Email: <?php echo $usuario_sel['email']; ?> | Tipo: <?php echo ucfirst($usuario_sel['tipo']); ?> | Status: <?php echo $usuario_sel['status']; ?></small>
                                </div>
                                
                                <form method="POST">
                                    <input type="hidden" name="usuario_id" value="<?php echo $usuario_sel_id; ?>">
                                    
                                    <div class="mb-3">
                                        <div class="select-all">
                                            <input type="checkbox" id="selectAllUsuario" onclick="selecionarTodasUsuario(this.checked)">
                                            <label for="selectAllUsuario">Selecionar Todas as Permissões</label>
                                        </div>
                                    </div>
                                    
                                    <div id="permissoes_usuario_container">
                                        <?php foreach ($modulos as $modulo): ?>
                                        <div class="modulo-card">
                                            <div class="modulo-nome">
                                                <i class="fas fa-<?php echo $modulo['icone']; ?>"></i> <?php echo htmlspecialchars($modulo['nome']); ?>
                                            </div>
                                            <div class="checkbox-group">
                                                <?php foreach ($tipos_permissoes as $key => $label): ?>
                                                <div class="checkbox-item">
                                                    <input type="checkbox" name="permissoes[<?php echo $modulo['id']; ?>][]" 
                                                           value="<?php echo $key; ?>" 
                                                           id="usuario_mod_<?php echo $modulo['id']; ?>_<?php echo $key; ?>"
                                                           <?php echo (isset($permissoes_usuario[$modulo['id']]) && in_array($key, $permissoes_usuario[$modulo['id']])) ? 'checked' : ''; ?>
                                                           class="permissao-check usuario-permissao">
                                                    <label for="usuario_mod_<?php echo $modulo['id']; ?>_<?php echo $key; ?>"><?php echo $label; ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <button type="submit" name="salvar_permissoes_usuario" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Salvar Permissões do Usuário
                                        </button>
                                    </div>
                                </form>
                                <?php else: ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle"></i> Selecione um usuário na lista ao lado para configurar permissões específicas.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab: Cargos e Funções -->
                    <div class="tab-pane fade" id="cargos">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Cargo</th>
                                        <th>Descrição</th>
                                        <th>Nível</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cargos as $cargo): ?>
                                    <tr>
                                        <td><?php echo $cargo['id']; ?></div>
                                        <td><strong><?php echo htmlspecialchars($cargo['nome']); ?></strong></div>
                                        <td><?php echo htmlspecialchars($cargo['descricao']); ?></div>
                                        <td><span class="badge bg-primary">Nível <?php echo $cargo['nivel']; ?></span></div>
                                        <td><?php echo $cargo['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-danger">Inativo</span>'; ?></div>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="editarCargo(<?php echo $cargo['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="excluirCargo(<?php echo $cargo['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                         </div>
                                     </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <button class="btn btn-success" onclick="novoCargo()">
                                <i class="fas fa-plus"></i> Novo Cargo
                            </button>
                        </div>
                    </div>
                    
                    <!-- Tab: Configuração em Massa -->
                    <div class="tab-pane fade" id="emMassa">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atenção:</strong> Esta funcionalidade permite aplicar permissões em massa para múltiplos usuários.
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <i class="fas fa-copy"></i> Copiar Permissões
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Usuário Origem</label>
                                            <select id="origem_usuario" class="form-select">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($usuarios as $usuario): ?>
                                                <option value="<?php echo $usuario['id']; ?>"><?php echo htmlspecialchars($usuario['nome']); ?> (<?php echo $usuario['tipo']; ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Usuários Destino (selecione vários)</label>
                                            <select id="destino_usuarios" class="form-select" multiple size="5">
                                                <?php foreach ($usuarios as $usuario): ?>
                                                <option value="<?php echo $usuario['id']; ?>"><?php echo htmlspecialchars($usuario['nome']); ?> (<?php echo $usuario['tipo']; ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button class="btn btn-primary w-100" onclick="copiarPermissoes()">
                                            <i class="fas fa-copy"></i> Copiar Permissões
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <i class="fas fa-layer-group"></i> Aplicar por Tipo
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Tipo de Usuário</label>
                                            <select id="tipo_massa" class="form-select">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($tipos_usuarios as $tipo): ?>
                                                <option value="<?php echo $tipo; ?>"><?php echo ucfirst($tipo); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Selecione os Usuários (opcional - deixe vazio para todos do tipo)</label>
                                            <select id="usuarios_massa" class="form-select" multiple size="5">
                                                <?php foreach ($usuarios as $usuario): ?>
                                                <option value="<?php echo $usuario['id']; ?>"><?php echo htmlspecialchars($usuario['nome']); ?> (<?php echo $usuario['tipo']; ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button class="btn btn-success w-100" onclick="aplicarPermissoesTipo()">
                                            <i class="fas fa-check-circle"></i> Aplicar Permissões Padrão
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Gestão de Permissões - Ajuda</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6><i class="fas fa-info-circle"></i> Como Funciona?</h6>
                    <p>O sistema de permissões permite controlar o acesso dos usuários aos módulos e funcionalidades do sistema.</p>
                    
                    <h6 class="mt-3"><i class="fas fa-tag"></i> Tipos de Permissão:</h6>
                    <ul>
                        <li><strong>Visualizar</strong> - Permite ver o conteúdo do módulo</li>
                        <li><strong>Criar</strong> - Permite adicionar novos registros</li>
                        <li><strong>Editar</strong> - Permite modificar registros existentes</li>
                        <li><strong>Excluir</strong> - Permite remover registros</li>
                        <li><strong>Exportar</strong> - Permite exportar dados</li>
                        <li><strong>Imprimir</strong> - Permite imprimir relatórios</li>
                    </ul>
                    
                    <h6 class="mt-3"><i class="fas fa-layer-group"></i> Formas de Configurar:</h6>
                    <ol>
                        <li><strong>Por Tipo de Usuário</strong> - Define permissões padrão para todos os usuários daquele tipo</li>
                        <li><strong>Por Usuário Específico</strong> - Permite personalizar permissões individualmente</li>
                        <li><strong>Cargos e Funções</strong> - Gerencia cargos com níveis de acesso</li>
                        <li><strong>Configuração em Massa</strong> - Copia permissões entre usuários</li>
                    </ol>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Carregar permissões por tipo de usuário
        function carregarPermissoesTipo(tipo) {
            if (!tipo) {
                $('#permissoes_tipo_container').html('<div class="alert alert-info">Selecione um tipo de usuário para configurar as permissões.</div>');
                $('#btn_salvar_tipo').hide();
                return;
            }
            
            $.ajax({
                url: 'ajax_permissoes.php',
                method: 'POST',
                data: { acao: 'carregar_permissoes_tipo', tipo: tipo },
                dataType: 'json',
                success: function(response) {
                    let html = '';
                    for (let modulo of response.modulos) {
                        let permissoes = response.permissoes[modulo.id] || [];
                        html += `
                            <div class="modulo-card">
                                <div class="modulo-nome">
                                    <i class="fas fa-${modulo.icone}"></i> ${modulo.nome}
                                </div>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="checkbox" class="select-modulo" data-modulo="${modulo.id}" onclick="selecionarModulo(${modulo.id}, this.checked)">
                                        <label>Selecionar Todas</label>
                                    </div>
                        `;
                        for (let [key, label] of Object.entries(response.tipos_permissoes)) {
                            let checked = permissoes.includes(key) ? 'checked' : '';
                            html += `
                                <div class="checkbox-item">
                                    <input type="checkbox" name="permissoes[${modulo.id}][]" value="${key}" id="tipo_mod_${modulo.id}_${key}" ${checked} class="permissao-check tipo-permissao" data-modulo="${modulo.id}">
                                    <label for="tipo_mod_${modulo.id}_${key}">${label}</label>
                                </div>
                            `;
                        }
                        html += `</div></div>`;
                    }
                    $('#permissoes_tipo_container').html(html);
                    $('#btn_salvar_tipo').show();
                }
            });
        }
        
        function selecionarModulo(moduloId, checked) {
            $(`input[name="permissoes[${moduloId}][]"]`).prop('checked', checked);
        }
        
        function selecionarTodasUsuario(checked) {
            $('.usuario-permissao').prop('checked', checked);
        }
        
        // Funções para cargos
        function novoCargo() {
            Swal.fire({
                title: 'Novo Cargo',
                html: `
                    <input id="cargo_nome" class="swal2-input" placeholder="Nome do Cargo">
                    <textarea id="cargo_descricao" class="swal2-textarea" placeholder="Descrição"></textarea>
                    <input id="cargo_nivel" type="number" class="swal2-input" placeholder="Nível (1-10)" min="1" max="10">
                `,
                showCancelButton: true,
                confirmButtonText: 'Salvar',
                preConfirm: () => {
                    return {
                        nome: document.getElementById('cargo_nome').value,
                        descricao: document.getElementById('cargo_descricao').value,
                        nivel: document.getElementById('cargo_nivel').value
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'ajax_permissoes.php',
                        method: 'POST',
                        data: { acao: 'salvar_cargo', nome: result.value.nome, descricao: result.value.descricao, nivel: result.value.nivel },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Sucesso!', 'Cargo criado com sucesso', 'success').then(() => location.reload());
                            } else {
                                Swal.fire('Erro!', response.error, 'error');
                            }
                        }
                    });
                }
            });
        }
        
        function editarCargo(id) {
            Swal.fire('Em desenvolvimento', 'Funcionalidade em breve', 'info');
        }
        
        function excluirCargo(id) {
            Swal.fire({
                title: 'Confirmar exclusão',
                text: 'Tem certeza que deseja excluir este cargo?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sim, excluir'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'ajax_permissoes.php',
                        method: 'POST',
                        data: { acao: 'excluir_cargo', id: id },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Sucesso!', 'Cargo excluído', 'success').then(() => location.reload());
                            } else {
                                Swal.fire('Erro!', response.error, 'error');
                            }
                        }
                    });
                }
            });
        }
        
        function copiarPermissoes() {
            let origem = $('#origem_usuario').val();
            let destinos = $('#destino_usuarios').val();
            
            if (!origem) {
                Swal.fire('Atenção!', 'Selecione um usuário de origem', 'warning');
                return;
            }
            if (!destinos || destinos.length === 0) {
                Swal.fire('Atenção!', 'Selecione pelo menos um usuário de destino', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Confirmar cópia',
                text: `Copiar permissões do usuário origem para ${destinos.length} usuário(s)?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sim, copiar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'ajax_permissoes.php',
                        method: 'POST',
                        data: { acao: 'copiar_permissoes', origem: origem, destinos: destinos },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Sucesso!', response.message, 'success');
                            } else {
                                Swal.fire('Erro!', response.error, 'error');
                            }
                        }
                    });
                }
            });
        }
        
        function aplicarPermissoesTipo() {
            let tipo = $('#tipo_massa').val();
            let usuarios = $('#usuarios_massa').val();
            
            if (!tipo) {
                Swal.fire('Atenção!', 'Selecione um tipo de usuário', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Confirmar aplicação',
                text: `Aplicar permissões padrão do tipo "${tipo}" aos usuários selecionados?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sim, aplicar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'ajax_permissoes.php',
                        method: 'POST',
                        data: { acao: 'aplicar_permissoes_tipo', tipo: tipo, usuarios: usuarios },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Sucesso!', response.message, 'success');
                            } else {
                                Swal.fire('Erro!', response.error, 'error');
                            }
                        }
                    });
                }
            });
        }
        
        // Inicializar se já houver tipo selecionado
        $(document).ready(function() {
            let tipoSelecionado = $('#tipo_usuario_select').val();
            if (tipoSelecionado) {
                carregarPermissoesTipo(tipoSelecionado);
            }
        });
    </script>
</body>
</html>