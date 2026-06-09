<?php
// escola/relatorios/exportar_excel_caderneta.php - Exportar Excel da Caderneta

require_once __DIR__ . '/../../config/database.php';
session_start();

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
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;

if ($turma_id == 0 || $disciplina_id == 0) {
    die('Parâmetros inválidos');
}

// Buscar ano letivo
if ($ano_letivo_id == 0) {
    $sql_ano = "SELECT id FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':escola_id' => $escola_id]);
    $ano = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_id = $ano['id'] ?? 1;
}

// Buscar informações
$sql_turma = "SELECT nome, ano, turno FROM turmas WHERE id = :id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':id' => $turma_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

$sql_disciplina = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

$sql_escola = "SELECT nome FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Funções auxiliares
function isClasseExame($ano_turma) {
    $classes_exame = [6, 9, 12];
    return in_array($ano_turma, $classes_exame);
}

function isLinguagem($disciplina_nome) {
    $linguagens = ['Português', 'Inglês', 'Língua Portuguesa', 'Língua Inglesa'];
    $disciplina_lower = strtolower($disciplina_nome);
    foreach ($linguagens as $ling) {
        if (strpos($disciplina_lower, strtolower($ling)) !== false) {
            return true;
        }
    }
    return false;
}

$is_exame_classe = isClasseExame($turma['ano']);
$is_linguagem = isLinguagem($disciplina['nome']);

// Buscar alunos e notas
$sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero,
                      n.mac, n.npt, n.exame_normal, n.exame_oral, n.exame_escrito, n.media_final
               FROM estudantes e
               INNER JOIN matriculas m ON m.estudante_id = e.id
               LEFT JOIN notas n ON n.estudante_id = e.id 
                    AND n.disciplina_id = :disciplina_id 
                    AND n.bimestre = :trimestre
                    AND n.ano_letivo_id = :ano_letivo_id
               WHERE m.turma_id = :turma_id 
                    AND m.status = 'ativa' 
                    AND m.ano_letivo = :ano_letivo
               ORDER BY e.nome";

$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([
    ':disciplina_id' => $disciplina_id,
    ':trimestre' => $trimestre,
    ':ano_letivo_id' => $ano_letivo_id,
    ':ano_letivo' => $ano_letivo_id,
    ':turma_id' => $turma_id
]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Calcular médias
foreach ($alunos as &$aluno) {
    $mac = $aluno['mac'] !== null ? (float)$aluno['mac'] : null;
    $npt = $aluno['npt'] !== null ? (float)$aluno['npt'] : null;
    $exame_normal = $aluno['exame_normal'] !== null ? (float)$aluno['exame_normal'] : null;
    $exame_oral = $aluno['exame_oral'] !== null ? (float)$aluno['exame_oral'] : null;
    $exame_escrita = $aluno['exame_escrito'] !== null ? (float)$aluno['exame_escrito'] : null;
    $media_final = $aluno['media_final'] !== null ? (float)$aluno['media_final'] : null;
    
    if ($media_final === null) {
        if ($is_exame_classe && $trimestre == 3) {
            if ($is_linguagem) {
                $valores = [];
                if ($mac !== null) $valores[] = $mac;
                if ($exame_oral !== null) $valores[] = $exame_oral;
                if ($exame_escrita !== null) $valores[] = $exame_escrita;
                $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
            } else {
                $valores = [];
                if ($mac !== null) $valores[] = $mac;
                if ($exame_normal !== null) $valores[] = $exame_normal;
                $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
            }
        } else {
            $valores = [];
            if ($mac !== null) $valores[] = $mac;
            if ($npt !== null) $valores[] = $npt;
            $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
        }
    }
    
    $aluno['media_final_calculada'] = $media_final;
    
    if ($media_final !== null && $media_final > 0) {
        if ($media_final >= 14) $aluno['status'] = 'Aprovado';
        elseif ($media_final >= 10) $aluno['status'] = 'Exame';
        else $aluno['status'] = 'Reprovado';
    } else {
        $aluno['status'] = 'Sem nota';
    }
}

// Gerar Excel
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Cabeçalho
$sheet->setCellValue('A1', $escola['nome']);
$sheet->setCellValue('A2', 'CADERNETA DE NOTAS');
$sheet->setCellValue('A3', 'Turma: ' . $turma['ano'] . 'ª ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')');
$sheet->setCellValue('A4', 'Disciplina: ' . $disciplina['nome']);
$sheet->setCellValue('A5', 'Trimestre: ' . $trimestre . 'º');
$sheet->setCellValue('A6', 'Data: ' . date('d/m/Y H:i:s'));

$sheet->mergeCells('A1:J1');
$sheet->mergeCells('A2:J2');
$sheet->mergeCells('A3:J3');
$sheet->mergeCells('A4:J4');
$sheet->mergeCells('A5:J5');
$sheet->mergeCells('A6:J6');

// Cabeçalhos da tabela
$row = 8;
$headers = ['#', 'Matrícula', 'Aluno', 'Gênero', 'MAC', 'NPT'];
$colunas = ['A', 'B', 'C', 'D', 'E', 'F'];

if ($is_exame_classe && $trimestre == 3) {
    if ($is_linguagem) {
        $headers[] = 'Exame Oral';
        $headers[] = 'Exame Escrito';
        $colunas[] = 'G';
        $colunas[] = 'H';
    } else {
        $headers[] = 'Exame Normal';
        $colunas[] = 'G';
    }
}

$headers[] = 'Média Final';
$headers[] = 'Status';
$colunas[] = chr(65 + count($headers) - 2);
$colunas[] = chr(65 + count($headers) - 1);

for ($i = 0; $i < count($headers); $i++) {
    $sheet->setCellValue($colunas[$i] . $row, $headers[$i]);
    $sheet->getColumnDimension($colunas[$i])->setAutoSize(true);
}

$sheet->getStyle('A' . $row . ':' . end($colunas) . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':' . end($colunas) . $row)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row . ':' . end($colunas) . $row)->getFont()->getColor()->setRGB('FFFFFF');
$row++;

// Dados
foreach ($alunos as $index => $aluno) {
    $col_idx = 0;
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $aluno['matricula']);
    $sheet->setCellValue('C' . $row, $aluno['nome']);
    $sheet->setCellValue('D' . $row, $aluno['genero'] == 'masculino' ? 'M' : 'F');
    $sheet->setCellValue('E' . $row, $aluno['mac'] !== null ? number_format($aluno['mac'], 2, ',', '.') : '---');
    $sheet->setCellValue('F' . $row, $aluno['npt'] !== null ? number_format($aluno['npt'], 2, ',', '.') : '---');
    
    $col_idx = 6;
    if ($is_exame_classe && $trimestre == 3) {
        if ($is_linguagem) {
            $sheet->setCellValue('G' . $row, $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 2, ',', '.') : '---');
            $sheet->setCellValue('H' . $row, $aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrito'], 2, ',', '.') : '---');
            $sheet->setCellValue('I' . $row, $aluno['media_final_calculada'] !== null ? number_format($aluno['media_final_calculada'], 2, ',', '.') : '---');
            $sheet->setCellValue('J' . $row, $aluno['status']);
        } else {
            $sheet->setCellValue('G' . $row, $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 2, ',', '.') : '---');
            $sheet->setCellValue('H' . $row, $aluno['media_final_calculada'] !== null ? number_format($aluno['media_final_calculada'], 2, ',', '.') : '---');
            $sheet->setCellValue('I' . $row, $aluno['status']);
        }
    } else {
        $sheet->setCellValue('G' . $row, $aluno['media_final_calculada'] !== null ? number_format($aluno['media_final_calculada'], 2, ',', '.') : '---');
        $sheet->setCellValue('H' . $row, $aluno['status']);
    }
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
$sheet->getStyle('A8:' . end($colunas) . ($row - 1))->applyFromArray($styleArray);

// Configurar download
$filename = 'caderneta_' . $turma['nome'] . '_' . $disciplina['nome'] . '_' . $trimestre . 't_' . date('Y-m-d') . '.xlsx';
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>