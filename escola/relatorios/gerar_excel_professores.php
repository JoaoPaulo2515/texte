<?php
// escola/relatorios/gerar_excel_professores.php - Gerar Excel do Relatório de Professores

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

// ============================================
// RECEBER FILTROS
// ============================================
$status_filtro = $_GET['status'] ?? 'todos';
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$search = $_GET['search'] ?? '';

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROFESSORES COM FILTROS
// ============================================
$sql_professores = "SELECT p.nome, p.bi, p.email, p.telefone, p.endereco, p.status,
                    COUNT(DISTINCT pdt.turma_id) as total_turmas,
                    GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas_nomes
                    FROM funcionarios p
                    LEFT JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                    LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
                    WHERE p.escola_id = :escola_id  and p.tipo_funcionario='professor'";

$params = [':escola_id' => $escola_id];

if ($status_filtro != 'todos') {
    $sql_professores .= " AND p.status = :status";
    $params[':status'] = $status_filtro;
}

if ($disciplina_id > 0) {
    $sql_professores .= " AND pdt.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}

if (!empty($search)) {
    $sql_professores .= " AND (p.nome LIKE :search_nome OR p.email LIKE :search_email OR p.telefone LIKE :search_telefone OR p.bi LIKE :search_bi)";
    $search_value = "%$search%";
    $params[':search_nome'] = $search_value;
    $params[':search_email'] = $search_value;
    $params[':search_telefone'] = $search_value;
    $params[':search_bi'] = $search_value;
}

$sql_professores .= " GROUP BY p.id ORDER BY p.nome";

$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute($params);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// GERAR EXCEL
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Cabeçalho da escola
$sheet->setCellValue('A1', $escola_info['nome']);
$sheet->setCellValue('A2', 'Relatório de Professores');
$sheet->setCellValue('A3', 'Gerado em: ' . date('d/m/Y H:i:s'));

// Filtros aplicados
$row = 5;
$sheet->setCellValue('A' . $row, 'FILTROS APLICADOS:');
$row++;
if ($status_filtro != 'todos') {
    $sheet->setCellValue('A' . $row, 'Status: ' . ($status_filtro == 'ativo' ? 'Ativos' : 'Inativos'));
    $row++;
}
if ($disciplina_id > 0) {
    $sql_disc = "SELECT nome FROM disciplinas WHERE id = :id";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':id' => $disciplina_id]);
    $disciplina_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
    $sheet->setCellValue('A' . $row, 'Disciplina: ' . $disciplina_nome);
    $row++;
}
if (!empty($search)) {
    $sheet->setCellValue('A' . $row, 'Pesquisa: ' . $search);
    $row++;
}

$row += 2;

// Cabeçalhos da tabela
$headers = ['#', 'Nome', 'BI', 'Email', 'Telefone', 'Endereço', 'Disciplinas', 'Turmas', 'Status'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getColumnDimension($col)->setAutoSize(true);
    $col++;
}

// Estilo cabeçalho
$sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':I' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row . ':I' . $row)->getFont()->getColor()->setRGB('FFFFFF');

// Dados
$row++;
foreach ($professores as $index => $professor) {
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $professor['nome']);
    $sheet->setCellValue('C' . $row, $professor['bi'] ?: '---');
    $sheet->setCellValue('D' . $row, $professor['email'] ?: '---');
    $sheet->setCellValue('E' . $row, $professor['telefone'] ?: '---');
    $sheet->setCellValue('F' . $row, $professor['endereco'] ?: '---');
    $sheet->setCellValue('G' . $row, $professor['disciplinas_nomes'] ?: '---');
    $sheet->setCellValue('H' . $row, $professor['total_turmas']);
    $sheet->setCellValue('I' . $row, ucfirst($professor['status']));
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
$sheet->getStyle('A6:I' . ($row - 1))->applyFromArray($styleArray);

// Rodapé
$row += 2;
$sheet->setCellValue('A' . $row, 'Total de registros: ' . count($professores) . ' professores');
$sheet->mergeCells('A' . $row . ':I' . $row);

// Configurar cabeçalhos para download
$filename = 'relatorio_professores_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>