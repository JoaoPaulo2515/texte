<?php
// lib/Database.php - Classe de conexão com banco de dados
/**
 * Classe Database para gerenciamento de conexão PDO
 * @package SIGE Angola
 * @version 1.0
 */

class Database
{
    private static $instance = null;
    private $conn;
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset = 'utf8mb4';

    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        $this->loadConfig();
        $this->connect();
    }

    /**
     * Carrega configurações do banco de dados
     */
    private function loadConfig()
    {
        $configFile = __DIR__ . '/../config/db_config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            $this->host = $config['host'] ?? 'localhost';
            $this->dbname = $config['dbname'] ?? 'sige_angola';
            $this->username = $config['username'] ?? 'root';
            $this->password = $config['password'] ?? '';
        } else {
            // Configurações padrão para Angola
            $this->host = 'localhost';
            $this->dbname = 'sige_angola';
            $this->username = 'root';
            $this->password = '';
        }
    }

    /**
     * Estabelece conexão com o banco de dados
     */
    private function connect()
    {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            die("Erro ao conectar ao banco de dados: " . $e->getMessage());
        }
    }

    /**
     * Retorna a instância única da classe (Singleton)
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retorna a conexão PDO
     * @return PDO
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * Executa uma query preparada
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public function query($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Retorna o último ID inserido
     * @return int
     */
    public function lastInsertId()
    {
        return $this->conn->lastInsertId();
    }

    /**
     * Inicia uma transação
     * @return bool
     */
    public function beginTransaction()
    {
        return $this->conn->beginTransaction();
    }

    /**
     * Confirma uma transação
     * @return bool
     */
    public function commit()
    {
        return $this->conn->commit();
    }

    /**
     * Desfaz uma transação
     * @return bool
     */
    public function rollback()
    {
        return $this->conn->rollback();
    }

    /**
     * Escapa uma string para uso em queries
     * @param string $string
     * @return string
     */
    public function escape($string)
    {
        return $this->conn->quote($string);
    }

    /**
     * Retorna a conexão PDO original
     * @return PDO
     */
    public function getPdo()
    {
        return $this->conn;
    }
}