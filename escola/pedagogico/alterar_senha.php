<?php
// escola/pedagogico/alterar_senha.php - Alterar Senha do Usuário Pedagógico

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo, u.senha as senha_atual
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];
$usuario_id = $funcionario['usuario_id'];

$mensagem = '';
$erro = '';

// Processar alteração de senha
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_atual = $_POST['senha_atual'];
    $nova_senha = $_POST['nova_senha'];
    $confirmar_senha = $_POST['confirmar_senha'];
    
    // Validações
    if (empty($senha_atual)) {
        $erro = "Por favor, informe sua senha atual.";
    } elseif (empty($nova_senha)) {
        $erro = "Por favor, informe a nova senha.";
    } elseif (empty($confirmar_senha)) {
        $erro = "Por favor, confirme a nova senha.";
    } elseif (strlen($nova_senha) < 6) {
        $erro = "A nova senha deve ter no mínimo 6 caracteres.";
    } elseif ($nova_senha !== $confirmar_senha) {
        $erro = "A nova senha e a confirmação não coincidem.";
    } else {
        // Verificar senha atual
        if (!password_verify($senha_atual, $funcionario['senha_atual'])) {
            $erro = "Senha atual incorreta.";
        } else {
            // Verificar se a nova senha é diferente da atual
            if (password_verify($nova_senha, $funcionario['senha_atual'])) {
                $erro = "A nova senha deve ser diferente da senha atual.";
            } else {
                // Atualizar senha
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $sql = "UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :usuario_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':senha' => $nova_senha_hash,
                    ':usuario_id' => $usuario_id
                ]);
                
                $mensagem = "Senha alterada com sucesso!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); 
            padding: 20px; 
            min-height: 100vh; 
        }
        .container { max-width: 500px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-body { padding: 30px; }
        
        .form-group { margin-bottom: 25px; }
        .form-label { 
            font-weight: 600; 
            font-size: 14px; 
            color: #2c3e50; 
            margin-bottom: 8px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-label i { color: #1e5799; width: 20px; }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #1e5799;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30,87,153,0.1);
        }
        
        .btn-alterar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            width: 100%;
        }
        .btn-alterar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        
        .password-requirements {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
        }
        .password-requirements h6 {
            font-size: 13px;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .password-requirements ul {
            margin: 0;
            padding-left: 20px;
        }
        .password-requirements li {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .password-requirements li.valid { color: #28a745; }
        .password-requirements li.invalid { color: #dc3545; }
        
        .info-box {
            background: #e8f4f8;
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .info-box i { color: #1e5799; font-size: 18px; margin-top: 2px; }
        .info-box p { margin: 0; font-size: 12px; color: #555; }
        
        /* Modais */
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        
        .modal-custom-content {
            background: white;
            margin: 15% auto;
            width: 90%;
            max-width: 450px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-custom-header {
            padding: 20px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-custom-header.modal-success { background: linear-gradient(135deg, #28a745, #1e7e34); color: white; }
        .modal-custom-header.modal-danger { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        
        .modal-custom-header h3 { font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .close-modal:hover { background: rgba(255,255,255,0.2); }
        
        .modal-custom-body { padding: 25px; text-align: center; }
        .modal-custom-body p { margin-bottom: 0; font-size: 16px; }
        
        .modal-custom-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-modal-ok {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 25px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-modal-ok:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(39,174,96,0.3); }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .card-body { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-key"></i> Alterar Senha</h1>
            <p>Mantenha sua conta segura</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-lock"></i> Alteração de Senha
        </div>
        <div class="card-body">
            <form method="POST" id="formAlterarSenha">
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-key"></i> Senha Atual</label>
                    <input type="password" name="senha_atual" id="senha_atual" class="form-control" required placeholder="Digite sua senha atual">
                </div>
                
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-lock"></i> Nova Senha</label>
                    <input type="password" name="nova_senha" id="nova_senha" class="form-control" required placeholder="Digite a nova senha" onkeyup="validarSenha()">
                    <div id="senhaFeedback" class="small mt-1"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-check-circle"></i> Confirmar Nova Senha</label>
                    <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" required placeholder="Confirme a nova senha" onkeyup="validarConfirmacao()">
                    <div id="confirmarFeedback" class="small mt-1"></div>
                </div>
                
                <div class="password-requirements" id="requisitosSenha">
                    <h6><i class="fas fa-shield-alt"></i> Requisitos da senha:</h6>
                    <ul>
                        <li id="req_minimo"><i class="fas fa-circle fa-xs"></i> Mínimo de 6 caracteres</li>
                        <li id="req_maiuscula"><i class="fas fa-circle fa-xs"></i> Pelo menos uma letra maiúscula</li>
                        <li id="req_numero"><i class="fas fa-circle fa-xs"></i> Pelo menos um número</li>
                        <li id="req_caracter"><i class="fas fa-circle fa-xs"></i> Pelo menos um caractere especial (@, #, $, %, etc.)</li>
                    </ul>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <p>Recomendamos usar uma senha forte que você não utiliza em outros sites. Nunca compartilhe sua senha com ninguém.</p>
                </div>
                
                <button type="submit" class="btn-alterar mt-3">
                    <i class="fas fa-save"></i> Alterar Senha
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Sucesso -->
<div id="modalSucesso" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-success">
            <h3><i class="fas fa-check-circle"></i> Sucesso!</h3>
            <span class="close-modal" onclick="fecharModal('modalSucesso')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemSucesso"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="redirecionar()">OK</button>
        </div>
    </div>
</div>

<!-- Modal de Erro -->
<div id="modalErro" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header modal-danger">
            <h3><i class="fas fa-times-circle"></i> Erro!</h3>
            <span class="close-modal" onclick="fecharModal('modalErro')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p id="mensagemErro"></p>
        </div>
        <div class="modal-custom-footer">
            <button class="btn-modal-ok" onclick="fecharModal('modalErro')">OK</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function fecharModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    function redirecionar() {
        window.location.href = 'index.php';
    }
    
    function validarSenha() {
        const senha = document.getElementById('nova_senha').value;
        
        const reqMinimo = document.getElementById('req_minimo');
        const reqMaiuscula = document.getElementById('req_maiuscula');
        const reqNumero = document.getElementById('req_numero');
        const reqCaracter = document.getElementById('req_caracter');
        
        const temMinimo = senha.length >= 6;
        const temMaiuscula = /[A-Z]/.test(senha);
        const temNumero = /\d/.test(senha);
        const temCaracter = /[@#$%^&+=!]/.test(senha);
        
        if (temMinimo) {
            reqMinimo.innerHTML = '<i class="fas fa-check-circle text-success"></i> Mínimo de 6 caracteres';
            reqMinimo.classList.add('valid');
        } else {
            reqMinimo.innerHTML = '<i class="fas fa-circle fa-xs"></i> Mínimo de 6 caracteres';
            reqMinimo.classList.remove('valid');
        }
        
        if (temMaiuscula) {
            reqMaiuscula.innerHTML = '<i class="fas fa-check-circle text-success"></i> Pelo menos uma letra maiúscula';
            reqMaiuscula.classList.add('valid');
        } else {
            reqMaiuscula.innerHTML = '<i class="fas fa-circle fa-xs"></i> Pelo menos uma letra maiúscula';
            reqMaiuscula.classList.remove('valid');
        }
        
        if (temNumero) {
            reqNumero.innerHTML = '<i class="fas fa-check-circle text-success"></i> Pelo menos um número';
            reqNumero.classList.add('valid');
        } else {
            reqNumero.innerHTML = '<i class="fas fa-circle fa-xs"></i> Pelo menos um número';
            reqNumero.classList.remove('valid');
        }
        
        if (temCaracter) {
            reqCaracter.innerHTML = '<i class="fas fa-check-circle text-success"></i> Pelo menos um caractere especial (@, #, $, %, etc.)';
            reqCaracter.classList.add('valid');
        } else {
            reqCaracter.innerHTML = '<i class="fas fa-circle fa-xs"></i> Pelo menos um caractere especial (@, #, $, %, etc.)';
            reqCaracter.classList.remove('valid');
        }
        
        const feedback = document.getElementById('senhaFeedback');
        if (temMinimo && temMaiuscula && temNumero && temCaracter) {
            feedback.innerHTML = '<i class="fas fa-check-circle text-success"></i> Senha forte!';
            feedback.style.color = '#28a745';
        } else if (temMinimo && (temMaiuscula || temNumero)) {
            feedback.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> Senha média - adicione mais caracteres especiais';
            feedback.style.color = '#ffc107';
        } else if (senha.length > 0) {
            feedback.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Senha fraca - siga os requisitos acima';
            feedback.style.color = '#dc3545';
        } else {
            feedback.innerHTML = '';
        }
        
        validarConfirmacao();
    }
    
    function validarConfirmacao() {
        const senha = document.getElementById('nova_senha').value;
        const confirmar = document.getElementById('confirmar_senha').value;
        const feedback = document.getElementById('confirmarFeedback');
        
        if (confirmar.length > 0) {
            if (senha === confirmar) {
                feedback.innerHTML = '<i class="fas fa-check-circle text-success"></i> As senhas coincidem';
                feedback.style.color = '#28a745';
            } else {
                feedback.innerHTML = '<i class="fas fa-times-circle text-danger"></i> As senhas não coincidem';
                feedback.style.color = '#dc3545';
            }
        } else {
            feedback.innerHTML = '';
        }
    }
    
    // Validar formulário antes de enviar
    document.getElementById('formAlterarSenha').addEventListener('submit', function(e) {
        const senha = document.getElementById('nova_senha').value;
        const confirmar = document.getElementById('confirmar_senha').value;
        
        if (senha !== confirmar) {
            e.preventDefault();
            showModalErro('A nova senha e a confirmação não coincidem.');
            return false;
        }
        
        if (senha.length < 6) {
            e.preventDefault();
            showModalErro('A nova senha deve ter no mínimo 6 caracteres.');
            return false;
        }
    });
    
    function showModalSucesso(mensagem) {
        document.getElementById('mensagemSucesso').innerHTML = mensagem;
        document.getElementById('modalSucesso').style.display = 'block';
    }
    
    function showModalErro(mensagem) {
        document.getElementById('mensagemErro').innerHTML = mensagem;
        document.getElementById('modalErro').style.display = 'block';
    }
    
    window.onclick = function(event) {
        const modalSucesso = document.getElementById('modalSucesso');
        const modalErro = document.getElementById('modalErro');
        
        if (event.target == modalSucesso) fecharModal('modalSucesso');
        if (event.target == modalErro) fecharModal('modalErro');
    }
    
    // Mostrar mensagens do PHP
    <?php if ($mensagem): ?>
    showModalSucesso('<?php echo addslashes($mensagem); ?>');
    <?php endif; ?>
    
    <?php if ($erro): ?>
    showModalErro('<?php echo addslashes($erro); ?>');
    <?php endif; ?>
</script>
</body>
</html>