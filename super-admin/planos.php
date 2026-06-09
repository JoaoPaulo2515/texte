<?php
// super-admin/planos.php - Gestão de Planos/Pacotes
require_once __DIR__ . '/../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'super_admin') {
    header('Location: ../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// ============================================
// CADASTRAR PLANO
// ============================================
if ($action == 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $preco_mensal = str_replace(',', '.', str_replace('.', '', $_POST['preco_mensal'] ?? '0'));
    $preco_anual = str_replace(',', '.', str_replace('.', '', $_POST['preco_anual'] ?? '0'));
    $limite_alunos = $_POST['limite_alunos'] ?? 0;
    $limite_professores = $_POST['limite_professores'] ?? 0;
    $limite_turmas = $_POST['limite_turmas'] ?? 0;
    $modulos_disponiveis = json_encode($_POST['modulos'] ?? []);
    $recursos = json_encode([
        'suporte' => $_POST['suporte'] ?? 'email',
        'armazenamento' => $_POST['armazenamento'] ?? 10,
        'relatorios_basicos' => isset($_POST['relatorios_basicos']),
        'relatorios_avancados' => isset($_POST['relatorios_avancados']),
        'api' => isset($_POST['api']),
        'certificado_digital' => isset($_POST['certificado_digital'])
    ]);
    $status = $_POST['status'] ?? 'ativo';
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO planos (
                nome, descricao, preco_mensal, preco_anual,
                limite_alunos, limite_professores, limite_turmas,
                modulos_disponiveis, recursos, status, created_at
            ) VALUES (
                :nome, :descricao, :preco_mensal, :preco_anual,
                :limite_alunos, :limite_professores, :limite_turmas,
                :modulos, :recursos, :status, NOW()
            )
        ");
        
        $stmt->execute([
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':preco_mensal' => $preco_mensal,
            ':preco_anual' => $preco_anual,
            ':limite_alunos' => $limite_alunos,
            ':limite_professores' => $limite_professores,
            ':limite_turmas' => $limite_turmas,
            ':modulos' => $modulos_disponiveis,
            ':recursos' => $recursos,
            ':status' => $status
        ]);
        
        $message = "Plano cadastrado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'cadastrar_plano', 'planos', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $conn->lastInsertId(),
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        header("Location: ?page=planos&message=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// EDITAR PLANO
// ============================================
if ($action == 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_GET['id'];
    $nome = $_POST['nome'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $preco_mensal = str_replace(',', '.', str_replace('.', '', $_POST['preco_mensal'] ?? '0'));
    $preco_anual = str_replace(',', '.', str_replace('.', '', $_POST['preco_anual'] ?? '0'));
    $limite_alunos = $_POST['limite_alunos'] ?? 0;
    $limite_professores = $_POST['limite_professores'] ?? 0;
    $limite_turmas = $_POST['limite_turmas'] ?? 0;
    $modulos_disponiveis = json_encode($_POST['modulos'] ?? []);
    $recursos = json_encode([
        'suporte' => $_POST['suporte'] ?? 'email',
        'armazenamento' => $_POST['armazenamento'] ?? 10,
        'relatorios_basicos' => isset($_POST['relatorios_basicos']),
        'relatorios_avancados' => isset($_POST['relatorios_avancados']),
        'api' => isset($_POST['api']),
        'certificado_digital' => isset($_POST['certificado_digital'])
    ]);
    $status = $_POST['status'] ?? 'ativo';
    
    try {
        $stmt = $conn->prepare("
            UPDATE planos SET
                nome = :nome,
                descricao = :descricao,
                preco_mensal = :preco_mensal,
                preco_anual = :preco_anual,
                limite_alunos = :limite_alunos,
                limite_professores = :limite_professores,
                limite_turmas = :limite_turmas,
                modulos_disponiveis = :modulos,
                recursos = :recursos,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $id,
            ':nome' => $nome,
            ':descricao' => $descricao,
            ':preco_mensal' => $preco_mensal,
            ':preco_anual' => $preco_anual,
            ':limite_alunos' => $limite_alunos,
            ':limite_professores' => $limite_professores,
            ':limite_turmas' => $limite_turmas,
            ':modulos' => $modulos_disponiveis,
            ':recursos' => $recursos,
            ':status' => $status
        ]);
        
        $message = "Plano atualizado com sucesso!";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'editar_plano', 'planos', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        header("Location: ?page=planos&message=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// EXCLUIR PLANO
// ============================================
if ($action == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    try {
        // Verificar se há escolas usando este plano
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM escolas WHERE plano_id = :id");
        $stmt->execute([':id' => $id]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total > 0) {
            throw new Exception("Não é possível excluir este plano pois existem {$total} escolas utilizando-o.");
        }
        
        $stmt = $conn->prepare("DELETE FROM planos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $message = "Plano excluído com sucesso!";
        
        header("Location: ?page=planos&message=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// LISTAR PLANOS
// ============================================
$planos = $conn->query("SELECT * FROM planos ORDER BY preco_mensal ASC")->fetchAll(PDO::FETCH_ASSOC);

// Buscar plano para edição
$plano_edit = null;
if ($action == 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM planos WHERE id = :id");
    $stmt->execute([':id' => $_GET['id']]);
    $plano_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Módulos disponíveis
$modulos_sistema = [
    'dashboard' => 'Dashboard',
    'alunos' => 'Gestão de Alunos',
    'professores' => 'Gestão de Professores',
    'turmas' => 'Gestão de Turmas',
    'disciplinas' => 'Gestão de Disciplinas',
    'notas' => 'Lançamento de Notas',
    'chamada' => 'Registro de Chamada',
    'biblioteca' => 'Biblioteca Digital',
    'relatorios' => 'Relatórios',
    'financeiro' => 'Módulo Financeiro',
    'comunicados' => 'Comunicados',
    'calendario' => 'Calendário Escolar'
];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planos - Super Admin | SIGE SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .plano-card {
            border: 1px solid #eee;
            border-radius: 15px;
            padding: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .plano-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .plano-preco {
            font-size: 2em;
            font-weight: bold;
            color: #4361ee;
        }
        
        .plano-preco small {
            font-size: 0.5em;
            color: #666;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-ativo { background: #e8f5e9; color: #388e3c; }
        .status-inativo { background: #ffebee; color: #d32f2f; }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1a1a2e;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE SaaS</h3>
            <p>Sistema de Gestão Escolar</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item"><a href="?page=dashboard" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="?page=escolas" class="nav-link"><i class="fas fa-school"></i> Escolas</a></li>
            <li class="nav-item"><a href="?page=planos" class="nav-link active"><i class="fas fa-box"></i> Planos</a></li>
            <li class="nav-item"><a href="?page=assinaturas" class="nav-link"><i class="fas fa-credit-card"></i> Assinaturas</a></li>
            <li class="nav-item"><a href="?page=pagamentos" class="nav-link"><i class="fas fa-money-bill-wave"></i> Pagamentos</a></li>
            <li class="nav-item"><a href="?page=suporte" class="nav-link"><i class="fas fa-headset"></i> Suporte</a></li>
            <li class="nav-item"><a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a></li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2><i class="fas fa-box"></i> Planos e Pacotes</h2>
            </div>
            <div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalPlano">
                    <i class="fas fa-plus"></i> Novo Plano
                </button>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <?php foreach ($planos as $plano): 
                $modulos = json_decode($plano['modulos_disponiveis'], true) ?: [];
                $recursos = json_decode($plano['recursos'], true) ?: [];
            ?>
            <div class="col-md-4 mb-4">
                <div class="plano-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <h4><?php echo htmlspecialchars($plano['nome']); ?></h4>
                        <span class="status-badge status-<?php echo $plano['status']; ?>">
                            <?php echo ucfirst($plano['status']); ?>
                        </span>
                    </div>
                    <p class="text-muted small"><?php echo htmlspecialchars($plano['descricao']); ?></p>
                    
                    <div class="plano-preco">
                        R$ <?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>
                        <small>/mês</small>
                    </div>
                    <div class="text-muted small mb-3">
                        Anual: R$ <?php echo number_format($plano['preco_anual'], 2, ',', '.'); ?>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-2">
                        <i class="fas fa-users text-primary"></i> Até <?php echo $plano['limite_alunos']; ?> alunos<br>
                        <i class="fas fa-chalkboard-user text-primary"></i> Até <?php echo $plano['limite_professores']; ?> professores<br>
                        <i class="fas fa-users-group text-primary"></i> Até <?php echo $plano['limite_turmas']; ?> turmas
                    </div>
                    
                    <hr>
                    
                    <div class="mb-2">
                        <strong>Módulos:</strong>
                        <?php foreach ($modulos as $modulo): ?>
                            <span class="badge bg-info"><?php echo $modulos_sistema[$modulo] ?? $modulo; ?></span>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <strong>Recursos:</strong>
                        <ul class="small mb-0">
                            <li>Suporte: <?php echo ucfirst($recursos['suporte'] ?? 'Email'); ?></li>
                            <li>Armazenamento: <?php echo $recursos['armazenamento'] ?? 10; ?>GB</li>
                            <?php if (!empty($recursos['relatorios_basicos'])): ?>
                            <li>✓ Relatórios Básicos</li>
                            <?php endif; ?>
                            <?php if (!empty($recursos['relatorios_avancados'])): ?>
                            <li>✓ Relatórios Avançados</li>
                            <?php endif; ?>
                            <?php if (!empty($recursos['api'])): ?>
                            <li>✓ API de Integração</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="btn-group w-100">
                        <a href="?page=planos&action=edit&id=<?php echo $plano['id']; ?>" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#modalPlanoEdit<?php echo $plano['id']; ?>">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="?page=planos&action=delete&id=<?php echo $plano['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem certeza que deseja excluir este plano?')">
                            <i class="fas fa-trash"></i> Excluir
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Modal Editar Plano -->
            <div class="modal fade" id="modalPlanoEdit<?php echo $plano['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Editar Plano: <?php echo htmlspecialchars($plano['nome']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Nome do Plano *</label>
                                            <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($plano['nome']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Status</label>
                                            <select name="status" class="form-control">
                                                <option value="ativo" <?php echo $plano['status'] == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                                                <option value="inativo" <?php echo $plano['status'] == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Descrição</label>
                                    <textarea name="descricao" class="form-control" rows="2"><?php echo htmlspecialchars($plano['descricao']); ?></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Preço Mensal (R$)</label>
                                            <input type="text" name="preco_mensal" class="form-control money" value="<?php echo number_format($plano['preco_mensal'], 2, ',', '.'); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label>Preço Anual (R$)</label>
                                            <input type="text" name="preco_anual" class="form-control money" value="<?php echo number_format($plano['preco_anual'], 2, ',', '.'); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Limite de Alunos</label>
                                            <input type="number" name="limite_alunos" class="form-control" value="<?php echo $plano['limite_alunos']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Limite de Professores</label>
                                            <input type="number" name="limite_professores" class="form-control" value="<?php echo $plano['limite_professores']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Limite de Turmas</label>
                                            <input type="number" name="limite_turmas" class="form-control" value="<?php echo $plano['limite_turmas']; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Tipo de Suporte</label>
                                            <select name="suporte" class="form-control">
                                                <option value="email" <?php echo ($recursos['suporte'] ?? '') == 'email' ? 'selected' : ''; ?>>Email</option>
                                                <option value="email_telefone" <?php echo ($recursos['suporte'] ?? '') == 'email_telefone' ? 'selected' : ''; ?>>Email + Telefone</option>
                                                <option value="dedicado" <?php echo ($recursos['suporte'] ?? '') == 'dedicado' ? 'selected' : ''; ?>>Suporte Dedicado</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label>Armazenamento (GB)</label>
                                            <input type="number" name="armazenamento" class="form-control" value="<?php echo $recursos['armazenamento'] ?? 10; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Módulos Disponíveis</label>
                                    <div class="row">
                                        <?php foreach ($modulos_sistema as $key => $modulo): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input type="checkbox" name="modulos[]" value="<?php echo $key; ?>" class="form-check-input"
                                                    <?php echo in_array($key, $modulos) ? 'checked' : ''; ?>>
                                                <label class="form-check-label"><?php echo $modulo; ?></label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label>Recursos Adicionais</label>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="relatorios_basicos" class="form-check-input" <?php echo !empty($recursos['relatorios_basicos']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label">Relatórios Básicos</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="relatorios_avancados" class="form-check-input" <?php echo !empty($recursos['relatorios_avancados']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label">Relatórios Avançados</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="api" class="form-check-input" <?php echo !empty($recursos['api']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label">API de Integração</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="certificado_digital" class="form-check-input" <?php echo !empty($recursos['certificado_digital']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label">Certificado Digital</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Modal Novo Plano -->
    <div class="modal fade" id="modalPlano" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Novo Plano</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label>Nome do Plano *</label>
                                    <input type="text" name="nome" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Status</label>
                                    <select name="status" class="form-control">
                                        <option value="ativo">Ativo</option>
                                        <option value="inativo">Inativo</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Descrição</label>
                            <textarea name="descricao" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Preço Mensal (R$)</label>
                                    <input type="text" name="preco_mensal" class="form-control money" placeholder="0,00" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Preço Anual (R$)</label>
                                    <input type="text" name="preco_anual" class="form-control money" placeholder="0,00" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Limite de Alunos</label>
                                    <input type="number" name="limite_alunos" class="form-control" value="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Limite de Professores</label>
                                    <input type="number" name="limite_professores" class="form-control" value="20">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label>Limite de Turmas</label>
                                    <input type="number" name="limite_turmas" class="form-control" value="10">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Tipo de Suporte</label>
                                    <select name="suporte" class="form-control">
                                        <option value="email">Email</option>
                                        <option value="email_telefone">Email + Telefone</option>
                                        <option value="dedicado">Suporte Dedicado</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Armazenamento (GB)</label>
                                    <input type="number" name="armazenamento" class="form-control" value="10">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Módulos Disponíveis</label>
                            <div class="row">
                                <?php foreach ($modulos_sistema as $key => $modulo): ?>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input type="checkbox" name="modulos[]" value="<?php echo $key; ?>" class="form-check-input">
                                        <label class="form-check-label"><?php echo $modulo; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label>Recursos Adicionais</label>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="relatorios_basicos" class="form-check-input">
                                        <label class="form-check-label">Relatórios Básicos</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="relatorios_avancados" class="form-check-input">
                                        <label class="form-check-label">Relatórios Avançados</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="api" class="form-check-input">
                                        <label class="form-check-label">API de Integração</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="certificado_digital" class="form-check-input">
                                        <label class="form-check-label">Certificado Digital</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Cadastrar Plano</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() {
            $('#sidebar').toggleClass('open');
        });
        
        // Máscara para valores monetários
        $('.money').on('input', function() {
            let value = this.value.replace(/[^0-9]/g, '');
            value = (parseInt(value) / 100).toFixed(2);
            this.value = value.replace('.', ',');
        });
    </script>
</body>
</html>