
Solução Rápida para testes locais

Se você só quer testar o sistema sem enviar e-mails realmente, crie uma função mock:
// Substitua a função mail() por uma que apenas registra no log
function mail($to, $subject, $message, $headers = '', $params = '') {
    // Registrar no log
    $log = "=== E-MAIL SIMULADO ===\n";
    $log .= "Para: {$to}\n";
    $log .= "Assunto: {$subject}\n";
    $log .= "Headers: {$headers}\n";
    $log .= "Mensagem: {$message}\n";
    $log .= "=======================\n\n";
    
    file_put_contents(__DIR__ . '/../logs/emails.log', $log, FILE_APPEND);
    
    // Salvar na fila do banco
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("
        INSERT INTO email_queue (destinatario, assunto, mensagem, status, created_at)
        VALUES (:to, :subject, :message, 'pendente', NOW())
    ");
    $stmt->execute([
        ':to' => $to,
        ':subject' => $subject,
        ':message' => $message
    ]);
    
    return true;
}


 Solução 6: Usar serviço de e-mail gratuito (SendGrid, Mailgun)
Exemplo com SendGrid:


// Instalar via composer: composer require sendgrid/sendgrid

use SendGrid\Mail\Mail;

function enviarEmailSendGrid($to, $subject, $content, $attachment = null) {
    $email = new Mail();
    $email->setFrom("noreply@sige.ao", "SIGE Angola");
    $email->setSubject($subject);
    $email->addTo($to);
    $email->addContent("text/html", $content);
    
    if ($attachment && file_exists($attachment)) {
        $fileContent = base64_encode(file_get_contents($attachment));
        $email->addAttachment(
            $fileContent,
            mime_content_type($attachment),
            basename($attachment),
            "attachment"
        );
    }
    
    $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY'));
    try {
        $response = $sendgrid->send($email);
        return $response->statusCode() == 202;
    } catch (Exception $e) {
        error_log('Erro SendGrid: ' . $e->getMessage());
        return false;
    }
}

🔧 Solução 5: Criar função de fallback (salvar e-mail na fila)

// No registrar.php, substitua o envio de e-mail por:

if ($enviar_email && !empty($dados['email'])) {
    // Salvar na fila de e-mails
    $stmt = $conn->prepare("
        INSERT INTO email_queue (destinatario, assunto, mensagem, anexos, status, created_at)
        VALUES (:destinatario, :assunto, :mensagem, :anexos, 'pendente', NOW())
    ");
    
    $stmt->execute([
        ':destinatario' => $dados['email'],
        ':assunto' => "Comprovante de Pagamento - SIGE Angola",
        ':mensagem' => $mensagem_email,
        ':anexos' => $comprovante_file
    ]);
    
    // Exibir mensagem informativa
    $info_email = "O comprovante será enviado por e-mail em breve.";
}

Configure o PHP.ini:

SMTP = localhost
smtp_port = 1025
sendmail_from = noreply@sige.ao

Passo 3: Configurar autenticação SMTP (para Gmail)

Adicione no final do arquivo php.ini:

; SMTP Authentication
smtp_auth = on
smtp_secure = tls
auth_username = seuemail@gmail.com
auth_password = suasenha

Passo 2: Configurar o SMTP

Procure pelas linhas e altere:

[mail function]
; For Win32 only.
SMTP = smtp.gmail.com
smtp_port = 587

; For Win32 only.
sendmail_from = noreply@sige.ao

; For Unix only. You may supply arguments as well (default: "sendmail -t -i").
;sendmail_path = "C:\xampp\sendmail\sendmail.exe -t"

; Force the addition of the specified parameters to be passed to the sendmail binary. These parameters will replace the default value of "-t -i".
;mail.force_extra_parameters =

; Add X-PHP-Originating-Script header (includes script path and uid).
;mail.add_x_header = Off

; Log all mail sent by mail() into a log file.
;mail.log = "C:\xampp\apache\logs\php_mail.log"


📧 Qual e-mail usar no SMTP = smtp.gmail.com

O SMTP = smtp.gmail.com não é um e-mail, é o servidor do Gmail. Você precisa configurar suas credenciais separadamente.
🔧 Configuração completa para Gmail

No arquivo C:\xampp\php\php.ini:

[mail function]
SMTP = smtp.gmail.com
smtp_port = 587
sendmail_from = seuemail@gmail.com
auth_username = seuemail@gmail.com
auth_password = sua_senha_do_gmail
smtp_secure = tls