<?php
// config/email_config.php - Configuração de envio de e-mails

// Configurações SMTP para produção
define('SMTP_HOST', 'smtp.gmail.com'); // ou mail.seu dominio.ao
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@sige.ao');
define('SMTP_PASS', 'sua_senha_aqui');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM', 'noreply@sige.ao');
define('SMTP_FROM_NAME', 'SIGE Angola');

// Função para enviar e-mail
function enviarEmail($para, $assunto, $mensagem, $anexos = []) {
    // Verificar se PHPMailer está disponível
    if (file_exists(__DIR__ . '/../lib/PHPMailer/PHPMailer.php')) {
        return enviarEmailPHPMailer($para, $assunto, $mensagem, $anexos);
    } else {
        return enviarEmailMail($para, $assunto, $mensagem);
    }
}

// Enviar e-mail com PHPMailer (recomendado)
function enviarEmailPHPMailer($para, $assunto, $mensagem, $anexos = []) {
    require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
    require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($para);
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = $mensagem;
        $mail->AltBody = strip_tags($mensagem);
        
        foreach ($anexos as $anexo) {
            if (file_exists($anexo)) {
                $mail->addAttachment($anexo);
            }
        }
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Erro ao enviar e-mail: " . $mail->ErrorInfo);
        return false;
    }
}

// Enviar e-mail com mail() nativo (fallback)
function enviarEmailMail($para, $assunto, $mensagem) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    
    return mail($para, $assunto, $mensagem, $headers);
}

// Enviar código de recuperação
function enviarCodigoRecuperacao($email, $nome, $codigo) {
    $assunto = "🔐 Código de Recuperação - SIGE Angola";
    $mensagem = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f8f9fa; }
            .code { font-size: 32px; font-weight: bold; text-align: center; padding: 20px; background: white; border-radius: 10px; letter-spacing: 5px; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>SIGE Angola</h2>
                <p>Sistema Integrado de Gestão Escolar</p>
            </div>
            <div class='content'>
                <h3>Olá, $nome!</h3>
                <p>Recebemos uma solicitação para recuperar sua senha.</p>
                <p>Utilize o código abaixo para redefinir sua senha:</p>
                <div class='code'>$codigo</div>
                <p>Este código é válido por <strong>15 minutos</strong>.</p>
                <p>Se você não solicitou esta recuperação, ignore este e-mail.</p>
            </div>
            <div class='footer'>
                <p>&copy; 2026 SIGE Angola - Todos os direitos reservados</p>
                <p>Este é um e-mail automático, por favor não responda.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return enviarEmail($email, $assunto, $mensagem);
}

// Enviar confirmação de alteração de senha
function enviarConfirmacaoAlteracaoSenha($email, $nome) {
    $assunto = "✅ Senha alterada com sucesso - SIGE Angola";
    $mensagem = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f8f9fa; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>SIGE Angola</h2>
                <p>Sistema Integrado de Gestão Escolar</p>
            </div>
            <div class='content'>
                <h3>Olá, $nome!</h3>
                <p>Sua senha foi alterada com sucesso.</p>
                <p>Se você não realizou esta alteração, entre em contato imediatamente com o suporte.</p>
                <p>Acesse o sistema com sua nova senha:</p>
                <p><a href='" . APP_URL . "/login.php' style='background: #006B3E; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Fazer Login</a></p>
            </div>
            <div class='footer'>
                <p>&copy; 2026 SIGE Angola - Todos os direitos reservados</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return enviarEmail($email, $assunto, $mensagem);
}