<?php
// escola/perfil.php - Perfil do Usuário (Professor/Admin)

require_once __DIR__ . '/../config/database.php';
session_start();

// Verificar autenticação
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_email = $_SESSION['usuario_email'] ?? '';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// ============================================
// DETECTAR TIPO DE USUÁRIO
// ============================================
$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');

// ============================================
// BUSCAR DADOS DO USUÁRIO
// ============================================
$usuario = null;
$funcionario = null;
$dados_adicionais = [];

// Buscar dados básicos do usuário
$sql_usuario = "SELECT id, nome, email, tipo, status, data_cadastro FROM usuarios WHERE id = :usuario_id";
$stmt_usuario = $conn->prepare($sql_usuario);
$stmt_usuario->execute([':usuario_id' => $usuario_id]);
$usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

if ($usuario) {
    // Buscar dados do funcionário (se for professor ou admin)
    $sql_funcionario = "SELECT * FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
    $stmt_funcionario = $conn->prepare($sql_funcionario);
    $stmt_funcionario->execute([
        ':usuario_id' => $usuario_id,
        ':escola_id' => $escola_id
    ]);
    $funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
    
    // Buscar dados da escola
    $sql_escola = "SELECT nome, logotipo, endereco, telefone, email FROM escolas WHERE id = :escola_id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':escola_id' => $escola_id]);
    $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// PROCESSAR ATUALIZAÇÃO DE PERFIL
// ============================================
$mensagem_sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['atualizar_perfil'])) {
        $nome = trim($_POST['nome']);
        $email = trim($_POST['email']);
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        
        // Validar dados
        if (empty($nome) || empty($email)) {
            $erro = "Nome e email são obrigatórios!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = "Email inválido!";
        } else {
            try {
                // Atualizar usuário
                $sql_update_usuario = "UPDATE usuarios SET nome = :nome, email = :email WHERE id = :usuario_id";
                $stmt_update_usuario = $conn->prepare($sql_update_usuario);
                $stmt_update_usuario->execute([
                    ':nome' => $nome,
                    ':email' => $email,
                    ':usuario_id' => $usuario_id
                ]);
                
                // Atualizar funcionário se existir
                if ($funcionario) {
                    $sql_update_funcionario = "UPDATE funcionarios SET telefone = :telefone, endereco = :endereco WHERE id = :id";
                    $stmt_update_funcionario = $conn->prepare($sql_update_funcionario);
                    $stmt_update_funcionario->execute([
                        ':telefone' => $telefone,
                        ':endereco' => $endereco,
                        ':id' => $funcionario['id']
                    ]);
                }
                
                // Atualizar sessão
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_email'] = $email;
                
                $mensagem_sucesso = "Perfil atualizado com sucesso!";
                
                // Recarregar dados
                $usuario['nome'] = $nome;
                $usuario['email'] = $email;
                if ($funcionario) {
                    $funcionario['telefone'] = $telefone;
                    $funcionario['endereco'] = $endereco;
                }
                
            } catch (Exception $e) {
                $erro = "Erro ao atualizar perfil: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['alterar_senha'])) {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        
        if (empty($senha_atual) || empty($nova_senha) || empty($confirmar_senha)) {
            $erro = "Todos os campos de senha são obrigatórios!";
        } elseif ($nova_senha !== $confirmar_senha) {
            $erro = "Nova senha e confirmação não coincidem!";
        } elseif (strlen($nova_senha) < 6) {
            $erro = "A nova senha deve ter no mínimo 6 caracteres!";
        } else {
            try {
                // Verificar senha atual
                $sql_check = "SELECT senha FROM usuarios WHERE id = :usuario_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([':usuario_id' => $usuario_id]);
                $user_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($user_data && password_verify($senha_atual, $user_data['senha'])) {
                    // Atualizar senha
                    $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                    $sql_update_senha = "UPDATE usuarios SET senha = :senha WHERE id = :usuario_id";
                    $stmt_update_senha = $conn->prepare($sql_update_senha);
                    $stmt_update_senha->execute([
                        ':senha' => $nova_senha_hash,
                        ':usuario_id' => $usuario_id
                    ]);
                    
                    $mensagem_sucesso = "Senha alterada com sucesso!";
                } else {
                    $erro = "Senha atual incorreta!";
                }
            } catch (Exception $e) {
                $erro = "Erro ao alterar senha: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .profile-avatar { width: 150px; height: 150px; border-radius: 50%; background: #006B3E; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .profile-avatar i { font-size: 60px; color: white; }
        .tipo-usuario { font-size: 12px; padding: 4px 12px; border-radius: 20px; }
        .tipo-professor { background: #17a2b8; color: white; }
        .tipo-admin { background: #28a745; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .info-label { font-weight: 600; color: #555; font-size: 0.85rem; margin-bottom: 5px; }
        .info-value { font-size: 1rem; margin-bottom: 15px; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-user-circle"></i> Meu Perfil</h2>
                <?php if ($is_professor): ?>
                    <span class="tipo-usuario tipo-professor"><i class="fas fa-chalkboard-user"></i> Professor</span>
                <?php else: ?>
                    <span class="tipo-usuario tipo-admin"><i class="fas fa-user-shield"></i> Administrador</span>
                <?php endif; ?>
            </div>
            <div>
                <span><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?></span>
                <span class="ms-3"><i class="fas fa-user"></i> <?php echo htmlspecialchars($usuario_nome); ?></span>
            </div>
        </div>
        
        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Coluna da Foto/Avatar -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="profile-avatar">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h4><?php echo htmlspecialchars($usuario['nome'] ?? $usuario_nome); ?></h4>
                        <p class="text-muted">
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($usuario['email'] ?? $usuario_email); ?>
                        </p>
                        <hr>
                        <div class="text-start">
                            <div class="info-label">
                                <i class="fas fa-building"></i> Escola
                            </div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($escola['nome'] ?? 'Carregando...'); ?>
                            </div>
                            
                            <div class="info-label">
                                <i class="fas fa-calendar-alt"></i> Membro desde
                            </div>
                            <div class="info-value">
                                <?php echo isset($usuario['data_cadastro']) ? date('d/m/Y', strtotime($usuario['data_cadastro'])) : 'Data não disponível'; ?>
                            </div>
                            
                            <div class="info-label">
                                <i class="fas fa-id-card"></i> Status
                            </div>
                            <div class="info-value">
                                <?php if (isset($usuario['status']) && $usuario['status'] == 'ativo'): ?>
                                    <span class="badge bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inativo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna de Edição -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-tabs card-header-tabs" id="perfilTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="dados-tab" data-bs-toggle="tab" data-bs-target="#dados" type="button" role="tab">
                                    <i class="fas fa-user-edit"></i> Dados Pessoais
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="senha-tab" data-bs-toggle="tab" data-bs-target="#senha" type="button" role="tab">
                                    <i class="fas fa-lock"></i> Alterar Senha
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Tab Dados Pessoais -->
                            <div class="tab-pane fade show active" id="dados" role="tabpanel">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Nome Completo</label>
                                        <input type="text" name="nome" class="form-control" required 
                                               value="<?php echo htmlspecialchars($usuario['nome'] ?? $usuario_nome); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">E-mail</label>
                                        <input type="email" name="email" class="form-control" required 
                                               value="<?php echo htmlspecialchars($usuario['email'] ?? $usuario_email); ?>">
                                    </div>
                                    
                                    <?php if ($funcionario): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Telefone</label>
                                        <input type="text" name="telefone" class="form-control" 
                                               value="<?php echo htmlspecialchars($funcionario['telefone'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Endereço</label>
                                        <textarea name="endereco" class="form-control" rows="3"><?php echo htmlspecialchars($funcionario['endereco'] ?? ''); ?></textarea>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Tipo de Usuário</label>
                                        <input type="text" class="form-control" disabled 
                                               value="<?php echo $is_professor ? 'Professor' : 'Administrador'; ?>">
                                    </div>
                                    
                                    <button type="submit" name="atualizar_perfil" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Atualizar Perfil
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Tab Alterar Senha -->
                            <div class="tab-pane fade" id="senha" role="tabpanel">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Senha Atual</label>
                                        <input type="password" name="senha_atual" class="form-control" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Nova Senha</label>
                                        <input type="password" name="nova_senha" class="form-control" required>
                                        <small class="text-muted">Mínimo de 6 caracteres</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Confirmar Nova Senha</label>
                                        <input type="password" name="confirmar_senha" class="form-control" required>
                                    </div>
                                    
                                    <button type="submit" name="alterar_senha" class="btn btn-warning">
                                        <i class="fas fa-key"></i> Alterar Senha
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.querySelector('.main-content').classList.toggle('active');
        });
    </script>
</body>
</html>