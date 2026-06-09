<?php
// escola/professor/relatorios/gerar_excel_mini_pautas.php - Gerar Excel das Mini Pautas

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR PHPSPREADSHEET
// ============================================
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($turma_id <= 0 || $disciplina_id <= 0) {
    die('Parâmetros inválidos');
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA DISCIPLINA
// ============================================
$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$sql_alunos = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_final,
        n.status as situacao
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id 
        AND n.disciplina_id = :disciplina_id 
        AND n.bimestre = :bimestre 
        AND n.ano_letivo_id = :ano_letivo_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':bimestre' => $bimestre,
    ':ano_letivo_id' => $ano_letivo_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao'] == 'reprovado') $total_reprovados++;
    
    if ($aluno['media_final'] > 0) {
        $soma_notas += $aluno['media_final'];
    }
}
$media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getSituacaoTexto($situacao) {
    switch ($situacao) {
        case 'aprovado': return 'Aprovado';
        case 'recuperacao': return 'Recuperação';
        case 'reprovado': return 'Reprovado';
        default: return 'Pendente';
    }
}

// ============================================
// CRIAR PLANILHA EXCEL
// ============================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Mini Pauta');

// ============================================
// CABEÇALHO
// ============================================
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->mergeCells('A1:G1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'MINI PAUTA DE NOTAS');
$sheet->mergeCells('A2:G2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . htmlspecialchars($disciplina['nome']) . ' | ' . $bimestre . 'º BIMESTRE');
$sheet->mergeCells('A3:G3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Linha em branco
$sheet->setCellValue('A4', '');

// ============================================
// INFORMAÇÕES DA TURMA
// ============================================
$sheet->setCellValue('A5', 'INFORMAÇÕES DA TURMA');
$sheet->mergeCells('A5:G5');
$sheet->getStyle('A5')->getFont()->setBold(true);
$sheet->getStyle('A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A5')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A6', 'Turma:');
$sheet->setCellValue('B6', $turma['ano'] . 'ª ' . $turma['nome']);
$sheet->setCellValue('D6', 'Turno:');
$sheet->setCellValue('E6', ucfirst($turma['turno']));
$sheet->setCellValue('F6', 'Sala:');
$sheet->setCellValue('G6', $turma['sala'] ?: 'Não definida');

$sheet->setCellValue('A7', 'Disciplina:');
$sheet->setCellValue('B7', htmlspecialchars($disciplina['nome']));
$sheet->setCellValue('D7', 'Código:');
$sheet->setCellValue('E7', htmlspecialchars($disciplina['codigo'] ?? '-'));
$sheet->setCellValue('F7', 'Ano Letivo:');
$sheet->setCellValue('G7', $ano_letivo_ano);

$sheet->setCellValue('A8', 'Professor:');
$sheet->setCellValue('B8', htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('D8', 'Data Emissão:');
$sheet->setCellValue('E8', date('d/m/Y H:i:s'));

// Estilo para as informações
$sheet->getStyle('A6:G8')->getFont()->setSize(10);
$sheet->getStyle('A6')->getFont()->setBold(true);
$sheet->getStyle('D6')->getFont()->setBold(true);
$sheet->getStyle('F6')->getFont()->setBold(true);
$sheet->getStyle('A7')->getFont()->setBold(true);
$sheet->getStyle('D7')->getFont()->setBold(true);
$sheet->getStyle('F7')->getFont()->setBold(true);
$sheet->getStyle('A8')->getFont()->setBold(true);
$sheet->getStyle('D8')->getFont()->setBold(true);

// Linha em branco
$sheet->setCellValue('A9', '');

// ============================================
// ESTATÍSTICAS
// ============================================
$sheet->setCellValue('A10', 'ESTATÍSTICAS');
$sheet->mergeCells('A10:G10');
$sheet->getStyle('A10')->getFont()->setBold(true);
$sheet->getStyle('A10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A10')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A11', 'Total de Alunos:');
$sheet->setCellValue('B11', $total_alunos);
$sheet->setCellValue('D11', 'Aprovados:');
$sheet->setCellValue('E11', $total_aprovados . ' (' . $percentual_aprovacao . '%)');
$sheet->setCellValue('F11', 'Recuperação:');
$sheet->setCellValue('G11', $total_recuperacao);

$sheet->setCellValue('A12', 'Reprovados:');
$sheet->setCellValue('B12', $total_reprovados);
$sheet->setCellValue('D12', 'Média Geral:');
$sheet->setCellValue('E12', $media_geral);

$sheet->getStyle('A11:G12')->getFont()->setSize(10);
$sheet->getStyle('A11')->getFont()->setBold(true);
$sheet->getStyle('D11')->getFont()->setBold(true);
$sheet->getStyle('F11')->getFont()->setBold(true);
$sheet->getStyle('A12')->getFont()->setBold(true);
$sheet->getStyle('D12')->getFont()->setBold(true);

// ============================================
// TABELA DE NOTAS
// ============================================
$row = 14;

$sheet->setCellValue('A' . $row, 'LISTA DE NOTAS');
$sheet->mergeCells('A' . $row . ':G' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$headers = ['#', 'Aluno', 'Matrícula', 'MAC', 'NPT', 'Média', 'Situação'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

foreach ($headers as $index => $header) {
    $sheet->setCellValue($colunas[$index] . $row, $header);
    $sheet->getStyle($colunas[$index] . $row)->getFont()->setBold(true);
    $sheet->getStyle($colunas[$index] . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
    $sheet->getStyle($colunas[$index] . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$row++;
$contador = 1;
foreach ($alunos as $aluno) {
    $sheet->setCellValue('A' . $row, $contador++);
    $sheet->setCellValue('B' . $row, htmlspecialchars($aluno['nome']));
    $sheet->setCellValue('C' . $row, htmlspecialchars($aluno['matricula']));
    $sheet->setCellValue('D' . $row, number_format($aluno['mac'] ?? 0, 1));
    $sheet->setCellValue('E' . $row, number_format($aluno['npt'] ?? 0, 1));
    $sheet->setCellValue('F' . $row, number_format($aluno['media_final'] ?? 0, 1));
    $sheet->setCellValue('G' . $row, getSituacaoTexto($aluno['situacao']));
    
    // Cor da linha baseada na situação
    if ($aluno['situacao'] == 'aprovado') {
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
    } elseif ($aluno['situacao'] == 'recuperacao') {
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
    } elseif ($aluno['situacao'] == 'reprovado') {
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
    }
    
    $row++;
}

// Aplicar bordas
$lastRow = $row - 1;
$sheet->getStyle('A' . ($row - count($alunos) - 1) . ':G' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// RODAPÉ
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'Documento emitido eletronicamente pelo SIGE Angola - ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A' . $row . ':G' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, $escola['endereco'] ?? '');
$sheet->mergeCells('A' . $row . ':G' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, 'Tel: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? ''));
$sheet->mergeCells('A' . $row . ':G' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

// ============================================
// ASSINATURA
// ============================================
$row += 2;
$sheet->setCellValue('D' . $row, '_________________________');
$sheet->setCellValue('E' . $row, '_________________________');
$sheet->mergeCells('D' . $row . ':E' . $row);

$row++;
$sheet->setCellValue('D' . $row, htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('E' . $row, 'Professor(a) Responsável');
$sheet->mergeCells('D' . $row . ':E' . $row);

// Auto-size das colunas
foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ============================================
// SALVAR ARQUIVO
// ============================================
$nome_arquivo = 'mini_pauta_' . $turma['ano'] . '_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.xlsx';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>