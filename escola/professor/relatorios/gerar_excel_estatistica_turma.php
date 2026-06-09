<?php
// escola/professor/relatorios/gerar_excel_estatistica_turma.php - Gerar Excel da Estatística por Turma

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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

if ($turma_id <= 0) {
    die('Parâmetros inválidos. Turma não selecionada.');
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

if (!$turma) {
    die('Turma não encontrada.');
}

$classe_ano = $turma['ano'] ?? 0;

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR NA TURMA
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id AND pdt.turma_id = :turma_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
$disciplinas_turma = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ESTATÍSTICAS
// ============================================
$estatisticas = [];
$total_alunos_turma = 0;

// Buscar total de alunos da turma
$sql_total = "SELECT COUNT(*) as total FROM matriculas WHERE turma_id = :turma_id AND status = 'ativa'";
$stmt_total = $conn->prepare($sql_total);
$stmt_total->execute([':turma_id' => $turma_id]);
$total_alunos_turma = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

foreach ($disciplinas_turma as $disciplina) {
    // Buscar estatísticas das notas
    $sql_stats = "
        SELECT 
            COUNT(DISTINCT n.estudante_id) as total_alunos_com_nota,
            AVG(n.media_final) as media_geral,
            MAX(n.media_final) as maior_nota,
            MIN(CASE WHEN n.media_final > 0 THEN n.media_final END) as menor_nota,
            SUM(CASE WHEN n.status = 'aprovado' THEN 1 ELSE 0 END) as total_aprovados,
            SUM(CASE WHEN n.status = 'recuperacao' THEN 1 ELSE 0 END) as total_recuperacao,
            SUM(CASE WHEN n.status = 'reprovado' THEN 1 ELSE 0 END) as total_reprovados
        FROM notas n
        WHERE n.disciplina_id = :disciplina_id 
        AND n.bimestre = :bimestre 
        AND n.ano_letivo_id = :ano_letivo_id
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->execute([
        ':disciplina_id' => $disciplina['id'],
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    $estatisticas[] = [
        'disciplina_nome' => $disciplina['nome'],
        'disciplina_codigo' => $disciplina['codigo'],
        'total_alunos' => $total_alunos_turma,
        'com_nota' => $stats['total_alunos_com_nota'] ?? 0,
        'media_geral' => round($stats['media_geral'] ?? 0, 1),
        'maior_nota' => round($stats['maior_nota'] ?? 0, 1),
        'menor_nota' => round($stats['menor_nota'] ?? 0, 1),
        'aprovados' => $stats['total_aprovados'] ?? 0,
        'recuperacao' => $stats['total_recuperacao'] ?? 0,
        'reprovados' => $stats['total_reprovados'] ?? 0,
        'percentual_aprovacao' => ($stats['total_alunos_com_nota'] ?? 0) > 0 ? round(($stats['total_aprovados'] / $stats['total_alunos_com_nota']) * 100, 1) : 0,
        'percentual_com_nota' => $total_alunos_turma > 0 ? round(($stats['total_alunos_com_nota'] / $total_alunos_turma) * 100, 1) : 0
    ];
}

// ============================================
// CALCULAR MÉDIAS GERAIS DA TURMA
// ============================================
$turma_media_geral = 0;
$turma_total_aprovados = 0;
$turma_total_alunos = 0;
$count_disciplinas = 0;
$melhor_disciplina = '';
$melhor_media = 0;
$pior_disciplina = '';
$pior_media = 100;

foreach ($estatisticas as $est) {
    if ($est['media_geral'] > 0) {
        $turma_media_geral += $est['media_geral'];
        $count_disciplinas++;
        
        if ($est['media_geral'] > $melhor_media) {
            $melhor_media = $est['media_geral'];
            $melhor_disciplina = $est['disciplina_nome'];
        }
        if ($est['media_geral'] < $pior_media && $est['media_geral'] > 0) {
            $pior_media = $est['media_geral'];
            $pior_disciplina = $est['disciplina_nome'];
        }
    }
    $turma_total_aprovados += $est['aprovados'];
    $turma_total_alunos += $est['total_alunos'];
}
$turma_media_geral = $count_disciplinas > 0 ? round($turma_media_geral / $count_disciplinas, 1) : 0;
$turma_taxa_aprovacao = $turma_total_alunos > 0 ? round(($turma_total_aprovados / $turma_total_alunos) * 100, 1) : 0;

// ============================================
// CRIAR PLANILHA EXCEL
// ============================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Estatística por Turma');

// ============================================
// CONFIGURAÇÃO DE PÁGINA (A4 PAISAGEM)
// ============================================
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageMargins()->setTop(0.75);
$sheet->getPageMargins()->setBottom(0.75);
$sheet->getPageMargins()->setLeft(0.5);
$sheet->getPageMargins()->setRight(0.5);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// ============================================
// CABEÇALHO
// ============================================
$sheet->setCellValue('A1', strtoupper($escola['nome'] ?? 'SIGE ANGOLA'));
$sheet->mergeCells('A1:L1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'ESTATÍSTICA POR TURMA');
$sheet->mergeCells('A2:L2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', $turma['ano'] . 'ª CLASSE - ' . htmlspecialchars($turma['nome']) . ' | ' . $bimestre . 'º BIMESTRE | ANO LETIVO ' . $ano_letivo_ano);
$sheet->mergeCells('A3:L3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A3')->getFont()->setSize(9);

// ============================================
// INFORMAÇÕES DA TURMA
// ============================================
$sheet->setCellValue('A5', 'INFORMAÇÕES DA TURMA');
$sheet->mergeCells('A5:L5');
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
$sheet->setCellValue('K6', $ano_letivo_ano);

$sheet->setCellValue('A7', 'Professor:');
$sheet->setCellValue('B7', htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('D7', 'Data Emissão:');
$sheet->setCellValue('E7', date('d/m/Y H:i:s'));

$sheet->getStyle('A6:K7')->getFont()->setSize(9);
$sheet->getStyle('A6')->getFont()->setBold(true);
$sheet->getStyle('D6')->getFont()->setBold(true);
$sheet->getStyle('G6')->getFont()->setBold(true);
$sheet->getStyle('J6')->getFont()->setBold(true);
$sheet->getStyle('A7')->getFont()->setBold(true);
$sheet->getStyle('D7')->getFont()->setBold(true);

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================
$sheet->setCellValue('A9', 'ESTATÍSTICAS GERAIS');
$sheet->mergeCells('A9:L9');
$sheet->getStyle('A9')->getFont()->setBold(true);
$sheet->getStyle('A9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A9')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A10', 'Total de Disciplinas:');
$sheet->setCellValue('B10', count($disciplinas_turma));
$sheet->setCellValue('D10', 'Total de Alunos:');
$sheet->setCellValue('E10', $total_alunos_turma);
$sheet->setCellValue('G10', 'Média Geral:');
$sheet->setCellValue('H10', $turma_media_geral);
$sheet->setCellValue('J10', 'Taxa de Aprovação:');
$sheet->setCellValue('K10', $turma_taxa_aprovacao . '%');

$sheet->getStyle('A10:K10')->getFont()->setSize(9);
$sheet->getStyle('A10')->getFont()->setBold(true);
$sheet->getStyle('D10')->getFont()->setBold(true);
$sheet->getStyle('G10')->getFont()->setBold(true);
$sheet->getStyle('J10')->getFont()->setBold(true);

// ============================================
// DESTAQUES
// ============================================
$sheet->setCellValue('A12', 'DESTAQUES');
$sheet->mergeCells('A12:L12');
$sheet->getStyle('A12')->getFont()->setBold(true);
$sheet->getStyle('A12')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A12')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A13', '⭐ Melhor Disciplina:');
$sheet->setCellValue('B13', $melhor_disciplina ?: '-');
$sheet->setCellValue('D13', 'Média:');
$sheet->setCellValue('E13', $melhor_media > 0 ? $melhor_media : '-');
$sheet->setCellValue('G13', '⚠️ Disciplina com Menor Média:');
$sheet->setCellValue('H13', $pior_disciplina ?: '-');
$sheet->setCellValue('J13', 'Média:');
$sheet->setCellValue('K13', $pior_media < 100 ? $pior_media : '-');

$sheet->getStyle('A13:K13')->getFont()->setSize(9);
$sheet->getStyle('A13')->getFont()->setBold(true);
$sheet->getStyle('D13')->getFont()->setBold(true);
$sheet->getStyle('G13')->getFont()->setBold(true);
$sheet->getStyle('J13')->getFont()->setBold(true);

// ============================================
// TABELA DE ESTATÍSTICAS POR DISCIPLINA
// ============================================
$row = 15;

$sheet->setCellValue('A' . $row, 'ESTATÍSTICAS POR DISCIPLINA');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
// Cabeçalho principal
$sheet->setCellValue('A' . $row, '#');
$sheet->setCellValue('B' . $row, 'Disciplina');
$sheet->setCellValue('C' . $row, 'Código');
$sheet->mergeCells('D' . $row . ':E' . $row);
$sheet->setCellValue('D' . $row, 'Alunos');
$sheet->mergeCells('F' . $row . ':H' . $row);
$sheet->setCellValue('F' . $row, 'Notas');
$sheet->mergeCells('I' . $row . ':K' . $row);
$sheet->setCellValue('I' . $row, 'Resultados');
$sheet->setCellValue('L' . $row, 'Aprovação');

$row++;
// Sub-cabeçalho
$sheet->setCellValue('A' . $row, '#');
$sheet->setCellValue('B' . $row, 'Disciplina');
$sheet->setCellValue('C' . $row, 'Código');
$sheet->setCellValue('D' . $row, 'Total');
$sheet->setCellValue('E' . $row, 'C/ Nota');
$sheet->setCellValue('F' . $row, 'Média');
$sheet->setCellValue('G' . $row, 'Mín.');
$sheet->setCellValue('H' . $row, 'Máx.');
$sheet->setCellValue('I' . $row, 'Aprov');
$sheet->setCellValue('J' . $row, 'Recup');
$sheet->setCellValue('K' . $row, 'Reprov');
$sheet->setCellValue('L' . $row, '%');

// Estilo do cabeçalho
$sheet->getStyle('A' . ($row - 1) . ':L' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . ($row - 1) . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
$sheet->getStyle('A' . ($row - 1) . ':L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . ($row - 1) . ':L' . $row)->getFont()->setSize(8);

$row++;
$contador = 1;
if (empty($estatisticas)) {
    $sheet->setCellValue('A' . $row, 'Nenhuma estatística disponível para esta turma no bimestre selecionado.');
    $sheet->mergeCells('A' . $row . ':L' . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
} else {
    foreach ($estatisticas as $est) {
        $sheet->setCellValue('A' . $row, $contador++);
        $sheet->setCellValue('B' . $row, htmlspecialchars($est['disciplina_nome']));
        $sheet->setCellValue('C' . $row, htmlspecialchars($est['disciplina_codigo'] ?? '-'));
        $sheet->setCellValue('D' . $row, $est['total_alunos']);
        $sheet->setCellValue('E' . $row, $est['com_nota'] . ' (' . $est['percentual_com_nota'] . '%)');
        $sheet->setCellValue('F' . $row, $est['media_geral']);
        $sheet->setCellValue('G' . $row, $est['menor_nota'] > 0 ? $est['menor_nota'] : '-');
        $sheet->setCellValue('H' . $row, $est['maior_nota'] > 0 ? $est['maior_nota'] : '-');
        $sheet->setCellValue('I' . $row, $est['aprovados']);
        $sheet->setCellValue('J' . $row, $est['recuperacao']);
        $sheet->setCellValue('K' . $row, $est['reprovados']);
        $sheet->setCellValue('L' . $row, $est['percentual_aprovacao'] . '%');
        
        // Cor da linha baseada na média
        if ($est['media_geral'] >= 9.5) {
            $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4EDDA');
        } elseif ($est['media_geral'] >= 5) {
            $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');
        } elseif ($est['media_geral'] > 0) {
            $sheet->getStyle('A' . $row . ':L' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
        }
        
        $row++;
    }
}

// Aplicar bordas
$lastRow = $row - 1;
$sheet->getStyle('A' . 16 . ':L' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// ============================================
// RESUMO DA TURMA
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'RESUMO DA TURMA');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
$sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');

$row++;
$sheet->setCellValue('A' . $row, '• Total de Disciplinas:');
$sheet->setCellValue('B' . $row, count($disciplinas_turma));
$sheet->setCellValue('D' . $row, '• Total de Alunos:');
$sheet->setCellValue('E' . $row, $total_alunos_turma);
$sheet->setCellValue('G' . $row, '• Média Geral da Turma:');
$sheet->setCellValue('H' . $row, $turma_media_geral);
$sheet->setCellValue('J' . $row, '• Taxa de Aprovação:');
$sheet->setCellValue('K' . $row, $turma_taxa_aprovacao . '%');

$row++;
if ($melhor_disciplina) {
    $sheet->setCellValue('A' . $row, '• Melhor Desempenho:');
    $sheet->setCellValue('B' . $row, htmlspecialchars($melhor_disciplina) . ' (Média: ' . $melhor_media . ')');
    $row++;
    $sheet->setCellValue('A' . $row, '• Disciplina com Maior Dificuldade:');
    $sheet->setCellValue('B' . $row, htmlspecialchars($pior_disciplina) . ' (Média: ' . $pior_media . ')');
}

$sheet->getStyle('A' . ($row - 2) . ':K' . $row)->getFont()->setSize(9);

// ============================================
// RODAPÉ
// ============================================
$row += 2;
$sheet->setCellValue('A' . $row, 'Documento emitido eletronicamente pelo SIGE Angola - ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(8);

$row++;
$sheet->setCellValue('A' . $row, $escola['endereco'] ?? '');
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(8);

$row++;
$sheet->setCellValue('A' . $row, 'Tel: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? ''));
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setSize(8);

// ============================================
// ASSINATURA
// ============================================
$row += 2;
$sheet->setCellValue('J' . $row, '_________________________');
$sheet->setCellValue('K' . $row, '_________________________');
$sheet->mergeCells('J' . $row . ':K' . $row);

$row++;
$sheet->setCellValue('J' . $row, htmlspecialchars($professor['professor_nome']));
$sheet->setCellValue('K' . $row, 'Professor(a) Responsável');
$sheet->mergeCells('J' . $row . ':K' . $row);

// Auto-size das colunas
$colunas = range('A', 'L');
foreach ($colunas as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ============================================
// SALVAR ARQUIVO
// ============================================
$nome_arquivo = 'estatistica_turma_' . $turma['ano'] . '_' . $turma['nome'] . '_' . $bimestre . 'B_' . date('Ymd_His') . '.xlsx';
$nome_arquivo = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nome_arquivo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nome_arquivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>