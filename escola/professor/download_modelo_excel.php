<?php
// escola/professor/download_modelo_excel.php

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Cabeçalho
$sheet->setCellValue('A1', 'Enunciado');
$sheet->setCellValue('B1', 'Tipo');
$sheet->setCellValue('C1', 'Pontuação');
$sheet->setCellValue('D1', 'Alternativa A');
$sheet->setCellValue('E1', 'Alternativa B');
$sheet->setCellValue('F1', 'Alternativa C');
$sheet->setCellValue('G1', 'Alternativa D');
$sheet->setCellValue('H1', 'Alternativa E');
$sheet->setCellValue('I1', 'Alternativa Correta (0-4)');
$sheet->setCellValue('J1', 'Dica');
$sheet->setCellValue('K1', 'URL da Imagem');
$sheet->setCellValue('L1', 'URL do Vídeo');

// Estilo do cabeçalho
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '006B3E']]
];
$sheet->getStyle('A1:L1')->applyFromArray($headerStyle);

// Exemplo 1: Múltipla Escolha
$sheet->setCellValue('A2', 'Qual é a capital de Angola?');
$sheet->setCellValue('B2', 'multipla_escolha');
$sheet->setCellValue('C2', 2.00);
$sheet->setCellValue('D2', 'Luanda');
$sheet->setCellValue('E2', 'Benguela');
$sheet->setCellValue('F2', 'Huambo');
$sheet->setCellValue('G2', 'Lubango');
$sheet->setCellValue('H2', 'Namibe');
$sheet->setCellValue('I2', 0);
$sheet->setCellValue('J2', 'A capital está localizada no litoral');

// Exemplo 2: Verdadeiro/Falso
$sheet->setCellValue('A3', 'A cidade de Luanda é a capital de Angola.');
$sheet->setCellValue('B3', 'verdadeiro_falso');
$sheet->setCellValue('C3', 1.00);
$sheet->setCellValue('I3', 0);
$sheet->setCellValue('J3', 'Pergunta básica sobre geografia de Angola');

// Exemplo 3: Dissertativa
$sheet->setCellValue('A4', 'Explique a importância da independência de Angola.');
$sheet->setCellValue('B4', 'dissertativa');
$sheet->setCellValue('C4', 5.00);
$sheet->setCellValue('J4', 'Resposta deve abordar aspectos históricos e culturais');

// Ajustar largura das colunas
foreach(range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="modelo_questoes.xlsx"');
$writer->save('php://output');
exit;
?>