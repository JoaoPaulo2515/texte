<?php
// escola/config/sistema/feriados.php - Feriados
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar e criar tabela
$check = $conn->query("SHOW TABLES LIKE 'escola_feriados'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_feriados (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            data DATE NOT NULL,
            tipo ENUM('nacional', 'provincial', 'municipal', 'escolar') DEFAULT 'nacional',
            descricao TEXT,
            ano INT,
            recorrente ENUM('sim', 'nao') DEFAULT 'nao',
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Buscar feriados
$ano_atual = date('Y');
$feriados = $conn->prepare("
    SELECT * FROM escola_feriados 
    WHERE escola_id = :escola_id AND (ano = :ano OR recorrente = 'sim')
    ORDER BY data ASC
");
$feriados->execute([':escola_id' => $escola_id, ':ano' => $ano_atual]);
$feriados = $feriados->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- HTML similar... -->