<?php
// config/database.php - Configuração e conexão com banco de dados

class Database {
    private $host = 'localhost';
    private $db_name = 'sige_angola';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    private $conn;
    private static $instance = null;
    


 
// config/database.php
 /*
// Função de log simples (caso constants.php não esteja carregado)
if (!function_exists('writeLog')) {
    function writeLog($message, $type = 'info') {
        $logFile = __DIR__ . '/../logs/error.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
        
        // Criar diretório de logs se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}*/

// Resto da classe Database...
    /**
     * Construtor privado para Singleton
     */
    private function __construct() {
        // Configurações serão carregadas do arquivo se existir
        $this->loadConfig();
    }
    
    /**
     * Get instance (Singleton pattern)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Carregar configurações do arquivo se existir
     */
    private function loadConfig() {
        $configFile = __DIR__ . '/db_config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (is_array($config)) {
                $this->host = $config['host'] ?? $this->host;
                $this->db_name = $config['db_name'] ?? $this->db_name;
                $this->username = $config['username'] ?? $this->username;
                $this->password = $config['password'] ?? $this->password;
                $this->charset = $config['charset'] ?? $this->charset;
            }
        }
    }
    
    /**
     * Obter conexão PDO
     */
    public function getConnection() {
        if ($this->conn === null) {
            try {
                $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE utf8mb4_unicode_ci"
                ];
                
                $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            } catch(PDOException $e) {
               // writeLog("Erro de conexão: " . $e->getMessage(), 'error');
                throw new Exception("Erro ao conectar ao banco de dados: " . $e->getMessage());
            }
        }
        
        return $this->conn;
    }
    
    /**
     * Fechar conexão
     */
    public function closeConnection() {
        $this->conn = null;
    }
    
    /**
     * Iniciar transação
     */
    public function beginTransaction() {
        if ($this->conn) {
            return $this->conn->beginTransaction();
        }
        return false;
    }
    
    /**
     * Commit da transação
     */
    public function commit() {
        if ($this->conn) {
            return $this->conn->commit();
        }
        return false;
    }
    
    /**
     * Rollback da transação
     */
    public function rollback() {
        if ($this->conn) {
            return $this->conn->rollback();
        }
        return false;
    }
    
    /**
     * Executar query com prepared statement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            writeLog("Erro na query: " . $e->getMessage() . " - SQL: " . $sql, 'error');
            throw $e;
        }
    }
    
    /**
     * Obter último ID inserido
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
    
    /**
     * Escapar string para segurança
     */
    public function escape($string) {
        return $this->conn->quote($string);
    }
}

// Se o arquivo não for incluído via require, criar função helper
if (!function_exists('getDB')) {
    function getDB() {
        return Database::getInstance();
    }
}