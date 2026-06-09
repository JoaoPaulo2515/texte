// lib/Email.php - Classe de envio de e-mails com PHPMailer
<?php
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Email {
    private $mail;
    private $config;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->loadConfig();
        $this->setup();
    }
    
    private function loadConfig() {
        // Configurações padrão
        $this->config = [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'noreply@sige.ao',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => 'noreply@sige.ao',
            'from_name' => 'SIGE Angola'
        ];
        
        // Tentar carregar do banco de dados
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $stmt = $conn->query("SELECT * FROM configuracoes_sistema LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $config['enviar_email']) {
                $this->config['host'] = $config['email_host'] ?? $this->config['host'];
                $this->config['port'] = $config['email_porta'] ?? $this->config['port'];
                $this->config['username'] = $config['email_usuario'] ?? $this->config['username'];
                $this->config['password'] = $config['email_senha'] ?? $this->config['password'];
                $this->config['encryption'] = $config['email_seguranca'] ?? $this->config['encryption'];
                $this->config['from_email'] = $config['email_remetente'] ?? $this->config['from_email'];
                $this->config['from_name'] = $config['nome_sistema'] ?? $this->config['from_name'];
            }
        } catch (Exception $e) {
            // Configurações não carregadas ainda
        }
    }
    
    private function setup() {
        try {
            $this->mail->isSMTP();
            $this->mail->Host = $this->config['host'];
            $this->mail->Port = $this->config['port'];
            $this->mail->SMTPAuth = true;
            $this->mail->Username = $this->config['username'];
            $this->mail->Password = $this->config['password'];
            
            if ($this->config['encryption'] == 'tls') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($this->config['encryption'] == 'ssl') {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            
            $this->mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';
        } catch (Exception $e) {
            error_log("Erro ao configurar email: " . $e->getMessage());
        }
    }
    
    public function send($to, $subject, $message, $attachments = []) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to);
            $this->mail->Subject = $subject;
            $this->mail->Body = $message;
            $this->mail->AltBody = strip_tags($message);
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $this->mail->addAttachment($attachment);
                }
            }
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Erro ao enviar email: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendWithAttachment($to, $subject, $message, $filePath, $fileName = null) {
        return $this->send($to, $subject, $message, [$filePath]);
    }
}