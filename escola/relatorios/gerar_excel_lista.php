<?php
// escola/relatorios/gerar_excel_lista.php - Gerar Excel da lista nominal

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// Verificar se o usuário tem permissão de administrador
$tipos_permitidos = ['super_admin', 'admin_escola', 'administrador', 'diretor'];
if (!in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

require_once 'funcoes_lista.php';

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;


$escola_id = $_SESSION['escola_id'];
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$tipo_lista = isset($_GET['tipo_lista']) ? $_GET['tipo_lista'] : 'completa';

if ($turma_id == 0) {
    die('Turma não selecionada');
}

// Buscar dados
$dados = buscarDadosLista($conn, $escola_id, $turma_id);
$escola_info = buscarDadosEscola($conn, $escola_id);

if (empty($dados['alunos'])) {
    die('Nenhum aluno encontrado nesta turma');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Cabeçalho da escola
$sheet->setCellValue('A1', $escola_info['nome']);
$sheet->setCellValue('A2', 'Lista Nominal de Alunos');
$sheet->setCellValue('A3', 'Turma: ' . $dados['turma_info']['ano'] . 'ª - ' . $dados['turma_info']['nome']);
$sheet->setCellValue('A4', 'Data: ' . date('d/m/Y H:i:s'));

$sheet->mergeCells('A1:I1');
$sheet->mergeCells('A2:I2');
$sheet->mergeCells('A3:I3');
$sheet->mergeCells('A4:I4');

// Estilo do cabeçalho
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

// Estatísticas
$sheet->setCellValue('A6', 'ESTATÍSTICAS DA TURMA');
$sheet->setCellValue('A7', 'Total de Alunos:');
$sheet->setCellValue('B7', $dados['estatisticas']['total']);
$sheet->setCellValue('A8', 'Masculino:');
$sheet->setCellValue('B8', $dados['estatisticas']['masculino']);
$sheet->setCellValue('A9', 'Feminino:');
$sheet->setCellValue('B9', $dados['estatisticas']['feminino']);
$sheet->setCellValue('A10', 'Idade Média:');
$sheet->setCellValue('B10', $dados['estatisticas']['idade_media']);

$sheet->mergeCells('A6:I6');
$sheet->getStyle('A6')->getFont()->setBold(true);

// Linha em branco
$row = 12;

// Cabeçalhos da tabela
$headers = ['#', 'Matrícula', 'Nome Completo', 'Sexo', 'Data Nasc.', 'BI', 'Nome do Pai', 'Nome da Mãe', 'Telefone'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $sheet->getColumnDimension($col)->setAutoSize(true);
    $col++;
}

// Estilo cabeçalho tabela
$sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':I' . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row . ':I' . $row)->getFont()->getColor()->setRGB('FFFFFF');

// Dados
$row++;
foreach ($dados['alunos'] as $index => $aluno) {
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $aluno['matricula']);
    $sheet->setCellValue('C' . $row, $aluno['nome']);
    $sheet->setCellValue('D' . $row, $aluno['sexo'] == 'masculino' ? 'M' : 'F');
    $sheet->setCellValue('E' . $row, date('d/m/Y', strtotime($aluno['data_nascimento'])));
    $sheet->setCellValue('F' . $row, $aluno['bi']);
    $sheet->setCellValue('G' . $row, $aluno['nome_pai']);
    $sheet->setCellValue('H' . $row, $aluno['nome_mae']);
    $sheet->setCellValue('I' . $row, $aluno['telefone']);
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

$sheet->getStyle('A' . ($row- count($dados['alunos']) - 1) . ':I' . ($row-1))->applyFromArray($styleArray);

// Rodapé
$sheet->setCellValue('A' . ($row + 2), 'Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola');
$sheet->mergeCells('A' . ($row + 2) . ':I' . ($row + 2));
$sheet->setCellValue('A' . ($row + 3), $escola_info['endereco'] . ' | Tel: ' . $escola_info['telefone'] . ' | Email: ' . $escola_info['email']);
$sheet->mergeCells('A' . ($row + 3) . ':I' . ($row + 3));

// Configurar cabeçalhos para download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="lista_nominal_' . $dados['turma_info']['nome'] . '_' . date('Y-m-d') . '.xlsx"');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>