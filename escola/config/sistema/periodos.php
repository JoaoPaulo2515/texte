<?php
// escola/config/sistema/periodos.php - Períodos Letivos
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
$check = $conn->query("SHOW TABLES LIKE 'escola_periodos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE escola_periodos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            data_inicio DATE,
            data_fim DATE,
            ano_letivo VARCHAR(9),
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Processar ações (similar aos anteriores)
// ... (código similar aos outros arquivos)

// Buscar dados
$periodos = $conn->prepare("SELECT * FROM escola_periodos WHERE escola_id = :escola_id ORDER BY data_inicio DESC");
$periodos->execute([':escola_id' => $escola_id]);
$periodos = $periodos->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- HTML similar aos outros arquivos... -->