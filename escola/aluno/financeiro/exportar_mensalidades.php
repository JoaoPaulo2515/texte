<?php
// escola/aluno/financeiro/exportar_mensalidades.php

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    die('Acesso negado.');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$tipo = $_GET['tipo'] ?? 'csv';

// Buscar dados das mensalidades
$sql = "SELECT m.*, 
        DATE_FORMAT(m.data_vencimento, '%M/%Y') as mes_ano,
        CASE 
            WHEN m.status = 'pago' THEN 'PAGO'
            WHEN m.data_vencimento < CURDATE() AND m.status != 'pago' THEN 'ATRASADO'
            ELSE 'PENDENTE'
        END as status_texto
        FROM mensalidades m
        WHERE m.aluno_id = :aluno_id AND m.escola_id = :escola_id
        ORDER BY m.data_vencimento ASC";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$mensalidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($tipo == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mensalidades_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Mês/Ano', 'Data Vencimento', 'Data Pagamento', 'Valor', 'Valor Pago', 'Status', 'Dias Atraso']);
    
    foreach ($mensalidades as $m) {
        fputcsv($output, [
            $m['mes_ano'],
            date('d/m/Y', strtotime($m['data_vencimento'])),
            $m['data_pagamento'] ? date('d/m/Y', strtotime($m['data_pagamento'])) : '-',
            number_format($m['valor'], 2, ',', '.'),
            number_format($m['valor_pago'] ?? 0, 2, ',', '.'),
            $m['status_texto'],
            $m['dias_atraso'] ?? 0
        ]);
    }
    
    fclose($output);
    exit;
}
?>