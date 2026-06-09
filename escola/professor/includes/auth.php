<?php
// escola/professor/includes/auth.php - Autenticação do Professor

session_start();

function getConnection() {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=sige_angola;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Erro na conexão: " . $e->getMessage());
    }
}

function checkProfessorAuth() {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] != 'professor') {
        header('Location: login.php');
        exit;
    }
    
    return [
        'professor_id' => $_SESSION['professor_id'],
        'escola_id' => $_SESSION['escola_id'],
        'professor_nome' => $_SESSION['usuario_nome']
    ];
}
?>