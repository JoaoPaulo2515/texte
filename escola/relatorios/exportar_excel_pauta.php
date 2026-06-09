<?php
// escola/relatorios/exportar_excel_pauta.php

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 1;

// Buscar informações
$sql_info = "SELECT t.nome as turma_nome, t.ano as turma_ano, d.nome as disciplina_nome, e.nome as escola_nome
             FROM turmas t, disciplinas d, escolas e
             WHERE t.id = :turma_id AND d.id = :disciplina_id AND e.id = :escola_id";
$stmt_info = $conn->prepare($sql_info);
$stmt_info->execute([':turma_id' => $turma_id, ':disciplina_id' => $disciplina_id, ':escola_id' => $escola_id]);
$info = $stmt_info->fetch(PDO::FETCH_ASSOC);

// Buscar alunos e notas
$sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero, n.media_final
               FROM estudantes e
               INNER JOIN matriculas m ON m.estudante_id = e.id
               LEFT JOIN notas n ON n.estudante_id = e.id AND n.disciplina_id = :disciplina_id AND n.bimestre = :trimestre AND n.ano_letivo_id = :ano_letivo_id
               WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
               ORDER BY e.nome";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':trimestre' => $trimestre,
    ':ano_letivo' => $ano_letivo_id,
    ':ano_letivo_id' => $ano_letivo_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Cabeçalho
$sheet->setCellValue('A1', $info['escola_nome']);
$sheet->setCellValue('A2', 'Pauta de Notas');
$sheet->setCellValue('A3', 'Turma: ' . $info['turma_ano'] . 'ª ' . $info['turma_nome']);
$sheet->setCellValue('A4', 'Disciplina: ' . $info['disciplina_nome']);
$sheet->setCellValue('A5', 'Trimestre: ' . $trimestre . 'º');
$sheet->setCellValue('A6', 'Data: ' . date('d/m/Y H:i:s'));

$sheet->mergeCells('A1:F1');
$sheet->mergeCells('A2:F2');
$sheet->mergeCells('A3:F3');
$sheet->mergeCells('A4:F4');
$sheet->mergeCells('A5:F5');
$sheet->mergeCells('A6:F6');

// Cabeçalhos da tabela
$headers = ['#', 'Matrícula', 'Aluno', 'Sexo', 'Nota (0-20)', 'Status'];
$col = 'A';
$row = 8;
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getColumnDimension($col)->setAutoSize(true);
    $col++;
}

// Estilo cabeçalho
$sheet->getStyle('A8:F8')->getFont()->setBold(true);
$sheet->getStyle('A8:F8')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A8:F8')->getFont()->getColor()->setRGB('FFFFFF');

// Dados
$row++;
foreach ($alunos as $index => $aluno) {
    $nota = $aluno['media_final'] !== null ? $aluno['media_final'] : '';
    $status = '';
    if ($nota !== '') {
        if ($nota >= 14) $status = 'Aprovado';
        elseif ($nota >= 10) $status = 'Exame';
        else $status = 'Reprovado';
    } else {
        $status = 'Sem nota';
    }
    
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $aluno['matricula']);
    $sheet->setCellValue('C' . $row, $aluno['nome']);
    $sheet->setCellValue('D' . $row, $aluno['genero'] == 'masculino' ? 'Masculino' : 'Feminino');
    $sheet->setCellValue('E' . $row, $nota);
    $sheet->setCellValue('F' . $row, $status);
    $row++;
}

// Aplicar bordas
$styleArray = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
];
$sheet->getStyle('A8:F' . ($row - 1))->applyFromArray($styleArray);

// Configurar cabeçalhos para download
$filename = 'pauta_' . $info['turma_nome'] . '_' . $info['disciplina_nome'] . '_' . $trimestre . 't_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>