<?php
// lib/Email.php - Classe de envio de e-mails
/**
 * Classe Email para envio de mensagens
 * @package SIGE Angola
 * @version 1.0
 */

class Email
{
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpSecure;
    private $fromEmail;
    private $fromName;
    private $isConfigured = false;
    
    /**
     * Construtor - carrega configurações do sistema
     */
    public function __construct()
    {
        $this->loadConfig();
    }
    
    /**
     * Carrega configurações de e-mail
     */
    private function loadConfig()
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        try {
            $stmt = $conn->query("SELECT * FROM configuracoes_sistema LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $config['enviar_email']) {
                $this->smtpHost = $config['email_host'];
                $this->smtpPort = $config['email_porta'];
                $this->smtpUser = $config['email_usuario'];
                $this->smtpPass = $config['email_senha'];
                $this->smtpSecure = $config['email_seguranca'];
                $this->fromEmail = $config['email_remetente'];
                $this->fromName = $config['nome_sistema'] ?? 'SIGE Angola';
                $this->isConfigured = !empty($this->smtpHost) && !empty($this->smtpUser);
            }
        } catch (Exception $e) {
            // Configurações não carregadas ainda
            $this->isConfigured = false;
        }
    }
    
    /**
     * Envia e-mail usando SMTP
     * @param string|array $to Destinatário(s)
     * @param string $subject Assunto
     * @param string $message Mensagem (HTML)
     * @param array $attachments Anexos
     * @return bool
     */
    public function send($to, $subject, $message, $attachments = [])
    {
        if (!$this->isConfigured) {
            // Se não configurado, salvar na fila
            return $this->saveToQueue($to, $subject, $message, $attachments);
        }
        
        try {
            // Preparar cabeçalhos
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
                'Reply-To: ' . $this->fromEmail,
                'X-Mailer: PHP/' . phpversion()
            ];
            
            // Adicionar CC/BCC se necessário
            if (is_array($to)) {
                $toList = implode(', ', $to);
            } else {
                $toList = $to;
            }
            
            // Template padrão
            $htmlMessage = $this->getTemplate($subject, $message);
            
            // Enviar usando mail()
            if (mail($toList, $subject, $htmlMessage, implode("\r\n", $headers))) {
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            // Salvar na fila em caso de erro
            $this->saveToQueue($to, $subject, $message, $attachments);
            return false;
        }
    }
    
    /**
     * Envia e-mail usando PHPMailer (recomendado)
     * @param string|array $to
     * @param string $subject
     * @param string $message
     * @param array $attachments
     * @return bool
     */
    public function sendPHPMailer($to, $subject, $message, $attachments = [])
    {
        if (!$this->isConfigured) {
            return $this->saveToQueue($to, $subject, $message, $attachments);
        }
        
        try {
            // Verificar se PHPMailer está disponível
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                return $this->send($to, $subject, $message, $attachments);
            }
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configurações do servidor
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->Port = $this->smtpPort;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            
            if ($this->smtpSecure == 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpSecure == 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Remetente e destinatário
            $mail->setFrom($this->fromEmail, $this->fromName);
            
            if (is_array($to)) {
                foreach ($to as $email) {
                    $mail->addAddress($email);
                }
            } else {
                $mail->addAddress($to);
            }
            
            // Conteúdo
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $this->getTemplate($subject, $message);
            $mail->AltBody = strip_tags($message);
            
            // Anexos
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
            
            return $mail->send();
            
        } catch (Exception $e) {
            $this->saveToQueue($to, $subject, $message, $attachments);
            return false;
        }
    }
    
    /**
     * Salva e-mail na fila para envio posterior
     * @param string|array $to
     * @param string $subject
     * @param string $message
     * @param array $attachments
     * @return bool
     */
    private function saveToQueue($to, $subject, $message, $attachments = [])
    {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO email_queue (destinatario, assunto, mensagem, anexos, tentativas, created_at)
                VALUES (:destinatario, :assunto, :mensagem, :anexos, 0, NOW())
            ");
            
            $stmt->execute([
                ':destinatario' => is_array($to) ? implode(',', $to) : $to,
                ':assunto' => $subject,
                ':mensagem' => $message,
                ':anexos' => json_encode($attachments)
            ]);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Processa fila de e-mails
     * @return int Número de e-mails enviados
     */
    public function processQueue()
    {
        if (!$this->isConfigured) {
            return 0;
        }
        
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT * FROM email_queue 
            WHERE status = 'pendente' AND tentativas < 3
            ORDER BY created_at ASC
            LIMIT 10
        ");
        $stmt->execute();
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $enviados = 0;
        
        foreach ($emails as $email) {
            $destinatarios = explode(',', $email['destinatario']);
            $anexos = json_decode($email['anexos'], true) ?: [];
            
            $success = $this->sendPHPMailer($destinatarios, $email['assunto'], $email['mensagem'], $anexos);
            
            $status = $success ? 'enviado' : 'falha';
            $tentativas = $email['tentativas'] + 1;
            
            $stmt = $conn->prepare("
                UPDATE email_queue SET 
                    status = :status, 
                    tentativas = :tentativas,
                    enviado_em = CASE WHEN :status = 'enviado' THEN NOW() ELSE enviado_em END
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $email['id'],
                ':status' => $status,
                ':tentativas' => $tentativas
            ]);
            
            if ($success) {
                $enviados++;
            }
        }
        
        return $enviados;
    }
    
    /**
     * Retorna template HTML padrão
     * @param string $subject
     * @param string $content
     * @return string
     */
    private function getTemplate($subject, $content)
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($subject) . '</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 0;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }
                .header {
                    background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                    border-radius: 10px 10px 0 0;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .content {
                    background: white;
                    padding: 30px 20px;
                    border: 1px solid #ddd;
                    border-top: none;
                    border-radius: 0 0 10px 10px;
                }
                .footer {
                    text-align: center;
                    padding: 20px;
                    font-size: 12px;
                    color: #666;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background: #006B3E;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    margin-top: 15px;
                }
                .info-box {
                    background: #f0f2f5;
                    padding: 15px;
                    border-radius: 8px;
                    margin: 15px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>SIGE Angola</h1>
                    <p>Sistema Integrado de Gestão Escolar</p>
                </div>
                <div class="content">
                    ' . $content . '
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' SIGE Angola - Todos os direitos reservados</p>
                    <p>Este é um e-mail automático, por favor não responda.</p>
                </div>
            </div>
        </body>
        </html>
        ';
    }
    
    /**
     * Envia boas-vindas para nova escola
     * @param string $email
     * @param string $escolaNome
     * @param string $subdominio
     * @param string $senha
     * @return bool
     */
    public function sendWelcomeSchool($email, $escolaNome, $subdominio, $senha)
    {
        $subject = "Bem-vindo ao SIGE Angola - {$escolaNome}";
        $message = "
            <h2>Olá!</h2>
            <p>Sua escola <strong>{$escolaNome}</strong> foi cadastrada com sucesso no SIGE Angola.</p>
            
            <div class='info-box'>
                <strong>Dados de acesso:</strong><br>
                URL: <a href='http://{$subdominio}.sige.ao'>http://{$subdominio}.sige.ao</a><br>
                E-mail: {$email}<br>
                Senha temporária: <strong>{$senha}</strong>
            </div>
            
            <p>Recomendamos alterar sua senha no primeiro acesso.</p>
            
            <a href='http://{$subdominio}.sige.ao' class='btn'>Acessar o Sistema</a>
        ";
        
        return $this->send($email, $subject, $message);
    }
    
    /**
     * Envia notificação de pagamento
     * @param string $email
     * @param string $escolaNome
     * @param float $valor
     * @param string $referente
     * @param string $status
     * @return bool
     */
    public function sendPaymentNotification($email, $escolaNome, $valor, $referente, $status)
    {
        $statusText = $status == 'pago' ? 'confirmado' : 'pendente';
        $statusColor = $status == 'pago' ? '#28a745' : '#ffc107';
        
        $subject = "Pagamento {$statusText} - SIGE Angola";
        $message = "
            <h2>Olá {$escolaNome}!</h2>
            <p>O pagamento referente a <strong>{$referente}</strong> foi {$statusText}.</p>
            
            <div class='info-box'>
                <strong>Detalhes:</strong><br>
                Valor: KZ " . number_format($valor, 2, ',', '.') . "<br>
                Status: <span style='color: {$statusColor}'>{$statusText}</span>
            </div>
            
            " . ($status == 'pendente' ? "
            <p>Para regularizar sua situação, realize o pagamento o mais breve possível.</p>
            <a href='https://sige.ao/pagamentos' class='btn'>Efetuar Pagamento</a>
            " : "
            <p>Obrigado por manter sua assinatura em dia!</p>
            ") . "
        ";
        
        return $this->send($email, $subject, $message);
    }
    
    /**
     * Envia notificação de renovação de assinatura
     * @param string $email
     * @param string $escolaNome
     * @param string $planoNome
     * @param string $novaData
     * @return bool
     */
    public function sendRenewalNotification($email, $escolaNome, $planoNome, $novaData)
    {
        $subject = "Assinatura Renovada - SIGE Angola";
        $message = "
            <h2>Olá {$escolaNome}!</h2>
            <p>Sua assinatura do plano <strong>{$planoNome}</strong> foi renovada com sucesso.</p>
            
            <div class='info-box'>
                <strong>Nova data de expiração:</strong> " . date('d/m/Y', strtotime($novaData)) . "
            </div>
            
            <p>Continue aproveitando todos os recursos do SIGE Angola!</p>
        ";
        
        return $this->send($email, $subject, $message);
    }
    
    /**
     * Envia lembrete de vencimento
     * @param string $email
     * @param string $escolaNome
     * @param float $valor
     * @param string $dataVencimento
     * @return bool
     */
    public function sendDueReminder($email, $escolaNome, $valor, $dataVencimento)
    {
        $subject = "Aviso de Vencimento - SIGE Angola";
        $message = "
            <h2>Olá {$escolaNome}!</h2>
            <p>Seu pagamento está próximo do vencimento.</p>
            
            <div class='info-box'>
                <strong>Detalhes:</strong><br>
                Valor: KZ " . number_format($valor, 2, ',', '.') . "<br>
                Data de vencimento: " . date('d/m/Y', strtotime($dataVencimento)) . "
            </div>
            
            <p>Evite a suspensão do serviço realizando o pagamento.</p>
            <a href='https://sige.ao/pagamentos' class='btn'>Efetuar Pagamento</a>
        ";
        
        return $this->send($email, $subject, $message);
    }
}