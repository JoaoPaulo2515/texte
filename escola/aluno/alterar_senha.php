<?php
// escola/aluno/alterar_senha.php - Alteração de Senha do Aluno

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/constants.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';

// Buscar dados completos do aluno
$sql_aluno = "SELECT e.id, e.nome, e.matricula, e.email, u.id as usuario_id, u.nome, u.email as user_email
              FROM estudantes e
              JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    session_destroy();
    header('Location: ../login.php?msg=erro');
    exit;
}

$usuario_id = $aluno['usuario_id'];

// ============================================
// PROCESSAR ALTERAÇÃO DE SENHA
// ============================================
$success = '';
$error = '';
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (empty($senha_atual)) {
        $error = "Digite a senha atual.";
    } elseif (empty($nova_senha)) {
        $error = "Digite a nova senha.";
    } elseif (strlen($nova_senha) < 6) {
        $error = "A nova senha deve ter no mínimo 6 caracteres.";
    } elseif ($nova_senha !== $confirmar_senha) {
        $error = "A nova senha e a confirmação não coincidem.";
    } else {
        // Verificar senha atual
        $sql_check = "SELECT senha FROM usuarios WHERE id = :id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':id' => $usuario_id]);
        $usuario = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($senha_atual, $usuario['senha'])) {
            $error = "Senha atual incorreta.";
        } else {
            // Verificar se a nova senha é igual à atual
            if (password_verify($nova_senha, $usuario['senha'])) {
                $error = "A nova senha deve ser diferente da senha atual.";
            } else {
                // Atualizar senha
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $sql_update = "UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':senha' => $nova_senha_hash, ':id' => $usuario_id]);
                
                $success = "Senha alterada com sucesso!";
                
                // Registrar log
                $sql_log = "INSERT INTO logs (usuario_id, acao, descricao, ip, created_at) 
                            VALUES (:usuario_id, 'alterar_senha_aluno', 'Alteração de senha do aluno: ' || :nome, :ip, NOW())";
                $stmt_log = $conn->prepare($sql_log);
                $stmt_log->execute([
                    ':usuario_id' => $usuario_id,
                    ':nome' => $aluno['nome'],
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
            }
        }
    }
}

// Buscar notificações não lidas
$sql_notificacoes = "SELECT COUNT(*) as total FROM notificacoes 
                     WHERE usuario_id = :usuario_id AND lida = 0";
$stmt_notificacoes = $conn->prepare($sql_notificacoes);
$stmt_notificacoes->execute([':usuario_id' => $usuario_id]);
$total_notificacoes = $stmt_notificacoes->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha | Área do Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
            padding: 12px 30px;
        }
        
        .btn-primary:hover {
            background: #004d2d;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
            display: inline-block;
        }
        
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        .form-control:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 0.2rem rgba(0, 107, 62, 0.25);
        }
        
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .strength-weak { background: #dc3545; width: 25%; }
        .strength-medium { background: #ffc107; width: 50%; }
        .strength-strong { background: #28a745; width: 75%; }
        .strength-very-strong { background: #006B3E; width: 100%; }
        
        .requisito-item {
            font-size: 0.8rem;
            margin: 5px 0;
            color: #6c757d;
        }
        
        .requisito-ok {
            color: #28a745;
        }
        
        .requisito-ok i {
            color: #28a745;
        }
        
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
            .menu-toggle {
                display: block;
            }
        }
        
        .icon-requisito {
            width: 20px;
            display: inline-block;
        }
        
        .info-aluno {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid #006B3E;
        }
        
        /* CORRIGIDO: Títulos com cor preta */
        .page-title {
            color: #333;
            text-shadow: none;
        }
        
        .page-subtitle {
            color: #666;
        }
        
        h1 {
            color: #333 !important;
            text-shadow: none !important;
        }
        
        .text-white {
            color: #333 !important;
        }
        
        p.text-white-50 {
            color: #666 !important;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho CORRIGIDO - Texto preto -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 style="color: #333; text-shadow: none;">
                    <i class="fas fa-key"></i> Alterar Senha
                </h1>
                <p style="color: #666;">Mantenha sua conta segura alterando sua senha regularmente <?php echo $usuario_id; ?></p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($warning): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-info-circle"></i> <?php echo $warning; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Formulário de Alteração de Senha -->
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-lock"></i> Formulário de Alteração de Senha</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formAlterarSenha">
                            <!-- Informações do Aluno -->
                            <div class="alert info-aluno mb-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <i class="fas fa-user-graduate"></i>
                                        <strong>Aluno:</strong> <?php echo htmlspecialchars($aluno['nome']); ?>
                                    </div>
                                    <div class="col-md-6">
                                        <i class="fas fa-id-card"></i>
                                        <strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula']); ?>
                                    </div>
                                    <div class="col-md-6 mt-2">
                                        <i class="fas fa-envelope"></i>
                                        <strong>Email:</strong> <?php echo htmlspecialchars($aluno['email'] ?: $aluno['user_email']); ?>
                                    </div>
                                    <div class="col-md-6 mt-2">
                                        <i class="fas fa-building"></i>
                                        <strong>ID do Aluno:</strong> <?php echo $aluno_id; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Senha Atual -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-key"></i> Senha Atual <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" name="senha_atual" id="senha_atual" class="form-control" placeholder="Digite sua senha atual" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-password" data-target="senha_atual">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Nova Senha -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-key"></i> Nova Senha <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" name="nova_senha" id="nova_senha" class="form-control" placeholder="Digite a nova senha (mínimo 6 caracteres)" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-password" data-target="nova_senha">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength" id="password-strength"></div>
                                
                                <!-- Requisitos de Senha -->
                                <div class="mt-2">
                                    <small class="text-muted">Requisitos da senha:</small>
                                    <div class="row mt-1">
                                        <div class="col-md-6">
                                            <div class="requisito-item" id="req-min-6">
                                                <i class="fas fa-circle icon-requisito"></i> Mínimo 6 caracteres
                                            </div>
                                            <div class="requisito-item" id="req-maiuscula">
                                                <i class="fas fa-circle icon-requisito"></i> Pelo menos uma letra maiúscula
                                            </div>
                                            <div class="requisito-item" id="req-minuscula">
                                                <i class="fas fa-circle icon-requisito"></i> Pelo menos uma letra minúscula
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="requisito-item" id="req-numero">
                                                <i class="fas fa-circle icon-requisito"></i> Pelo menos um número
                                            </div>
                                            <div class="requisito-item" id="req-especial">
                                                <i class="fas fa-circle icon-requisito"></i> Pelo menos um caractere especial (!@#$%&*)
                                            </div>
                                            <div class="requisito-item" id="req-diferente">
                                                <i class="fas fa-circle icon-requisito"></i> Diferente da senha atual
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Confirmar Nova Senha -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-check-circle"></i> Confirmar Nova Senha <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" placeholder="Digite novamente a nova senha" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-password" data-target="confirmar_senha">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="confirmacao-status" class="mt-1">
                                    <small id="confirmacao-text"></small>
                                </div>
                            </div>
                            
                            <!-- Divider -->
                            <hr class="my-4">
                            
                            <!-- Botões -->
                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                                <button type="submit" class="btn btn-primary" id="btn-submit">
                                    <i class="fas fa-save"></i> Alterar Senha
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Dicas de Segurança -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Dicas de Segurança</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-3">
                            <i class="fas fa-check-circle text-success me-3 mt-1 fa-lg"></i>
                            <div>
                                <strong>Use uma senha única</strong>
                                <p class="small text-muted mb-0">Não use a mesma senha para outros serviços</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start mb-3">
                            <i class="fas fa-check-circle text-success me-3 mt-1 fa-lg"></i>
                            <div>
                                <strong>Evite informações pessoais</strong>
                                <p class="small text-muted mb-0">Não use seu nome, data de nascimento ou "123456"</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start mb-3">
                            <i class="fas fa-check-circle text-success me-3 mt-1 fa-lg"></i>
                            <div>
                                <strong>Altere regularmente</strong>
                                <p class="small text-muted mb-0">Recomendamos trocar a senha a cada 3 meses</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start mb-3">
                            <i class="fas fa-check-circle text-success me-3 mt-1 fa-lg"></i>
                            <div>
                                <strong>Não compartilhe</strong>
                                <p class="small text-muted mb-0">Sua senha é pessoal e intransferível</p>
                            </div>
                        </div>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            <small>Após alterar a senha, você precisará usar a nova senha no próximo login.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Toggle password visibility
        $('.toggle-password').on('click', function() {
            let target = $(this).data('target');
            let input = $('#' + target);
            let icon = $(this).find('i');
            
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                input.attr('type', 'password');
                icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });
        
        // Verificar força da senha
        $('#nova_senha').on('keyup', function() {
            let senha = $(this).val();
            let forca = 0;
            
            // Requisitos
            let temMin6 = senha.length >= 6;
            let temMaiuscula = /[A-Z]/.test(senha);
            let temMinuscula = /[a-z]/.test(senha);
            let temNumero = /[0-9]/.test(senha);
            let temEspecial = /[!@#$%&*]/.test(senha);
            
            // Atualizar ícones dos requisitos
            $('#req-min-6').html((temMin6 ? '<i class="fas fa-check-circle text-success icon-requisito"></i>' : '<i class="fas fa-circle icon-requisito"></i>') + ' Mínimo 6 caracteres');
            $('#req-maiuscula').html((temMaiuscula ? '<i class="fas fa-check-circle text-success icon-requisito"></i>' : '<i class="fas fa-circle icon-requisito"></i>') + ' Pelo menos uma letra maiúscula');
            $('#req-minuscula').html((temMinuscula ? '<i class="fas fa-check-circle text-success icon-requisito"></i>' : '<i class="fas fa-circle icon-requisito"></i>') + ' Pelo menos uma letra minúscula');
            $('#req-numero').html((temNumero ? '<i class="fas fa-check-circle text-success icon-requisito"></i>' : '<i class="fas fa-circle icon-requisito"></i>') + ' Pelo menos um número');
            $('#req-especial').html((temEspecial ? '<i class="fas fa-check-circle text-success icon-requisito"></i>' : '<i class="fas fa-circle icon-requisito"></i>') + ' Pelo menos um caractere especial (!@#$%&*)');
            
            // Calcular força
            if (temMin6) forca++;
            if (temMaiuscula) forca++;
            if (temMinuscula) forca++;
            if (temNumero) forca++;
            if (temEspecial) forca++;
            
            // Aplicar classe de força
            let strengthBar = $('#password-strength');
            strengthBar.removeClass('strength-weak strength-medium strength-strong strength-very-strong');
            
            if (senha.length === 0) {
                strengthBar.css('width', '0%').css('background', 'transparent');
            } else if (forca <= 2) {
                strengthBar.addClass('strength-weak');
            } else if (forca === 3) {
                strengthBar.addClass('strength-medium');
            } else if (forca === 4) {
                strengthBar.addClass('strength-strong');
            } else {
                strengthBar.addClass('strength-very-strong');
            }
            
            // Verificar se senha é diferente da atual
            let senhaAtual = $('#senha_atual').val();
            if (senhaAtual && senha === senhaAtual) {
                $('#req-diferente').html('<i class="fas fa-times-circle text-danger icon-requisito"></i> Diferente da senha atual');
            } else {
                $('#req-diferente').html('<i class="fas fa-circle icon-requisito"></i> Diferente da senha atual');
            }
        });
        
        // Verificar confirmação de senha
        $('#confirmar_senha, #nova_senha').on('keyup', function() {
            let novaSenha = $('#nova_senha').val();
            let confirmar = $('#confirmar_senha').val();
            
            if (confirmar.length === 0) {
                $('#confirmacao-text').html('');
                $('#confirmar_senha').removeClass('is-valid is-invalid');
            } else if (novaSenha === confirmar) {
                $('#confirmacao-text').html('<i class="fas fa-check-circle text-success"></i> As senhas coincidem');
                $('#confirmar_senha').addClass('is-valid').removeClass('is-invalid');
            } else {
                $('#confirmacao-text').html('<i class="fas fa-times-circle text-danger"></i> As senhas não coincidem');
                $('#confirmar_senha').addClass('is-invalid').removeClass('is-valid');
            }
        });
        
        // Validar formulário antes de enviar
        $('#formAlterarSenha').on('submit', function(e) {
            let novaSenha = $('#nova_senha').val();
            let confirmar = $('#confirmar_senha').val();
            let senhaAtual = $('#senha_atual').val();
            
            if (!senhaAtual) {
                e.preventDefault();
                alert('Por favor, digite sua senha atual.');
                return false;
            }
            
            if (!novaSenha) {
                e.preventDefault();
                alert('Por favor, digite a nova senha.');
                return false;
            }
            
            if (novaSenha.length < 6) {
                e.preventDefault();
                alert('A nova senha deve ter no mínimo 6 caracteres.');
                return false;
            }
            
            if (novaSenha !== confirmar) {
                e.preventDefault();
                alert('A nova senha e a confirmação não coincidem.');
                return false;
            }
            
            if (novaSenha === senhaAtual) {
                e.preventDefault();
                alert('A nova senha deve ser diferente da senha atual.');
                return false;
            }
            
            return true;
        });
        
        // Verificar senha atual ao digitar
        $('#senha_atual').on('keyup', function() {
            let novaSenha = $('#nova_senha').val();
            let senhaAtual = $(this).val();
            
            if (novaSenha && novaSenha === senhaAtual) {
                $('#req-diferente').html('<i class="fas fa-times-circle text-danger icon-requisito"></i> Diferente da senha atual');
            } else {
                $('#req-diferente').html('<i class="fas fa-circle icon-requisito"></i> Diferente da senha atual');
            }
        });
    </script>
</body>
</html>