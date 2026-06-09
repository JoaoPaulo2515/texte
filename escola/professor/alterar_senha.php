<?php
// escola/professor/alterar_senha.php - Alterar Senha do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$usuario_id = $professor['usuario_id'];
$professor_nome = $professor['nome'] ?? 'Professor';
$escola_nome = $professor['escola_nome'] ?? 'SIGE Angola';

$success = '';
$error = '';

// Processar alteração de senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_senha'])) {
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    // Validações
    if (empty($senha_atual) || empty($nova_senha) || empty($confirmar_senha)) {
        $error = "⚠️ Por favor, preencha todos os campos.";
    } elseif (strlen($nova_senha) < 6) {
        $error = "⚠️ A nova senha deve ter no mínimo 6 caracteres.";
    } elseif ($nova_senha !== $confirmar_senha) {
        $error = "⚠️ A nova senha e a confirmação não coincidem.";
    } else {
        // Verificar senha atual
        $sql = "SELECT senha FROM usuarios WHERE id = :usuario_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario || !password_verify($senha_atual, $usuario['senha'])) {
            $error = "⚠️ Senha atual incorreta.";
        } else {
            // Atualizar senha
            $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $sql_update = "UPDATE usuarios SET senha = :senha, updated_at = NOW() WHERE id = :usuario_id";
            $stmt_update = $conn->prepare($sql_update);
            
            if ($stmt_update->execute([':senha' => $nova_senha_hash, ':usuario_id' => $usuario_id])) {
                $success = "✅ Senha alterada com sucesso!";
                
                // Registrar log de alteração de senha
                $sql_log = "INSERT INTO logs_usuarios (usuario_id, acao, ip_address, user_agent) 
                           VALUES (:usuario_id, 'alteracao_senha', :ip, :agent)";
                $stmt_log = $conn->prepare($sql_log);
                $stmt_log->execute([
                    ':usuario_id' => $usuario_id,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } else {
                $error = "❌ Erro ao alterar senha. Tente novamente.";
            }
        }
    }
}

// Buscar dados do professor para exibição
$sql_professor = "SELECT p.*, u.email, u.created_at as usuario_criado 
                  FROM funcionarios p 
                  INNER JOIN usuarios u ON u.id = p.usuario_id 
                  WHERE p.id = :professor_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':professor_id' => $professor_id]);
$dados_professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);

$email = $dados_professor['email'] ?? '';
$data_cadastro = $dados_professor['usuario_criado'] ?? '';
$telefone = $dados_professor['telefone'] ?? '';
$cargo = $dados_professor['cargo'] ?? 'Professor';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           VARIÁVEIS E RESET
        ============================================ */
        :root {
            --primary-color: #006B3E;
            --primary-dark: #004d2b;
            --primary-light: #e8f5e9;
            --secondary-color: #1A2A6C;
            --accent-color: #FF6B35;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --dark: #2c3e50;
            --gray: #6c757d;
            --light-gray: #f8f9fa;
            --border-radius: 16px;
            --box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }

        /* ============================================
           PAGE HEADER
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        /* Botões modernos */
        .btn-modern {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-salvar {
            background: linear-gradient(135deg, var(--success), #1e7e34);
            color: white;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            width: 100%;
        }

        .btn-salvar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
        }

        /* ============================================
           CARDS
        ============================================ */
        .card-modern {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .card-modern:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary-color);
            display: inline-block;
        }

        /* ============================================
           FORMULÁRIO
        ============================================ */
        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .form-label i {
            color: var(--primary-color);
            width: 20px;
        }

        .input-group-modern {
            position: relative;
        }

        .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 16px;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
            background: white;
            border: none;
            z-index: 10;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        /* ============================================
           INFO CARD
        ============================================ */
        .info-card {
            background: linear-gradient(135deg, var(--primary-light), #ffffff);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(0, 107, 62, 0.1);
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        /* ============================================
           STRENGTH METER
        ============================================ */
        .strength-meter {
            margin-top: 10px;
        }

        .strength-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background 0.3s ease;
            border-radius: 3px;
        }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 5px;
            display: inline-block;
        }

        .strength-weak .strength-fill {
            width: 25%;
            background: #dc3545;
        }

        .strength-medium .strength-fill {
            width: 50%;
            background: #ffc107;
        }

        .strength-good .strength-fill {
            width: 75%;
            background: #17a2b8;
        }

        .strength-strong .strength-fill {
            width: 100%;
            background: #28a745;
        }

        /* ============================================
           REQUISITOS SENHA
        ============================================ */
        .requisitos {
            margin-top: 15px;
            padding: 15px;
            background: var(--light-gray);
            border-radius: 12px;
            font-size: 0.8rem;
        }

        .requisitos p {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .requisitos ul {
            margin: 0;
            padding-left: 20px;
            list-style: none;
        }

        .requisitos li {
            margin-bottom: 5px;
            color: var(--gray);
            transition: var(--transition);
        }

        .requisitos li.valid {
            color: var(--success);
        }

        .requisitos li.invalid {
            color: var(--danger);
        }

        .requisitos li i {
            width: 20px;
            margin-right: 8px;
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            animation: fadeInUp 0.5s ease-out;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c8e6d9);
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid var(--danger);
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .animated {
            animation: fadeInUp 0.6s ease-out;
        }

        .shake {
            animation: shake 0.3s ease-in-out;
        }

        /* ============================================
           SCROLLBAR
        ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
        }

        /* ============================================
           DICAS DE SEGURANÇA
        ============================================ */
        .security-tips {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 25px;
        }

        .security-tips h6 {
            color: #e65100;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .security-tips ul {
            margin: 0;
            padding-left: 20px;
        }

        .security-tips li {
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #bf360c;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
            
            .card-modern {
                padding: 20px;
            }
            
            .info-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
        }

        /* ============================================
           PRINT STYLES
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-salvar, .password-toggle, .security-tips {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-key me-2"></i>Alterar Senha</h2>
                    <p class="mb-0">Mantenha sua conta segura alterando sua senha regularmente</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn-modern"><i class="fas fa-arrow-left"></i> Voltar ao Dashboard</a>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert-custom alert-success animated">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-custom alert-danger animated" id="errorAlert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <!-- Formulário de Alteração de Senha -->
            <div class="col-lg-7">
                <div class="card-modern animated" style="animation-delay: 0.1s;">
                    <div class="card-title">
                        <i class="fas fa-lock me-2"></i> Alterar Senha
                    </div>
                    
                    <form method="POST" id="formAlterarSenha" onsubmit="return validarSenha()">
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-key"></i> Senha Atual *</label>
                            <div class="input-group-modern">
                                <input type="password" name="senha_atual" id="senha_atual" class="form-control" 
                                       placeholder="Digite sua senha atual" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('senha_atual')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-lock"></i> Nova Senha *</label>
                            <div class="input-group-modern">
                                <input type="password" name="nova_senha" id="nova_senha" class="form-control" 
                                       placeholder="Digite sua nova senha" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('nova_senha')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                            <!-- Força da senha -->
                            <div class="strength-meter" id="strengthMeter" style="display: none;">
                                <div class="strength-bar">
                                    <div class="strength-fill"></div>
                                </div>
                                <span class="strength-text" id="strengthText"></span>
                            </div>
                            
                            <!-- Requisitos da senha -->
                            <div class="requisitos">
                                <p><i class="fas fa-check-circle me-1"></i> Requisitos da senha:</p>
                                <ul>
                                    <li id="req-length"><i class="fas fa-circle"></i> Mínimo 6 caracteres</li>
                                    <li id="req-number"><i class="fas fa-circle"></i> Pelo menos 1 número</li>
                                    <li id="req-upper"><i class="fas fa-circle"></i> Pelo menos 1 letra maiúscula</li>
                                    <li id="req-lower"><i class="fas fa-circle"></i> Pelo menos 1 letra minúscula</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-check-circle"></i> Confirmar Nova Senha *</label>
                            <div class="input-group-modern">
                                <input type="password" name="confirmar_senha" id="confirmar_senha" class="form-control" 
                                       placeholder="Confirme sua nova senha" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('confirmar_senha')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="confirmStatus" class="mt-2"></div>
                        </div>
                        
                        <div class="security-tips">
                            <h6><i class="fas fa-shield-alt me-2"></i> Dicas de Segurança</h6>
                            <ul>
                                <li><i class="fas fa-check-circle me-2"></i> Use uma senha diferente das suas outras contas</li>
                                <li><i class="fas fa-check-circle me-2"></i> Evite informações pessoais como datas de nascimento</li>
                                <li><i class="fas fa-check-circle me-2"></i> Combine letras maiúsculas, minúsculas, números e símbolos</li>
                                <li><i class="fas fa-check-circle me-2"></i> Altere sua senha a cada 3 meses</li>
                                <li><i class="fas fa-check-circle me-2"></i> Não compartilhe sua senha com ninguém</li>
                            </ul>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="alterar_senha" class="btn-salvar">
                                <i class="fas fa-save me-2"></i> Alterar Senha
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Informações do Professor -->
            <div class="col-lg-5">
                <div class="card-modern animated" style="animation-delay: 0.2s;">
                    <div class="card-title">
                        <i class="fas fa-user-graduate me-2"></i> Informações da Conta
                    </div>
                    
                    <div class="text-center mb-4">
                        <div class="rounded-circle bg-gradient-primary d-inline-flex align-items-center justify-content-center mb-3" 
                             style="width: 100px; height: 100px; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));">
                            <i class="fas fa-user-graduate fa-3x text-white"></i>
                        </div>
                        <h5 class="mb-1"><?php echo htmlspecialchars($professor_nome); ?></h5>
                        <small class="text-muted"><?php echo htmlspecialchars($cargo); ?></small>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">E-mail</div>
                                <div class="info-value"><?php echo htmlspecialchars($email); ?></div>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Telefone</div>
                                <div class="info-value"><?php echo !empty($telefone) ? htmlspecialchars($telefone) : 'Não informado'; ?></div>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Cadastrado em</div>
                                <div class="info-value"><?php echo !empty($data_cadastro) ? date('d/m/Y', strtotime($data_cadastro)) : 'Não informado'; ?></div>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="info-content">
                                <div class="info-label">Escola</div>
                                <div class="info-value"><?php echo htmlspecialchars($escola_nome); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3" style="border-radius: 12px; background: #e8f4fd; border: none;">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Após alterar sua senha, você precisará usar a nova senha na próxima vez que fizer login.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Validar força da senha
        function checkPasswordStrength(password) {
            let strength = 0;
            let requirements = {
                length: false,
                number: false,
                upper: false,
                lower: false
            };
            
            if (password.length >= 6) {
                strength++;
                requirements.length = true;
            }
            if (/\d/.test(password)) {
                strength++;
                requirements.number = true;
            }
            if (/[A-Z]/.test(password)) {
                strength++;
                requirements.upper = true;
            }
            if (/[a-z]/.test(password)) {
                strength++;
                requirements.lower = true;
            }
            
            // Atualizar requisitos visuais
            updateRequirements(requirements);
            
            // Retornar nível de força
            if (password.length === 0) return { level: 0, text: '' };
            if (strength <= 2) return { level: 1, text: 'Fraca', class: 'strength-weak' };
            if (strength === 3) return { level: 2, text: 'Média', class: 'strength-medium' };
            if (strength === 4) return { level: 3, text: 'Boa', class: 'strength-good' };
            return { level: 4, text: 'Forte', class: 'strength-strong' };
        }
        
        function updateRequirements(requirements) {
            const reqLength = document.getElementById('req-length');
            const reqNumber = document.getElementById('req-number');
            const reqUpper = document.getElementById('req-upper');
            const reqLower = document.getElementById('req-lower');
            
            updateRequirementItem(reqLength, requirements.length);
            updateRequirementItem(reqNumber, requirements.number);
            updateRequirementItem(reqUpper, requirements.upper);
            updateRequirementItem(reqLower, requirements.lower);
        }
        
        function updateRequirementItem(element, isValid) {
            if (isValid) {
                element.classList.add('valid');
                element.classList.remove('invalid');
                element.innerHTML = '<i class="fas fa-check-circle"></i> ' + element.innerHTML.split('>')[1];
            } else {
                element.classList.add('invalid');
                element.classList.remove('valid');
                element.innerHTML = '<i class="fas fa-circle"></i> ' + element.innerHTML.split('>')[1];
            }
        }
        
        // Verificar confirmação de senha
        function checkPasswordMatch() {
            const novaSenha = document.getElementById('nova_senha').value;
            const confirmarSenha = document.getElementById('confirmar_senha').value;
            const confirmStatus = document.getElementById('confirmStatus');
            
            if (confirmarSenha.length > 0) {
                if (novaSenha === confirmarSenha) {
                    confirmStatus.innerHTML = '<small class="text-success"><i class="fas fa-check-circle"></i> As senhas coincidem</small>';
                    return true;
                } else {
                    confirmStatus.innerHTML = '<small class="text-danger"><i class="fas fa-times-circle"></i> As senhas não coincidem</small>';
                    return false;
                }
            } else {
                confirmStatus.innerHTML = '';
                return false;
            }
        }
        
        // Event listeners para validação em tempo real
        document.getElementById('nova_senha').addEventListener('input', function() {
            const password = this.value;
            const strengthMeter = document.getElementById('strengthMeter');
            const strength = checkPasswordStrength(password);
            
            if (password.length > 0) {
                strengthMeter.style.display = 'block';
                const meter = document.querySelector('.strength-meter');
                meter.className = 'strength-meter ' + strength.class;
                document.getElementById('strengthText').innerHTML = '<i class="fas fa-chart-line"></i> Força: ' + strength.text;
            } else {
                strengthMeter.style.display = 'none';
            }
            
            checkPasswordMatch();
        });
        
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            checkPasswordMatch();
        });
        
        // Validação final antes de enviar
        function validarSenha() {
            const novaSenha = document.getElementById('nova_senha').value;
            const confirmarSenha = document.getElementById('confirmar_senha').value;
            const strength = checkPasswordStrength(novaSenha);
            
            if (novaSenha.length < 6) {
                mostrarErro('A nova senha deve ter no mínimo 6 caracteres.');
                document.getElementById('nova_senha').focus();
                return false;
            }
            
            if (strength.level < 2) {
                mostrarErro('Por favor, use uma senha mais forte. Combine letras maiúsculas, minúsculas e números.');
                document.getElementById('nova_senha').focus();
                return false;
            }
            
            if (novaSenha !== confirmarSenha) {
                mostrarErro('A nova senha e a confirmação não coincidem.');
                document.getElementById('confirmar_senha').focus();
                return false;
            }
            
            return true;
        }
        
        function mostrarErro(mensagem) {
            // Criar alerta de erro dinâmico
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert-custom alert-danger animated';
            errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> ' + mensagem;
            
            const container = document.querySelector('.main-content');
            const firstChild = container.firstChild;
            container.insertBefore(errorDiv, firstChild);
            
            // Remover após 5 segundos
            setTimeout(() => {
                errorDiv.remove();
            }, 5000);
        }
        
        // Auto-fechar alertas após 5 segundos
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        }, 100);
        
        // Adicionar animação de entrada aos elementos
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.animated');
            elements.forEach((el, index) => {
                el.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>