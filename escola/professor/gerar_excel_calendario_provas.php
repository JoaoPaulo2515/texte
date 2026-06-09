<?php
// escola/professor/gerar_excel_calendario_provas.php - Gerar Excel do Calendário de Provas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// CARREGAR PHPSPREADSHEET
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// PARÂMETROS
// ============================================
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$status = isset($_GET['status']) ? $_GET['status'] : 'todas';

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROVAS DO CALENDÁRIO
// ============================================
$sql_provas = "
    SELECT 
        cp.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo
    FROM calendario_provas cp
    INNER JOIN turmas t ON t.id = cp.turma_id
    INNER JOIN disciplinas d ON d.id = cp.disciplina_id
    WHERE cp.professor_id = :professor_id
    AND cp.ano_letivo_id = :ano_letivo_id
";

if ($bimestre > 0) {
    $sql_provas .= " AND cp.bimestre = :bimestre";
}
if ($turma_id > 0) {
    $sql_provas .= " AND cp.turma_id = :turma_id";
}
if ($mes > 0 && $mes <= 12) {
    $sql_provas .= " AND MONTH(cp.data_prova) = :mes AND YEAR(cp.data_prova) = :ano";
}
if ($status != 'todas') {
    $sql_provas .= " AND cp.status = :status";
}

$sql_provas .= " ORDER BY cp.data_prova ASC, cp.horario ASC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':professor_id' => $professor_id,
    ':ano_letivo_id' => $ano_letivo_id
];
if ($bimestre > 0) {
    $params[':bimestre'] = $bimestre;
}
if ($turma_id > 0) {
    $params[':turma_id'] = $turma_id;
}
if ($mes > 0 && $mes <= 12) {
    $params[':mes'] = $mes;
    $params[':ano'] = $ano;
}
if ($status != 'todas') {
    $params[':status'] = $status;
}
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_provas = count($provas);
$provas_agendadas = 0;
$provas_realizadas = 0;
$provas_canceladas = 0;
$provas_por_bimestre = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

foreach ($provas as $prova) {
    switch ($prova['status']) {
        case 'agendada': $provas_agendadas++; break;
        case 'realizada': $provas_realizadas++; break;
        case 'cancelada': $provas_canceladas++; break;
    }
    if (isset($provas_por_bimestre[$prova['bimestre']])) {
        $provas_por_bimestre[$prova['bimestre']]++;
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

function getStatusTexto($status) {
    switch ($status) {
        case 'agendada': return 'Agendada';
        case 'realizada': return 'Realizada';
        case 'cancelada': return 'Cancelada';
        default: return '-';
    }
}

function getTipoTexto($tipo) {
    switch ($tipo) {
        case 'prova': return 'Prova';
        case 'teste': return 'Teste';
        case 'trabalho': return 'Trabalho';
        case 'recuperacao': return 'Recuperação';
        case 'exame': return 'Exame';
        default: return '-';
    }
}

// ============================================
// CRIAR PLANILHA EXCEL
// ============================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Calendário de Provas');

// ============================================
// CABEÇALHO
// ============================================
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->mergeCells('A1:J1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'CALENDÁRIO DE PROVAS');
$sheet->mergeCells('A2:J2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', 'Professor: ' . htmlspecialchars($professor['professor_nome']));
$sheet->mergeCells('A3:J3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A4', 'Ano Letivo: ' . $ano_letivo_ano);
$sheet->mergeCells('A4:J4');
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A5', 'Data Emissão: ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A5:J5');
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ============================================
// FILTROS APLICADOS
// ============================================
$row = 7;
$sheet->setCellValue('A' . $row, 'FILTROS APLICADOS');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$filtros = [];
if ($bimestre > 0) $filtros[] = 'Bimestre: ' . $bimestre . 'º';
if ($turma_id > 0) {
    $sql_turma = "SELECT nome, ano FROM turmas WHERE id = :id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    $filtros[] = 'Turma: ' . ($turma['ano'] ?? '') . 'ª ' . ($turma['nome'] ?? '');
}
if ($mes > 0) {
    $meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $filtros[] = 'Mês: ' . $meses[$mes - 1];
}
if ($status != 'todas') $filtros[] = 'Status: ' . getStatusTexto($status);

$sheet->setCellValue('A' . $row, implode(' | ', $filtros) ?: 'Todos os registros');
$sheet->mergeCells('A' . $row . ':J' . $row);

// ============================================
// ESTATÍSTICAS
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'ESTATÍSTICAS');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, 'Total de Provas:');
$sheet->setCellValue('B' . $row, $total_provas);
$sheet->setCellValue('D' . $row, 'Agendadas:');
$sheet->setCellValue('E' . $row, $provas_agendadas);
$sheet->setCellValue('G' . $row, 'Realizadas:');
$sheet->setCellValue('H' . $row, $provas_realizadas);
$sheet->setCellValue('I' . $row, 'Canceladas:');
$sheet->setCellValue('J' . $row, $provas_canceladas);

$row++;
$sheet->setCellValue('A' . $row, 'Distribuição por Bimestre:');
$sheet->setCellValue('C' . $row, '1º Bim: ' . $provas_por_bimestre[1]);
$sheet->setCellValue('E' . $row, '2º Bim: ' . $provas_por_bimestre[2]);
$sheet->setCellValue('G' . $row, '3º Bim: ' . $provas_por_bimestre[3]);
$sheet->setCellValue('I' . $row, '4º Bim: ' . $provas_por_bimestre[4]);

// ============================================
// TABELA DE PROVAS
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'LISTA DE PROVAS');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$headers = ['#', 'Data', 'Horário', 'Disciplina', 'Código', 'Turma', 'Título', 'Tipo', 'Bimestre', 'Status'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

foreach ($headers as $index => $header) {
    $sheet->setCellValue($colunas[$index] . $row, $header);
    $sheet->getStyle($colunas[$index] . $row)->getFont()->setBold(true);
    $sheet->getStyle($colunas[$index] . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
    $sheet->getStyle($colunas[$index] . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$row++;
$contador = 1;
foreach ($provas as $prova) {
    $sheet->setCellValue('A' . $row, $contador++);
    $sheet->setCellValue('B' . $row, formatarData($prova['data_prova']));
    $sheet->setCellValue('C' . $row, formatarHorario($prova['horario']));
    $sheet->setCellValue('D' . $row, htmlspecialchars($prova['disciplina_nome']));
    $sheet->setCellValue('E' . $row, htmlspecialchars($prova['disciplina_codigo'] ?? '-'));
    $sheet->setCellValue('F' . $row, $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']));
    $sheet->setCellValue('G' . $row, htmlspecialchars($prova['titulo']));
    $sheet->setCellValue('H' . $row, getTipoTexto($prova['tipo']));
    $sheet->setCellValue('I' . $row, $prova['bimestre'] . 'º');
    $sheet->setCellValue('J' . $row, getStatusTexto($prova['status']));
    
    // Cor da linha baseada no status
    $cor = '';
    switch ($prova['status']) {
        case 'agendada': $cor = 'FFF3CD'; break;
        case 'realizada': $cor = 'D4EDDA'; break;
        case 'cancelada': $cor = 'F8D7DA'; break;
    }
    if ($cor) {
        $sheet->getStyle('A' . $row . ':J' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($cor);
    }
    
    $row++;
}

// Aplicar bordas
$lastRow = $row - 1;
$sheet->getStyle('A' . ($row - count($provas) - 1) . ':J' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// OBSERVAÇÕES GERAIS (se houver)
// ============================================
$temObservacao = false;
foreach ($provas as $prova) {
    if (!empty($prova['observacoes']) || !empty($prova['conteudo'])) {
        $temObservacao = true;
        break;
    }
}

if ($temObservacao) {
    $row += 2;
    $sheet->setCellValue('A' . $row, 'OBSERVAÇÕES E CONTEÚDO DAS PROVAS');
    $sheet->mergeCells('A' . $row . ':J' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
    $sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');
    
    $row++;
    $sheet->setCellValue('A' . $row, 'Disciplina');
    $sheet->setCellValue('C' . $row, 'Turma');
    $sheet->setCellValue('E' . $row, 'Conteúdo');
    $sheet->setCellValue('H' . $row, 'Observações');
    $sheet->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
    
    $row++;
    foreach ($provas as $prova) {
        if (!empty($prova['conteudo']) || !empty($prova['observacoes'])) {
            $sheet->setCellValue('A' . $row, htmlspecialchars($prova['disciplina_nome']));
            $sheet->setCellValue('C' . $row, $prova['turma_ano'] . 'ª ' . htmlspecialchars($prova['turma_nome']));
            $sheet->setCellValue('E' . $row, htmlspecialchars(substr($prova['conteudo'] ?? '', 0, 200)));
            $sheet->setCellValue('H' . $row, htmlspecialchars(substr($prova['observacoes'] ?? '', 0, 200)));
            $row++;
        }
    }
}

// ============================================
// RODAPÉ
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'Documento emitido eletronicamente pelo SIGE Angola - ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, $escola['endereco'] ?? '');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

$row++;
$sheet->setCellValue('A' . $row, 'Tel: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? ''));
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(9);

// ============================================
// AUTO-SIZE DAS COLUNAS
// ============================================
foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ============================================
// SALVAR ARQUIVO
// ============================================
$nome_arquivo = 'calendario_provas_' . $professor['professor_nome'] . '_' . date('Ymd_His') . '.xlsx';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>