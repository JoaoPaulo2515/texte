<?php
// escola/aluno/financeiro/exportar_excel.php

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    die('Acesso negado.');
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Buscar pagamentos
$sql = "SELECT p.*, 
        CASE 
            WHEN p.tipo_pagamento = 'mensalidade' THEN 'Mensalidade'
            WHEN p.tipo_pagamento = 'matricula' THEN 'Matrícula'
            WHEN p.tipo_pagamento = 'certificado' THEN 'Certificado'
            ELSE p.tipo_pagamento
        END as tipo_formatado
        FROM pagamentos p
        WHERE p.assinatura_id = :aluno_id 
        AND p.escola_id = :escola_id
        AND p.status IN ('pago', 'confirmado')
        ORDER BY p.data_pagamento DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Configurar headers para download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="relatorio_pagamentos_' . date('Ymd') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Gerar HTML para Excel
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Relatório de Pagamentos</title>';
echo '<style>';
echo 'th { background: #006B3E; color: white; }';
echo 'td { border: 1px solid #ddd; }';
echo '</style>';
echo '</head>';
echo '<body>';
echo '<h2>RELATÓRIO DE PAGAMENTOS</h2>';
echo '<p><strong>Aluno:</strong> ' . htmlspecialchars($aluno_nome) . '</p>';
echo '<p><strong>Matrícula:</strong> ' . $aluno_matricula . '</p>';
echo '<p><strong>Data de Emissão:</strong> ' . date('d/m/Y H:i:s') . '</p>';
echo '<table border="1">';
echo '<tr>';
echo '<th>Data</th>';
echo '<th>Tipo</th>';
echo '<th>Descrição</th>';
echo '<th>Valor</th>';
echo '<th>Forma de Pagamento</th>';
echo '<th>Fatura</th>';
echo '<th>Status</th>';
echo '</tr>';

$total = 0;
foreach ($pagamentos as $pg) {
    $total += $pg['valor'];
    echo '<tr>';
    echo '<td>' . date('d/m/Y', strtotime($pg['data_pagamento'])) . '</td>';
    echo '<td>' . htmlspecialchars($pg['tipo_formatado']) . '</td>';
    echo '<td>' . htmlspecialchars($pg['referente'] ?? '-') . '</td>';
    echo '<td align="right">' . number_format($pg['valor'], 2, ',', '.') . ' Kz</td>';
    echo '<td>' . ucfirst($pg['metodo_pagamento'] ?? '-') . '</td>';
    echo '<td>' . ($pg['numero_fatura'] ?? '-') . '</td>';
    echo '<td>' . ucfirst($pg['status']) . '</td>';
    echo '</tr>';
}

echo '<tr style="background: #f0f0f0; font-weight: bold;">';
echo '<td colspan="3" align="right">TOTAL:</td>';
echo '<td align="right">' . number_format($total, 2, ',', '.') . ' Kz</td>';
echo '<td colspan="3"></td>';
echo '</tr>';
echo '</table>';
echo '<p><small>Documento emitido por SIGE Angola - Sistema de Gestão Escolar</small></p>';
echo '<p><small>Data de emissão: ' . date('d/m/Y H:i:s') . '</small></p>';
echo '</body>';
echo '</html>';
exit;
?>