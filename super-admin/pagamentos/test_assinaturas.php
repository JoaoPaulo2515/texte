<?php
require_once __DIR__ . '/../../config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$escola_id = 1; // Altere para o ID da sua escola

$stmt = $conn->prepare("
    SELECT a.id, a.valor, a.tipo_cobranca, a.data_fim, p.nome as plano_nome
    FROM assinaturas a
    JOIN planos p ON p.id = a.plano_id
    WHERE a.escola_id = :escola_id AND a.status = 'ativa'
");
$stmt->execute([':escola_id' => $escola_id]);
$result = $stmt->fetchAll();

echo "<pre>";
print_r($result);
echo "</pre>";
?>