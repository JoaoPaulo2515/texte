<?php
// lib/Auth.php - Classe de autenticação e gerenciamento de sessão
/**
 * Classe Auth para gerenciamento de autenticação
 * @package SIGE Angola
 * @version 1.0
 */

class Auth
{
    private $db;
    private $sessionName = 'sige_session';
    private $userData = null;

    /**
     * Construtor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        session_name($this->sessionName);
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Tenta fazer login do usuário
     * @param string $email
     * @param string $password
     * @param bool $remember
     * @return array|bool
     */
    public function login($email, $password, $remember = false)
    {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT u.*, e.id as escola_id, e.nome as escola_nome, e.status as escola_status
            FROM usuarios u
            LEFT JOIN escolas e ON e.id = u.escola_id
            WHERE u.email = :email AND u.status = 'ativo'
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['senha'])) {
            // Verificar status da escola se não for super admin
            if ($user['tipo'] !== 'super_admin' && $user['escola_status'] !== 'ativa') {
                return ['error' => 'Escola está inativa ou suspensa'];
            }
            
            // Atualizar último acesso
            $stmt = $conn->prepare("
                UPDATE usuarios SET 
                    ultimo_acesso = NOW(),
                    ultimo_ip = :ip
                WHERE id = :id
            ");
            $stmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':id' => $user['id']
            ]);
            
            // Salvar dados na sessão
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['usuario_tipo'] = $user['tipo'];
            $_SESSION['usuario_foto'] = $user['foto'];
            
            if ($user['escola_id']) {
                $_SESSION['escola_id'] = $user['escola_id'];
                $_SESSION['escola_nome'] = $user['escola_nome'];
            }
            
            // Se lembrar, criar cookie
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_token', $token, time() + 86400 * 30, '/');
                
                $stmt = $conn->prepare("UPDATE usuarios SET remember_token = :token WHERE id = :id");
                $stmt->execute([':token' => $token, ':id' => $user['id']]);
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Login com token de lembrar
     * @return bool
     */
    public function loginWithToken()
    {
        if (isset($_COOKIE['remember_token'])) {
            $token = $_COOKIE['remember_token'];
            
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                SELECT u.* FROM usuarios u
                WHERE u.remember_token = :token AND u.status = 'ativo'
            ");
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario_nome'] = $user['nome'];
                $_SESSION['usuario_email'] = $user['email'];
                $_SESSION['usuario_tipo'] = $user['tipo'];
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se o usuário está logado
     * @return bool
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['usuario_id']);
    }

    /**
     * Verifica se o usuário tem permissão
     * @param string $permissao
     * @return bool
     */
    public function hasPermission($permissao)
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        // Super admin tem todas as permissões
        if ($_SESSION['usuario_tipo'] === 'super_admin') {
            return true;
        }
        
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total
            FROM papel_permissoes pp
            JOIN papeis p ON p.id = pp.papel_id
            JOIN permissoes perm ON perm.id = pp.permissao_id
            WHERE p.tipo = :tipo AND perm.nome = :permissao
        ");
        $stmt->execute([
            ':tipo' => $_SESSION['usuario_tipo'],
            ':permissao' => $permissao
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['total'] > 0;
    }

    /**
     * Retorna os dados do usuário logado
     * @return array|null
     */
    public function getUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        if ($this->userData === null) {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                SELECT u.*, e.nome as escola_nome
                FROM usuarios u
                LEFT JOIN escolas e ON e.id = u.escola_id
                WHERE u.id = :id
            ");
            $stmt->execute([':id' => $_SESSION['usuario_id']]);
            $this->userData = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $this->userData;
    }

    /**
     * Altera a senha do usuário
     * @param string $oldPassword
     * @param string $newPassword
     * @return bool|string
     */
    public function changePassword($oldPassword, $newPassword)
    {
        $user = $this->getUser();
        
        if (!password_verify($oldPassword, $user['senha'])) {
            return 'Senha atual incorreta';
        }
        
        if (strlen($newPassword) < 6) {
            return 'A nova senha deve ter no mínimo 6 caracteres';
        }
        
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
        $stmt->execute([':senha' => $newHash, ':id' => $user['id']]);
        
        return true;
    }

    /**
     * Recupera senha (envia e-mail com link)
     * @param string $email
     * @return bool|string
     */
    public function forgotPassword($email)
    {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT id, nome FROM usuarios WHERE email = :email AND status = 'ativo'");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return 'E-mail não encontrado';
        }
        
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmt = $conn->prepare("
            INSERT INTO recuperacao_senha (usuario_id, token, expira, created_at)
            VALUES (:usuario_id, :token, :expira, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $user['id'],
            ':token' => $token,
            ':expira' => $expira
        ]);
        
        // Enviar e-mail
        $emailObj = new Email();
        $link = APP_URL . '/reset_password.php?token=' . $token;
        $subject = "Recuperação de Senha - SIGE Angola";
        $message = "
            <h2>Olá {$user['nome']}!</h2>
            <p>Recebemos uma solicitação para redefinir sua senha.</p>
            <p>Clique no link abaixo para criar uma nova senha:</p>
            <p><a href='{$link}'>Redefinir Senha</a></p>
            <p>Este link expira em 1 hora.</p>
            <p>Se você não solicitou, ignore este e-mail.</p>
        ";
        
        $emailObj->send($email, $subject, $message);
        
        return true;
    }

    /**
     * Redefine a senha usando token
     * @param string $token
     * @param string $newPassword
     * @return bool|string
     */
    public function resetPassword($token, $newPassword)
    {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT * FROM recuperacao_senha 
            WHERE token = :token AND expira > NOW() AND usado = 0
        ");
        $stmt->execute([':token' => $token]);
        $recuperacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$recuperacao) {
            return 'Token inválido ou expirado';
        }
        
        if (strlen($newPassword) < 6) {
            return 'A senha deve ter no mínimo 6 caracteres';
        }
        
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
        $stmt->execute([':senha' => $newHash, ':id' => $recuperacao['usuario_id']]);
        
        $stmt = $conn->prepare("UPDATE recuperacao_senha SET usado = 1 WHERE id = :id");
        $stmt->execute([':id' => $recuperacao['id']]);
        
        $conn->commit();
        
        return true;
    }

    /**
     * Faz logout do usuário
     */
    public function logout()
    {
        // Remover token de lembrar
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
            
            if ($this->isLoggedIn()) {
                $conn = $this->db->getConnection();
                $stmt = $conn->prepare("UPDATE usuarios SET remember_token = NULL WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['usuario_id']]);
            }
        }
        
        // Destruir sessão
        $_SESSION = [];
        session_destroy();
        
        return true;
    }

    /**
     * Verifica se é super admin
     * @return bool
     */
    public function isSuperAdmin()
    {
        return $this->isLoggedIn() && $_SESSION['usuario_tipo'] === 'super_admin';
    }

    /**
     * Verifica se é admin da escola
     * @return bool
     */
    public function isAdminEscola()
    {
        return $this->isLoggedIn() && $_SESSION['usuario_tipo'] === 'admin_escola';
    }

    /**
     * Verifica se é professor
     * @return bool
     */
    public function isProfessor()
    {
        return $this->isLoggedIn() && $_SESSION['usuario_tipo'] === 'professor';
    }

    /**
     * Verifica se é aluno
     * @return bool
     */
    public function isAluno()
    {
        return $this->isLoggedIn() && $_SESSION['usuario_tipo'] === 'aluno';
    }
}