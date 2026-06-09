<?php
// escola/professor/gerar_excel_notas.php - Gerar Excel das Notas da Turma

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];


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
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "
    SELECT t.*, COUNT(DISTINCT m.estudante_id) as total_alunos
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.id = :turma_id AND t.escola_id = :escola_id
    GROUP BY t.id
";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada');
}

// ============================================
// BUSCAR DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

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
        n.exame_oral,
        n.exame_escrito,
        n.media_final,
        n.status as situacao
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id 
        AND n.disciplina_id = :disciplina_id 
        AND n.ano_letivo_id = :ano_letivo_id
        AND n.bimestre = :bimestre
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':ano_letivo_id' => $ano_letivo_id,
    ':bimestre' => $bimestre
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ESCOLA
// ============================================
$sql_escola = "SELECT nome, nome, logo, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

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

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

// Calcular estatísticas
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

// ============================================
// GERAR EXCEL
// ============================================

// Incluir a biblioteca PhpSpreadsheet
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// Criar nova planilha
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ============================================
// CABEÇALHO
// ============================================

// Logo da escola (se existir)
if (!empty($escola['logo']) && file_exists('../../uploads/escolas/logos/' . $escola['logo'])) {
    // Adicionar imagem (opcional - comentado pois pode dar erro)
    // $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    // $drawing->setName('Logo');
    // $drawing->setDescription('Logo');
    // $drawing->setPath('../../uploads/escolas/logos/' . $escola['logo']);
    // $drawing->setHeight(60);
    // $drawing->setCoordinates('A1');
    // $drawing->setWorksheet($sheet);
}

// Título
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'ESCOLA'));
$sheet->mergeCells('A1:K1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'PAUTA DE NOTAS');
$sheet->mergeCells('A2:K2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', $disciplina['nome'] . ' - ' . $bimestre . 'º Bimestre');
$sheet->mergeCells('A3:K3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Linha em branco
$sheet->setCellValue('A4', '');

// ============================================
// INFORMAÇÕES DA TURMA
// ============================================
$sheet->setCellValue('A5', 'INFORMAÇÕES DA TURMA');
$sheet->mergeCells('A5:K5');
$sheet->getStyle('A5')->getFont()->setBold(true);
$sheet->getStyle('A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A5')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A6', 'Turma:');
$sheet->setCellValue('B6', $turma['ano'] . 'ª ' . $turma['nome']);
$sheet->setCellValue('D6', 'Turno:');
$sheet->setCellValue('E6', ucfirst($turma['turno']));
$sheet->setCellValue('G6', 'Sala:');
$sheet->setCellValue('H6', $turma['sala'] ?: 'Não definida');
$sheet->setCellValue('J6', 'Ano Letivo:');
$sheet->setCellValue('K6', $ano_letivo['ano'] ?? date('Y'));

$sheet->setCellValue('A7', 'Professor:');
$sheet->setCellValue('B7', $professor['professor_nome']);
$sheet->setCellValue('D7', 'Data Emissão:');
$sheet->setCellValue('E7', date('d/m/Y H:i'));
$sheet->setCellValue('G7', 'Total Alunos:');
$sheet->setCellValue('H7', $total_alunos);

// Estilo para as informações
$sheet->getStyle('A6:K7')->getFont()->setSize(10);
$sheet->getStyle('A6')->getFont()->setBold(true);
$sheet->getStyle('D6')->getFont()->setBold(true);
$sheet->getStyle('G6')->getFont()->setBold(true);
$sheet->getStyle('J6')->getFont()->setBold(true);
$sheet->getStyle('A7')->getFont()->setBold(true);
$sheet->getStyle('D7')->getFont()->setBold(true);
$sheet->getStyle('G7')->getFont()->setBold(true);

// Linha em branco
$sheet->setCellValue('A8', '');

// ============================================
// TABELA DE NOTAS
// ============================================
$row = 9;

// Cabeçalho da tabela
$headers = ['Nº', 'Aluno', 'Matrícula', 'MAC', 'NPT', 'Exame Normal', 'Exame Recurso', 'Exame Especial', 'Exame Oral', 'Exame Escrito', 'Média', 'Situação'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

foreach ($headers as $index => $header) {
    $sheet->setCellValue($colunas[$index] . $row, $header);
    $sheet->getStyle($colunas[$index] . $row)->getFont()->setBold(true);
    $sheet->getStyle($colunas[$index] . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
    $sheet->getStyle($colunas[$index] . $row)->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($colunas[$index] . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Dados dos alunos
$row++;
$contador = 1;
foreach ($alunos as $aluno) {
    $sheet->setCellValue('A' . $row, $contador++);
    $sheet->setCellValue('B' . $row, $aluno['nome']);
    $sheet->setCellValue('C' . $row, $aluno['matricula']);
    $sheet->setCellValue('D' . $row, number_format($aluno['mac'] ?? 0, 1));
    $sheet->setCellValue('E' . $row, number_format($aluno['npt'] ?? 0, 1));
    $sheet->setCellValue('F' . $row, number_format($aluno['exame_normal'] ?? 0, 1));
    $sheet->setCellValue('G' . $row, number_format($aluno['exame_recurso'] ?? 0, 1));
    $sheet->setCellValue('H' . $row, number_format($aluno['exame_especial'] ?? 0, 1));
    $sheet->setCellValue('I' . $row, number_format($aluno['exame_oral'] ?? 0, 1));
    $sheet->setCellValue('J' . $row, number_format($aluno['exame_escrito'] ?? 0, 1));
    $sheet->setCellValue('K' . $row, number_format($aluno['media_final'] ?? 0, 1));
    $sheet->setCellValue('L' . $row, getSituacaoTexto($aluno['situacao']));
    
    // Cor da linha baseada na situação
    if ($aluno['situacao'] == 'aprovado') {
        $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
    } elseif ($aluno['situacao'] == 'recuperacao') {
        $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
    } elseif ($aluno['situacao'] == 'reprovado') {
        $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
    }
    
    $row++;
}

// Aplicar bordas na tabela
$lastRow = $row - 1;
$sheet->getStyle('A9:L' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// RESUMO ESTATÍSTICO
// ============================================
$row += 2;

$sheet->setCellValue('A' . $row, 'RESUMO ESTATÍSTICO');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, 'Total de Alunos:');
$sheet->setCellValue('B' . $row, $total_alunos);
$sheet->setCellValue('D' . $row, 'Aprovados:');
$sheet->setCellValue('E' . $row, $total_aprovados . ' (' . ($total_alunos > 0 ? round($total_aprovados / $total_alunos * 100, 1) : 0) . '%)');
$sheet->setCellValue('G' . $row, 'Recuperação:');
$sheet->setCellValue('H' . $row, $total_recuperacao . ' (' . ($total_alunos > 0 ? round($total_recuperacao / $total_alunos * 100, 1) : 0) . '%)');
$sheet->setCellValue('J' . $row, 'Reprovados:');
$sheet->setCellValue('K' . $row, $total_reprovados . ' (' . ($total_alunos > 0 ? round($total_reprovados / $total_alunos * 100, 1) : 0) . '%)');

$row++;
$sheet->setCellValue('A' . $row, 'Média Geral da Turma:');
$sheet->setCellValue('B' . $row, number_format($media_geral, 1) . ' valores');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);

// ============================================
// ASSINATURA
// ============================================
$row += 3;
$sheet->setCellValue('E' . $row, '_________________________');
$sheet->setCellValue('F' . $row, '_________________________');
$sheet->mergeCells('E' . $row . ':F' . $row);

$row++;
$sheet->setCellValue('E' . $row, $professor['professor_nome']);
$sheet->setCellValue('F' . $row, 'Professor(a) Responsável');
$sheet->mergeCells('E' . $row . ':F' . $row);

// ============================================
// RODAPÉ
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'Documento emitido eletronicamente - ' . date('d/m/Y H:i'));
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, $escola['endereco'] ?? '');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, 'Tel: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? ''));
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

// Auto-size das colunas
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ============================================
// SALVAR ARQUIVO
// ============================================

// Nome do arquivo
$nome_arquivo = 'pauta_notas_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $bimestre . 'B_' . date('Ymd') . '.xlsx';

// Configurar headers para download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

// Escrever arquivo
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>