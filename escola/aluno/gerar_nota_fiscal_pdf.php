<?php
// escola/aluno/financeiro/gerar_nota_fiscal_pdf.php - Gerar PDF com FPDF

require_once __DIR__ . '/../../../vendor/fpdf/fpdf.php'; // Ajuste o caminho

// ... (buscar dados igual ao anterior)

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'NOTA FISCAL', 0, 1, 'C');
$pdf->Ln(10);

// Adicionar dados da nota...
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(40, 8, 'Número da Nota:', 0, 0);
$pdf->Cell(0, 8, $nota['numero_nota'], 0, 1);

// ... resto do conteúdo

$pdf->Output('nota_fiscal_' . $nota['numero_nota'] . '.pdf', 'I');
?>